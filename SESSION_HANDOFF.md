# Session Handoff — Velvet Vixxen 0.7.4

## Resume point

Current runtime package: **0.7.4 — Visual Composer Direct Manipulation & Asset Repair**

Repository: `afterburn25/velvet_vixxen`

Branch: `fix/0.7.4-visual-composer`

Runtime package SHA-256:

```text
860447db31eba4dfb6ee7acd686021cb63eac17d3fd161b1881160e4676c77e3
```

The customer ZIP remains runtime-only. Engineering documents, tests, SDK source, screenshots, and GitHub files are not included in it.

## Reported problems

- Photo layers could not be reliably dragged around the Visual Composer canvas.
- Images could not be resized extremely small or extremely large.
- The button/text safe area was fixed in place.
- Media Tray images appeared broken or nonexistent because uploaded and bundled asset paths were resolved incorrectly.

## 0.7.4 implementation

- Layer drag updates the selected DOM node directly during pointer movement, then records history and rerenders after release.
- Four corner resize handles scale photo layers between `0.5%` and `400%`.
- Tiny, Small, Medium, Large, and Huge presets were added.
- The former fixed safe area is now a draggable/resizable `Live text + buttons` box.
- Content-box state is stored per Hero/Footer and per desktop/tablet/mobile arrangement.
- Pan Background mode allows direct background focal-point movement.
- Canvas movement keeps resize handles accessible.
- Media Tray results are filtered to image-compatible assets.
- Asset resolution distinguishes bundled `/images/...` files from uploaded `/uploads/images/...` files.
- Model/media/thumbnail/export paths resolve beneath `/uploads/`.
- Broken assets receive a safe visual placeholder and status message.
- Public `site.js` applies saved content-box geometry to the live Hero and Footer.

## Preserved locked decisions

- Approved neon-pink design; no new mockups unless explicitly requested.
- Simplified single-page browser installer.
- No installation key.
- Exact 12-character minimum owner/member passwords.
- Safe `0755`/`0775`; never `0777`.
- Automatic ownership-safe runtime-folder repair.
- Organized runtime-only installation ZIP.
- One Entitlement Resolver across subscriptions, PPV, rentals, bundles, gifts, and complimentary access.

## Local verification

- 113 PHP runtime files: syntax passed
- 6 public JavaScript files: syntax passed
- Runtime package: 186 files
- Extracted ZIP integrity and SHA-256: passed
- Chromium composer suite: passed
- Media cards: 9; broken images: 0
- Direct photo movement: passed
- Direct handle resize: 20% → 102.7%
- Numeric sizing: 0.5% and 300% passed
- Content box movement and handle resize: passed
- Background pan: passed
- Live content-box placement: passed
- Bundled and uploaded asset URL resolution: passed
- Browser console/page errors: 0

## Limits

- Local PDO MySQL/MySQL unavailable; live DDL remains CI-owned.
- Managed Chromium policy blocks direct localhost navigation. Browser interaction was exercised using the actual fetched admin shell and exact extracted assets loaded into Chromium.
- Payment remains sandbox-only.

## Next work

1. Confirm 0.7.4 on the affected host with uploaded transparent images.
2. Import complete engineering source separately from the runtime ZIP.
3. Run full PHP/MySQL/HTTP/Playwright CI.
4. Continue **0.8.0 — Marketing, Publishing & Analytics**.
