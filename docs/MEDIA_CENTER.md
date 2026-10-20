# Media Center — Velvet Vixxen 0.8.2

Version **0.8.2**, Build **20261020.3**, adds editable photo captions and tags, exact gallery removal and ordering, ordered playlist editing, saved scheduling dates and a bounded publishing runner. Use the complete Upgrade for an existing installation, then follow [Update Verification](UPDATE_VERIFICATION.md) to check the uploaded files and refresh the running PHP application.

## Edit a gallery

Open **Content & Media → Photo Galleries** as the active owner and choose the gallery's **Edit** action. The editor shows the saved photographs in their current order.

1. Enter a **Caption** for any photograph. A caption belongs to that photograph's entry in this gallery; the public gallery/lightbox uses the saved caption.
2. Enter comma-separated **Tags** for a photograph. Tags belong to the photo asset, so a tag change also appears wherever that same asset is edited in another gallery. Use up to 30 tags, each no longer than 64 characters. Repeated tags are combined without regard to letter case. Leaving an existing field empty deliberately clears it.
3. Use **Move photo earlier** and **Move photo later** on desktop or mobile. Desktop drag ordering is also available. The numbered order shown in the editor is the order saved and displayed publicly.
4. Choose **Remove** to leave a photograph out of this gallery. **Undo removal** restores the most recently removed photograph and its unsaved caption/tag fields. Removal takes effect when you save; the original uploaded file remains available in the Media Library.
5. Review **Free Preview Photos**. The first specified number of photographs in the new order form the free preview. Moving or removing photographs can therefore change which photographs are free to view.
6. Click **Save Gallery**, then confirm the editor reopens with the intended photographs, captions, tags and order. New uploads are appended; save them before adding their caption and tag fields.

A removed gallery cover falls back to the first retained photograph. Keep at least one photograph, or upload a replacement before saving; a completely empty gallery is rejected without changing the saved gallery. The editor accepts at most 200 photographs and 2,000 characters per caption.

The form includes a revision of the saved gallery. If another tab, another owner action or the publishing runner changes that gallery before the form is saved, the old form is rejected without replacing the newer gallery. Use **Reload latest gallery** and reapply the intended changes. Changing a photo's shared tags in another gallery also makes an older form for that photo stale.

## Edit a playlist

Open **Content & Media → Music** and choose a playlist's **Edit** action. Selected tracks appear first in their saved playback order. Select or clear tracks and use **Move track earlier** / **Move track later** to arrange the selected tracks. Click **Save Playlist** and confirm the editor reopens with the same order.

Review the playlist's **Status**, **Access**, **Publish Date** and **Scheduled Date**. Both dates remain saved across ordinary edits. A deliberately empty date field clears that date; the scheduled status requires a scheduled date. An older playlist form is rejected after a newer save or publishing transition, just as with galleries.

Public playlist playback follows the saved order and the playlist's access setting. A visitor must meet the playlist's access requirement before its playback queue is offered. Each track also retains its own access and preview rules. Draft, future and otherwise unavailable tracks are excluded from the public queue.

## Schedule publication

Date fields use the installation's configured timezone, which is shown next to the scheduling controls. Choose **Scheduled** and a valid scheduled date, then save. The item remains unavailable until that date is due and a publishing run handles it. If a later publish date is also saved, that date holds publication until it is due too.

For a manual run, open a Media Center page as the active owner and click **Run Due Media Now**. A run can publish up to 100 due albums, tracks, playlists, galleries and videos. It preserves access settings and saved dates, skips invalid legacy schedules, and reports how many items were published. Repeating the action does not publish the same item twice. Opening an ordinary public or admin page does not itself publish scheduled work.

For automatic operation, add a hosting scheduler/cron entry that runs once a minute from the website directory:

```bash
php app/Console/run_media.php --limit=100
```

Use the hosting account's PHP executable and this installation's absolute script path when its scheduler does not offer a working-directory setting. The command must be run by the hosting scheduler; saving a date does not create a cron entry. This command handles media publication. Campaign delivery has its existing separate command documented in [Update Verification](UPDATE_VERIFICATION.md).

## Release identity

The visible release number advances with each completed release. Build IDs remain additional diagnostics. Version 0.8.2 is the Media Center increment; the separate privacy/protection/operations milestone remains on the [roadmap](ROADMAP.md).

The complete Fresh and Upgrade packages identify **0.8.2 / Build 20261020.3**. The separate unfinished-installer repair is explicitly for an incomplete **0.8.1** installation and does not advance that installation's runtime version. The former focused Media/Composer overlay is retired for this new release; installed sites use the complete Upgrade.
