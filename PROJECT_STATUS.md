# Project Status — Nikki Wilder / The Velvet Vixxen

## Current packaged release

**0.7.4 — Visual Composer Direct Manipulation & Asset Repair**

The public package remains runtime-only. Repository documents, tests, SDK source, screenshots, and development tooling stay outside the customer installation ZIP.

## 0.7.4 corrections

- Photo layers can be dragged directly on the Visual Composer canvas without rebuilding the DOM during each pointer move.
- Corner handles resize images directly from very small to very large.
- Exact layer width accepts `0.5%` through `400%`, with Tiny, Small, Medium, Large, and Huge presets.
- The live text-and-buttons area is now a movable and resizable canvas object.
- Text/button-area arrangements are stored separately for desktop, tablet, and mobile.
- Pan Background mode moves the room/background focal point.
- Layer movement keeps resize handles reachable within the canvas.
- The Media Tray only presents image-compatible assets.
- Bundled root images and uploaded images now resolve from their correct locations.
- Missing assets show a controlled placeholder/status instead of a permanently broken image icon.
- The public homepage applies the saved live-content box position and size.

## Preserved behavior

- Simplified one-page installer with no installation key.
- Exact 12-character minimum owner/member passwords.
- Safe `0755`/`0775` permissions; never `0777`.
- Automatic ownership-safe repair of panel-owned runtime folders.
- Cache-safe final configuration verification.
- Versioned migrations, rollback attempts, installation lock, and `/install` cleanup.
- Approved responsive design, Canva workflow, Media Center, members/entitlements, subscriptions, PPV, add-ons, licensing/updates, messaging, booking, and member requests.

## Package evidence

- Runtime files: 186
- Runtime PHP files: 113
- Public JavaScript files: 6
- Database migrations: 11
- Declared tables: 64
- ZIP integrity: passed
- PHP/JavaScript syntax: passed
- Extracted-package Chromium Visual Composer interaction suite: passed
- Media Tray cards rendered: 9
- Broken Media Tray images: 0
- Direct photo drag: passed
- Direct corner resize: passed
- Smallest tested image width: 0.5%
- Largest tested image width: 300%
- Content-box drag and resize: passed
- Background pan: passed
- Live-site content-box and asset resolution: passed
- Runtime-only exclusions and clean uninstalled state: passed
- Package SHA-256: `860447db31eba4dfb6ee7acd686021cb63eac17d3fd161b1881160e4676c77e3`

## Environmental limits

- Local PDO MySQL/MySQL remains unavailable, so live DDL execution is not claimed locally.
- Direct localhost navigation is restricted by a managed Chromium policy. The interaction suite used the actual rendered administration HTML and exact extracted release assets in Chromium.
- The payment provider remains sandbox-only.

## Repository truth boundary

- Repository: `afterburn25/velvet_vixxen`
- Hotfix branch: `fix/0.7.4-visual-composer`
- The complete executable source import and full MySQL/Playwright CI matrix remain separate tracked work.
- Never commit credentials, generated keys, customer records, private communications/attachments, signing keys, runtime state, or private/paid media.

## Next actions

1. Deploy and verify 0.7.4 on the affected hosting account.
2. Import the complete clean engineering source separately from the runtime ZIP.
3. Run PHP 8.2/8.3, MySQL 8, HTTP, repository-safety, and full Playwright CI.
4. Resume **0.8.0 — Marketing, Publishing & Analytics** after the composer hotfix is confirmed.
