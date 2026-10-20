<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }require ROOT_PATH.'/app/bootstrap.php';member_auth()->logout();redirect(base_url('account/login.php'));
