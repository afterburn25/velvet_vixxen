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
$publicationMetadata = null;
if ($publicationCollection !== 'banners') {
    $publicationMetadata = \Velvet\Core\MarketingMetadata::describe($publicationCollection, $publicationSaved ?? []);
    $publicationMetadataValues = $publicationMetadata['values'];
    $publicationTagsText = implode(', ', $publicationMetadataValues['tags'] ?? []);
    $publicationRelatedIds = $publicationMetadataValues['related_post_ids'] ?? [];
    if ($publicationSubmitted) {
        foreach (['author_name', 'featured_asset_id', 'featured_alt', 'seo_title', 'seo_description'] as $field) {
            if (array_key_exists($field, $publicationForm)) $publicationMetadataValues[$field] = mm_form_text($publicationForm[$field]);
        }
        if (array_key_exists('tags', $publicationForm)) $publicationTagsText = mm_form_text($publicationForm['tags']);
        if (array_key_exists('related_post_ids', $publicationForm)) {
            $publicationRelatedIds = [];
            if (is_array($publicationForm['related_post_ids'])) {
                foreach (array_slice($publicationForm['related_post_ids'], 0, 6) as $relatedId) {
                    $publicationRelatedIds[] = is_string($relatedId) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $relatedId) === 1 ? $relatedId : '';
                }
            }
        }
    }
    $publicationMetadataText = static fn(string $field): string => velvet_marketing_escape(mm_form_text($publicationMetadataValues[$field] ?? ''));
    $publicationImageCatalog = mm_publication_metadata_catalog();
    $publicationImageOptions = $publicationImageCatalog['images'];
    $publicationSelectedImage = null;
    foreach ($publicationImageOptions as $imageOption) {
        if ($imageOption['id'] === $publicationMetadataValues['featured_asset_id']) $publicationSelectedImage = $imageOption;
    }
    if ($publicationMetadataValues['featured_asset_id'] !== '' && $publicationSelectedImage === null) {
        $publicationImageOptions[] = ['id' => $publicationMetadataValues['featured_asset_id'], 'title' => 'Unavailable saved image', 'path' => ''];
    }
    $publicationRelatedOptions = [];
    if ($publicationCollection === 'posts') {
        foreach ($marketingStore->publicationRecords('posts') as $relatedOption) {
            try { $relatedId = \Velvet\Core\MarketingPublication::id($relatedOption['id'] ?? null); }
            catch (InvalidArgumentException) { continue; }
            if ($relatedId === ($publicationSaved['id'] ?? '')) continue;
            $publicationRelatedOptions[$relatedId] = ['id' => $relatedId, 'label' => mm_form_text($relatedOption['title'] ?? 'Untitled update', 1000)
                . ' · ' . mm_form_text($relatedOption['visibility']['state'] ?? 'Needs review')];
        }
        foreach ($publicationRelatedIds as $relatedId) {
            if ($relatedId !== '' && !isset($publicationRelatedOptions[$relatedId])) $publicationRelatedOptions[$relatedId] = ['id' => $relatedId, 'label' => 'Unavailable saved update'];
        }
    }
    $publicationSiteSettings = (array) app_store()->setting('site', []);
    $publicationSearchBrand = mm_form_text($publicationSiteSettings['brand_name'] ?? 'Nikki Wilder', 255);
    $publicationSearchTitle = $publicationMetadataValues['seo_title'] !== '' ? $publicationMetadataValues['seo_title'] : mm_form_text($publicationValues['title'] ?? '');
    $publicationSearchDescription = $publicationMetadataValues['seo_description'] !== '' ? $publicationMetadataValues['seo_description'] : mm_form_text($publicationValues['excerpt'] ?? '');
}
// Catalogue reads may wait on storage. Recheck the saved owner before exposing
// publication fields, public artwork choices or the private related-post list.
if (!velvet_marketing_is_owner()) {
    http_response_code(403);
    exit('Only the active site owner can edit publications.');
}
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
    <?php if ($publicationMetadata !== null): ?>
    <div class="marketing-metadata" data-editorial-metadata="<?= velvet_marketing_escape($publicationCollection) ?>" data-editorial-brand="<?= velvet_marketing_escape($publicationSearchBrand) ?>">
      <div class="marketing-section-heading"><span class="marketing-eyebrow">Make it yours</span><h3>Publication details</h3><p>Shape how this saved content appears to your readers and in search.</p></div>
      <?php foreach ($publicationMetadata['problems'] as $metadataProblem): ?><p class="marketing-notice" role="alert" data-editorial-metadata-problem><?= velvet_marketing_escape($metadataProblem) ?> Review the affected field before saving.</p><?php endforeach; ?>
      <?php if ($publicationCollection === 'posts'): ?>
      <fieldset class="marketing-metadata-group"><legend>Byline &amp; topics</legend>
        <label>Author display name<input name="author_name" value="<?= $publicationMetadataText('author_name') ?>" maxlength="120" autocomplete="off" placeholder="Optional public byline" data-editorial-author></label>
        <p class="marketing-field-help">Leave blank to omit the byline. This public name is separate from your owner account.</p>
        <label>Tags<textarea name="tags" rows="2" maxlength="3000" placeholder="Behind the scenes, Music, Announcements" data-editorial-tags-input><?= velvet_marketing_escape($publicationTagsText) ?></textarea></label>
        <p class="marketing-field-help">Separate topics with commas. Add up to 30 tags, with up to 64 characters each.</p>
      </fieldset>
      <?php endif; ?>
      <fieldset class="marketing-metadata-group"><legend>Featured image</legend>
        <label>Choose public artwork<select name="featured_asset_id" data-editorial-image-picker aria-describedby="editorial-image-help-<?= $publicationCollection ?>">
          <option value="" <?= $publicationMetadataValues['featured_asset_id'] === '' ? 'selected' : '' ?>>No featured image</option>
          <?php foreach ($publicationImageOptions as $imageOption): ?><option value="<?= velvet_marketing_escape($imageOption['id']) ?>" data-image-src="<?= $imageOption['path'] !== '' ? velvet_marketing_escape(base_url($imageOption['path'])) : '' ?>" <?= $publicationMetadataValues['featured_asset_id'] === $imageOption['id'] ? 'selected' : '' ?>><?= velvet_marketing_escape($imageOption['title']) ?></option><?php endforeach; ?>
        </select></label>
        <p class="marketing-field-help" id="editorial-image-help-<?= $publicationCollection ?>">Choose an existing public image. Protected media is not offered here. <a href="<?= velvet_marketing_escape(base_url('admin/?page=media')) ?>" target="_blank" rel="noopener noreferrer">Open Media Library</a> to upload artwork, then reload this editor after saving your changes.</p>
        <?php if ($publicationImageCatalog['problem'] !== ''): ?><p class="marketing-notice" role="alert"><?= velvet_marketing_escape($publicationImageCatalog['problem']) ?></p><?php endif; ?>
        <?php if ($publicationMetadataValues['featured_asset_id'] !== '' && $publicationSelectedImage === null): ?><p class="marketing-notice" data-editorial-image-unavailable>The saved image is unavailable. Choose a current public image or select “No featured image.”</p><?php endif; ?>
        <div class="marketing-artwork-preview" data-editorial-image-preview>
          <img <?= $publicationSelectedImage !== null ? 'src="' . velvet_marketing_escape(base_url($publicationSelectedImage['path'])) . '"' : 'hidden' ?> alt="<?= $publicationMetadataText('featured_alt') ?>" data-editorial-image-sample>
          <span <?= $publicationSelectedImage !== null ? 'hidden' : '' ?> data-editorial-image-empty>Selected artwork appears here.</span>
        </div>
        <label>Image description<input name="featured_alt" value="<?= $publicationMetadataText('featured_alt') ?>" maxlength="240" data-editorial-image-alt></label>
        <p class="marketing-field-help">Describe the image for readers using assistive technology. If blank, the publication title is used.</p>
      </fieldset>
      <fieldset class="marketing-metadata-group"><legend>Search appearance</legend>
        <label>Search title<input name="seo_title" value="<?= $publicationMetadataText('seo_title') ?>" maxlength="120" placeholder="Uses the publication title when blank" data-editorial-seo-title></label>
        <p class="marketing-field-help"><span data-editorial-seo-title-count><?= preg_match_all('/./us', $publicationMetadataValues['seo_title']) ?></span>/120 characters</p>
        <label>Search description<textarea name="seo_description" rows="3" maxlength="320" placeholder="Uses the excerpt when blank" data-editorial-seo-description><?= $publicationMetadataText('seo_description') ?></textarea></label>
        <p class="marketing-field-help"><span data-editorial-seo-description-count><?= preg_match_all('/./us', $publicationMetadataValues['seo_description']) ?></span>/320 characters. These fields do not change the visible heading, saved address, schedule or access setting.</p>
        <div class="marketing-search-preview" data-editorial-search-preview aria-label="Search appearance preview">
          <span class="marketing-search-brand"><?= velvet_marketing_escape($publicationSearchBrand) ?></span>
          <strong data-editorial-search-title><?= velvet_marketing_escape(($publicationSearchTitle !== '' ? $publicationSearchTitle : 'Your publication title') . ' · ' . $publicationSearchBrand) ?></strong>
          <p data-editorial-search-description><?= velvet_marketing_escape($publicationSearchDescription !== '' ? $publicationSearchDescription : 'Add an excerpt or search description to introduce this publication.') ?></p>
        </div>
        <p class="marketing-field-help">This preview uses your form text. Save first to update the site and Preview Saved.</p>
      </fieldset>
      <?php if ($publicationCollection === 'posts'): ?>
      <fieldset class="marketing-metadata-group" data-editorial-related-options><legend>Related updates</legend>
        <p class="marketing-field-help">Choose up to six updates in the order you want them shown. Each visitor sees only the updates available to them under the current publication dates and access settings.</p>
        <div class="marketing-related-picker-grid">
          <?php for ($relatedSlot = 0; $relatedSlot < 6; $relatedSlot++): $selectedRelatedId = $publicationRelatedIds[$relatedSlot] ?? ''; ?>
          <label>Related update <?= $relatedSlot + 1 ?><select name="related_post_ids[]" data-editorial-related-slot="<?= $relatedSlot + 1 ?>">
            <option value="" <?= $selectedRelatedId === '' ? 'selected' : '' ?>>None</option>
            <?php foreach ($publicationRelatedOptions as $relatedOption): ?><option value="<?= velvet_marketing_escape($relatedOption['id']) ?>" <?= $selectedRelatedId === $relatedOption['id'] ? 'selected' : '' ?>><?= velvet_marketing_escape($relatedOption['label']) ?></option><?php endforeach; ?>
          </select></label>
          <?php endfor; ?>
        </div>
        <?php if ($publicationRelatedOptions === []): ?><p class="marketing-field-help">Save another update to make it available here.</p><?php endif; ?>
      </fieldset>
      <?php endif; ?>
    </div>
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
<?php if ($publicationMetadata !== null): ?><script src="<?= velvet_marketing_escape(asset_url('js/marketing-editorial.js')) ?>" defer></script><?php endif; ?>
