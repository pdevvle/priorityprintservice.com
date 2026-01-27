// SettingsView.swift
// Claude for Safari - Settings Configuration

import SwiftUI

struct SettingsView: View {
    @EnvironmentObject var appState: AppState
    @Environment(\.dismiss) var dismiss

    @State private var apiKey: String = ""
    @State private var selectedModel: ClaudeModel = .sonnet
    @State private var autoIncludeContext: Bool = true
    @State private var saveHistory: Bool = true
    @State private var showApiKeyHelp: Bool = false

    var body: some View {
        NavigationStack {
            ZStack {
                Color.black.ignoresSafeArea()

                ScrollView {
                    VStack(spacing: 24) {
                        // API Key Section
                        apiKeySection

                        // Model Selection
                        modelSection

                        // Preferences
                        preferencesSection

                        // About Section
                        aboutSection

                        // Clear Data
                        dangerZoneSection
                    }
                    .padding()
                }
            }
            .navigationTitle("Settings")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Cancel") {
                        dismiss()
                    }
                    .foregroundColor(.orange)
                }

                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Save") {
                        saveSettings()
                        dismiss()
                    }
                    .foregroundColor(.orange)
                    .fontWeight(.bold)
                }
            }
            .onAppear {
                apiKey = appState.apiKey
                selectedModel = appState.selectedModel
            }
            .sheet(isPresented: $showApiKeyHelp) {
                ApiKeyHelpView()
            }
        }
    }

    // MARK: - API Key Section
    var apiKeySection: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text("API Key")
                    .font(.headline)
                    .foregroundColor(.white)

                Spacer()

                Button(action: { showApiKeyHelp = true }) {
                    Image(systemName: "questionmark.circle")
                        .foregroundColor(.orange)
                }
            }

            SecureField("sk-ant-...", text: $apiKey)
                .textFieldStyle(CustomTextFieldStyle())

            Text("Your API key is stored securely on your device")
                .font(.caption)
                .foregroundColor(.gray)
        }
        .padding()
        .background(Color.white.opacity(0.05))
        .cornerRadius(16)
    }

    // MARK: - Model Section
    var modelSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Model")
                .font(.headline)
                .foregroundColor(.white)

            ForEach(ClaudeModel.allCases) { model in
                ModelSelectionRow(
                    model: model,
                    isSelected: selectedModel == model
                ) {
                    selectedModel = model
                }
            }
        }
        .padding()
        .background(Color.white.opacity(0.05))
        .cornerRadius(16)
    }

    // MARK: - Preferences Section
    var preferencesSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Preferences")
                .font(.headline)
                .foregroundColor(.white)

            Toggle(isOn: $autoIncludeContext) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("Auto-include page context")
                        .foregroundColor(.white)
                    Text("Automatically send page content with your messages")
                        .font(.caption)
                        .foregroundColor(.gray)
                }
            }
            .tint(.orange)

            Divider()
                .background(Color.white.opacity(0.1))

            Toggle(isOn: $saveHistory) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("Save conversation history")
                        .foregroundColor(.white)
                    Text("Keep your chat history between sessions")
                        .font(.caption)
                        .foregroundColor(.gray)
                }
            }
            .tint(.orange)
        }
        .padding()
        .background(Color.white.opacity(0.05))
        .cornerRadius(16)
    }

    // MARK: - About Section
    var aboutSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("About")
                .font(.headline)
                .foregroundColor(.white)

            HStack {
                Text("Version")
                    .foregroundColor(.white)
                Spacer()
                Text("1.0.0")
                    .foregroundColor(.gray)
            }

            Divider()
                .background(Color.white.opacity(0.1))

            Link(destination: URL(string: "https://claude.ai")!) {
                HStack {
                    Text("Claude by Anthropic")
                        .foregroundColor(.white)
                    Spacer()
                    Image(systemName: "arrow.up.right")
                        .foregroundColor(.orange)
                }
            }

            Divider()
                .background(Color.white.opacity(0.1))

            Link(destination: URL(string: "https://docs.anthropic.com")!) {
                HStack {
                    Text("API Documentation")
                        .foregroundColor(.white)
                    Spacer()
                    Image(systemName: "arrow.up.right")
                        .foregroundColor(.orange)
                }
            }
        }
        .padding()
        .background(Color.white.opacity(0.05))
        .cornerRadius(16)
    }

    // MARK: - Danger Zone
    var dangerZoneSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Data")
                .font(.headline)
                .foregroundColor(.white)

            Button(action: clearHistory) {
                HStack {
                    Image(systemName: "trash")
                    Text("Clear Conversation History")
                }
                .foregroundColor(.red)
            }

            Divider()
                .background(Color.white.opacity(0.1))

            Button(action: resetSettings) {
                HStack {
                    Image(systemName: "arrow.counterclockwise")
                    Text("Reset All Settings")
                }
                .foregroundColor(.red)
            }
        }
        .padding()
        .background(Color.white.opacity(0.05))
        .cornerRadius(16)
    }

    // MARK: - Actions
    func saveSettings() {
        appState.apiKey = apiKey
        appState.selectedModel = selectedModel
        appState.saveSettings()
    }

    func clearHistory() {
        appState.conversationHistory = []
        UserDefaults.standard.removeObject(forKey: "conversationHistory")
    }

    func resetSettings() {
        apiKey = ""
        selectedModel = .sonnet
        autoIncludeContext = true
        saveHistory = true
    }
}

