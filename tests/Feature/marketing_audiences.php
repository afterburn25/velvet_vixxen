<?php
declare(strict_types=1);

use Velvet\Core\MarketingAudience;
use Velvet\Core\MarketingStore;

require dirname(__DIR__, 2) . '/app/Core/MarketingStore.php';
require dirname(__DIR__, 2) . '/includes/marketing_admin.php';

// Standalone source acceptance: only marked, randomly named temporary stores.
// This test never constructs a mail service, runs a delivery worker or connects.
$fixture = sys_get_temp_dir() . '/vvx-audience-source-' . bin2hex(random_bytes(8));
if (!mkdir($fixture, 0700) || file_put_contents($fixture . '/fixture.marker', 'audience-source-test') === false) {
    throw new RuntimeException('Could not create the disposable audience fixture.');
}
$assertions = 0;
$failures = [];
$assert = static function (bool $ok, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$ok) $failures[] = $message;
};
$reject = static function (callable $operation, string $message, ?string $dataFile = null) use ($assert): void {
    $before = $dataFile === null ? null : file_get_contents($dataFile);
    $caught = false;
    try { $operation(); }
    catch (InvalidArgumentException|RuntimeException) { $caught = true; }
    $assert($caught, $message);
    if ($dataFile !== null) $assert(file_get_contents($dataFile) === $before, $message . ' Rejection changed saved data.');
};
$makeStore = static function (string $name) use ($fixture): array {
    $root = $fixture . '/' . $name;
    return [new MarketingStore($root), $root . '/storage/private/marketing/data.json', $root];
};
$subscribe = static function (MarketingStore $store, string $email, string $source, string $confirmedAt = '2024-06-01T12:00:00Z'): array {
    $result = $store->subscribe($email, 'Audience fixture', $source, true);
    if (!$store->confirm($result['token'])) throw new RuntimeException('Fixture confirmation failed.');
    $store->update(static function (array &$data) use ($result, $confirmedAt): void {
        foreach ($data['contacts'] as &$contact) if ($contact['id'] === $result['contact']['id']) {
            $contact['confirmed_at'] = $confirmedAt;
            $contact['consented_at'] = '2023-01-01T00:00:00Z';
        }
    });
    return $result['contact'];
};
$selection = static fn(array $segment): array => ['mode' => 'segment', 'segment_id' => $segment['id'], 'segment_revision' => $segment['revision']];
$newCampaign = static fn(MarketingStore $store, array $audience = ['mode' => 'all']): array => $store->upsert('campaigns', [
    'subject' => 'Audience fixture message', 'preheader' => 'Fixture only', 'body' => "Line one.\nLine two.", 'status' => 'draft', 'audience' => $audience,
]);
$deliveries = static fn(MarketingStore $store, string $id): array => array_values(array_filter($store->all()['campaign_queue'], static fn(array $row): bool => $row['campaign_id'] === $id));
$emails = static function (array $rows): array {
    $emails = array_column($rows, 'email');
    sort($emails, SORT_STRING);
    return $emails;
};
$timezone = date_default_timezone_get();

