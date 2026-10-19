#!/usr/bin/env python3
"""Click real Composer and related public actions in extracted runtime packages.

Every case uses PHP's normal file serving without a routing fallback. The
fixture's organized scene handler can be removed to reproduce a missing-file failure;
requests are never intercepted or fulfilled by Playwright.
"""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from urllib.parse import parse_qs, urlparse
from zipfile import ZipFile

import requests
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
helper_spec = importlib.util.spec_from_file_location('vvx_packaged_action_helpers', ROOT / 'tests/Browser/packaged_media_upload_playwright.py')
assert helper_spec is not None and helper_spec.loader is not None
helpers = importlib.util.module_from_spec(helper_spec)
helper_spec.loader.exec_module(helpers)
require = helpers.require
EVIDENCE = helpers.EVIDENCE


def control(page, name: str, value: str | int) -> None:
    field = page.locator(f'[data-control="{name}"]')
    field.fill(str(value))
    field.press('Tab')


def ready_controls(page) -> None:
    for action in ['save', 'publish', 'preview']:
        expect(page.locator(f'[data-action="{action}"]')).to_be_enabled()


def public_scene(page, section: str) -> dict:
    return page.evaluate('section => window.VELVET_CONFIG.scenes[section]', section)


def contains_layer(scene: dict, device: str, identifier: str) -> bool:
    return any(layer.get('id') == identifier for layer in scene['devices'][device]['layers'])


def dismiss_age(page) -> None:
    if page.locator('#ageGate:not([hidden])').count():
        page.locator('#ageEnter').click()


def button(page, section: str, identifier: str):
    return page.locator(f'[data-scene="{section}"] .public-scene-button[data-layer-id="{identifier}"]')


def publish(page, base: str, section: str) -> dict:
    page.locator(f'[data-section="{section}"]').click()
    endpoint = helpers.route_url(base, f'api/scene.php?section={section}')
    with page.expect_response(lambda response: response.url == endpoint and response.request.method == 'POST') as captured:
        page.get_by_role('button', name='Publish', exact=True).click()
    response = captured.value
    result = helpers.response_json(response, 200, f'{section} Publish')
    payload = response.request.post_data_json
    require(payload['action'] == 'publish', 'Publish control did not send a publish operation.')
    require(result['ok'] and 'published' in result.get('message', '').lower(), 'Publish did not return a visible success message.')
    expect(page.locator('[data-status]')).to_contain_text('published')
    ready_controls(page)
    return result


def save_marketing(page, base: str, tab: str, action: str, values: dict, button_label: str) -> None:
    page.goto(base + '/admin/?page=marketing&tab=' + tab, wait_until='networkidle')
    form = page.locator(f'form:has(input[name="action"][value="{action}"])')
    for name, value in values.items():
        field = form.locator(f'[name="{name}"]')
        if field.evaluate('element => element.tagName') == 'SELECT':
            field.select_option(value)
        else:
            field.fill(value)
    with page.expect_navigation(wait_until='networkidle') as submitted:
        form.get_by_role('button', name=button_label, exact=True).click()
    require(submitted.value is not None and submitted.value.status == 200,
            f'Owner {button_label} did not reach the real admin handler.')


