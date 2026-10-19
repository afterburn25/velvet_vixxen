"""Independent desktop/mobile audit of shared public navigation and page layout."""
from __future__ import annotations
import json
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = os.environ['VELVET_BASE_URL'].rstrip('/')
ROOT = Path(os.environ['VELVET_TEST_RUNTIME'])
assert (ROOT / '.vvx-test-runtime').is_file(), 'Disposable runtime marker is required.'
OUT = Path(os.environ.get('VELVET_SCREENSHOT_DIR', '/tmp/vvx-public-layout'))
OUT.mkdir(parents=True, exist_ok=True)
failures = []
results = []
paths = [('home', '/'), ('about', '/about/'), ('music', '/music/'), ('photos', '/photos/'), ('videos', '/videos/'),
    ('membership', '/membership/'), ('store', '/shop/'), ('collections', '/collections/'), ('booking', '/booking/'),
    ('login', '/account/login.php'), ('register', '/account/register.php'), ('cart', '/cart/')]

def visit(page, path):
    response = page.goto(BASE + path, wait_until='domcontentloaded')
    if page.locator('#ageGate:not([hidden])').count():
        page.locator('#ageEnter').click()
    return response

with sync_playwright() as playwright:
    options = {'headless': True, 'args': ['--no-sandbox', '--disable-dev-shm-usage']}
    if os.getenv('VELVET_CHROMIUM'):
        options['executable_path'] = os.environ['VELVET_CHROMIUM']
    browser = playwright.chromium.launch(**options)
    for label, viewport in [('desktop', {'width': 1440, 'height': 1000}), ('mobile', {'width': 390, 'height': 844})]:
        context = browser.new_context(viewport=viewport)
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        for key, path in paths:
            response = visit(page, path)
            if response is None or response.status != 200:
                failures.append(label + ' ' + path + ': HTTP ' + str(response.status if response else None))
                continue
            report = page.evaluate('''() => ({
                overflow: document.documentElement.scrollWidth > innerWidth + 1,
                navigation: document.querySelectorAll('nav[aria-label="Primary navigation"]').length,
                footer: document.querySelectorAll('footer').length,
                brokenImages: [...document.images].filter(image => image.complete && image.naturalWidth === 0 && image.currentSrc).map(image => image.currentSrc),
                headerControlsOutside: [...document.querySelectorAll('.public-navigation a,.public-navigation button')]
                    .filter(element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden')
                    .filter(element => {const box = element.getBoundingClientRect(); return box.left < -1 || box.right > innerWidth + 1;})
                    .map(element => element.textContent.trim()),
                headings: [...document.querySelectorAll('h1')].filter(element => element.getClientRects().length).map(element => element.textContent.trim())
            })''')
            report.update({'viewport': label, 'path': path})
            results.append(report)
            for check in ['overflow', 'brokenImages', 'headerControlsOutside']:
                if report[check]:
                    failures.append(label + ' ' + path + ': ' + check + ' ' + str(report[check]))
            if report['navigation'] != 1 or report['footer'] != 1:
                failures.append(label + ' ' + path + ': shared navigation/footer missing or duplicated')
            if key in ('home', 'about', 'login', 'register', 'booking', 'store'):
                page.screenshot(path=str(OUT / (key + '-' + label + '.png')), full_page=True)
            if label == 'mobile':
                menu = page.locator('#mobileMenu')
                menu.click()
                if menu.get_attribute('aria-expanded') != 'true' or not page.locator('#primaryNav').is_visible():
                    failures.append(label + ' ' + path + ': mobile menu did not open accessibly')
                # An account entry must remain reachable on small screens.
                if not any(link.is_visible() for link in page.locator('.public-navigation a[href*="account/login"]').all()):
                    failures.append(label + ' ' + path + ': no reachable Login link')
                menu.click()
                if menu.get_attribute('aria-expanded') != 'false':
                    failures.append(label + ' ' + path + ': mobile menu did not close')
        if errors:
            failures.extend(label + ': JavaScript ' + error for error in errors)
        # Verify a real navigation link lands on About with its active-page annotation.
        visit(page, '/')
        if label == 'mobile':
            page.locator('#mobileMenu').click()
        page.locator('#primaryNav').get_by_role('link', name='About Me', exact=True).click()
        page.wait_for_url('**/about/')
        if page.locator('#primaryNav').get_by_role('link', name='About Me', exact=True, include_hidden=True).get_attribute('aria-current') != 'page':
            failures.append(label + ': About navigation failed to mark the current page')
        # Signed-in account access must also stay reachable on both layouts.
        visit(page, '/account/login.php')
        page.locator('[name="email"]').fill('member@example.com')
        page.locator('[name="password"]').fill('VelvetMember12')
        page.get_by_role('button', name='Sign In', exact=True).click()
        page.wait_for_url('**/account/')
        if label == 'mobile':
            page.locator('#mobileMenu').click()
        if not any(link.is_visible() for link in page.locator('.public-navigation a[href$="account/"]').all()):
            failures.append(label + ': no reachable My Account link for a signed-in member')
        context.close()
    browser.close()
(OUT / 'public-layout-audit.json').write_text(json.dumps({'pages': results, 'failures': failures}, indent=2))
assert not failures, '\n'.join(failures)
print('Public layout audit passed: 12 routes at desktop/mobile, navigation, images, viewport fit and guest/member account access.')
