"""Installed merchandise, inventory and direct-checkout boundaries over real HTTP."""
from __future__ import annotations
import json
import os
from pathlib import Path
from urllib.parse import parse_qs, urljoin, urlparse
import requests
from bs4 import BeautifulSoup

BASE = os.environ['VELVET_BASE_URL'].rstrip('/') + '/'
ROOT = Path(os.environ['VELVET_TEST_RUNTIME'])
assert (ROOT / '.vvx-test-runtime').is_file()

def state():
    return json.loads((ROOT / 'storage/data.json').read_text())

def get(session, path):
    response = session.get(urljoin(BASE, path.lstrip('/')), timeout=20)
    assert response.status_code == 200, (path, response.status_code)
    return response

def csrf(response):
    soup = BeautifulSoup(response.text, 'html.parser')
    node = soup.select_one('input[name="_csrf"]')
    assert node and node.get('value'), response.url
    return node['value']

def post(session, path, page, payload, **kwargs):
    return session.post(urljoin(BASE, path.lstrip('/')), data={'_csrf': csrf(page), **payload}, timeout=20, **kwargs)

def member_login(email):
    session = requests.Session()
    page = get(session, 'account/login.php')
    response = post(session, 'account/login.php', page, {'email': email, 'password': 'VelvetMember12'})
    assert urlparse(response.url).path == urlparse(BASE).path.rstrip('/') + '/route.php'
    assert parse_qs(urlparse(response.url).query).get('vvx_route') == ['account']
    return session

guest, owner = requests.Session(), requests.Session()
owner_login = get(owner, 'admin/?page=login')
assert '/admin/' in post(owner, 'admin/?page=login', owner_login, {'email': 'admin@example.com', 'password': 'VelvetDemo12'}).url
editor = get(owner, 'admin/?page=merchandise')
assert 'Create product' in editor.text
fixture = state()
fixture['admins']['merch-editor'] = {**fixture['admins']['demo-owner'], 'id': 'merch-editor', 'email': 'merch-editor@example.test', 'role': 'editor'}
(ROOT / 'storage/data.json').write_text(json.dumps(fixture))
limited_admin = requests.Session()
limited_login = get(limited_admin, 'admin/?page=login')
post(limited_admin, 'admin/?page=login', limited_login, {'email': 'merch-editor@example.test', 'password': 'VelvetDemo12'})
limited_editor = get(limited_admin, 'admin/?page=merchandise')
assert 'Owner access required' in limited_editor.text and 'merchandise-product-form' not in limited_editor.text
payload = {
    'action': 'save_merchandise_product', 'title': 'HTTP Velvet Tee', 'slug': 'http-velvet-tee',
    'description': 'Fixture description <script id="merchandise-xss">alert(1)</script>',
    'price': '20.00', 'currency': 'USD', 'status': 'active', 'requires_shipping': '1', 'shipping_price': '4.00',
    'variants[0][label]': 'Rose / Medium', 'variants[0][sku]': 'HTTP-TEE-M', 'variants[0][stock]': '2', 'variants[0][enabled]': '1', 'variants[0][option_text]': 'Size: M; Color: Rose',
    'variants[1][label]': 'Rose / Large', 'variants[1][sku]': 'HTTP-TEE-L', 'variants[1][price]': '25.00', 'variants[1][stock]': '0', 'variants[1][enabled]': '1', 'variants[1][option_text]': 'Size: L; Color: Rose',
}
before = len(state().get('merchandiseProducts', {}))
limited_post = post(limited_admin, 'admin/?page=merchandise', get(limited_admin, 'admin/?page=settings'), payload)
assert 'Owner access is required to manage merchandise' in limited_post.text and len(state().get('merchandiseProducts', {})) == before
missing_csrf = owner.post(urljoin(BASE, 'admin/?page=merchandise'), data=payload, timeout=20)
assert len(state().get('merchandiseProducts', {})) == before and 'session token expired' in missing_csrf.text
unauthorized = guest.post(urljoin(BASE, 'admin/?page=merchandise'), data=payload, timeout=20, allow_redirects=False)
assert unauthorized.status_code in (302, 303, 403) and len(state().get('merchandiseProducts', {})) == before
with (ROOT / 'images/defaults/brand-mark.png').open('rb') as image:
    created = post(owner, 'admin/?page=merchandise', editor, payload, files={'product_images[]': ('fixture-product.png', image, 'image/png')})
