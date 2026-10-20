<?php
declare(strict_types=1);

$sourceRoot = dirname(__DIR__, 2);
$fixtureRoot = sys_get_temp_dir() . '/vvx-booking-form-test-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot . '/config', 0700, true);
mkdir($fixtureRoot . '/storage', 0700, true);
define('ROOT_PATH', $fixtureRoot);
spl_autoload_register(static function (string $class) use ($sourceRoot): void {
    if (!str_starts_with($class, 'App\\')) return;
    $path = $sourceRoot . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require_once $path;
});
require_once $sourceRoot . '/includes/installation_recovery.php';
require_once $sourceRoot . '/app/Support/functions.php';
file_put_contents($fixtureRoot . '/config/app.php', "<?php\nreturn " . var_export([
    'storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'base_url' => 'https://example.test',
    'security' => ['app_key' => str_repeat('k', 48)], 'timezone' => 'America/Chicago',
], true) . ";\n");

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$fail = static function (callable $action, string $message) use ($assert): void {
    $failed = false;
    try { $action(); } catch (RuntimeException) { $failed = true; }
    $assert($failed, $message);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    @rmdir($path);
};
try {
    $store = new \App\Core\Store(new \App\Core\Config($fixtureRoot . '/config/app.php'));
    $builder = new \App\Core\BookingFormService($store);
    $definition = $builder->definition();
    $assert(count($definition['fields']) === 9, 'Existing inquiry fields must be preserved as configurable defaults.');
    $good = ['inquiry_type' => 'appearance', 'contact_name' => 'Guest Fixture', 'contact_email' => ' guest+booking@example.test ',
        'phone' => '3374508598', 'details' => 'Professional appearance fixture'];
    $valid = $builder->validate($good);
    $assert($valid['errors'] === [], 'A realistic default booking must validate.');
    $assert($valid['values']['contact_email'] === 'guest+booking@example.test', 'Email aliases must normalize correctly.');
    $assert($valid['values']['phone'] === '(337) 450-8598', 'Phone digits must be formatted before persistence.');
    $assert(\App\Core\BookingFormService::formatPhone('+1 (337) 450-8598') === '(337) 450-8598', 'Country-code US phone must normalize.');
    foreach (['337450', '1111111111', '1234567890', '3370508598', '337450859899', 'call3374508598'] as $bad) {
        $assert(isset($builder->validate(array_merge($good, ['phone' => $bad]))['errors']['phone']), 'Invalid or incomplete phone must fail server validation.');
    }
    foreach (['plain-address', 'a@localhost', 'a@@example.test', 'a b@example.test', "a@example.test\nBcc:other@example.test"] as $bad) {
        $assert(isset($builder->validate(array_merge($good, ['contact_email' => $bad]))['errors']['contact_email']), 'Invalid email must fail server validation.');
    }
    $assert(isset($builder->validate(array_merge($good, ['contact_name' => ['bad']]))['errors']['contact_name']), 'Unexpected array answers must fail safely.');
    $fail(static fn() => $store->createBookingRequest(['formValues' => array_merge($good, ['phone' => '337'])]), 'Store must run the same server validator instead of trusting caller metadata.');
    $assert($store->bookingRequests() === [], 'Invalid answers must not create partial booking records.');

    $legacy = $store->createBookingRequest(['contactName' => 'Earlier Guest', 'contactEmail' => 'legacy@example.test', 'details' => 'Before builder']);
    $assert($legacy['formSubmission'] === null, 'Existing booking API must remain compatible without a form snapshot.');
    $saved = $store->createBookingRequest(['formValues' => $good, 'formRevision' => $definition['revision']]);
    $originalSubmission = $saved['formSubmission'];
    $assert($originalSubmission['values']['phone'] === '(337) 450-8598', 'Validated values must persist with each inquiry.');
    $assert(count($originalSubmission['fields']) === 9, 'Submission must retain a full snapshot of the questions shown.');
    $assert($saved['threadId'] !== null, 'Builder inquiries must enter the existing conversation workflow.');
    $longSaved = $store->createBookingRequest(['formValues' => array_merge($good, ['details' => str_repeat('a', 10000)])]);
    $assert(strlen($longSaved['formSubmission']['values']['details']) === 10000, 'Long valid answers must remain complete in the snapshot.');
    $longMessages = $store->communicationMessages($longSaved['threadId']);
    $assert(strlen($longMessages[0]['body']) < 10000, 'Conversation summary must fit the message limit without rejecting a valid inquiry.');

    $add = static function (array $input) use ($builder): array {
        $updated = $builder->saveField(array_merge(['label' => 'Fixture Field', 'type' => 'text', 'enabled' => true,
            'required' => false, 'options' => '', 'minSelections' => 0], $input), $builder->definition()['revision']);
        return $updated['fields'][count($updated['fields']) - 1];
    };
    $radio = $add(['label' => 'Preferred time', 'type' => 'radio', 'required' => true, 'options' => "Morning\nAfternoon\nEvening"]);
    $checkbox = $add(['label' => 'Services needed', 'type' => 'checkbox', 'required' => true, 'minSelections' => 2,
        'options' => "Appearance\nInterview\nPhotographs"]);
    $select = $add(['label' => 'Travel plan', 'type' => 'select', 'options' => "Local\nTravel"]);
    $text = $add(['label' => '<img src=x onerror=alert(1)>', 'help' => '<script>alert(1)</script>', 'type' => 'textarea']);
    $customEmail = $add(['label' => 'Assistant email', 'type' => 'email']);
    $customPhone = $add(['label' => 'Assistant phone', 'type' => 'phone']);
    $answers = array_merge($good, [
        $radio['id'] => $radio['options'][0]['value'],
        $checkbox['id'] => [$checkbox['options'][0]['value'], $checkbox['options'][1]['value']],
        $select['id'] => $select['options'][1]['value'],
        $text['id'] => '<svg onload=alert(1)>private fixture</svg>',
        $customEmail['id'] => 'assistant@example.test',
        $customPhone['id'] => '2255550199',
    ]);
    $assert($builder->validate($answers)['errors'] === [], 'All supported custom field types must validate together.');
    $bad = array_merge($answers, [$radio['id'] => [$radio['options'][0]['value'], $radio['options'][1]['value']]]);
    $assert(isset($builder->validate($bad)['errors'][$radio['id']]), 'Radio group must reject multiple submitted answers.');
    $bad = array_merge($answers, [$radio['id'] => 'forged-option']);
    $assert(isset($builder->validate($bad)['errors'][$radio['id']]), 'Radio group must reject unconfigured choices.');
    $bad = array_merge($answers, [$checkbox['id'] => [$checkbox['options'][0]['value'], $checkbox['options'][0]['value']]]);
    $assert(isset($builder->validate($bad)['errors'][$checkbox['id']]), 'Duplicate checkbox selections must not satisfy minimum rules.');
    $bad = array_merge($answers, [$checkbox['id'] => [$checkbox['options'][0]['value'], 'forged-option']]);
    $assert(isset($builder->validate($bad)['errors'][$checkbox['id']]), 'Checkbox group must reject unconfigured choices.');
    $bad = array_merge($answers, [$select['id'] => 'forged-option']);
    $assert(isset($builder->validate($bad)['errors'][$select['id']]), 'Dropdown must reject unconfigured choices.');

    $savedCustom = $store->createBookingRequest(['formValues' => $answers, 'formRevision' => $builder->definition()['revision']]);
    $customSnapshot = $savedCustom['formSubmission'];
    $assert(\App\Core\BookingFormService::displayValue($radio, $customSnapshot['values'][$radio['id']]) === 'Morning', 'Choice answers must display their saved labels.');
    $assert(\App\Core\BookingFormService::displayValue($checkbox, $customSnapshot['values'][$checkbox['id']]) === 'Appearance, Interview', 'Multiple choices must display their saved labels.');

    $staleRevision = $builder->definition()['revision'];
    $builder->moveField($radio['id'], 'up', $staleRevision);
    $ids = array_column($builder->definition()['fields'], 'id');
    $assert(array_search($radio['id'], $ids, true) === 8, 'Move Up must change the field order.');
    $fail(static fn() => $builder->deleteField($radio['id'], $staleRevision), 'Stale owner edits must not overwrite a newer form revision.');
    $builder->toggleField($radio['id'], $builder->definition()['revision']);
    $withoutRadio = $answers;
    unset($withoutRadio[$radio['id']]);
    $assert(!isset($builder->validate($withoutRadio)['errors'][$radio['id']]), 'Disabled required fields must stop collecting and requiring answers.');
    $assert(!array_key_exists($radio['id'], $builder->validate($answers)['values']), 'Disabled-field submissions must be ignored.');
    $builder->toggleField($radio['id'], $builder->definition()['revision']);
    $builder->deleteField($checkbox['id'], $builder->definition()['revision']);
    $assert(!in_array($checkbox['id'], array_column($builder->definition()['fields'], 'id'), true), 'Deleted fields must leave future forms.');
    $assert($store->bookingRequest($savedCustom['id'])['formSubmission'] === $customSnapshot, 'Deleting or reordering fields must preserve historical questions, choices, and answers.');
    $assert($store->bookingRequest($saved['id'])['formSubmission'] === $originalSubmission, 'Default submission snapshots must remain unchanged after future edits.');
    $assert($store->bookingRequest($legacy['id'])['details'] === 'Before builder', 'Historical bookings must remain unchanged by builder edits.');

    $builder->saveField(['id' => 'contact_email', 'label' => 'Contact preference', 'type' => 'text', 'enabled' => true,
        'required' => false], $builder->definition()['revision']);
    $changedType = $builder->validate(array_merge($answers, ['contact_email' => 'Reply through my member inbox']));
    $assert(!isset($changedType['errors']['contact_email']) && $changedType['mapped']['contactEmail'] === '', 'Changing a default field type must not leave an invisible email validation requirement or send mail to text.');

    $current = $builder->definition();
    foreach ($current['fields'] as $field) {
        $builder->deleteField($field['id'], $builder->definition()['revision']);
    }
    $assert($builder->validate([])['errors'] === [], 'Owner may remove every question without requiring deleted fields.');
    $optional = $add(['label' => 'Optional choices', 'type' => 'checkbox', 'required' => false, 'minSelections' => 2, 'options' => "One\nTwo\nThree"]);
    $assert($builder->validate([])['errors'] === [], 'Optional multiple-choice field may remain unanswered.');
    $assert(isset($builder->validate([$optional['id'] => [$optional['options'][0]['value']]])['errors'][$optional['id']]), 'Optional group must enforce its minimum when answered.');
    $fail(static fn() => $builder->saveField(['label' => 'Broken choices', 'type' => 'checkbox', 'options' => "One\nTwo", 'minSelections' => 3], $builder->definition()['revision']), 'Minimum selection count cannot exceed available choices.');
    $fail(static fn() => $builder->saveField(['label' => 'Invalid type', 'type' => 'script'], $builder->definition()['revision']), 'Unsupported field types must not persist.');
    $fail(static fn() => $builder->saveField(['label' => 'Duplicate choices', 'type' => 'select', 'options' => "Same\nSame"], $builder->definition()['revision']), 'Repeated choices must not persist.');

    $optionalSaved = $store->createBookingRequest(['formValues' => []]);
    $assert($optionalSaved['contactEmail'] === '' && $optionalSaved['contactName'] === '', 'Removed contact questions must not be made mandatory by legacy storage.');
    $assert($optionalSaved['formSubmission']['values'][$optional['id']] === [], 'Blank optional answers must be snapshotted accurately.');

    // Render hostile labels/answers through the real public partial; no app bootstrap.
    $malicious = $add(['label' => '<img src=x onerror=alert(1)>', 'type' => 'textarea', 'help' => '<script>alert(1)</script>']);
    $bookingBuilder = $builder;
    $bookingDefinition = $builder->definition();
    $bookingValues = [$malicious['id'] => '</textarea><script>alert(1)</script>'];
    $bookingFieldErrors = [];
    ob_start();
    require $sourceRoot . '/app/Views/public/partials/booking-fields.php';
    $html = ob_get_clean();
    $assert(!str_contains($html, '<script>') && !str_contains($html, '<img src=x'), 'Question labels, help and answers must be escaped in public HTML.');
    $assert(str_contains($html, '&lt;script&gt;'), 'Escaped user input should remain visible as text.');

    $limiter = new \App\Core\BookingSubmissionLimiter($fixtureRoot, str_repeat('k', 48));
    $limiter->consume('fixture-client', 2, 900);
    $limiter->consume('fixture-client', 2, 900);
    $fail(static fn() => $limiter->consume('fixture-client', 2, 900), 'Repeated attempts must be rate limited.');
    $buckets = glob($fixtureRoot . '/storage/private/booking-rate-limit/*.json') ?: [];
    $assert(count($buckets) === 1 && !str_contains(file_get_contents($buckets[0]), 'fixture-client'), 'Limiter must retain hashed buckets without raw client identities.');

    echo "PASS booking form builder: {$checks} assertions (disposable fixtures only)\n";
} finally {
    $remove($fixtureRoot);
}
