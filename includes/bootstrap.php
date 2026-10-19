<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/includes/installation_recovery.php';
vvx_attempt_automatic_installation_recovery(ROOT_PATH);

require_once ROOT_PATH . '/includes/version.php';
require_once ROOT_PATH . '/includes/autoload.php';
require_once ROOT_PATH . '/includes/helpers.php';

$configuredTimezone = (string) app_config('timezone', 'America/Chicago');
if (in_array($configuredTimezone, DateTimeZone::listIdentifiers(), true)) {
    date_default_timezone_set($configuredTimezone);
}

require_once ROOT_PATH . '/includes/security.php';
