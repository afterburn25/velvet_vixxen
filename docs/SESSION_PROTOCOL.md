# Session Communication Protocol

This repository is the handoff channel between the owner, ChatGPT sessions, coding agents, and human contributors.

## At session start

- Confirm the repository and branch.
- Read `AGENTS.md`, `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, and `docs/TESTING.md`.
- Inspect open issues, pull requests, recent commits, and CI results.
- Run the existing tests before changing code so pre-existing failures remain distinguishable from new failures.

## During work

- Keep implementation scoped to a branch and issue.
- Add tests as functionality is added.
- Record lasting architectural decisions in `docs/DECISIONS.md`.
- Never silently change a locked product decision.
- Do not mark roadmap items complete based on plans, schemas, or placeholders alone.

## At session end

- Commit implementation and tests.
- Update `CHANGELOG.md`, `PROJECT_STATUS.md`, and `docs/TEST_REPORT.md`.
- Update roadmap status only from verified implementation.
- Rewrite `SESSION_HANDOFF.md` so the next agent can resume without relying on chat history.
- Include branch, commit, exact tests, known failures, environmental limits, and the next concrete task.
