<?php
declare(strict_types=1);
/** @var \App\Core\BookingFormService $bookingBuilder */
/** @var array $bookingDefinition */
/** @var array $bookingValues */
/** @var array $bookingFieldErrors */
foreach ($bookingDefinition['fields'] as $bookingField):
    if (!$bookingField['enabled']) continue;
    $bookingFieldId = $bookingField['id'];
    $bookingFieldType = $bookingField['type'];
    $bookingInputName = $bookingBuilder->inputName($bookingFieldId);
    $bookingInputId = 'booking-' . $bookingFieldId;
    $bookingValue = $bookingValues[$bookingFieldId] ?? '';
    $bookingError = $bookingFieldErrors[$bookingFieldId] ?? '';
    $bookingChoices = in_array($bookingFieldType, ['radio', 'checkbox'], true);
?>
<div class="booking-field <?=in_array($bookingFieldType, ['textarea','radio','checkbox'], true) ? 'booking-field-wide' : ''?>" data-booking-type="<?=e($bookingFieldType)?>" data-booking-required="<?=$bookingField['required'] ? 'true' : 'false'?>" data-booking-minimum="<?=e((string) $bookingField['minSelections'])?>">
<?php if ($bookingChoices): ?>
<fieldset aria-describedby="<?=e($bookingInputId)?>-help <?=e($bookingInputId)?>-error">
  <legend><?=e($bookingField['label'])?> <small><?=$bookingField['required'] ? '(required)' : '(optional)'?></small></legend>
  <div class="booking-choice-list">
  <?php foreach ($bookingField['options'] as $bookingChoice):
      $bookingChecked = $bookingFieldType === 'checkbox' ? in_array($bookingChoice['value'], is_array($bookingValue) ? $bookingValue : [], true) : $bookingValue === $bookingChoice['value'];
  ?>
    <label><input type="<?=$bookingFieldType === 'checkbox' ? 'checkbox' : 'radio'?>" name="<?=e($bookingInputName)?><?=$bookingFieldType === 'checkbox' ? '[]' : ''?>" value="<?=e($bookingChoice['value'])?>" <?=$bookingChecked ? 'checked' : ''?> <?=$bookingField['required'] && $bookingFieldType === 'radio' ? 'required' : ''?>><span><?=e($bookingChoice['label'])?></span></label>
  <?php endforeach; ?>
  </div>
</fieldset>
<?php else: ?>
<label for="<?=e($bookingInputId)?>"><?=e($bookingField['label'])?> <small><?=$bookingField['required'] ? '(required)' : '(optional)'?></small></label>
<?php if ($bookingFieldType === 'textarea'): ?>
<textarea id="<?=e($bookingInputId)?>" name="<?=e($bookingInputName)?>" maxlength="<?=e((string) $bookingField['maxLength'])?>" placeholder="<?=e($bookingField['placeholder'])?>" <?=$bookingField['required'] ? 'required' : ''?> aria-describedby="<?=e($bookingInputId)?>-help <?=e($bookingInputId)?>-error" <?= $bookingError !== '' ? 'aria-invalid="true"' : ''?>><?=e(is_string($bookingValue) ? $bookingValue : '')?></textarea>
<?php elseif ($bookingFieldType === 'select'): ?>
<select id="<?=e($bookingInputId)?>" name="<?=e($bookingInputName)?>" <?=$bookingField['required'] ? 'required' : ''?> aria-describedby="<?=e($bookingInputId)?>-help <?=e($bookingInputId)?>-error"><option value="">Choose an option</option><?php foreach ($bookingField['options'] as $bookingChoice): ?><option value="<?=e($bookingChoice['value'])?>" <?=$bookingValue === $bookingChoice['value'] ? 'selected' : ''?>><?=e($bookingChoice['label'])?></option><?php endforeach; ?></select>
<?php else:
    $bookingInputType = $bookingFieldType === 'phone' ? 'tel' : $bookingFieldType;
?>
<input id="<?=e($bookingInputId)?>" type="<?=e($bookingInputType)?>" name="<?=e($bookingInputName)?>" maxlength="<?=e((string) $bookingField['maxLength'])?>" placeholder="<?=e($bookingField['placeholder'])?>" value="<?=e(is_string($bookingValue) ? $bookingValue : '')?>" <?=$bookingField['required'] ? 'required' : ''?> <?=$bookingFieldType === 'phone' ? 'inputmode="tel" autocomplete="tel-national"' : ''?> <?=$bookingFieldType === 'email' ? 'autocomplete="email"' : ''?> aria-describedby="<?=e($bookingInputId)?>-help <?=e($bookingInputId)?>-error" <?= $bookingError !== '' ? 'aria-invalid="true"' : ''?>>
<?php endif; ?>
<?php endif; ?>
<p id="<?=e($bookingInputId)?>-help" class="booking-field-help"><?=e($bookingField['help'])?></p>
<p id="<?=e($bookingInputId)?>-error" class="booking-field-error" role="status" aria-live="polite"><?=$bookingError !== '' ? e($bookingError) : ''?></p>
</div>
<?php endforeach; ?>
