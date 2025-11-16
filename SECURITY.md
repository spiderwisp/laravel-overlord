# Security Policy

## Supported Versions

We actively support the following versions of Laravel Overlord with security updates:

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security vulnerability in Laravel Overlord, please report it responsibly.

### How to Report

**Option 1: Built-in Bug/Issue Reporting Interface**

Laravel Overlord includes a built-in bug and issue reporting interface within the package. You can use this interface to report security vulnerabilities directly from your Laravel application.

**Option 2: Email**

For sensitive security issues, please email us directly at **tim@spiderwisp.com** with the subject line `[SECURITY] Laravel Overlord Vulnerability Report`.

### What to Include

When reporting a security vulnerability, please include:

- A clear description of the vulnerability
- Steps to reproduce the issue
- Potential impact of the vulnerability
- Any suggested fixes or mitigations (if available)
- Your contact information (optional, but helpful for follow-up questions)

### Response Time

We aim to:

- Acknowledge receipt of your report within **48 hours**
- Provide an initial assessment within **7 days**
- Keep you informed of our progress throughout the resolution process

### Disclosure Policy

- We will work with you to understand and resolve the issue quickly
- We will not disclose the vulnerability publicly until a fix is available
- We will credit you in our security advisories (unless you prefer to remain anonymous)
- We will notify you before any public disclosure

### Security Best Practices

When using Laravel Overlord in production:

- Always use authentication middleware to protect terminal routes
- Restrict access to trusted users only
- Keep your API keys secure and rotate them regularly
- Monitor command logs for suspicious activity
- Use Redis password protection in production
- Keep Laravel Overlord and its dependencies up to date

## Security Updates

Security updates will be released as patch versions (e.g., 1.0.1, 1.0.2) and will be announced through:

- GitHub releases (when repository is published)
- Package updates on Packagist
- Security advisories via email (for critical issues)

Thank you for helping keep Laravel Overlord secure!

