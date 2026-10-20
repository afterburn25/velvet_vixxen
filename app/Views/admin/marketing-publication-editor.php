<?php
declare(strict_types=1);
$publicationMeta = mm_publication_meta($publicationCollection);
$publicationSubmitted = isset($publicationForm) && ($publicationFormCollection ?? '') === $publicationCollection;
$publicationValues = ['id' => '', 'publication_revision' => '', 'title' => '', 'slug' => '', 'category' => 'Update',
    'excerpt' => '', 'body' => '', 'status' => 'draft', 'publish_at' => date('Y-m-d\TH:i'),
    'text' => '', 'url' => '', 'eyebrow' => 'Special Offer', 'cta_label' => 'Join Now', 'cta_url' => '/membership/',
    'enabled' => true, 'starts_at' => '', 'ends_at' => ''];
$publicationSaved = null;
$publicationLoadError = '';
$publicationEditId = $publicationSubmitted ? ($publicationForm['id'] ?? '') : ($_GET[$publicationMeta['edit']] ?? '');
try {
    $publicationEditId = \Velvet\Core\MarketingPublication::id($publicationEditId, true);
    if ($publicationEditId !== '') {
        $publicationSaved = $marketingStore->publicationRecord($publicationCollection, $publicationEditId);
        if ($publicationSaved === null) throw new RuntimeException('This publication no longer exists. Choose another saved item.');
        $publicationValues = array_replace($publicationValues, $publicationSaved, ['publication_revision' => $publicationSaved['revision']]);
    }
} catch (Throwable $caught) { $publicationLoadError = $caught->getMessage(); }
if ($publicationSubmitted) {
    $publicationValues = array_replace($publicationValues, $publicationForm);
    $publicationValues['enabled'] = ($publicationForm['enabled'] ?? null) === '1';
}
$publicationDateFields = [];
foreach ($publicationCollection === 'posts' ? ['publish_at' => 'Publish at'] : ['starts_at' => 'Starts', 'ends_at' => 'Ends'] as $key => $label) {
    $value = $publicationValues[$key] ?? '';
    $invalid = false;
    try {
        if ($publicationSubmitted) {
            \Velvet\Core\MarketingPublication::formDate($value);
            $value = is_string($value) ? $value : '';
        } else $value = \Velvet\Core\MarketingPublication::dateForForm($value);
    } catch (InvalidArgumentException) {
        $invalid = true;
        $value = is_string($value) ? $value : '[invalid saved date]';
    }
    $publicationDateFields[$key] = ['label' => $label, 'value' => $value, 'invalid' => $invalid];
}
$publicationEditing = is_string($publicationValues['id'] ?? null) && $publicationValues['id'] !== '';
$publicationPermalinkLocked = is_string($publicationSaved['slug'] ?? null) && $publicationSaved['slug'] !== '';
$publicationText = static fn(string $key): string => velvet_marketing_escape(mm_form_text($publicationValues[$key] ?? ''));
$publicationAccessCatalog = mm_publication_access_catalog();
$publicationAccessRecord = $publicationSaved ?? [];
if ($publicationSubmitted && array_key_exists('access_mode', $publicationForm)) {
    try { $publicationAccessRecord = ['access' => mm_publication_access_input($publicationForm)]; }
    catch (InvalidArgumentException) { $publicationAccessRecord = ['access' => null]; }
}
$publicationAccess = \Velvet\Core\MarketingAccess::describe($publicationAccessRecord, $publicationAccessCatalog['available_plan_ids']);
$publicationAccessMode = $publicationAccess['mode'];
$publicationAccessPlans = $publicationAccess['plan_ids'];
if ($publicationSubmitted && array_key_exists('access_mode', $publicationForm)) {
    $publicationAccessMode = in_array($publicationForm['access_mode'], ['public', 'members', 'tiers'], true) ? $publicationForm['access_mode'] : '';
    $publicationAccessPlans = [];
    if (is_array($publicationForm['access_plan_ids'] ?? null)) {
        foreach (array_slice($publicationForm['access_plan_ids'], 0, 100) as $planId) {
            if (is_string($planId) && preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $planId) === 1) $publicationAccessPlans[] = $planId;
        }
    }
}
$publicationPlanOptions = $publicationAccessCatalog['plans'];
foreach ($publicationAccessPlans as $planId) {
    if (!in_array($planId, array_column($publicationPlanOptions, 'id'), true)) {
        $publicationPlanOptions[] = ['id' => $planId, 'name' => 'Unavailable saved tier', 'available' => false];
    }
}
$publicationPlanOptions = array_values(array_filter($publicationPlanOptions,
    static fn(array $plan): bool => $plan['available'] || in_array($plan['id'], $publicationAccessPlans, true)));
