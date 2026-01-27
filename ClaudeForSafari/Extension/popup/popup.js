// Claude for Safari - Popup Script
// Handles UI interactions and communication with background/content scripts

import { ClaudeAPI } from '../shared/claude-api.js';
import { formatMessage, escapeHtml } from '../shared/utils.js';

class ClaudePopup {
  constructor() {
    this.messages = [];
    this.pageContext = null;
    this.selectedText = null;
    this.isLoading = false;
    this.claudeAPI = null;

    this.init();
  }

  async init() {
    // Initialize Claude API
    const settings = await this.loadSettings();
    if (settings.apiKey) {
      this.claudeAPI = new ClaudeAPI(settings.apiKey, settings.model);
    }

    // Bind DOM elements
    this.bindElements();

    // Setup event listeners
    this.setupEventListeners();

    // Load page context
    await this.loadPageContext();

    // Load conversation history
    await this.loadConversationHistory();
  }

  bindElements() {
    this.elements = {
      pageTitle: document.getElementById('page-title'),
      pageUrl: document.getElementById('page-url'),
      refreshContext: document.getElementById('refresh-context'),
      messagesContainer: document.getElementById('messages'),
      chatContainer: document.getElementById('chat-container'),
      messageInput: document.getElementById('message-input'),
      sendBtn: document.getElementById('send-btn'),
      attachSelection: document.getElementById('attach-selection'),
      settingsBtn: document.getElementById('settings-btn'),
      settingsPanel: document.getElementById('settings-panel'),
      closeSettings: document.getElementById('close-settings'),
      automationPanel: document.getElementById('automation-panel'),
      closeAutomation: document.getElementById('close-automation'),
      apiKeyInput: document.getElementById('api-key'),
      modelSelect: document.getElementById('model-select'),
      autoContext: document.getElementById('auto-context'),
      saveHistory: document.getElementById('save-history'),
      saveSettings: document.getElementById('save-settings'),
      openClaudeApp: document.getElementById('open-claude-app'),
      quickActions: document.querySelectorAll('.action-btn'),
      automationBtns: document.querySelectorAll('.automation-btn')
    };
  }

