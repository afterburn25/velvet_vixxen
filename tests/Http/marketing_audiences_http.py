"""Actual owner audience forms; marked temporary localhost sites, mail disabled.

Import-safe so the packaged browser suite can run the same independent HTTP
acceptance on a separate extracted installation without any test web routes.
"""
from __future__ import annotations

import copy
import json
import os
from pathlib import Path
import subprocess
import tempfile
from urllib.parse import urlparse

from bs4 import BeautifulSoup
import requests

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = ROOT / 'tests/Http/marketing_audiences_fixture.php'
MEMBER_FIXTURE = ROOT / 'tests/Http/media_upload_fixture.php'


def fixture(php: str, site: Path, action: str, **values) -> dict:
    result = subprocess.run([php, str(FIXTURE), str(site), action], input=json.dumps(values),
                            text=True, capture_output=True, timeout=20, cwd=tempfile.gettempdir())
    if result.returncode != 0:
        raise AssertionError('Guarded audience fixture failed: ' + result.stderr[:600])
    return json.loads(result.stdout)


def state(site: Path) -> dict:
    return json.loads((site / 'storage/private/marketing/data.json').read_text())


def fields(html: str, selector: str) -> dict:
    form = BeautifulSoup(html, 'html.parser').select_one(selector)
    if form is None:
        raise AssertionError('Actual owner form is missing: ' + selector)
    result = {}
    for element in form.select('input[name], select[name], textarea[name]'):
        if element.get('type') in ('checkbox', 'radio') and not element.has_attr('checked'):
            continue
        if element.name == 'select':
            option = element.select_one('option[selected]') or element.select_one('option')
            value = option.get('value', '') if option else ''
        elif element.name == 'textarea':
            value = element.text
        else:
            value = element.get('value', '')
        result[element['name']] = value
    return result


def session():
    current = requests.Session()
    current.trust_env = False
    return current


def login(base: str):
    owner = session()
    response = owner.get(base + '/admin/?page=login', timeout=10)
    form = fields(response.text, 'form')
    result = owner.post(base + '/admin/?page=login', data={'_csrf': form['_csrf'], 'email': 'owner@example.test', 'password': 'VelvetDemo12'}, timeout=10)
    if result.status_code != 200 or 'page=login' in result.url:
        raise AssertionError('Real owner authentication failed.')
    return owner


