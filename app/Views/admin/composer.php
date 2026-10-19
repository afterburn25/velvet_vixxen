<?php
$composerScenes = [
    'hero' => $store->scene('hero', false),
    'footer' => $store->scene('footer', false),
];
$composerMedia = array_values(array_filter($store->media(), static function (array $asset): bool {
    $type = strtolower((string) ($asset['type'] ?? ''));
    $mime = strtolower((string) ($asset['mime'] ?? ''));
    $file = strtolower((string) ($asset['file'] ?? ''));
    // Hero/Footer artwork is public; protected originals belong in the Media Center.
    if (!empty($asset['private']) || str_starts_with($file, 'private:')) {
        return false;
    }
    return str_starts_with($mime, 'image/')
        || in_array($type, ['model', 'background', 'export', 'thumbnail', 'gallery', 'poster'], true)
        || (bool) preg_match('/\.(?:jpe?g|png|webp|gif|avif)$/i', $file);
}));
$heroLibrary = (new \App\Core\HeroLibrary(root_path()))->catalog();
$composerAssetUrls = [];
foreach ($heroLibrary['scenes'] as &$libraryScene) {
    $libraryScene['url'] = scene_asset_url($libraryScene['file']);
    $libraryScene['thumbnailUrl'] = scene_asset_url($libraryScene['thumbnail']);
    $composerAssetUrls[$libraryScene['file']] = $libraryScene['url'];
    $composerAssetUrls[$libraryScene['thumbnail']] = $libraryScene['thumbnailUrl'];
}
unset($libraryScene);
foreach ($composerMedia as &$composerAsset) {
    $file = (string) ($composerAsset['file'] ?? '');
    $thumbnail = (string) ($composerAsset['thumbnail'] ?? $file);
    $composerAsset['url'] = scene_asset_url($file);
    $composerAsset['thumbnailUrl'] = scene_asset_url($thumbnail !== '' ? $thumbnail : $file);
    if ($file !== '') {
        $composerAssetUrls[$file] = $composerAsset['url'];
    }
    if ($thumbnail !== '') {
        $composerAssetUrls[$thumbnail] = $composerAsset['thumbnailUrl'];
    }
}
unset($composerAsset);
foreach ($composerScenes as $composerScene) {
    $backgroundPath = (string) ($composerScene['background']['src'] ?? '');
    if ($backgroundPath !== '') {
        $composerAssetUrls[$backgroundPath] = scene_asset_url($backgroundPath);
    }
    foreach ((array) ($composerScene['devices'] ?? []) as $composerDevice) {
        $deviceBackground = (string) ($composerDevice['background']['src'] ?? '');
        if ($deviceBackground !== '') {
            $composerAssetUrls[$deviceBackground] = scene_asset_url($deviceBackground);
        }
        foreach ((array) ($composerDevice['layers'] ?? []) as $composerLayer) {
            foreach (['src', 'thumbnail'] as $composerPathKey) {
                $composerPath = (string) ($composerLayer[$composerPathKey] ?? '');
                if ($composerPath !== '') {
                    $composerAssetUrls[$composerPath] = scene_asset_url($composerPath);
                }
            }
        }
    }
}
$composerData = [
    'scenes' => $composerScenes,
    'media' => $composerMedia,
    'library' => $heroLibrary,
    'assetUrls' => $composerAssetUrls,
    'versions' => [
        'hero' => $store->sceneVersions('hero'),
        'footer' => $store->sceneVersions('footer'),
    ],
    'baseUrl' => base_url(),
    'uploadBase' => base_url('uploads/'),
]; ?>
<div id="visualComposer" class="composer-app">
  <div class="composer-toolbar">
    <div class="segmented" data-section-tabs>
      <button class="active" data-section="hero">Homepage Hero</button>
      <button data-section="footer">Visual Footer</button>
    </div>
    <div class="segmented" data-device-tabs>
      <button class="active" data-device="desktop">Desktop</button>
      <button data-device="tablet">Tablet</button>
      <button data-device="mobile">Mobile</button>
    </div>
    <div class="toolbar-spacer"></div>
    <button class="icon-button" data-action="undo" title="Undo">↶</button>
    <button class="icon-button" data-action="redo" title="Redo">↷</button>
    <button class="admin-button secondary" type="button" data-action="add-button">+ Add Button</button>
    <button class="admin-button secondary" data-action="preview">Full Page Preview</button>
    <button class="admin-button secondary" data-action="save">Save Draft</button>
    <button class="admin-button primary" data-action="publish">Publish</button>
  </div>

  <div class="composer-workspace">
    <aside class="media-tray">
      <div class="tray-head">
        <div><span class="admin-kicker">Scene artwork + photos</span><h2>Image Library</h2></div>
        <button class="icon-button" data-action="toggle-upload" title="Upload image">+</button>
      </div>
      <div class="asset-tabs" role="group" aria-label="Image source">
        <button type="button" data-assets-tab="library">Backgrounds</button>
        <button type="button" data-assets-tab="media">Your Images</button>
      </div>
      <div data-assets-pane="library">
        <div class="library-search">
          <label class="sr-only" for="heroLibrarySearch">Search background scenes</label>
          <input id="heroLibrarySearch" type="search" placeholder="Search rooms, lighting, placement…" data-library-search>
          <label class="sr-only" for="heroLibraryCategory">Background category</label>
          <select id="heroLibraryCategory" data-library-category><option value="all">All categories</option></select>
        </div>
        <label class="composer-check"><input type="checkbox" data-library-all-devices checked> Use new background on all devices</label>
        <p class="library-count" data-library-count></p>
        <div class="library-warnings" data-library-warnings role="status" hidden></div>
        <div class="library-list" data-library-list></div>
      </div>
      <div data-assets-pane="media" hidden>
      <div class="upload-panel" data-upload-panel hidden>
        <form id="quickUpload" enctype="multipart/form-data">
          <label>Image<input type="file" name="image" accept="image/png,image/jpeg,image/webp" required></label>
          <label>Custom thumbnail <small>optional</small><input type="file" name="thumbnail" accept="image/png,image/jpeg,image/webp"></label>
          <label>Type<select name="type"><option value="model">Model / cutout</option><option value="background">Background</option></select></label>
          <button class="admin-button primary full">Upload</button>
        </form>
      </div>
      <div class="tray-search">
        <input type="search" placeholder="Search images" data-media-search>
        <select data-media-filter><option value="all">All</option><option value="model">Cutouts</option><option value="background">Backgrounds</option><option value="export">Canva exports</option></select>
      </div>
      <div class="media-list" data-media-list></div>
      </div>
    </aside>

    <section class="canvas-area">
      <div class="canvas-topline">
        <span data-canvas-label>Homepage Hero · Desktop</span>
        <div>
          <label class="composer-check compact"><input type="checkbox" data-snap-enabled checked> Snap</label>
          <label class="composer-check compact"><input type="checkbox" data-placement-enabled> Placement guides</label>
          <button class="mini-button" data-action="pan-background" aria-pressed="false">Pan Background</button>
          <button class="mini-button" data-action="copy-layout">Copy to other devices</button>
          <button class="mini-button" data-action="reset-view">Reset view</button>
        </div>
      </div>
      <div class="canvas-viewport">
        <div class="design-canvas" data-canvas>
          <img class="canvas-background" data-canvas-bg alt="">
          <div class="canvas-overlay" data-canvas-overlay></div>
          <div class="canvas-grid"></div>
          <div class="canvas-layers" data-canvas-layers></div>
          <div class="placement-guides" data-placement-guides aria-hidden="true"></div>
          <div class="snap-guides" data-snap-guides aria-hidden="true"></div>
          <div class="canvas-content-box" data-content-box tabindex="0" role="button" aria-label="Move the live text and button area">
            <span class="content-box-label">Live text + buttons</span>
            <div class="content-box-preview" aria-hidden="true"><b>LIVE CONTENT</b><i></i><div><em>Button</em><em>Button</em></div></div>
            <span class="content-resize-handle nw" data-content-handle="nw"></span>
            <span class="content-resize-handle ne" data-content-handle="ne"></span>
            <span class="content-resize-handle sw" data-content-handle="sw"></span>
            <span class="content-resize-handle se" data-content-handle="se"></span>
          </div>
          <div class="selection-overlay" data-selection-overlay></div>
          <div class="canvas-empty" data-canvas-empty>Drag an image here or choose Add Button</div>
        </div>
      </div>
      <div class="canvas-help">Drag photos beyond any edge to crop them. Corner handles stay inside the frame; use the layer stack to recover hidden or off-frame photos. Arrow keys nudge, Shift moves faster, and Alt temporarily disables snapping. Geometry is saved separately for each device.</div>
    </section>

    <aside class="properties-panel">
      <div class="property-tabs">
        <button class="active" data-property-tab="background">Background</button>
        <button data-property-tab="content">Text + Buttons</button>
        <button data-property-tab="layer">Layers</button>
        <button data-property-tab="versions">Versions</button>
      </div>

      <div class="property-page active" data-property-page="background">
        <div class="control-group">
          <label class="scene-title-label">Composition title<input type="text" maxlength="160" data-control="sceneTitle" placeholder="Name this composition"></label>
          <div class="background-info" data-background-info></div>
          <h3>Background placement · this device</h3>
          <label>Horizontal position <output data-output="bgX">50%</output><input type="range" min="0" max="100" step="1" data-control="bgX"></label>
          <label>Vertical position <output data-output="bgY">50%</output><input type="range" min="0" max="100" step="1" data-control="bgY"></label>
          <label>Zoom <output data-output="bgZoom">100%</output><input type="range" min="25" max="400" step="1" data-control="bgZoom"></label>
          <label>Section height <output data-output="height">760px</output><input type="range" min="280" max="1800" step="10" data-control="height"></label>
        </div>
        <div class="control-group">
          <h3>Background appearance</h3>
          <label>Brightness <output data-output="bgBrightness">100%</output><input type="range" min="25" max="175" data-control="bgBrightness"></label>
          <label>Contrast <output data-output="bgContrast">100%</output><input type="range" min="50" max="175" data-control="bgContrast"></label>
          <label>Saturation <output data-output="bgSaturation">100%</output><input type="range" min="0" max="200" data-control="bgSaturation"></label>
          <label>Blur <output data-output="bgBlur">0px</output><input type="range" min="0" max="20" data-control="bgBlur"></label>
          <label>Dark overlay <output data-output="overlay">25%</output><input type="range" min="0" max="90" data-control="overlay"></label>
          <label>Overlay color<input type="color" data-control="overlayColor"></label>
        </div>
      </div>

      <div class="property-page" data-property-page="content">
        <div class="control-group">
          <h3>Live text and button box</h3>
          <p class="property-help">Drag the pink box directly on the canvas. This moves the real headline, description, and buttons on the live site for the selected device.</p>
          <div class="control-grid">
            <label>Left %<input type="number" min="0" max="100" step=".1" data-control="contentX"></label>
            <label>Top %<input type="number" min="0" max="100" step=".1" data-control="contentY"></label>
            <label>Width %<input type="number" min="5" max="100" step=".1" data-control="contentWidth"></label>
            <label>Height %<input type="number" min="5" max="100" step=".1" data-control="contentHeight"></label>
          </div>
          <button class="mini-button full" type="button" data-action="reset-content">Reset content box</button>
        </div>
      </div>

      <div class="property-page" data-property-page="layer">
        <div class="no-selection" data-no-selection>Select a photo or button on the canvas, or choose Add Button above.</div>
        <div data-layer-controls hidden>
          <div class="selected-preview"><img data-selected-preview alt=""><span class="selected-button-icon" data-button-icon hidden aria-hidden="true">↗</span><div><strong data-selected-name>Layer</strong><small data-selected-size></small></div></div>
          <div class="control-group button-controls" data-button-controls hidden>
            <h3>Live button</h3>
            <label>Button label<input type="text" maxlength="120" data-control="buttonLabel" placeholder="Book with me"></label>
            <label>Destination<input type="text" maxlength="2048" data-control="buttonUrl" placeholder="booking/ or https://example.com"></label>
            <p class="property-help">Page paths such as booking/ stay within this website. Complete http:// or https:// links open the address you enter.</p>
            <label class="composer-check"><input type="checkbox" data-control="buttonNewTab"> Open in a new tab</label>
            <label>Button style<select data-control="buttonStyle"><option value="solid">Solid</option><option value="outline">Outline</option><option value="pill">Pill</option></select></label>
            <div class="control-grid">
              <label>Fill color<input type="color" data-control="buttonColor"></label>
              <label>Text color<input type="color" data-control="buttonTextColor"></label>
              <label>Border color<input type="color" data-control="buttonBorderColor"></label>
              <label>Text size<input type="number" min="8" max="96" step="1" data-control="buttonFontSize"></label>
              <label>Corner radius<input type="number" min="0" max="100" step="1" data-control="buttonRadius"></label>
            </div>
          </div>
          <div class="control-grid">
            <label>X position<input type="number" min="-400" max="500" step=".1" data-control="layerX"></label>
            <label>Y position<input type="number" min="-400" max="500" step=".1" data-control="layerY"></label>
            <label>Width %<input type="number" min=".5" max="400" step=".5" data-control="layerWidth"></label>
            <label data-button-only hidden>Height %<input type="number" min=".5" max="400" step=".5" data-control="layerHeight"></label>
            <label>Rotation<input type="number" min="-180" max="180" step="1" data-control="layerRotation"></label>
          </div>
          <div class="control-group">
            <label>Layer width <output data-output="layerScale">34%</output><input type="range" min=".5" max="400" step=".5" data-control="layerScale"></label>
            <div class="size-presets"><button type="button" data-layer-size="5">Tiny</button><button type="button" data-layer-size="25">Small</button><button type="button" data-layer-size="60">Medium</button><button type="button" data-layer-size="120">Large</button><button type="button" data-layer-size="250">Huge</button></div>
            <label>Opacity <output data-output="layerOpacity">100%</output><input type="range" min="0" max="100" data-control="layerOpacity"></label>
            <label data-photo-only>Brightness <output data-output="layerBrightness">100%</output><input type="range" min="25" max="175" data-control="layerBrightness"></label>
            <label data-photo-only>Contrast <output data-output="layerContrast">100%</output><input type="range" min="50" max="175" data-control="layerContrast"></label>
            <label data-photo-only>Saturation <output data-output="layerSaturation">100%</output><input type="range" min="0" max="200" data-control="layerSaturation"></label>
            <label data-photo-only>Edge feather <output data-output="layerFeather">0%</output><input type="range" min="0" max="45" data-control="layerFeather"></label>
            <label>Shadow strength <output data-output="layerShadow">35%</output><input type="range" min="0" max="100" data-control="layerShadow"></label>
          </div>
          <div class="control-group">
            <h3>Align or crop at the frame</h3>
            <div class="layer-alignment">
              <button type="button" data-action="layer-align-left">Left</button><button type="button" data-action="layer-align-center-x">Center X</button><button type="button" data-action="layer-align-right">Right</button>
              <button type="button" data-action="layer-align-top">Top</button><button type="button" data-action="layer-align-center-y">Center Y</button><button type="button" data-action="layer-align-bottom">Bottom</button>
            </div>
            <button type="button" class="mini-button full" data-action="layer-crop-bottom" data-photo-only>Hide the lower 20% below the scene</button>
            <button type="button" class="mini-button full" data-action="layer-recover">Bring layer into view</button>
          </div>
          <div class="layer-actions">
            <button data-action="layer-forward">Move Forward</button><button data-action="layer-back">Move Back</button>
            <button data-action="layer-flip" data-photo-only>Flip Horizontal</button><button data-action="layer-duplicate">Duplicate</button>
            <button data-action="layer-lock">Lock</button><button data-action="layer-hide">Hide</button><button class="danger" data-action="layer-delete">Delete</button>
          </div>
        </div>
        <div class="layer-stack-list" data-layer-stack></div>
      </div>

      <div class="property-page" data-property-page="versions"><div class="versions-list" data-versions-list></div></div>
    </aside>
  </div>
  <div class="composer-status" data-status role="status" aria-live="polite"><span class="status-dot"></span><span>Ready</span></div>
</div>
<script>window.COMPOSER_DATA=<?= json_encode($composerData, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
