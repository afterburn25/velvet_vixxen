# Owner Email & SMTP Settings

Build **20261020.2** adds **System → Email & SMTP** to the existing owner dashboard at `admin/?page=mail-settings`. This connection is shared by account email, booking notifications and configured newsletter campaign delivery. Each feature retains its own notification or delivery preferences.

This release is verified, merged into `main` through [PR #17](https://github.com/afterburn25/velvet_vixxen/pull/17), and delivered. All seven required CI jobs passed, and the four ZIPs plus separate manifest were saved, retrieved and compared byte for byte with the gated artifact. [The release record](releases/0.8.1-owner-mail-settings.md) gives the exact evidence. The affected customer's host and a production email provider have not been verified.

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

For an installed 0.8.x site, use the complete **Build-20261020.2 Upgrade** and follow [Update Verification](UPDATE_VERIFICATION.md): preserve installed configuration and content, verify uploaded runtime files, refresh the application's PHP when needed, and verify the running build. Do not run a Fresh installer over an existing installation.

The **11-file Installer Database Repair** is for unfinished setup. Its filename and `repair_build_id` identify Build 20261020.2's installer repair revision; applying that overlay does not install the owner settings feature or advance an installed site's runtime build. See [the installer repair guide](INSTALLER_DATABASE_REPAIR.md).

## Maintainer storage boundary

Mail settings retain compatibility with the existing protected configuration file and backup format. This release does **not** migrate SMTP credentials to encrypted storage. Passwords stay out of HTML snapshots, ordinary audit metadata, customer ZIPs and source control. Treat the installed configuration and its backups as private, and preserve their existing access protections.
