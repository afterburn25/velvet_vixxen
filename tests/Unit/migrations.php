<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = glob($root . '/database/migrations/*.php') ?: [];
sort($files);
$ids = [];
$tables = [];
$upSql = '';
$tableSql = [];
foreach ($files as $file) {
    $migration = require $file;
    if (!is_array($migration) || empty($migration['id']) || empty($migration['up']) || !isset($migration['down'])) {
        fwrite(STDERR, 'Invalid migration: ' . basename($file) . PHP_EOL);
        exit(1);
    }
    if (isset($ids[$migration['id']])) {
        fwrite(STDERR, 'Duplicate migration id: ' . $migration['id'] . PHP_EOL);
        exit(1);
    }
    $ids[$migration['id']] = true;
    foreach ($migration['up'] as $sql) {
        $upSql .= "\n" . $sql;
        if (preg_match('/CREATE TABLE IF NOT EXISTS\s+`?([a-z0-9_]+)`?/i', $sql, $match)) {
            $table = strtolower($match[1]);
            if (isset($tables[$table])) {
                fwrite(STDERR, 'Duplicate table definition: ' . $table . PHP_EOL);
                exit(1);
            }
            $tables[$table] = true;
        }
        if (preg_match('/(?:CREATE TABLE IF NOT EXISTS|ALTER TABLE)\s+`?([a-z0-9_]+)`?/i', $sql, $match)) {
            $tableSql[strtolower($match[1])] = ($tableSql[strtolower($match[1])] ?? '') . "\n" . $sql;
        }
    }
}
$required = [
    'admins','settings','installation_state','addons','members','membership_plans','subscriptions',
    'photo_galleries','photo_gallery_items','videos','audio_albums','audio_tracks','audio_playlists',
    'audio_playlist_items','video_playlists','video_playlist_items','orders','content_entitlements',
    'member_media_state','content_access_rules','member_auth_tokens','member_sessions','member_account_events','mail_outbox',
    'payment_providers','coupons','commerce_bundles','commerce_bundle_items','coupon_redemptions','payment_webhook_events',
    'addon_activity_log','addon_settings','addon_backups','addon_tasks',
    'addon_license_products','addon_license_keys','addon_license_activations','addon_releases','addon_update_checks','addon_license_transactions','addon_download_tokens',
    'message_threads','messages','message_attachments','saved_replies','communication_blocks','content_requests','booking_requests','request_attachments','member_notifications',
    'marketing_posts','marketing_categories','marketing_post_categories','newsletter_contacts','newsletter_segments','newsletter_segment_members','marketing_campaigns','marketing_campaign_recipients','marketing_banners','marketing_landing_pages','marketing_links','referral_codes','analytics_events','consent_records',
];
$missing = array_values(array_filter($required, static fn(string $table): bool => !isset($tables[$table])));
if ($missing !== []) {
    fwrite(STDERR, 'Missing foundation tables: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}
foreach (['preferences_json','failed_login_count','locked_until','last_seen_at','view_count','completed_at','metadata_json'] as $column) {
    if (!str_contains(strtolower($upSql), strtolower($column))) {
        fwrite(STDERR, "Required member migration column is missing: {$column}.\n");
        exit(1);
    }
}
if (count($ids) < 12) {
    fwrite(STDERR, 'Expected all twelve baseline migrations.' . PHP_EOL);
    exit(1);
}
$videoMigration = (string) file_get_contents($root . '/database/migrations/202610180006_media_center_metadata.php');
if (!str_contains($videoMigration, 'caption_asset_id')) { fwrite(STDERR, "Video caption migration is missing.\n"); exit(1); }

// Check executable migrations, not an out-of-date optional schema snapshot.
$adminSql = strtolower($tableSql['admins'] ?? '');
if (str_contains($adminSql, 'failed_login_count') || str_contains($adminSql, 'preferences_json')) {
    fwrite(STDERR, "Member security columns were incorrectly placed on admins.\n");
    exit(1);
}
foreach ([
    'members' => ['preferences_json','failed_login_count','locked_until','last_seen_at'],
    'orders' => ['processing','refunded_total','provider_key','idempotency_key','billing_email','metadata_json'],
    'message_threads' => ['member_id','internal_notes','member_unread_count','admin_unread_count'],
    'addon_license_activations' => ['installation_id','domain_name','status'],
] as $table => $columns) {
    foreach ($columns as $column) {
        if (!str_contains(strtolower($tableSql[$table] ?? ''), $column)) {
            fwrite(STDERR, "Required migrated column is missing: {$table}.{$column}.\n");
            exit(1);
        }
    }
}


foreach (['package_sha256','signature_status','installed_path','previous_version','failure_count','last_error'] as $column) {
    if (!str_contains(strtolower($upSql), $column)) {
        fwrite(STDERR, "Required add-on registry column is missing: {$column}.\n");
        exit(1);
    }
}
echo 'Migration manifest test passed (' . count($ids) . ' migrations, ' . count($tables) . ' tables).' . PHP_EOL;
