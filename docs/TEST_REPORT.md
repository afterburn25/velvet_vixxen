# Test Report — 0.7.4 Visual Composer Direct Manipulation & Asset Repair

## Release

Runtime package: `Nikki-Wilder-Velvet-Vixxen-0.7.4.zip`

SHA-256:

```text
860447db31eba4dfb6ee7acd686021cb63eac17d3fd161b1881160e4676c77e3
```

## Visual Composer browser verification

The Visual Composer was exercised in headless Chromium using the actual rendered administration shell, the release CSS/JavaScript, and bundled site imagery.

Verified:

- Media Tray images loaded successfully.
- Non-image media records were excluded from the layer tray.
- A photo was added from the tray and dragged directly on the canvas.
- A corner resize handle enlarged the photo directly.
- Numeric widths of `0.5%` and `300%` were applied successfully.
- The live text-and-buttons box was dragged and resized directly.
- Background Pan mode changed the room focal point.
- No console or page-script errors occurred.

Recorded interaction values:

```text
Media cards rendered: 9
Broken Media Tray images: 0
Photo moved: x 72.0 → 90.0, y 50.0 → 82.7
Direct-handle resize: width 20.0% → 102.7%
Smallest tested width: 0.5%
Largest tested width: 300.0%
Content box moved: x 6.0 → 21.1, y 17.0 → 37.0
Content box resized: 28 × 28 → 47.3 × 63
Background focal X moved: 50 → 29
Browser errors: 0
```

## Asset-path verification

Verified path behavior:

- Bundled `images/defaults/...` files resolve from the site root.
- Uploaded `images/demo/...` files resolve under `/uploads/images/demo/`.
- Model, media, thumbnail, export, audio, video, and caption paths resolve beneath `/uploads/`.
- HTTP, data, and blob URLs remain supported.
- Missing assets receive a safe placeholder/status rather than a permanent broken-image icon.

Live-site test result:

```text
Hero content box: left 20%, top 10%, width 55%, height 60%
Uploaded image URL: /uploads/images/demo/gallery-1.jpg
Bundled background URL: /images/defaults/hero-pink-room.jpg
JavaScript errors: 0
```

## Runtime package verification

The final ZIP was extracted into a separate clean directory and retested.

Passed:

- ZIP integrity
- SHA-256 verification
- 186 runtime files
- 113 PHP runtime syntax checks
- 6 public JavaScript syntax checks
- Clean uninstalled configuration
- Runtime-only exclusion policy
- No world-writable files or directories
- No residual `0.7.3` version references
- Extracted-package Visual Composer interaction suite
- Extracted-package live content-box and asset-resolution suite

## Environmental boundary

Direct localhost navigation is restricted by a managed Chromium policy in the packaging environment. The interaction suite used Chromium with the actual fetched administration HTML and exact extracted release assets injected into the page. Pointer movement, resize handles, layout controls, image loading, content placement, and JavaScript errors were still exercised in a real browser engine.

Local PDO MySQL/MySQL remains unavailable; live migration execution remains CI-owned. Database migrations and declared table count are unchanged from 0.7.3.

## Result

**PASS — 0.7.4 resolves the reported broken-image problem and provides direct photo drag, very small/large resize, movable button-area layout, and background positioning in the Visual Composer.**
