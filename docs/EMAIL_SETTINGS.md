# Owner Email & SMTP Settings

**Current runtime: 0.8.5 / Build 20261020.6.** Open **System → Email & SMTP** at `admin/?page=mail-settings` to manage the shared connection used by account email, booking notifications and newsletter campaigns. Each feature retains its own notification or delivery preferences. Private-reply alerts use the generic notices described below.

The editor was introduced in the verified Build 20261020.2 owner-mail release through [PR #17](https://github.com/afterburn25/velvet_vixxen/pull/17); [its release record](releases/0.8.1-owner-mail-settings.md) preserves that historical evidence. [The current 0.8.5 release record](releases/0.8.5-publication-windows.md) identifies the latest verified source and downloads. The affected customer host and production email service remain unverified.

## Private-reply email alerts

When the owner sends a member-facing reply, email contains a generic alert and an authenticated conversation link. The private conversation subject, message body and attachment names remain out of the email; the full reply remains in the protected on-site conversation. A guest booking notice links to the existing public booking form for follow-up and does not grant anonymous conversation access. Internal notes send no email. These notices use the installation’s configured mail transport.

## Save a connection

1. Sign in as the active owner, open **System**, then **Email & SMTP**.
2. Choose the delivery method: **Disabled**, **SMTP**, or **PHP mail**.
3. Set the sender name and, when needed, an explicit **From email**. For SMTP, enter the host, port, security mode, username and password supplied by the email provider.
4. Click **Save Email Settings**. Saving updates the protected site configuration; it does not connect to the provider or send a message.
5. Review the saved status, then use **Send Test Email** to test those saved settings.

| Setting | Behavior |
| --- | --- |
| Disabled | Keeps email optional and stops this mail transport from sending. Newsletter signup and campaign delivery have separate controls. |
| SMTP | Uses the saved server details. Enter a hostname or IP address, without a URL scheme, path or appended port. |
| PHP mail | Uses the hosting server's PHP mail transport. Choose it only when the host supports that service. |
| Connection security | Match the provider's required setting: STARTTLS, TLS from connection start, or None. The form shows common port hints; use the provider's actual port. |
| From email | A valid explicit address takes precedence. A blank value resolves to an SMTP username that is an email address, then the owner email fallback. Use an address the provider permits. |
| SMTP password | A blank field keeps the saved password. Enter a replacement to change it. The page never displays the saved value. |
| Remove the saved SMTP password | Explicitly clears the saved password when saved. Use this instead of assuming a blank field removes it. |

Ordinary pasted email forms are normalized, while carriage returns, line feeds and unsafe control characters are rejected. A rejected form leaves the saved configuration unchanged. If another request changes the configuration, reload the page before saving again; the stale form cannot overwrite the newer state.

## Test the saved settings

The test sends one fixed plain-text diagnostic message to the **current owner account email shown on the page**. It is not a campaign and does not add a member message or notification to the outbox. The recipient and body cannot be changed through form input.

Save edits before testing. The test button is disabled while the form has unsaved changes, and the server independently verifies the saved configuration revision. A test submission is single use, expires after 30 minutes and has a 60-second per-session cooldown. Refreshing a result page does not send another message. The active owner and unchanged configuration are checked again before the SMTP message body is transmitted.

**Test accepted** means the selected transport accepted the message. Check the recipient inbox to confirm arrival. An explicit rejection reports a safe failure; an unknown result means the final delivery acknowledgement was not established and the application does not automatically retry. Check the provider and inbox before deliberately testing again. Saving settings clears the previous result, and a result from an older configuration is hidden.

## Related controls and troubleshooting

Use **Newsletter campaigns** to control campaigns and their delivery switch. Use **Communication settings** for feature notification preferences. Enabling a connection does not itself enqueue or send a campaign, and disabling newsletter signup is separate from disabling the mail connection.

If the connection is disabled or incomplete, the test panel explains what needs attention. For a provider rejection, check the host, port, security mode, permitted sender and credentials. Raw provider response text and saved passwords are not displayed or included in ordinary audit metadata. Configuration read/write problems require correcting the site's file ownership and permissions; never use `0777` or replace an installed configuration with a fresh one.

## Install or update

For an installed 0.8.x site, use the complete **0.8.5 / Build 20261020.6 Upgrade** and follow [Update Verification](UPDATE_VERIFICATION.md): preserve installed configuration and content, verify uploaded files, refresh running PHP and require the current build. Fresh setup is for a new installation.

The unchanged **11-file 0.8.1 / repair Build 20261020.3 Installer Database Repair** is for unfinished 0.8.1 setup. It retains its saved identity and bytes; applying it does not install the owner settings feature or advance an installed runtime build. See [the installer repair guide](INSTALLER_DATABASE_REPAIR.md).

## Maintainer storage boundary

Mail settings retain compatibility with the existing protected configuration file and backup format. This release does **not** migrate SMTP credentials to encrypted storage. Passwords stay out of HTML snapshots, ordinary audit metadata, customer ZIPs and source control. Treat the installed configuration and its backups as private, and preserve their existing access protections.