  setupEventListeners() {
    // Send message
    this.elements.sendBtn.addEventListener('click', () => this.sendMessage());
    this.elements.messageInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        this.sendMessage();
      }
    });

    // Auto-resize textarea
    this.elements.messageInput.addEventListener('input', () => {
      this.elements.messageInput.style.height = 'auto';
      this.elements.messageInput.style.height = Math.min(this.elements.messageInput.scrollHeight, 100) + 'px';
    });

    // Refresh page context
    this.elements.refreshContext.addEventListener('click', () => this.loadPageContext());

    // Attach selection
    this.elements.attachSelection.addEventListener('click', () => this.attachSelectedText());

    // Settings panel
    this.elements.settingsBtn.addEventListener('click', () => this.toggleSettings(true));
    this.elements.closeSettings.addEventListener('click', () => this.toggleSettings(false));
    this.elements.saveSettings.addEventListener('click', () => this.saveSettings());

    // Open Claude app
    this.elements.openClaudeApp.addEventListener('click', () => {
      // Deep link to Claude iOS app
      window.open('claude://chat', '_blank');
    });

    // Automation panel
    this.elements.closeAutomation.addEventListener('click', () => this.toggleAutomation(false));

    // Quick actions
    this.elements.quickActions.forEach(btn => {
      btn.addEventListener('click', () => this.executeQuickAction(btn.dataset.action));
    });

    // Automation actions
    this.elements.automationBtns.forEach(btn => {
      btn.addEventListener('click', () => this.executeAutomation(btn.dataset.automation));
    });
  }

  async loadPageContext() {
    try {
      // Get current tab
      const [tab] = await browser.tabs.query({ active: true, currentWindow: true });

      if (tab) {
        this.elements.pageTitle.textContent = tab.title || 'Unknown page';
        this.elements.pageUrl.textContent = new URL(tab.url).hostname;

        // Get page content from content script
        const response = await browser.tabs.sendMessage(tab.id, { action: 'getPageContext' });

        if (response) {
          this.pageContext = {
            title: tab.title,
            url: tab.url,
            content: response.content,
            selectedText: response.selectedText,
            metadata: response.metadata
          };
        }
      }
    } catch (error) {
      console.error('Failed to load page context:', error);
      this.elements.pageTitle.textContent = 'Unable to read page';
      this.elements.pageUrl.textContent = 'Refresh to try again';
    }
  }

  async attachSelectedText() {
    try {
      const [tab] = await browser.tabs.query({ active: true, currentWindow: true });
      const response = await browser.tabs.sendMessage(tab.id, { action: 'getSelection' });

      if (response && response.selectedText) {
        this.selectedText = response.selectedText;
        this.showSelectionAttached();
      } else {
        this.showNotification('No text selected on the page');
      }
    } catch (error) {
      console.error('Failed to get selection:', error);
    }
  }

  showSelectionAttached() {
    // Remove existing attachment indicator
    const existing = document.querySelector('.context-attached');
    if (existing) existing.remove();

    const indicator = document.createElement('div');
    indicator.className = 'context-attached';
    indicator.innerHTML = `
      <span>Selected text attached (${this.selectedText.length} chars)</span>
      <span class="remove" onclick="this.parentElement.remove()">×</span>
    `;
    indicator.querySelector('.remove').addEventListener('click', () => {
      this.selectedText = null;
    });

    this.elements.chatContainer.insertBefore(indicator, this.elements.messagesContainer);
  }

  async sendMessage() {
    const text = this.elements.messageInput.value.trim();
    if (!text || this.isLoading) return;

    // Check for API key
    if (!this.claudeAPI) {
      this.showNotification('Please set your API key in settings');
      this.toggleSettings(true);
      return;
    }

    // Clear input
    this.elements.messageInput.value = '';
    this.elements.messageInput.style.height = 'auto';

    // Build message with context
    let fullMessage = text;
    const settings = await this.loadSettings();

    if (settings.autoContext && this.pageContext) {
      fullMessage = `[Page Context]
Title: ${this.pageContext.title}
URL: ${this.pageContext.url}
${this.pageContext.content ? `Content Preview: ${this.pageContext.content.substring(0, 2000)}...` : ''}

[User Question]
${text}`;
    }

    if (this.selectedText) {
      fullMessage += `\n\n[Selected Text]\n${this.selectedText}`;
      this.selectedText = null;
      document.querySelector('.context-attached')?.remove();
    }

    // Add user message to UI
    this.addMessage('user', text);

    // Show typing indicator
    this.showTypingIndicator();
    this.isLoading = true;

    try {
      // Send to Claude API
      const response = await this.claudeAPI.chat(fullMessage, this.messages);

      // Add assistant response
      this.addMessage('assistant', response);

      // Save conversation if enabled
      if (settings.saveHistory) {
        await this.saveConversationHistory();
      }
    } catch (error) {
      console.error('API error:', error);
      this.addMessage('assistant', `Error: ${error.message}. Please check your API key and try again.`);
    } finally {
      this.hideTypingIndicator();
      this.isLoading = false;
    }
  }

  addMessage(role, content) {
    // Remove welcome message if present
    const welcome = this.elements.messagesContainer.querySelector('.welcome-message');
    if (welcome) welcome.remove();

    // Add to messages array
    this.messages.push({ role, content });

    // Create message element
    const messageEl = document.createElement('div');
    messageEl.className = `message ${role}`;
    messageEl.innerHTML = formatMessage(content);

    this.elements.messagesContainer.appendChild(messageEl);

    // Scroll to bottom
    this.elements.chatContainer.scrollTop = this.elements.chatContainer.scrollHeight;
  }

  showTypingIndicator() {
    const indicator = document.createElement('div');
    indicator.className = 'typing-indicator';
    indicator.id = 'typing-indicator';
    indicator.innerHTML = '<span></span><span></span><span></span>';
    this.elements.messagesContainer.appendChild(indicator);
    this.elements.chatContainer.scrollTop = this.elements.chatContainer.scrollHeight;
  }

  hideTypingIndicator() {
    document.getElementById('typing-indicator')?.remove();
  }

  async executeQuickAction(action) {
    const prompts = {
      summarize: 'Please summarize this page concisely, highlighting the key points.',
      explain: 'Please explain the main content of this page in simple terms that anyone can understand.',
      extract: 'Please extract the most important information from this page in a structured format (bullet points or table).',
      translate: 'Please translate the main content of this page to English (or if already in English, to Spanish).'
    };

    if (prompts[action]) {
      this.elements.messageInput.value = prompts[action];
      await this.sendMessage();
    }
  }

  async executeAutomation(automation) {
    this.toggleAutomation(false);

    switch (automation) {
      case 'record':
        await this.startWorkflowRecording();
        break;
      case 'fill-form':
        await this.executeFillForm();
        break;
      case 'click-sequence':
        await this.executeClickSequence();
        break;
      case 'scrape':
        await this.executeScraping();
        break;
    }
  }

  async startWorkflowRecording() {
    try {
      const [tab] = await browser.tabs.query({ active: true, currentWindow: true });
      await browser.tabs.sendMessage(tab.id, { action: 'startRecording' });
      this.showNotification('Recording started. Click elements to record actions.');
    } catch (error) {
      console.error('Failed to start recording:', error);
    }
  }

  async executeFillForm() {
    this.elements.messageInput.value = 'Please identify all form fields on this page and help me fill them out. List each field and what information it needs.';
    await this.sendMessage();
  }

  async executeClickSequence() {
    this.elements.messageInput.value = 'Please analyze this page and identify the main interactive elements (buttons, links). What actions can I take on this page?';
    await this.sendMessage();
  }

  async executeScraping() {
    this.elements.messageInput.value = 'Please extract all structured data from this page (tables, lists, key-value pairs) and format it as JSON.';
    await this.sendMessage();
  }

  toggleSettings(show) {
    if (show) {
      this.loadSettingsUI();
    }
    this.elements.settingsPanel.classList.toggle('hidden', !show);
    this.elements.settingsPanel.classList.toggle('visible', show);
  }

  toggleAutomation(show) {
    this.elements.automationPanel.classList.toggle('hidden', !show);
    this.elements.automationPanel.classList.toggle('visible', show);
  }

  async loadSettings() {
    try {
      const result = await browser.storage.local.get(['apiKey', 'model', 'autoContext', 'saveHistory']);
      return {
        apiKey: result.apiKey || '',
        model: result.model || 'claude-sonnet-4-20250514',
        autoContext: result.autoContext !== false,
        saveHistory: result.saveHistory !== false
      };
    } catch (error) {
      console.error('Failed to load settings:', error);
      return {
        apiKey: '',
        model: 'claude-sonnet-4-20250514',
        autoContext: true,
        saveHistory: true
      };
    }
  }

  async loadSettingsUI() {
    const settings = await this.loadSettings();
    this.elements.apiKeyInput.value = settings.apiKey;
    this.elements.modelSelect.value = settings.model;
    this.elements.autoContext.checked = settings.autoContext;
    this.elements.saveHistory.checked = settings.saveHistory;
  }

  async saveSettings() {
    const apiKey = this.elements.apiKeyInput.value.trim();
    const model = this.elements.modelSelect.value;
    const autoContext = this.elements.autoContext.checked;
    const saveHistory = this.elements.saveHistory.checked;

    try {
      await browser.storage.local.set({ apiKey, model, autoContext, saveHistory });

      // Update Claude API instance
      if (apiKey) {
        this.claudeAPI = new ClaudeAPI(apiKey, model);
      }

      this.showNotification('Settings saved successfully');
      this.toggleSettings(false);
    } catch (error) {
      console.error('Failed to save settings:', error);
      this.showNotification('Failed to save settings');
    }
  }

  async loadConversationHistory() {
    try {
      const result = await browser.storage.local.get(['conversationHistory']);
      if (result.conversationHistory && result.conversationHistory.length > 0) {
        // Remove welcome message
        const welcome = this.elements.messagesContainer.querySelector('.welcome-message');
        if (welcome) welcome.remove();

        this.messages = result.conversationHistory;
        this.messages.forEach(msg => {
          const messageEl = document.createElement('div');
          messageEl.className = `message ${msg.role}`;
          messageEl.innerHTML = formatMessage(msg.content);
          this.elements.messagesContainer.appendChild(messageEl);
        });

        this.elements.chatContainer.scrollTop = this.elements.chatContainer.scrollHeight;
      }
    } catch (error) {
      console.error('Failed to load conversation history:', error);
    }
  }

  async saveConversationHistory() {
    try {
      // Keep last 50 messages
      const historyToSave = this.messages.slice(-50);
      await browser.storage.local.set({ conversationHistory: historyToSave });
    } catch (error) {
      console.error('Failed to save conversation history:', error);
    }
  }

  showNotification(message) {
    // Simple notification - could be enhanced
    const notification = document.createElement('div');
    notification.style.cssText = `
      position: fixed;
      bottom: 80px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--bg-tertiary);
      color: var(--text-primary);
      padding: 10px 16px;
      border-radius: 8px;
      font-size: 13px;
      z-index: 1000;
      box-shadow: var(--shadow);
    `;
    notification.textContent = message;
    document.body.appendChild(notification);

    setTimeout(() => notification.remove(), 3000);
  }
}

// Initialize popup
document.addEventListener('DOMContentLoaded', () => {
  new ClaudePopup();
});
