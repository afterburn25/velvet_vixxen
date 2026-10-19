"""Canonical public destinations for real browser tests, independent of URL encoding order.

These helpers construct requests to the physical dispatcher; they do not intercept,
serve or rewrite requests. Admin pages, installation pages and assets stay literal.
"""
from __future__ import annotations

from urllib.parse import parse_qs, parse_qsl, quote, urlencode, urljoin, urlparse


_PUBLIC_PAGES = frozenset({
    "about", "account", "blog", "booking", "campaign", "cart", "checkout",
    "collections", "create-account", "extension", "join", "links", "login",
    "membership", "messages", "music", "newsletter", "photos", "receipt",
    "recover", "requests", "shop", "videos",
})
_PUBLIC_ENDPOINTS = frozenset({
    "account/billing.php", "account/forgot.php", "account/library.php",
    "account/login.php", "account/logout.php", "account/register.php",
    "account/reset.php", "account/security.php", "account/verify.php",
    "api/addon-license.php", "api/marketing-event.php", "api/member-state.php",
    "api/payment-webhook.php", "api/scene.php", "api/upload.php", "blog/post.php",
    "checkout/success.php", "communication/download.php", "media.php",
    "newsletter/confirm.php", "newsletter/unsubscribe.php", "photos/view.php",
    "videos/watch.php",
})


def route_url(base: str, destination: str) -> str:
    """Build a physical public route URL, keeping query values and the fragment."""
    parsed = urlparse(destination)
    assert not parsed.scheme and not parsed.netloc, "Use a local logical route."
    name = parsed.path.strip("/")
    assert name, "The home page uses its physical index, not a route selector."
    parameters = [("vvx_route", name), *parse_qsl(parsed.query, keep_blank_values=True)]
    suffix = "#" + parsed.fragment if parsed.fragment else ""
    return base.rstrip("/") + "/route.php?" + urlencode(parameters, quote_via=quote) + suffix


def site_url(base: str, destination: str) -> str:
    """Resolve expected public aliases through route.php and other files literally."""
    parsed = urlparse(destination)
    if parsed.scheme or parsed.netloc:
        return destination
    name = parsed.path.strip("/")
    if (name in _PUBLIC_PAGES or name in _PUBLIC_ENDPOINTS
            or (name.endswith("/index.php") and name[:-10] in _PUBLIC_PAGES)):
        return route_url(base, destination)
    return urljoin(base.rstrip("/") + "/", destination.lstrip("/"))


def is_route_url(value, base: str, name: str, required_query=()) -> bool:
    """Match the actual origin, installation prefix, scalar selector and required values."""
    actual = urlparse(value.geturl() if hasattr(value, "geturl") else str(value))
    origin = urlparse(base.rstrip("/"))
    query = parse_qs(actual.query, keep_blank_values=True)
    return ((actual.scheme, actual.netloc, actual.path)
            == (origin.scheme, origin.netloc, origin.path + "/route.php")
            and query.get("vvx_route") == [name.strip("/")]
            and all(len(query.get(key, [])) == 1 and query[key][0] for key in required_query))


def wait_for_route(page, base: str, name: str, required_query=(), **options) -> None:
    page.wait_for_url(lambda value: is_route_url(value, base, name, required_query), **options)


def assert_route_destination(value: str, base: str, destination: str, message="Incorrect public destination") -> None:
    """Compare every path/query/fragment value without depending on query ordering."""
    actual = urlparse(value)
    expected = urlparse(route_url(base, destination))
    assert ((actual.scheme, actual.netloc, actual.path, actual.fragment)
            == (expected.scheme, expected.netloc, expected.path, expected.fragment)
            and parse_qs(actual.query, keep_blank_values=True)
            == parse_qs(expected.query, keep_blank_values=True)), (message, value, expected.geturl())
