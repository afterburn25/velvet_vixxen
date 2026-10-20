"""Publication owner and public HTTP acceptance in a guarded temporary package."""
from __future__ import annotations

import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
from urllib.parse import parse_qsl, quote, urlencode, urlparse

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('vvx_publication_http_helpers', ROOT / 'tests/Http/marketing_audiences_http.py')
assert spec is not None and spec.loader is not None
common = importlib.util.module_from_spec(spec)
spec.loader.exec_module(common)
fields, state, session, login = common.fields, common.state, common.session, common.login


def initialize(php: str, site: Path):
    result = subprocess.run([php, str(ROOT / 'tests/Http/publication_windows_fixture.php'), str(site), 'initialize'],
                            text=True, capture_output=True, timeout=20, cwd=tempfile.gettempdir())
    if result.returncode != 0:
        raise AssertionError('Guarded publication fixture failed: ' + result.stderr[:800])
    return json.loads(result.stdout)


def run_http(site: Path, base: str, php: str = 'php') -> dict:
    if not site.resolve().is_relative_to(Path(tempfile.gettempdir()).resolve()) or not (site / '.vvx-test-runtime').is_file() \
            or urlparse(base).hostname != '127.0.0.1' or urlparse(base).scheme != 'http':
        raise AssertionError('A marked temporary localhost installation is required.')
    checks = 0

    def check(condition, message):
        nonlocal checks
        checks += 1
        if not condition:
            raise AssertionError(message)

    owner, public, guest = login(base), session(), session()
    path = site / 'storage/private/marketing/data.json'
    preserved_files = {name: (site / name).read_bytes() for name in ('config/app.php', 'storage/install.lock')}
    protected = ['contacts', 'consents', 'campaigns', 'campaign_queue', 'segments', 'settings', 'owner_fixture_unrelated', 'links', 'referral_codes']
    preserved = {key: copy.deepcopy(state(site)[key]) for key in protected}
    urls = {collection: base + '/admin/?page=marketing&tab=' + tab for collection, tab in [('posts', 'posts'), ('banners', 'banners'), ('landing_pages', 'campaigns')]}
    def route(destination):
        parsed = urlparse(destination)
        return base + '/route.php?' + urlencode([('vvx_route', parsed.path.strip('/')), *parse_qsl(parsed.query)], quote_via=quote)

    selector = lambda collection: 'form[data-publication-editor="' + collection + '"]'
    row_form = lambda record: '[data-publication-id="' + record + '"] form:has(input[name="action"][value="set_publication_enabled"])'

    def saved_post(collection, form):
        response = owner.post(urls[collection], data=form, timeout=10)
        check(response.status_code == 200 and bool(response.history) and response.request.method == 'GET', 'Publication save did not redirect to a real owner GET.')
        check('admin-alert error' not in response.text, 'Publication save returned an error.')
        return response, fields(response.text, selector(collection))

    def rejection(collection, form, expected):
        before = path.read_bytes()
        response = owner.post(urls[collection], data=form, timeout=10)
        check(expected in response.text and path.read_bytes() == before, 'Rejected publication edit changed storage or hid its reason: ' + expected)
        return response

    try:
        for destination in ['blog/post.php?slug=legacy-invalid-post', 'campaign/?slug=legacy-invalid-page']:
            response = public.get(route(destination), timeout=10)
            check(response.status_code == 404, 'Invalid legacy content is publicly visible.')
        response = public.get(route('blog/'), timeout=10)
        check('Invalid legacy banner' not in response.text and 'Invalid legacy post' not in response.text, 'Invalid legacy post/banner reached public HTML.')
        for collection in urls:
            page = owner.get(urls[collection], timeout=10)
            check(page.status_code == 200 and 'America/New_York' in page.text and 'Needs review' in page.text, 'Owner cannot inspect saved dates in the configured timezone.')
        post_form = fields(owner.get(urls['posts'], timeout=10).text, selector('posts'))
        post_form.update(title='Owner <publication> & exact', slug='owner-window-post', category='Publication fixture',
                         excerpt='Plain excerpt.', body='Saved post content.\nSecond line.', status='published', publish_at='2024-06-01T12:30')
        before = path.read_bytes()
        denied = guest.post(urls['posts'], data=post_form, allow_redirects=False, timeout=10)
        check(denied.status_code in (302, 303, 403) and path.read_bytes() == before, 'Guest created a publication.')
        for token in ('', 'wrong-fixture-token'):
            rejection('posts', {**post_form, '_csrf': token}, 'session token expired')
        get_action = owner.get(urls['posts'], params={'action': 'save_post', 'title': 'GET must not save'}, timeout=10)
        check(get_action.status_code == 200 and path.read_bytes() == before, 'GET performed a publication mutation.')
        response, editing = saved_post('posts', post_form)
        post_id = editing['id']
        record = next(row for row in state(site)['posts'] if row['id'] == post_id)
        check(record['publish_at'] == '2024-06-01T16:30:00+00:00' and record['body'] == post_form['body'], 'Real form lost site timezone or multiline text.')
        check(editing['publication_revision'] and editing['publish_at'] == '2024-06-01T12:30:00' and editing['slug'] == 'owner-window-post', 'Saved editor did not reopen its identity, revision and local time.')
        check(BeautifulSoup(response.text, 'html.parser').select_one(selector('posts') + ' [name="slug"]').has_attr('readonly'), 'Existing permalink is not presented as stable.')
        view = BeautifulSoup(response.text, 'html.parser').select_one('[data-publication-id="' + post_id + '"] a:nth-of-type(2)')
        check(view is not None and view['href'] == route('blog/post.php?slug=owner-window-post'), 'Saved owner View link leaves the actual configured route.')
        visible = public.get(view['href'], timeout=10)
        check(visible.status_code == 200 and 'Owner &lt;publication&gt; &amp; exact' in visible.text and 'Saved post content.' in visible.text, 'Published post is missing or unescaped on its stable permalink.')
        stale = copy.deepcopy(editing)
        response, editing = saved_post('posts', {**editing, 'title': 'Edited owner post', 'body': 'Revised preserved post body.', 'publish_at': '2030-06-01T12:30'})
        check(editing['id'] == post_id and len(state(site)['posts']) == 2, 'Editing duplicated the post or changed its ID.')
        check(public.get(view['href'], timeout=10).status_code == 404, 'Future published post is visible before its date.')
        rejected = rejection('posts', {**stale, 'title': 'Stale overwritten title'}, 'This publication changed')
        stale_display = fields(rejected.text, selector('posts'))
        check(stale_display['publication_revision'] == stale['publication_revision'] and stale_display['title'] == 'Stale overwritten title', 'Stale rejection replaced the submitted revision or lost attempted content.')
        rejection('posts', {**editing, 'slug': 'different-address'}, 'saved permalink cannot be changed')
        rejection('posts', {**post_form, 'slug': 'owner-window-post'}, 'permalink is already used')
        for updates in [{'publish_at': '2024-02-30T12:30'}, {'publish_at': '2024-03-10T02:30'}, {'publish_at': 'tomorrow'},
                        {'publish_at': '2024-06-01T12:30\r'}, {'status': 'unlisted'}, {'publication_revision': ''}, {'id': 'injected-id'}, {'title[]': 'array'}]:
            changed = {**editing, **updates}
            if 'title[]' in updates:
                changed.pop('title')
            rejected = rejection('posts', changed, 'admin-alert error')
            if updates.get('publish_at') == 'tomorrow':
                invalid_field = BeautifulSoup(rejected.text, 'html.parser').select_one(selector('posts') + ' [name="publish_at"]')
                check(invalid_field['type'] == 'text' and invalid_field['value'] == 'tomorrow', 'Invalid date disappeared from its repair form.')
        response, editing = saved_post('posts', {**editing, 'publish_at': '', 'status': 'draft'})
        check(public.get(view['href'], timeout=10).status_code == 404, 'Draft post is visible with a blank date.')
        toggle = fields(response.text, row_form(post_id))
        response = owner.post(urls['posts'], data=toggle, timeout=10)
        check('Post updated. Active.' in response.text and public.get(view['href'], timeout=10).status_code == 200, 'Publish control failed to use the existing saved post.')
        immediate = public.get(view['href'], timeout=10)
        check('1970' not in immediate.text, 'Blank publication date displayed an epoch date.')
        rejection('posts', toggle, 'This publication changed')
        deletion = fields(response.text, '[data-publication-id="' + post_id + '"] form:has(input[name="action"][value="delete_publication"])')
        rejection('posts', {**deletion, 'action': 'delete', 'publication_revision': ''}, 'This publication changed')

        # Banner windows and overlap use actual rendered public HTML.
        banner_form = fields(owner.get(urls['banners'], timeout=10).text, selector('banners'))
        banner_form.update(text='First <banner> fixture', url='/membership/?fixture=first', starts_at='2030-01-01T00:00', ends_at='2031-01-01T00:00', enabled='1')
        response, banner_edit = saved_post('banners', banner_form)
        banner_id = banner_edit['id']
        html = public.get(route('blog/'), timeout=10).text
        check('First &lt;banner&gt; fixture' not in html, 'Scheduled banner appeared before its window.')
        response, banner_edit = saved_post('banners', {**banner_edit, 'starts_at': '2024-01-01T00:00', 'ends_at': '2030-01-01T00:00'})
        html = public.get(route('blog/'), timeout=10).text
        check('First &lt;banner&gt; fixture' in html, 'Banner did not appear during its active window.')
        second_form = fields(owner.get(urls['banners'], timeout=10).text, selector('banners'))
        response, second_edit = saved_post('banners', {**second_form, 'text': 'Second banner fixture', 'url': '/membership/', 'enabled': '1'})
        check('shown now' in response.text and 'waiting behind the shown banner' in response.text, 'Owner cannot identify overlapping banner precedence.')
        html = public.get(route('blog/'), timeout=10).text
        check('First &lt;banner&gt; fixture' in html and 'Second banner fixture' not in html, 'A later active banner silently replaced saved precedence.')
        response = owner.post(urls['banners'], data=fields(response.text, row_form(banner_id)), timeout=10)
        html = public.get(route('blog/'), timeout=10).text
        check('First &lt;banner&gt; fixture' not in html and 'Second banner fixture' in html, 'Disable did not reveal the next active banner.')
        response = owner.get(urls['banners'] + '&edit_banner=' + banner_id, timeout=10)
        banner_edit = fields(response.text, selector('banners'))
        response, banner_edit = saved_post('banners', {**banner_edit, 'enabled': '1', 'ends_at': '2025-01-01T00:00'})
        html = public.get(route('blog/'), timeout=10).text
        check('Ended' in response.text and 'First &lt;banner&gt; fixture' not in html and 'Second banner fixture' in html, 'Ended banner remained public or blocked the next active banner.')
        for updates in [{'ends_at': '2024-01-01T00:00'}, {'ends_at': '2023-01-01T00:00'}, {'ends_at': 'not-a-date'}, {'url': 'javascript:alert(1)'}, {'enabled[]': '1'}]:
            changed = {**banner_edit, **updates}
            if 'enabled[]' in updates:
                changed.pop('enabled', None)
            rejection('banners', changed, 'admin-alert error')

        landing_form = fields(owner.get(urls['landing_pages'], timeout=10).text, selector('landing_pages'))
        landing_form.update(title='Landing <window> fixture', slug='owner-window-page', body='Landing body.', cta_label='Fixture join',
                            cta_url='/membership/?fixture=landing', starts_at='2030-01-01T00:00', ends_at='2031-01-01T00:00', enabled='1')
        response, landing_edit = saved_post('landing_pages', landing_form)
        page_id = landing_edit['id']
        page_url = route('campaign/?slug=owner-window-page')
        check(public.get(page_url, timeout=10).status_code == 404, 'Future landing page is visible before its window.')
        response, landing_edit = saved_post('landing_pages', {**landing_edit, 'starts_at': '2024-01-01T00:00', 'ends_at': '2030-01-01T00:00'})
        check(public.get(page_url, timeout=10).status_code == 200, 'Landing page did not become visible during its window.')
        response, landing_edit = saved_post('landing_pages', {**landing_edit, 'title': 'Revised page title', 'body': 'Edited stable page.', 'ends_at': '2025-01-01T00:00'})
        check(public.get(page_url, timeout=10).status_code == 404 and landing_edit['id'] == page_id and landing_edit['slug'] == 'owner-window-page', 'Ended page broke its identity or remained public.')
        response, landing_edit = saved_post('landing_pages', {**landing_edit, 'ends_at': ''})
        html = public.get(page_url, timeout=10)
        check(html.status_code == 200 and 'Edited stable page.' in html.text, 'Open end failed to restore the existing landing page content.')
        response = owner.post(urls['landing_pages'], data=fields(response.text, row_form(page_id)), timeout=10)
        check(public.get(page_url, timeout=10).status_code == 404, 'Owner disable did not withdraw the landing page.')

        # Legacy bad dates stay visible as editable text, never silently blank.
        for collection, key, record_id in [('posts', 'publish_at', 'legacy-window-post'), ('banners', 'starts_at', 'legacy-window-banner'), ('landing_pages', 'ends_at', 'legacy-window-page')]:
            edit_key = {'posts': 'edit_post', 'banners': 'edit_banner', 'landing_pages': 'edit_page'}[collection]
            response = owner.get(urls[collection] + '&' + edit_key + '=' + record_id, timeout=10)
            bad = fields(response.text, selector(collection))
            element = BeautifulSoup(response.text, 'html.parser').select_one(selector(collection) + ' [name="' + key + '"]')
            check(element['type'] == 'text' and bad[key].startswith('invalid-legacy'), 'Legacy invalid date was erased from the owner form.')
            rejection(collection, bad, 'admin-alert error')
            toggle = fields(response.text, row_form(record_id))
            response = owner.post(urls[collection], data=toggle, timeout=10)
            withdrawn = next(row for row in state(site)[collection] if row['id'] == record_id)
            check(bool(response.history) and (withdrawn['status'] == 'draft' if collection == 'posts' else withdrawn['enabled'] is False), 'Legacy publication withdrawal failed.')
            rejection(collection, fields(response.text, row_form(record_id)), 'Repair this publication')
            response = owner.get(urls[collection] + '&' + edit_key + '=' + record_id, timeout=10)
            repaired = fields(response.text, selector(collection))
            repaired[key] = ''
            if collection == 'posts': repaired['status'] = 'published'
            else: repaired['enabled'] = '1'
            response, repaired = saved_post(collection, repaired)
            check(repaired['id'] == record_id, 'Legacy date repair duplicated its record.')

        # Every new mutation remains owner-only with current role and CSRF.
        latest = owner.get(urls['banners'], timeout=10)
        toggle = fields(latest.text, row_form(second_edit['id']))
        rejection('banners', {**toggle, '_csrf': ''}, 'session token expired')
        rejection('banners', {**toggle, 'collection': 'contacts'}, 'Choose a post, banner or landing page')
        before = path.read_bytes()
        denied = guest.post(urls['banners'], data=toggle, allow_redirects=False, timeout=10)
        check(denied.status_code in (302, 303, 403) and path.read_bytes() == before, 'Guest changed visibility.')
        member_result = subprocess.run([php, str(common.MEMBER_FIXTURE), str(site), 'member-session'], text=True, capture_output=True, timeout=20)
        check(member_result.returncode == 0, 'Real member-only fixture login failed.')
        member_info = json.loads(member_result.stdout)
        member = session()
        member.cookies.set(member_info['name'], member_info['id'], domain='127.0.0.1', path='/')
        before = path.read_bytes()
        denied = member.post(urls['banners'], data={**toggle, '_csrf': member_info['csrf']}, allow_redirects=False, timeout=10)
        check(denied.status_code in (302, 303, 403) and path.read_bytes() == before, 'Member changed owner publication visibility.')
        member.close()
        for role, active in [('editor', True), ('owner', False)]:
            common.fixture(php, site, 'owner', role=role, active=active)
            before = path.read_bytes()
            denied = owner.post(urls['banners'], data=toggle, allow_redirects=False, timeout=10)
            check(denied.status_code in (302, 303, 403) and path.read_bytes() == before, 'Stale owner session bypassed current role/active checks.')
            common.fixture(php, site, 'owner', role='owner', active=True)
        check(all(state(site)[key] == value for key, value in preserved.items()), 'Publication actions changed frozen campaign recipients/content or unrelated marketing records.')
        check(all((site / name).read_bytes() == value for name, value in preserved_files.items()), 'Publication actions changed installation identity or mail configuration.')
        check(all(row['attempts'] == 0 and row['status'] == 'queued' for row in state(site)['campaign_queue']), 'Publication acceptance attempted email delivery.')
        check(not json.loads((site / 'storage/data.json').read_text()).get('mailOutbox'), 'Publication operations created transactional messages.')
        return {'assertions': checks, 'real_owner_http': True, 'stable_identity_permalink': True, 'site_timezone': 'America/New_York',
                'strict_dates_and_stale_rejections': True, 'public_before_during_after': True, 'explicit_banner_precedence': True,
                'current_owner_csrf': True, 'frozen_campaigns_preserved': True, 'external_messages_sent': 0}
    finally:
        owner.close()
        public.close()
        guest.close()


if __name__ == '__main__':
    result = run_http(Path(os.environ['VELVET_TEST_RUNTIME']), os.environ['VELVET_BASE_URL'], os.environ.get('VELVET_PHP', 'php'))
    print('Publication windows HTTP acceptance passed: ' + json.dumps(result, sort_keys=True))
