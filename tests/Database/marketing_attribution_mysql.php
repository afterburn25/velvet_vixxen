<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('VVX_ATTRIBUTION_SQL', true);
require dirname(__DIR__) . '/Feature/marketing_attribution.php';
