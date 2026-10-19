<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$forbiddenModes = [];
$secretFindings = [];
$patterns = [
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    '/[\'\"](?:api[_-]?key|secret[_-]?key|private[_-]?key|webhook[_-]?secret)[\'\"]\s*(?:=>|:)\s*[\'\"][A-Za-z0-9_\-]{20,}[\'\"]/i',
    '/^(?:DB_PASSWORD|SMTP_PASSWORD|CANVA_CLIENT_SECRET|APP_KEY|SIGNING_KEY|MEDIA_KEY)\s*=\s*[^#\s]{8,}$/mi',
];
$skipNames = ['repository_safety.php'];
$binaryExtensions = ['png','jpg','jpeg','gif','webp','avif','ico','pdf','pptx','zip','mp3','wav','flac','m4a','mp4','webm','mov'];

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    if (str_contains($relative, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $mode = fileperms($path) & 0777;
    if ($mode === 0777) {
        $forbiddenModes[] = $relative;
    }
    if (in_array(strtolower($file->getExtension()), $binaryExtensions, true) || in_array($file->getBasename(), $skipNames, true)) {
        continue;
    }
    $content = file_get_contents($path);
    if (!is_string($content)) {
        continue;
    }
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content) === 1) {
            $secretFindings[] = $relative;
            break;
        }
    }
}

if ($forbiddenModes !== []) {
    fwrite(STDERR, 'Files with forbidden 0777 mode: ' . implode(', ', $forbiddenModes) . PHP_EOL);
    exit(1);
}
if ($secretFindings !== []) {
    fwrite(STDERR, 'Potential committed secrets found in: ' . implode(', ', array_unique($secretFindings)) . PHP_EOL);
    exit(1);
}

$runtimeFiles = [
    'storage/data.json',
    'storage/install.lock',
];
foreach ($runtimeFiles as $relative) {
    if (is_file($root . '/' . $relative)) {
        fwrite(STDERR, "Runtime installation state must not be packaged or committed: {$relative}\n");
        exit(1);
    }
}

foreach (['storage/logs', 'storage/sessions', 'storage/temp', 'storage/temporary', 'storage/cache', 'storage/updates', 'storage/backups'] as $relativeDir) {
    $dir = $root . '/' . $relativeDir;
    if (!is_dir($dir)) continue;
    $runtimeIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($runtimeIterator as $entry) {
        if (!$entry->isFile() || in_array($entry->getFilename(), ['.gitkeep', '.htaccess'], true)) continue;
        $relative = ltrim(str_replace($root, '', $entry->getPathname()), DIRECTORY_SEPARATOR);
        fwrite(STDERR, "Runtime file must not be packaged or committed: {$relative}\n");
        exit(1);
    }
}


foreach (['addons/installed', 'addons/staging'] as $relativeDir) {
    $dir = $root . '/' . $relativeDir;
    if (!is_dir($dir)) continue;
    foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
        if (in_array($entry->getFilename(), ['.gitkeep', '.htaccess'], true)) continue;
        fwrite(STDERR, "Runtime add-on file must not be packaged or committed: {$relativeDir}/{$entry->getFilename()}\n");
        exit(1);
    }
}

$example = require $root . '/config/app.example.php';
if (($example['db']['password'] ?? '') !== '' || ($example['mail']['password'] ?? '') !== '' || ($example['canva']['client_secret'] ?? '') !== '') {
    fwrite(STDERR, "Example configuration contains a non-empty credential.\n");
    exit(1);
}

$runtimeConfigPath = $root . '/config/app.php';
if (is_file($runtimeConfigPath)) {
    $runtimeConfig = require $runtimeConfigPath;
    if (($runtimeConfig['installed'] ?? false) !== false || ($runtimeConfig['installation_id'] ?? '') !== '') {
        fwrite(STDERR, "config/app.php contains installed runtime state.\n");
        exit(1);
    }
    foreach ([
        $runtimeConfig['db']['password'] ?? '',
        $runtimeConfig['mail']['password'] ?? '',
        $runtimeConfig['canva']['client_secret'] ?? '',
        $runtimeConfig['security']['app_key'] ?? '',
        $runtimeConfig['security']['signing_key'] ?? '',
        $runtimeConfig['security']['media_key'] ?? '',
    ] as $value) {
        if ((string) $value !== '') {
            fwrite(STDERR, "config/app.php contains a generated secret or credential.\n");
            exit(1);
        }
    }
}

echo "Repository security scan passed.\n";
