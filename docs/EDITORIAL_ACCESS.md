# Editorial access and saved-owner previews

## Release status

**Available in verified 0.8.7 / Build 20261020.8.** [PR #23](https://github.com/afterburn25/velvet_vixxen/pull/23) is merged; all seven required CI jobs passed and the saved downloads match the gated artifact literally. [The release record](releases/0.8.7-editorial-access.md) contains the exact evidence.

## Choose who can see a publication

The existing Marketing editors for blog posts, banners and landing pages gain an access selector. The saved rule applies to the whole publication, including its title, excerpt, body and promotion link.

| Access | Who can see it when its publication window is active |
| --- | --- |
| Public | Any visitor. |
| Members | A currently active, email-verified account with a valid signed-in device session. Free accounts qualify. |
| Selected tiers | A qualifying member with a currently eligible subscription to at least one explicitly selected, enabled membership plan. |

Selected tiers is an allowlist. A higher-priced plan does not automatically include another plan's editorial content. Individual media purchases and media entitlements do not grant editorial access. An empty tier selection is invalid. If a saved selected plan is removed, disabled or malformed, the publication is held until the owner deliberately repairs its selection; it does not silently become public or broaden to all paid members.

Current active and trial subscriptions can qualify. A past-due subscription can qualify only during its recorded grace period. Cancelled, paused or expired subscriptions do not qualify. Account deactivation, loss of email verification, device revocation and changed plan/subscription state are checked again on the next request.

## Publication dates still apply

Access is evaluated together with the [existing publication rules](PUBLICATION_WINDOWS.md). A published post is not public before its publication date. Draft posts, disabled banners/pages, future windows, ended windows and malformed stored dates remain unavailable on public routes, including for an otherwise eligible member. This increment does not add post expiry or a Composer scheduling worker.

Hidden posts are omitted before listing limits are applied. Restricted titles, excerpts and page metadata are not included in a denial response. Detail and campaign routes use their ordinary generic not-found response when the viewer is ineligible. Banner selection keeps saved order and chooses the first currently eligible banner. Viewing a denied campaign does not create a campaign-view event.

Responses containing viewer-dependent editorial content use private, no-store caching headers before HTML output. The same policy covers public denial responses and existing pages that render marketing banners, so a shared cache is not instructed to reuse a member's editorial response for another visitor.

## Preview saved content as the owner

Use **Preview Saved** in the publication editor or list to open a separate saved-content preview inside the existing owner area. It can display a saved draft, scheduled publication or restricted publication without making the public URL available. Save edits first: the preview reads the stored record, not unsaved form text. **Back to Edit** returns to that publication's editor. Content buttons and promotion links are inert in the preview.

Each preview request rechecks the currently saved active owner. A prior owner session does not retain preview access after demotion or deactivation. Ordinary members and staff do not receive this bypass, and adding an owner/preview flag to a public URL grants no access.

Preview responses are private and noncacheable, request no indexing/following, and suppress referrer disclosure. They do not publish or modify the saved content, record marketing page/referral/campaign events, send mail, or expose a share token. A missing record or unauthorized request receives a generic response. The approved site colors and responsive content rendering remain in use.

## Editing and compatibility

Access changes use the existing owner POST, CSRF and saved revision checks. The owner and selected plans are revalidated at the locked save boundary. A stale editor cannot overwrite a newer publication. Invalid access form shapes or unsupported modes are rejected instead of normalized to Public.

Previously saved records without an access field retain their legacy public behavior. Editing unrelated fields through an older caller that omits access preserves the stored access value, including the absence of a legacy value. A malformed explicit saved restriction remains held until deliberately repaired; opening an editor does not reset it. An owner must repair that policy before saving through the editor, while safe withdrawal or deletion remains available.

The same rules apply to the protected file backend and the explicitly activated MySQL marketing backend introduced in 0.8.6. IDs, stored order, immutable existing permalinks, publication windows and revision semantics remain intact. Unrelated newsletter consent, suppressions, saved audiences, frozen campaign text, recipients, delivery results and other marketing fields are preserved. This feature requires no new database migration and does not switch the active backend.

## Required acceptance

The new source suite is mandatory in PHP 8.2 and 8.3. Both MySQL jobs run the actual-package HTTP access and preview cases at root and nested paths. The browser job additionally runs actual file/MySQL packages through desktop and mobile owner flows. Tests cover member and subscription boundaries, invalid policies and unavailable plans, fresh state, timing, generic denial, filtering before disclosure, caching, current-owner preview authorization, stale edits, request validation and preserved data. Preview checks assert no marketing events or SMTP messages.

Every preceding source, installer, ownership, persistent OPcache, MySQL, HTTP, browser and package gate remains required. The current release record supplies the executed results and exact source, CI, merge and saved-download identities.

## Remaining roadmap

The bounded [0.8.8 editorial metadata](EDITORIAL_METADATA.md) increment adds post tags, explicit public bylines and ordered related selections, plus featured images and SEO controls for posts and landing pages. Editorial version history/restore, a publishing calendar and post expiry remain separately scoped next work. Scene activation/expiry, durable referral attribution, analytics operations, campaign message previews/test-send and templates/tracking, missing notification workflows and provider hooks remain under the broader roadmap. Actual customer-host verification and production email certification require their own evidence.