try {
    date_default_timezone_set('UTC');
    [$store, $dataFile, $root] = $makeStore('selection');
    $before = $subscribe($store, 'before@example.test', 'alpha', '2024-05-31T23:59:59Z');
    $start = $subscribe($store, 'start@example.test', 'alpha', '2024-06-01T00:00:00Z');
    $inside = $subscribe($store, 'inside@example.test', 'alpha', '2024-06-15T12:30:00+00:00');
    $end = $subscribe($store, 'end@example.test', 'alpha', '2024-07-01T00:00:00Z');
    $beta = $subscribe($store, 'beta@example.test', 'beta');
    $upper = $subscribe($store, 'upper@example.test', 'Alpha');
    $store->subscribe('pending@example.test', 'Pending fixture', 'alpha', true);
    $revoked = $subscribe($store, 'revoked@example.test', 'alpha');
    $suppressed = $subscribe($store, 'suppressed@example.test', 'alpha');
    $flagged = $subscribe($store, 'flagged@example.test', 'alpha');
    $undated = $subscribe($store, 'undated@example.test', 'alpha');
    $noConsent = $subscribe($store, 'noconsent@example.test', 'alpha');
    $noToken = $subscribe($store, 'notoken@example.test', 'alpha');
    $store->update(static function (array &$data) use ($inside, $revoked, $suppressed, $flagged, $undated, $noConsent, $noToken): void {
        $data['consents'][] = ['email' => $revoked['email'], 'type' => 'newsletter', 'granted' => false];
        $data['suppressions'][] = ['email' => $suppressed['email'], 'active' => true];
        foreach ($data['contacts'] as &$contact) {
            if ($contact['id'] === $flagged['id']) $contact['suppressed'] = true;
            if ($contact['id'] === $undated['id']) $contact['confirmed_at'] = 'not-a-date';
            if ($contact['id'] === $noConsent['id']) $contact['consented_at'] = null;
            if ($contact['id'] === $noToken['id']) $contact['unsubscribe_token'] = '';
        }
        unset($contact);
        foreach ($data['contacts'] as $contact) if ($contact['id'] === $inside['id']) {
            $contact['id'] = 'duplicate_fixture_contact';
            $contact['email'] = strtoupper($contact['email']);
            $data['contacts'][] = $contact;
            break;
        }
        $data['owner_fixture_unrelated'] = ['preserve' => ['nested' => true]];
    });
    $rules = ['version' => 1, 'sources' => ['alpha', 'alpha'], 'confirmed_after' => '2024-06-01T01:00:00+01:00', 'confirmed_before' => '2024-07-01T00:00:00Z'];
    $segment = $store->saveSegment(['name' => 'June alpha', 'enabled' => true, 'rules' => $rules]);
    $assert($segment['rules'] === ['version' => 1, 'sources' => ['alpha'], 'confirmed_after' => '2024-06-01T00:00:00Z', 'confirmed_before' => '2024-07-01T00:00:00Z'], 'Rules did not persist in a canonical UTC format.');
    $assert((new MarketingStore($root))->segment($segment['id']) === $segment, 'A fresh store did not read the saved segment exactly.');
    $saved = file_get_contents($dataFile);
    $preview = $store->previewAudience($selection($segment));
    $assert($preview['eligible_count'] === 2 && $preview['new_count'] === 2, 'Preview crossed a date, source, consent, suppression or duplicate boundary.');
    $assert($store->previewSegment($rules)['eligible_count'] === 2, 'Unsaved rules preview differs from saved audience selection.');
    $assert(file_get_contents($dataFile) === $saved, 'Preview wrote marketing data.');
    $previewText = json_encode($preview, JSON_THROW_ON_ERROR);
    $assert(!str_contains($previewText, '@') && !str_contains($previewText, 'unsubscribe_token') && !array_key_exists('recipients', $preview), 'Preview exposed contact addresses or tokens.');
    $campaign = $newCampaign($store, $selection($segment));
    $assert($store->queueCampaign($campaign['id'], $preview['revision']) === 2, 'Queue and the exact displayed preview disagree.');
    $rows = $deliveries($store, $campaign['id']);
    $assert($emails($rows) === ['inside@example.test', 'start@example.test'], 'Queue did not apply inclusive start, exclusive end and exact source matching.');
    $assert(array_column($rows, 'contact_id') === [$start['id'], $inside['id']], 'Queue did not freeze the selected contact identities.');
    $assert(($store->all()['owner_fixture_unrelated'] ?? null) === ['preserve' => ['nested' => true]], 'Audience operations changed unrelated marketing data.');
    $queued = $store->campaign($campaign['id']);
    $assert($queued['audience_snapshot']['rules'] === $segment['rules'] && $queued['audience_snapshot']['segment_revision'] === $segment['revision'], 'First queue did not save the selected rule revision.');
    $assert($queued['delivery_snapshot']['body'] === "Line one.\nLine two.", 'Queue changed legitimate multiline campaign body text.');

    $newSegment = $store->saveSegment(['id' => $segment['id'], 'name' => 'Beta now', 'enabled' => false, 'rules' => ['sources' => ['beta']], 'expected_revision' => $segment['revision']]);
    $assert($newSegment['revision'] !== $segment['revision'], 'A segment edit did not change its revision.');
    $reject(fn() => $store->saveSegment(['id' => $segment['id'], 'name' => 'Old edit', 'enabled' => true, 'rules' => $rules, 'expected_revision' => $segment['revision']]), 'A stale segment edit was accepted.', $dataFile);
    $reject(fn() => $store->deleteSegment($segment['id'], $segment['revision']), 'A stale segment removal was accepted.', $dataFile);
    $reject(fn() => $store->previewAudience($selection($segment)), 'A stale segment selection was accepted.', $dataFile);
    $reject(fn() => $newCampaign($store, $selection($newSegment)), 'A disabled segment was selected by a new campaign.', $dataFile);
    $store->deleteSegment($newSegment['id'], $newSegment['revision']);
    $assert($store->segment($segment['id']) === null, 'Audience removal did not persist.');
    $assert($store->campaign($campaign['id'])['audience_snapshot'] === $queued['audience_snapshot'], 'Editing or removing a segment changed queued campaign rules.');
    $assert($deliveries($store, $campaign['id']) === $rows, 'Editing or removing a segment changed queued addresses.');
    $late = $subscribe($store, 'late-alpha@example.test', 'alpha');
    $subscribe($store, 'late-beta@example.test', 'beta');
    $assert($deliveries($store, $campaign['id']) === $rows, 'New confirmation silently appended campaign deliveries.');
    $requeuePreview = $store->previewCampaignAudience($campaign['id']);
    $assert($requeuePreview['new_count'] === 1 && $requeuePreview['existing_count'] === 2, 'Requeue preview used changed/deleted segment rules or existing addresses.');
    $assert($store->queueCampaign($campaign['id'], $requeuePreview['revision']) === 1, 'Explicit requeue did not append exactly the new frozen-rule match.');
    $assert($emails($deliveries($store, $campaign['id'])) === ['inside@example.test', 'late-alpha@example.test', 'start@example.test'], 'Explicit requeue broadened the original audience.');
    $assert($store->queueCampaign($campaign['id']) === 0, 'Repeated explicit queue duplicated deliveries.');
    $reject(fn() => $store->upsert('campaigns', ['id' => $campaign['id'], 'audience' => ['mode' => 'all']]), 'A queued campaign changed its audience.', $dataFile);
    $reject(fn() => $store->upsert('campaigns', ['id' => $campaign['id'], 'body' => 'Changed frozen text']), 'A queued campaign changed its message.', $dataFile);
    $reject(fn() => $store->upsert('campaigns', ['id' => $campaign['id'], 'audience_snapshot' => $queued['audience_snapshot']]), 'A caller could supply a queue-owned audience snapshot.', $dataFile);

    // The selected addresses are frozen. Current eligibility may still revoke them,
    // but a later source change alone must not retarget or suppress a saved address.
    $store->update(static function (array &$data) use ($start, $campaign): void {
        foreach ($data['contacts'] as &$contact) if ($contact['id'] === $start['id']) $contact['source'] = 'beta';
        foreach ($data['campaign_queue'] as $i => &$row) if ($row['campaign_id'] === $campaign['id']) $row['status'] = ['sent', 'unknown', 'failed'][$i % 3];
    });
    $frozenRows = $deliveries($store, $campaign['id']);
    $assert($store->eligibleContact($store->all(), $start['id'], $start['email']) !== null, 'A later source change revoked otherwise valid consent for the saved address.');
    $assert($store->queueCampaign($campaign['id']) === 0 && $deliveries($store, $campaign['id']) === $frozenRows, 'Requeue duplicated or reset accepted/unknown/failed delivery outcomes.');
    $assert($store->unsubscribe($late['unsubscribe_token']), 'Fixture unsubscribe failed.');
    $lateRows = array_values(array_filter($deliveries($store, $campaign['id']), static fn(array $row): bool => $row['email'] === $late['email']));
    $assert($lateRows[0]['status'] === 'suppressed' && $store->eligibleContact($store->all(), $late['id'], $late['email']) === null, 'Unsubscribe stopped suppressing selected queued recipients.');
    $assert($store->queueCampaign($campaign['id']) === 0, 'Suppressed recipient entered the same campaign again.');

    [$fresh, $freshFile] = $makeStore('stale-preview');
    $first = $subscribe($fresh, 'one@example.test', 'alpha');
    $savedAudience = $fresh->saveSegment(['name' => 'Alpha', 'enabled' => true, 'rules' => ['sources' => ['alpha']]]);
    $draft = $newCampaign($fresh, $selection($savedAudience));
    $stalePreview = $fresh->previewCampaignAudience($draft['id']);
    $subscribe($fresh, 'two@example.test', 'alpha');
    $reject(fn() => $fresh->queueCampaign($draft['id'], $stalePreview['revision']), 'A stale eligible-recipient preview was queued.', $freshFile);
    $assert(!array_key_exists('audience_snapshot', $fresh->campaign($draft['id'])) && $deliveries($fresh, $draft['id']) === [], 'Rejected preview partially froze a draft or appended deliveries.');
    $currentPreview = $fresh->previewCampaignAudience($draft['id']);
    $fresh->recordEvent('page_view', ['path' => '/blog/']);
    $assert($fresh->queueCampaign($draft['id'], $currentPreview['revision']) === 2, 'Unrelated analytics invalidated an exact recipient preview.');
    $secondDraft = $newCampaign($fresh, $selection($savedAudience));
    $savedAudience = $fresh->saveSegment(['id' => $savedAudience['id'], 'name' => 'Changed', 'enabled' => true, 'rules' => ['sources' => ['beta']], 'expected_revision' => $savedAudience['revision']]);
    $reject(fn() => $fresh->queueCampaign($secondDraft['id']), 'A draft silently used audience edits made after its selection.', $freshFile);
    $fresh->deleteSegment($savedAudience['id'], $savedAudience['revision']);
    $reject(fn() => $fresh->queueCampaign($secondDraft['id']), 'A draft with a removed audience fell back to the whole list.', $freshFile);
    $unknown = ['mode' => 'segment', 'segment_id' => 'missing_segment', 'segment_revision' => str_repeat('a', 64)];
    $reject(fn() => $newCampaign($fresh, $unknown), 'A missing audience was selected by a new campaign.', $freshFile);
    $fresh->update(static function (array &$data) use ($draft): void {
        foreach ($data['campaigns'] as &$record) if ($record['id'] === $draft['id']) $record['audience_snapshot']['rules']['membership'] = 'paid';
    });
    $reject(fn() => $fresh->queueCampaign($draft['id']), 'An unsupported frozen predicate broadened to all contacts.', $freshFile);

    [$validation, $validationFile] = $makeStore('validation');
    $invalidRules = [null, 'alpha', ['version' => 2], ['version' => '1'], ['version' => null], ['membership' => 'paid'],
        ['sources' => null], ['sources' => 'alpha'], ['sources' => ['key' => 'alpha']], ['sources' => [['alpha']]], ['sources' => ['']],
        ['sources' => ["alpha\nother"]], ['sources' => ["\xC3\x28"]], ['sources' => [str_repeat('a', 121)]],
        ['sources' => array_map(static fn(int $i): string => 'source-' . $i, range(1, 21))],
        ['sources' => ['alpha'], 'confirmed_after' => 'yesterday'], ['sources' => ['alpha'], 'confirmed_after' => '2024-02-30T00:00:00Z'],
        ['sources' => ['alpha'], 'confirmed_before' => '2024-06-01'], ['sources' => ['alpha'], 'confirmed_after' => '2024-01-01T00:00:00+14:30'],
        ['sources' => ['alpha'], 'confirmed_after' => '2024-01-01T25:00:00Z'], ['sources' => ['alpha'], 'confirmed_after' => '0999-01-01T00:00:00Z'],
        ['confirmed_after' => '2024-07-01T00:00:00Z', 'confirmed_before' => '2024-06-01T00:00:00Z'],
        ['confirmed_after' => '2024-06-01T00:00:00Z', 'confirmed_before' => '2024-06-01T00:00:00Z'], []];
    foreach ($invalidRules as $i => $invalid) $reject(fn() => $validation->saveSegment(['name' => 'Invalid', 'enabled' => true, 'rules' => $invalid]), 'Invalid rule case ' . $i . ' was saved.', $validationFile);
    foreach (['', "Hidden\nName", ['nested'], str_repeat('a', 121)] as $i => $name) $reject(fn() => $validation->saveSegment(['name' => $name, 'enabled' => true, 'rules' => ['sources' => ['alpha']]]), 'Invalid segment name case ' . $i . ' was saved.', $validationFile);
    $reject(fn() => $validation->saveSegment(['name' => 'Bad enabled', 'enabled' => '1', 'rules' => ['sources' => ['alpha']]]), 'Non-boolean segment state was saved.', $validationFile);
    $reject(fn() => $validation->upsert('segments', ['name' => 'Bypass', 'enabled' => true, 'rules' => ['referral' => 'paid']]), 'Generic upsert bypassed segment validation.', $validationFile);
    $reject(fn() => $validation->delete('segments', 'anything'), 'Generic deletion bypassed segment revisions.', $validationFile);
    $valid = $validation->upsert('segments', ['name' => 'Valid', 'enabled' => true, 'rules' => ['sources' => ['zeta', 'alpha', 'zeta']]]);
    $assert($valid['rules']['sources'] === ['alpha', 'zeta'], 'Sources were not sorted and deduplicated exactly.');
    $reject(fn() => $validation->saveSegment(['id' => $valid['id'], 'name' => 'Missing revision', 'enabled' => true, 'rules' => $valid['rules']]), 'Segment update lacked an edit revision.', $validationFile);
    $reject(fn() => $validation->previewAudience(['mode' => 'all', 'rules' => ['sources' => ['alpha']]]), 'Extra audience selection fields were accepted.', $validationFile);
    $reject(fn() => $validation->previewAudience(['mode' => 'segment', 'segment_id' => $valid['id'], 'segment_revision' => ['nested']]), 'A nested audience revision was accepted.', $validationFile);
    $reject(fn() => MarketingAudience::selectionFromForm(['all']), 'A nested select option was accepted.');
    $assert(MarketingAudience::selectionFromForm($valid['id'] . ':' . $valid['revision']) === $selection($valid), 'The owner select option lost its saved revision.');
    $reject(fn() => mm_campaign_selection(['id' => 'existing_campaign']), 'An older edit form without an audience silently selected all contacts.');
    $assert(mm_campaign_selection([]) === ['mode' => 'all'], 'Legacy new campaigns lost their default all-eligible audience.');
    $assert(MarketingAudience::text(str_repeat('é', 255), 255, 'Campaign subject') === str_repeat('é', 255), 'The subject character limit was treated as a byte limit.');
    $assert(MarketingAudience::text(str_repeat('é', 500), 500, 'Campaign preheader') === str_repeat('é', 500), 'A valid preheader was truncated in the middle of UTF-8 text.');
    $reject(fn() => MarketingAudience::sourcesFromText("alpha\t"), 'A trailing control was silently stripped from a source.');
    $assert(MarketingAudience::sourcesFromText(" alpha \r\nbeta\r\n") === ['alpha', 'beta'], 'Textarea source line normalization failed.');
    $reject(fn() => mm_checked(['1']), 'An array checkbox bypassed owner-form validation.');
    $assert(mm_checked('1') && !mm_checked(null), 'Normal checkbox values changed meaning.');
    $reject(fn() => MarketingAudience::formDate('2024-02-30T12:00'), 'An impossible owner date was normalized silently.');
    $reject(fn() => MarketingAudience::formDate('tomorrow'), 'A relative owner date was accepted.');
    date_default_timezone_set('America/New_York');
    $assert(MarketingAudience::formDate('2024-06-01T12:00') === '2024-06-01T16:00:00Z', 'Owner date did not use the configured site timezone.');
    $assert(MarketingAudience::dateForForm('2024-06-01T16:00:00Z') === '2024-06-01T12:00:00', 'Saved UTC date did not round-trip to the owner form.');
    $reject(fn() => MarketingAudience::formDate('2024-03-10T02:30'), 'A nonexistent daylight-saving time was silently normalized.');
    date_default_timezone_set('UTC');
    $validation->update(static function (array &$data): void {
        $data['segments'] = array_merge([['id' => 'legacy_invalid', 'name' => 'Legacy unsupported', 'enabled' => true, 'rules' => ['tier' => 'paid']]], $data['segments']);
    });
    $assert($validation->segment('legacy_invalid')['valid'] === false, 'Unsupported legacy segment was marked selectable.');
    $valid = $validation->saveSegment(['id' => $valid['id'], 'name' => 'After invalid legacy record', 'enabled' => true, 'rules' => $valid['rules'], 'expected_revision' => $valid['revision']]);
    $assert($validation->segment('legacy_invalid')['name'] === 'Legacy unsupported' && $validation->segment($valid['id'])['name'] === 'After invalid legacy record', 'Editing one segment replaced an unrelated legacy record.');

    [$legacy, $legacyFile] = $makeStore('legacy');
    $legacyAlpha = $subscribe($legacy, 'legacy-alpha@example.test', 'alpha');
    $subscribe($legacy, 'legacy-beta@example.test', 'beta');
    $legacyDraft = $legacy->upsert('campaigns', ['name' => 'Old draft without audience', 'body' => 'Old draft body']);
    $assert($legacy->queueCampaign($legacyDraft['id']) === 2, 'A legacy draft lost its all-eligible audience.');
    $oldQueued = $legacy->upsert('campaigns', ['name' => 'Old queued campaign', 'body' => 'Old queued body', 'delivery_snapshot' => ['subject' => 'Old queued campaign', 'preheader' => '', 'body' => 'Old queued body']]);
    $legacy->update(static function (array &$data) use ($oldQueued, $legacyAlpha): void {
        foreach ($data['campaigns'] as &$record) if ($record['id'] === $oldQueued['id']) $record['audience'] = 'unused historical metadata';
        $data['campaign_queue'][] = ['id' => 'legacy_prepared', 'campaign_id' => $oldQueued['id'], 'contact_id' => $legacyAlpha['id'], 'email' => $legacyAlpha['email'], 'status' => 'prepared', 'prepared_at' => '2024-01-01T00:00:00Z'];
    });
    $assert($legacy->previewCampaignAudience($oldQueued['id'])['new_count'] === 1, 'A legacy queued campaign reinterpreted unused audience metadata.');
    $assert($legacy->queueCampaign($oldQueued['id']) === 1, 'Explicit legacy queue did not retain the all-eligible append behavior.');
    $oldRows = $deliveries($legacy, $oldQueued['id']);
    $assert($oldRows[0]['status'] === 'queued' && $oldRows[0]['legacy_prepared_at'] === '2024-01-01T00:00:00Z', 'Legacy prepared rows were not released by explicit queueing.');
    $assert($legacy->campaign($oldQueued['id'])['audience_snapshot']['mode'] === 'all', 'Legacy queued audience did not freeze explicitly as all eligible.');

    // Real server-rendered form helpers: no browser, authentication or transport
    // shortcuts are claimed by these rendering checks.
    session_save_path($fixture);
    require dirname(__DIR__, 2) . '/includes/marketing.php';
    function csrf_field(): string { return '<input type="hidden" name="csrf" value="disposable-render-fixture">'; }
    $hostile = $validation->saveSegment(['name' => '<script>fixture</script>', 'enabled' => true, 'rules' => ['sources' => ['<img src=x>', '123']]]);
    $subscribe($validation, 'numeric-source@example.test', '123');
    $subscribe($validation, 'escaped-source@example.test', '<img src=x>');
    $render = static function (string $path, array $vars): string {
        extract($vars, EXTR_SKIP);
        ob_start();
        try { require $path; return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    };
    $_GET = [];
    $html = $render(dirname(__DIR__, 2) . '/app/Views/admin/marketing-audiences.php', ['marketingStore' => $validation, 'data' => $validation->all(), 'segments' => $validation->segments()]);
    $assert(str_contains($html, '&lt;script&gt;fixture&lt;/script&gt;') && !str_contains($html, '<script>fixture'), 'Audience name was not escaped in the actual owner view.');
    $assert(str_contains($html, '&lt;img src=x&gt;') && !str_contains($html, '<img src=x>') && str_contains($html, '<code>123</code>'), 'Saved source rendering broke numeric sources or markup escaping.');
    $assert(str_contains($html, 'name="segment_revision" value="' . $hostile['revision'] . '"'), 'Audience delete form omitted its displayed edit revision.');
    $staleValue = $hostile['id'] . ':' . str_repeat('0', 64);
    $html = $render(dirname(__DIR__, 2) . '/app/Views/admin/marketing-campaign-form.php', ['marketingStore' => $validation, 'segments' => $validation->segments(), 'campaignForm' => ['audience_selection' => $staleValue, 'body' => '</textarea><script>fixture</script>']]);
    $assert(str_contains($html, 'The audience changed; select it again') && !str_contains($html, 'value="all" selected'), 'Stale campaign selection silently selected the whole list.');
    $assert(str_contains($html, '&lt;/textarea&gt;&lt;script&gt;fixture&lt;/script&gt;'), 'Rejected campaign body was not safely restored.');
    $assert(!is_dir($root . '/storage/private/marketing/outbox'), 'Audience operations created a message outbox.');
} catch (Throwable $e) {
    $failures[] = get_class($e) . ': ' . $e->getMessage();
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    date_default_timezone_set($timezone);
    if (is_dir($fixture) && file_get_contents($fixture . '/fixture.marker') === 'audience-source-test') {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($fixture);
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Newsletter audience acceptance failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'Newsletter audience source acceptance passed (' . $assertions . " assertions; disposable stores, no mail transport).\n";
