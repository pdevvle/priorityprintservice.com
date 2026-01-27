// ContentView.swift
// Claude for Safari - Main Content View

import SwiftUI
import SafariServices

struct ContentView: View {
    @EnvironmentObject var appState: AppState
    @State private var showSettings = false
    @State private var showOnboarding = false

    var body: some View {
        NavigationStack {
            ZStack {
                // Background
                Color.black.ignoresSafeArea()

                VStack(spacing: 0) {
                    // Main content
                    ScrollView {
                        VStack(spacing: 24) {
                            // Hero Section
                            heroSection

                            // Extension Status
                            extensionStatusCard

                            // Quick Actions
                            quickActionsSection

                            // Features Section
                            featuresSection

                            // How to Enable
                            if !appState.isExtensionEnabled {
                                enableInstructionsCard
                            }
                        }
                        .padding()
                    }
                }
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button(action: { showSettings = true }) {
                        Image(systemName: "gearshape.fill")
                            .foregroundColor(.orange)
                    }
                }
            }
            .sheet(isPresented: $showSettings) {
                SettingsView()
            }
            .sheet(isPresented: $showOnboarding) {
                OnboardingView()
            }
            .onAppear {
                if !UserDefaults.standard.bool(forKey: "hasCompletedOnboarding") {
                    showOnboarding = true
                }
            }
        }
    }

    // MARK: - Hero Section
    var heroSection: some View {
        VStack(spacing: 16) {
            // Claude Logo
            ZStack {
                Circle()
                    .fill(
                        LinearGradient(
                            colors: [Color.orange, Color.orange.opacity(0.7)],
                            startPoint: .topLeading,
                            endPoint: .bottomTrailing
                        )
                    )
                    .frame(width: 80, height: 80)

                Image(systemName: "bubble.left.and.bubble.right.fill")
                    .font(.system(size: 32))
                    .foregroundColor(.white)
            }

            Text("Claude for Safari")
                .font(.system(size: 28, weight: .bold))
                .foregroundColor(.white)

            Text("Bring the power of Claude AI to every webpage")
                .font(.subheadline)
                .foregroundColor(.gray)
                .multilineTextAlignment(.center)
        }
        .padding(.vertical, 24)
    }

    // MARK: - Extension Status Card
    var extensionStatusCard: some View {
        HStack(spacing: 16) {
            ZStack {
                Circle()
                    .fill(appState.isExtensionEnabled ? Color.green.opacity(0.2) : Color.red.opacity(0.2))
                    .frame(width: 48, height: 48)

                Image(systemName: appState.isExtensionEnabled ? "checkmark.circle.fill" : "xmark.circle.fill")
                    .font(.system(size: 24))
                    .foregroundColor(appState.isExtensionEnabled ? .green : .red)
            }

            VStack(alignment: .leading, spacing: 4) {
                Text("Safari Extension")
                    .font(.headline)
                    .foregroundColor(.white)

                Text(appState.isExtensionEnabled ? "Enabled and ready" : "Not enabled yet")
                    .font(.subheadline)
                    .foregroundColor(.gray)
            }

            Spacer()

            Button(action: openSafariSettings) {
                Text(appState.isExtensionEnabled ? "Manage" : "Enable")
                    .font(.subheadline.bold())
                    .foregroundColor(.black)
                    .padding(.horizontal, 16)
                    .padding(.vertical, 8)
                    .background(Color.orange)
                    .cornerRadius(20)
            }
        }
        .padding()
        .background(Color.white.opacity(0.05))
        .cornerRadius(16)
    }

    // MARK: - Quick Actions Section
    var quickActionsSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Quick Actions")
                .font(.headline)
                .foregroundColor(.white)

            LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                QuickActionButton(
                    icon: "doc.text.fill",
                    title: "Summarize",
                    color: .blue
                ) {
                    openClaudeApp(action: "summarize")
                }

                QuickActionButton(
                    icon: "questionmark.circle.fill",
                    title: "Explain",
                    color: .purple
                ) {
                    openClaudeApp(action: "explain")
                }

                QuickActionButton(
                    icon: "globe",
                    title: "Translate",
                    color: .green
                ) {
                    openClaudeApp(action: "translate")
                }

                QuickActionButton(
                    icon: "tablecells.fill",
                    title: "Extract",
                    color: .orange
                ) {
                    openClaudeApp(action: "extract")
                }
            }
        }
    }

    // MARK: - Features Section
    var featuresSection: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("Features")
                .font(.headline)
                .foregroundColor(.white)

            FeatureRow(
                icon: "doc.text.magnifyingglass",
                title: "Read & Understand",
                description: "Claude can read any webpage and answer questions about its content"
            )

            FeatureRow(
                icon: "hand.tap.fill",
                title: "Browser Automation",
                description: "Fill forms, click buttons, and automate repetitive tasks"
            )

            FeatureRow(
                icon: "arrow.triangle.branch",
                title: "Workflow Recording",
                description: "Record your actions and let Claude repeat them automatically"
            )

            FeatureRow(
                icon: "square.and.arrow.down.fill",
                title: "Data Extraction",
                description: "Extract structured data from tables, lists, and page content"
            )
        }
        .padding()
        .background(Color.white.opacity(0.05))
        .cornerRadius(16)
    }

    // MARK: - Enable Instructions
    var enableInstructionsCard: some View {
        VStack(alignment: .leading, spacing: 16) {
            HStack {
                Image(systemName: "info.circle.fill")
                    .foregroundColor(.orange)
                Text("How to Enable")
                    .font(.headline)
                    .foregroundColor(.white)
            }

            VStack(alignment: .leading, spacing: 12) {
                InstructionStep(number: 1, text: "Open Settings app")
                InstructionStep(number: 2, text: "Tap Safari > Extensions")
                InstructionStep(number: 3, text: "Find \"Claude for Safari\"")
                InstructionStep(number: 4, text: "Toggle it ON")
                InstructionStep(number: 5, text: "Allow for all websites")
            }

            Button(action: openSafariSettings) {
                HStack {
                    Text("Open Safari Settings")
                    Image(systemName: "arrow.right")
                }
                .font(.subheadline.bold())
                .foregroundColor(.white)
                .frame(maxWidth: .infinity)
                .padding()
                .background(Color.orange)
                .cornerRadius(12)
            }
        }
        .padding()
        .background(Color.orange.opacity(0.1))
        .cornerRadius(16)
        .overlay(
            RoundedRectangle(cornerRadius: 16)
                .stroke(Color.orange.opacity(0.3), lineWidth: 1)
        )
    }

    // MARK: - Actions
    func openSafariSettings() {
        if let url = URL(string: UIApplication.openSettingsURLString) {
            UIApplication.shared.open(url)
        }
    }

    func openClaudeApp(action: String) {
        if let url = URL(string: "claude://\(action)") {
            UIApplication.shared.open(url)
        }
    }
}

