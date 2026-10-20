"""Interactive merchandise editing, variants, cart, shipping and isolated content checkout."""
from __future__ import annotations

import json
import os
from public_urls import site_url, wait_for_route
import re
from pathlib import Path
from urllib.parse import parse_qs, urlparse
from playwright.sync_api import sync_playwright

BASE = os.environ['VELVET_BASE_URL'].rstrip('/')
ROOT = Path(os.environ['VELVET_TEST_RUNTIME'])
assert (ROOT / '.vvx-test-runtime').is_file(), 'A disposable runtime is required.'
OUT = Path(os.environ.get('VELVET_SCREENSHOT_DIR', '/tmp/vvx-merchandise-browser'))
OUT.mkdir(parents=True, exist_ok=True)
errors: list[str] = []


def state():
    return json.loads((ROOT / 'storage/data.json').read_text())


def observe(page, label):
    page.on('pageerror', lambda error: errors.append(f'{label}: {error}'))
    page.on('console', lambda message: errors.append(f'{label}: {message.text}') if message.type == 'error' else None)


def visit(page, path):
    response = page.goto(site_url(BASE, path), wait_until='domcontentloaded')
    assert response and response.status == 200, (path, response.status if response else None)
    if page.locator('#ageGate:not([hidden])').count():
        page.locator('#ageEnter').click()


def fits(page, label):
    assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), f'{label} overflows the viewport.'


def shot(page, name):
    page.evaluate('window.scrollTo({top:0,left:0,behavior:"instant"})')
    page.screenshot(path=str(OUT / name), full_page=True)


def variant(row, label, sku, size, stock, price=''):
    row.get_by_label('Option label', exact=True).fill(label)
    row.get_by_label('SKU', exact=True).fill(sku)
    row.get_by_label('Available stock', exact=True).fill(str(stock))
    row.get_by_label('Option price (optional)', exact=True).fill(price)
    row.get_by_label('Size / color / other options').fill(f'Size: {size}; Color: Rose')


