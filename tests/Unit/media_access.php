<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root);
require $root . '/app/bootstrap.php';

use App\Core\MediaAccess;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

foreach (MediaAccess::MODES as $mode) {
    $assert(MediaAccess::normalize($mode) === $mode, "Mode did not normalize: {$mode}");
    $assert(MediaAccess::label($mode) !== '', "Mode has no label: {$mode}");
}
$assert(MediaAccess::normalize('unexpected') === 'public', 'Unknown modes must fail safe to public metadata mode.');
$assert(MediaAccess::isPublic('public'), 'Public mode was not public.');
$assert(!MediaAccess::isPublic('ppv'), 'PPV mode was treated as public.');
$assert(array_keys(MediaAccess::options()) === MediaAccess::MODES, 'Access option order or keys changed unexpectedly.');

if ($failures !== []) {
    fwrite(STDERR, "Media access test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Media access test passed.\n";
