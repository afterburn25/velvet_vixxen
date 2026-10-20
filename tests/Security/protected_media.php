<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$endpoint = (string) file_get_contents($root . '/public/routes/media.php');
$uploadService = (string) file_get_contents($root . '/app/Core/MediaAdminService.php');
$uploadRules = (string) file_get_contents($root . '/uploads/.htaccess');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

$assert(str_contains($endpoint, 'hash_equals'), 'Protected endpoint lacks constant-time signature comparison.');
$assert(str_contains($endpoint, 'realpath'), 'Protected endpoint lacks canonical path validation.');
$assert(str_contains($endpoint, 'str_starts_with($stored, \'private:\')'), 'Protected endpoint does not require private assets.');
$assert(str_contains($endpoint, 'Accept-Ranges: bytes'), 'Protected video/audio endpoint lacks range support.');
$assert(str_contains($uploadService, 'upload((array) $files[\'audio\'], \'audio\', true)'), 'Audio originals are not stored privately.');
$assert(str_contains($uploadService, 'upload((array) $files[\'full_video\'], \'video\', true)'), 'Full videos are not stored privately.');
$assert(str_contains($uploadService, 'upload($upload, \'gallery\', true)'), 'Gallery originals are not stored privately.');
$assert(str_contains($uploadRules, 'Require all denied'), 'Upload executable blocking rule is missing.');

if ($failures !== []) {
    fwrite(STDERR, "Protected media test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Protected media test passed.\n";