def marketing_actions(page, public, site: Path, base: str, label: str) -> dict:
    """Exercise destinations through rendered owner/public UI and a real beacon."""
    title = 'Packaged post ' + label
    slug = 'packaged-post-' + label
    save_marketing(page, base, 'posts', 'save_post', {
        'title': title, 'slug': slug, 'excerpt': 'Synthetic packaged route acceptance.',
        'body': 'This synthetic post checks the owner View link after folder reorganization.',
        'status': 'published', 'publish_at': '2026-01-01T12:00',
    }, 'Save Post')
    row = page.locator('tr').filter(has=page.get_by_text(title, exact=True))
    view = row.get_by_role('link', name='View', exact=True)
    expected_post = helpers.route_url(base, 'blog/post.php?slug=' + slug)
    require(view.evaluate('element => element.href') == expected_post,
            'Owner Posts View points outside the configured installation.')
    with page.expect_navigation(wait_until='networkidle') as post_navigation:
        view.click()
    require(post_navigation.value is not None and post_navigation.value.status == 200
            and page.url == expected_post, 'Owner Posts View did not open the actual published post.')
    dismiss_age(page)
    expect(page.get_by_role('heading', name=title, exact=True)).to_be_visible()

    campaign_slug = 'packaged-campaign-' + label
    campaign_label = 'Campaign Booking ' + label
    destination = '/booking/?source=campaign-' + label + '#bookingForm'
    save_marketing(page, base, 'campaigns', 'save_page', {
        'title': 'Packaged campaign ' + label, 'slug': campaign_slug,
        'body': 'Synthetic campaign navigation and same-origin analytics acceptance.',
        'cta_label': campaign_label, 'cta_url': destination,
    }, 'Save Page')
    banner_label = 'Packaged booking banner ' + label
    save_marketing(page, base, 'banners', 'save_banner', {
        'text': banner_label, 'url': '/booking/?source=banner-' + label,
    }, 'Save Banner')

    public.goto(helpers.route_url(base, 'links/'), wait_until='networkidle')
    dismiss_age(public)
    for link_label, path in [('Official Website', '/'), ('Join the Velvet Club', '/membership/'),
                             ('Booking & Appearances', '/booking/')]:
        link = public.locator('.marketing-link-list').get_by_role('link', name=link_label, exact=True)
        helpers.assert_route_destination(link.evaluate('element => element.href'), base, path,
                                         f'Default {link_label} link does not use the physical installation route.')
    banner_link = public.locator('[data-marketing-banner]').get_by_role('link', name=banner_label, exact=True)
    expected_banner = helpers.route_url(base, 'booking/?source=banner-' + label)
    require(banner_link.evaluate('element => element.href') == expected_banner,
            'Promotion banner leaves the configured installation.')
    with public.expect_navigation(wait_until='networkidle') as banner_navigation:
        banner_link.click()
    require(banner_navigation.value is not None and banner_navigation.value.status == 200
            and public.url == expected_banner, 'Promotion banner did not open the actual Booking page.')
    public.goto(helpers.route_url(base, 'links/'), wait_until='networkidle')
    public.get_by_role('button', name='Dismiss promotion', exact=True).click()
    expect(public.locator('[data-marketing-banner]')).to_have_count(0)
    public.reload(wait_until='networkidle')
    expect(public.locator('[data-marketing-banner]')).to_have_count(0)

    public.goto(helpers.route_url(base, 'campaign/?slug=' + campaign_slug), wait_until='networkidle')
    campaign = public.get_by_role('link', name=campaign_label, exact=True)
    expected_destination = helpers.route_url(base, destination)
    require(campaign.evaluate('element => element.href') == expected_destination,
            'Campaign CTA leaves the configured installation.')
    script = public.locator('script[src*="/public/assets/js/marketing.js"]')
    require('?v=' in (script.get_attribute('src') or ''), 'Marketing script URL lacks the current asset revision.')
    marketing_file = site / 'storage/private/marketing/data.json'
    previous_events = {event['id'] for event in json.loads(marketing_file.read_text())['events']}
    with public.expect_navigation(wait_until='networkidle') as campaign_navigation:
        campaign.click()
    expected_event_path = urlparse(base).path + '/campaign'
    require(campaign_navigation.value is not None and campaign_navigation.value.status == 200
            and public.url == expected_destination, 'Campaign CTA did not open Booking with its query and fragment.')
    # Chromium can discard keepalive request/response details during navigation.
    # The real API must still create exactly one new event with the correct path.
    # The caller also checks the PHP server's actual POST/200 log entry.
    deadline = time.monotonic() + 5
    while True:
        events = json.loads(marketing_file.read_text())['events']
        new_clicks = [event for event in events if event['id'] not in previous_events and event['name'] == 'cta_click']
        if new_clicks:
            require(len(new_clicks) == 1 and new_clicks[0]['path'] == expected_event_path,
                    'Campaign click was duplicated or lost its installation path.')
            break
        require(time.monotonic() < deadline, 'The real campaign click was not recorded by the marketing endpoint.')
        time.sleep(0.05)
    return {'owner_posts_view_click': True, 'default_link_destinations': True,
            'banner_click_and_dismiss_persistence': True, 'campaign_cta_click': True,
            'same_origin_campaign_beacon_recorded': True, 'versioned_marketing_asset': True}


