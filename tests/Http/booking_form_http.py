"""Booking builder authorization, validation, snapshots and protected uploads over real HTTP."""
from __future__ import annotations
import copy
import json
import os
from pathlib import Path
from urllib.parse import urljoin
import requests
from bs4 import BeautifulSoup

BASE = os.environ['VELVET_BASE_URL'].rstrip('/') + '/'
ROOT = Path(os.environ['VELVET_TEST_RUNTIME'])
assert (ROOT / '.vvx-test-runtime').is_file(), 'Disposable runtime marker is required.'

def data():
    return json.loads((ROOT / 'storage/data.json').read_text())

def get(session, path):
    response = session.get(urljoin(BASE, path), timeout=20)
    assert response.status_code == 200, (path, response.status_code)
    return response

def hidden(response, name):
    node = BeautifulSoup(response.text, 'html.parser').select_one('input[name="' + name + '"]')
    assert node is not None, (name, response.url)
    return node.get('value', '')

def admin_login(email='admin@example.com'):
    session = requests.Session()
    page = get(session, 'admin/?page=login')
    response = session.post(urljoin(BASE, 'admin/?page=login'), data={'_csrf': hidden(page, '_csrf'), 'email': email, 'password': 'VelvetDemo12'}, timeout=20)
    assert response.status_code == 200 and 'Welcome back.' not in response.text, 'Admin login failed.'
    return session

def admin_action(session, action, extras=None, csrf=True):
    page = get(session, 'admin/?page=booking-form')
    payload = {'action': action, 'form_revision': hidden(page, 'form_revision') if 'form_revision' in page.text else data()['settings']['bookingForm']['revision']}
    if csrf:
        payload['_csrf'] = hidden(page, '_csrf')
    payload.update(extras or {})
    return session.post(urljoin(BASE, 'admin/?page=booking-form'), data=payload, timeout=20)

def add_field(label, kind, required=False, choices='', minimum=0):
    response = admin_action(owner, 'save_booking_form_field', {'field_id': '', 'field_label': label, 'field_type': kind,
        'field_options': choices, 'field_minimum': str(minimum), 'field_enabled': '1', **({'field_required': '1'} if required else {})})
    assert 'Booking form updated.' in response.text, response.text[-1200:]
    return next(field for field in data()['settings']['bookingForm']['fields'] if field['label'] == label)

guest = requests.Session()
unauthorized = guest.post(urljoin(BASE, 'admin/?page=booking-form'), data={'action': 'delete_booking_form_field', 'field_id': 'details'}, allow_redirects=False, timeout=20)
assert unauthorized.status_code in (302, 303, 403), 'Guest changed the form.'
owner = admin_login()
initial = copy.deepcopy(data().get('settings', {}).get('bookingForm'))
rejected = admin_action(owner, 'delete_booking_form_field', {'field_id': 'details'}, csrf=False)
assert 'session token expired' in rejected.text and data().get('settings', {}).get('bookingForm') == initial, 'Owner form update bypassed CSRF.'
radio = add_field('Preferred session', 'radio', True, 'Morning\nAfternoon\nEvening')
checkbox = add_field('Event requirements', 'checkbox', True, 'Interview\nPhotographs\nAppearance', 2)
unsafe_label = '<img src=x onerror=alert(1)> Owner note'
text_field = add_field(unsafe_label, 'textarea')
old_revision = data()['settings']['bookingForm']['revision']

# A non-owner admin has an authenticated session but cannot administer the builder.
fixture = data()
fixture['admins']['fixture-editor'] = {**fixture['admins']['demo-owner'], 'id': 'fixture-editor', 'email': 'editor@example.test', 'role': 'editor'}
(ROOT / 'storage/data.json').write_text(json.dumps(fixture))
editor = admin_login('editor@example.test')
editor_page = get(editor, 'admin/?page=booking-form')
assert 'booking-field-editor' not in editor_page.text and 'Owner access required' in editor_page.text, 'Non-owner saw builder controls.'
# Obtain the session token from another permitted admin form.
editor_settings = get(editor, 'admin/?page=settings')
editor_attempt = editor.post(urljoin(BASE, 'admin/?page=booking-form'), data={'_csrf': hidden(editor_settings, '_csrf'),
    'action': 'delete_booking_form_field', 'field_id': 'details', 'form_revision': old_revision}, timeout=20)
assert 'Only the owner' in editor_attempt.text and data()['settings']['bookingForm']['revision'] == old_revision, 'Non-owner altered definitions.'

booking = get(guest, 'booking/')
assert not BeautifulSoup(booking.text, 'html.parser').select('img[onerror]'), 'Owner-defined label executed as markup.'
assert unsafe_label in BeautifulSoup(booking.text, 'html.parser').get_text(), 'Escaped label was lost.'
payload = {'_csrf': hidden(booking, '_csrf'), 'form_revision': hidden(booking, 'form_revision'), 'inquiry_type': 'appearance',
    'contact_name': 'HTTP Builder Fixture', 'contact_email': 'fixture+booking@example.test', 'phone': '3374508598',
    'details': 'HTTP custom intake fixture', 'consent': '1', 'booking_fields[' + radio['id'] + ']': radio['options'][0]['value'],
    'booking_fields[' + checkbox['id'] + '][]': [choice['value'] for choice in checkbox['options'][:2]],
    'booking_fields[' + text_field['id'] + ']': '<svg onload=alert(1)>private answer</svg>'}

