<?php
declare(strict_types=1);

// Preserve this public URL when the host does not rewrite application routes.
if (!defined('ROOT_PATH')) { define('ROOT_PATH', __DIR__); }
require ROOT_PATH . '/public/routes/media.php';
