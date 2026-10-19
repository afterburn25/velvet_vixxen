"""Owner About editing, persistent admin categories and standalone public layouts."""
from __future__ import annotations

import os
from public_urls import assert_route_destination, site_url
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import expect, sync_playwright

BASE = os.environ['VELVET_BASE_URL'].rstrip('/')
ROOT = Path(os.environ['VELVET_TEST_RUNTIME'])
assert (ROOT / '.vvx-test-runtime').is_file() and urlparse(BASE).hostname in {'127.0.0.1', 'localhost', '::1'}
OUT = Path(os.environ.get('VELVET_SCREENSHOT_DIR', '/tmp/vvx-site-ux'))
OUT.mkdir(parents=True, exist_ok=True)


def visit(page, path):
    response = page.goto(site_url(BASE, path), wait_until='networkidle')
    assert response and response.status == 200, (path, response.status if response else None)
    gate = page.locator('#ageGate:not([hidden])')
    if gate.count():
        page.locator('#ageEnter').click()
    return response


def activate_and_reload(page, changes):
    """Snapshot storage and refresh before a queued native toggle task can run."""
    with page.expect_navigation(wait_until='networkidle'):
        page.evaluate('''changes => {
          for (const key of Object.keys(changes)) {
            document.querySelector('[data-admin-group="' + key + '"] summary').click();
          }
          queueMicrotask(() => {
            const scope = document.querySelector('[data-admin-navigation]').dataset.storageKey;
            sessionStorage.setItem('vvx-test-admin-before-reload', localStorage.getItem(scope) || '{}');
            location.reload();
          });
        }''', changes)
    saved = page.evaluate("JSON.parse(sessionStorage.getItem('vvx-test-admin-before-reload'))")
    for group, opened in changes.items():
        assert saved.get(group) is opened, 'Category preference was not saved before the next browser task'
        assert page.locator('[data-admin-group="' + group + '"]').evaluate('(node) => node.open') is opened, ('Category state did not survive immediate refresh', group, opened)
    assert 'page=about' in page.url, 'Refreshing an admin page changed the current page'
    expect(page.locator('[data-admin-navigation] [aria-current="page"]')).to_have_text('♡About Me')


