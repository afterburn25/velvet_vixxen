# Contributing

Development is organized around the versioned milestones in `docs/ROADMAP.md`.

## Branching

- `main`: authoritative, tested release state
- `feature/<version>-<topic>`: active feature work
- `fix/<version>-<topic>`: targeted corrections
- `docs/<topic>`: documentation-only updates

## Required checks

Before opening or merging a pull request:

```bash
bash scripts/test.sh
```

When MySQL is available, also run the disposable database integration test described in `docs/TESTING.md`.

## Commit requirements

- Keep commits focused.
- Include tests for behavior changes.
- Add database changes through versioned migrations.
- Update status and handoff documents when milestone state changes.
- Never commit production secrets, identity records, customer data, or private media.

## Pull-request evidence

Every pull request should identify the linked issue, implementation details, migration impact, security/privacy impact, exact tests run, known limitations, and documentation updated.
