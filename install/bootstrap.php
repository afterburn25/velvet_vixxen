<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/app/bootstrap.php';
require_once ROOT_PATH . '/install/services/InstallerLog.php';
require_once ROOT_PATH . '/install/services/InstallerProgress.php';
require_once ROOT_PATH . '/install/services/FileSystemService.php';
require_once ROOT_PATH . '/install/services/RequirementChecker.php';
require_once ROOT_PATH . '/install/services/EmailAddress.php';
require_once ROOT_PATH . '/install/services/SmtpTester.php';
require_once ROOT_PATH . '/install/services/InstallerService.php';
