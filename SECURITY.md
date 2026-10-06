# 🛡️ Security Policy

## Supported Versions

Only the latest release receives security updates and vulnerability patches.

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |
| < 1.0   | :x:                |

---

## Reporting a Vulnerability

The safety of our users and their email infrastructure is our top priority. If you believe you have found a security vulnerability in **AI Mailbox Pro**, please do **NOT** open a public GitHub issue.

Instead, please report it via one of the following channels:

1. **GitHub Security Advisory**: Submit a private advisory directly on GitHub via the **Security** tab -> **Report a vulnerability**.
2. **Email Disclosure**: Send details to the maintainer via the email configured on the GitHub profile.

### What to Include in Your Report
To help us triage and resolve the issue quickly, please provide:
- A clear description of the vulnerability.
- Steps to reproduce the issue (proof-of-concept scripts, screenshots, or curl commands).
- The potential impact of the vulnerability.
- Any suggested fixes or remediation steps.

### Our Response Process
- **Acknowledgement**: We aim to acknowledge receipt of security reports within 48 hours.
- **Assessment**: We will confirm the vulnerability and provide an estimated timeline for a patch.
- **Resolution**: Once a fix is verified, a patched release will be published along with appropriate security advisory credit.

---

## Security Architecture Highlights

AI Mailbox Pro implements defense-in-depth principles:
- **AES-256-CBC Encryption**: All IMAP and SMTP passwords are encrypted at rest using PBKDF2/SHA-256 derived keys and random IVs before being stored in the database.
- **Strict HTML Sanitization**: Inbound email HTML rendering is sanitized using [DOMPurify](https://github.com/cure53/DOMPurify) to prevent Cross-Site Scripting (XSS).
- **Prepared Statements**: All database operations strictly employ PDO prepared statements to eliminate SQL injection risks.
- **CSRF Token Validation**: State-modifying requests require anti-CSRF token verification.
