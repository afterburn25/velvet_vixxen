# Newsletter audiences

**Available in verified 0.8.4 / Build 20261020.5.** [PR #20](https://github.com/afterburn25/velvet_vixxen/pull/20) is merged; all seven required CI jobs passed and saved downloads match the gated artifact literally. [The numbered release record](releases/0.8.4-newsletter-audiences.md) contains exact evidence. The broader marketing roadmap remains open.

## Create a saved audience

Open **Marketing & Publishing → Audiences** in the owner administration area. An active owner can create, edit, disable or remove saved newsletter audiences.

Give the audience a name, then choose at least one of these criteria:

| Criterion | Meaning |
| --- | --- |
| Exact signup sources | One saved newsletter source value per line. A contact can match any source in the list. Values are case sensitive. Open **Saved signup sources** to see the values currently recorded on the site. |
| Confirmed on or after | Includes contacts whose latest newsletter confirmation happened at or after this moment. |
| Confirmed before | Includes contacts whose latest newsletter confirmation happened before this moment. The exact ending moment is excluded. |

Every entered criterion must match. For example, two source values plus a start and end date mean either listed source **and** a confirmation inside the chosen time window. A blank source list means any saved source within the date criteria. A blank date field leaves that side of the date range open.

Dates in the form use the site timezone printed beside the fields. The application saves their equivalent UTC timestamps. Impossible dates, a start at or after the end, and nonexistent local times during a daylight-saving change are rejected.

Source describes the value saved by the newsletter signup flow. It is not verified membership, purchase or referral attribution. A later subscription request can update the saved source and requires confirmation again. The date criteria describe the latest confirmation, rather than an immutable first-signup date.

Choose **Preview Audience** to see the current matching eligible count without saving an audience, creating a campaign or queueing a message. Choose **Save Audience** to save its name, criteria and availability. Turning off **Available for new campaigns** prevents new selections while keeping the definition available to edit.

Saved audiences have edit revisions. If another tab or owner request changed a definition after the form opened, saving or removing it is rejected. Reload the current definition and review it before applying the change again.

## Select and preview a campaign audience

Open **Marketing & Publishing → Campaigns**. In the email campaign form, choose either **All eligible newsletter contacts** or an available saved audience.

Eligibility always requires the existing newsletter confirmation, current consent and suppression checks. Selecting an audience never activates a pending contact, restores revoked consent or overrides an unsubscribe or suppression record. Member registration alone does not enroll a person in this newsletter list.

**Preview Campaign Audience** shows the eligible count for the selected rules. It preserves the form inputs and saves nothing. The form keeps a revision for that exact selection and its eligible contact identities. If you then queue the campaign and the eligible recipient set changed, queueing is held until you refresh the preview. Unrelated analytics events do not invalidate that preview. Preview is optional. Queueing without a supplied preview still validates the selected audience and current recipient eligibility; reopening a form does not reuse an earlier preview.

You can save a draft without queueing it. Its selected saved-audience revision remains part of the draft. If the audience is later edited, disabled or removed before the first queue, reopen the draft and select an available audience again. A stale selection never silently becomes the entire newsletter list.

Older new-campaign submissions without audience metadata retain the all-eligible default. Editing an existing campaign through an older form that omits the audience is rejected, so an existing segmented draft cannot be reset accidentally.

### A draft can be saved while queueing is held

The **Save Campaign** action saves a valid draft before attempting its optional queue operation. If the preview has become stale, the page explains: “The campaign draft was saved, but no new recipients were queued.” It retains the saved draft ID, selected audience and entered message.

Use **Preview Campaign Audience** again, review the new count, then save with the queue option selected. This updates and queues the same draft. It does not require creating another campaign.

## Understand the first queue and later requeue

The first explicit queue saves the campaign's message text, audience label, exact criteria and selected audience revision. It also creates delivery records containing each selected contact ID and email address. The message and audience rules are then frozen, including when the first queue finds no eligible recipients.

Editing, disabling or removing the original saved audience cannot rewrite that campaign's saved rules or its existing delivery addresses. Create a new campaign to use different message text or audience rules. Use **Pause Campaign** to hold its waiting deliveries; changing an audience definition is not a campaign stop control.

New confirmations do not automatically add recipients to an existing campaign. On the saved campaign, choose **Preview Recipients** to see how many new eligible contacts match its frozen rules and how many addresses already have delivery records. Choose **Queue Recipients** to append the current new matches explicitly. A displayed preview is checked again during that operation.

Requeue uses the original saved criteria even if the named audience has since changed or been removed. It does not duplicate existing delivery addresses or reset their accepted, failed, unknown or suppressed outcomes. A later source change does not retarget a saved address; current consent and suppression are still checked by the delivery worker before transmission. Unsubscribe remains effective for waiting deliveries.

## Delivery remains a separate action

Audience preview, audience save and campaign queue do not send messages. The existing campaign worker and **Run Due Campaign Batch** action deliver eligible due work through the configured mail transport. Manage that shared connection under **System → Email & SMTP**. Disabled email or paused campaign delivery holds queued work without consuming delivery attempts.

Existing schedule, pause, retry and unknown-outcome behavior continues to apply. Accepted means the mail transport accepted the message. An unknown outcome is held because transmission might already have succeeded; it is not automatically retried.

## Limits and remaining work

- Up to 100 saved audiences, with names up to 120 characters.
- Up to 20 exact source values per audience; each criterion value can contain up to 120 characters. Duplicate sources are removed, and source matching remains case sensitive.
- Only saved source and latest confirmation date are supported criteria. Unsupported predicates and malformed form values are rejected.
- Previews return counts and audience details, without exposing a recipient list or unsubscribe tokens.
- The original 0.8.4 audience increment used the protected, locked marketing JSON store and added no database migration. In 0.8.6, [Marketing Storage](MARKETING_STORAGE.md) provides a separate, explicit owner move to per-record MySQL storage while preserving the audience and queue rules described here.

The broader marketing roadmap remains open. Public/Members/Selected tiers editorial access and private saved-owner previews are delivered in [0.8.7](EDITORIAL_ACCESS.md), and the bounded [editorial metadata](EDITORIAL_METADATA.md) controls are delivered in 0.8.8. Separately scoped editorial history/restore, calendar and post expiry are next. Membership and purchase audiences require trustworthy consent-aware joins; referral audiences require durable attribution; scene scheduling remains separate work. Both storage backends preserve the same queue snapshots, exact preview behavior, edit revisions and live consent/suppression checks.
