# Claude for Safari - iOS Extension

A Safari Web Extension that brings Claude AI to every webpage on iOS, similar to Claude for Chrome.

## Features

### Core Capabilities
- **Page Understanding**: Claude can read and understand any webpage content
- **Quick Actions**: One-tap summarize, explain, translate, and extract data
- **Chat Interface**: Ask questions about the current page in a sleek popup
- **Context Awareness**: Automatically includes page context in conversations

### Browser Automation
- **Form Filling**: Let Claude help fill out forms intelligently
- **Click Sequences**: Automate repetitive click patterns
- **Workflow Recording**: Record your actions and replay them later
- **Data Extraction**: Pull structured data from tables, lists, and content

### Deep Integration
- **Native iOS App**: Container app for settings and extension management
- **Claude App Integration**: Open conversations directly in the Claude iOS app
- **Context Menu Actions**: Right-click to summarize, explain, or translate selected text
- **Shared Storage**: Settings sync between app and extension via App Groups

## Project Structure

```
ClaudeForSafari/
├── Extension/                    # Safari Web Extension
│   ├── manifest.json            # Extension manifest
│   ├── popup/                   # Extension popup UI
│   │   ├── popup.html
│   │   ├── popup.css
│   │   └── popup.js
│   ├── content/                 # Content scripts
│   │   ├── content.js
│   │   └── content.css
│   ├── background/              # Background service worker
│   │   └── background.js
│   ├── shared/                  # Shared utilities
│   │   ├── claude-api.js
│   │   └── utils.js
│   ├── welcome/                 # Welcome/onboarding page
│   │   └── welcome.html
│   └── images/                  # Extension icons
│       ├── icon-48.svg
│       ├── icon-96.svg
│       └── icon-128.svg
│
└── iOS/                         # iOS Container App
    ├── ClaudeForSafari/         # Main app target
    │   ├── ClaudeForSafariApp.swift
    │   ├── ContentView.swift
    │   ├── SettingsView.swift
    │   ├── OnboardingView.swift
    │   └── Info.plist
    │
    └── ClaudeForSafariExtension/ # Safari Extension target
        ├── SafariWebExtensionHandler.swift
        └── Info.plist
```

## Setup Instructions

### Prerequisites
- Xcode 15.0 or later
- iOS 17.0+ deployment target
- Apple Developer account (for testing on device)
- Claude API key from [console.anthropic.com](https://console.anthropic.com)

### Building the Project

1. **Open in Xcode**
   ```bash
   cd ClaudeForSafari/iOS
   open ClaudeForSafari.xcodeproj
   ```

2. **Configure Signing**
   - Select the ClaudeForSafari target
   - Go to Signing & Capabilities
   - Select your development team
   - Repeat for ClaudeForSafariExtension target

3. **Configure App Groups**
   - Add the "App Groups" capability to both targets
   - Create a group: `group.com.claude.safari`

4. **Build & Run**
   - Select your target device/simulator
   - Press Cmd+R to build and run

### Enabling the Extension

1. Open the Claude for Safari app
2. Follow the onboarding instructions
3. Go to Settings > Safari > Extensions
4. Enable "Claude for Safari"
5. Grant "Allow" permission for all websites (or specific sites)

### Configuration

1. Open the Claude for Safari app or extension popup
2. Go to Settings (gear icon)
3. Enter your Claude API key
4. Select your preferred model
5. Configure preferences

## Usage

### Quick Actions
Click the Claude icon in Safari's toolbar to access:
- **Summarize**: Get a concise summary of the page
- **Explain**: Have complex content explained simply
- **Extract**: Pull structured data from the page
- **Translate**: Translate content to another language

### Chat Interface
1. Click the Claude icon to open the popup
2. Type your question about the page
3. Claude will respond with context from the current page

### Workflow Recording
1. Click "Record Workflow" in the automation panel
2. Perform your desired actions on the page
3. Stop recording when done
4. Save the workflow for future replay

### Context Menu
Right-click (long-press) on selected text to:
- Ask Claude to summarize
- Get an explanation
- Translate the selection
- Open in Claude app

## API

### Content Script Messages

```javascript
// Get page context
browser.tabs.sendMessage(tabId, { action: 'getPageContext' });

// Get selected text
browser.tabs.sendMessage(tabId, { action: 'getSelection' });

// Execute automation action
browser.tabs.sendMessage(tabId, {
  action: 'executeAction',
  actionData: { type: 'click', selector: '#button' }
});

// Fill form fields
browser.tabs.sendMessage(tabId, {
  action: 'fillForm',
  formData: { '#email': 'user@example.com' }
});
```

### Native Handler Messages

```javascript
// Get settings from native app
browser.runtime.sendNativeMessage('application.id', {
  action: 'getSettings'
});

// Save workflow
browser.runtime.sendNativeMessage('application.id', {
  action: 'saveWorkflow',
  workflow: { id: 'wf-1', actions: [...] }
});
```

## Privacy & Security

- **Local Storage**: API keys are stored securely on-device using iOS Keychain
- **No Tracking**: The extension doesn't collect any usage data
- **Explicit Consent**: Page content is only sent when you explicitly ask Claude
- **HTTPS Only**: All API communication uses secure HTTPS connections

## Troubleshooting

### Extension Not Working
1. Ensure the extension is enabled in Safari settings
2. Check that you've granted website permissions
3. Verify your API key is correctly entered

### API Errors
1. Verify your API key is valid at console.anthropic.com
2. Check your API usage limits
3. Ensure you have internet connectivity

### Performance Issues
1. Try refreshing the page
2. Clear the extension's conversation history
3. Disable auto-context for large pages

## Contributing

Contributions are welcome! Please read our contributing guidelines before submitting PRs.

## License

MIT License - see LICENSE file for details.

## Acknowledgments

- [Anthropic](https://anthropic.com) for Claude AI
- Safari Web Extensions documentation
- The open-source community

---

Built with care by developers who love both Claude and Safari.
