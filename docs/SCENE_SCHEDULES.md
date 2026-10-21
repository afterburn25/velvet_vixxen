# Scheduled Hero and Visual Footer scenes

Version 0.8.14 adds deliberate scheduling for saved Visual Composer scenes. The scheduling page is under **Site & Design → Scene schedules**. Choose **Homepage Hero** or **Visual Footer**; each section can have one open schedule at a time.

## Schedule a scene

1. Compose the scene using the existing independent backgrounds, photos, buttons, depth and foreground controls. Review its Desktop, Tablet and Mobile layouts.
2. Click **Schedule** in Composer. This saves both current Hero and Visual Footer drafts and opens the scheduling page for the selected section. Alternatively, use **Save Draft**, then open **Scene schedules** from the sidebar.
3. Check the saved draft title. Enter **Starts at** in the displayed site timezone.
4. Optionally fill **Ends at (optional)** and choose **After the end time**: **Keep this scene live** or **Restore the previous scene**.
5. Click **Schedule saved draft**. The page displays the saved schedule and its status. The publishing calendar also shows its start and end dates.

Scheduling takes a fixed copy of the saved scene, including all device layouts, separate photo layers, depth, foreground outlines and live buttons. Later draft edits do not change that copy. Save, cancel and create a new schedule to choose a different composition or dates. Opening the editor or calendar does not execute a scheduled change.

The start is required. An end must be later than the start. **Restore the previous scene** requires an end time and an existing published scene. Impossible dates and local times skipped or repeated during daylight-saving changes are refused. Choose an unambiguous time. Dates are stored as absolute UTC instants and displayed in the configured site timezone; a later timezone change does not move an already saved instant.

A start in the past is allowed: it is due on the next worker check. If the complete start/end window has already passed, the runner skips that scene rather than briefly displaying an expired promotion.

## Run due changes

**Run due scenes now** checks both Hero and Visual Footer. It is an explicit owner action protected by the normal sign-in and form-token checks. It does not publish future schedules.

For unattended operation, configure a cron job in the hosting control panel to run every minute:

```sh
php /path/to/website/app/Console/run_scenes.php --limit=10
```

Use the host's PHP executable and the full installed website directory. The worker requires an installed application and supports `--limit=1` through `--limit=25`. It is a command-line entry point, not a public URL. This release does not create a hosting cron job or verify that one exists on the customer's server.

Changes take effect when the worker runs. With a once-per-minute cron, a due change normally waits until the next check. If cron is paused, the current live scene remains until a later check. The page's saved states distinguish a scheduled date from a change that has actually run. A claim left by an interrupted process becomes recoverable after its 60-second lease; a later run either completes the still-applicable change or records why it was skipped, superseded or held.

The JSON command result reports activations, expirations, skips, held work, superseded work, recovered claims and busy sections. A busy section means another file-store update still held the lock after a bounded wait; its schedule stays intact for the next check. The owner page explains: “Another update is finishing. Check due scenes again in a moment.” Exit code 0 indicates the bounded run completed without held work; code 2 indicates a held schedule needing owner review; code 1 indicates an invalid command or storage failure. Production monitoring should retain failed-run output privately.

## End, cancellation and manual changes

| Choice or action | Result |
| --- | --- |
| No end time | The runner publishes the scene once, records completion, and leaves it live until a later publication. |
| End time + Keep this scene live | The runner records the schedule's end and keeps that scene live. |
| End time + Restore the previous scene | The runner restores the scene that preceded the scheduled publication, provided the scheduled scene is still the current publication. |
| **Cancel schedule** before its start | The scheduled copy never goes live. The current scene is preserved. |
| **Cancel return to previous scene** on an active return schedule, or **Cancel schedule** on an active keep schedule | The current live scene stays in place; its automatic return is cancelled. |
| Publish or restore manually in Composer | The manual publication supersedes that section's pending activation or automatic return. |
| Edit or save a draft | The current publication and scheduled copy are preserved. |

Automatic activation and return preserve the current editor draft. Returning to the previous live scene does not discard an unfinished newer draft. Published changes remain available through the existing Composer version history; the scheduling page retains the latest 20 completed, cancelled, skipped, superseded or held schedule records for each section.

## Review a problem

A stale form is rejected if the saved draft, live scene, schedule or site timezone changed after it opened. Attempted dates remain visible; use **Reload saved scene**, review the current draft and try again. One open schedule per section avoids overlapping return rules. Cancel the existing schedule before creating another.

The runner checks the current saved authority of the owner who created the schedule. A revoked or deactivated creator cannot cause a later automatic publication. Invalid saved schedule data is held without replacing the live scene. Storage failures stop processing rather than treating an archived or missing data source as current.

## Storage and update behavior

Both file and MySQL installations use the existing Store and settings storage. No database migration or marketing-storage move is required. A section lock serializes manual publications, schedule creation/cancellation and automatic changes. A durable processing claim precedes execution; publication, scene history and final schedule state then commit together. A repeated or concurrent runner does not publish the same transition twice.

The scheduler uses the existing approved application directories. The complete Upgrade preserves configuration, storage, uploads, installation identity, account records and keys. The separate unfinished-installer repair is unchanged and is not the package for an installed site.

See [the release record](releases/0.8.14-scene-schedules.md), [Visual Composer](VISUAL_COMPOSER.md), [the publishing calendar](EDITORIAL_CALENDAR.md) and [update verification](UPDATE_VERIFICATION.md).