def browser_case(browser, site: Path, base: str, label: str, viewport: dict) -> dict:
    context = browser.new_context(viewport=viewport)
    visitor = browser.new_context(viewport=viewport)
    page = context.new_page()
    errors: list[str] = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill('owner@example.test')
        page.get_by_label('Password', exact=True).fill('VelvetDemo12')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        page.goto(base + '/admin/?page=composer', wait_until='networkidle')
        device = 'mobile' if viewport['width'] < 600 else 'desktop'
        before = helpers.state(site)['scenes']
        layers = {}
        for section in ['hero', 'footer']:
            page.locator(f'[data-section="{section}"]').click()
            page.locator(f'[data-device="{device}"]').click()
            page.get_by_role('button', name=re.compile(r'Add Button$')).click()
            title = f'{section.title()} {label}'
            destination = ('/booking/' if section == 'hero' else 'booking/index.php') + f'?source=composer-{section}#bookingForm'
            control(page, 'buttonLabel', title)
            control(page, 'buttonUrl', destination)
            # Place the test CTA in an open part of each real scene using the
            # owner controls, away from the separately movable live text group.
            control(page, 'layerY', 25)
            identifier = page.locator('.composer-layer.selected').get_attribute('data-layer-id')
            require(bool(identifier), 'Add Button did not select a real layer.')
            layers[section] = {'id': identifier, 'label': title, 'destination': destination}
        require(helpers.state(site)['scenes'] == before, 'Unsaved button edits changed stored drafts or live scenes.')

        endpoint = site / 'public/routes/api/scene.php'
        original_endpoint = endpoint.read_bytes()
        endpoint.unlink()
        try:
            # The real physical dispatcher must reject a missing organized handler.
            # The former implementation closed this popup in its error handler.
            with page.expect_popup() as opened, page.expect_response(lambda response: helpers.is_route_url(response.url, base, 'api/scene.php') and response.request.method == 'POST') as failed_request:
                page.get_by_role('button', name='Full Page Preview', exact=True).click()
            failed_preview = opened.value
            require(failed_request.value.status == 404, 'Missing scene endpoint was hidden by a test router.')
            expect(page.locator('[data-status]')).to_have_class(re.compile(r'\berror\b'))
            ready_controls(page)
            require(not failed_preview.is_closed(), 'Failed Full Page Preview opened and then immediately closed its tab.')
            expect(failed_preview).to_have_title('Preview could not be opened')
            expect(failed_preview.get_by_role('heading', name='Preview could not be opened')).to_be_visible()
            helpers.useful_error(page.locator('[data-status]').inner_text())
            helpers.useful_error(failed_preview.locator('p').inner_text())
            expect(failed_preview.get_by_role('link', name='Return to Composer')).to_have_attribute('href', base + '/admin/?page=composer')
            if EVIDENCE:
                failed_preview.screenshot(path=str(EVIDENCE / f'composer-actions-{label}-preview-error.png'), full_page=True)
            failed_preview.get_by_role('link', name='Return to Composer').click()
            failed_preview.wait_for_url(base + '/admin/?page=composer')
            expect(failed_preview.locator('[data-canvas]')).to_be_visible()
            failed_preview.close()

            with page.expect_response(lambda response: helpers.is_route_url(response.url, base, 'api/scene.php') and response.request.method == 'POST') as failed_publish:
                page.get_by_role('button', name='Publish', exact=True).click()
            require(failed_publish.value.status == 404, 'Missing-file Publish did not reach the real host.')
            expect(page.locator('[data-status]')).to_have_class(re.compile(r'\berror\b'))
            helpers.useful_error(page.locator('[data-status]').inner_text())
            ready_controls(page)
            require(helpers.state(site)['scenes'] == before, 'Failed preview/publish mutated stored scenes.')
        finally:
            endpoint.write_bytes(original_endpoint)

        preview_requests = []
        def record_preview(response) -> None:
            if helpers.is_route_url(response.url, base, 'api/scene.php') and response.request.method == 'POST':
                preview_requests.append(response)
        page.on('response', record_preview)
        try:
            with page.expect_popup() as opened:
                page.get_by_role('button', name='Full Page Preview', exact=True).click()
            preview = opened.value
            preview.wait_for_url(base + '/?preview=1')
            preview.wait_for_load_state('networkidle')
            ready_controls(page)
        finally:
            page.remove_listener('response', record_preview)
        require(not preview.is_closed(), 'Successful preview tab did not remain open.')
        require(preview.evaluate('window.opener === null'), 'Preview retained an unnecessary window.opener reference.')
        require(len(preview_requests) == 2, 'Full Page Preview did not save exactly the Hero and Footer drafts.')
        require([parse_qs(urlparse(response.url).query).get('section') for response in preview_requests] == [['hero'], ['footer']],
                'Full Page Preview sent a wrong or reordered scene target.')
        for response in preview_requests:
            require(helpers.is_route_url(response.url, base, 'api/scene.php'), 'Preview save lost the physical dispatcher or configured installation path.')
            require(helpers.response_json(response, 200, 'Preview draft save')['ok'], 'A preview draft did not save.')
            require(response.request.post_data_json['action'] == 'save', 'Full Page Preview published a scene instead of saving its draft.')
        dismiss_age(preview)
        public = visitor.new_page()
        public.goto(base + '/', wait_until='networkidle')
        dismiss_age(public)
        anonymous_preview = visitor.new_page()
        anonymous_preview.goto(base + '/?preview=1', wait_until='networkidle')
        for section, layer in layers.items():
            require(contains_layer(public_scene(preview, section), device, layer['id']), f'Owner preview lost the edited {section} draft.')
            expect(preview.get_by_role('link', name=layer['label'], exact=True)).to_be_visible()
            require(not contains_layer(public_scene(public, section), device, layer['id']), 'An unpublished preview edit appeared on the live homepage.')
            require(not contains_layer(public_scene(anonymous_preview, section), device, layer['id']), 'The preview query exposed owner drafts to an anonymous visitor.')
            require(helpers.state(site)['scenes'][section]['published'] == before[section]['published'], 'Saving preview changed the published scene snapshot.')
        if EVIDENCE:
            preview.screenshot(path=str(EVIDENCE / f'composer-actions-{label}-draft-preview.png'), full_page=True)
        anonymous_preview.close()
        preview.close()

        hero_result = publish(page, base, 'hero')
        require(len(hero_result['versions']) == len(before['hero']['versions']) + 1, 'Hero Publish did not create a history entry.')
        require(helpers.state(site)['scenes']['footer']['published'] == before['footer']['published'], 'Hero Publish unexpectedly published the Footer draft.')
        footer_result = publish(page, base, 'footer')
        require(len(footer_result['versions']) == len(before['footer']['versions']) + 1, 'Footer Publish did not create a history entry.')
        page.reload(wait_until='networkidle')
        for section, layer in layers.items():
            page.locator(f'[data-section="{section}"]').click()
            page.locator(f'[data-device="{device}"]').click()
            expect(page.locator(f'[data-layer-id="{layer["id"]}"]')).to_have_count(1)
            public.goto(base + '/', wait_until='networkidle')
            dismiss_age(public)
            require(contains_layer(public_scene(public, section), device, layer['id']), f'{section} Publish did not update the actual public page.')
            cta = public.get_by_role('link', name=layer['label'], exact=True)
            expected_url = cta.get_attribute('href') or ''
            helpers.assert_route_destination(expected_url, base, layer['destination'],
                                             'Published CTA lost its physical dispatcher, route, query or fragment.')
            with public.expect_navigation(wait_until='networkidle') as navigation:
                cta.click()
            require(navigation.value is not None and navigation.value.status == 200, 'Published CTA did not open the real Booking page.')
            require(public.url == expected_url, 'Published CTA lost its installation path, query or fragment.')
            expect(public.get_by_role('heading', name=re.compile(r'Booking.*Appearances'))).to_be_visible()
            expect(public.locator('#bookingForm')).to_be_visible()
        if EVIDENCE:
            page.screenshot(path=str(EVIDENCE / f'composer-actions-{label}-published.png'), full_page=True)
        marketing = marketing_actions(page, public, site, base, label)
        require(not errors, 'Composer actions caused uncaught JavaScript errors: ' + '; '.join(errors))
        return {
            'viewport': viewport, 'preview_error_tab_retained': True,
            'preview_return_link_works': True, 'publish_error_readable_and_retry_enabled': True,
            'preview_saves_two_drafts_without_publishing': True, 'owner_only_draft_preview': True,
            'publish_updates_live_scene_and_history': True, 'reload_preserves_buttons': True,
            'accessible_cta_clicks_preserve_path_query_fragment': True,
            'marketing': marketing,
        }
    finally:
        visitor.close()
        context.close()


