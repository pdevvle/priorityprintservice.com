// Claude for Safari - Claude API Client
// Handles communication with Claude API

export class ClaudeAPI {
  constructor(apiKey, model = 'claude-sonnet-4-20250514') {
    this.apiKey = apiKey;
    this.model = model;
    this.baseUrl = 'https://api.anthropic.com/v1';
    this.maxTokens = 4096;
  }

  async chat(userMessage, conversationHistory = []) {
    // Build messages array
    const messages = this.formatMessages(conversationHistory, userMessage);

    const requestBody = {
      model: this.model,
      max_tokens: this.maxTokens,
      messages: messages,
      system: this.getSystemPrompt()
    };

    try {
      const response = await fetch(`${this.baseUrl}/messages`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': this.apiKey,
          'anthropic-version': '2023-06-01',
          'anthropic-dangerous-direct-browser-access': 'true'
        },
        body: JSON.stringify(requestBody)
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.error?.message || `API error: ${response.status}`);
      }

      const data = await response.json();

      // Extract text from response
      const assistantMessage = data.content
        .filter(block => block.type === 'text')
        .map(block => block.text)
        .join('\n');

      return assistantMessage;
    } catch (error) {
      console.error('Claude API error:', error);
      throw error;
    }
  }

  formatMessages(history, newMessage) {
    const messages = [];

    // Add conversation history
    for (const msg of history) {
      messages.push({
        role: msg.role,
        content: msg.content
      });
    }

    // Add new user message
    messages.push({
      role: 'user',
      content: newMessage
    });

    return messages;
  }

  getSystemPrompt() {
    return `You are Claude, an AI assistant created by Anthropic, integrated into Safari as a browser extension. You help users understand web content, extract information, and automate browser tasks.

Key capabilities:
- Summarize and explain webpage content
- Extract structured data from pages
- Help fill out forms
- Translate text
- Answer questions about the current page
- Guide users through browser automation

Guidelines:
- Be concise and helpful
- When analyzing page content, focus on the most relevant information
- For data extraction, format output clearly (use tables, lists, or JSON as appropriate)
- If asked about forms, identify all fields and their purposes
- When asked to translate, maintain the original meaning and tone
- For technical content, adjust explanation level based on context

You have access to the page content that the user is viewing. Use this context to provide relevant, accurate assistance.`;
  }

  setModel(model) {
    this.model = model;
  }

  setMaxTokens(maxTokens) {
    this.maxTokens = maxTokens;
  }

  // Stream response (for future implementation)
  async *chatStream(userMessage, conversationHistory = []) {
    const messages = this.formatMessages(conversationHistory, userMessage);

    const requestBody = {
      model: this.model,
      max_tokens: this.maxTokens,
      messages: messages,
      system: this.getSystemPrompt(),
      stream: true
    };

    try {
      const response = await fetch(`${this.baseUrl}/messages`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': this.apiKey,
          'anthropic-version': '2023-06-01',
          'anthropic-dangerous-direct-browser-access': 'true'
        },
        body: JSON.stringify(requestBody)
      });

      if (!response.ok) {
        throw new Error(`API error: ${response.status}`);
      }

      const reader = response.body.getReader();
      const decoder = new TextDecoder();

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        const chunk = decoder.decode(value);
        const lines = chunk.split('\n');

        for (const line of lines) {
          if (line.startsWith('data: ')) {
            const data = line.slice(6);
            if (data === '[DONE]') return;

            try {
              const parsed = JSON.parse(data);
              if (parsed.type === 'content_block_delta' && parsed.delta?.text) {
                yield parsed.delta.text;
              }
            } catch (e) {
              // Skip invalid JSON
            }
          }
        }
      }
    } catch (error) {
      console.error('Claude API stream error:', error);
      throw error;
    }
  }
}

// Tool definitions for browser automation
export const BROWSER_TOOLS = [
  {
    name: 'click_element',
    description: 'Click on an element on the page',
    input_schema: {
      type: 'object',
      properties: {
        selector: {
          type: 'string',
          description: 'CSS selector for the element to click'
        },
        description: {
          type: 'string',
          description: 'Human-readable description of what is being clicked'
        }
      },
      required: ['selector']
    }
  },
  {
    name: 'fill_input',
    description: 'Fill a text input or textarea',
    input_schema: {
      type: 'object',
      properties: {
        selector: {
          type: 'string',
          description: 'CSS selector for the input element'
        },
        value: {
          type: 'string',
          description: 'Text to enter into the input'
        }
      },
      required: ['selector', 'value']
    }
  },
  {
    name: 'select_option',
    description: 'Select an option from a dropdown',
    input_schema: {
      type: 'object',
      properties: {
        selector: {
          type: 'string',
          description: 'CSS selector for the select element'
        },
        value: {
          type: 'string',
          description: 'Value of the option to select'
        }
      },
      required: ['selector', 'value']
    }
  },
  {
    name: 'scroll_to',
    description: 'Scroll to an element on the page',
    input_schema: {
      type: 'object',
      properties: {
        selector: {
          type: 'string',
          description: 'CSS selector for the element to scroll to'
        }
      },
      required: ['selector']
    }
  },
  {
    name: 'extract_text',
    description: 'Extract text content from an element',
    input_schema: {
      type: 'object',
      properties: {
        selector: {
          type: 'string',
          description: 'CSS selector for the element to extract text from'
        }
      },
      required: ['selector']
    }
  },
  {
    name: 'get_page_info',
    description: 'Get information about the current page',
    input_schema: {
      type: 'object',
      properties: {
        include_content: {
          type: 'boolean',
          description: 'Whether to include page content'
        },
        include_forms: {
          type: 'boolean',
          description: 'Whether to include form information'
        }
      }
    }
  }
];
