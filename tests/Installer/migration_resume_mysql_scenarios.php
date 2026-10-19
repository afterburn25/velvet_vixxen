<?php
declare(strict_types=1);

// Included by existing_database_mysql.php after it owns an empty disposable MySQL 8
// database and has extracted/loaded the actual fresh-install package. No fixture
// may connect to or reset a database independently of that guarded parent suite.
(static function (PDO $pdo, string $runtime, callable $assert, callable $reject, callable $reset, array $db): void {
    $rows = static function (array $snapshot, bool $includeLedger = false): array {
        if (!$includeLedger) unset($snapshot['migrations']);
        return array_map(static fn(array $state): array => $state['rows'], $snapshot);
    };
    $runner = static fn(): \App\Core\MigrationRunner => new \App\Core\MigrationRunner($pdo, $runtime . '/database/migrations');
    $rejectWithoutChanges = static function (string $object) use ($pdo, $runner, $assert, $reject): void {
        $before = vvx_installer_fixture_snapshot($pdo);
        $reject(static fn() => $runner()->validateForResume(), $object);
        $assert(vvx_installer_fixture_snapshot($pdo) === $before, 'Compatibility preflight changed schema, rows or ledger for ' . $object);
        // Even the legacy rollback flag cannot authorize down SQL in resume mode.
        $reject(static fn() => $runner()->migrate(rollbackOnFailure: true, resumeExisting: true), $object);
        $assert(vvx_installer_fixture_snapshot($pdo) === $before, 'Rejected resume changed schema, rows or ledger for ' . $object);
    };

    $reset('partially executed caption ALTER');
    vvx_installer_fixture_populate($pdo, $db, $runtime);
    $assetId = (int) $pdo->query('SELECT MIN(id) FROM media_assets')->fetchColumn();
    $assert($assetId > 0, 'Caption fixture requires a real existing media row.');
    $video = $pdo->prepare("INSERT INTO videos(slug,title,caption_asset_id,created_at,updated_at) VALUES('existing-caption-fixture','Existing caption reference',?,'2026-01-02 03:04:05','2026-01-02 03:04:05')");
    $video->execute([$assetId]);
    $pdo->exec('ALTER TABLE videos DROP FOREIGN KEY fk_videos_caption, DROP INDEX idx_videos_caption');
    $captionMigration = '202610180006_media_center_metadata';
    $pdo->prepare('DELETE FROM migrations WHERE migration_key=?')->execute([$captionMigration]);
    $before = vvx_installer_fixture_snapshot($pdo);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='videos' AND COLUMN_NAME='caption_asset_id'")->fetchColumn() === 1,
        'Partial ALTER fixture must retain the already-added caption column.');
    $runner()->validateForResume();
    $assert(vvx_installer_fixture_snapshot($pdo) === $before, 'Partial ALTER preflight must be read-only.');
    $assert($runner()->migrate(rollbackOnFailure: false, resumeExisting: true) === [$captionMigration], 'Partial ALTER must record only its missing migration.');
    $after = vvx_installer_fixture_snapshot($pdo);
    $assert($rows($after) === $rows($before), 'Partial ALTER changed an existing row, caption reference or customized sandbox field.');
    $unchangedLedger = array_values(array_filter($after['migrations']['rows'], static fn(array $row): bool => $row['migration_key'] !== $captionMigration));
    $assert($unchangedLedger === $before['migrations']['rows'], 'Partial ALTER rewrote an existing ledger record.');
    $checksum = $pdo->prepare('SELECT checksum FROM migrations WHERE migration_key=?');
    $checksum->execute([$captionMigration]);
    $assert($checksum->fetchColumn() === hash_file('sha256', $runtime . '/database/migrations/' . $captionMigration . '.php'), 'Resumed migration did not retain the historical file checksum.');
    $index = $pdo->query("SELECT NON_UNIQUE,COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='videos' AND INDEX_NAME='idx_videos_caption' ORDER BY SEQ_IN_INDEX")->fetchAll();
    $assert(count($index) === 1 && (int) $index[0]['NON_UNIQUE'] === 1 && $index[0]['COLUMN_NAME'] === 'caption_asset_id', 'Partial ALTER did not restore the correct caption index.');
    $foreignKey = $pdo->query("SELECT DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='videos' AND CONSTRAINT_NAME='fk_videos_caption'")->fetchColumn();
    $assert($foreignKey === 'SET NULL', 'Partial ALTER did not restore the caption foreign-key delete action.');
    $assert($runner()->migrate(rollbackOnFailure: false, resumeExisting: true) === [], 'Completed partial ALTER was reapplied or recorded twice.');
    $assert(vvx_installer_fixture_snapshot($pdo) === $after, 'Second resume changed the completed schema, existing rows or migration timestamps.');
    echo "PASS installer MySQL: partial caption column/index/foreign-key ALTER resumes without changing existing rows or ledger records\n";

    $reset('existing order status ENUM expansion');
    vvx_installer_fixture_populate($pdo, $db, $runtime);
    // Construct the genuine older schema using only values valid in that schema.
    // Its original ledger is retained to prove inspection does not skip recorded up SQL.
    $pdo->exec("UPDATE orders SET status='paid' WHERE status='processing'");
    $memberId = (int) $pdo->query('SELECT MIN(id) FROM members')->fetchColumn();
    $order = $pdo->prepare("INSERT INTO orders(member_id,order_number,status,grand_total,metadata_json,created_at,updated_at) VALUES(?,?,?,19.25,'{\"fixture\":\"retain status and amount\"}','2026-01-02 03:04:05','2026-01-02 03:04:05')");
    foreach (['pending', 'paid', 'failed', 'refunded', 'partially_refunded', 'chargeback', 'cancelled'] as $status) {
        $order->execute([$memberId, 'EXISTING-ENUM-' . $status, $status]);
    }
    $pdo->exec("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','paid','failed','refunded','partially_refunded','chargeback','cancelled') NOT NULL DEFAULT 'pending'");
    $before = vvx_installer_fixture_snapshot($pdo);
    $runner()->validateForResume();
    $assert(vvx_installer_fixture_snapshot($pdo) === $before, 'Older order ENUM preflight changed existing data or schema.');
    $assert($runner()->migrate(rollbackOnFailure: false, resumeExisting: true) === [], 'ENUM expansion rewrote an already-recorded migration.');
    $after = vvx_installer_fixture_snapshot($pdo);
    $assert($rows($after, true) === $rows($before, true), 'ENUM expansion changed an existing status, amount, provider setting or ledger record.');
    $statusColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch();
    $assert($statusColumn['Type'] === "enum('pending','processing','paid','failed','refunded','partially_refunded','chargeback','cancelled')"
        && $statusColumn['Null'] === 'NO' && $statusColumn['Default'] === 'pending', 'Resume did not restore the exact expanded order status definition.');
    echo "PASS installer MySQL: old order ENUM expands while every existing status, order field and ledger record remains unchanged\n";

    $reset('conflicting same-name index uniqueness');
    vvx_installer_fixture_populate($pdo, $db, $runtime);
    $pdo->exec('ALTER TABLE orders DROP INDEX uniq_orders_idempotency, ADD INDEX uniq_orders_idempotency (idempotency_key)');
    $rejectWithoutChanges('orders.uniq_orders_idempotency');
    echo "PASS installer MySQL: incompatible named index uniqueness is rejected before any mutation\n";

    $reset('conflicting same-name foreign-key action');
    vvx_installer_fixture_populate($pdo, $db, $runtime);
    // MySQL 8 requires separate statements when reusing a dropped FK name.
    $pdo->exec('ALTER TABLE videos DROP FOREIGN KEY fk_videos_caption');
    $pdo->exec('ALTER TABLE videos ADD CONSTRAINT fk_videos_caption FOREIGN KEY (caption_asset_id) REFERENCES media_assets(id) ON DELETE RESTRICT');
    $rejectWithoutChanges('videos.fk_videos_caption');
    echo "PASS installer MySQL: incompatible named foreign-key delete action is rejected before any mutation\n";
})($pdo, $runtime, $assert, $reject, $reset, $db);
