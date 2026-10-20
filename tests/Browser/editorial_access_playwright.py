#!/usr/bin/env python3
"""Actual Fresh editorial access: file/MySQL, root desktop/nested mobile.

Each of four independently extracted runtimes repeats the standalone HTTP
matrix. MySQL activation uses real browser owner review/move POSTs before that
matrix, then editor and saved-preview UI are exercised at each viewport.
"""
from __future__ import annotations

import importlib.metadata
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from urllib.parse import parse_qs

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('vvx_editorial_http', ROOT / 'tests/Http/editorial_access_http.py')
assert spec and spec.loader
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)
check = http.check


def screenshot(page, evidence: Path | None, label: str):
    if evidence:
        page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
        page.screenshot(path=str(evidence / ('editorial-access-' + label + '.png')), full_page=True)


def geometry(page, label: str):
    measured = page.evaluate('''() => ({
      viewport: innerWidth, content: document.documentElement.scrollWidth,
      controls: [...document.querySelectorAll('[data-editorial-access], [data-publication-editor-card] .marketing-button, [data-preview-back]')]
        .filter(element => element.getClientRects().length).map(element => {
          const box = element.getBoundingClientRect();
          return {text: (element.innerText || element.getAttribute('aria-label') || element.tagName).trim(),
                  left: box.left, right: box.right, width: box.width, height: box.height};
        })
    })''')
    check(measured['content'] <= measured['viewport'] + 1, label + ' overflows horizontally: ' + repr(measured))
    check(all(control['left'] >= -1 and control['right'] <= measured['viewport'] + 1
              and control['height'] >= 40 for control in measured['controls']),
          label + ' has clipped or undersized access/preview controls: ' + repr(measured))
    return measured


def keyboard_focus(page, control):
    control.focus()
    page.keyboard.press('Shift+Tab')
    page.keyboard.press('Tab')
    check(control.evaluate('(element) => document.activeElement === element && element.matches(":focus-visible")'),
          'An editorial access control is not keyboard reachable with visible focus.')


def enter_fixture_age_gate(page):
    gate = page.locator('#ageGate:not([hidden])')
    if gate.count():
        # Reserved adult acceptance accounts use the actual existing age gate.
        page.locator('#ageEnter').click()
        expect(page.locator('#ageGate')).to_be_hidden()


def owner_login(page, base: str):
    page.goto(base + '/admin/?page=login', wait_until='networkidle')
    page.get_by_label('Email address').fill(http.OWNER)
    page.get_by_label('Password', exact=True).fill(http.PASSWORD)
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.wait_for_url(base + '/admin/')


def submit(page, control, label: str, redirect=True):
    with page.expect_navigation(wait_until='networkidle') as navigation:
        control.click()
    response = navigation.value
    check(response is not None and response.status == 200, label + ' returned an unexpected HTTP status.')
    if redirect:
        check(response.request.method == 'GET' and response.request.redirected_from is not None
              and response.request.redirected_from.method == 'POST', label + ' did not use POST/Redirect/GET.')
    else:
        check(response.request.method == 'POST', label + ' did not submit the actual owner form.')
    check(page.locator('.admin-alert.error').count() == 0,
          label + ' returned an owner error: ' + ' '.join(page.locator('.admin-alert.error').all_inner_texts()))


def browser_activate(browser, case: dict, viewport: dict):
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    actions, errors = [], []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def posted(request):
        if request.method == 'POST':
            actions.extend(parse_qs(request.post_data or '').get('action', []))

    page.on('request', posted)
    try:
        before = http.fixture(case['site'], 'read', case['php'])
        check(before['status']['state'] == 'ready', 'Browser SQL activation requires an actual installed, unmoved fixture.')
        owner_login(page, case['base'])
        page.goto(case['base'] + '/admin/?page=marketing-storage', wait_until='networkidle')
        expect(page.locator('[data-marketing-storage]')).to_have_attribute('data-storage-state', 'ready')
        submit(page, page.get_by_role('button', name='Preview Move', exact=True), 'Browser SQL storage review', redirect=False)
        submit(page, page.get_by_role('button', name='Move to MySQL', exact=True), 'Browser SQL storage activation')
        expect(page.locator('[data-marketing-storage]')).to_have_attribute('data-storage-state', 'mysql')
        after = http.fixture(case['site'], 'read', case['php'])
        http.independent_sql(after)
        check(before['marketing'] == after['marketing'] and before['legacy_sha256'] == after['legacy_sha256'],
              'Browser SQL activation changed the original editorial snapshot.')
        http.protected(before, after)
        check(actions == ['preview_marketing_storage', 'migrate_marketing_storage'] and not errors,
              'Browser activation skipped a real review/move action or raised a JavaScript error.')
        return {'real_owner_post_actions': actions, 'exact_sql_import_and_original_json_preserved': True,
                'javascript_errors': errors, 'mysql_version': after['mysql_version']}
    finally:
        context.close()


