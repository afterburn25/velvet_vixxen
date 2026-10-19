<?php
declare(strict_types=1);
$submittedForm = is_array($selected['formSubmission'] ?? null) ? $selected['formSubmission'] : null;
if ($submittedForm !== null):
?>
<link rel="stylesheet" href="<?=e(asset_url('css/booking-form.css'))?>">
<section class="booking-submitted-answers"><h3>Submitted questions and answers</h3><p>These are the questions shown when this inquiry was submitted.</p><dl>
<?php foreach ($submittedForm['fields'] as $submittedField):
    $submittedAnswer = \App\Core\BookingFormService::displayValue($submittedField, $submittedForm['values'][$submittedField['id']] ?? '');
?>
<div><dt><?=e($submittedField['label'])?></dt><dd><?=$submittedAnswer !== '' ? e($submittedAnswer) : 'Not provided'?></dd></div>
<?php endforeach; ?>
</dl></section>
<?php endif; ?>
