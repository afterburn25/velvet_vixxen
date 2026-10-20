<?php
declare(strict_types=1);
$campaignForm = $campaignForm ?? [];
$campaignLoadError = '';
if ($campaignForm === [] && isset($_GET['edit_campaign'])) {
    try {
        $editingCampaign = $marketingStore->campaign(\Velvet\Core\MarketingAudience::id($_GET['edit_campaign']));
        if ($editingCampaign === null) throw new RuntimeException('The campaign no longer exists.');
        if (isset($editingCampaign['delivery_snapshot']) || isset($editingCampaign['audience_snapshot'])) throw new RuntimeException('Queued message text and audience rules are saved. Create a new campaign for different content or criteria.');
        $campaignForm = ['id' => $editingCampaign['id'], 'subject' => $editingCampaign['subject'] ?? $editingCampaign['name'] ?? '',
            'preheader' => $editingCampaign['preheader'] ?? '', 'body' => $editingCampaign['body'] ?? '',
            'scheduled_at' => \Velvet\Core\MarketingAudience::dateForForm($editingCampaign['scheduled_at'] ?? null),
            'audience_selection' => mm_audience_option($editingCampaign['audience'] ?? ['mode' => 'all'])];
    } catch (Throwable $caught) { $campaignLoadError = $caught->getMessage(); }
}
$selectedAudience = mm_form_text($campaignForm['audience_selection'] ?? 'all', 145);
$audienceChoices = ['all' => 'All eligible newsletter contacts'];
foreach ($segments as $segment) {
    if ($segment['enabled'] && $segment['valid']) $audienceChoices[$segment['id'] . ':' . $segment['revision']] = $segment['name'];
}
?>
<section class="marketing-card">
  <h2><?= !empty($campaignForm['id']) ? 'Edit email campaign draft' : 'Email Campaign' ?></h2>
  <?php if ($campaignLoadError !== ''): ?><p class="marketing-notice" role="alert"><?= velvet_marketing_escape($campaignLoadError) ?></p><?php endif; ?>
  <form class="marketing-form" method="post" data-campaign-editor>
    <?= csrf_field() ?><input type="hidden" name="action" value="save_campaign">
    <input type="hidden" name="id" value="<?= velvet_marketing_escape(mm_form_text($campaignForm['id'] ?? '', 80)) ?>">
    <input type="hidden" name="audience_preview_revision" value="<?= velvet_marketing_escape(mm_form_text($campaignForm['audience_preview_revision'] ?? '', 64)) ?>">
    <label>Subject<input name="subject" maxlength="255" value="<?= velvet_marketing_escape(mm_form_text($campaignForm['subject'] ?? '', 1020)) ?>" required></label>
    <label>Preheader<input name="preheader" maxlength="500" value="<?= velvet_marketing_escape(mm_form_text($campaignForm['preheader'] ?? '', 2000)) ?>"></label>
    <label>Message<textarea name="body" rows="8" maxlength="100000" required><?= velvet_marketing_escape(mm_form_text($campaignForm['body'] ?? '')) ?></textarea></label>
    <label>Newsletter audience<select name="audience_selection" required>
      <?php if (!array_key_exists($selectedAudience, $audienceChoices)): ?><option value="" selected disabled>The audience changed; select it again</option><?php endif; ?>
      <?php foreach ($audienceChoices as $value => $label): ?><option value="<?= velvet_marketing_escape($value) ?>" <?= $selectedAudience === $value ? 'selected' : '' ?>><?= velvet_marketing_escape($label) ?></option><?php endforeach; ?>
    </select></label>
    <a href="?page=marketing&amp;tab=audiences">Manage newsletter audiences</a>
    <button class="marketing-button" name="preview_audience" value="1" formnovalidate>Preview Campaign Audience</button>
    <?php if (isset($campaignAudiencePreview)): ?><p class="marketing-notice" role="status" data-campaign-audience-preview><strong><?= (int) $campaignAudiencePreview['eligible_count'] ?> eligible newsletter contacts</strong> match now. Queueing checks this preview again; refresh it if contacts or criteria change.</p><?php endif; ?>
    <label>Schedule (<?= velvet_marketing_escape(date_default_timezone_get()) ?>)<input type="datetime-local" step="1" name="scheduled_at" value="<?= velvet_marketing_escape(mm_form_text($campaignForm['scheduled_at'] ?? '', 40)) ?>"></label>
    <input type="hidden" name="status" value="draft">
    <label><input type="checkbox" name="queue" value="1" style="width:auto" <?= !empty($campaignForm['queue']) ? 'checked' : '' ?>> Queue for confirmed subscribers in this audience</label>
    <p>Leave the schedule blank to make queued messages eligible now. Queueing saves message text, audience rules and recipient addresses. Create a new campaign to change its message or audience rules.</p>
    <button class="marketing-button">Save Campaign</button>
    <?php if (!empty($campaignForm['id'])): ?><a href="?page=marketing&amp;tab=campaigns">Create another campaign</a><?php endif; ?>
  </form>
</section>
