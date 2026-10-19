"""Owner/CSRF/route boundaries for installed-site database updates in a disposable runtime."""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
from urllib.parse import urljoin
import requests
from bs4 import BeautifulSoup

BASE = os.environ['VELVET_BASE_URL'].rstrip('/') + '/'
ROOT = Path(os.environ['VELVET_TEST_RUNTIME'])
assert (ROOT / '.vvx-test-runtime').is_file(), 'Disposable runtime marker is required.'

def get(session, path):
    result = session.get(urljoin(BASE, path), timeout=20)
    assert result.status_code == 200, (path, result.status_code)
    return result

def csrf(response):
    field = BeautifulSoup(response.text, 'html.parser').select_one('input[name="_csrf"]')
    assert field is not None, 'Missing session token in ' + response.url
    return field['value']

def login(email):
    session = requests.Session()
    response = get(session, 'admin/?page=login')
    logged = session.post(urljoin(BASE, 'admin/?page=login'), data={'_csrf': csrf(response), 'email': email, 'password': 'VelvetDemo12'}, timeout=20)
    assert logged.status_code == 200 and 'Welcome back.' not in logged.text, 'Admin login failed.'
    return session

config_before = (ROOT / 'config/app.php').read_bytes()
lock_before = (ROOT / 'storage/install.lock').read_bytes()
guest = requests.Session()
assert guest.get(urljoin(BASE, 'admin/?page=database-updates'), allow_redirects=False, timeout=20).status_code in (302, 303, 403), 'Anonymous user read database-update status.'
assert guest.post(urljoin(BASE, 'admin/?page=database-updates'), data={'action': 'apply_database_updates'}, allow_redirects=False, timeout=20).status_code in (302, 303, 403), 'Anonymous user triggered database updates.'
for path in ['app/Console/migrate.php', 'app/Support/database_updates_action.php', 'storage/private/database-updates/last-run.json']:
    assert guest.get(urljoin(BASE, path), timeout=20).status_code in (403, 404), 'Private database-update implementation or state was publicly accessible.'
owner = login('admin@example.com')
status = get(owner, 'admin/?page=database-updates')
assert 'No SQL update is needed' in status.text, 'File-storage owner status did not explain the harmless no-op.'
assert BeautifulSoup(status.text, 'html.parser').select_one('input[name="action"][value="apply_database_updates"]') is None, 'File-storage page displayed a SQL apply action.'
missing_token = owner.post(urljoin(BASE, 'admin/?page=database-updates'), data={'action': 'apply_database_updates', 'backup_confirmed': '1', 'backup_reference': 'Fixture'}, timeout=20)
assert 'session token expired' in missing_token.text, 'Database update bypassed CSRF.'
owner_settings = get(owner, 'admin/?page=settings')
no_op = owner.post(urljoin(BASE, 'admin/?page=database-updates'), data={'_csrf': csrf(owner_settings), 'action': 'apply_database_updates'}, timeout=20)
assert 'does not need SQL migrations' in no_op.text, 'File-storage update action did not safely remain a no-op.'

fixture = json.loads((ROOT / 'storage/data.json').read_text())
fixture['admins']['update-editor'] = {**fixture['admins']['demo-owner'], 'id': 'update-editor', 'email': 'update-editor@example.test', 'role': 'editor'}
(ROOT / 'storage/data.json').write_text(json.dumps(fixture))
editor = login('update-editor@example.test')
editor_status = get(editor, 'admin/?page=database-updates')
assert 'Owner access required' in editor_status.text and 'No SQL update is needed' not in editor_status.text, 'Non-owner obtained update inspection details.'
editor_settings = get(editor, 'admin/?page=settings')
denied = editor.post(urljoin(BASE, 'admin/?page=database-updates'), data={'_csrf': csrf(editor_settings), 'action': 'apply_database_updates',
    'backup_confirmed': '1', 'backup_reference': 'Forged operator acknowledgment'}, timeout=20)
assert 'Only the owner can apply database updates' in denied.text, 'Authenticated non-owner triggered database updates.'
assert not (ROOT / 'storage/private/database-updates').exists(), 'Rejected/no-op actions created migration state.'
assert (ROOT / 'config/app.php').read_bytes() == config_before and (ROOT / 'storage/install.lock').read_bytes() == lock_before, 'Update endpoint changed active configuration or installation lock.'
print('Database update HTTP boundaries passed: owner-only status/apply, CSRF, private paths and file-storage no-op preserve installation state.')
