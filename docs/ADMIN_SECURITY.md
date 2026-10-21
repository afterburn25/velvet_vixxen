# Administrator security

Open **Security** in the administrator navigation while signed in as the active
site owner. This page manages the owner's authenticator, recent identity
confirmation and active sessions. It also shows recorded security activity and
recent administrative actions. Member sign-in remains a separate account flow.

The implementation is in [Auth](../app/Core/Auth.php),
[AdminSecurity](../app/Core/AdminSecurity.php) and the
[Security controls](../app/Views/admin/security.php). This guide describes the
current implementation; the release record establishes which exact build and
required acceptance run were delivered.

## Connect an authenticator

1. Enter your current password under **Authenticator app** and choose **Set up
   authenticator**.
2. Add the displayed setup key to your authenticator. Use a time-based account,
   six digits and a 30-second interval. The page identifies the account email and
   Velvet Vixxen. Setup expires after ten minutes.
3. Enter a current code and choose **Connect authenticator**.
4. Save the recovery codes shown in that response in a private location separate
   from the site. Each works once; the application does not show the raw codes
   again on a later page load.

Enabling the authenticator requires a valid code before it becomes active. Its
secret is encrypted using the installation's existing secure-value service;
recovery codes are stored as keyed hashes. Successful factor use is recorded
atomically, preventing concurrent reuse of the same authenticator step or
recovery code. Keep the server and authenticator clocks correct.

After enrollment, sign-in asks for the password followed by an authenticator or
unused recovery code. The pending second-step challenge expires in five minutes.
Enrollment and removal invalidate the other administrator sessions. To remove an
authenticator, use **Remove authenticator**, supply the current password and a
valid factor, then select the explicit confirmation. There is no public URL,
query parameter or client-side setting that bypasses the second step.

## Confirm sensitive changes

Private records, credential changes and system recovery require a currently
active owner whose identity was confirmed within the preceding 15 minutes. A
normal successful sign-in establishes that confirmation. When it expires, open
**Security → Confirm protected actions**, enter the current password and, when
enabled, an authenticator or recovery code, then choose **Confirm my identity**.
Return to the original page, reload its current form and review the change before
submitting it again.

Confirmation does not extend the session's maximum lifetime. It does not make an
editor an owner, revive a revoked session or authorize a stale form revision.
Current saved account status and role are checked again at protected mutation
boundaries. A form rejected because its CSRF token expired must be reloaded; an
HTTP error does not mean the requested mutation succeeded.

For a runtime update, also follow [Update verification](UPDATE_VERIFICATION.md).
After extracting the **complete Upgrade ZIP**, open **`admin/repair-current.php`**
directly for the 0.8.x to 0.9 upgrade. This new filename reaches the current guard
even if the prior `admin/repair.php` entry remains cached. Its forms and redirects
stay on the new entry until application PHP is refreshed. The existing
`admin/repair.php` remains a compatibility entry once current code is active.
The entry verifies the entire on-disk runtime and its current dependency inventory
before loading cached application bootstrap. It refreshes the verified current
authentication dependencies so an older cached `Auth` class cannot bypass the
saved security rules or prevent owner confirmation.

An older password-only administrator cookie does not become a current session.
The repair page asks for the existing owner password, then the current
authenticator or an unused recovery code when enabled. If a current session's
15-minute confirmation has expired, the page provides password/factor confirmation
there. These deliberate attempts use the normal limits and security journal.
The page neither replaces installation keys nor resets owner accounts or data.

Missing or mixed application files, including a missing repair helper, produce
**Complete the update** with HTTP503 before sign-in or activation. Extract the
complete matching ZIP and reload. After owner confirmation, use **Refresh
application PHP**, confirm the running build, and then use the existing organized
folder repair if offered. Authentication preflight does not activate the cached
application/version/Composer files; that requires the explicit protected refresh.
An already cached legacy repair endpoint still executes its old security policy
until PHP is refreshed; uploading files cannot change bytecode already in use.
Do not use an old repair form as proof that current MFA is enforced. Use the new
entry above, or have hosting refresh this site's workers/scoped OPcache before
proceeding. Do not use a global OPcache reset or delete installation records as a
substitute for the update process.

## Sessions and sign-in limits

Administrator sessions have a 12-hour maximum lifetime. The application stores
hashed session tokens and keeps at most 20 current session records per account.
It validates the saved account, password stamp, security generation, expiry and
browser user-agent binding on authenticated requests. Deactivating an account,
changing its password or revoking its session ends that access. Restoring the
account does not restore a session that was already rejected; sign in again.

Use **Sign out my other sessions** to retain the current authorized session and
revoke the other saved sessions. This action also requires recent owner
confirmation. The session list shows creation and expiry times without exposing
session tokens. A change of browser user-agent can require a fresh sign-in.

Persistent attempt limits apply to passwords, second-step challenges,
reauthentication and enrollment. Each scope permits eight attempts per account
and 40 per connecting peer within a 15-minute window. Successful authentication
clears its account bucket; the peer limit remains. Unknown accounts also consume
attempts, and a full limiter refuses additional identities instead of discarding
existing limits. Repeated rejected attempts should be followed by a wait rather
than repeated submissions. The connecting peer is the actual `REMOTE_ADDR`;
untrusted forwarded headers cannot change this identity.

## Security activity and owner alerts

**Security activity** displays the latest 100 security events from a bounded
1,000-event security journal. Events include sign-in, new-client sign-in,
authenticator changes, factor use, session revocation and sign-out. The separate
administrative-action table shows saved audit entries. These views omit raw
passwords, setup keys, recovery codes and session tokens.

A new client after an earlier recognized client generates a security event and
requests a generic owner notification. The client identity uses keyed
fingerprints; the alert does not contain a password, code or private member
content. Delivery requires notification automation, an allowed owner-alert type,
a configured mail transport and a running worker. See
[Notifications](NOTIFICATIONS.md) and [Mail provider events](MAIL_PROVIDER_EVENTS.md).
A queued alert or a local SMTP capture does not prove production inbox delivery.

## Recovery and operating checks

Keep recovery codes and the installation's protected backup key available to the
authorized operator through separate private storage. Encrypted records depend
on the preserved installation keys; replacing those keys is not a sign-in repair.
If both the authenticator and all recovery codes are lost, the normal web flow
cannot remove the factor without identity confirmation. Resolve that condition
through the authorized installation operator and a controlled recovery process.
Do not edit the public runtime to grant a session or expose stored security
records.

Before putting a changed installation into service, verify actual owner sign-in,
one unused recovery code, explicit session revocation, a rejected stale session
and reauthentication for a protected action. Verify owner-alert delivery using
approved recipients on the configured service. The source and package gates
exercise these boundaries on disposable file and SQL installations; they do not
establish access to, configuration of or delivery from the customer host.

## Retained acceptance

```sh
php tests/Feature/admin_security.php
php tests/Feature/repair_access.php
python3 tests/Http/admin_security_http.py --mode both
python3 tests/Browser/admin_security_playwright.py --mode both
python3 tests/Browser/installed_update_playwright.py
```

SQL acceptance requires an explicitly owned disposable test schema. The tests
exercise actual extracted customer packages, real password/factor forms,
revocation, account changes, delayed reads, private error handling and desktop and
mobile controls. Final release evidence must bind those results to the committed
source and delivered ZIPs, alongside the retained upgrade and Apache form gates.
