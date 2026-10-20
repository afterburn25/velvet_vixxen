<?php
declare(strict_types=1);

use App\Core\MigrationRunner;

$root = dirname(__DIR__, 2);
require $root . '/includes/version.php';
require $root . '/includes/autoload.php';

$host = getenv('VELVET_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('VELVET_TEST_DB_PORT') ?: 3306);
$name = getenv('VELVET_TEST_DB_NAME') ?: '';
$user = getenv('VELVET_TEST_DB_USER') ?: '';
$password = getenv('VELVET_TEST_DB_PASSWORD') ?: '';

if ($name === '' || !preg_match('/(?:^|_)test(?:_|$)/i', $name)) {
    fwrite(STDERR, "Refusing to run: VELVET_TEST_DB_NAME must clearly identify a test database.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
if ($existing !== []) {
    fwrite(STDERR, "Refusing to run against a non-empty database. Existing tables: " . implode(', ', $existing) . "\n");
    exit(1);
}

$runner = new MigrationRunner($pdo, $root . '/database/migrations');
$applied = [];
try {
    $applied = $runner->migrate();
    $required = [
        'admins','settings','installation_state','addons','members','membership_plans','subscriptions',
        'photo_galleries','photo_gallery_items','videos','audio_albums','audio_tracks','orders',
        'content_entitlements','member_media_state','content_access_rules','audio_playlists','audio_playlist_items','video_playlists','video_playlist_items',
        'member_auth_tokens','member_sessions','member_account_events','mail_outbox',
        'payment_providers','coupons','commerce_bundles','commerce_bundle_items','coupon_redemptions','payment_webhook_events',
        'addon_activity_log','addon_settings','addon_backups','addon_tasks',
    ];
    foreach (glob($root . '/database/migrations/*.php') ?: [] as $file) {
        $definition = require $file;
        foreach ($definition['up'] as $sql) {
            if (preg_match('/CREATE TABLE IF NOT EXISTS\s+`?([a-z0-9_]+)`?/i', $sql, $match)) $required[] = strtolower($match[1]);
        }
    }
    $required = array_values(array_unique($required));
    $tables = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($required, $tables));
    if ($missing !== []) {
        throw new RuntimeException('Missing migrated tables: ' . implode(', ', $missing));
    }
    $videoColumns = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM videos')->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('caption_asset_id', $videoColumns, true)) {
        throw new RuntimeException('videos.caption_asset_id was not migrated.');
    }
    $memberColumns = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM members')->fetchAll(PDO::FETCH_COLUMN));
    foreach (['preferences_json','failed_login_count','locked_until','last_seen_at'] as $column) {
        if (!in_array($column, $memberColumns, true)) {
            throw new RuntimeException('members.' . $column . ' was not migrated.');
        }
    }
    $orderColumns = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN));
    foreach (['refunded_total','provider_key','provider_checkout_id','idempotency_key','billing_email','metadata_json'] as $column) {
        if (!in_array($column, $orderColumns, true)) {
            throw new RuntimeException('orders.' . $column . ' was not migrated.');
        }
    }
    $orderStatus = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($orderStatus) || !str_contains((string) ($orderStatus['Type'] ?? ''), "'processing'")) {
        throw new RuntimeException('orders.status does not include processing.');
    }
    $orderItemColumns = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM order_items')->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('fulfillment_status', $orderItemColumns, true)) {
        throw new RuntimeException('order_items.fulfillment_status was not migrated.');
    }
    $transactionColumns = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM payment_transactions')->fetchAll(PDO::FETCH_COLUMN));
    foreach (['last4','brand'] as $column) {
        if (!in_array($column, $transactionColumns, true)) {
            throw new RuntimeException('payment_transactions.' . $column . ' was not migrated.');
        }
    }
    $stateColumns = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM member_media_state')->fetchAll(PDO::FETCH_COLUMN));
    foreach (['view_count','completed_at','metadata_json'] as $column) {
        if (!in_array($column, $stateColumns, true)) {
            throw new RuntimeException('member_media_state.' . $column . ' was not migrated.');
        }
    }

    $addonColumns = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM addons')->fetchAll(PDO::FETCH_COLUMN));
    foreach (['package_sha256','signature_status','installed_path','previous_version','failure_count','last_error'] as $column) {
        if (!in_array($column, $addonColumns, true)) {
            throw new RuntimeException('addons.' . $column . ' was not migrated.');
        }
    }

    if (count($applied) < 12) {
        throw new RuntimeException('Expected all twelve baseline migrations, got ' . count($applied));
    }
    if ($runner->migrate() !== []) throw new RuntimeException('Reapplying unchanged migrations was not idempotent.');
    require $root . '/tests/Database/database_update_scenarios.php';
    require $root . '/tests/Database/merchandise_scenarios.php';
    require $root . '/tests/Database/booking_form_scenarios.php';
    require $root . '/tests/Database/media_center_mysql.php';
    echo 'MySQL migrations applied successfully (' . count($applied) . ' migrations, ' . count($required) . " declared tables).\n";
} finally {
    if ($applied !== []) {
        $runner->rollbackKeys(array_reverse($applied));
    }
    $pdo->exec('DROP TABLE IF EXISTS migrations');
}

$remaining = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if ($remaining !== []) {
    fwrite(STDERR, 'Rollback left tables behind: ' . implode(', ', $remaining) . PHP_EOL);
    exit(1);
}

echo "MySQL migration rollback passed.\n";
