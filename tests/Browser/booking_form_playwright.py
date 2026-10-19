"""Real owner editing and responsive, accessible public booking form interactions."""
from __future__ import annotations
import json
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = os.environ['VELVET_BASE_URL'].rstrip('/')
ROOT = Path(os.environ['VELVET_TEST_RUNTIME'])
assert (ROOT / '.vvx-test-runtime').is_file(), 'Disposable runtime marker is required.'
OUT = Path(os.environ.get('VELVET_SCREENSHOT_DIR', '/tmp/vvx-booking-browser'))
OUT.mkdir(parents=True, exist_ok=True)

def data():
    return json.loads((ROOT / 'storage/data.json').read_text())

def visit(page, path):
    response = page.goto(BASE + path, wait_until='domcontentloaded')
    assert response is not None and response.status == 200, path
    if page.locator('#ageGate:not([hidden])').count():
        page.locator('#ageEnter').click()

def add_field(page, label, kind, required=False, options='', minimum=0):
    visit(page, '/admin/?page=booking-form')
    form = page.locator('.booking-field-editor')
    form.locator('[name="field_label"]').fill(label)
    form.locator('[name="field_type"]').select_option(kind)
    form.locator('[name="field_help"]').fill('Please use accurate details for the private review.')
    if kind in ('select', 'radio', 'checkbox'):
        assert form.locator('[data-booking-choice-settings]').is_visible()
        form.locator('[name="field_options"]').fill(options)
    else:
        assert not form.locator('[data-booking-choice-settings]').is_visible()
    if kind == 'checkbox':
        form.locator('[name="field_minimum"]').fill(str(minimum))
    form.locator('[name="field_required"]').set_checked(required)
    form.get_by_role('button', name='Add Field', exact=True).click()
    page.get_by_text('Booking form updated. Earlier inquiries were preserved.', exact=True).wait_for()
    return next(field for field in data()['settings']['bookingForm']['fields'] if field['label'] == label)

def custom(page, field):
    return page.locator('[name="booking_fields[' + field['id'] + ']"]')