assert 'Merchandise product saved' in created.text, created.text[-2000:]
product_id = parse_qs(urlparse(created.url).query)['edit'][0]
product = state()['merchandiseProducts'][product_id]
variants = [v for v in state()['merchandiseVariants'].values() if v['productId'] == product_id]
variant_id = next(v['id'] for v in variants if v['sku'] == 'HTTP-TEE-M')
assert product['imageIds'] and len(variants) == 2
detail = get(guest, 'shop/?product=http-velvet-tee')
document = BeautifulSoup(detail.text, 'html.parser')
assert not document.select('script#merchandise-xss') and '&lt;script id=' in detail.text
for image in document.select('.merchandise-gallery img'):
    response = guest.get(urljoin(BASE, image['src']), timeout=20)
    assert response.status_code == 200 and response.headers.get('Content-Type', '').startswith('image/')
storefront = BeautifulSoup(get(guest, 'shop/').text, 'html.parser')
assert not storefront.select('form input[name="type"][value="gallery"], form input[name="type"][value="video"]'), 'PPV appeared as normal store merchandise.'
plan_fields = storefront.select('form input[name="type"][value="plan"]')
assert plan_fields, 'Membership checkout form was not rendered.'
assert all(urlparse(field.find_parent('form')['action']).path == urlparse(BASE).path.rstrip('/') + '/route.php'
           and parse_qs(urlparse(field.find_parent('form')['action']).query).get('vvx_route') == ['checkout']
           for field in plan_fields), 'Membership did not use its own canonical checkout.'

# Invalid media is rejected before a product record is created.
bad_payload = dict(payload, title='Bad Upload', slug='bad-upload')
bad_upload = post(owner, 'admin/?page=merchandise', get(owner, 'admin/?page=merchandise'), bad_payload, files={'product_images[]': ('bad.php', b'<?php echo "unsafe";', 'application/x-httpd-php')})
assert 'Unsupported media type' in bad_upload.text or 'does not match' in bad_upload.text
assert len(state()['merchandiseProducts']) == before + 1

member = member_login('other@example.com')
nonowner = post(member, 'admin/?page=merchandise', get(member, 'shop/'), payload, allow_redirects=False)
assert nonowner.status_code in (302, 303, 403) and len(state()['merchandiseProducts']) == before + 1
cart = post(member, 'cart/', get(member, 'shop/?product=http-velvet-tee'), {'action': 'add', 'type': 'merchandise', 'id': product_id, 'variant_id': variant_id, 'quantity': '2'})
assert 'HTTP Velvet Tee' in cart.text and 'USD 48.00' in cart.text
mixed = post(member, 'cart/', cart, {'action': 'add', 'type': 'gallery', 'id': 'demo-gallery-lounge'})
assert 'Only merchandise belongs' in mixed.text
assert len(BeautifulSoup(get(member, 'cart/').text, 'html.parser').select('[data-cart-line]')) == 1

# A PPV checkout targets precisely one release and leaves physical goods in cart.
content = get(member, 'photos/view.php?slug=velvet-lounge')
direct = post(member, 'checkout/', content, {'action': 'start', 'type': 'gallery', 'id': 'demo-gallery-lounge', 'mode': 'purchase'})
assert 'intent=' in direct.url and 'Direct Content Checkout' in direct.text and 'HTTP Velvet Tee' not in direct.text
direct_paid = post(member, direct.url, direct, {'action': 'pay', 'provider': 'sandbox', 'sandbox_result': 'success', 'confirmation': '1'})
assert 'Your content is ready' in direct_paid.text
assert 'Velvet Lounge' in get(member, 'account/library.php').text
cart_after_direct = get(member, 'cart/')
assert 'HTTP Velvet Tee' in cart_after_direct.text and 'USD 48.00' in cart_after_direct.text

