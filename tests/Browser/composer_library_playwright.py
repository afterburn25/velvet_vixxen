"""Validate every shipped scene through real Composer and public-device rendering."""
from __future__ import annotations

import hashlib

from playwright.sync_api import expect, sync_playwright

from composer_support import (
    RUNTIME, action, background_loaded, choose_device, control,
    dismiss_age, launch, login, read_scene, screenshot, url,
)

with sync_playwright() as playwright:
    browser = launch(playwright)
    context = browser.new_context(viewport={"width": 1680, "height": 1100})
    errors = []
    failed_images = []
    context.on('page', lambda child: child.on('pageerror', lambda error: errors.append(str(error))))
    context.on('response', lambda response: failed_images.append(f'{response.status} {response.url}') if response.request.resource_type == 'image' and response.status >= 400 else None)
    page = context.new_page()
    login(page)
    library = page.evaluate('window.COMPOSER_DATA.library')
    scenes = library['scenes']
    assert len(scenes) >= 30, f'The shipped Hero Library requires at least 30 actual, selectable scenes; found {len(scenes)}'
    assert not library['warnings'] and not library['unavailable'], f'Bundled library has unavailable entries: {library["warnings"]}'
    assert len({scene['id'] for scene in scenes}) == len(scenes)
    assert len({scene['file'] for scene in scenes}) == len(scenes), 'Several scene entries point at the same image file'
    fingerprints = {hashlib.sha256((RUNTIME / scene['file']).read_bytes()).hexdigest() for scene in scenes}
    assert len(fingerprints) == len(scenes), 'Duplicate background image bytes were counted as distinct scenes'
    assert all(scene['orientation'] == 'landscape' and scene['width'] > scene['height'] for scene in scenes), 'A full-width scene is not landscape'
    assert all(scene['title'] and scene['placement'] and scene['categoryTitle'] for scene in scenes), 'A scene is missing picker metadata'
    page.locator('[data-assets-tab="library"]').click()
    expect(page.locator('[data-library-id]')).to_have_count(len(scenes))
    expect(page.locator('[data-library-count]')).to_have_text(f'{len(scenes)} of {len(scenes)} available scenes')

    # Load/decode each thumbnail and full-resolution runtime file in Chromium.
    # Catalog validation alone cannot prove that the web server serves a real image.
    report = page.evaluate('''async scenes => {
      return await Promise.all(scenes.map(async scene => {
        const inspect = async url => {
          const image = new Image();
          image.src = url;
          try { await image.decode(); return {ok: image.naturalWidth > 0, width: image.naturalWidth, height: image.naturalHeight, src: image.src}; }
          catch (_) { return {ok: false, src: image.src}; }
        };
        return {id: scene.id, image: await inspect(scene.url), thumbnail: await inspect(scene.thumbnailUrl)};
      }));
    }''', scenes)
    for result, scene in zip(report, scenes):
        assert result['image']['ok'] and result['thumbnail']['ok'], f'Broken full image or thumbnail: {result}'
        assert (result['image']['width'], result['image']['height']) == (scene['width'], scene['height']), 'Picker dimensions do not match the served image'
        assert result['thumbnail']['width'] <= scene['width'] and result['thumbnail']['height'] <= scene['height'], 'Thumbnail is larger than its runtime image'
        assert '/uploads/images/hero/' not in result['image']['src'], 'Bundled image was misrouted as an upload'
    assert page.locator('[data-library-list] img').evaluate_all("images => images.every(image => image.complete && image.naturalWidth > 0 && image.dataset.fallbackApplied !== '1')"), 'A library thumbnail uses the missing-image fallback'
    for category in library['categories']:
        page.locator('[data-library-category]').select_option(category['id'])
        expected = len([scene for scene in scenes if scene['category'] == category['id']])
        assert category['count'] == expected
        expect(page.locator('[data-library-id]')).to_have_count(expected)
    page.locator('[data-library-category]').select_option('all')
    sample = scenes[0]
    page.locator('[data-library-search]').fill(sample['title'])
    expect(page.locator(f'[data-library-id="{sample["id"]}"]')).to_be_visible()
    page.locator('[data-library-search]').fill('no-such-fixture-scene-zzzz')
    expect(page.locator('[data-library-id]')).to_have_count(0)
    page.locator('[data-library-search]').fill('')

    before = read_scene(context)
    page.locator(f'[data-library-id="{sample["id"]}"]').click()
    background_loaded(page, url(sample['file']))
    expect(page.locator('[data-background-info]')).to_contain_text(sample['title'])
    expect(page.locator('[data-background-info]')).to_contain_text(sample['placement'])
    assert read_scene(context) == before, 'Clicking a library scene saved or published it without an action'
    page.locator('[data-placement-enabled]').check()
    expected_guides = int(sample['modelZone'] is not None) + int(sample['textZone'] is not None)
    expect(page.locator('.placement-guide')).to_have_count(expected_guides)
    page.locator('[data-placement-enabled]').uncheck()
    expect(page.locator('.placement-guide')).to_have_count(0)

    # Each actual scene is selected on all devices, published in the isolated app,
    # and visited at desktop, tablet and mobile widths through the public router.
    public = context.new_page()
    public_checks = 0
    viewports = [('desktop', 1440, 1000), ('tablet', 900, 1000), ('mobile', 390, 844)]
    for index, scene in enumerate(scenes):
        page.locator(f'[data-library-id="{scene["id"]}"]').click()
        for device, _, _ in viewports:
            choose_device(page, device)
            background_loaded(page, url(scene['file']))
        control(page, 'sceneTitle', scene['title'])
        action(page, 'publish')
        for device, width, height in viewports:
            public.set_viewport_size({'width': width, 'height': height})
            public.goto(url('/'), wait_until='networkidle')
            dismiss_age(public)
            background_loaded(public, url(scene['file']), public=True)
            data = public.evaluate('(device) => window.VELVET_CONFIG.scenes.hero.devices[device].background', device)
            assert data['libraryId'] == scene['id'] and data['src'] == scene['file'], f'{scene["id"]} was not published for {device}'
            assert public.locator('[data-scene="hero"] .scene-background').evaluate('image => getComputedStyle(image).objectFit') == 'cover'
            assert public.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'{scene["id"]} caused overflow at {device}'
            image_failures = public.locator('img').evaluate_all('''async images => {
              const bad = [];
              for (const image of images) {
                if (!image.getAttribute('src')) continue;
                try { await image.decode(); if (!image.naturalWidth) bad.push(image.src); }
                catch (_) { bad.push(image.src); }
              }
              return bad;
            }''')
            assert not image_failures, f'Broken public image at {scene["id"]}/{device}: {image_failures}'
            public_checks += 1
            if index in {0, len(scenes) - 1}:
                screenshot(public, f'library-{index + 1:02d}-public-{device}.png')
    choose_device(page, 'desktop')
    page.locator('[data-assets-tab="library"]').click()
    screenshot(page, 'composer-library-desktop.png')

    # A background can be changed on one device while the other two retain theirs.
    page.locator('[data-library-all-devices]').uncheck()
    choose_device(page, 'mobile')
    page.locator(f'[data-library-id="{sample["id"]}"]').click()
    background_loaded(page, url(sample['file']))
    choose_device(page, 'desktop')
    background_loaded(page, url(scenes[-1]['file']))
    choose_device(page, 'tablet')
    background_loaded(page, url(scenes[-1]['file']))
    action(page, 'save')
    page.reload(wait_until='networkidle')
    choose_device(page, 'mobile')
    background_loaded(page, url(sample['file']))
    page.set_viewport_size({'width': 390, 'height': 844})
    page.locator('[data-canvas]').scroll_into_view_if_needed()
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'The mobile library controls overflow'
    screenshot(page, 'composer-library-mobile.png')
    assert not errors, 'Composer/public JavaScript errors: ' + '; '.join(errors)
    assert not failed_images, 'HTTP image failures: ' + '; '.join(failed_images)
    context.close()
    browser.close()

print(f'Hero Library acceptance passed: {len(scenes)} distinct real scenes, every full image and thumbnail decoded, category/search/placement metadata, immediate draft selection, and {public_checks} published public renders across desktop/tablet/mobile.')