// MARK: - Supporting Views

struct ModelSelectionRow: View {
    let model: ClaudeModel
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(model.displayName)
                        .foregroundColor(.white)
                        .fontWeight(isSelected ? .bold : .regular)

                    Text(model.description)
                        .font(.caption)
                        .foregroundColor(.gray)
                }

                Spacer()

                if isSelected {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundColor(.orange)
                }
            }
            .padding(.vertical, 8)
        }
    }
}

struct CustomTextFieldStyle: TextFieldStyle {
    func _body(configuration: TextField<Self._Label>) -> some View {
        configuration
            .padding()
            .background(Color.white.opacity(0.1))
            .cornerRadius(10)
            .foregroundColor(.white)
    }
}

struct ApiKeyHelpView: View {
    @Environment(\.dismiss) var dismiss

    var body: some View {
        NavigationStack {
            ZStack {
                Color.black.ignoresSafeArea()

                ScrollView {
                    VStack(alignment: .leading, spacing: 20) {
                        Text("How to get your API key")
                            .font(.title2.bold())
                            .foregroundColor(.white)

                        VStack(alignment: .leading, spacing: 16) {
                            StepView(number: 1, text: "Go to console.anthropic.com")
                            StepView(number: 2, text: "Sign in or create an account")
                            StepView(number: 3, text: "Navigate to API Keys section")
                            StepView(number: 4, text: "Create a new API key")
                            StepView(number: 5, text: "Copy and paste it here")
                        }

                        Text("Your API key allows the extension to communicate directly with Claude. It's stored securely on your device and never shared.")
                            .font(.subheadline)
                            .foregroundColor(.gray)

                        Link(destination: URL(string: "https://console.anthropic.com/settings/keys")!) {
                            HStack {
                                Text("Open Anthropic Console")
                                Image(systemName: "arrow.up.right")
                            }
                            .font(.headline)
                            .foregroundColor(.black)
                            .frame(maxWidth: .infinity)
                            .padding()
                            .background(Color.orange)
                            .cornerRadius(12)
                        }
                    }
                    .padding()
                }
            }
            .navigationTitle("API Key Help")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Done") {
                        dismiss()
                    }
                    .foregroundColor(.orange)
                }
            }
        }
    }
}

struct StepView: View {
    let number: Int
    let text: String

    var body: some View {
        HStack(spacing: 12) {
            Text("\(number)")
                .font(.headline)
                .foregroundColor(.orange)
                .frame(width: 32, height: 32)
                .background(Color.orange.opacity(0.2))
                .cornerRadius(16)

            Text(text)
                .foregroundColor(.white)
        }
    }
}

#Preview {
    SettingsView()
        .environmentObject(AppState())
}