checkout = get(member, 'checkout/')
orders_before = len(state()['orders'])
invalid_address = post(member, 'checkout/', checkout, {'action': 'pay', 'provider': 'sandbox', 'sandbox_result': 'success', 'confirmation': '1'})
assert 'required shipping field' in invalid_address.text and len(state()['orders']) == orders_before
payment = {'action': 'pay', 'provider': 'sandbox', 'sandbox_result': 'success', 'confirmation': '1', 'shipping[name]': 'HTTP Customer', 'shipping[address1]': '1 Fixture Lane', 'shipping[city]': 'Test City', 'shipping[region]': 'CA', 'shipping[postalCode]': '90210', 'shipping[country]': 'US'}
paid = post(member, 'checkout/', invalid_address, payment)
assert 'Your merchandise order is confirmed' in paid.text, paid.text[-1500:]
order_number = parse_qs(urlparse(paid.url).query)['order'][0]
order = next(o for o in state()['orders'].values() if o['orderNumber'] == order_number)
assert order['metadata']['shippingAddress']['postalCode'] == '90210' and order['grandTotal'] == 48
assert state()['merchandiseVariants'][variant_id]['stock'] == 0
assert 'Your cart is waiting' in get(member, 'cart/').text
assert 'HTTP Velvet Tee' not in get(member, 'account/library.php').text
receipt = get(member, 'receipt/?order=' + order_number)
assert 'Shipping' in receipt.text and 'Rose / Medium' in receipt.text and 'USD 48.00' in receipt.text
assert guest.get(urljoin(BASE, 'receipt/?order=' + order_number), timeout=20, allow_redirects=False).status_code in (302, 303, 403)
other_member = member_login('member@example.com')
assert other_member.get(urljoin(BASE, 'receipt/?order=' + order_number), timeout=20).status_code == 404

sold_out = post(member, 'cart/', get(member, 'shop/?product=http-velvet-tee'), {'action': 'add', 'type': 'merchandise', 'id': product_id, 'variant_id': variant_id, 'quantity': '1'})
assert 'enough stock' in sold_out.text
order_admin = get(owner, 'admin/?page=orders&order=' + order_number)
assert 'Merchandise fulfillment' in order_admin.text and '1 Fixture Lane' in order_admin.text
shipped = post(owner, 'admin/?page=orders&order=' + order_number, order_admin, {'action': 'update_merchandise_fulfillment', 'order_id': order['id'], 'shipping_status': 'shipped', 'tracking_reference': 'HTTP-TRACKING'})
assert 'fulfillment updated' in shipped.text and state()['orders'][order['id']]['metadata']['shippingStatus'] == 'shipped'
assert 'HTTP-TRACKING' in get(member, 'receipt/?order=' + order_number).text
refunded = post(owner, 'admin/?page=orders&order=' + order_number, shipped, {'action': 'refund_order', 'order_id': order['id'], 'reason': 'HTTP shipment refund fixture'})
assert 'was refunded' in refunded.text and state()['merchandiseVariants'][variant_id]['stock'] == 0
deleted = post(owner, 'admin/?page=merchandise', get(owner, 'admin/?page=merchandise'), {'action': 'delete_merchandise_product', 'product_id': product_id})
assert 'Product removed' in deleted.text and guest.get(urljoin(BASE, 'shop/?product=http-velvet-tee'), timeout=20).status_code == 404
assert 'HTTP Velvet Tee' in get(member, 'receipt/?order=' + order_number).text
print('Merchandise owner CRUD/upload, stock, isolated PPV/cart checkout, shipping, order privacy, fulfillment and refund HTTP acceptance passed.')
