// Claude for Safari - Content Script
// Runs on web pages to read content, interact with elements, and record workflows

class ClaudeContentScript {
  constructor() {
    this.isRecording = false;
    this.recordedActions = [];
    this.highlightOverlay = null;

    this.init();
  }

  init() {
    // Listen for messages from popup/background
    browser.runtime.onMessage.addListener((message, sender, sendResponse) => {
      return this.handleMessage(message, sender, sendResponse);
    });

    // Setup context menu integration
    this.setupContextMenu();
  }

  handleMessage(message, sender, sendResponse) {
    switch (message.action) {
      case 'getPageContext':
        return Promise.resolve(this.getPageContext());

      case 'getSelection':
        return Promise.resolve({ selectedText: this.getSelectedText() });

      case 'startRecording':
        this.startRecording();
        return Promise.resolve({ success: true });

      case 'stopRecording':
        const actions = this.stopRecording();
        return Promise.resolve({ actions });

      case 'executeAction':
        return this.executeAction(message.actionData);

      case 'highlightElement':
        this.highlightElement(message.selector);
        return Promise.resolve({ success: true });

      case 'fillForm':
        return this.fillForm(message.formData);

      case 'extractData':
        return Promise.resolve(this.extractStructuredData());

      case 'clickElement':
        return this.clickElement(message.selector);

      case 'scrollTo':
        return this.scrollToElement(message.selector);

      default:
        return Promise.resolve({ error: 'Unknown action' });
    }
  }

  getPageContext() {
    return {
      title: document.title,
      url: window.location.href,
      content: this.extractPageContent(),
      selectedText: this.getSelectedText(),
      metadata: this.extractMetadata(),
      forms: this.detectForms(),
      links: this.extractLinks(),
      images: this.extractImageInfo()
    };
  }

  extractPageContent() {
    // Get main content, prioritizing article/main elements
    const mainContent = document.querySelector('article, main, [role="main"], .content, #content');

    if (mainContent) {
      return this.cleanText(mainContent.innerText);
    }

    // Fallback to body, excluding nav/footer/sidebar
    const body = document.body.cloneNode(true);
    const elementsToRemove = body.querySelectorAll('nav, footer, aside, header, script, style, noscript, [role="navigation"], [role="banner"], [role="contentinfo"]');
    elementsToRemove.forEach(el => el.remove());

    return this.cleanText(body.innerText);
  }

  cleanText(text) {
    return text
      .replace(/\s+/g, ' ')
      .replace(/\n\s*\n/g, '\n\n')
      .trim()
      .substring(0, 50000); // Limit to ~50k chars
  }

  getSelectedText() {
    const selection = window.getSelection();
    return selection ? selection.toString().trim() : '';
  }

  extractMetadata() {
    const metadata = {
      description: '',
      keywords: '',
      author: '',
      publishedDate: '',
      ogTitle: '',
      ogDescription: '',
      ogImage: ''
    };

    // Meta tags
    const metaTags = document.querySelectorAll('meta');
    metaTags.forEach(meta => {
      const name = meta.getAttribute('name') || meta.getAttribute('property');
      const content = meta.getAttribute('content');

      if (!name || !content) return;

      switch (name.toLowerCase()) {
        case 'description':
          metadata.description = content;
          break;
        case 'keywords':
          metadata.keywords = content;
          break;
        case 'author':
          metadata.author = content;
          break;
        case 'article:published_time':
        case 'date':
          metadata.publishedDate = content;
          break;
        case 'og:title':
          metadata.ogTitle = content;
          break;
        case 'og:description':
          metadata.ogDescription = content;
          break;
        case 'og:image':
          metadata.ogImage = content;
          break;
      }
    });

    return metadata;
  }

  detectForms() {
    const forms = [];
    document.querySelectorAll('form').forEach((form, index) => {
      const fields = [];
      form.querySelectorAll('input, select, textarea').forEach(field => {
        if (field.type === 'hidden') return;

        fields.push({
          type: field.tagName.toLowerCase() === 'select' ? 'select' : field.type,
          name: field.name || field.id,
          label: this.findFieldLabel(field),
          placeholder: field.placeholder,
          required: field.required,
          selector: this.getUniqueSelector(field)
        });
      });

      if (fields.length > 0) {
        forms.push({
          id: form.id || `form-${index}`,
          action: form.action,
          method: form.method,
          fields
        });
      }
    });

    return forms;
  }

  findFieldLabel(field) {
    // Check for associated label
    if (field.id) {
      const label = document.querySelector(`label[for="${field.id}"]`);
      if (label) return label.textContent.trim();
    }

    // Check for parent label
    const parentLabel = field.closest('label');
    if (parentLabel) return parentLabel.textContent.replace(field.value, '').trim();

    // Check for aria-label
    if (field.getAttribute('aria-label')) return field.getAttribute('aria-label');

    // Fallback to name/placeholder
    return field.name || field.placeholder || '';
  }

