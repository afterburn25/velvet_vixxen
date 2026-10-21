<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/includes/bootstrap.php';

if (function_exists('is_installed') && is_installed()) {
    if (function_exists('vvx_retire_reintroduced_installer')) {
        vvx_retire_reintroduced_installer(ROOT_PATH);
    }
    if (!\App\Core\OperationsGate::recovery()) addon_runtime()->boot();
}
