<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Inspect distributed runtime files without reading or changing installed data. */
class RuntimeInventoryService
{
    public function __construct(private readonly string $root)
    {
    }

    public function inspect(): array
    {
        clearstatcache(true);
        return $this->inspectManifest($this->manifest());
    }

    private function inspectManifest(array $manifest): array
    {
        $problems = [];
        foreach ($manifest['files'] as $relative => $expected) {
            $path = $this->regularFile($relative);
            if ($path === null) {
                $problems[] = ['path' => $relative, 'status' => 'Missing, unreadable or linked file'];
                continue;
            }
            $actual = @hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                $problems[] = ['path' => $relative, 'status' => 'Different file contents'];
            }
        }
        $runningBuild = defined('VELVET_VIXXEN_BUILD_ID') ? (string) VELVET_VIXXEN_BUILD_ID : '';
        return [
            'version' => $manifest['version'], 'build_id' => $manifest['build_id'],
            'running_build_id' => $runningBuild,
            'running_asset_version' => defined('VELVET_VIXXEN_ASSET_VERSION') ? (string) VELVET_VIXXEN_ASSET_VERSION : '',
            'files_checked' => count($manifest['files']), 'problems' => $problems,
            'files_match' => $problems === [],
            'running_build_matches' => $runningBuild === $manifest['build_id'],
        ];
    }

    /** Invalidate this distribution's PHP files only, never a shared host's whole cache. */
    public function refreshPhpCache(): array
    {
        clearstatcache(true);
        $manifest = $this->manifest();
        $status = $this->inspectManifest($manifest);
        if (!$status['files_match']) {
            throw new RuntimeException('The update is incomplete. Replace the files listed below before refreshing PHP.');
        }
        $enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
            && (PHP_SAPI !== 'cli'
                || filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN));
        if (!$enabled) {
            return ['status' => 'disabled', 'files' => 0];
        }
        if (!function_exists('opcache_invalidate')) {
            throw new RuntimeException('This host does not allow the site to refresh PHP. Ask hosting support to clear this site\'s PHP OPcache, then reload this page.');
        }
        $count = 0;
        $failed = [];
        foreach ($manifest['files'] as $relative => $expectedHash) {
            if (!str_ends_with($relative, '.php')) continue;
            $path = $this->regularFile($relative);
            if ($path === null || !hash_equals($expectedHash, (string) @hash_file('sha256', $path))
                || !@opcache_invalidate($path, true)) {
                $failed[] = $relative;
            } else {
                $count++;
            }
        }
        clearstatcache(true);
        if ($failed !== []) {
            throw new RuntimeException('The host could not refresh all application PHP files. Ask hosting support to clear this site\'s PHP OPcache, then reload this page.');
        }
        if (!$this->inspectManifest($manifest)['files_match']) {
            throw new RuntimeException('Application files changed during the PHP refresh. Finish extracting the update and check again.');
        }
        return ['status' => 'refreshed', 'files' => $count];
    }

    private function manifest(): array
    {
        $path = $this->regularFile('includes/runtime-build.json');
        if ($path === null || @filesize($path) > 2_000_000) {
            throw new RuntimeException('The update file inventory is missing or unreadable. Extract the complete Upgrade ZIP into the active site folder and replace existing files.');
        }
        try {
            $data = json_decode((string) @file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('The update file inventory is invalid. Extract the complete Upgrade ZIP again.');
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['product'] ?? '') !== 'Velvet Vixxen'
            || !is_string($data['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/D', $data['version'])
            || !is_string($data['build_id'] ?? null) || !preg_match('/^\d{8}\.\d+$/D', $data['build_id'])
            || !is_array($data['files'] ?? null) || count($data['files']) > 2000) {
            throw new RuntimeException('The update file inventory is invalid. Extract the complete Upgrade ZIP again.');
        }
        $requiredFiles = ['admin/update.php', 'admin/index.php', 'app/Core/RuntimeBuildService.php', 'includes/version.php',
            'app/Views/admin/composer.php', 'public/assets/js/composer.js', 'public/assets/js/api-client.js',
            'admin/repair.php', 'app/Core/RuntimeInventoryService.php', 'app/Core/RootLayoutService.php',
            'app/Views/admin/repair-status.php', 'route.php', 'public/routes/api/upload.php',
            'public/routes/api/scene.php', 'app/Support/public_routes.php', 'public/router.php'];
        // Older inventories remain inspectable; this dependency first shipped in 0.8.10.
        if (version_compare($data['version'], '0.8.10', '>=')) {
            $requiredFiles[] = 'public/assets/js/scene-geometry.js';
        }
        // Menu dependencies first ship in 0.8.11; older inventories keep their own floor.
        if (version_compare($data['version'], '0.8.11', '>=')) {
            $requiredFiles = array_merge($requiredFiles, [
                'app/Core/MenuService.php',
                'app/Core/MenuStoreTrait.php',
                'app/Support/menu_admin.php',
                'app/Views/admin/menus-actions.php',
                'app/Views/admin/menus.php',
                'app/Views/public/partials/menu-items.php',
                'public/assets/css/menu-admin.css',
                'public/assets/css/navigation.css',
                'public/assets/css/site-ux.css',
                'public/assets/js/menu-admin.js',
                'public/assets/js/navigation.js',
            ]);
        }
        // Depth and foreground masking first ship in 0.8.12.
        if (version_compare($data['version'], '0.8.12', '>=')) {
            $requiredFiles = array_merge($requiredFiles, [
                'app/Core/SceneDepth.php',
                'public/assets/js/scene-depth.js',
            ]);
        }
        // The publishing calendar and post expiry first ship in 0.8.13.
        if (version_compare($data['version'], '0.8.13', '>=')) {
            $requiredFiles = array_merge($requiredFiles, [
                'app/Core/MarketingCalendar.php',
                'app/Views/admin/media-center.php',
                'app/Core/MarketingPublication.php',
                'app/Core/MarketingHistory.php',
                'app/Core/MarketingStorage.php',
                'app/Core/MarketingStore.php',
                'app/Core/Store.php',
                'app/Views/admin/marketing.php',
                'app/Views/admin/marketing-calendar.php',
                'app/Views/admin/marketing-publication-editor.php',
                'app/Views/admin/marketing-publication-list.php',
                'app/Views/admin/marketing-history.php',
                'includes/marketing.php',
                'includes/marketing_admin.php',
                'public/assets/css/marketing-calendar.css',
            ]);
        }
        // Scene scheduling first ships in 0.8.14; prior inventories keep their own floor.
        if (version_compare($data['version'], '0.8.14', '>=')) {
            $requiredFiles = array_merge($requiredFiles, [
                'app/Core/SceneSchedule.php',
                'app/Core/SceneScheduleStoreTrait.php',
                'app/Console/run_scenes.php',
                'app/Support/scene_schedule_admin.php',
                'app/Views/admin/scene-schedules-actions.php',
                'app/Views/admin/scene-schedules.php',
                'public/assets/css/scene-schedules.css',
            ]);
        }
        // 0.9 safeguards and marketing operations require their complete runtime.
        if (version_compare($data['version'], '0.9.0', '>=')) {
            $requiredFiles = array_merge($requiredFiles, [
                'app/Console/run_analytics.php',
                'app/Console/run_mail_events.php',
                'app/Console/run_notifications.php',
                'app/Core/AdminSecurity.php',
                'app/Core/AdminSecurityStoreTrait.php',
                'app/Core/CampaignMessage.php',
                'app/Core/CampaignTestService.php',
                'app/Core/MailProviderService.php',
                'app/Core/MarketingAnalytics.php',
                'app/Core/MarketingAnalyticsTrait.php',
                'app/Core/MarketingAttribution.php',
                'app/Core/MarketingAttributionAdminTrait.php',
                'app/Core/MarketingAttributionBridge.php',
                'app/Core/MarketingAttributionTrait.php',
                'app/Core/MarketingBusinessStoreTrait.php',
                'app/Core/MarketingCampaignMessages.php',
                'app/Core/MarketingMeasurementSqlRepository.php',
                'app/Core/MarketingPrivacy.php',
                'app/Core/MarketingProviderEvents.php',
                'app/Core/MarketingRecovery.php',
                'app/Core/NotificationDiscovery.php',
                'app/Core/NotificationProviderEvents.php',
                'app/Core/NotificationService.php',
                'app/Core/NotificationStoreTrait.php',
                'app/Core/PrivateState.php',
                'app/Core/SendGridSignature.php',
                'app/Core/Totp.php',
                'app/Support/marketing_recovery_admin.php',
                'app/Views/admin/campaign-message-preview.php',
                'app/Views/admin/login.php',
                'app/Views/admin/marketing-analytics.php',
                'app/Views/admin/marketing-attribution.php',
                'app/Views/admin/marketing-deleted-actions.php',
                'app/Views/admin/marketing-deleted.php',
                'app/Views/admin/marketing-email-brand.php',
                'app/Views/admin/marketing-link-bio.php',
                'app/Views/admin/marketing-measurement-actions.php',
                'app/Views/admin/marketing-notifications-actions.php',
                'app/Views/admin/marketing-notifications.php',
                'app/Views/admin/marketing-provider-actions.php',
                'app/Views/admin/marketing-provider.php',
                'app/Views/admin/security-actions.php',
                'app/Views/admin/security.php',
                'database/migrations/202610210016_marketing_measurements.php',
                'database/migrations/202610210017_private_state.php',
                'public/assets/css/marketing-deleted.css',
                'public/assets/css/security-admin.css',
                'public/routes/api/mail-events.php',
                'public/routes/campaign/click.php',
                'public/routes/privacy/choices.php',
            ]);
        }
        foreach ($requiredFiles as $required) {
            if (!isset($data['files'][$required])) {
                throw new RuntimeException('The update file inventory is incomplete. Extract the complete Upgrade ZIP again.');
            }
        }
        foreach ($data['files'] as $relative => $hash) {
            if (!is_string($relative) || !$this->runtimePath($relative) || $relative === 'includes/runtime-build.json'
                || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('The update file inventory contains an invalid runtime entry. Extract the complete Upgrade ZIP again.');
            }
        }
        return $data;
    }

    private function runtimePath(string $relative): bool
    {
        if ($relative === '' || str_contains($relative, '\\') || preg_match('/[\x00-\x20\x7f]/', $relative)) return false;
        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || (str_starts_with($part, '.') && $part !== '.htaccess')) return false;
        }
        if (count($parts) === 1) return in_array($relative, ['.htaccess', 'index.php', 'route.php', 'VERSION'], true);
        if (in_array($parts[0], ['admin', 'app', 'images', 'includes', 'public', 'templates', 'vendor'], true)) return true;
        if ($parts[0] === 'database') return $relative === 'database/.htaccess'
            || $relative === 'database/backups/.htaccess'
            || (count($parts) === 3 && $parts[1] === 'migrations' && str_ends_with($relative, '.php'));
        if ($parts[0] === 'addons') return in_array($relative, ['addons/.htaccess', 'addons/installed/.htaccess',
            'addons/staging/.htaccess', 'addons/packages/velvet-welcome-banner-1.0.0.zip',
            'addons/packages/velvet-welcome-banner-1.0.1.zip'], true);
        return false;
    }

    private function regularFile(string $relative): ?string
    {
        $root = realpath($this->root);
        if ($root === false || !$this->runtimePath($relative)) return null;
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) return null;
        }
        $real = realpath($path);
        return $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            && is_file($real) && is_readable($real) ? $real : null;
    }
}