with sync_playwright() as playwright:
    options = {'headless': True, 'args': ['--no-sandbox', '--disable-dev-shm-usage']}
    if os.getenv('VELVET_CHROMIUM'):
        options['executable_path'] = os.environ['VELVET_CHROMIUM']
    browser = playwright.chromium.launch(**options)
    owner_context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    owner = owner_context.new_page()
    observe(owner, 'owner')
    visit(owner, '/admin/?page=login')
    owner.get_by_label('Email address', exact=True).fill('admin@example.com')
    owner.get_by_label('Password', exact=True).fill('VelvetDemo12')
    owner.get_by_role('button', name='Sign in', exact=True).click()
    owner.wait_for_url('**/admin/')
    visit(owner, '/admin/?page=merchandise')
    form = owner.locator('.merchandise-product-form')
    form.get_by_label('Product title', exact=True).fill('Browser Velvet Tee')
    form.get_by_label('URL slug', exact=True).fill('browser-velvet-tee')
    form.get_by_label('Description', exact=True).fill('A soft cotton fixture, available in two real sizes.')
    form.get_by_label('Product price', exact=True).fill('20.00')
    form.get_by_label('Shipping price per item', exact=True).fill('4.00')
    form.locator('[name="status"]').select_option('active')
    form.locator('[name="product_images[]"]').set_input_files(ROOT / 'images/defaults/brand-mark.png')
    variant(form.locator('[data-variant-row]').nth(0), 'Rose / Medium', 'BROWSER-TEE-M', 'M', 3)
    form.get_by_role('button', name='Add option', exact=True).click()
    form.get_by_role('button', name='Add option', exact=True).click()
    form.locator('[data-variant-row]').nth(1).get_by_role('button', name='Remove option', exact=True).click()
    assert form.locator('[data-variant-row]').count() == 2, 'Removing a middle variant removed the wrong option.'
    variant(form.locator('[data-variant-row]').nth(1), 'Rose / Large', 'BROWSER-TEE-L', 'L', 3, '25.00')
    assert form.locator('[data-variant-row] [name]').evaluate_all('(nodes) => nodes.length === new Set(nodes.map(node => node.name)).size'), 'Dynamic variants reused form field names.'
    fits(owner, 'Desktop merchandise editor')
    form.get_by_role('button', name='Save Product', exact=True).click()
    owner.get_by_text('Merchandise product saved.', exact=True).wait_for()
    product_id = parse_qs(urlparse(owner.url).query)['edit'][0]
    product = state()['merchandiseProducts'][product_id]
    variants = [row for row in state()['merchandiseVariants'].values() if row['productId'] == product_id]
    medium_id = next(row['id'] for row in variants if row['sku'] == 'BROWSER-TEE-M')
    large_id = next(row['id'] for row in variants if row['sku'] == 'BROWSER-TEE-L')
    assert len(variants) == 2 and product['imageIds'], 'The saved product lost its variants or uploaded photo.'
    owner.reload(wait_until='domcontentloaded')
    assert owner.locator('[name="title"]').input_value() == 'Browser Velvet Tee'
    assert owner.locator('[data-variant-row]').nth(1).get_by_label('Option price (optional)', exact=True).input_value() in ('25', '25.00')
    shot(owner, 'merchandise-editor-desktop.png')

    order_numbers = []
    for label, viewport in [('desktop', {'width': 1440, 'height': 1000}), ('mobile', {'width': 390, 'height': 844})]:
        context = browser.new_context(viewport=viewport)
        page = context.new_page()
        observe(page, 'merchandise-' + label)
        visit(page, '/account/login.php')
        page.get_by_label('Email address', exact=True).fill('other@example.com')
        page.get_by_label('Password', exact=True).fill('VelvetMember12')
        page.get_by_role('button', name='Sign In', exact=True).click()
        wait_for_route(page, BASE, 'account')
        visit(page, '/shop/?product=browser-velvet-tee')
        fits(page, label + ' product')
        page.wait_for_function('() => { const images = [...document.querySelectorAll(".merchandise-gallery img")]; return images.length > 0 && images.every(image => image.complete && image.naturalWidth > 0); }')
        buy = page.locator('[data-merchandise-buy]')
        selected = large_id if label == 'desktop' else medium_id
        buy.locator('[name="variant_id"]').select_option(selected)
        assert buy.locator('[data-merchandise-price]').inner_text() == ('USD 25.00' if label == 'desktop' else 'USD 20.00'), 'Variant selection did not update the displayed price.'
        assert buy.locator('[name="quantity"]').get_attribute('max') == '3', 'Quantity did not follow available stock.'
        buy.locator('[name="quantity"]').fill('2' if label == 'desktop' else '1')
        shot(page, 'merchandise-product-' + label + '.png')
        buy.get_by_role('button', name='Add to Cart', exact=True).click()
        wait_for_route(page, BASE, 'cart')
        assert page.locator('[data-cart-line]').count() == 1
        if label == 'desktop':
            quantity_form = page.locator('.cart-quantity')
            quantity_form.locator('[name="quantity"]').fill('1')
            quantity_form.get_by_role('button', name='Update', exact=True).click()
            page.get_by_text('Quantity updated.', exact=True).wait_for()
            assert 'USD 29.00' in page.locator('.order-summary').inner_text()
            quantity_form.locator('[name="quantity"]').fill('2')
            quantity_form.get_by_role('button', name='Update', exact=True).click()
            page.get_by_text('Quantity updated.', exact=True).wait_for()
            # Buying one PPV release does not consume the merchandise waiting in cart.
            visit(page, '/photos/view.php?slug=velvet-lounge')
            page.get_by_role('button', name=re.compile(r'^Buy Now')).click()
            wait_for_route(page, BASE, 'checkout', required_query=('intent',))
            assert page.get_by_role('heading', name='Unlock this release.', exact=True).is_visible()
            assert 'Browser Velvet Tee' not in page.locator('.checkout-items').inner_text()
            page.locator('.checkout-confirm input').check()
            page.get_by_role('button', name=re.compile(r'^Pay ')).click()
            wait_for_route(page, BASE, 'checkout/success.php', required_query=('order',))
            visit(page, '/account/library.php')
            assert 'Velvet Lounge' in page.locator('body').inner_text()
            visit(page, '/cart/')
            assert page.locator('[data-cart-line]').count() == 1
            assert page.locator('.cart-quantity [name="quantity"]').input_value() == '2', 'Direct purchase changed merchandise quantity.'
        total = 'USD 58.00' if label == 'desktop' else 'USD 24.00'
        assert total in page.locator('.order-summary').inner_text()
        fits(page, label + ' cart')
        assert page.locator('.cart-item-copy').bounding_box()['width'] >= (280 if label == 'desktop' else 200), 'Product details were squeezed into a legacy icon column.'
        shot(page, 'merchandise-cart-' + label + '.png')
        page.get_by_role('link', name='Continue to Checkout', exact=True).click()
        wait_for_route(page, BASE, 'checkout')
        assert page.get_by_role('heading', name='Complete your merchandise order.', exact=True).is_visible()
        fits(page, label + ' checkout')
        page.locator('.checkout-confirm input').check()
        order_count = len(state()['orders'])
        page.get_by_role('button', name=re.compile(r'^Pay ')).click()
        assert page.locator('.shipping-address input:invalid').count() > 0 and len(state()['orders']) == order_count, 'The browser accepted an incomplete shipping address.'
        address = {'name': 'Browser Customer ' + label, 'address1': '1 Fixture Lane', 'city': 'Test City', 'region': 'CA', 'postalCode': '90210', 'country': 'US'}
        for key, value in address.items():
            page.locator('[name="shipping[' + key + ']"]').fill(value)
        shot(page, 'merchandise-checkout-' + label + '.png')
        page.get_by_role('button', name=re.compile(r'^Pay ')).click()
        wait_for_route(page, BASE, 'checkout/success.php', required_query=('order',))
        assert 'Your merchandise order is confirmed' in page.locator('body').inner_text()
        number = parse_qs(urlparse(page.url).query)['order'][0]
        order_numbers.append(number)
        order = next(row for row in state()['orders'].values() if row['orderNumber'] == number)
        assert state()['orderItems'][order['id']][0]['quantity'] == (2 if label == 'desktop' else 1)
        assert order['metadata']['shippingAddress']['name'] == address['name']
        assert state()['merchandiseVariants'][selected]['stock'] == (1 if label == 'desktop' else 2), 'Purchase did not deduct selected variant stock exactly once.'
        visit(page, '/receipt/?order=' + number)
        assert total in page.locator('body').inner_text()
        fits(page, label + ' receipt')
        shot(page, 'merchandise-receipt-' + label + '.png')
        visit(page, '/cart/')
        assert page.locator('[data-cart-line]').count() == 0
        visit(page, '/account/library.php')
        assert 'Browser Velvet Tee' not in page.locator('body').inner_text(), 'Physical merchandise entered My Library.'
        context.close()

    # This owner form remained open while the member bought from both variants.
    owner.locator('[name="description"]').fill('Stale edit must not restore sold inventory.')
    owner.get_by_role('button', name='Save Product', exact=True).click()
    owner.get_by_text('This product or its stock changed. Reload the product before saving.', exact=True).wait_for()
    assert state()['merchandiseVariants'][large_id]['stock'] == 1 and state()['merchandiseVariants'][medium_id]['stock'] == 2, 'An old owner form restored sold stock.'
    assert state()['merchandiseProducts'][product_id]['description'] == 'A soft cotton fixture, available in two real sizes.'

    visit(owner, '/admin/?page=orders&order=' + order_numbers[0])
    fulfillment = owner.locator('.merchandise-order')
    assert 'Browser Customer desktop' in fulfillment.inner_text()
    fulfillment.locator('[name="shipping_status"]').select_option('shipped')
    fulfillment.get_by_label('Tracking reference', exact=True).fill('BROWSER-TRACKING')
    fulfillment.get_by_role('button', name='Update Fulfillment', exact=True).click()
    owner.get_by_text('Merchandise fulfillment updated.', exact=True).wait_for()
    shipped = next(row for row in state()['orders'].values() if row['orderNumber'] == order_numbers[0])
    assert shipped['metadata']['shippingStatus'] == 'shipped' and shipped['metadata']['trackingReference'] == 'BROWSER-TRACKING'
    shot(owner, 'merchandise-fulfillment-desktop.png')
    owner.set_viewport_size({'width': 390, 'height': 844})
    visit(owner, '/admin/?page=merchandise&edit=' + product_id)
    fits(owner, 'Mobile merchandise editor')
    owner.locator('[name="description"]').fill('Edited from a mobile owner screen.')
    owner.get_by_role('button', name='Save Product', exact=True).click()
    owner.get_by_text('Merchandise product saved.', exact=True).wait_for()
    assert state()['merchandiseProducts'][product_id]['description'] == 'Edited from a mobile owner screen.'
    shot(owner, 'merchandise-editor-mobile.png')
    owner_context.close()
    browser.close()

assert not errors, '\n'.join(errors)
print('Merchandise browser passed: owner CRUD/images/dynamic variants, desktop/mobile cart/shipping/payment, direct PPV isolation, stock and fulfillment.')