def browser_case(browser, case: dict, viewport: dict, evidence: Path | None):
    site, base, php, label = case['site'], case['base'], case['php'], case['label']
    context = browser.new_context(viewport=viewport)
    owner = context.new_page()
    errors, actions, preview_requests = [], [], []
    measures = {}
    contexts = []

    def listen(page):
        page.on('pageerror', lambda error: errors.append(str(error)))

    context.on('page', listen)
    listen(owner)

    def posted(request):
        if request.method == 'POST':
            actions.extend(parse_qs(request.post_data or '').get('action', []))

    owner.on('request', posted)
    try:
        owner_login(owner, base)
        initial = http.fixture(site, 'read', php)
        url = http.editor_url(base, 'posts')
        owner.goto(url, wait_until='networkidle')
        editor = owner.locator('form[data-publication-editor="posts"]')
        access = editor.locator('[data-editorial-access]')
        expect(access).to_have_value('tiers')
        expect(editor.locator('[data-editorial-plan-options] input:checked')).to_have_count(1)
        keyboard_focus(owner, access)
        access.select_option('members')
        editor.locator('[name="body"]').fill('Saved owner preview — this draft is waiting for publication.')
        submit(owner, editor.get_by_role('button', name='Save Post', exact=True), 'Save member-only draft through the actual editor')
        updated = http.fixture(site, 'read', php)
        saved_post = http.record(updated, 'posts', 'draft')
        check(saved_post['access'] == {'version': 1, 'mode': 'members', 'plan_ids': []}
              and saved_post['status'] == 'draft' and saved_post['body'] == 'Saved owner preview — this draft is waiting for publication.',
              'The browser save did not preserve a member-only unpublished draft.')
        expect(owner.locator('form[data-publication-editor="posts"] [data-editorial-access]')).to_have_value('members')
        measures['editor'] = geometry(owner, label + ' saved access editor')
        screenshot(owner, evidence, label + '-editor')
        owner.locator('form[data-publication-editor="posts"] [name="body"]').fill('UNSAVED_BROWSER_PREVIEW_INJECTION')

        for collection, kind in (('posts', 'post'), ('banners', 'banner'), ('landing_pages', 'page')):
            if collection != 'posts':
                owner.goto(http.editor_url(base, collection), wait_until='networkidle')
            link = owner.locator('[data-publication-editor-card="' + collection + '"] a[data-publication-preview]')
            expect(link).to_have_attribute('href', http.preview_url(base, collection))
            keyboard_focus(owner, link)
            before = http.fixture(site, 'read', php)
            with owner.expect_popup() as opened:
                link.click()
            preview = opened.value
            requests_for_preview = []
            preview.on('request', lambda request: requests_for_preview.append({'url': request.url, 'method': request.method}))
            preview.wait_for_load_state('networkidle')
            expect(preview.locator('[data-marketing-preview]')).to_be_visible()
            expect(preview.locator('[data-preview-content]')).to_have_attribute('data-preview-content', collection)
            expect(preview.locator('[data-preview-content]')).to_have_attribute('data-preview-id', 'editorial-' + kind + '-draft')
            check('UNSAVED_BROWSER_PREVIEW_INJECTION' not in preview.content(), 'The saved preview rendered unsaved browser fields.')
            if collection == 'posts':
                expect(preview.locator('[data-preview-content]')).to_contain_text(saved_post['body'])
            else:
                expect(preview.locator('[data-preview-inert]')).to_have_count(1)
                check(preview.locator('[data-preview-inert]').get_attribute('href') is None,
                      'A preview link can navigate to an event-producing public destination.')
                preview_address = preview.url
                preview.locator('[data-preview-inert]').click()
                check(preview.url == preview_address, 'An inert preview control navigated away from the private preview.')
            check(preview.locator('script, form, [data-marketing-event]').count() == 0,
                  'A saved preview contains scripts, forms or marketing tracking hooks.')
            measures[collection + '_preview'] = geometry(preview, label + ' ' + collection + ' saved preview')
            screenshot(preview, evidence, label + '-' + kind + '-preview')
            preview.reload(wait_until='networkidle')
            check(all(entry['method'] == 'GET' and not any(marker in entry['url'] for marker in
                      ('marketing-event', 'member-state', 'heartbeat', 'marketing.js', 'site.js'))
                      for entry in requests_for_preview), 'A private preview emitted tracking or member heartbeat requests.')
            preview_requests.extend(requests_for_preview)
            after = http.fixture(site, 'read', php)
            check(after['marketing'] == before['marketing'] and after['sql'] == before['sql']
                  and after['legacy_sha256'] == before['legacy_sha256'] and after['outbox'] == before['outbox'],
                  'Opening, reloading or interacting with saved preview wrote analytics, campaign, marketing or mail state.')
            preview.close()

        for name, expected_policies, banner in (
            ('guest', {'legacy', 'public'}, 'legacy'),
            ('free', {'legacy', 'public', 'members'}, 'members'),
            ('gold', {'legacy', 'public', 'members', 'gold'}, 'gold')):
            reader_context = browser.new_context(viewport=viewport)
            contexts.append(reader_context)
            reader = reader_context.new_page()
            listen(reader)
            if name != 'guest':
                reader.goto(http.route(base, 'account/login.php?return=%2Fblog%2F'), wait_until='networkidle')
                enter_fixture_age_gate(reader)
                reader.get_by_label('Email address').fill(name + '@example.test')
                reader.get_by_label('Password', exact=True).fill(http.MEMBER_PASSWORD)
                reader.get_by_role('button', name='Sign In', exact=True).click()
                reader.wait_for_load_state('networkidle')
            reader.goto(http.route(base, 'blog/'), wait_until='networkidle')
            enter_fixture_age_gate(reader)
            actual_titles = set(reader.locator('.marketing-grid .marketing-card h2').all_text_contents())
            check(actual_titles == {'Editorial post ' + value for value in expected_policies},
                  'The browser blog list exposed the wrong viewer content for ' + name + ': ' + repr(actual_titles))
            expect(reader.locator('body')).to_contain_text('EDITORIAL_BANNER_' + banner)
            denied = reader.goto(http.route(base, 'blog/post.php?slug=editorial-post-draft'), wait_until='networkidle')
            check(denied is not None and denied.status == 404 and saved_post['body'] not in reader.content(),
                  'An unpublished draft became public to a browser reader after the access edit.')
            rejected_preview = reader.goto(http.preview_url(base, 'posts'), wait_until='networkidle')
            check(rejected_preview is not None and rejected_preview.status == 403
                  and reader.locator('body').inner_text() == 'Preview unavailable.',
                  'A guest or member browser opened private saved-owner preview.')
            if name == 'gold':
                response = reader.goto(http.route(base, 'blog/post.php?slug=editorial-post-gold'), wait_until='networkidle')
                check(response is not None and response.status == 200, 'An eligible browser member lost selected-tier content.')
                expect(reader.locator('h1.marketing-title')).to_have_text('Editorial post gold')
                measures['member_view'] = geometry(reader, label + ' selected-tier reader')
                screenshot(reader, evidence, label + '-member-view')
        final = http.fixture(site, 'read', php)
        http.protected(initial, final)
        if case['mode'] == 'mysql':
            http.independent_sql(final)
        check(not errors, 'Editorial browser JavaScript errors: ' + repr(errors))
        check(actions == ['save_post'], 'The actual browser editor did not submit its expected owner action.')
        check(case['capture'].connections == 0 and not case['capture'].payloads and not case['capture'].errors,
              'Editorial browser work contacted SMTP or sent a message.')
        return {'actual_owner_edit_actions': actions, 'access_form_round_trip_and_keyboard_focus': True,
                'saved_preview_all_three_collections': True, 'unsaved_browser_fields_not_previewed': True,
                'preview_cta_and_banner_destinations_inert': True,
                'preview_open_reload_and_interaction_leave_no_events_or_mail': True,
                'guest_free_member_and_matching_tier_browser_views': True,
                'draft_stays_unpublished_and_nonowners_cannot_preview': True,
                'original_archive_and_frozen_marketing_records_preserved': True,
                'mysql_payload_readback': case['mode'] == 'mysql', 'preview_requests': preview_requests,
                'javascript_errors': errors, 'geometry': measures,
                'smtp_connections': case['capture'].connections, 'smtp_messages': len(case['capture'].payloads)}
    finally:
        for reader_context in contexts:
            reader_context.close()
        context.close()


