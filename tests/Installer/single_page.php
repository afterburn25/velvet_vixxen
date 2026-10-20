<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root);
require $root . '/app/bootstrap.php';
require_once $root . '/install/services/InstallerProgress.php';
require_once $root . '/install/services/SmtpTester.php';

use Velvet\Installer\InstallerProgress;
use Velvet\Installer\SmtpTester;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$temp = sys_get_temp_dir() . '/vvx-single-page-test-' . bin2hex(random_bytes(5));
mkdir($temp . '/storage/temp', 0775, true);
$progress = new InstallerProgress($temp, 'abc123');
$progress->reset();
$progress->update('database', 'Testing database', 24, 'running', 'Checking privileges.');
$state = $progress->read();
$assert(($state['stage'] ?? '') === 'database', 'Installer progress stage was not persisted.');
$assert(($state['percent'] ?? 0) === 24, 'Installer progress percent was not persisted.');
$assert(($state['status'] ?? '') === 'running', 'Installer progress status was not persisted.');
$progress->remove();

$mail = (new SmtpTester())->test(['mode' => 'disabled']);
$assert(($mail['mode'] ?? '') === 'disabled', 'Disabled email mode was not accepted.');

$view = (string) file_get_contents($root . '/install/views/single-page.php');
$assert(substr_count($view, 'id="installerForm"') === 1, 'The simplified installer must expose one installation form.');
foreach (['readiness', 'database', 'owner-site', 'install-review'] as $section) {
    $assert(str_contains($view, 'id="' . $section . '"'), 'Missing installer section: ' . $section);
}
$assert(str_contains($view, 'data-installation-console'), 'Live installation progress console is missing.');
$assert(str_contains($view, 'data-test-database'), 'Inline database test control is missing.');
$assert(str_contains($view, 'name="mail_mode" value="disabled"'), 'Simplified installation must allow mail to be configured later.');

$script = (string) file_get_contents($root . '/public/assets/js/installer.js');
foreach (['recheck', 'test_database', 'test_mail', 'progress', 'install_now'] as $action) {
    $assert(str_contains($script, "'{$action}'"), 'Installer JavaScript is missing action: ' . $action);
}
$assert(str_contains($script, "form.addEventListener('submit', installWebsite)"), 'Single-page AJAX submit handler is missing.');
$assert(str_contains($view, 'data-installation-success'), 'Same-page success panel is missing.');
$assert(!is_dir($root . '/install/steps'), 'Legacy /install/steps directory still exists.');

$delete = static function (string $path) use (&$delete): void {
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $delete($path . '/' . $item);
    }
    @rmdir($path);
};
$delete($temp);

if ($failures !== []) {
    fwrite(STDERR, "Single-page installer test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Single-page installer test passed.\n";