def run() -> None:
    php = shutil.which('php')
    require(php is not None, 'PHP is required for real packaged Composer action tests.')
    package_spec = importlib.util.spec_from_file_location('vvx_composer_actions_package', ROOT / 'scripts/package.py')
    require(package_spec is not None and package_spec.loader is not None, 'The runtime package builder is missing.')
    package = importlib.util.module_from_spec(package_spec)
    package_spec.loader.exec_module(package)
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'),
                'Keep browser evidence outside customer runtime and tracked source paths.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'server': 'php -S without any router script', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-composer-actions-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary['fresh_zip_sha256'] = fresh['sha256']
        options = {'headless': True}
        executable = os.environ.get('VELVET_CHROMIUM') or os.environ.get('VV_TEST_CHROME')
        if executable:
            options['executable_path'] = executable
        browser = playwright.chromium.launch(**options)
        summary['browser'] = browser.version
        try:
            for prefix, location in [('', 'root'), ('/nested/velvet', 'nested')]:
                for viewport, device in [({'width': 1440, 'height': 1000}, 'desktop'), ({'width': 390, 'height': 844}, 'mobile')]:
                    label = location + '-' + device
                    document_root = work / label / 'www'
                    site = document_root / prefix.lstrip('/')
                    site.mkdir(parents=True)
                    with ZipFile(archive) as zipped:
                        zipped.extractall(site)
                    (site / '.vvx-test-runtime').write_text('Disposable packaged Composer action acceptance.\n')
                    with socket.socket() as listener:
                        listener.bind(('127.0.0.1', 0))
                        port = listener.getsockname()[1]
                    base = f'http://127.0.0.1:{port}{prefix}'
                    subprocess.run([php, str(helpers.FIXTURE), str(site), 'setup', base], check=True, capture_output=True, text=True)
                    preserved = {name: (site / name).read_bytes() for name in ['config/app.php', 'storage/install.lock']}
                    owners = helpers.state(site)['admins']
                    environment = os.environ.copy()
                    environment.pop('PHP_CLI_SERVER_WORKERS', None)
                    log_file = work / label / 'php-server.log'
                    with log_file.open('w+') as log:
                        process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                                   cwd=work, env=environment, stdout=log, stderr=log)
                        try:
                            session = helpers.requests_session()
                            deadline = time.monotonic() + 15
                            while True:
                                require(process.poll() is None, 'Disposable PHP server exited before becoming ready.')
                                try:
                                    if session.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                        break
                                except requests.RequestException:
                                    pass
                                require(time.monotonic() < deadline, 'Disposable PHP server did not become ready.')
                                time.sleep(0.1)
                            session.close()
                            summary['cases'][label] = browser_case(browser, site, base, label, viewport)
                            require(any(helpers.is_route_url('http://127.0.0.1:' + str(port) + match, base, 'api/marketing-event.php')
                                        for match in re.findall(r'\[200\]: POST (\S+)', log_file.read_text())),
                                    'The local PHP server did not record a successful real campaign beacon at the configured endpoint.')
                            summary['cases'][label]['marketing']['server_logged_beacon_post_200'] = True
                            require(all((site / name).read_bytes() == data for name, data in preserved.items())
                                    and helpers.state(site)['admins'] == owners, 'Composer actions changed installation identity or owner credentials.')
                            print(f'Packaged Composer actions {label}: PASS (real preview, failure/retry, draft isolation, Publish, CTA navigation, and marketing links/beacon).', flush=True)
                        finally:
                            process.terminate()
                            try:
                                process.wait(timeout=10)
                            except subprocess.TimeoutExpired:
                                process.kill()
                                process.wait(timeout=5)
                            if EVIDENCE:
                                shutil.copyfile(log_file, EVIDENCE / f'composer-actions-{label}-http.log')
        finally:
            browser.close()
    if EVIDENCE:
        (EVIDENCE / 'composer-actions-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Packaged Composer preview/Publish and marketing actions passed for root/nested desktop/mobile without rewritten test routes.', flush=True)


if __name__ == '__main__':
    run()