def run_http(site: Path, base: str, php: str = 'php') -> dict:
    site = site.resolve()
    if not site.is_relative_to(Path(tempfile.gettempdir()).resolve()) or not (site / '.vvx-test-runtime').is_file() \
            or urlparse(base).hostname != '127.0.0.1' or urlparse(base).scheme != 'http':
        raise AssertionError('A marked, temporary localhost installation is required.')
    checks = 0

    def check(ok, message):
        nonlocal checks
        checks += 1
        if not ok:
            raise AssertionError(message)

    audience_url = base + '/admin/?page=marketing&tab=audiences'
    campaign_url = base + '/admin/?page=marketing&tab=campaigns'
    path = site / 'storage/private/marketing/data.json'
    preserved = {name: (site / name).read_bytes() for name in ('config/app.php', 'storage/install.lock')}
    seed = [('before', 'alpha', '2024-05-31T23:59:59Z'), ('start', 'alpha', '2024-06-01T00:00:00Z'),
            ('inside', 'alpha', '2024-06-15T12:00:00Z'), ('end', 'alpha', '2024-07-01T00:00:00Z'),
            ('beta', 'beta', '2024-06-15T12:00:00Z')]
    for label, source, when in seed:
        fixture(php, site, 'contact', email=label + '@example.test', source=source, confirmed_at=when)
    fixture(php, site, 'contact', email='pending@example.test', confirmed=False)
    fixture(php, site, 'contact', email='suppressed@example.test', suppressed=True)
    fixture(php, site, 'contact', email='revoked@example.test', revoked=True)
    owner = login(base)
    guest = session()
    try:
        page = owner.get(audience_url, timeout=10)
        check(page.status_code == 200 and 'data-audience-editor' in page.text, 'Owner cannot open the actual audience editor.')
        audience = fields(page.text, 'form[data-audience-editor]')
        audience.update(name='June alpha <owner>', sources='alpha', enabled='1', confirmed_after='2024-06-01T00:00', confirmed_before='2024-07-01T00:00')
        saved = path.read_bytes()
        denied = guest.post(audience_url, data=audience, allow_redirects=False, timeout=10)
        check(denied.status_code in (302, 303, 403) and path.read_bytes() == saved, 'Guest changed audiences.')
        for token in ('', 'wrong-fixture-token'):
            denied = owner.post(audience_url, data={**audience, '_csrf': token}, timeout=10)
            check('session token expired' in denied.text and path.read_bytes() == saved, 'Missing/wrong CSRF changed audiences.')
        response = owner.get(audience_url, params={'action': 'save_segment', 'name': 'GET must not save'}, timeout=10)
        check(response.status_code == 200 and path.read_bytes() == saved, 'GET action changed audiences.')
        preview = owner.post(audience_url, data={**audience, 'preview_segment': '1'}, timeout=10)
        check('2 eligible newsletter contacts' in preview.text and path.read_bytes() == saved, 'Source/date preview did not match exact confirmed eligibility or wrote data.')
        audience = fields(preview.text, 'form[data-audience-editor]')
        saved_response = owner.post(audience_url, data=audience, timeout=10)
        check(saved_response.status_code == 200 and bool(saved_response.history) and saved_response.request.method == 'GET', 'Audience save did not redirect to GET.')
        check(len(state(site)['segments']) == 1, 'Owner audience save did not persist one segment.')
        segment = copy.deepcopy(state(site)['segments'][0])
        editing = fields(saved_response.text, 'form[data-audience-editor]')
        original_revision = editing['segment_revision']
        check(editing['name'] == 'June alpha <owner>' and editing['sources'] == 'alpha', 'Saved audience did not reopen with its exact values.')
        option = segment['id'] + ':' + original_revision

        page = owner.get(campaign_url, timeout=10)
        campaign = fields(page.text, 'form[data-campaign-editor]')
        campaign.update(subject='é' * 255, preheader='é' * 500, body='Actual HTTP audience fixture.\nMail is disabled.', audience_selection=option,
                        scheduled_at='2030-01-15T18:30', queue='1')
        saved = path.read_bytes()
        preview = owner.post(campaign_url, data={**campaign, 'preview_audience': '1'}, timeout=10)
        check('2 eligible newsletter contacts' in preview.text and path.read_bytes() == saved, 'Campaign preview saved a draft or selected the wrong contacts.')
        campaign = fields(preview.text, 'form[data-campaign-editor]')
        check(len(campaign['audience_preview_revision']) == 64, 'Campaign form omitted the exact preview revision.')
        fixture(php, site, 'contact', email='late@example.test', source='alpha')
        stale = owner.post(campaign_url, data=campaign, timeout=10)
        check('draft was saved, but no new recipients were queued' in stale.text and 'Refresh the preview' in stale.text, 'Stale preview did not explain the saved draft and held queue.')
        check(len(state(site)['campaigns']) == 1 and not state(site)['campaign_queue'], 'Stale preview partially queued work or duplicated a draft.')
        draft = copy.deepcopy(state(site)['campaigns'][0])
        check('audience_snapshot' not in draft and draft['subject'] == 'é' * 255 and draft['preheader'] == 'é' * 500,
              'Stale preview froze the draft or cut valid UTF-8 subject/preheader text.')
        retry = fields(stale.text, 'form[data-campaign-editor]')
        check(retry['id'] == draft['id'] and retry['audience_selection'] == option, 'Stale preview lost the saved draft ID or selected audience.')
        missing = {key: value for key, value in retry.items() if key != 'audience_selection'}
        saved = path.read_bytes()
        rejected = owner.post(campaign_url, data=missing, timeout=10)
        check('Choose the campaign audience again' in rejected.text and path.read_bytes() == saved, 'An old edit form without audience reset the segmented draft to all contacts.')
        refreshed = owner.post(campaign_url, data={**retry, 'preview_audience': '1'}, timeout=10)
        check('3 eligible newsletter contacts' in refreshed.text, 'Preview retry did not reflect current eligible contacts.')
        queued_response = owner.post(campaign_url, data=fields(refreshed.text, 'form[data-campaign-editor]'), timeout=10)
        check(queued_response.status_code == 200 and bool(queued_response.history) and queued_response.request.method == 'GET', 'Queued campaign did not redirect to GET.')
        current = state(site)
        check(len(current['campaigns']) == 1 and len(current['campaign_queue']) == 3, 'Preview retry duplicated the draft or queued the wrong count.')
        check(sorted(row['email'] for row in current['campaign_queue']) == ['inside@example.test', 'late@example.test', 'start@example.test'], 'Queue did not match the refreshed source/date preview.')
        queued = copy.deepcopy(current['campaigns'][0])
        existing = copy.deepcopy(current['campaign_queue'])
        check(queued['audience_snapshot']['rules'] == segment['rules'] and queued['audience_snapshot']['segment_revision'] == original_revision,
              'Queue did not freeze the displayed audience rules and revision.')

        editing.update(name='Beta next', sources='beta', enabled='1')
        changed = owner.post(audience_url, data=editing, timeout=10)
        check('Audience saved.' in changed.text, 'Owner audience edit failed.')
        saved = path.read_bytes()
        rejected = owner.post(audience_url, data=editing, timeout=10)
        check('This audience changed' in rejected.text and path.read_bytes() == saved, 'A stale owner audience edit overwrote fresh rules.')
        latest = fields(changed.text, 'form[data-audience-editor]')
        removed = owner.post(audience_url, data={'_csrf': latest['_csrf'], 'action': 'delete_segment', 'id': segment['id'], 'segment_revision': latest['segment_revision']}, timeout=10)
        check('Audience removed.' in removed.text and state(site)['segments'] == [], 'Owner audience removal failed.')
        check(state(site)['campaigns'][0]['audience_snapshot'] == queued['audience_snapshot'] and state(site)['campaign_queue'] == existing,
              'Editing/removing the live audience changed frozen campaign work.')
        fixture(php, site, 'contact', email='appended-alpha@example.test', source='alpha')
        fixture(php, site, 'contact', email='appended-beta@example.test', source='beta')
        check(state(site)['campaign_queue'] == existing, 'A later contact was appended without explicit queueing.')
        page = owner.get(campaign_url, timeout=10)
        check('Audience: June alpha &lt;owner&gt;' in page.text, 'Queued owner display lost the saved audience label after deletion.')
        queue_form = fields(page.text, f'[data-campaign-id="{queued["id"]}"] form')
        preview = owner.post(campaign_url, data={**queue_form, 'action': 'preview_campaign_queue'}, timeout=10)
        check('1 new eligible recipients can be added' in preview.text, 'Requeue preview did not use the deleted audience’s frozen rules.')
        frozen_preview = fields(preview.text, f'[data-campaign-id="{queued["id"]}"] form')
        fixture(php, site, 'contact', email='second-alpha@example.test', source='alpha')
        saved = path.read_bytes()
        stale = owner.post(campaign_url, data={**frozen_preview, 'action': 'queue_campaign'}, timeout=10)
        check('Refresh the preview' in stale.text and path.read_bytes() == saved, 'Stale explicit requeue mutated the queue.')
        preview = owner.post(campaign_url, data={**queue_form, 'action': 'preview_campaign_queue'}, timeout=10)
        refreshed = fields(preview.text, f'[data-campaign-id="{queued["id"]}"] form')
        response = owner.post(campaign_url, data={**refreshed, 'action': 'queue_campaign'}, timeout=10)
        check('2 new eligible recipients queued' in response.text and len(state(site)['campaign_queue']) == 5, 'Explicit requeue did not append exact new frozen-rule matches.')
        check(state(site)['campaigns'][0]['audience_snapshot'] == queued['audience_snapshot']
              and state(site)['campaign_queue'][:len(existing)] == existing, 'Explicit requeue rewrote frozen rules or previous delivery rows.')
        check('appended-beta@example.test' not in [row['email'] for row in state(site)['campaign_queue']], 'Edited live rules broadened a queued audience.')
        saved = path.read_bytes()
        response = owner.post(campaign_url, data={**queue_form, 'action': 'queue_campaign'}, timeout=10)
        check('0 new eligible recipients queued' in response.text and path.read_bytes() == saved, 'Repeated queueing changed stored work.')

        # Invalid form arrays, control characters and impossible dates preserve
        # the complete current marketing file rather than partially saving.
        page = owner.get(audience_url, timeout=10)
        blank = fields(page.text, 'form[data-audience-editor]')
        for changes in ({'name': 'Invalid', 'sources': 'alpha', 'confirmed_after': '2024-02-30T10:00'},
                        {'name': 'Invalid', 'sources': 'alpha\t'},
                        {'name': 'Invalid', 'sources': 'alpha', 'enabled[]': '1'}):
            data = {**blank, **changes}
            if 'enabled[]' in data:
                data.pop('enabled', None)
            saved = path.read_bytes()
            rejected = owner.post(audience_url, data=data, timeout=10)
            check('admin-alert error' in rejected.text and path.read_bytes() == saved, 'Invalid audience form partially changed storage.')
        member_result = subprocess.run([php, str(MEMBER_FIXTURE), str(site), 'member-session'], text=True, capture_output=True, timeout=20)
        check(member_result.returncode == 0, 'Real member-only fixture login failed.')
        member_data = json.loads(member_result.stdout)
        member = session()
        member.cookies.set(member_data['name'], member_data['id'], domain='127.0.0.1', path='/')
        saved = path.read_bytes()
        denied = member.post(audience_url, data={**blank, '_csrf': member_data['csrf'], 'name': 'Member denied', 'sources': 'alpha'}, allow_redirects=False, timeout=10)
        check(denied.status_code in (302, 303, 403) and path.read_bytes() == saved, 'A member-only session saved an owner audience.')
        member.close()
        for role, active in [('editor', True), ('owner', False)]:
            fixture(php, site, 'owner', role=role, active=active)
            saved = path.read_bytes()
            denied = owner.post(audience_url, data={**blank, 'name': 'Fresh role denied', 'sources': 'alpha'}, allow_redirects=False, timeout=10)
            check(denied.status_code in (302, 303, 403) and path.read_bytes() == saved, 'Stale owner session bypassed current role/active authorization.')
            fixture(php, site, 'owner', role='owner', active=True)
        owner.close()
        owner = login(base)
        page = owner.get(campaign_url, timeout=10)
        batch = fields(page.text, 'form:has(input[name="action"][value="run_campaign_batch"])')
        saved = path.read_bytes()
        held = owner.post(campaign_url, data=batch, timeout=10)
        check('Email delivery is disabled' in held.text and path.read_bytes() == saved, 'Disabled transport changed the selected queue.')
        check(all(row['attempts'] == 0 and row['status'] == 'queued' for row in state(site)['campaign_queue']), 'Audience acceptance consumed a delivery attempt.')
        check(not json.loads((site / 'storage/data.json').read_text()).get('mailOutbox'), 'Audience operations created transactional messages.')
        check(all((site / name).read_bytes() == value for name, value in preserved.items()), 'Audience actions changed mail configuration or installation identity.')
        return {'assertions': checks, 'real_owner_http': True, 'source_date_preview_exact': True,
                'preview_staleness_preserves_queue': True, 'frozen_rules_after_edit_delete': True,
                'explicit_append_only_requeue': True, 'current_owner_and_csrf': True,
                'mail_mode': 'disabled', 'external_messages_sent': 0}
    finally:
        owner.close()
        guest.close()


if __name__ == '__main__':
    evidence = run_http(Path(os.environ['VELVET_TEST_RUNTIME']), os.environ['VELVET_BASE_URL'], os.environ.get('VELVET_PHP', 'php'))
    print('Newsletter audience HTTP acceptance passed: ' + json.dumps(evidence, sort_keys=True))