def run():
    evidence = http.evidence_directory()
    summary = {'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'),
               'server': 'php -S without any router script', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-editorial-browser-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        archive, identity = http.prepare_package(work)
        summary.update(identity)
        options = {'headless': True}
        executable = os.environ.get('VELVET_CHROMIUM') or os.environ.get('VV_TEST_CHROME')
        if executable:
            options['executable_path'] = executable
        browser = playwright.chromium.launch(**options)
        summary['browser'] = browser.version
        try:
            for mode in ('file', 'mysql'):
                for name, prefix, viewport in (('root-desktop', '', {'width': 1440, 'height': 1000}),
                                               ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})):
                    label = mode + '-' + name
                    with http.runtime(work, archive, mode, label, prefix, evidence) as case:
                        activation = browser_activate(browser, case, viewport) if mode == 'mysql' else None
                        result = http.http_case(case['site'], case['base'], case['php'], case['capture'], activate=False)
                        summary['cases'][label] = {'viewport': viewport, 'mysql_activation': activation, 'http': result,
                                                  'browser': browser_case(browser, case, viewport, evidence)}
                    print('Editorial Access browser ' + label + ': PASS.', flush=True)
        finally:
            browser.close()
    if evidence:
        (evidence / 'editorial-access-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps(summary, indent=2), flush=True)
    print('Editorial access actual-package browser acceptance passed: four file/MySQL root-desktop/nested-mobile cases.', flush=True)


if __name__ == '__main__':
    run()