def booking_post(changes=None, remove=(), files=None):
    post = {**payload, **(changes or {})}
    for key in remove:
        post.pop(key, None)
    return guest.post(urljoin(BASE, 'booking/'), data=post, files=files, timeout=20)

before = len(data().get('bookingRequests', {}))
for changes, remove, expected in [
    ({'_csrf': 'invalid'}, (), 'form session expired'),
    ({'website': 'https://spam.example.test'}, (), 'could not be submitted'),
    ({}, ('consent',), 'Confirm that the inquiry details'),
    ({'contact_email': 'incomplete@localhost'}, (), 'complete email address'),
    ({'phone': '33745'}, (), 'valid 10-digit US phone'),
    ({'phone': '1111111111'}, (), 'valid 10-digit US phone'),
    ({'booking_fields[' + radio['id'] + '][]': [radio['options'][0]['value'], radio['options'][1]['value']]}, ('booking_fields[' + radio['id'] + ']',), 'one valid value'),
    ({'booking_fields[' + radio['id'] + ']': 'forged-choice'}, (), 'listed options'),
    ({'booking_fields[' + checkbox['id'] + '][]': [checkbox['options'][0]['value']]}, (), 'Choose at least 2 options'),
]:
    response = booking_post(changes, remove)
    assert expected in response.text, (expected, response.text[-500:])
    assert len(data().get('bookingRequests', {})) == before, 'Rejected submission left a booking record.'

uploads_before = list((ROOT / 'storage/private/communications').rglob('*')) if (ROOT / 'storage/private/communications').exists() else []
unsupported = booking_post(files=[('references[]', ('valid.txt', b'Good first reference', 'text/plain')),
    ('references[]', ('forbidden.php', b'<?php echo "unsafe";', 'application/x-php'))])
assert 'Unsupported attachment' in unsupported.text, 'Executable upload was accepted.'
assert list((ROOT / 'storage/private/communications').rglob('*')) == uploads_before if (ROOT / 'storage/private/communications').exists() else not uploads_before, 'Invalid batch left an untracked first upload.'
assert len(data().get('bookingRequests', {})) == before, 'Invalid upload created a booking.'

too_many = booking_post(files=[('references[]', (f'ref-{index}.txt', b'Fixture reference', 'text/plain')) for index in range(6)])
assert 'no more than 5' in too_many.text, 'Upload count limit failed.'
saved = booking_post(files={'references[]': ('reference.txt', b'Private booking reference fixture', 'text/plain')})
assert 'submitted successfully' in saved.text, saved.text[-1500:]
assert len(data()['bookingRequests']) == before + 1, 'Valid submission did not persist exactly once.'
record = next(row for row in data()['bookingRequests'].values() if row['contactEmail'] == 'fixture+booking@example.test')
snapshot = copy.deepcopy(data()['bookingFormSubmissions'][record['id']])
assert snapshot['values']['phone'] == '(337) 450-8598', 'Phone was not normalized server-side.'
attachment = next(row for row in data()['requestAttachments'].values() if row['requestId'] == record['id'])
assert attachment['filePath'].startswith('private:communications/'), 'Attachment was stored in public space.'
for path in ['communication/download.php?id=' + attachment['id'], 'storage/private/' + attachment['filePath'][8:]]:
    assert guest.get(urljoin(BASE, path), timeout=20).status_code in (403, 404), 'Guest could download a private attachment.'
assert owner.get(urljoin(BASE, 'communication/download.php?id=' + attachment['id']), timeout=20).content == b'Private booking reference fixture', 'Owner could not download protected reference.'

admin_action(owner, 'delete_booking_form_field', {'field_id': text_field['id']})
assert data()['bookingFormSubmissions'][record['id']] == snapshot, 'Field deletion rewrote old answers.'
detail = get(owner, 'admin/?page=booking-requests&request=' + record['id'])
parsed_detail = BeautifulSoup(detail.text, 'html.parser')
assert unsafe_label in parsed_detail.get_text() and '<svg onload=alert(1)>private answer</svg>' in parsed_detail.get_text(), 'Admin lost the original question or answer.'
assert not parsed_detail.select('img[onerror],svg[onload]'), 'Stored booking answers executed as markup.'
stale = booking_post()
assert 'booking form changed' in stale.text and len(data()['bookingRequests']) == before + 1, 'Stale public definition was silently accepted.'

# Attempts have one shared server-side budget even with new browser sessions.
for _ in range(21):
    limited = booking_post({'phone': '337'})
assert 'Too many booking attempts' in limited.text, 'Server-side booking rate limit failed.'
assert len(data()['bookingRequests']) == before + 1, 'Rate-limited attempts created inquiries.'
print('Booking builder HTTP passed: owner/CSRF boundaries, custom validation, snapshots, upload privacy and spam limits.')
