<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\PrivateState;
use App\Core\ProtectionDelivery;
use App\Core\ProtectionRecords as Protection;
use App\Core\ProtectionResources as Resources;
use App\Core\ProtectionUploads;
use App\Core\Store;
use Velvet\Core\MarketingStorage;
use Velvet\Core\MarketingStore;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-protection-source-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true); mkdir($root . '/storage', 0700); mkdir($root . '/uploads', 0755);
file_put_contents($root . '/.owned-fixture', 'Disposable protection source fixture.');
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require_once $source . '/app/Support/functions.php';
require_once $source . '/app/Core/MarketingStore.php';
require_once $source . '/tests/Installer/database_fixture.php';
$mysql = in_array('--mysql', $argv, true); $pdo = null; $owns = false; $checks = 0;
$assert = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($why); };
$reject = static function (callable $action, string $why, ?int $code = null) use ($assert): void {
    try { $action(); } catch (InvalidArgumentException|RuntimeException $error) { $assert($code === null || $error->getCode() === $code, $why . ': ' . $error->getMessage()); return; }
    $assert(false, $why);
};
$config = new Config($root . '/config/app.php');
$guard = static function (): void {};
try {
    $values = ['storage_mode' => $mysql ? 'mysql' : 'file', 'file_store_path' => 'storage/data.json', 'base_url' => 'http://127.0.0.1:9/fixture',
        'installed' => true, 'installation_id' => 'protection-test-' . bin2hex(random_bytes(12)),
        'security' => ['app_key' => bin2hex(random_bytes(32)), 'media_key' => bin2hex(random_bytes(32))]];
    if ($mysql) { [$db, $pdo] = vvx_installer_fixture_database(); vvx_installer_fixture_empty($pdo); $owns = true; $values['db'] = $db; }
    $config->write($values);
    file_put_contents($root . '/storage/install.lock', json_encode(['installation_id' => $values['installation_id']], JSON_THROW_ON_ERROR));
    if ($mysql) {
        (new App\Core\MigrationRunner($pdo, $source . '/database/migrations'))->migrate(false, true);
        (new Store($config))->setSetting('installation', ['installation_id' => $values['installation_id'], 'database_hash' => hash('sha256', $db['name'] . '|' . $values['installation_id'])]);
        mkdir($root . '/database/migrations', 0700, true);
        foreach (glob($source . '/database/migrations/*.php') as $migration) copy($migration, $root . '/database/migrations/' . basename($migration));
    }
    else file_put_contents($root . '/storage/data.json', '{}');
    $state = new PrivateState($config);
    $protection = new Protection($config, $state);
    $assert($protection->snapshot()['ready'] === false && $protection->publicationAllowed('asset', 'legacy') && !is_dir($root . '/storage/private'), 'Untouched default-off reads initialized or blocked the old installation.');
    $store = new Store($config); $ownerId = (string) $store->createAdmin('Protection owner', 'protection-owner@example.test', 'ProtectionOwner12!');
    $owner = $store->findAdminByEmail('protection-owner@example.test');
    $changeOwner = static function (bool $active) use ($pdo, $store, $ownerId): void {
        if ($pdo) $pdo->prepare('UPDATE admins SET is_active=? WHERE id=?')->execute([$active ? 1 : 0, $ownerId]);
        else { $data = $store->rawFileData(); $data['admins'][$ownerId]['is_active'] = $active ? 1 : 0; $store->replaceFileData($data); }
    };
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6XioAAAAASUVORK5CYII=');
    file_put_contents($root . '/uploads/saved.png', $png); file_put_contents($root . '/uploads/.htaccess', "Options -Indexes\n");
    $asset = $store->addMedia(['id' => 'fixture-image', 'title' => 'Synthetic protection picture', 'type' => 'image', 'file' => 'saved.png', 'thumbnail' => 'saved.png', 'mime' => 'image/png', 'width' => 1, 'height' => 1, 'size' => strlen($png)]);
    $album = $store->saveAlbum(['title' => 'Saved protection album', 'slug' => 'protection-album', 'status' => 'published', 'coverAssetId' => $asset['id']]);
    $marketing = new MarketingStore($root);
    $post = $marketing->savePublication('posts', ['title' => 'Saved protection post', 'slug' => 'protection-post', 'status' => 'published', 'body' => 'Latest body must survive withdrawal.', 'featured_asset_id' => $asset['id']]);
    if ($mysql) {
        $storage = new MarketingStorage($root); $plan = $storage->status();
        $moved = $storage->migrate($plan['plan']);
        $assert($moved['mode'] === 'mysql', 'Protection source SQL mode did not activate authoritative editorial SQL storage.');
    }
    $resources = new Resources($config); $delivery = new ProtectionDelivery($config);
    $assert($delivery->url('saved.png') === null, 'Default-off path unexpectedly changed.');
    $beforeState = $state->read('protection');
    $badProbe = new ProtectionUploads($config, static fn(string $url, string $marker): int => is_file($marker) ? 200 : 404);
    $reject(fn() => (new Protection($config, $state, $badProbe))->activate($config->revision(), Protection::revision($protection->snapshot()), $owner, $guard), 'A raw-file HTTP 200 activated protection.', 503);
    $assert(!$protection->enabled() && $state->read('protection') === $beforeState && is_file($root . '/uploads/saved.png'), 'Failed denial probe enabled or moved media.');
    $assert((new ProtectionUploads($config))->privateRequired(), 'The persistent barrier did not protect a concurrent writer after an interrupted setup.');
    $assert(glob($root . '/uploads/protection-check-*.txt') === [], 'Failed denial probe left its harmless file behind.');
    foreach ([301, 302, 500, 0] as $status) {
        $probe = new ProtectionUploads($config, static fn(): int => $status);
        $reject(fn() => (new Protection($config, $state, $probe))->activate($config->revision(), Protection::revision($protection->snapshot()), $owner, $guard), 'An unproven or redirected check activated protection.', 503);
    }
    $seenProbe = false;
    $goodProbe = new ProtectionUploads($config, static function (string $url, string $marker) use (&$seenProbe, $assert): int {
        $assert(is_file($marker) && str_contains(file_get_contents($marker), 'Harmless Velvet Vixxen') && str_contains($url, '/fixture/uploads/protection-check-'), 'Denial probe was absent or escaped the configured installation.');
        $seenProbe = true; return 403;
    });
    $protection = new Protection($config, $state, $goodProbe);
    // Revoke the saved owner after a real file move. The journal cannot commit;
    // raw access stays denied and the next explicit owner setup recovers bytes.
    $revoked = false;
    $afterMove = static function () use ($root, $changeOwner, &$revoked): void {
        if (!$revoked && !is_file($root . '/uploads/saved.png') && glob($root . '/storage/private/protected-assets/*.bin') !== []) { $changeOwner(false); $revoked = true; }
    };
    $reject(fn() => $protection->activate($config->revision(), Protection::revision($protection->snapshot()), $owner, $afterMove), 'Owner revocation after IO still committed protection.', 403);
    $assert($revoked && $seenProbe && !$protection->snapshot()['ready'] && $config->get('protection.setup_pending') === true, 'Interrupted activation lost its fail-closed checkpoint.');
    $assert(!$protection->publicationAllowed('asset', $asset['id']), 'Interrupted setup served managed media.');
    $changeOwner(true);
    $protection->activate($config->revision(), Protection::revision($protection->snapshot()), $owner, $guard);
    $assert($protection->snapshot()['ready'] && !$config->get('protection.setup_pending'), 'Explicit setup retry did not finish.');
    $entry = $protection->vaultEntry('uploads/saved.png');
    $assert($entry !== null && file_get_contents($resources->vaultFile($entry)) === $png && !file_exists($root . '/uploads/saved.png'), 'Private preservation changed bytes or retained the old public file.');
    $assert((fileperms($resources->vaultFile($entry)) & 0777) === 0600, 'Protected media permissions are not private.');
    $assert(str_contains((string) $delivery->url('saved.png'), 'protected-asset.php') && $protection->publicationAllowed('asset', $asset['id']), 'Unheld private media is unavailable.');
    $before = $state->read('protection'); $configBefore = file_get_contents($config->path());
    $overview = $protection->overview($owner); $protection->snapshot(); $resources->catalog(); $delivery->resolve(hash('sha256', 'uploads/saved.png'));
    $assert($before === $state->read('protection') && $configBefore === file_get_contents($config->path()), 'Owner/public review mutated saved protection state.');
    $performerInput = ['label' => 'Synthetic collaborator', 'status' => 'active', 'document_reference' => 'urn:fixture:synthetic-document-reference', 'notes' => 'PRIVATE_COLLABORATOR_NOTE', 'reviewed_on' => gmdate('Y-m-d')];
    $person = $protection->savePerformer($performerInput, 'new', $owner, $guard);
    $personReview = $protection->review('performers', $person, $owner, $guard);
    $assert($personReview['document_reference'] === $performerInput['document_reference'] && $personReview['notes'] === 'PRIVATE_COLLABORATOR_NOTE', 'Owner review failed to decrypt its own reference.');
    $assert(!str_contains(json_encode($state->read('protection')), 'PRIVATE_COLLABORATOR_NOTE'), 'Private notes were persisted in plaintext.');
    $reject(fn() => $protection->savePerformer($performerInput + ['id' => $person], str_repeat('0', 64), $owner, $guard), 'Stale collaborator edit succeeded.', 409);
    $releaseInput = ['label' => 'Synthetic reviewed release', 'status' => 'active', 'performer_ids' => [$person], 'targets' => [['kind' => 'album', 'id' => $album['id']]], 'document_reference' => 'urn:fixture:release-reference', 'notes' => 'PRIVATE_RELEASE_NOTE', 'consent_reviewed' => true, 'expires_on' => ''];
    foreach (['consent_reviewed' => false, 'document_reference' => '', 'performer_ids' => [], 'expires_on' => '2026-02-30'] as $field => $invalid) $reject(fn() => $protection->saveRelease(array_replace($releaseInput, [$field => $invalid]), 'new', $owner, $guard), 'Invalid release approval succeeded.');
    $releaseId = $protection->saveRelease($releaseInput, 'new', $owner, $guard);
    $assert($protection->publicationAllowed('album', $album['id'], $store->album($album['id'])), 'Approved release blocked its saved asset.');
    $reportInput = ['category' => 'consent', 'label' => 'Synthetic report subject', 'name' => 'Fixture Reporter', 'email' => 'reporter@example.test', 'url' => $values['base_url'] . '/music/album.php?id=' . $album['id'], 'description' => 'PRIVATE_REPORT_BODY <script>literal</script>', 'accurate' => true];
    foreach (['accurate' => false, 'url' => 'https://foreign.example.test/media', 'email' => 'invalid', 'category' => 'unknown'] as $field => $bad) $reject(fn() => $protection->report(array_replace($reportInput, [$field => $bad]), 'rate-fixture'), 'Malformed public report was accepted.');
    $receipt = $protection->report($reportInput, 'rate-fixture'); $caseId = $receipt['id']; $key = $receipt['access_key'];
    $public = $protection->publicReview($caseId, $key);
    $assert(strlen($key) === 48 && !str_contains(json_encode($state->read('protection')), $key), 'A receipt key was persisted without hashing.');
    $assert(!str_contains(json_encode($public), 'PRIVATE_REPORT_BODY') && !isset($public['email'], $public['name'], $public['receipt_hash']), 'A reporter status projection exposed private fields.');
    $reject(fn() => $protection->publicReview($caseId, str_repeat('0', 48)), 'A foreign receipt key read a private case.', 403);
    $reject(fn() => $protection->reporterReply($caseId, $public['revision'], $key, null, 'Reply before invitation', false), 'A non-invited reply changed the case.', 409);
    $case = $protection->review('cases', $caseId, $owner, $guard);
    $action = ['message' => 'PRIVATE_OWNER_DECISION', 'public_message' => 'Please provide the missing information.', 'action' => 'request_reply'];
    $protection->caseAction($caseId, $case['revision'], $action, $owner, $guard);
    $public = $protection->publicReview($caseId, $key);
    $assert($public['can_reply'] && !str_contains(json_encode($public), 'PRIVATE_OWNER_DECISION'), 'Private owner notes appeared in the reporter timeline.');
    $protection->reporterReply($caseId, $public['revision'], $key, null, 'PRIVATE_REPORTER_REPLY', false);
    $reject(fn() => $protection->reporterReply($caseId, $public['revision'], $key, null, 'Replay', false), 'A stale reply replay succeeded.', 409);
    $case = $protection->review('cases', $caseId, $owner, $guard);
    $action = ['message' => 'PRIVATE_WITHDRAWAL_REASON', 'public_message' => 'The selected item is withdrawn while reviewed.', 'action' => 'takedown', 'targets' => [['kind' => 'album', 'id' => $album['id']]]];
    $protection->caseAction($caseId, $case['revision'], $action, $owner, $guard);
    $assert(!$protection->publicationAllowed('asset', $asset['id']) && $delivery->url('saved.png') === '', 'A takedown left a prior media address available.');
    $assert($store->albums(true) === [] && $marketing->findPost('protection-post') === null, 'Linked publications remained public under a case hold.');
    $reject(fn() => $store->saveAlbum(array_replace($album, ['status' => 'published'])), 'A media editor bypassed the publication hold.', 409);
    $reject(fn() => $marketing->savePublication('posts', ['id' => $post['id'], 'expected_revision' => $marketing->publicationRecord('posts', $post['id'])['revision'], 'status' => 'published']), 'Editorial publishing bypassed the held image.', 409);
    $case = $protection->review('cases', $caseId, $owner, $guard);
    $withdrawal = $resources->withdrawalReview($case['targets'], $case['asset_ids']);
    $freshPost = $marketing->savePublication('posts', ['id' => $post['id'], 'expected_revision' => $marketing->publicationRecord('posts', $post['id'])['revision'], 'status' => 'draft', 'body' => 'Newest draft survives lifting.']);
    $lift = ['action' => 'reinstate', 'message' => 'Reviewed request.', 'public_message' => 'This case hold is lifted; publications remain offline.', 'content_revision' => $withdrawal['revision']];
    $reject(fn() => $protection->caseAction($caseId, $case['revision'], $lift, $owner, $guard), 'Lifting a hold accepted changed linked content.', 409);
    $lift['content_revision'] = $resources->withdrawalReview($case['targets'], $case['asset_ids'])['revision'];
    $protection->caseAction($caseId, $case['revision'], $lift, $owner, $guard);
    $assert($store->album($album['id'])['status'] === 'draft' && $marketing->publicationRecord('posts', $post['id'])['body'] === 'Newest draft survives lifting.', 'Lifting republished or overwrote current content.');
    $assert(!$protection->publicationAllowed('asset', $asset['id']) && $delivery->url('saved.png') === '' && isset($protection->snapshot()['offline_assets'][$asset['id']]), 'Lifting a hold revived an old direct asset URL.');
    $assert($protection->overview($owner)['media'][0]['can_release'], 'A cleared case could not be deliberately reviewed for file release.');
    $reject(fn() => $protection->releaseMedia($asset['id'], str_repeat('0', 64), Protection::revision($store->mediaAsset($asset['id'])), $owner, $guard), 'A stale media release succeeded.', 409);
    $protection->releaseMedia($asset['id'], Protection::revision($protection->snapshot()), Protection::revision($store->mediaAsset($asset['id'])), $owner, $guard);
    $assert($protection->publicationAllowed('asset', $asset['id']) && $store->album($album['id'])['status'] === 'draft', 'Deliberate file release changed a parent publication.');
    $public = $protection->publicReview($caseId, $key);
    $protection->reporterReply($caseId, $public['revision'], $key, null, 'Synthetic appeal with additional details.', true);
    $assert($protection->publicReview($caseId, $key)['status'] === 'appealed', 'The reporter appeal was not stateful.');

    // A later release revocation and reapproval is independently bounded.
    $release = $protection->review('releases', $releaseId, $owner, $guard);
    $protection->saveRelease(array_replace($releaseInput, ['id' => $releaseId, 'status' => 'revoked']), $release['revision'], $owner, $guard);
    $assert(!$protection->publicationAllowed('asset', $asset['id']), 'A revoked release permitted media.');
    $release = $protection->review('releases', $releaseId, $owner, $guard);
    $review = $resources->withdrawalReview($release['targets'], $release['asset_ids']);
    $protection->saveRelease($releaseInput + ['id' => $releaseId, 'content_revision' => $review['revision']], $release['revision'], $owner, $guard);
    $assert(!$protection->publicationAllowed('asset', $asset['id']), 'Reapproval silently reopened prior media URLs.');
    $protection->releaseMedia($asset['id'], Protection::revision($protection->snapshot()), Protection::revision($store->mediaAsset($asset['id'])), $owner, $guard);
    $personReview = $protection->review('performers', $person, $owner, $guard);
    $protection->savePerformer(array_replace($performerInput, ['id' => $person, 'status' => 'inactive']), $personReview['revision'], $owner, $guard);
    $assert(!$protection->publicationAllowed('asset', $asset['id']), 'Inactive collaborator did not hold their released media.');
    $personReview = $protection->review('performers', $person, $owner, $guard); $links = $protection->performerLinks($protection->snapshot(), $person);
    $protection->savePerformer($performerInput + ['id' => $person, 'content_revision' => $resources->withdrawalReview($links['targets'], $links['asset_ids'])['revision']], $personReview['revision'], $owner, $guard);
    $assert(!$protection->publicationAllowed('asset', $asset['id']), 'Collaborator reactivation reopened an old media URL.');

    // Actual private-first destination: no public byte exists before registration.
    $destination = (new ProtectionUploads($config))->destination('media/new-private-first.png');
    file_put_contents($destination, $png); chmod($destination, 0600);
    $assert(str_starts_with($destination, $root . '/storage/private/') && !file_exists($root . '/uploads/media/new-private-first.png'), 'An active upload destination was public-first.');
    $newAsset = (new Store($config))->addMedia(['file' => 'media/new-private-first.png', 'thumbnail' => 'media/new-private-first.png', 'mime' => 'image/png', 'type' => 'image', 'title' => 'Private first upload']);
    $assert($protection->vaultEntry('uploads/media/new-private-first.png') !== null && $protection->publicationAllowed('asset', $newAsset['id']), 'Private-first registration lost its bytes.');

    // Saved-owner checks and malformed persisted state never reset holds.
    $valid = $state->read('protection'); $changeOwner(false);
    $reject(fn() => $protection->overview($owner), 'A revoked owner read private labels.', 403);
    $reject(fn() => $protection->review('cases', $caseId, $owner, $guard), 'A revoked owner read case details.', 403);
    $reject(fn() => $protection->savePerformer($performerInput, 'new', $owner, $guard), 'A revoked owner saved private records.', 403);
    $assert($state->read('protection') === $valid, 'Revoked owner attempts changed protection state.'); $changeOwner(true);
    $corruptions = [static function (array &$d): void { $d['schema'] = 99; }, static function (array &$d): void { unset($d['offline_assets']); },
        static function (array &$d) use ($caseId): void { $d['cases'][$caseId]['hold'] = 'false'; },
        static function (array &$d) use ($caseId): void { $d['cases'][$caseId]['receipt_hash'] = []; },
        static function (array &$d) use ($caseId): void { $d['cases'][$caseId]['events'][0]['at'] = '2026-02-30T00:00:00+00:00'; },
        static function (array &$d) use ($releaseId): void { $d['releases'][$releaseId]['unreviewed_extra'] = true; },
        static function (array &$d) use ($releaseId): void { $d['releases'][$releaseId]['document_reference_encrypted'] = 'vvx1:sodium:not-base64!'; },
        static function (array &$d) use ($person): void { $d['performers'][$person]['reviewed_on'] = '2026-02-30'; },
        static function (array &$d): void { $key = array_key_first($d['vault']); $d['vault'][$key]['storage'] = '../outside'; }];
    foreach ($corruptions as $corrupt) {
        $bad = $valid; $corrupt($bad); $state->update('protection', static function (array &$d) use ($bad): void { $d = $bad; });
        $assert(!$protection->publicationAllowed('asset', $newAsset['id']) && $delivery->url('media/new-private-first.png') === '', 'Malformed protection state failed open.');
        $reject(fn() => $protection->overview($owner), 'Malformed records were shown as valid.');
        $assert($state->read('protection') === $bad, 'A failed read overwrote malformed evidence.');
        $state->update('protection', static function (array &$d) use ($valid): void { $d = $valid; });
    }
    $state->update('protection', static function (array &$d): void { $d = []; });
    $assert(!$protection->publicationAllowed('asset', $newAsset['id']), 'Enabled but missing state served media.');
    $reject(fn() => $protection->activate($config->revision(), Protection::revision($protection->snapshot()), $owner, $guard), 'Missing configured journal was silently reset.', 503);
    $assert(!$protection->snapshot()['ready'], 'Missing journal refusal reinitialized state.');
    echo 'Protection records source passed (' . ($mysql ? 'mysql' : 'file') . ", $checks checks).\n";
} finally {
    if ($pdo && $owns) vvx_installer_fixture_reset_database($pdo, true);
    if (is_file($root . '/.owned-fixture')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $path) { if ($path->isDir() && !$path->isLink()) rmdir($path->getPathname()); else unlink($path->getPathname()); }
        rmdir($root);
    }
}
