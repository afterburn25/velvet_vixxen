<?php
declare(strict_types=1);

namespace App\Core {
    // Exercise copy/verify fallback deterministically without changing production code.
    function rename(string $source, string $target): bool
    {
        return !empty($GLOBALS['vvx_test_force_rename_failure']) ? false : \rename($source, $target);
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/app/Core/RepairBackupService.php';
    use App\Core\RepairBackupService;

    $fixtures = sys_get_temp_dir() . '/vvx-backup-test-' . bin2hex(random_bytes(8));
    mkdir($fixtures, 0700, true);
    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$checks): void {
        if (!$condition) throw new RuntimeException($message);
        $checks++;
    };
    $expectFailure = static function (callable $action, string $message) use ($assert): void {
        $failed = false;
        try { $action(); } catch (RuntimeException) { $failed = true; }
        $assert($failed, $message);
    };
    $write = static function (string $path, string $contents): void {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        if (file_put_contents($path, $contents) !== strlen($contents)) throw new RuntimeException('Fixture write failed.');
    };
    $newRoot = static function (string $name) use ($fixtures, $write): string {
        $root = $fixtures . '/' . $name;
        $write($root . '/storage/private/sentinel.txt', 'private fixture sentinel');
        $write($root . '/uploads/existing.txt', 'current upload');
        return $root;
    };
    $encode = static fn(string $path): string => rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);
            return;
        }
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
        }
        @rmdir($path);
    };

    try {
        $root = $newRoot('merge-and-preserve');
        $backup = $root . '/.vvx-private-repair-uploads-first';
        $write($backup . '/existing.txt', 'older upload');
        $write($backup . '/nested/missing.txt', 'only backup copy');
        $write($backup . '/.vvx-write-important', 'unique probe-looking filename');
        mkdir($backup . '/empty', 0700, true);
        $service = new RepairBackupService($root);
        $before = $service->inspect($backup);
        $assert($before['fileCount'] === 3, 'Inspection must count dot files and probe-like filenames.');
        $assert($before['differentCount'] === 1 && $before['missingCount'] === 4, 'Inspection must identify different files and missing directories.');
        $assert(!$before['safeToDelete'] && $before['canMerge'], 'Unique ordinary backup must be restorable and undeletable.');
        foreach (['', 'DELETE UNIQUE BACKUP', 'DELETE VERIFIED BACKUP'] as $confirmation) {
            $expectFailure(static fn() => $service->delete($before['id'], $confirmation), 'Unique backup deletion must be refused for every confirmation.');
        }
        $assert(file_get_contents($backup . '/nested/missing.txt') === 'only backup copy', 'Refused deletion must preserve unique content.');
        $result = $service->mergeMissing($before['id']);
        $assert($result === ['copied' => 2, 'skipped' => 1], 'Merge must copy missing files and skip existing files.');
        $assert(file_get_contents($root . '/uploads/existing.txt') === 'current upload', 'Merge must not overwrite an existing upload.');
        $assert(file_get_contents($root . '/uploads/nested/missing.txt') === 'only backup copy', 'Missing uploads must be restored exactly.');
        $assert(file_get_contents($root . '/uploads/.vvx-write-important') === 'unique probe-looking filename', 'Probe-like files must be preserved and restored.');
        $assert(is_dir($root . '/uploads/empty'), 'Missing empty directories must be restored.');
        $assert(file_get_contents($backup . '/existing.txt') === 'older upload', 'Merge must preserve the different original in the backup.');
        $expectFailure(static fn() => $service->delete($before['id'], 'DELETE VERIFIED BACKUP'), 'Different older upload must still prevent deletion after merge.');
        $assert(file_get_contents($root . '/storage/private/sentinel.txt') === 'private fixture sentinel', 'Unrelated private runtime files must remain unchanged.');

        $root = $newRoot('duplicate-delete');
        $backup = $root . '/.vvx-private-repair-uploads-duplicate';
        $write($backup . '/existing.txt', 'current upload');
        $service = new RepairBackupService($root);
        $info = $service->inspect($backup);
        $assert($info['safeToDelete'], 'Fully verified duplicate should allow explicit deletion.');
        $expectFailure(static fn() => $service->delete($info['id']), 'Duplicate deletion must require typed confirmation.');
        $assert(is_file($backup . '/existing.txt'), 'Unconfirmed duplicate must remain in place.');
        $write($root . '/uploads/existing.txt', 'changed after inspection');
        $expectFailure(static fn() => $service->delete($info['id'], 'DELETE VERIFIED BACKUP'), 'Deletion must reverify live contents when the action runs.');
        $assert(is_file($backup . '/existing.txt'), 'Changed live data must preserve the now-unique backup.');
        $write($root . '/uploads/existing.txt', 'current upload');
        $service->delete($info['id'], 'DELETE VERIFIED BACKUP');
        $assert(!is_dir($backup), 'Confirmed verified duplicate should be deleted.');
        $assert(file_get_contents($root . '/uploads/existing.txt') === 'current upload', 'Deleting a duplicate must preserve the live upload.');

        $root = $newRoot('archive-and-list');
        $backup = $root . '/.vvx-private-repair-uploads-visible';
        $write($backup . '/unique.txt', 'archived unique content');
        $service = new RepairBackupService($root);
        $oldId = $service->inspect($backup)['id'];
        $result = $service->archiveRootResidues();
        $assert($result === ['moved' => 1, 'failed' => []], 'Root residues must move to private archive.');
        $assert(!is_dir($backup), 'Successfully archived root residue should leave the root.');
        $rows = $service->backups();
        $assert(count($rows) === 1 && $rows[0]['location'] === 'archive', 'Dot-prefixed archived backups must stay visible in the manager.');
        $assert(file_get_contents($rows[0]['path'] . '/unique.txt') === 'archived unique content', 'Archiving must preserve unique bytes.');
        $assert(!$rows[0]['safeToDelete'], 'Archiving must not mark unique data deletable.');
        $expectFailure(static fn() => $service->mergeMissing($oldId), 'An old root-location identifier must not target another folder after a move.');
        $assert($service->mergeMissing($rows[0]['id'])['copied'] === 1, 'The listed archived backup must still be restorable.');
        $assert(file_get_contents($root . '/uploads/unique.txt') === 'archived unique content', 'Restoring from archive must recover exact contents.');
        $write($backup . '/second.txt', 'second backup same original name');
        $service->archiveRootResidues();
        $rows = $service->backups();
        $assert(count($rows) === 2, 'Archive name collisions must preserve both backups.');
        $allContents = [];
        foreach ($rows as $row) {
            foreach (glob($row['path'] . '/*.txt') ?: [] as $path) $allContents[] = file_get_contents($path);
        }
        sort($allContents);
        $assert($allContents === ['archived unique content', 'second backup same original name'], 'A collision must never overwrite an earlier backup.');

        $root = $newRoot('copy-fallback');
        $backup = $root . '/.vvx-private-repair-uploads-fallback';
        $write($backup . '/nested/unique.txt', 'copy fallback unique content');
        mkdir($backup . '/empty', 0700, true);
        $service = new RepairBackupService($root);
        $GLOBALS['vvx_test_force_rename_failure'] = true;
        try { $result = $service->archiveRootResidues(); } finally { $GLOBALS['vvx_test_force_rename_failure'] = false; }
        $assert($result === ['moved' => 1, 'failed' => []], 'Archive copy fallback must verify before removing the original.');
        $rows = $service->backups();
        $assert(count($rows) === 1 && !is_dir($backup), 'Verified fallback should preserve one complete archived copy.');
        $assert(file_get_contents($rows[0]['path'] . '/nested/unique.txt') === 'copy fallback unique content', 'Fallback archiving must preserve nested content.');
        $assert(is_dir($rows[0]['path'] . '/empty'), 'Fallback archiving must preserve empty directories.');

        $root = $newRoot('identity-files');
        $configBackup = $root . '/.vvx-private-repair-config-identity';
        $storageBackup = $root . '/.vvx-private-repair-storage-identity';
        $write($configBackup . '/app.php', 'saved configuration identity');
        $write($configBackup . '/extra.php', 'other missing config');
        $write($storageBackup . '/install.lock', 'saved installation lock identity');
        $write($storageBackup . '/extra.txt', 'other missing storage');
        $service = new RepairBackupService($root);
        $configId = $service->inspect($configBackup)['id'];
        $storageId = $service->inspect($storageBackup)['id'];
        $assert($service->mergeMissing($configId) === ['copied' => 1, 'skipped' => 1], 'Generic merge must leave app.php to verified installation recovery.');
        $assert($service->mergeMissing($storageId) === ['copied' => 1, 'skipped' => 1], 'Generic merge must leave install.lock to verified installation recovery.');
        $assert(!file_exists($root . '/config/app.php') && !file_exists($root . '/storage/install.lock'), 'Generic merge must not create an unverified installation identity.');
        $write($root . '/config/app.php', 'active configuration keys');
        $write($root . '/storage/install.lock', 'active installation identity');
        $service->mergeMissing($configId);
        $service->mergeMissing($storageId);
        $assert(file_get_contents($root . '/config/app.php') === 'active configuration keys', 'Existing configuration keys must never be overwritten by generic restore.');
        $assert(file_get_contents($root . '/storage/install.lock') === 'active installation identity', 'Existing installation lock must never be overwritten by generic restore.');

        $root = $newRoot('scope');
        $backup = $root . '/storage/private/repair-backups/.vvx-private-repair-uploads-scoped';
        $write($backup . '/nested/current.txt', 'scoped backup data');
        $service = new RepairBackupService($root);
        foreach ([$root, $root . '/uploads', $root . '/storage/private/repair-backups', $backup . '/nested',
            $backup . '/../.vvx-private-repair-uploads-scoped', $fixtures] as $unsafe) {
            $expectFailure(static fn() => $service->delete($encode($unsafe), 'DELETE VERIFIED BACKUP'), 'Crafted backup IDs must not select roots, nested paths, traversal, or outside directories.');
        }
        $assert(is_file($backup . '/nested/current.txt'), 'Rejected identifiers must preserve the valid backup.');
        $unknown = $root . '/storage/private/repair-backups/automatic-recovery-fixture';
        mkdir($unknown, 0700, true);
        $unknownInfo = $service->inspect($unknown);
        $assert(!$unknownInfo['safeToDelete'] && !$unknownInfo['canMerge'], 'Unknown backup types must never be treated as verified live duplicates.');
        $expectFailure(static fn() => $service->delete($unknownInfo['id'], 'DELETE VERIFIED BACKUP'), 'Unknown backup deletion must remain blocked.');
        $emptyKnown = $root . '/.vvx-private-repair-addons-empty';
        mkdir($emptyKnown, 0700, true);
        $assert(!$service->inspect($emptyKnown)['safeToDelete'], 'An empty backup without a corresponding live folder is not yet a duplicate.');

        $outside = $fixtures . '/outside';
        $write($outside . '/unique.txt', 'outside protected content');
        chmod($outside . '/unique.txt', 0600);
        $outsideMode = fileperms($outside . '/unique.txt') & 0777;
        $root = $newRoot('source-link');
        $backup = $root . '/.vvx-private-repair-uploads-links';
        mkdir($backup, 0700, true);
        symlink($outside . '/unique.txt', $backup . '/linked.txt');
        symlink($outside, $backup . '/linked-directory');
        $service = new RepairBackupService($root);
        $info = $service->inspect($backup);
        $assert(!$info['safeToDelete'] && !$info['canMerge'] && count($info['verificationIssues']) === 2, 'Linked entries must be visible as verification failures, not silently ignored.');
        $expectFailure(static fn() => $service->delete($info['id'], 'DELETE VERIFIED BACKUP'), 'Linked backup contents must block deletion.');
        $expectFailure(static fn() => $service->mergeMissing($info['id']), 'Linked backup contents must block restore.');
        $result = $service->archiveRootResidues();
        $assert($result['moved'] === 0 && count($result['failed']) === 1 && is_dir($backup), 'Unsupported linked backup must remain intact when archive verification fails.');
        $assert(file_get_contents($outside . '/unique.txt') === 'outside protected content', 'Operations must never change linked outside contents.');
        clearstatcache(true, $outside . '/unique.txt');
        $assert((fileperms($outside . '/unique.txt') & 0777) === $outsideMode, 'Operations must never widen linked outside file permissions.');

        $root = $newRoot('destination-link');
        $backup = $root . '/.vvx-private-repair-uploads-target-link';
        $write($backup . '/aa-first.txt', 'must not copy before validation');
        $write($backup . '/zz-linked/new.txt', 'must not write outside');
        symlink($outside, $root . '/uploads/zz-linked');
        $service = new RepairBackupService($root);
        $info = $service->inspect($backup);
        $assert(!$info['safeToDelete'] && !$info['canMerge'], 'A linked live destination must disable verified actions.');
        $expectFailure(static fn() => $service->mergeMissing($info['id']), 'Merge must reject linked destination parents.');
        $assert(!file_exists($root . '/uploads/aa-first.txt'), 'All destination paths must be validated before copying any file.');
        $assert(!file_exists($outside . '/new.txt'), 'Merge must not write through a linked parent.');
        unlink($root . '/uploads/zz-linked');
        symlink($outside . '/unique.txt', $root . '/uploads/aa-first.txt');
        $expectFailure(static fn() => $service->mergeMissing($info['id']), 'Merge must reject linked target files.');
        $assert(file_get_contents($outside . '/unique.txt') === 'outside protected content', 'Merge must preserve the target of a linked file.');

        $root = $newRoot('archive-link');
        $backup = $root . '/.vvx-private-repair-uploads-archive-link';
        $write($backup . '/unique.txt', 'source kept when archive linked');
        symlink($outside, $root . '/storage/private/repair-backups');
        $service = new RepairBackupService($root);
        $expectFailure(static fn() => $service->archiveRootResidues(), 'Linked archive parent must block all archive writes.');
        $assert(is_file($backup . '/unique.txt') && !file_exists($outside . '/' . basename($backup)), 'Linked archive must not move a backup outside the root.');
        symlink($outside, $root . '/.vvx-private-repair-uploads-top-link');
        $expectFailure(static fn() => $service->inspect($root . '/.vvx-private-repair-uploads-top-link'), 'Top-level linked backups must not resolve to an outside directory.');

        $root = $newRoot('legacy-routes');
        $write($root . '/route.php', 'router fixture');
        $write($root . '/public/router.php', 'router fixture');
        $write($root . '/public/routes/account/index.php', 'new account route');
        $write($root . '/public/routes/media.php', 'new media route');
        $write($root . '/account/index.php', 'old account route');
        $write($root . '/account/uploads/unique.bin', 'unexpected unique legacy content');
        $write($root . '/media.php', 'old media route');
        $write($root . '/music/index.php', 'route missing consolidated replacement');
        $service = new RepairBackupService($root);
        $result = $service->archiveLegacyRootEntries();
        $assert($result['moved'] === ['account', 'media.php'] && count($result['failed']) === 1, 'Legacy archiving must require corresponding replacement routes.');
        $assert(file_get_contents($result['archive'] . '/account/uploads/unique.bin') === 'unexpected unique legacy content', 'Legacy archival must preserve unexpected unique contents.');
        $assert(file_get_contents($result['archive'] . '/media.php') === 'old media route', 'Legacy file archival must preserve exact contents.');
        $assert(is_file($root . '/music/index.php'), 'A legacy route without a replacement must remain available.');
        $assert(is_file($root . '/public/routes/account/index.php') && is_file($root . '/public/routes/media.php'), 'Consolidated replacement routes must stay unchanged.');

        echo "PASS repair backups: {$checks} assertions (disposable fixtures only)\n";
    } finally {
        $GLOBALS['vvx_test_force_rename_failure'] = false;
        $remove($fixtures);
    }
}
