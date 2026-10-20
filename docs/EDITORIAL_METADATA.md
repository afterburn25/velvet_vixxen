# Editorial Metadata

## Release status

**0.8.8 / Build 20261020.9 is verified, merged and delivered.** [PR #24](https://github.com/afterburn25/velvet_vixxen/pull/24) and all seven [required CI jobs](https://github.com/afterburn25/velvet_vixxen/actions/runs/39492640091) passed their recorded release gates. The four frozen customer files match both the gated artifact and retrieved saved files literally. See [the complete release record](releases/0.8.8-editorial-metadata.md), including exact identities and the recovered local-summary limitation. The affected customer host remains unverified.

## Owner controls

Open an existing post in **Marketing → Posts**, or create a post, and use its metadata controls. Posts support an author display name, comma-separated tags, a featured image with alternative text, a search title and description, and up to six related posts in a chosen order. Landing-page editors support the same featured-image and search fields. Banners retain their existing controls.

The author field is text deliberately entered for publication. It does not copy an owner's account name, email or other private profile fields. Tags supplement the existing Category field. Metadata does not change a saved post's ID, permalink, position, access policy or publication schedule.

| Field | Accepted value |
| --- | --- |
| Author display name | Optional text, up to 120 Unicode characters. |
| Tags | Up to 30 comma-separated tags, each up to 64 Unicode characters. Duplicate tags are combined without changing the first spelling or selected order. |
| Featured image | An existing available public raster image from the site's saved Media Library. |
| Image description | Optional alternative text, up to 240 Unicode characters. |
| Search title | Optional text, up to 120 Unicode characters. |
| Search description | Optional text, up to 320 Unicode characters. |
| Related posts | Up to six distinct saved posts, in the order selected. A post cannot select itself. |

These are application input limits, not a promise about how a search engine will display a result. Empty optional fields use the page's ordinary presentation. The editor's search-appearance sample is a preview of the entered text; search engines may choose a different result presentation.

## Featured images

Upload or manage the image through the existing media tools, then choose it in the publication editor. Metadata stores the saved asset ID and alternative text; it does not accept an external image URL or copy private media into a public location.

The selected asset is checked when explicitly saved and resolved again when content is read. Protected originals stay ineligible even if they have a public thumbnail. Non-image files, missing files, unsafe paths and linked files are excluded. An unavailable image is omitted from public rendering; the saved reference is not silently replaced with another image. Legacy public images without a MIME label are accepted only when the actual local file is a supported image.

Featured images are public assets. Selecting an image for a member-only post does not convert that image into protected media. Use the existing member-media controls for private or paid originals.

## Related content and access

The public page checks the source post and each related post against current publication timing and Public / Members / Selected tiers access before exposing the related title, excerpt, byline, tags, image or link. Draft, future, unavailable and inaccessible related posts are omitted. Changes to the saved posts or a visitor's membership take effect on subsequent requests.

Related posts are explicit selections. There is no automatic recommendation, advertising or third-party tracking service. Selecting a draft post does not publish it.

## Saved previews and search fields

**Preview Saved** continues to show the last saved content. Unsaved editor changes are not included. Metadata and related content are prepared before the final active-owner check, and related links in the private preview are inert. The preview retains private/no-store caching, noindex/nofollow and no-referrer behavior. Previewing does not publish, queue email, send email or record marketing events.

Search fields are output only after a public request has passed the publication's timing and access checks. A denied request keeps the existing generic response and does not disclose private search text. Empty or malformed legacy metadata has safe display fallbacks; the owner can correct fields through the editor.

## Storage and preservation

Metadata is stored with the existing publication record. File and active MySQL marketing storage use the same validation and visibility rules. No database migration or storage cutover is added by this increment.

Explicit submissions validate their types, limits and current references. An older caller that omits metadata fields preserves their saved values. The existing locked revision check and fresh owner authorization remain required, including after an image-catalogue read waits. Failed validation or stale forms leave the record and unrelated marketing state unchanged.

The feature does not change newsletter consent, suppressions, audiences, frozen messages, delivery queues or outcomes. It does not implement editorial history/restore, an editorial calendar or post expiry; those remain the next separately scoped roadmap work.

## Verification

The metadata source suite passed 173 assertions on PHP 8.2 and PHP 8.3. Both real-MySQL jobs passed 1308 metadata HTTP checks at each root/nested path, including current-owner refusal after actual media reads waited. The actual Fresh package passed four file/MySQL root-desktop/nested-mobile browser cases, with 20 decoded screenshots, fitting geometry, no JavaScript errors and no SMTP activity. The two MySQL browser cases include four blocked media-catalogue owner-revocation scenarios. All retained source, security, ownership, persistent-OPcache, MySQL, installer, HTTP and browser gates passed.

[The release record](releases/0.8.8-editorial-metadata.md) records the exact observed runtimes, source/CI/merge trees, current artifact identities and literal saved-file comparisons. The local summary explicitly discloses loss of its temporary raw reports during workspace recovery; the final committed-source GitHub run supplies independent durable logs and artifacts. [Update verification](UPDATE_VERIFICATION.md) gives owner-facing checks after applying the complete Upgrade. These release tests do not establish actual customer-host installation or production email delivery.
