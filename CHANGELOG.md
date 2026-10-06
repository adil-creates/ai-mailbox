# 📝 Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] - 2026-10-06

### Added
- **Multi-Account Switching**: Capsule tab interface for rapid switching between multiple IMAP/SMTP mailboxes.
- **Virtualized Thread List**: Infinite scrolling using `@tanstack/react-virtual` capable of handling large message counts.
- **Deliverability & Warmup Engine**:
  - 28-day ramp-up schedule with daily quota scaling (5 to 40 emails/day).
  - Rolling bounce rate calculations with auto-pause tripwires at 2% bounce rate.
- **Security & Cryptography**:
  - OpenSSL AES-256-CBC encryption for mailbox passwords stored in MySQL.
  - DOMPurify HTML sanitization protecting against XSS attacks in email viewing.
  - Zero hardcoded secrets; 100% environment-driven configuration.
- **AI Response Drafting & Approval**:
  - AI draft generation review gate.
  - 1-click draft approval routing directly to the background sending queue.
- **Asynchronous Background Processing**:
  - `sync_mailbox.php` CLI/cron runner for IMAP inbox ingestion.
  - `process_mail_queue.php` CLI/cron runner for SMTP dispatching via PHPMailer.
- **5-Layer Email Verification**:
  - Syntax check, MX DNS lookup, disposable domain detection, and role-based filtering.