with sync_playwright() as playwright:
    launch = {'headless': True, 'args': ['--no-sandbox', '--disable-dev-shm-usage']}
    if os.getenv('VELVET_CHROMIUM'):
        launch['executable_path'] = os.environ['VELVET_CHROMIUM']
    browser = playwright.chromium.launch(**launch)
    owner_context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    owner = owner_context.new_page()
    errors = []
    owner.on('pageerror', lambda error: errors.append(str(error)))
    visit(owner, '/admin/?page=login')
    owner.locator('[name="email"]').fill('admin@example.com')
    owner.locator('[name="password"]').fill('VelvetDemo12')
    owner.get_by_role('button', name='Sign in', exact=True).click()
    owner.wait_for_url('**/admin/')
    short = add_field(owner, 'Organization', 'text')
    paragraph = add_field(owner, 'Additional event context', 'textarea')
    email = add_field(owner, 'Assistant email', 'email')
    phone = add_field(owner, 'Assistant phone', 'phone')
    select = add_field(owner, 'Travel arrangement', 'select', False, 'Local event\nTravel provided')
    radio = add_field(owner, 'Preferred session', 'radio', True, 'Morning\nAfternoon\nEvening')
    checkbox = add_field(owner, 'Event requirements', 'checkbox', True, 'Interview\nPhotographs\nAppearance', 2)
    row = owner.locator('[data-booking-field-id="' + checkbox['id'] + '"]')
    row.get_by_role('button', name='Move Event requirements up', exact=True).click()
    owner.get_by_text('Booking form updated. Earlier inquiries were preserved.', exact=True).wait_for()
    assert data()['settings']['bookingForm']['fields'][-2]['id'] == checkbox['id'], 'Owner move did not persist.'
    row = owner.locator('[data-booking-field-id="' + short['id'] + '"]')
    row.get_by_role('button', name='Disable', exact=True).click()
    owner.get_by_text('Booking form updated. Earlier inquiries were preserved.', exact=True).wait_for()
    preview = owner_context.new_page()
    visit(preview, '/booking/')
    assert custom(preview, short).count() == 0, 'Disabled field remained public.'
    preview.close()
    row = owner.locator('[data-booking-field-id="' + short['id'] + '"]')
    row.get_by_role('button', name='Enable', exact=True).click()
    owner.get_by_text('Booking form updated. Earlier inquiries were preserved.', exact=True).wait_for()
    # Every default question can also be optional.
    visit(owner, '/admin/?page=booking-form&field=contact_name')
    owner.locator('.booking-field-editor [name="field_required"]').uncheck()
    owner.locator('.booking-field-editor').get_by_role('button', name='Save Field', exact=True).click()
    owner.get_by_text('Booking form updated. Earlier inquiries were preserved.', exact=True).wait_for()
    owner.screenshot(path=str(OUT / 'booking-builder-desktop.png'), full_page=True)

    records = []
    for label, viewport in [('desktop', {'width': 1440, 'height': 1000}), ('mobile', {'width': 390, 'height': 844})]:
        context = browser.new_context(viewport=viewport)
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        visit(page, '/booking/')
        assert not page.locator('[name="contact_name"]').get_attribute('required'), 'Default required/optional update was not rendered.'
        assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'Public form overflows the viewport.'
        heading_dimensions = page.locator('.communication-hero h1').evaluate('(heading) => ({scroll:heading.scrollWidth,width:heading.clientWidth,font:getComputedStyle(heading).fontSize})')
        assert heading_dimensions['scroll'] <= heading_dimensions['width'] + 1, 'Booking heading is clipped: ' + label + ' ' + str(heading_dimensions)
        page.locator('[name="inquiry_type"]').select_option('appearance')
        page.locator('[name="contact_name"]').fill('Browser Builder ' + label)
        page.locator('[name="contact_email"]').fill('incomplete@localhost')
        page.locator('[name="details"]').fill('Private browser booking fixture ' + label)
        page.locator('[name="phone"]').press_sequentially('337')
        assert page.locator('[name="phone"]').input_value() == '(337)', 'Phone did not format while typing the area code.'
        page.locator('[name="phone"]').press_sequentially('4508598')
        assert page.locator('[name="phone"]').input_value() == '(337) 450-8598', 'Live phone formatting did not produce the requested US format.'
        page.locator('[name="phone"]').press('Backspace')
        assert page.locator('[name="phone"]').input_value() == '(337) 450-859', 'Phone backspace did not edit the number.'
        page.locator('[name="phone"]').press_sequentially('8')
        page.locator('[name="phone"]').evaluate('(input) => input.setSelectionRange(5, 5)')
        page.locator('[name="phone"]').press('Backspace')
        assert page.locator('[name="phone"]').input_value() == '(334) 508-598', 'Backspace at a phone separator did not remove the preceding digit.'
        page.locator('[name="phone"]').fill('3374508598')
        custom(page, short).fill('Fixture Organization')
        custom(page, paragraph).fill('Private original answer ' + label)
        custom(page, email).fill('assistant+booking@example.test')
        custom(page, phone).press_sequentially('2255550199')
        assert custom(page, phone).input_value() == '(225) 555-0199', 'Custom phone field did not format.'
        custom(page, select).select_option(label='Travel provided')
        radios = custom(page, radio)
        radios.nth(0).check()
        radios.nth(1).check()
        assert sum(radios.nth(i).is_checked() for i in range(radios.count())) == 1, 'Radio buttons were not linked as one group.'
        checkboxes = page.locator('[name="booking_fields[' + checkbox['id'] + '][]"]')
        checkboxes.nth(0).check()
        page.locator('[name="consent"]').check()
        page.get_by_role('button', name='Submit Private Inquiry', exact=True).click()
        page.locator('#booking-contact_email-error').get_by_text('Enter a complete email address, such as name@example.com.', exact=True).wait_for()
        page.locator('#booking-' + checkbox['id'] + '-error').get_by_text('Choose at least 2 options.', exact=True).wait_for()
        assert page.locator('[name="contact_email"]').get_attribute('aria-invalid') == 'true', 'Email error was not accessible.'
        page.screenshot(path=str(OUT / ('booking-validation-' + label + '.png')), full_page=True)
        page.locator('[name="contact_email"]').fill('builder-' + label + '@example.test')
        page.locator('[name="phone"]').fill('1111111111')
        checkboxes.nth(1).check()
        page.get_by_role('button', name='Submit Private Inquiry', exact=True).click()
        page.locator('#booking-phone-error').get_by_text('Enter a valid 10-digit US phone number, including the area code.', exact=True).wait_for()
        page.locator('[name="phone"]').fill('3374508598')
        page.get_by_role('button', name='Submit Private Inquiry', exact=True).click()
        page.get_by_text('Your private inquiry was submitted successfully.', exact=True).wait_for()
        record = next(row for row in data()['bookingRequests'].values() if row['contactEmail'] == 'builder-' + label + '@example.test')
        records.append(record)
        assert data()['bookingFormSubmissions'][record['id']]['values'][radio['id']] == radio['options'][1]['value'], 'Radio answer was not persisted correctly.'
        page.screenshot(path=str(OUT / ('booking-submitted-' + label + '.png')), full_page=True)
        context.close()

    # Editing and deleting fields cannot remove the question/answer snapshot for existing inquiries.
    visit(owner, '/admin/?page=booking-form&field=' + paragraph['id'])
    owner.locator('.booking-field-editor [name="field_label"]').fill('Renamed future event question')
    owner.locator('.booking-field-editor').get_by_role('button', name='Save Field', exact=True).click()
    owner.get_by_text('Booking form updated. Earlier inquiries were preserved.', exact=True).wait_for()
    owner.once('dialog', lambda dialog: dialog.accept())
    owner.locator('[data-booking-field-id="' + paragraph['id'] + '"]').get_by_role('button', name='Delete', exact=True).click()
    owner.get_by_text('Booking form updated. Earlier inquiries were preserved.', exact=True).wait_for()
    visit(owner, '/admin/?page=booking-requests&request=' + records[0]['id'])
    snapshot = owner.locator('.booking-submitted-answers')
    assert 'Additional event context' in snapshot.inner_text(), 'Old question label was lost after edits/deletion.'
    assert 'Private original answer desktop' in snapshot.inner_text(), 'Old answer was lost after edits/deletion.'
    assert 'Renamed future event question' not in snapshot.inner_text(), 'An old inquiry was changed retroactively.'
    owner.screenshot(path=str(OUT / 'booking-original-answers.png'), full_page=True)
    owner.set_viewport_size({'width': 390, 'height': 844})
    visit(owner, '/admin/?page=booking-form')
    assert owner.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'Owner builder overflows the mobile viewport.'
    assert owner.locator('.booking-editor-panel').bounding_box()['y'] < owner.locator('.booking-builder-list').bounding_box()['y'], 'Mobile owner must be able to reach the editor without scrolling through every field.'
    owner.screenshot(path=str(OUT / 'booking-builder-mobile.png'), full_page=True)
    assert not errors, errors
    owner_context.close()
    browser.close()
print('Booking builder browser passed: owner CRUD/order/toggles, linked choices, live phone formatting, inline validation and preserved answers on desktop/mobile.')
