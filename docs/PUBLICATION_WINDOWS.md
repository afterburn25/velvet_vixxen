# Publication windows and saved promotion editors

**Available in verified 0.8.5 / Build 20261020.6.** [PR #21](https://github.com/afterburn25/velvet_vixxen/pull/21) is merged; all seven required CI jobs passed and saved downloads match the gated artifact literally. [The numbered release record](releases/0.8.5-publication-windows.md) contains exact evidence. The broader marketing roadmap remains open.

## Open and edit saved content

In **Marketing**, use **Posts**, **Banners**, or the landing-page section of **Campaigns**. Each saved item now has an **Edit** link. Saving reopens that same item, with its content, visibility setting and dates. Reloading the successful save does not create a duplicate.

Posts and landing pages keep their existing permalink when edited. A new item can derive its slug from its title, or you can enter a lowercase slug using letters, numbers and separating hyphens. New duplicate slugs are rejected. Create a separate item when you need a different address.

Text remains plain text. The editor preserves paragraph breaks in post and landing-page bodies. Banner links and landing-page buttons accept a site path or an HTTP or HTTPS address.

## Set dates in the site timezone

The editor displays the configured site timezone beside the date controls. New dates are interpreted in that timezone and saved as absolute UTC instants. Existing valid absolute timestamps retain their instant; supported older local date formats use the site timezone. Older date-only values mean local midnight.

For a **post**, choose `draft` or `published`:

- A draft stays hidden regardless of its date.
- A published post becomes visible at its publication time, including that exact instant.
- A published post with a blank date is visible immediately. It does not display a fictitious 1970 publication date.
- **Move to draft** withdraws an existing post without losing its content or permalink. **Publish** honors its saved publication date, so a future post remains scheduled.

For a **banner or landing page**, enable it and optionally set a start and an end:

- A blank start leaves the beginning open; a blank end leaves the ending open.
- The start is included and the end is excluded. A window from 09:00 to 17:00 is visible at 09:00 and stops being visible at 17:00.
- When both are present, the end must be strictly after the start. Equal or reversed dates are rejected.
- **Disable** withdraws the saved item without deleting its text, address or dates. Enabling it again honors the same window.

The list reports **Draft**, **Disabled**, **Scheduled**, **Active**, **Ended**, or **Needs review**. Visibility is evaluated on each fresh public request; this feature requires no publication worker or cron job. Reading a public page does not rewrite stored publication status or campaign work. Media scheduling and queued email delivery retain their separate workflows.

## Understand which banner appears

Saved banner order is preserved: the **first active banner eligible for the current visitor** is shown. Editing a banner does not move it to a different position. The owner list distinguishes **Active · shown now to guests**, **Active · waiting behind the shown banner for guests** and **Active · available to eligible members in this order**. Check guest and eligible member views when their access policies differ.

To show the next eligible banner, disable the one shown to that visitor or adjust its dates. An expired, disabled, future, invalid or access-restricted banner does not block a later banner for an eligible visitor. A visitor who has dismissed the promotion may still have that dismissal remembered by their browser.

## Repair an invalid old date

An invalid nonempty saved date holds the publication out of public view. The owner editor shows the original value as text with a repair notice; it does not silently replace it with a blank, unrestricted date.

Enter a real local date and time using `YYYY-MM-DDTHH:MM`, optionally with seconds, or deliberately clear the value to use an open bound. Relative phrases, impossible calendar dates and times skipped by a daylight-saving transition are rejected. An unchanged valid time retains its original stored instant, including an explicit offset during a repeated daylight-saving hour.

You can disable a record before repairing its date. Enabling an invalid record is held until the dates are corrected. A rejected edit leaves the saved content unchanged.

## If another edit changed the item

Each editor and visibility control carries the revision of the item it displayed. If another request changes that item, a stale save, disable, publish or delete is rejected. The failed editor keeps your attempted text and old revision so it cannot silently overwrite the newer version. Reload the saved item and reapply the changes you still want.

Edits to other publications and new analytics events do not invalidate your form. A change to the configured site timezone does require reloading, because it changes the meaning of local date inputs.

## Scope and verification

The original 0.8.5 increment used the protected marketing store and did not switch marketing data to SQL. In 0.8.6, the separate owner-reviewed [Marketing Storage](MARKETING_STORAGE.md) move adds per-record MySQL persistence. Publication edits retain the same behavior on both backends and preserve newsletter consent, saved audiences, queued addresses, frozen campaign content and unrelated marketing settings. In 0.8.7, [editorial access and private saved-owner previews](EDITORIAL_ACCESS.md) add Public/Members/Selected tiers policies and saved preview controls for posts, banners and landing pages. In 0.8.8, [editorial metadata](EDITORIAL_METADATA.md) adds post tags/public bylines/ordered related selections and featured images/SEO for posts and landing pages. Separately scoped editorial history/restore, calendar and post expiry remain next; scene scheduling, referral attribution and provider connections also remain open.

Focused acceptance uses disposable stores and extracted customer packages with email disabled. It checks exact date boundaries and site-timezone conversion, valid legacy date compatibility, invalid-date visibility holds, stable identities and links, concurrent stale edits, owner and CSRF checks, banner precedence, and real desktop/mobile form interaction. Integrated release evidence is recorded in [the 0.8.5 release record](releases/0.8.5-publication-windows.md).