?>
<section class="marketing-card" data-publication-editor-card="<?= velvet_marketing_escape($publicationCollection) ?>">
  <h2><?= $publicationEditing ? 'Edit ' : 'New ' ?><?= velvet_marketing_escape($publicationMeta['name']) ?></h2>
  <?php if ($publicationLoadError !== ''): ?><p class="marketing-notice" role="alert"><?= velvet_marketing_escape($publicationLoadError) ?></p><?php endif; ?>
  <?php if ($publicationEditing): ?><p><a href="?page=marketing&amp;tab=<?= $publicationMeta['tab'] ?>">Create another <?= $publicationCollection === 'posts' ? 'post' : ($publicationCollection === 'banners' ? 'banner' : 'landing page') ?></a></p><?php endif; ?>
  <?php if ($publicationSaved !== null): ?><p><a class="marketing-button" data-publication-preview href="<?= velvet_marketing_escape(mm_publication_preview_url($publicationCollection, $publicationSaved['id'])) ?>" target="_blank" rel="noopener noreferrer">Preview Saved</a></p><p>Preview opens the last saved content. Save your changes before previewing them.</p><?php endif; ?>
  <form class="marketing-form" method="post" data-publication-editor="<?= velvet_marketing_escape($publicationCollection) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $publicationMeta['action'] ?>">
    <input type="hidden" name="id" value="<?= $publicationText('id') ?>">
    <input type="hidden" name="publication_revision" value="<?= $publicationText('publication_revision') ?>">
    <?php if ($publicationCollection === 'banners'): ?>
      <label>Text<input name="text" value="<?= $publicationText('text') ?>" maxlength="1000" required></label>
      <label>Link<input name="url" value="<?= $publicationText('url') ?>" maxlength="2048"></label>
    <?php else: ?>
      <label>Title<input name="title" value="<?= $publicationText('title') ?>" maxlength="255" required></label>
      <label>Slug<input name="slug" value="<?= $publicationText('slug') ?>" <?= $publicationPermalinkLocked ? 'readonly' : 'maxlength="160"' ?>></label>
      <p><?= $publicationPermalinkLocked ? 'This saved permalink stays the same when you edit or change visibility.' : 'Leave blank to create a permalink from the title. The saved permalink stays fixed.' ?></p>
      <?php if ($publicationCollection === 'posts'): ?><label>Category<input name="category" value="<?= $publicationText('category') ?>" maxlength="120"></label><?php else: ?><label>Eyebrow<input name="eyebrow" value="<?= $publicationText('eyebrow') ?>" maxlength="120"></label><?php endif; ?>
      <label>Excerpt<textarea name="excerpt" rows="3"><?= $publicationText('excerpt') ?></textarea></label>
      <label>Body<textarea name="body" rows="8"><?= $publicationText('body') ?></textarea></label>
      <?php if ($publicationCollection === 'landing_pages'): ?>
        <label>Button label<input name="cta_label" value="<?= $publicationText('cta_label') ?>" maxlength="120"></label>
        <label>Button link<input name="cta_url" value="<?= $publicationText('cta_url') ?>" maxlength="2048"></label>
      <?php endif; ?>
    <?php endif; ?>
    <label>Who can view this?<select name="access_mode" data-editorial-access required aria-describedby="publication-access-help-<?= $publicationCollection ?>">
      <?php if ($publicationAccessMode === ''): ?><option value="" selected>Choose who can view</option><?php endif; ?>
      <?php foreach (['public' => 'Public', 'members' => 'Members', 'tiers' => 'Selected tiers'] as $accessMode => $accessLabel): ?><option value="<?= $accessMode ?>" <?= $publicationAccessMode === $accessMode ? 'selected' : '' ?>><?= $accessLabel ?></option><?php endforeach; ?>
    </select></label>
    <p id="publication-access-help-<?= $publicationCollection ?>">Public is visible to everyone. Members includes verified free and paid accounts. Selected tiers requires current access to at least one chosen membership tier. Publication status and dates still apply.</p>
    <?php if (!$publicationAccess['valid']): ?><p class="marketing-notice" role="alert" data-editorial-access-problem><?= velvet_marketing_escape($publicationAccess['problem'] ?? 'The saved access setting needs review.') ?> Choose the intended access before saving; the saved item stays restricted until repaired.</p><?php endif; ?>
    <?php if ($publicationAccessCatalog['problem'] !== ''): ?><p class="marketing-notice" role="alert"><?= velvet_marketing_escape($publicationAccessCatalog['problem']) ?></p><?php endif; ?>
    <fieldset class="marketing-access-plans" data-editorial-plan-options>
      <legend>Selected tiers</legend>
      <p>Choose one or more tiers. These selections apply only when “Selected tiers” is chosen above.</p>
      <?php if ($publicationPlanOptions === []): ?><p>No membership tiers are available. Add an active tier in <a href="<?= velvet_marketing_escape(base_url('admin/?page=plans')) ?>">Membership Plans</a> before using selected-tier access.</p><?php endif; ?>
      <?php foreach ($publicationPlanOptions as $accessPlan):
        $planSelected = in_array($accessPlan['id'], $publicationAccessPlans, true);
      ?><label class="marketing-publication-checkbox"><input type="checkbox" name="access_plan_ids[]" value="<?= velvet_marketing_escape($accessPlan['id']) ?>" <?= $planSelected ? 'checked' : '' ?> <?= !$accessPlan['available'] ? 'data-unavailable-tier' : '' ?>><span><?= velvet_marketing_escape($accessPlan['name']) ?><?= !$accessPlan['available'] ? ' — unavailable; clear this selection and choose a current tier.' : '' ?></span></label><?php endforeach; ?>
    </fieldset>
    <?php if ($publicationCollection === 'posts'): ?>
      <label>Status<select name="status"><?php foreach (['draft', 'published'] as $status): ?><option value="<?= $status ?>" <?= ($publicationValues['status'] ?? '') === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></label>
    <?php else: ?>
      <label class="marketing-publication-checkbox"><input type="checkbox" name="enabled" value="1" <?= !empty($publicationValues['enabled']) ? 'checked' : '' ?>> Enabled</label>
    <?php endif; ?>
    <p>Dates use <strong><?= velvet_marketing_escape(date_default_timezone_get()) ?></strong>. <?= $publicationCollection === 'posts' ? 'A published post with a blank date is visible immediately; a draft stays hidden.' : 'Blank dates leave that end of the window open. The start is included; the end is excluded and must be after the start.' ?></p>
    <?php foreach ($publicationDateFields as $key => $dateField): ?>
      <label><?= $dateField['label'] ?><input type="<?= $dateField['invalid'] ? 'text' : 'datetime-local' ?>" name="<?= $key ?>" value="<?= velvet_marketing_escape($dateField['value']) ?>" <?= $dateField['invalid'] ? 'aria-invalid="true"' : 'step="1"' ?>></label>
      <?php if ($dateField['invalid']): ?><p class="marketing-notice" role="alert">This date is invalid. Enter YYYY-MM-DDTHH:MM or deliberately clear it before saving. Invalid saved dates hold public visibility.</p><?php endif; ?>
    <?php endforeach; ?>
    <button class="marketing-button"><?= $publicationMeta['button'] ?></button>
  </form>
</section>
