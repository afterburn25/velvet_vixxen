<?php
declare(strict_types=1);

// Loaded only after the main admin controller's owner and CSRF checks.
if ($action === 'save_analytics_configuration') {
    $raw = filter_var($_POST['retention_days'] ?? '', FILTER_VALIDATE_INT);
    $rollups = filter_var($_POST['rollup_days'] ?? '', FILTER_VALIDATE_INT);
    if ($raw === false || $rollups === false || !is_string($_POST['analytics_revision'] ?? null)) throw new InvalidArgumentException('Enter valid retention days and reload the saved settings.');
    $marketingStore->saveAnalyticsConfiguration(['enabled' => mm_checked($_POST['analytics_enabled'] ?? null), 'retention_days' => $raw, 'rollup_days' => $rollups],
        $_POST['analytics_revision'], mm_measurement_authorize(...));
    $notice = 'Measurement settings saved. Run maintenance to apply shortened retention now.';
} elseif ($action === 'run_analytics_maintenance') {
    $stats = $marketingStore->runAnalyticsMaintenance(app_store(), 100, mm_measurement_authorize(...));
    $notice = 'Measurement maintenance examined ' . $stats['examined'] . ' business records, projected ' . $stats['projected'] . ' new outcomes and removed ' . $stats['removed'] . ' expired records. Run another batch to continue.';
} elseif ($action === 'export_measurement_csv') {
    $report = $marketingStore->measurementReport(mm_measurement_filters($_POST));
    mm_measurement_authorize();
    $csv = \Velvet\Core\MarketingAnalytics::csv($report);
    header('Cache-Control: private, no-store, max-age=0'); header('X-Content-Type-Options: nosniff');
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="measurement-report.csv"');
    echo $csv; exit;
} elseif ($action === 'save_coupon_attribution') {
    foreach (['coupon_code','campaign_id','coupon_revision'] as $field) if (!is_string($_POST[$field] ?? null)) throw new InvalidArgumentException('Choose a saved coupon and campaign.');
    $marketingStore->saveCouponAttribution(app_store(), $_POST['coupon_code'], $_POST['campaign_id'], mm_checked($_POST['enabled'] ?? null), $_POST['coupon_revision'], mm_measurement_authorize(...));
    $notice = 'Coupon attribution saved. Future consented checkouts use this campaign; previous orders keep their saved origin.';
} elseif ($action === 'save_link_bio_presentation') {
    if (!is_string($_POST['bio_revision'] ?? null)) throw new InvalidArgumentException('Reload the links image before saving.');
    $marketingStore->saveLinkBioPresentation(['featured_asset_id' => $_POST['featured_asset_id'] ?? '', 'featured_alt' => $_POST['featured_alt'] ?? ''], $_POST['bio_revision'], mm_measurement_authorize(...));
    $notice = 'Link-in-bio featured image saved.';
}