  extractLinks() {
    const links = [];
    document.querySelectorAll('a[href]').forEach(link => {
      const href = link.href;
      const text = link.textContent.trim();

      if (href && text && !href.startsWith('javascript:')) {
        links.push({
          text: text.substring(0, 100),
          href,
          isExternal: !href.startsWith(window.location.origin)
        });
      }
    });

    // Limit to first 100 links
    return links.slice(0, 100);
  }

  extractImageInfo() {
    const images = [];
    document.querySelectorAll('img[src]').forEach(img => {
      images.push({
        src: img.src,
        alt: img.alt,
        width: img.naturalWidth,
        height: img.naturalHeight
      });
    });

    return images.slice(0, 50);
  }

  extractStructuredData() {
    const data = {
      tables: [],
      lists: [],
      jsonLd: []
    };

    // Extract tables
    document.querySelectorAll('table').forEach((table, index) => {
      const headers = [];
      const rows = [];

      table.querySelectorAll('thead th, thead td').forEach(cell => {
        headers.push(cell.textContent.trim());
      });

      table.querySelectorAll('tbody tr').forEach(row => {
        const rowData = [];
        row.querySelectorAll('td').forEach(cell => {
          rowData.push(cell.textContent.trim());
        });
        if (rowData.length > 0) rows.push(rowData);
      });

      if (rows.length > 0) {
        data.tables.push({ headers, rows });
      }
    });

    // Extract definition lists
    document.querySelectorAll('dl').forEach(dl => {
      const items = {};
      let currentTerm = '';

      dl.querySelectorAll('dt, dd').forEach(el => {
        if (el.tagName === 'DT') {
          currentTerm = el.textContent.trim();
        } else if (el.tagName === 'DD' && currentTerm) {
          items[currentTerm] = el.textContent.trim();
        }
      });

      if (Object.keys(items).length > 0) {
        data.lists.push(items);
      }
    });

    // Extract JSON-LD
    document.querySelectorAll('script[type="application/ld+json"]').forEach(script => {
      try {
        const json = JSON.parse(script.textContent);
        data.jsonLd.push(json);
      } catch (e) {
        // Invalid JSON, skip
      }
    });

    return data;
  }

  // Workflow Recording
  startRecording() {
    this.isRecording = true;
    this.recordedActions = [];

    // Add recording indicator
    this.showRecordingIndicator();

    // Listen for user interactions
    document.addEventListener('click', this.recordClick.bind(this), true);
    document.addEventListener('input', this.recordInput.bind(this), true);
    document.addEventListener('change', this.recordChange.bind(this), true);
  }

  stopRecording() {
    this.isRecording = false;
    this.hideRecordingIndicator();

    document.removeEventListener('click', this.recordClick.bind(this), true);
    document.removeEventListener('input', this.recordInput.bind(this), true);
    document.removeEventListener('change', this.recordChange.bind(this), true);

    return this.recordedActions;
  }

  recordClick(event) {
    if (!this.isRecording) return;

    const target = event.target;
    const selector = this.getUniqueSelector(target);

    this.recordedActions.push({
      type: 'click',
      selector,
      tagName: target.tagName,
      text: target.textContent?.substring(0, 50),
      timestamp: Date.now()
    });
  }

  recordInput(event) {
    if (!this.isRecording) return;

    const target = event.target;
    if (!['INPUT', 'TEXTAREA'].includes(target.tagName)) return;

    // Debounce input recording
    clearTimeout(this.inputTimeout);
    this.inputTimeout = setTimeout(() => {
      this.recordedActions.push({
        type: 'input',
        selector: this.getUniqueSelector(target),
        value: target.value,
        inputType: target.type,
        timestamp: Date.now()
      });
    }, 500);
  }

  recordChange(event) {
    if (!this.isRecording) return;

    const target = event.target;
    if (target.tagName !== 'SELECT') return;

    this.recordedActions.push({
      type: 'select',
      selector: this.getUniqueSelector(target),
      value: target.value,
      selectedText: target.options[target.selectedIndex]?.text,
      timestamp: Date.now()
    });
  }

  showRecordingIndicator() {
    const indicator = document.createElement('div');
    indicator.id = 'claude-recording-indicator';
    indicator.innerHTML = `
      <style>
        #claude-recording-indicator {
          position: fixed;
          top: 16px;
          right: 16px;
          background: #ef4444;
          color: white;
          padding: 8px 16px;
          border-radius: 20px;
          font-family: -apple-system, BlinkMacSystemFont, sans-serif;
          font-size: 13px;
          font-weight: 500;
          display: flex;
          align-items: center;
          gap: 8px;
          z-index: 999999;
          box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        #claude-recording-indicator .dot {
          width: 8px;
          height: 8px;
          background: white;
          border-radius: 50%;
          animation: pulse 1s infinite;
        }
        @keyframes pulse {
          0%, 100% { opacity: 1; }
          50% { opacity: 0.5; }
        }
      </style>
      <span class="dot"></span>
      Recording...
    `;
    document.body.appendChild(indicator);
  }

