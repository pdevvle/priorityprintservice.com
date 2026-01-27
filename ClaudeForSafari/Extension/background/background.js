// Claude for Safari - Background Service Worker
// Handles API communication, tab management, and extension state

import { ClaudeAPI } from '../shared/claude-api.js';

class BackgroundService {
  constructor() {
    this.claudeAPI = null;
    this.contextSelection = '';
    this.workflows = new Map();

    this.init();
  }

  async init() {
    // Load settings and initialize API
    await this.loadSettings();

    // Setup message listeners
    browser.runtime.onMessage.addListener((message, sender, sendResponse) => {
      return this.handleMessage(message, sender);
    });

    // Setup context menus
    this.setupContextMenus();

    // Handle extension install/update
    browser.runtime.onInstalled.addListener((details) => {
      this.onInstalled(details);
    });

    // Handle tab updates for context
    browser.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
      if (changeInfo.status === 'complete') {
        this.onTabReady(tabId, tab);
      }
    });
  }

  async loadSettings() {
    try {
      const result = await browser.storage.local.get(['apiKey', 'model']);
      if (result.apiKey) {
        this.claudeAPI = new ClaudeAPI(result.apiKey, result.model || 'claude-sonnet-4-20250514');
      }
    } catch (error) {
      console.error('Failed to load settings:', error);
    }
  }

  async handleMessage(message, sender) {
    switch (message.action) {
      case 'chat':
        return this.handleChat(message.prompt, message.context);

      case 'setContextSelection':
        this.contextSelection = message.selectedText;
        return { success: true };

      case 'executeWorkflow':
        return this.executeWorkflow(message.workflowId, sender.tab?.id);

      case 'saveWorkflow':
        return this.saveWorkflow(message.workflow);

      case 'getWorkflows':
        return this.getWorkflows();

      case 'apiKeyUpdated':
        await this.loadSettings();
        return { success: true };

      case 'openInClaudeApp':
        return this.openInClaudeApp(message.content);

      default:
        return { error: 'Unknown action' };
    }
  }

  async handleChat(prompt, context) {
    if (!this.claudeAPI) {
      return { error: 'API key not configured' };
    }

    try {
      const response = await this.claudeAPI.chat(prompt, context || []);
      return { response };
    } catch (error) {
      return { error: error.message };
    }
  }

  setupContextMenus() {
    // Remove existing menus
    browser.contextMenus.removeAll();

    // Create context menu items
    browser.contextMenus.create({
      id: 'claude-summarize',
      title: 'Ask Claude to summarize',
      contexts: ['selection']
    });

    browser.contextMenus.create({
      id: 'claude-explain',
      title: 'Ask Claude to explain',
      contexts: ['selection']
    });

    browser.contextMenus.create({
      id: 'claude-translate',
      title: 'Ask Claude to translate',
      contexts: ['selection']
    });

    browser.contextMenus.create({
      id: 'claude-separator',
      type: 'separator',
      contexts: ['selection']
    });

    browser.contextMenus.create({
      id: 'claude-open-app',
      title: 'Open in Claude app',
      contexts: ['selection']
    });

    browser.contextMenus.create({
      id: 'claude-page-summarize',
      title: 'Summarize this page with Claude',
      contexts: ['page']
    });

    // Handle context menu clicks
    browser.contextMenus.onClicked.addListener((info, tab) => {
      this.handleContextMenuClick(info, tab);
    });
  }

  async handleContextMenuClick(info, tab) {
    const selectedText = info.selectionText || this.contextSelection;

    switch (info.menuItemId) {
      case 'claude-summarize':
        await this.processWithClaude(tab.id, `Please summarize the following text concisely:\n\n${selectedText}`);
        break;

      case 'claude-explain':
        await this.processWithClaude(tab.id, `Please explain the following text in simple terms:\n\n${selectedText}`);
        break;

      case 'claude-translate':
        await this.processWithClaude(tab.id, `Please translate the following text to English (or if already in English, to Spanish):\n\n${selectedText}`);
        break;

      case 'claude-open-app':
        await this.openInClaudeApp(selectedText);
        break;

      case 'claude-page-summarize':
        await this.summarizePage(tab.id);
        break;
    }
  }

  async processWithClaude(tabId, prompt) {
    // Open popup with pre-filled prompt
    await browser.action.openPopup();

    // Send message to popup with the prompt
    setTimeout(async () => {
      try {
        await browser.runtime.sendMessage({
          action: 'prefillPrompt',
          prompt
        });
      } catch (error) {
        // Popup might not be ready yet
        console.log('Waiting for popup...');
      }
    }, 300);
  }

  async summarizePage(tabId) {
    try {
      // Get page content from content script
      const response = await browser.tabs.sendMessage(tabId, { action: 'getPageContext' });

      if (response && response.content) {
        const prompt = `Please summarize this webpage:\n\nTitle: ${response.title}\nURL: ${response.url}\n\nContent:\n${response.content.substring(0, 10000)}`;
        await this.processWithClaude(tabId, prompt);
      }
    } catch (error) {
      console.error('Failed to summarize page:', error);
    }
  }

  async openInClaudeApp(content) {
    // Create deep link to Claude iOS app
    const encodedContent = encodeURIComponent(content);
    const claudeUrl = `claude://new?prompt=${encodedContent}`;

    // Open in Claude app (iOS) or fallback to web
    try {
      await browser.tabs.create({ url: claudeUrl });
    } catch (error) {
      // Fallback to Claude web
      await browser.tabs.create({ url: `https://claude.ai/new?q=${encodedContent}` });
    }

    return { success: true };
  }

  // Workflow Management
  async saveWorkflow(workflow) {
    try {
      const workflows = await this.getWorkflows();
      workflows[workflow.id] = workflow;
      await browser.storage.local.set({ workflows });
      return { success: true };
    } catch (error) {
      return { error: error.message };
    }
  }

  async getWorkflows() {
    try {
      const result = await browser.storage.local.get(['workflows']);
      return result.workflows || {};
    } catch (error) {
      return {};
    }
  }

  async executeWorkflow(workflowId, tabId) {
    const workflows = await this.getWorkflows();
    const workflow = workflows[workflowId];

    if (!workflow) {
      return { error: 'Workflow not found' };
    }

    try {
      for (const action of workflow.actions) {
        await browser.tabs.sendMessage(tabId, {
          action: 'executeAction',
          actionData: action
        });

        // Wait between actions
        await this.sleep(action.delay || 500);
      }

      return { success: true };
    } catch (error) {
      return { error: error.message };
    }
  }

  onInstalled(details) {
    if (details.reason === 'install') {
      // First install - show welcome page
      browser.tabs.create({
        url: browser.runtime.getURL('welcome/welcome.html')
      });
    } else if (details.reason === 'update') {
      // Update - could show changelog
      console.log('Extension updated to version', browser.runtime.getManifest().version);
    }
  }

  onTabReady(tabId, tab) {
    // Could pre-fetch page context here for faster popup loading
    // For now, just log
    console.log('Tab ready:', tab.url);
  }

  sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }
}

// Initialize background service
new BackgroundService();
