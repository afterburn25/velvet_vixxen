<?php
declare(strict_types=1);
/** @var \App\Core\Store $store */
$bookingBuilder = new \App\Core\BookingFormService($store);
$bookingDefinition = $bookingBuilder->definition();
$editingField = null;
foreach ($bookingDefinition['fields'] as $definitionField) {
    if ($definitionField['id'] === (string) ($_GET['field'] ?? '')) $editingField = $definitionField;
}
$editingField ??= ['id' => '', 'label' => '', 'type' => 'text', 'help' => '', 'placeholder' => '',
    'required' => false, 'enabled' => true, 'minSelections' => 0, 'options' => []];
?>
<link rel="stylesheet" href="<?=e(asset_url('css/booking-form.css'))?>">
<section class="welcome-panel"><div><span class="admin-kicker">Members &amp; Communications</span><h2>Booking Form Builder</h2><p>Choose the questions visitors see, arrange their order, and decide which answers are required. Earlier inquiries keep their original questions and answers.</p><div class="panel-actions"><a class="admin-button secondary" href="<?=e(base_url('booking/'))?>" target="_blank" rel="noopener">Preview Public Form</a><a class="admin-button secondary" href="<?=e(base_url('admin/?page=booking-requests'))?>">View Booking Inquiries</a></div></div></section>
<div class="booking-builder">
  <section class="admin-card">
    <div class="card-head"><div><span class="admin-kicker">Public form order</span><h2>Your fields</h2></div><a class="admin-button secondary" href="<?=e(base_url('admin/?page=booking-form#booking-field-editor'))?>">New Field</a></div>
    <?php if ($bookingDefinition['fields'] === []): ?><p>No questions are enabled yet. Add a field to begin.</p><?php endif; ?>
    <ol class="booking-builder-list">
    <?php foreach ($bookingDefinition['fields'] as $position => $definitionField): ?>
      <li data-booking-field-id="<?=e($definitionField['id'])?>">
        <div class="booking-builder-item-head"><a href="<?=e(base_url('admin/?page=booking-form&field='.rawurlencode($definitionField['id']).'#booking-field-editor'))?>"><?=e($definitionField['label'])?></a><span><?=e(\App\Core\BookingFormService::TYPES[$definitionField['type']] ?? $definitionField['type'])?> · <?=$definitionField['enabled'] ? ($definitionField['required'] ? 'Required' : 'Optional') : 'Disabled'?></span></div>
        <div class="booking-builder-actions">
          <a class="mini-button" href="<?=e(base_url('admin/?page=booking-form&field='.rawurlencode($definitionField['id']).'#booking-field-editor'))?>">Edit</a>
          <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="move_booking_form_field"><input type="hidden" name="field_id" value="<?=e($definitionField['id'])?>"><input type="hidden" name="form_revision" value="<?=e($bookingDefinition['revision'])?>"><button class="mini-button" name="direction" value="up" aria-label="Move <?=e($definitionField['label'])?> up" <?=$position === 0 ? 'disabled' : ''?>>↑</button><button class="mini-button" name="direction" value="down" aria-label="Move <?=e($definitionField['label'])?> down" <?=$position === count($bookingDefinition['fields']) - 1 ? 'disabled' : ''?>>↓</button></form>
          <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle_booking_form_field"><input type="hidden" name="field_id" value="<?=e($definitionField['id'])?>"><input type="hidden" name="form_revision" value="<?=e($bookingDefinition['revision'])?>"><button class="mini-button"><?=$definitionField['enabled'] ? 'Disable' : 'Enable'?></button></form>
          <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete_booking_form_field"><input type="hidden" name="field_id" value="<?=e($definitionField['id'])?>"><input type="hidden" name="form_revision" value="<?=e($bookingDefinition['revision'])?>"><button class="mini-button" data-confirm-field-delete="<?=e($definitionField['label'])?>">Delete</button></form>
        </div>
      </li>
    <?php endforeach; ?>
    </ol>
  </section>
  <section class="admin-card booking-editor-panel" id="booking-field-editor">
    <span class="admin-kicker"><?=$editingField['id'] === '' ? 'Add a question' : 'Edit question'?></span>
    <h2><?=$editingField['id'] === '' ? 'New Field' : e($editingField['label'])?></h2>
    <form method="post" class="communication-admin-form booking-field-editor">
      <?=csrf_field()?><input type="hidden" name="action" value="save_booking_form_field"><input type="hidden" name="field_id" value="<?=e($editingField['id'])?>"><input type="hidden" name="form_revision" value="<?=e($bookingDefinition['revision'])?>">
      <label for="booking-field-label">Field label<input id="booking-field-label" name="field_label" maxlength="120" required value="<?=e($editingField['label'])?>"></label>
      <label for="booking-field-type">Answer type<select id="booking-field-type" name="field_type"><?php foreach (\App\Core\BookingFormService::TYPES as $value => $label): ?><option value="<?=e($value)?>" <?=$editingField['type'] === $value ? 'selected' : ''?>><?=e($label)?></option><?php endforeach; ?></select></label>
      <?php if ($editingField['id'] === 'contact_email'): ?><p>This field supplies the address for email replies when its answer type is Email address. Inquiries without that answer remain available in Booking Inquiries.</p><?php endif; ?>
      <label for="booking-field-help">Help text <small>optional</small><textarea id="booking-field-help" name="field_help" rows="2" maxlength="400"><?=e($editingField['help'])?></textarea></label>
      <label for="booking-field-placeholder">Example answer <small>optional</small><input id="booking-field-placeholder" name="field_placeholder" maxlength="190" value="<?=e($editingField['placeholder'])?>"></label>
      <label data-booking-choice-settings>Choices <small>One choice per line; use 2 to 30 different choices.</small><textarea name="field_options" rows="6"><?=e(implode("\n", array_column($editingField['options'], 'label')))?></textarea></label>
      <label data-booking-minimum-settings>Minimum choices when answered<input type="number" name="field_minimum" min="0" max="30" value="<?=e((string) $editingField['minSelections'])?>"><small>A required multiple-choice field always needs at least one selection.</small></label>
      <label class="admin-inline-check"><input type="checkbox" name="field_required" value="1" <?=$editingField['required'] ? 'checked' : ''?>><span>Required answer</span></label>
      <label class="admin-inline-check"><input type="checkbox" name="field_enabled" value="1" <?=$editingField['enabled'] ? 'checked' : ''?>><span>Show on the public booking form</span></label>
      <button class="admin-button primary"><?=$editingField['id'] === '' ? 'Add Field' : 'Save Field'?></button>
      <?php if ($editingField['id'] !== ''): ?><a class="admin-button secondary" href="<?=e(base_url('admin/?page=booking-form'))?>">Cancel Editing</a><?php endif; ?>
    </form>
  </section>
</div>
<script src="<?=e(asset_url('js/booking-form.js'))?>" defer></script>
