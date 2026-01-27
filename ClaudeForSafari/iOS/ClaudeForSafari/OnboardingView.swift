// OnboardingView.swift
// Claude for Safari - Onboarding Experience

import SwiftUI

struct OnboardingView: View {
    @Environment(\.dismiss) var dismiss
    @State private var currentPage = 0

    let pages: [OnboardingPage] = [
        OnboardingPage(
            icon: "safari.fill",
            title: "Claude for Safari",
            description: "Bring the power of Claude AI to every webpage you visit. Ask questions, get summaries, and automate tasks.",
            color: .blue
        ),
        OnboardingPage(
            icon: "doc.text.magnifyingglass",
            title: "Understand Any Page",
            description: "Claude can read and understand the content of any webpage. Ask questions, get summaries, or have content explained in simple terms.",
            color: .purple
        ),
        OnboardingPage(
            icon: "hand.tap.fill",
            title: "Automate Tasks",
            description: "Let Claude fill forms, click buttons, and perform repetitive browser tasks for you. Record workflows and replay them anytime.",
            color: .orange
        ),
        OnboardingPage(
            icon: "square.and.arrow.down.fill",
            title: "Extract Data",
            description: "Pull structured information from pages - tables, lists, contact info, and more. Export data in any format you need.",
            color: .green
        ),
        OnboardingPage(
            icon: "checkmark.shield.fill",
            title: "Privacy First",
            description: "Your API key is stored securely on your device. Page content is only sent when you explicitly ask Claude for help.",
            color: .cyan
        )
    ]

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()

            VStack(spacing: 0) {
                // Page content
                TabView(selection: $currentPage) {
                    ForEach(0..<pages.count, id: \.self) { index in
                        OnboardingPageView(page: pages[index])
                            .tag(index)
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: .never))

                // Page indicators and buttons
                VStack(spacing: 24) {
                    // Page dots
                    HStack(spacing: 8) {
                        ForEach(0..<pages.count, id: \.self) { index in
                            Circle()
                                .fill(currentPage == index ? Color.orange : Color.white.opacity(0.3))
                                .frame(width: 8, height: 8)
                                .animation(.easeInOut, value: currentPage)
                        }
                    }

                    // Buttons
                    HStack(spacing: 16) {
                        if currentPage > 0 {
                            Button(action: { withAnimation { currentPage -= 1 } }) {
                                Text("Back")
                                    .font(.headline)
                                    .foregroundColor(.white)
                                    .frame(maxWidth: .infinity)
                                    .padding()
                                    .background(Color.white.opacity(0.1))
                                    .cornerRadius(12)
                            }
                        }

                        Button(action: {
                            if currentPage < pages.count - 1 {
                                withAnimation { currentPage += 1 }
                            } else {
                                completeOnboarding()
                            }
                        }) {
                            Text(currentPage == pages.count - 1 ? "Get Started" : "Next")
                                .font(.headline)
                                .foregroundColor(.black)
                                .frame(maxWidth: .infinity)
                                .padding()
                                .background(Color.orange)
                                .cornerRadius(12)
                        }
                    }

                    // Skip button
                    if currentPage < pages.count - 1 {
                        Button(action: completeOnboarding) {
                            Text("Skip")
                                .font(.subheadline)
                                .foregroundColor(.gray)
                        }
                    }
                }
                .padding(.horizontal, 24)
                .padding(.bottom, 40)
            }
        }
    }

    func completeOnboarding() {
        UserDefaults.standard.set(true, forKey: "hasCompletedOnboarding")
        dismiss()
    }
}

struct OnboardingPage: Identifiable {
    let id = UUID()
    let icon: String
    let title: String
    let description: String
    let color: Color
}

struct OnboardingPageView: View {
    let page: OnboardingPage

    var body: some View {
        VStack(spacing: 32) {
            Spacer()

            // Icon
            ZStack {
                Circle()
                    .fill(
                        LinearGradient(
                            colors: [page.color, page.color.opacity(0.6)],
                            startPoint: .topLeading,
                            endPoint: .bottomTrailing
                        )
                    )
                    .frame(width: 120, height: 120)

                Image(systemName: page.icon)
                    .font(.system(size: 48))
                    .foregroundColor(.white)
            }

            // Text content
            VStack(spacing: 16) {
                Text(page.title)
                    .font(.system(size: 28, weight: .bold))
                    .foregroundColor(.white)
                    .multilineTextAlignment(.center)

                Text(page.description)
                    .font(.body)
                    .foregroundColor(.gray)
                    .multilineTextAlignment(.center)
                    .lineSpacing(4)
            }
            .padding(.horizontal, 32)

            Spacer()
            Spacer()
        }
    }
}

#Preview {
    OnboardingView()
}
