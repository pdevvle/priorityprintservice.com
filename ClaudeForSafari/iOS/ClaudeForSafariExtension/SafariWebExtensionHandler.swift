// SafariWebExtensionHandler.swift
// Claude for Safari - Safari Web Extension Native Handler
// Bridges JavaScript extension with native iOS capabilities

import SafariServices
import os.log

class SafariWebExtensionHandler: NSObject, NSExtensionRequestHandling {

    private let logger = Logger(subsystem: "com.claude.safari.extension", category: "handler")

    func beginRequest(with context: NSExtensionContext) {
        let item = context.inputItems[0] as! NSExtensionItem
        let message = item.userInfo?[SFExtensionMessageKey] as? [String: Any]

        logger.log("Received message from extension: \(message ?? [:], privacy: .public)")

        guard let action = message?["action"] as? String else {
            sendResponse(context: context, response: ["error": "No action specified"])
            return
        }

        handleAction(action: action, message: message, context: context)
    }

    private func handleAction(action: String, message: [String: Any]?, context: NSExtensionContext) {
        switch action {
        case "getSettings":
            handleGetSettings(context: context)

        case "saveSettings":
            handleSaveSettings(message: message, context: context)

        case "getApiKey":
            handleGetApiKey(context: context)

        case "openClaudeApp":
            handleOpenClaudeApp(message: message, context: context)

        case "shareToClaudeApp":
            handleShareToClaudeApp(message: message, context: context)

        case "saveWorkflow":
            handleSaveWorkflow(message: message, context: context)

        case "getWorkflows":
            handleGetWorkflows(context: context)

        case "log":
            handleLog(message: message, context: context)

        default:
            sendResponse(context: context, response: ["error": "Unknown action: \(action)"])
        }
    }

    // MARK: - Action Handlers

    private func handleGetSettings(context: NSExtensionContext) {
        let sharedDefaults = UserDefaults(suiteName: "group.com.claude.safari")

        let settings: [String: Any] = [
            "apiKey": sharedDefaults?.string(forKey: "apiKey") ?? "",
            "model": sharedDefaults?.string(forKey: "model") ?? "claude-sonnet-4-20250514",
            "autoContext": sharedDefaults?.bool(forKey: "autoContext") ?? true,
            "saveHistory": sharedDefaults?.bool(forKey: "saveHistory") ?? true
        ]

        sendResponse(context: context, response: ["settings": settings])
    }

    private func handleSaveSettings(message: [String: Any]?, context: NSExtensionContext) {
        guard let settings = message?["settings"] as? [String: Any] else {
            sendResponse(context: context, response: ["error": "No settings provided"])
            return
        }

        let sharedDefaults = UserDefaults(suiteName: "group.com.claude.safari")

        if let apiKey = settings["apiKey"] as? String {
            sharedDefaults?.set(apiKey, forKey: "apiKey")
        }
        if let model = settings["model"] as? String {
            sharedDefaults?.set(model, forKey: "model")
        }
        if let autoContext = settings["autoContext"] as? Bool {
            sharedDefaults?.set(autoContext, forKey: "autoContext")
        }
        if let saveHistory = settings["saveHistory"] as? Bool {
            sharedDefaults?.set(saveHistory, forKey: "saveHistory")
        }

        sendResponse(context: context, response: ["success": true])
    }

    private func handleGetApiKey(context: NSExtensionContext) {
        let sharedDefaults = UserDefaults(suiteName: "group.com.claude.safari")
        let apiKey = sharedDefaults?.string(forKey: "apiKey") ?? ""

        sendResponse(context: context, response: ["apiKey": apiKey])
    }

    private func handleOpenClaudeApp(message: [String: Any]?, context: NSExtensionContext) {
        // Signal to the containing app to open Claude
        // Note: Extensions can't directly open other apps, but we can notify the user
        let prompt = message?["prompt"] as? String ?? ""

        // Store prompt for the app to pick up
        let sharedDefaults = UserDefaults(suiteName: "group.com.claude.safari")
        sharedDefaults?.set(prompt, forKey: "pendingPrompt")

        sendResponse(context: context, response: ["success": true, "message": "Prompt saved for Claude app"])
    }

    private func handleShareToClaudeApp(message: [String: Any]?, context: NSExtensionContext) {
        guard let content = message?["content"] as? String else {
            sendResponse(context: context, response: ["error": "No content to share"])
            return
        }

        // Store content for sharing
        let sharedDefaults = UserDefaults(suiteName: "group.com.claude.safari")
        sharedDefaults?.set(content, forKey: "sharedContent")
        sharedDefaults?.set(Date(), forKey: "sharedContentTimestamp")

        sendResponse(context: context, response: ["success": true])
    }

    private func handleSaveWorkflow(message: [String: Any]?, context: NSExtensionContext) {
        guard let workflow = message?["workflow"] as? [String: Any],
              let workflowId = workflow["id"] as? String else {
            sendResponse(context: context, response: ["error": "Invalid workflow data"])
            return
        }

        let sharedDefaults = UserDefaults(suiteName: "group.com.claude.safari")
        var workflows = sharedDefaults?.dictionary(forKey: "workflows") ?? [:]
        workflows[workflowId] = workflow
        sharedDefaults?.set(workflows, forKey: "workflows")

        sendResponse(context: context, response: ["success": true])
    }

    private func handleGetWorkflows(context: NSExtensionContext) {
        let sharedDefaults = UserDefaults(suiteName: "group.com.claude.safari")
        let workflows = sharedDefaults?.dictionary(forKey: "workflows") ?? [:]

        sendResponse(context: context, response: ["workflows": workflows])
    }

    private func handleLog(message: [String: Any]?, context: NSExtensionContext) {
        if let logMessage = message?["message"] as? String {
            logger.log("Extension log: \(logMessage, privacy: .public)")
        }
        sendResponse(context: context, response: ["success": true])
    }

    // MARK: - Response Helper

    private func sendResponse(context: NSExtensionContext, response: [String: Any]) {
        let item = NSExtensionItem()
        item.userInfo = [SFExtensionMessageKey: response]

        context.completeRequest(returningItems: [item], completionHandler: nil)
    }
}
