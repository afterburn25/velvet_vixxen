# Public policies, verified age and location access

This guide describes the in-progress **0.9.0 / Build 20261021.4** implementation. It does not certify the owner’s production host, proxy configuration, Yoti account or a live provider transaction. The latest delivered release is recorded separately in the project’s release evidence.

## Where to work

Open **Content & Media → Policies & access**. The workspace has **Public policies** and **Age & location** tabs. Changes require the current active owner, a recent password/MFA confirmation, a valid form token and the revision that was loaded with the form. If confirmation has expired, open **System → Security**, confirm your identity, then reload the policy form. A rejected stale form retains the attempted wording and old revision; reload and reconcile it with the saved draft before submitting again.

**Protection & policies** remains the separate workspace for collaborator references, releases, content reports, appeals and protected media. A link connects the two workspaces. Existing protection and hold decisions remain in force when age or location settings change.

## Write and publish the five policies

The five available documents are:

| Document | Public destination | Typical information the owner supplies |
| --- | --- | --- |
| Terms of use | `policies/?document=terms` | Site-specific membership, purchases, permitted use and account terms |
| Privacy policy | `policies/?document=privacy` | Actual data collection, uses, retention, service providers and privacy contacts |
| Adults & access | `policies/?document=adults` | The applicable adult threshold, verification process and access restrictions |
| Copyright & content reports | `policies/?document=dmca` | Rights/consent reporting contacts, review and appeal process |
| Support & refunds | `policies/?document=support` | Actual support contacts and the site’s applicable payment/refund process |

These are empty draft slots. The application supplies no automatic legal wording or assertion that a policy meets a particular jurisdiction’s requirements. The owner supplies and reviews the wording appropriate to the actual service.

1. Choose the document and enter its title and policy text.
2. Select **Save policy draft**. A later draft does not change an already published version.
3. Read **Review the saved draft**, including the exact saved wording.
4. Check the approval box and select **Publish reviewed policy**. Publication uses the saved draft and its current revision.
5. Follow **Read public policy** to inspect the public result. Use **Unpublish policy** to withdraw it while retaining the draft.

Text is displayed literally, with HTML escaped. Scripts, markup and HTML links do not execute. The editor accepts a 160-byte title and up to 30,000 bytes of policy text. Unpublished and withdrawn documents return HTTP 404. Malformed or unavailable policy storage returns HTTP 503; the application does not invent a replacement policy. Reads do not initialize the private policy journal.

The homepage and shared footer have actual Terms, Privacy, Privacy choices, 18+, DMCA and Support links. **Privacy choices** remains the separate measurement-consent control. The public policy directory lists only published documents.

## Prepare protected delivery

Age verification and location rules are disabled by default. Enabling either requires completed **Protection & policies** activation first. That workflow places a harmless real file in the uploads directory, checks that the website denies its direct address, and preserves managed uploads in private storage. A server that ignores the access rules cannot activate protection merely because a probe file is missing. The file remains present throughout the check.

After activation, raw upload addresses are denied by the web server. Managed images and other saved files use guarded delivery. Signed media requests still pass through the current age/location policy, entitlement checks and protection holds. Range and HEAD requests use the same access boundary. Deliberately disabling age/location does not disable protected delivery or lift a release/case hold.

The shipped interface artwork and externally hosted files retain their own delivery boundaries. Previously distributed files or cached copies outside this server require the owner’s separate cache/removal process. Test the actual serving host and any CDN before treating the configured rule as production acceptance.

## Configure Yoti adult verification

