# Publishing calendar and post expiration

## Open the calendar

In the owner admin, open **Marketing & Publishing → Calendar**. Choose a month and content type, or use **Previous**, **This month** and **Next**. Select a day with events to jump to its agenda. Every date is displayed in the configured site timezone, shown above the calendar. The agenda includes seconds and the timezone abbreviation so repeated daylight-saving times can be distinguished.

The calendar combines saved dates for posts, banners, landing pages, galleries, videos, albums, tracks, playlists and email campaigns. It also shows the retained publication history for Hero and Visual Footer scenes. Each entry includes its current saved state and a link to the appropriate existing editor when its identity is valid. The **Without a date or needing review** section keeps undated drafts and malformed records visible for review, regardless of the selected month. Calendar months are limited to January 1970 through December 9998.

## What a date means

| Content | Dates displayed | Publication behavior |
| --- | --- | --- |
| Posts | Publication and optional expiration | A published post is publicly eligible from its publication instant until, but excluding, expiration; access rules still apply. Drafts stay private. |
| Banners and landing pages | Start and end | Existing enabled state, publication windows and access rules still apply. |
| Albums, tracks, playlists, galleries and videos | Saved publication and scheduled dates | The calendar reports saved/current state. Scheduled media still uses the existing media publication runner; opening the calendar does not run it. |
| Email campaigns | Saved scheduled dates | Draft, paused and queued states remain distinct. A date does not establish that delivery occurred. Opening the calendar does not queue or send mail. |
| Hero and Visual Footer | Retained publication timestamps; undated draft indicators | These are existing publication-history entries, limited to the newest 20 per section. Automatic scene activation or expiration is separate unfinished work. |

Status describes the item **now**, including a draft or paused item with a future date. It is not a reconstruction of what its status was on a historical calendar day. Invalid or conflicting dates are marked for review instead of silently appearing as valid schedules. Separate entries can describe publication and expiration for one item.

## Set an expiration on a post

1. Open **Marketing & Publishing → Posts**, then edit the saved post.
2. Enter **Expires at** in the site timezone. Leave it blank for no expiration.
3. If the post also has a publication date, expiration must be later than publication.
4. Save. A draft remains a draft; choose the ordinary publication controls when it is ready.

At the exact expiration instant the post becomes **Expired** and is excluded from public listings, direct public pages and related-post suggestions. Its saved content and history remain available to the active owner. This visibility change is evaluated when a page is requested, so it does not require a background job or permanently delete the post. Existing Public/Members/Selected tiers restrictions continue to apply before expiration.

Impossible dates, relative text, skipped daylight-saving times and equal or reversed bounds are rejected. An unchanged saved time keeps its original instant, including the original offset during a repeated hour. Older posts without an expiration continue without one. An older form that omits the field preserves the existing value; deliberately clearing the field removes the bound.

## History and safe review

Post history includes expiration. Reviewing and restoring a version from before expiration was set does not inherit a newer expiration. A restore always leaves the post **Draft**; review its dates and publish deliberately. Current owner authorization, CSRF protection and saved revision checks apply to edits and restores on both file and MySQL storage.

The calendar is an owner-only read-only page with private, non-cacheable responses. It reads existing data without creating marketing storage, running add-ons, creating analytics events, invoking publication workers or sending email. Access is checked again after data reads, including a read that waited on a database lock. The view contains calendar metadata rather than post bodies, campaign recipients or private media paths. Normal admin session/navigation preferences remain ordinary session state.

## Upgrade and scope

This feature belongs to **0.8.13 / Build 20261021.2**. Install the complete Upgrade on an existing 0.8.x site and follow [Update Verification](UPDATE_VERIFICATION.md). No new database migration is required. File storage and the existing explicit owner-reviewed MySQL marketing move retain their respective behavior.

This increment does not add automatic Composer scene schedules, deleted-content recovery, historical state reconstruction, drag-to-reschedule actions, production-email certification or large-dataset reporting guarantees. See [the current release record](releases/0.8.13-editorial-calendar.md), [publication windows](PUBLICATION_WINDOWS.md) and [editorial history](EDITORIAL_HISTORY.md).