  hideRecordingIndicator() {
    document.getElementById('claude-recording-indicator')?.remove();
  }

  // Element Interaction
  async executeAction(actionData) {
    try {
      switch (actionData.type) {
        case 'click':
          await this.clickElement(actionData.selector);
          break;
        case 'input':
          await this.inputText(actionData.selector, actionData.value);
          break;
        case 'select':
          await this.selectOption(actionData.selector, actionData.value);
          break;
        case 'scroll':
          await this.scrollToElement(actionData.selector);
          break;
      }
      return { success: true };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }

  async clickElement(selector) {
    const element = document.querySelector(selector);
    if (!element) throw new Error(`Element not found: ${selector}`);

    this.highlightElement(selector);
    await this.sleep(200);

    element.click();
    return { success: true };
  }

  async inputText(selector, value) {
    const element = document.querySelector(selector);
    if (!element) throw new Error(`Element not found: ${selector}`);

    element.focus();
    element.value = value;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));

    return { success: true };
  }

  async selectOption(selector, value) {
    const element = document.querySelector(selector);
    if (!element) throw new Error(`Element not found: ${selector}`);

    element.value = value;
    element.dispatchEvent(new Event('change', { bubbles: true }));

    return { success: true };
  }

  async scrollToElement(selector) {
    const element = document.querySelector(selector);
    if (!element) throw new Error(`Element not found: ${selector}`);

    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return { success: true };
  }

  async fillForm(formData) {
    const results = [];

    for (const [selector, value] of Object.entries(formData)) {
      try {
        const element = document.querySelector(selector);
        if (!element) {
          results.push({ selector, success: false, error: 'Element not found' });
          continue;
        }

        if (element.tagName === 'SELECT') {
          await this.selectOption(selector, value);
        } else {
          await this.inputText(selector, value);
        }

        results.push({ selector, success: true });
      } catch (error) {
        results.push({ selector, success: false, error: error.message });
      }
    }

    return { results };
  }

  highlightElement(selector) {
    // Remove existing highlight
    this.removeHighlight();

    const element = document.querySelector(selector);
    if (!element) return;

    const rect = element.getBoundingClientRect();

    this.highlightOverlay = document.createElement('div');
    this.highlightOverlay.id = 'claude-highlight-overlay';
    this.highlightOverlay.style.cssText = `
      position: fixed;
      top: ${rect.top - 4}px;
      left: ${rect.left - 4}px;
      width: ${rect.width + 8}px;
      height: ${rect.height + 8}px;
      border: 2px solid #D97706;
      border-radius: 4px;
      background: rgba(217, 119, 6, 0.1);
      pointer-events: none;
      z-index: 999998;
      transition: all 0.2s;
    `;

    document.body.appendChild(this.highlightOverlay);

    // Auto-remove after 2 seconds
    setTimeout(() => this.removeHighlight(), 2000);
  }

  removeHighlight() {
    this.highlightOverlay?.remove();
    this.highlightOverlay = null;
  }

  getUniqueSelector(element) {
    // Try ID first
    if (element.id) {
      return `#${element.id}`;
    }

    // Try unique class combination
    if (element.className) {
      const classes = Array.from(element.classList).join('.');
      if (classes && document.querySelectorAll(`.${classes}`).length === 1) {
        return `.${classes}`;
      }
    }

    // Try data attributes
    for (const attr of element.attributes) {
      if (attr.name.startsWith('data-')) {
        const selector = `[${attr.name}="${attr.value}"]`;
        if (document.querySelectorAll(selector).length === 1) {
          return selector;
        }
      }
    }

    // Build path from root
    const path = [];
    let current = element;

    while (current && current !== document.body) {
      let selector = current.tagName.toLowerCase();

      if (current.id) {
        selector = `#${current.id}`;
        path.unshift(selector);
        break;
      }

      const siblings = current.parentElement?.children;
      if (siblings && siblings.length > 1) {
        const index = Array.from(siblings).indexOf(current) + 1;
        selector += `:nth-child(${index})`;
      }

      path.unshift(selector);
      current = current.parentElement;
    }

    return path.join(' > ');
  }

  setupContextMenu() {
    // Listen for context menu selections
    document.addEventListener('contextmenu', (event) => {
      // Store selected text for context menu actions
      const selectedText = this.getSelectedText();
      if (selectedText) {
        browser.runtime.sendMessage({
          action: 'setContextSelection',
          selectedText
        });
      }
    });
  }

  sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }
}

// Initialize content script
new ClaudeContentScript();
