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
        $businessRules = $editingSegment['rules'];
        $segmentForm += ['membership' => $businessRules['membership'] ?? 'any', 'plan_ids' => $businessRules['plan_ids'] ?? [],
            'referral_codes' => implode("\n", $businessRules['referral_codes'] ?? []), 'purchase_enabled' => ($businessRules['purchase'] ?? null) !== null ? '1' : '',
            'purchase_min_count' => (string) ($businessRules['purchase']['min_count'] ?? 1), 'purchase_min_total_minor' => (string) ($businessRules['purchase']['min_total_minor'] ?? 0),
            'purchase_currency' => $businessRules['purchase']['currency'] ?? 'USD',
            'purchase_after' => \Velvet\Core\MarketingAudience::dateForForm($businessRules['purchase']['after'] ?? null),
            'purchase_before' => \Velvet\Core\MarketingAudience::dateForForm($businessRules['purchase']['before'] ?? null)];
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
  <p>Select by saved newsletter signup source, confirmation date, membership, paid purchases or a consented referral. Every campaign still requires current confirmed newsletter consent and passes suppression checks.</p>
  <p>Business criteria use an exact email match to one active verified member. Unknown or ambiguous records do not qualify. Newsletter source and confirmation dates remain separate from membership and measurement choices.</p>
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
      <details data-business-audience><summary>Membership, purchase and referral criteria</summary>
      <label>Membership<select name="membership" aria-label="Membership"><?php foreach (['any' => 'Any membership state', 'active' => 'Active membership', 'none' => 'Verified member without active membership'] as $value => $label): ?><option value="<?= $value ?>" <?= ($segmentForm['membership'] ?? 'any') === $value ? 'selected' : '' ?>><?= velvet_marketing_escape($label) ?></option><?php endforeach; ?></select></label>
      <?php $audiencePlanCatalog = mm_publication_access_catalog(); $selectedPlans = is_array($segmentForm['plan_ids'] ?? null) ? $segmentForm['plan_ids'] : []; ?>
      <fieldset><legend>Selected active tiers (optional)</legend><?php foreach ($audiencePlanCatalog['plans'] as $plan): ?><label class="marketing-check"><input type="checkbox" name="plan_ids[]" value="<?= velvet_marketing_escape($plan['id']) ?>" style="width:auto" <?= in_array($plan['id'], $selectedPlans, true) ? 'checked' : '' ?>> <?= velvet_marketing_escape($plan['name']) ?><?= $plan['available'] ? '' : ' — unavailable; review selection' ?></label><?php endforeach; ?><?php foreach (array_diff($selectedPlans, array_column($audiencePlanCatalog['plans'], 'id')) as $missing): if (!is_string($missing)) continue; ?><label class="marketing-check"><input type="checkbox" name="plan_ids[]" value="<?= velvet_marketing_escape($missing) ?>" style="width:auto" checked> Unavailable saved tier <?= velvet_marketing_escape($missing) ?> — review before changing</label><?php endforeach; ?></fieldset>
      <?php if ($audiencePlanCatalog['problem'] !== ''): ?><p class="marketing-notice" role="alert"><?= velvet_marketing_escape($audiencePlanCatalog['problem']) ?></p><?php endif; ?>
      <label class="marketing-check"><input type="checkbox" name="purchase_enabled" value="1" style="width:auto" <?= !empty($segmentForm['purchase_enabled']) ? 'checked' : '' ?>> Require paid purchases</label>
      <label>Minimum paid purchase count<input type="number" name="purchase_min_count" min="1" max="100000" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['purchase_min_count'] ?? '1', 10)) ?>"></label>
      <label>Minimum total in minor currency units<input type="number" name="purchase_min_total_minor" min="0" max="100000000000" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['purchase_min_total_minor'] ?? '0', 12)) ?>"></label>
      <label>Purchase currency<input name="purchase_currency" maxlength="3" pattern="[A-Z]{3}" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['purchase_currency'] ?? 'USD', 3)) ?>"></label>
      <label>Purchased on or after (<?= velvet_marketing_escape(date_default_timezone_get()) ?>)<input type="datetime-local" step="1" name="purchase_after" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['purchase_after'] ?? '', 40)) ?>"></label>
      <label>Purchased before (<?= velvet_marketing_escape(date_default_timezone_get()) ?>)<input type="datetime-local" step="1" name="purchase_before" value="<?= velvet_marketing_escape(mm_form_text($segmentForm['purchase_before'] ?? '', 40)) ?>"></label>
      <p>Purchase totals subtract partial refunds. Fully refunded and disputed purchases do not qualify. One currency is evaluated at a time; no exchange rate is inferred.</p>
      <label>Exact saved referral codes, one per line<textarea name="referral_codes" rows="3" maxlength="1300"><?= velvet_marketing_escape(mm_form_text($segmentForm['referral_codes'] ?? '', 1300)) ?></textarea></label>
      <p>Referral criteria use the consented origin saved with registration or a paid order. Browser deletion markers exclude erased attribution from these criteria.</p>
      </details>
      <p>All entered criteria must match. Starts include that moment; ends exclude it. Choose at least one criterion.</p>
      <label class="marketing-check"><input type="checkbox" name="enabled" value="1" style="width:auto" <?= ($segmentForm === [] || !empty($segmentForm['enabled'])) ? 'checked' : '' ?>> Available for new campaigns</label>
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
