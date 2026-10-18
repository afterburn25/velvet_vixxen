# Agent Instructions — Velvet Vixxen

## Source of truth

Repository: `afterburn25/velvet_vixxen`

Packaged target: **0.7.0 — Messaging, Booking & Member Requests**

Branch target: `feature/0.7.0-messaging-booking-requests`

Read `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, `docs/TESTING.md`, `docs/TEST_REPORT.md`, and `SECURITY.md` before changing code.

## Locked requirements

- Preserve the approved neon-pink/magenta/black design.
- Do not create more mockups unless explicitly requested.
- Keep the installer on one responsive page.
- Require no installation key.
- Use a 12-character minimum password, not 14.
- Never use `chmod 0777`.
- Keep application files organized.
- Preserve Visual Composer, Canva workflow, Media Center, members, entitlements, PPV/subscriptions, add-ons, licensing, and communications.
- Treat private messages, booking/request records, owner notes, moderation records, guest tokens, and attachments as sensitive runtime data.
- Do not include private body content in email alerts.
- Do not treat quotes as automatic charges or final contracts.

## 0.7.0 invariants

- Communication access is owner/member scoped.
- Owner-only notes never appear in member or guest output.
- Guest booking status requires the matching high-entropy token.
- Attachments live in protected storage and are streamed only after authorization.
- Blocks stop new contact but preserve historical evidence.
- Status, quote, acceptance/cancellation, and moderation changes are recorded.
- CSV exports require owner authentication.
- State-changing requests require CSRF protection.

## Required release gate

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
VELVET_TEST_PORT=8766 bash scripts/test_http.sh
bash scripts/clean_runtime.sh
php tests/Security/repository_safety.php
```

Current package evidence: 160 PHP files, 6 public JavaScript files, 11 Python files, 11 migrations, 66 tables, source suite passed, and all five HTTP suites passed.

CI must additionally run disposable MySQL apply/verify/rollback and all six Playwright suites. Record exact evidence in status, handoff, roadmap, testing, and test-report documents.

## Public repository safety

Never commit production credentials, generated secrets, payment tokens, raw license keys, signing private keys, private conversations, request/booking data, owner notes, notifications, moderation data, guest tokens, attachments, customer/member records, identity documents, runtime logs/sessions/backups, installed add-ons, or private/paid media.

## Truthfulness

Distinguish among locally tested package behavior, CI-prepared behavior, planned work, and real production-service integration. Do not claim source import, MySQL, Playwright, live payments, production email, public licensing SaaS, merge, tag, or release without direct evidence.
