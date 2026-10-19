<?php
declare(strict_types=1);

// A real PHP entry point also works when the host does not rewrite API paths.
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__)); }
require ROOT_PATH . '/public/routes/api/scene.php';