The adapter follows Yoti’s official [quick start](https://developers.yoti.com/age-verification/quick-start), [document-verification session request](https://developers.yoti.com/age-verification/headless-idv-session), and [result response](https://developers.yoti.com/age-verification/headless-idv-results). These schemas were checked during implementation. The adapter deliberately uses the documented **OVER** document-verification method with authenticity **AUTO** and liveness **PASSIVE**. It does not interpret a raw returned age, an age-estimation result, or a browser acknowledgement as the configured threshold proof.

Obtain the SDK ID and confidential API key from the owner’s Yoti account and enable its document-verification method. Configure the website’s real HTTPS base address. In **Age & location**, enter:

- **Age check:** Disabled or Require verified age.
- **Adult threshold:** An integer from 18 through 99. Select the threshold appropriate to the site.
- **Verified session, minutes:** 15–1,440 minutes. Provider check attempts expire after 15 minutes independently of the granted browser session.
- **Yoti SDK ID:** The UUID supplied for the owner’s Yoti integration.
- **Yoti API key:** Paste a new or replacement key. The saved value is encrypted using the installation application key and never redisplayed. A blank field retains it. **Clear the saved provider key** removes it; supplying a replacement and selecting Clear together is rejected.

Review the rules and save. Saving, opening settings, viewing ordinary content and following the provider’s callback do not contact Yoti. Required mode with absent credentials, an unusable HTTPS address, invalid configuration or unavailable protected delivery denies ordinary content with HTTP 503. This state is intentional and does not fall back to the old “I am 18+” notice.

Visitors begin by submitting the CSRF-protected **Continue to Yoti** form. The backend creates a session at the fixed `https://age.yoti.com/api/v1/sessions` endpoint with an opaque reference. The returned UUID is used to form the fixed `https://age.yoti.com` browser destination. The API key stays on the server. Automatic redirects from provider API requests are disabled, TLS certificates and hostnames are verified, and response/time limits are bounded. There is no configurable provider host, arbitrary callback input or webhook trust shortcut.

After completing the provider workflow, the visitor returns to the verification page and submits **Check verification result**. This POST fetches the fixed session’s backend result. A successful grant requires the exact SDK ID, session ID, opaque reference, OVER type, COMPLETE status, DOC_SCAN method, configured integer threshold, allowed-method configuration and consistent creation/update/expiry times. PENDING and IN_PROGRESS remain unverified; failed, unknown, malformed, expired and mismatched results do not grant access. The application stores no identity-document image, date of birth or complete provider response.

Attempts and successful grants are signed and kept in the server’s PHP session. Their signature binds the installation key and current PHP session. Threshold/provider/location changes invalidate the previous rule fingerprint. Expiry, changed rules, closing/replacing the session or signing in can require a new check. Client-side storage, forged query parameters, callback GETs and the retained optional adult notice cannot create this grant.

The start limiter permits at most six provider session starts per immediate connection address per hour, with at least 30 seconds between starts. It stores keyed hashes and recent timestamps in bounded private operational storage; expired buckets are removed on later starts. Result checks are separated by at least five seconds within the current session. Provider errors use a generic public message and do not echo credentials or response bodies.

## Configure geographic restrictions

Location rules are separate from age verification. They can be enabled while age verification is disabled. They require at least one blocked country and an explicitly configured trusted immediate proxy network.

1. Ask the host/CDN which proxy addresses connect directly to PHP and which supported header it sets after checking a visitor’s country.
2. Choose **CF-IPCountry**, **CloudFront-Viewer-Country**, or **X-Country-Code** according to that configuration.
3. Enter up to 32 explicit IPv4/IPv6 addresses or CIDR networks in **Trusted immediate proxy addresses**. Single addresses become `/32` or `/128` entries. `/0`, wildcard, malformed and oversized network lists are refused.
4. Enter the blocked countries as recognised two-letter codes, separated by spaces or commas.
5. Check **Enable location rules and block unconfirmed locations**, review the rules and save.

Only the actual `REMOTE_ADDR` of the immediate connection can authorize the selected header. The application never treats `X-Forwarded-For`, a query value, browser language or a cookie as that trusted connection. The upstream must replace untrusted client-supplied country headers and prevent an untrusted route from reaching the origin as though it were the configured proxy.

When enabled, a missing, malformed, lower-case, unknown or untrusted country value is blocked with HTTP 403. A two-letter-looking unknown code is not accepted. A correctly identified blocked country is denied even if its browser has a valid age grant. A country outside the blocked list is accepted only when supplied by a trusted immediate peer. IPv4 and IPv6 network matching use their actual address families; an IPv4 forwarding string cannot silently become a trusted IPv6 connection.

This is country-header enforcement, not a promise to detect a visitor’s physical location, VPN use or every regulatory condition. The actual proxy and origin behavior must be tested on the production host.

## Keep privacy and reports available

The access gate leaves these routes reachable: public policies; the age-check page; content reports/appeals; measurement Privacy choices; account login/logout, password recovery, verification and security; the member’s Account & privacy page and authenticated own-data download; and newsletter unsubscribe. The **Account & privacy** help link points directly to `account/privacy.php`.

These exceptions retain their own authentication, ownership, CSRF and recent-password rules. They do not expose member libraries, ordinary content, scenes or signed media. Machine payment/mail/license callbacks and owner uploads keep their existing independent authentication and signature requirements. Administrator security, recovery and policy settings remain available to an authorized owner who must repair a mistaken restriction.

## Check the actual deployment

Use the existing host acceptance process to confirm served build/version, protected raw-file denial, permitted/blocked/unknown proxy cases, policies and privacy/report routes. Use the owner’s provider account to conduct a separately approved live success, failure, expiry and callback-return transaction. Check that the configured threshold and document method match the provider account and the site’s policy. The local acceptance suite never makes a live Yoti request and cannot establish those external account/host facts.

## Engineering acceptance

The source producer is `tests/Feature/age_policies.php` (file and `--mysql`). Its provider transport is injected only into source-created service instances; there is no public test hook. It checks the official result shape and negative binding cases, encrypted key handling, signed grants, expiry/rules, current/recent owner checks, immutable policy snapshots and IPv4/IPv6 geographic trust.

`tests/Http/age_policies_http.py` builds or reads a checksum-pinned actual Fresh package, verifies its runtime bytes, and exercises root/nested real Apache installations. It uses actual owner forms and an existing static-file protection probe, independent file/SQL readback, ordinary/raw/guarded/signed/range/HEAD media, unpublished/published policy reads, unavailable-provider behavior, forged callback/session negatives and help exceptions. The HTTP fixture has no live provider key. Its limited corruption/session operations exist only in guarded, marked temporary CLI fixtures outside customer packages.

`tests/Browser/age_policies_playwright.py` exercises real desktop/mobile controls, keyboard navigation, safe public wording, stale forms, review/publication, encrypted settings, unavailable/blocked states and no-JavaScript policy editing. It captures actual PNG evidence and records console errors and layout geometry. Browser tests own separate extracted fixtures and repeat the HTTP acceptance.

Reports contain the actual PHP version, the actual SQL server’s `mysql_version`, package hash, source hashes, assertions and zero live-provider/SMTP counts. MySQL and MariaDB runs are recorded separately; one is not substituted for the other. Screenshot/report artifacts remain outside runtime packages. Final current-source acceptance, CI, integration and delivery evidence belongs in the root release record rather than being inferred from a restored historical test report.
