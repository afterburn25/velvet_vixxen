<?php
declare(strict_types=1);
$segmentForm = $segmentForm ?? [];
$segmentLoadError = '';
if ($segmentForm === [] && isset($_GET['edit_segment'])) {
    try {
        $editingSegment = $marketingStore->segment(\Velvet\Core\MarketingAudience::id($_GET['edit_segment']));
        if ($editingSegment === null) throw new RuntimeException('The audience no longer exists.');
        $segmentForm = ['id' => $editingSegment['id'], 'name' => $editingSegment['name'],
            'sources' => implode("\n", $editingSegment['rules']['sources']), 'enabled' => $editingSegment['enabled'] ? '1' : '',
            'confirmed_after' => \Velvet\Core\MarketingAudience::dateForForm($editingSegment['rules']['confirmed_after']),
            'confirmed_before' => \Velvet\Core\MarketingAudience::dateForForm($editingSegment['rules']['confirmed_before']),
            'segment_revision' => $editingSegment['revision']];
        if (!$editingSegment['valid']) $segmentLoadError = 'This saved audience needs valid criteria before it can be selected for a campaign.';
    } catch (Throwable $caught) { $segmentLoadError = $caught->getMessage(); }
}
$sourceOptions = [];
foreach ($data['contacts'] as $contact) {
    try { $source = \Velvet\Core\MarketingAudience::text($contact['source'] ?? '', 120, 'Signup source'); }
    catch (InvalidArgumentException) { continue; }
    if ($source !== '') $sourceOptions[$source] = true;
}
$sourceOptions = array_map('strval', array_keys($sourceOptions));
sort($sourceOptions, SORT_STRING);
?>
<section class="marketing-card" data-newsletter-audiences>
  <h2>Newsletter audiences</h2>
  <p>Select by saved newsletter signup source and confirmation date. Every campaign still requires current confirmed consent and passes the suppression checks.</p>
  <p>Source means the saved newsletter form source. Dates describe the latest newsletter confirmation, including a later subscription request. These criteria do not identify members, purchases or referrals.</p>
</section>
<div class="marketing-admin-grid" style="margin-top:22px">
  <section class="marketing-card">
    <h2><?= !empty($segmentForm['id']) ? 'Edit audience' : 'New audience' ?></h2>
    <?php if ($segmentLoadError !== ''): ?><p class="marketing-notice" role="alert"><?= velvet_marketing_escape($segmentLoadError) ?></p><?php endif; ?>
    <form class="marketing-form" method="post" data-audience-editor>
      <?= csrf_field() ?><input type="hidden" name="action" value="save_segment">
      <input type="hidden" name="id" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['id'] ?? '', 80)) ?>">
      <input type="hidden" name="segment_revision" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['segment_revision'] ?? '', 64)) ?>">
      <label>Audience name<input name="name" maxlength="120" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['name'] ?? '', 480)) ?>" required></label>
      <label>Exact signup sources, one per line<textarea name="sources" rows="4" maxlength="10000" aria-describedby="audience-source-help"><?= velvet_marketing_escape(mm_form_text($segmentForm['sources'] ?? '', 10000)) ?></textarea></label>
      <p id="audience-source-help">Use up to 20 exact, case-sensitive source values. Leave blank for any source within the confirmation dates.</p>
      <?php if ($sourceOptions !== []): ?><details><summary>Saved signup sources</summary><ul><?php foreach (array_slice($sourceOptions, 0, 100) as $source): ?><li><code><?= velvet_marketing_escape($source) ?></code></li><?php endforeach; ?></ul><?php if (count($sourceOptions) > 100): ?><p>Showing the first 100 saved sources.</p><?php endif; ?></details><?php endif; ?>
      <label>Confirmed on or after (<?= velvet_marketing_escape(date_default_timezone_get()) ?>)<input type="datetime-local" step="1" name="confirmed_after" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['confirmed_after'] ?? '', 40)) ?>"></label>
      <label>Confirmed before (<?= velvet_marketing_escape(date_default_timezone_get()) ?>)<input type="datetime-local" step="1" name="confirmed_before" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['confirmed_before'] ?? '', 40)) ?>"></label>
      <p>All entered criteria must match. The start includes that moment; the end excludes it. Choose at least one source or confirmation date.</p>
      <label><input type="checkbox" name="enabled" value="1" style="width:auto" <?= ($segmentForm === [] || !empty($segmentForm['enabled'])) ? 'checked' : '' ?>> Available for new campaigns</label>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><button class="marketing-button">Save Audience</button><button class="marketing-button" name="preview_segment" value="1" formnovalidate>Preview Audience</button></div>
      <?php if (!empty($segmentForm['id'])): ?><a href="?page=marketing&amp;tab=audiences">Create another audience</a><?php endif; ?>
    </form>
    <?php if (isset($segmentPreview)): ?><p class="marketing-notice" role="status" data-audience-preview><strong><?= (int) $segmentPreview['eligible_count'] ?> eligible newsletter contacts</strong> match now. Previewing saves nothing and queues no messages.</p><?php endif; ?>
  </section>
  <section class="marketing-card">
    <h2>Saved audiences</h2>
    <p>Changes affect future selections. Queued campaigns keep their saved rules and addresses. Pause a campaign to stop its waiting deliveries.</p>
    <?php if ($segments === []): ?><p>No audiences saved yet. Campaigns can still select all eligible newsletter contacts.</p><?php endif; ?>
    <?php foreach ($segments as $segment): ?>
      <article class="marketing-card" style="margin-top:16px" data-audience-id="<?= velvet_marketing_escape($segment['id']) ?>">
        <h3><?= velvet_marketing_escape($segment['name']) ?></h3>
        <p><?= !$segment['valid'] ? 'Needs review' : ($segment['enabled'] ? 'Available for new campaigns' : 'Disabled for new campaigns') ?></p>
        <?php if ($segment['valid']): ?><p><?= velvet_marketing_escape(mm_audience_summary($segment['rules'])) ?> (<?= velvet_marketing_escape(date_default_timezone_get()) ?>)</p><?php endif; ?>
        <a href="?page=marketing&amp;tab=audiences&amp;edit_segment=<?= rawurlencode($segment['id']) ?>">Edit audience</a>
        <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="delete_segment"><input type="hidden" name="id" value="<?= velvet_marketing_escape($segment['id']) ?>"><input type="hidden" name="segment_revision" value="<?= velvet_marketing_escape($segment['revision']) ?>"><button class="marketing-button">Remove Audience</button></form>
      </article>
    <?php endforeach; ?>
  </section>
</div>
