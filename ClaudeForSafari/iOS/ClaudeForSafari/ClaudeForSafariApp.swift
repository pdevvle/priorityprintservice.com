// ClaudeForSafariApp.swift
// Claude for Safari - iOS Container App
// This app contains the Safari Web Extension for Claude

import SwiftUI

@main
struct ClaudeForSafariApp: App {
    @StateObject private var appState = AppState()

    var body: some Scene {
        WindowGroup {
            ContentView()
                .environmentObject(appState)
                .preferredColorScheme(.dark)
        }
    }
}

// App-wide state management
class AppState: ObservableObject {
    @Published var isExtensionEnabled: Bool = false
    @Published var apiKey: String = ""
    @Published var selectedModel: ClaudeModel = .sonnet
    @Published var conversationHistory: [Message] = []

    init() {
        loadSettings()
        checkExtensionStatus()
    }

    func loadSettings() {
        if let key = UserDefaults.standard.string(forKey: "apiKey") {
            apiKey = key
        }
        if let modelRaw = UserDefaults.standard.string(forKey: "model"),
           let model = ClaudeModel(rawValue: modelRaw) {
            selectedModel = model
        }
    }

    func saveSettings() {
        UserDefaults.standard.set(apiKey, forKey: "apiKey")
        UserDefaults.standard.set(selectedModel.rawValue, forKey: "model")

        // Sync to extension via App Groups
        if let sharedDefaults = UserDefaults(suiteName: "group.com.claude.safari") {
            sharedDefaults.set(apiKey, forKey: "apiKey")
            sharedDefaults.set(selectedModel.rawValue, forKey: "model")
        }
    }

    func checkExtensionStatus() {
        // Check if Safari extension is enabled
        // This would use SFSafariExtensionManager in a real implementation
        isExtensionEnabled = true // Placeholder
    }
}

// Claude model options
enum ClaudeModel: String, CaseIterable, Identifiable {
    case sonnet = "claude-sonnet-4-20250514"
    case opus = "claude-opus-4-5-20251101"
    case haiku = "claude-3-5-haiku-20241022"

    var id: String { rawValue }

    var displayName: String {
        switch self {
        case .sonnet: return "Claude Sonnet 4"
        case .opus: return "Claude Opus 4.5"
        case .haiku: return "Claude 3.5 Haiku"
        }
    }

    var description: String {
        switch self {
        case .sonnet: return "Best balance of speed and intelligence"
        case .opus: return "Most capable, best for complex tasks"
        case .haiku: return "Fastest, most cost-effective"
        }
    }
}

// Message model
struct Message: Identifiable, Codable {
    let id: UUID
    let role: MessageRole
    let content: String
    let timestamp: Date

    init(role: MessageRole, content: String) {
        self.id = UUID()
        self.role = role
        self.content = content
        self.timestamp = Date()
    }
}

enum MessageRole: String, Codable {
    case user
    case assistant
}
