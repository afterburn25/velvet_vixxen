"""Real owner media forms, persistence, stale edits and scheduler boundaries."""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
from urllib.parse import urlencode, urlparse

from bs4 import BeautifulSoup
import requests

FIXTURE = Path(__file__).with_name('media_center_fixture.php')
OWNER = 'owner@example.test'
CAPTION = 'Caption <em>literal markup</em> & "quoted"\nSecond line — café'
checks = 0


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


def fixture(root: Path, action: str, value: str | None = None):
    command = ['php', str(FIXTURE), str(root), action]
    if value is not None:
        command.append(value)
    result = subprocess.run(command, text=True, capture_output=True, timeout=45, cwd='/tmp')
    check(result.returncode == 0, 'Guarded Media Center fixture failed: ' + result.stderr[:1000])
    return json.loads(result.stdout)


def session():
    client = requests.Session()
    client.trust_env = False
    return client


def route(base: str, destination: str):
    path, _, query = destination.partition('?')
    return base + '/route.php?' + urlencode({'vvx_route': path}) + ('&' + query if query else '')


def form_values(html: str, action: str | None = None):
    soup = BeautifulSoup(html, 'html.parser')
    form = (soup.select_one('form:has(input[name="action"][value="' + action + '"])')
            if action else soup.select_one('form'))
    check(form is not None, 'The requested real form is missing: ' + str(action))
    fields = {}
    for element in form.select('input[name], select[name], textarea[name]'):
        if element.has_attr('disabled') or element.get('type') in ('file', 'submit', 'button'):
            continue
        if element.get('type') in ('checkbox', 'radio') and not element.has_attr('checked'):
            continue
        if element.name == 'textarea':
            value = element.text
        elif element.name == 'select':
            option = element.select_one('option[selected]') or element.select_one('option')
            value = option.get('value', '') if option else ''
        else:
            value = element.get('value', '')
        name = element['name']
        if name.endswith('[]'):
            fields.setdefault(name, []).append(value)
        else:
            fields[name] = value
    return fields


def login(base: str, owner=None):
    owner = session() if owner is None else owner
    page = owner.get(base + '/admin/?page=login', timeout=10)
    fields = form_values(page.text)
    result = owner.post(base + '/admin/?page=login', data={'_csrf': fields['_csrf'],
                        'email': OWNER, 'password': 'VelvetDemo12'}, timeout=10)
    check(result.status_code == 200 and 'page=login' not in result.url, 'Real owner sign-in failed.')
    return owner


def snapshot(site: Path):
    state = fixture(site, 'read')
    return {name: state[name] for name in ('gallery', 'playlist', 'assets', 'unrelated', 'plan_ids')}


def gallery_fields(owner, url: str, retained: list[str] | None = None):
    page = owner.get(url, timeout=10)
    check(page.status_code == 200 and 'data-gallery-editor' in page.text, 'Real gallery editor did not load.')
    fields = form_values(page.text, 'save_gallery')
    if retained is not None:
        fields['asset_order'] = json.dumps(retained)
        for name in list(fields):
            if name.startswith(('asset_captions[', 'asset_tags[')) and name.rsplit('[', 1)[1][:-1] not in retained:
                del fields[name]
    return fields


def playlist_fields(owner, url: str):
    page = owner.get(url, timeout=10)
    check(page.status_code == 200, 'Real playlist editor did not load.')
    return form_values(page.text, 'save_audio_playlist')


def submit(owner, url: str, fields: dict):
    response = owner.post(url, data=fields, timeout=15)
    check(response.status_code == 200 and bool(response.history)
          and response.history[0].status_code in (302, 303) and response.request.method == 'GET',
          'Owner media action did not redirect after saving: ' + response.text[:200])
    check(not BeautifulSoup(response.text, 'html.parser').select('.admin-alert.error'),
          'Owner media action returned an error.')
    return response


def playlist_card(html: str, title: str):
    soup = BeautifulSoup(html, 'html.parser')
    heading = next((item for item in soup.select('h3') if item.get_text(strip=True) == title), None)
    return heading.find_parent('article') if heading else None


