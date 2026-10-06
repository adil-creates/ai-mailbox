# 🤝 Contributing to AI Mailbox Pro

Thank you for your interest in contributing to **AI Mailbox Pro**! We welcome bug fixes, documentation improvements, features, and feedback from the community.

---

## Code of Conduct

By participating in this project, you agree to abide by the terms of our [Code of Conduct](CODE_OF_CONDUCT.md).

---

## How Can I Contribute?

### 1. Reporting Bugs
- Search existing GitHub Issues before submitting a new one to avoid duplicates.
- Use the **Bug Report** template.
- Provide clear reproduction steps, environment details (PHP version, browser, OS), and logs if possible.

### 2. Suggesting Enhancements
- Open an issue using the **Feature Request** template.
- Describe the problem you are solving, why the feature is needed, and any potential implementation ideas.

### 3. Submitting Pull Requests (PRs)
1. **Fork** the repository and clone your fork locally.
2. Create a new topic branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. Make your changes:
   - Follow clean code practices and maintain consistency with the existing architecture.
   - Ensure PHP syntax checks pass: `php -l <file>`
   - Ensure frontend builds without errors: `npm run build`
4. Commit your changes with conventional commit messages:
   ```bash
   git commit -m "feat: add support for OAuth2 authentication"
   ```
5. Push to your fork and submit a **Pull Request** against the `main` branch.

---

## Coding Standards

### Backend (PHP)
- Adhere to PSR-12 coding guidelines.
- Always use prepared statements with PDO for database access.
- Never hardcode credentials, tokens, or encryption keys in source files.
- Ensure error handling produces structured JSON responses.

### Frontend (React / TypeScript)
- Use functional components with React Hooks.
- Avoid loose `any` types; define clear TypeScript interfaces for data models.
- Sanitize any raw HTML using `DOMPurify` before rendering.
- Follow Tailwind CSS utility class ordering.

---

## License

By contributing your code to AI Mailbox Pro, you agree that your contributions will be licensed under the project's [MIT License](LICENSE).
