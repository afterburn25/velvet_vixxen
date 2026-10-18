# Security Policy

Do not report exploitable security issues through a public issue. Contact the repository owner privately with the affected version, reproduction steps, impact, and any proposed mitigation.

## Never commit

- Database passwords or SMTP credentials
- Payment-provider keys or webhook secrets
- Canva OAuth secrets
- Production application or media-signing keys
- Performer identity documents or release records
- Customer records, private messages, or payment data
- Private or paid media
- Runtime logs containing personal information

## Required security boundaries

The application must preserve protected storage, signed media URLs, CSRF protection, secure password hashing, upload validation, strict installer locking, least-privilege administrative roles, safe migration handling, and add-on signature/permission checks.

The repository is public. Placeholder and approved non-private visual assets are acceptable; production data and private media are not.