def run():
    base = os.environ.get('VELVET_BASE_URL', '').rstrip('/')
    site = Path(os.environ.get('VELVET_TEST_RUNTIME', '')).resolve()
    check(urlparse(base).hostname == '127.0.0.1' and str(site).startswith('/tmp/')
          and (site / '.vvx-test-runtime').is_file(), 'A marked loopback HTTP test runtime is required.')
    fixture(site, 'initialize')
    initial = fixture(site, 'read')
    assets = {key: str(value['id']) for key, value in initial['seed']['assets'].items()}
    tracks = {key: str(value['id']) for key, value in initial['seed']['tracks'].items()}
    gallery_url = base + '/admin/?' + urlencode({'page': 'galleries', 'edit': initial['gallery']['id']})
    playlist_url = base + '/admin/?' + urlencode({'page': 'music', 'edit_playlist': initial['playlist']['id']})
    public_gallery = route(base, 'photos/view.php?slug=' + initial['gallery']['slug'])
    public_music = route(base, 'music')
    owner = login(base)
    anonymous = session()
    member = session()
    try:
        response = owner.get(gallery_url, timeout=10)
        check('no-store' in response.headers.get('Cache-Control', ''), 'Private media forms can be cached.')
        stale = gallery_fields(owner, gallery_url)
        fields = gallery_fields(owner, gallery_url, [assets['c'], assets['a']])
        fields['asset_captions[' + assets['c'] + ']'] = CAPTION
        fields['asset_tags[' + assets['c'] + ']'] = 'studio, sunset'
        submit(owner, gallery_url, fields)
        saved = fixture(site, 'read')
        check([str(item['assetId']) for item in saved['gallery']['items']] == [assets['c'], assets['a']],
              'HTTP gallery save did not persist exact membership and order.')
        check(saved['gallery']['items'][0]['caption'] == CAPTION and saved['gallery']['items'][0]['asset']['tags'] == ['studio', 'sunset'],
              'HTTP captions/tags did not survive a separate PHP read.')
        check(str(saved['gallery']['coverAssetId']) == assets['c'] and [bool(item['isPreview']) for item in saved['gallery']['items']] == [True, False],
              'HTTP removal did not update cover fallback and preview positions.')
        check(saved['plan_ids'] == [str(initial['seed']['plan']['id'])], 'Ordinary gallery edits cleared an omitted membership-plan restriction.')
        reloaded = gallery_fields(owner, gallery_url)
        check(reloaded['asset_captions[' + assets['c'] + ']'] == CAPTION
              and reloaded['asset_tags[' + assets['c'] + ']'] == 'studio, sunset', 'Actual editor lost saved captions/tags on reload.')
        reloaded['title'] = 'HTTP retained gallery'
        submit(owner, gallery_url, reloaded)
        check([str(item['assetId']) for item in fixture(site, 'read')['gallery']['items']] == [assets['c'], assets['a']],
              'A normal follow-up save reappended the removed photo.')
        public = anonymous.get(public_gallery, timeout=10)
        document = BeautifulSoup(public.text, 'html.parser')
        photos = document.select('[data-gallery-grid] article.gallery-photo')
        check(public.status_code == 200 and len(photos) == 2, 'Published gallery has the wrong public photo count.')
        check(photos[0].get('data-caption') == CAPTION and not photos[0].select('em')
              and photos[0].has_attr('data-lightbox-item') and not photos[1].has_attr('data-lightbox-item')
              and not photos[1].has_attr('data-src'), 'Public caption escaping or paid preview access is incorrect.')
        check(anonymous.get(photos[0]['data-src'], timeout=10).status_code == 200, 'Saved first preview cannot load through real signed media.')
        check(anonymous.get(route(base, 'media.php') + '&id=' + assets['a'], timeout=10).status_code in (403, 404),
              'A direct unsigned URL exposed the locked gallery photo.')

        bad_edits = [stale]
        for changes in ({'asset_order': json.dumps([assets['a'], assets['b']])}, {'asset_order': json.dumps([assets['a'], assets['a']])},
                        {'asset_captions[' + assets['b'] + ']': 'foreign'}, {'cover_asset_id': assets['cover']},
                        {'media_revision': ''}, {'scheduled_at': '2031-02-30T12:00'},
                        {'plan_ids[]': ['999999999'], 'title': 'Must not commit invalid plans'}):
            fields = gallery_fields(owner, gallery_url); fields.update(changes); bad_edits.append(fields)
        missing = gallery_fields(owner, gallery_url); del missing['media_revision']; bad_edits.append(missing)
        for index, fields in enumerate(bad_edits):
            before = snapshot(site)
            result = owner.post(gallery_url, data=fields, timeout=10)
            check(result.status_code == 200 and not result.history
                  and BeautifulSoup(result.text, 'html.parser').select('.admin-alert.error'), 'Invalid/stale gallery form was accepted: ' + str(index))
            check(snapshot(site) == before, 'Rejected gallery form changed persisted records or shared tags.')

        fields = playlist_fields(owner, playlist_url)
        fields.update({'track_ids[]': [tracks['second'], tracks['first']], 'status': 'scheduled',
                       'published_at': '', 'scheduled_at': '2031-06-15T12:00'})
        submit(owner, playlist_url, fields)
        saved = fixture(site, 'read')
        check(saved['playlist']['trackIds'] == [tracks['second'], tracks['first']]
              and saved['playlist']['scheduledAt'] == '2031-06-15 12:00:00' and saved['playlist']['publishedAt'] is None,
              'HTTP playlist order/schedule did not persist.')
        stale_playlist = playlist_fields(owner, playlist_url)
        for changes in ({'scheduled_at': ''}, {'scheduled_at': 'tomorrow'}, {'published_at': '2031-02-30T12:00'},
                        {'track_ids[]': [tracks['first'], '999999999']}, {'track_ids[]': [tracks['first'], tracks['first']]}, {'media_revision': ''}):
            fields = playlist_fields(owner, playlist_url); fields.update(changes)
            before = snapshot(site)
            result = owner.post(playlist_url, data=fields, timeout=10)
            check(not result.history and BeautifulSoup(result.text, 'html.parser').select('.admin-alert.error'), 'Invalid playlist edit was accepted.')
            check(snapshot(site) == before, 'Rejected playlist edit changed stored records.')

        fields = gallery_fields(owner, gallery_url)
        fields.update({'status': 'scheduled', 'published_at': '', 'scheduled_at': '2031-06-15T12:00'})
        submit(owner, gallery_url, fields)
        due_fields = form_values(owner.get(gallery_url, timeout=10).text, 'run_due_media')
        before = snapshot(site)
        check(anonymous.get(public_gallery, timeout=10).status_code == 404, 'A not-due gallery is visible.')
        check(playlist_card(anonymous.get(public_music, timeout=10).text, saved['playlist']['title']) is None, 'A not-due playlist is public.')
        owner.get(gallery_url + '&action=run_due_media', timeout=10)
        check(snapshot(site) == before, 'A public/admin GET performed scheduled publication.')
        response = submit(owner, gallery_url, due_fields)
        check('0 due media item(s) published.' in response.text and snapshot(site) == before, 'Owner action published future media.')

        member_session = fixture(site, 'member-session')
        member.cookies.set(member_session['name'], member_session['id'], domain='127.0.0.1', path='/')
        for client, label in ((anonymous, 'anonymous'), (member, 'member')):
            for url in (gallery_url, playlist_url):
                response = client.get(url, allow_redirects=False, timeout=10)
                check(response.status_code in (302, 303) and 'page=login' in response.headers.get('Location', ''), label + ' accessed owner media forms.')
            response = client.post(gallery_url, data=due_fields, allow_redirects=False, timeout=10)
            check(response.status_code in (302, 303), label + ' executed owner publication.')
        for token in ('', 'invalid-token'):
            response = owner.post(gallery_url, data={**due_fields, '_csrf': token}, timeout=10)
            check(response.status_code == 419, 'Missing/invalid CSRF did not reject scheduled publication.')
        response = owner.put(gallery_url, data=due_fields, timeout=10)
        check(response.status_code == 405 and 'POST' in response.headers.get('Allow', ''), 'Unsupported method reached media actions.')
        gallery_fields_saved = gallery_fields(owner, gallery_url)
        for status in ('demoted', 'inactive'):
            fixture(site, 'owner-status', status)
            for method, target, values in (('GET', gallery_url, {}), ('POST', base + '/admin/?page=dashboard', due_fields),
                                           ('POST', gallery_url, gallery_fields_saved)):
                denied = owner.request(method, target, data=values, allow_redirects=False, timeout=10)
                check(denied.status_code == (403 if status == 'demoted' else 302)
                      and (status == 'demoted' or denied.headers.get('Location') == base + '/admin/?page=login'),
                      'A revoked owner retained media authority through ' + method + ' ' + target)
            fixture(site, 'owner-status', 'owner')
        check(snapshot(site) == before, 'An authorization, CSRF or method rejection changed media.')
        revoked = owner.get(gallery_url, allow_redirects=False, timeout=10)
        check(revoked.status_code == 302 and revoked.headers.get('Location') == base + '/admin/?page=login',
              'Restoring the inactive account revived its media session.')
        login(base, owner)

        # Past schedules still remain hidden until a deliberate owner run.
        for url, getter in ((gallery_url, gallery_fields), (playlist_url, playlist_fields)):
            fields = getter(owner, url)
            fields.update({'scheduled_at': '2001-06-15T12:00', 'published_at': ''})
            submit(owner, url, fields)
        before_due = snapshot(site)
        check(anonymous.get(public_gallery, timeout=10).status_code == 404, 'A due gallery published on a public read.')
        anonymous.get(public_music, timeout=10)
        check(snapshot(site) == before_due, 'Public GET changed due records.')
        due_fields = form_values(owner.get(gallery_url, timeout=10).text, 'run_due_media')
        due_fields.update({'return_page': '//foreign.example.test', 'limit': '501'})
        result = submit(owner, gallery_url, due_fields)
        check(result.url == base + '/admin/?page=music' and '2 due media item(s) published.' in result.text,
              'Owner due run miscounted or accepted an external return destination.')
        after = fixture(site, 'read')
        check(after['gallery']['status'] == after['playlist']['status'] == 'published'
              and after['gallery']['scheduledAt'] == after['playlist']['scheduledAt'] == '2001-06-15 12:00:00'
              and after['gallery']['publishedAt'] == after['playlist']['publishedAt'] == '2001-06-15 12:00:00',
              'Due publication lost dates/status after separate storage reload.')
        check(after['plan_ids'] == [str(initial['seed']['plan']['id'])], 'Scheduling/publication cleared the gallery membership-plan restriction.')
        snapshot_after_publish = snapshot(site)
        result = submit(owner, gallery_url, due_fields)
        check('0 due media item(s) published.' in result.text and snapshot(site) == snapshot_after_publish,
              'Repeated scheduled publication is not idempotent.')
        guest_card = playlist_card(anonymous.get(public_music, timeout=10).text, after['playlist']['title'])
        member_card = playlist_card(member.get(public_music, timeout=10).text, after['playlist']['title'])
        check(guest_card is not None and not guest_card.select('[data-play-playlist]'), 'Registered playlist leaked a guest queue after publication.')
        check(member_card is not None and bool(member_card.select('[data-play-playlist]')), 'Verified member cannot access the registered playlist.')
        before = snapshot(site)
        result = owner.post(playlist_url, data=stale_playlist, timeout=10)
        check(not result.history and BeautifulSoup(result.text, 'html.parser').select('.admin-alert.error')
              and snapshot(site) == before, 'A pre-publication owner form reverted the scheduler.')
        fields = gallery_fields(owner, gallery_url)
        fields['asset_captions[' + assets['c'] + ']'] = ''
        fields['asset_tags[' + assets['c'] + ']'] = ''
        submit(owner, gallery_url, fields)
        cleared = fixture(site, 'read')
        check(cleared['gallery']['items'][0]['caption'] == '' and cleared['gallery']['items'][0]['asset']['tags'] == []
              and cleared['gallery']['items'][1]['caption'] == 'Original A' and cleared['unrelated'] == {'preserve': True},
              'Explicit clearing did not preserve sibling metadata and unrelated settings.')
    finally:
        fixture(site, 'owner-status', 'owner')
        owner.close(); anonymous.close(); member.close()
    print(f'Media Center HTTP acceptance passed ({checks} assertions; persistence, stale/invalid forms, owner boundaries, publication and access).')


if __name__ == '__main__':
    run()
