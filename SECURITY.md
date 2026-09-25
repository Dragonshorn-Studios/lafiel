# Security Policy

## Supported Versions

Only the latest release on the `main` branch receives security updates.

| Version | Supported          |
| ------- | ------------------ |
| main    | :white_check_mark: |

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

If you discover a security vulnerability in Lafiel, please report it privately using one of the following methods:

1. **GitHub Private Security Advisory**: Use GitHub's [Report a vulnerability](https://github.com/Dragonshorn-Studios/lafiel/security/advisories/new) feature on this repository.
2. **Email**: Contact the maintainer directly at `rughalt@gmail.com`.

### What to Include

Please include as much information as possible to help us reproduce and address the issue:
- Type of issue (e.g., credential exposure, SQL injection, privilege escalation, CSRF).
- Step-by-step instructions or proof-of-concept payload to reproduce the issue.
- Potential impact of the vulnerability.

### Response Timeline

- **Acknowledgment**: Within 48 hours.
- **Assessment & Fix Plan**: Within 7 business days.
- **Public Disclosure**: Coordinated after a patch is released.

## Security Architecture & Design Guarantees

- **Strictly Observational**: Lafiel reads provider infrastructure and cost data but **never** mutates or modifies upstream provider resources.
- **Credential Encryption**: All provider tokens and secret keys are encrypted at rest using `APP_KEY`.
- **Redaction**: Credential values, authorization headers, and raw secrets are sanitized by an internal redactor before reaching logs or serialized outputs.
- **Isolated Roles**: Containerized deployments enforce separate `web`, `queue`, and `scheduler` execution roles with unprivileged process context (`www-data`).