with sync_playwright() as p:
    options = {'headless': True, 'args': ['--no-sandbox', '--disable-dev-shm-usage']}
    if os.getenv('VELVET_CHROMIUM'):
        options['executable_path'] = os.environ['VELVET_CHROMIUM']
    browser = p.chromium.launch(**options)
    owner = browser.new_context(viewport={'width': 1440, 'height': 1000})
    page = owner.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    visit(page, '/admin/?page=login')
    page.locator('[name="email"]').fill('admin@example.com')
    page.locator('[name="password"]').fill('VelvetDemo12')
    page.get_by_role('button', name='Sign in', exact=True).click()
    visit(page, '/admin/?page=about')
    expect(page.locator('[data-admin-group="design"]')).to_have_attribute('open', '')
    expect(page.locator('[data-admin-navigation] [aria-current="page"]')).to_have_text('♡About Me')
    scope = page.locator('[data-admin-navigation]').get_attribute('data-storage-key')
    assert scope.startswith('velvet-admin-groups:')
    other_scope = scope + ':another-owner'
    page.evaluate("key => localStorage.setItem(key, '{\"content\":false}')", other_scope)
    activate_and_reload(page, {'content': True, 'marketing': True})
    assert page.locator('[data-admin-group="design"]').evaluate('(node) => node.open')
    assert not page.locator('[data-admin-group="extensions"]').evaluate('(node) => node.open')
    activate_and_reload(page, {'content': False, 'design': False})
    # An explicit collapse of the active category survives, without losing its
    # current-page marker. Native keyboard activation retains focus and persists.
    design_summary = page.locator('[data-admin-group="design"] summary')
    design_summary.focus()
    for key, opened in [('Enter', True), ('Space', False), ('Enter', True)]:
        design_summary.press(key)
        expect(design_summary).to_be_focused()
        assert page.locator('[data-admin-group="design"]').evaluate('(node) => node.open') is opened
        assert page.evaluate('key => JSON.parse(localStorage.getItem(key)).design', scope) is opened
    page.reload(wait_until='networkidle')
    assert page.locator('[data-admin-group="design"]').evaluate('(node) => node.open')
    assert page.evaluate('key => localStorage.getItem(key)', other_scope) == '{"content":false}', 'Category preferences escaped their owner/site scope'
    preferences = page.evaluate('key => localStorage.getItem(key)', scope)
    for malformed in ['[]', '"invalid preferences"', '{invalid JSON']:
        page.evaluate('([key, value]) => localStorage.setItem(key, value)', [scope, malformed])
        page.reload(wait_until='networkidle')
        assert page.locator('[data-admin-group="design"]').evaluate('(node) => node.open'), 'Malformed preferences hid the default active category'
        activate_and_reload(page, {'content': True})
    page.evaluate('([key, value]) => localStorage.setItem(key, value)', [scope, preferences])
    visit(page, '/admin/')
    expect(page.locator('[data-about-editor]')).to_be_visible()
    page.locator('[name="title"]').fill('About Nikki — Browser Fixture')
    page.locator('[name="biography"]').fill('A biography saved through the real owner editor.\n\n<script>window.aboutInjection=true</script> stays literal.')
    page.locator('[name="statistics[0][label]"]').fill('Favorite place')
    page.locator('[name="statistics[0][value]"]').fill('The music studio')
    page.locator('[name="statistics[1][label]"]').fill('Hidden measurement')
    page.locator('[name="statistics[1][value]"]').fill('')
    page.locator('[data-about-add]').click()
    last = page.locator('[data-about-row]').last
    last.locator('input').nth(0).fill('Custom detail')
    last.locator('input').nth(1).fill('Owner editable')
    last.locator('[data-row-up]').click()
    page.locator('[name="ctaLabel"]').fill('Ask about booking')
    page.locator('[name="ctaUrl"]').fill('/booking/')
    page.get_by_role('button', name='Save About Me', exact=True).click()
    expect(page.locator('.admin-alert.success')).to_contain_text('About Me was saved')
    page.reload(wait_until='networkidle')
    expect(page.locator('[name="biography"]')).to_have_value('A biography saved through the real owner editor.\n\n<script>window.aboutInjection=true</script> stays literal.')
    assert page.locator('[data-about-row]').nth(5).locator('input').nth(0).input_value() == 'Custom detail'
    # A forged POST without a valid CSRF token must not replace the biography.
    forged = owner.request.post(BASE + '/admin/?page=about', form={'action': 'save_about_page', 'title': 'FORGED', 'biography': 'MUTATED'})
    assert 'session token expired' in forged.text().lower()
    page.screenshot(path=str(OUT / 'about-editor-desktop.png'), full_page=True)
    # Unauthenticated writers must be redirected without updating the public page.
    guest = browser.new_context()
    response = guest.request.post(BASE + '/admin/?page=about', form={'action': 'save_about_page', 'title': 'UNAUTHORIZED'})
    assert 'page=login' in response.url
    guest.close()
    for width, height in [(1440, 1000), (834, 1112), (390, 844)]:
        context = browser.new_context(viewport={'width': width, 'height': height})
        public = context.new_page()
        public.on('pageerror', lambda error: errors.append(str(error)))
        visit(public, '/about/')
        expect(public.locator('#aboutTitle')).to_have_text('About Nikki — Browser Fixture')
        about_box = public.locator('.about-page').bounding_box()
        assert about_box['x'] >= 16 and about_box['x'] + about_box['width'] <= width - 16, ('About content touches the viewport edges', width, about_box)
        expect(public.locator('.about-statistics')).to_contain_text('The music studio')
        expect(public.locator('.about-statistics')).not_to_contain_text('Hidden measurement')
        expect(public.locator('.about-biography')).to_contain_text('<script>window.aboutInjection=true</script>')
        assert not public.evaluate('Boolean(window.aboutInjection)'), 'Biography executed as HTML'
        assert_route_destination(public.get_by_role('link', name='Ask about booking', exact=True).get_attribute('href'), BASE, 'booking/')
        public.screenshot(path=str(OUT / ('about-public-' + str(width) + '.png')), full_page=True)
        # Check both canonical auth URLs and friendly aliases, including Join.
        for path in ['/account/login.php', '/account/register.php', '/account/forgot.php', '/account/reset.php', '/account/verify.php', '/join/']:
            visit(public, path)
            card = public.locator('.member-auth-card')
            expect(card).to_be_visible()
            geometry = card.evaluate('''node => {
              const card = node.getBoundingClientRect();
              const shell = node.closest('.member-auth-shell').getBoundingClientRect();
              return {centerX: card.x + card.width / 2, centerY: card.y + card.height / 2,
                shellY: shell.y + shell.height / 2, width: innerWidth,
                overflow: document.documentElement.scrollWidth > innerWidth + 1};
            }''')
            assert abs(geometry['centerX'] - geometry['width'] / 2) < 2, (width, path, geometry)
            assert abs(geometry['centerY'] - geometry['shellY']) < 2, (width, path, geometry)
            assert not geometry['overflow']
            expect(public.locator('footer')).to_have_count(1)
            if path.endswith('register.php'):
                expect(public.locator('[name="password"]')).to_have_attribute('minlength', '12')
            if path.endswith('login.php'):
                public.screenshot(path=str(OUT / ('login-centered-' + str(width) + '.png')), full_page=True)
        if width < 1280:
            public.locator('#mobileMenu').click()
            cart_link = public.locator('#primaryNav').get_by_role('link', name='Cart', exact=True)
            expect(cart_link).to_be_visible()
            assert_route_destination(cart_link.get_attribute('href'), BASE, 'cart/')
            bars = public.locator('#mobileMenu span:not(.sr-only)').evaluate_all('(bars) => bars.map(bar => bar.getBoundingClientRect().width)')
            assert len(bars) == 3 and min(bars) >= 20, ('Mobile menu bars are not visible', bars)
            public.locator('#mobileMenu').click()
        for path in ['/', '/about/', '/booking/', '/music/', '/photos/', '/videos/', '/shop/', '/membership/', '/blog/', '/newsletter/', '/links/', '/cart/']:
            visit(public, path)
            links = public.locator('#primaryNav a').all_text_contents()
            assert 'Contact' not in links and links[links.index('Booking') + 1] == 'About Me', (path, links)
            public.evaluate('window.scrollTo(0, 700)')
            public.wait_for_timeout(80)
            header = public.locator('.public-navigation')
            assert abs(header.bounding_box()['y']) < 1, (width, path, 'navigation not sticky')
        context.close()
    page.set_viewport_size({'width': 390, 'height': 844})
    visit(page, '/admin/?page=about')
    page.locator('.sidebar-toggle').click()
    expect(page.locator('.sidebar-toggle')).to_have_attribute('aria-expanded', 'true')
    expect(page.locator('[data-admin-group="design"] [aria-current="page"]')).to_be_visible()
    page.keyboard.press('Escape')
    expect(page.locator('.sidebar-toggle')).to_have_attribute('aria-expanded', 'false')
    page.screenshot(path=str(OUT / 'about-editor-mobile.png'), full_page=True)
    assert not errors, errors
    owner.close()
    browser.close()

print('About Me owner editing/escaping, persistent admin categories/current page, sticky navigation and centered auth layouts passed at desktop/tablet/mobile widths.')
