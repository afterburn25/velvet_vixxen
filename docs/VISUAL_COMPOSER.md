# Visual Composer and Hero Library — 0.8.1

<!-- composer-depth-0812:start -->
## 0.8.12 candidate — place photos farther into the room

**In progress, Build 20261021.1**, on `feature/0.8.12-composer-depth` for [issue #28](https://github.com/afterburn25/velvet_vixxen/issues/28). Version 0.8.11 remains the latest verified delivery; the following controls describe the working candidate. Full instructions are in [Photo depth and foreground furniture](COMPOSER_DEPTH.md).

In **Layers**, select a photo and increase **Depth in scene** from Near toward Far. The photo gets smaller and moves toward the room's depth point; the other photos stay independent. **Reset depth** returns it to its stored base position and width. **Background → Depth direction** sets the room point for the current device. X/Y and width fields continue to describe the photo's visible canvas placement; dragging, alignment, recovery, duplication and keyboard nudges account for its depth.

In **Background → Foreground furniture**, choose **Draw outline**, click 3–64 points around the part of the bed or furniture that should cover a photo, and choose **Finish outline**. Select **Place behind foreground** on each photo that should sit behind that furniture. Buttons and live content remain above it. Drawn points are attached to the original background image, so pan, zoom and section-height changes keep the outline on the furniture.

**Undo point** removes the last point while drawing. **Cancel outline** or Escape keeps the previous outline; changing section/device/tab cancels an unfinished outline. Save, Preview, Publish and Restore remain unavailable until the outline is finished or cancelled. Completed points can be dragged in the Background canvas or changed under **Edit outline points**; an invalid edit keeps the previous valid shape. **Show foreground furniture** switches the outline off without deleting it, while **Clear outline** removes it and can be undone. Changing the background image disables its previous outline. Each device keeps its own depth settings and outline; **Copy to other devices** copies them with the layout.

The unchanged baseline and candidate's full local source suite pass, including **215 scene-depth assertions**. File-mode depth HTTP acceptance passes **1,954 checks**; retained scale and Composer action acceptance also pass. New depth browser/MySQL acceptance, all **seven CI jobs/74 configured steps**, merge and saved-download verification remain pending. This work does not establish an update to the affected customer host.
<!-- composer-depth-0812:end -->

The Visual Composer edits the Homepage Hero and Visual Footer as independent compositions. Background artwork, uploaded photos, separate button layers, and the live text/button region remain separate. The approved pink/magenta/black site design is retained.

## Choose and preview a scene

Open **Visual Composer** in the owner admin. The **Backgrounds** tab lists the bundled scenes whose runtime image and thumbnail both pass validation. Each card shows its title, category, actual orientation and dimensions, and suggested photo/text placement. Search matches titles, categories, orientation, placement notes, and keywords. The category selector and available count reflect real selectable assets.

Clicking a card changes the canvas immediately. It does not save or publish the composition. **Use new background on all devices** assigns that artwork to desktop, tablet, and mobile; uncheck it to change only the selected device. A new background resets its focal position and zoom, while the device's appearance controls remain available for further adjustment.

Enable **Placement guides** to see the scene's suggested photo and live-text areas. These guides follow the actual image fit, zoom, and focal point. They are editing aids and never appear on the public page. They do not add a person or photograph.

The **Your Images** tab contains public image assets from the Media Library. Protected gallery originals stay in the Media Center and are excluded from this public composition picker. Upload a PNG, JPEG, or WebP with an optional custom thumbnail. Use **+** to add a separate photo layer, or **BG** to use an image as the current device's background. Transparent PNG/WebP photos can sit naturally in front of the artwork without becoming part of the background file.

## Edit each device

Desktop, tablet, and mobile each store their own section height, background source and geometry, photo layers, and live text/button region. Their reference canvas widths are 1440, 900, and 390 pixels. The saved section height defines the canvas proportions. Both the editor and the public Hero/Footer fit that complete canvas to the available width, keeping the same background crop, photo edges, positions and button proportions.

For example, a desktop canvas saved at 1440 × 760 appears at 1920 × 1013.33 on a 1920-pixel-wide page. It remains 1440 × 760 at its reference width. The editor fits the same proportions into its smaller panel. Public mobile layouts apply through 760 pixels, tablet through 1120 pixels, and desktop above that. Resizing within a device layout scales the whole composition; crossing a breakpoint selects the separately saved layout. Existing saved scenes need no migration or resave.

Intentional off-frame cropping remains clipped at the same canvas edge in both views. The section following the Hero no longer overlaps and conceals its last visible pixels. The navigation header and persistent player remain separate page controls; they are not part of the scene canvas.

Background controls include horizontal and vertical focal position, zoom from 25% to 400%, brightness, contrast, saturation, blur, dark overlay, and overlay color. **Pan Background** lets you drag the room image directly. Changes affect the selected device. **Reset view** restores its focal position, zoom, and image adjustments. **Copy to other devices** deliberately copies the entire current device layout, including background, section height, photo layers, and content box.

Drag or resize the pink **Live text + buttons** box to place the public headline, description, and buttons. Numeric left/top/width/height controls are also available. Use **Full Page Preview** to review the actual live copy and controls in context; the editing box itself is only a positioning guide.

## Move, resize, align, and crop photos

A photo's X/Y coordinates refer to its center, as percentages of the scene. They can extend from −400% to 500%, allowing placement far beyond every frame edge. Width ranges from 0.5% to 400% of the scene width. Corner resizing preserves the original image aspect ratio. Width presets and numeric fields support precise changes.

The editor and public Hero/Footer clip all outside edges. Moving a photo partly below the scene hides that lower portion without changing the uploaded image. **Hide the lower 20% below the scene** positions its lower edge for a 20% crop; further movement can refine the placement. Testing uses a geometric color-strip fixture to verify this crop mathematically. Actual owner-photo placement and the visual effect on lower legs require the owner's photographs.

Selected-photo corner handles stay inside the editing frame, even when the photo extends outside it or is extremely small. The move grip remains reachable. The **Layer stack** can select hidden or completely off-frame photos, and **Bring photo into view** restores its center to 50%/50%.

- **Snap** aligns photo/content edges and centers with the frame and other photo layers; temporary lines show the alignment. Hold **Alt** while dragging to disable snapping for that movement.
- Alignment buttons place a photo against the left, right, top, bottom, or center of the frame.
- Arrow keys nudge by 0.2 percentage points; **Shift + Arrow** moves by one point. **+ / −** change photo width, with larger steps while holding Shift.
- **Lock** prevents direct, numeric, and keyboard geometry changes. Unlock it before adjusting the photo again.
- **Hide/Show**, duplicate, delete, move forward/back, horizontal flip, rotation, opacity, brightness, contrast, saturation, feathering, and shadow strength remain available in Photo Layer.

These settings are stored independently for each device. Hiding a layer in one device layout does not hide the corresponding layer in another layout unless the whole layout is copied.

## Add separate buttons

Choose a section and device, then click **Add Button** in the toolbar. Edit the selected button's label and destination, choose solid, outline or pill styling, and set its fill, text and border colors, text size, corner radius and new-tab behavior. Public buttons are real HTML links. Labels render as text; the server and public renderer reject unsafe URL schemes and malformed destinations.

Use `booking/`, `membership/` or another site-relative destination for this website, or a complete `https://` / `http://` link. Relative destinations resolve from the installation root, including a nested installation. A new-tab button uses `noopener noreferrer`.

Button layers have independent width and height and use the existing drag, resize, keyboard, numeric, snap, ordering, hide/lock, duplicate/delete and undo/redo controls. Positions and dimensions are separate for desktop, tablet and mobile. **Copy to other devices** copies the entire selected layout, including buttons. Hero/Footer clipping applies to buttons as well as photos. The existing live headline/description/button group remains independently movable.

## Save, preview, publish, and restore

Give the composition a title so published versions are easier to identify.

**Save Draft** saves the current Hero or Footer composition. The public site continues to use its published version. **Full Page Preview** first saves both in-memory Hero and Footer drafts sequentially, then opens the owner preview with those drafts. It does not publish either scene. If the browser blocks the new window, the status explains that the drafts were saved and asks the owner to allow the preview window. If saving fails, the preview tab remains open with a readable explanation and the admin shows the error. Upload, save, publish and restore also handle a host's non-JSON response without showing a raw JSON parsing exception.

**Publish** updates the live composition and adds a persistent version. The **Versions** tab shows titles and publication times. **Restore and publish** returns the selected section to a prior published version and creates a new publication entry. Restoring one section does not restore the other section. File-store history retains the latest 20 published versions.

Undo/redo tracks up to 60 editing snapshots during the current page session across both sections. Reloading clears that local undo history; saved drafts and published versions remain persistent.

## Bundled scene manifest

Runtime assets live under `images/hero/`, with optimized thumbnails under `images/hero/thumbnails/`. The manifest is `images/hero/manifest.json`:

```json
{
  "version": 1,
  "categories": [{"id": "bedrooms", "title": "Bedrooms & Suites"}],
  "scenes": [{
    "id": "scene-slug",
    "title": "Scene title",
    "category": "bedrooms",
    "file": "images/hero/scene-slug.webp",
    "thumbnail": "images/hero/thumbnails/scene-slug.webp",
    "width": 1536,
    "height": 1024,
    "orientation": "landscape",
    "placement": "Right foreground; text on the left",
    "modelZone": {"x": 58, "y": 20, "width": 34, "height": 78},
    "textZone": {"x": 7, "y": 18, "width": 36, "height": 64},
    "keywords": ["penthouse", "magenta", "night"]
  }]
}
```

Zones are optional and use percentages measured from the image's top-left corner. The example documents the format; it is not a counted asset. The service derives dimensions and orientation from each actual image. It checks unique valid identifiers, file existence, MIME/extension agreement, image dimensions, and path confinement to the bundled Hero directory. Missing, malformed, duplicated, or invalid entries are excluded from the selectable count and reported in a controlled warning. Files outside the library, including escaping symlinks, cannot be selected through the manifest.

The runtime needs the optimized assets and manifest. Generation masters, acceptance screenshots, development fixtures, and documentation stay outside customer runtime packages. Library image paths resolve from the installation root; uploaded image/model/thumbnail paths continue to resolve under uploads.

## Verification

- `tests/Unit/hero_library.php`: valid metadata and images, actual dimensions/orientation, category counts, malformed/unsupported manifests, missing assets, invalid image bytes, extension/MIME mismatch, duplicate identifiers, path confinement, and placement zones.
- `tests/Browser/composer_recovery_playwright.py`: continuous drag and resize, 0.5–400% save/reload, live content placement, device isolation, background pan, undo/redo, draft isolation, public image paths, and mobile overflow.
- `tests/Browser/composer_controls_playwright.py`: real upload, per-device backgrounds and appearance, all-edge movement, reachable handles at extreme coordinates, aspect ratio, locking/hiding/ordering, snapping, lower-edge crop, draft preview of both sections, safe metadata embedding, and publication rollback including leading-zero file-store version IDs.
- `tests/Browser/composer_library_playwright.py`: requires at least 30 actual distinct assets; decodes every runtime image and thumbnail, validates search/category/placement metadata, and publishes and renders every scene at desktop, tablet, and mobile public widths.
- `tests/Feature/scene_buttons.php` and `tests/Browser/composer_buttons_playwright.py`: validated button persistence, live links and appearance, device geometry, editing/history controls and destination rejection.
- `tests/Browser/packaged_media_upload_playwright.py` and `tests/Browser/composer_actions_playwright.py`: actual customer ZIPs served without a testing rewrite router, root/subfolder uploads, draft preview lifetime, publication, readable failures and public button destinations.

Run browser acceptance through `scripts/test_http.sh`. It copies the application to a marked disposable runtime, seeds fixtures there, starts the real local PHP router, and cleans up afterward. Do not seed the authoritative checkout or an installed customer site for testing. Current run evidence belongs in `docs/TEST_REPORT.md` and `PROJECT_STATUS.md`; the existence of a suite alone is not a passing result.