// MARK: - Supporting Views

struct QuickActionButton: View {
    let icon: String
    let title: String
    let color: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 12) {
                Image(systemName: icon)
                    .font(.system(size: 24))
                    .foregroundColor(color)

                Text(title)
                    .font(.subheadline.bold())
                    .foregroundColor(.white)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 20)
            .background(Color.white.opacity(0.05))
            .cornerRadius(12)
        }
    }
}

struct FeatureRow: View {
    let icon: String
    let title: String
    let description: String

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: icon)
                .font(.system(size: 20))
                .foregroundColor(.orange)
                .frame(width: 32)

            VStack(alignment: .leading, spacing: 4) {
                Text(title)
                    .font(.subheadline.bold())
                    .foregroundColor(.white)

                Text(description)
                    .font(.caption)
                    .foregroundColor(.gray)
            }
        }
    }
}

struct InstructionStep: View {
    let number: Int
    let text: String

    var body: some View {
        HStack(spacing: 12) {
            Text("\(number)")
                .font(.caption.bold())
                .foregroundColor(.orange)
                .frame(width: 24, height: 24)
                .background(Color.orange.opacity(0.2))
                .cornerRadius(12)

            Text(text)
                .font(.subheadline)
                .foregroundColor(.white)
        }
    }
}

#Preview {
    ContentView()
        .environmentObject(AppState())
}
