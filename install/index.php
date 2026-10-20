<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require ROOT_PATH . '/install/bootstrap.php';

use App\Core\Database;
use Velvet\Installer\EmailAddress;
use Velvet\Installer\FileSystemService;
use Velvet\Installer\InstallerLog;
use Velvet\Installer\InstallerProgress;
use Velvet\Installer\InstallerService;
use Velvet\Installer\RequirementChecker;
use Velvet\Installer\SmtpTester;

if (!isset($_SESSION['installer']) || !is_array($_SESSION['installer'])) {
    $_SESSION['installer'] = [];
}
if (empty($_SESSION['installer']['install_id'])) {
    $_SESSION['installer']['install_id'] = bin2hex(random_bytes(12));
}

$installId = (string) $_SESSION['installer']['install_id'];
$log = new InstallerLog(ROOT_PATH, $installId);
$filesystem = new FileSystemService(ROOT_PATH, $log);
$checker = new RequirementChecker(ROOT_PATH);
$progress = new InstallerProgress(ROOT_PATH, $installId);
$smtpTester = new SmtpTester();
$baseGuess = rtrim(base_url(), '/');
$error = '';
$notice = '';
$form = is_array($_SESSION['installer']['form'] ?? null) ? $_SESSION['installer']['form'] : [];

$isAjax = static function (): bool {
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
};

$databasePayload = static function (array $source): array {
    return [
        'host' => trim((string) ($source['db_host'] ?? 'localhost')),
        'port' => (int) ($source['db_port'] ?? 3306),
        'name' => trim((string) ($source['db_name'] ?? '')),
        'user' => trim((string) ($source['db_user'] ?? '')),
        'password' => (string) ($source['db_password'] ?? ''),
        'charset' => 'utf8mb4',
    ];
};

$mailPayload = static function (array $source): array {
    return [
        'mode' => strtolower(trim((string) ($source['mail_mode'] ?? 'disabled'))),
        'host' => trim((string) ($source['smtp_host'] ?? '')),
        'port' => (int) ($source['smtp_port'] ?? 587),
        'encryption' => strtolower(trim((string) ($source['smtp_encryption'] ?? 'tls'))),
        'username' => trim((string) ($source['smtp_username'] ?? '')),
        'password' => (string) ($source['smtp_password'] ?? ''),
        'from_email' => EmailAddress::normalize($source['mail_from_email'] ?? ''),
        'from_name' => trim((string) ($source['mail_from_name'] ?? 'Nikki Wilder')),
    ];
};

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'progress' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $requestedId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['id'] ?? ''));
    if (!is_string($requestedId) || $requestedId === '') {
        json_response(['ok' => false, 'message' => 'Invalid progress identifier.'], 400);
    }
    if (!hash_equals($installId, $requestedId)) {
        json_response(['ok' => false, 'message' => 'This progress stream does not belong to the current installer session.'], 403);
    }
    try {
        $requestedProgress = new InstallerProgress(ROOT_PATH, $requestedId);
        json_response(['ok' => true, 'progress' => $requestedProgress->read()]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

$installed = is_installed();
$lockData = $installed ? installed_lock_data() : [];

if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cleanup_installer') {
    try {
        if (!verify_csrf()) {
            throw new RuntimeException('The cleanup session expired. Refresh the page and try again.');
        }
        $service = new InstallerService(ROOT_PATH, $log);
        $cleanup = $service->cleanup((string) ($lockData['installation_id'] ?? $installId));
        $payload = [
            'ok' => $cleanup['public_removed'],
            'message' => $cleanup['message'],
            'redirect' => base_url('admin/?page=login&installed=1'),
            'cleanup' => $cleanup,
        ];
        if ($isAjax()) {
            json_response($payload, $cleanup['public_removed'] ? 200 : 409);
        }
        if ($cleanup['public_removed']) {
            redirect($payload['redirect']);
        }
        $error = $cleanup['message'];
    } catch (Throwable $e) {
        if ($isAjax()) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $error = $e->getMessage();
    }
}

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf()) {
            throw new RuntimeException('The installer session expired. Refresh the page and try again.');
        }

        if ($action === 'recheck') {
            $systemChecks = $checker->systemChecks();
            $fileResults = $filesystem->prepareAndProbe();
            $requirementsReady = $checker->requiredPassed($systemChecks) && $filesystem->allRequiredWritable($fileResults);
            $_SESSION['installer']['requirements_ready'] = $requirementsReady;
            $log->write('requirements.rechecked', ['ready' => $requirementsReady]);
            json_response([
                'ok' => true,
                'ready' => $requirementsReady,
                'system_checks' => $systemChecks,
                'file_results' => $fileResults,
                'message' => $requirementsReady
                    ? 'All required server and file checks passed.'
                    : 'One or more required checks still need attention.',
            ]);
        }

        if ($action === 'test_database') {
            $db = $databasePayload($_POST);
            if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
                throw new RuntimeException('Database host, database name, and database username are required.');
            }
            $result = Database::test($db, true);
            $_SESSION['installer']['database_verified'] = [
                'fingerprint' => hash('sha256', implode('|', [$db['host'], $db['port'], $db['name'], $db['user']])),
                'result' => $result,
                'verified_at' => gmdate('c'),
            ];
            $log->write('database.test_passed', ['db' => $db, 'result' => $result]);
            json_response([
                'ok' => true,
                'message' => 'Database connection and create/write/drop privileges were verified.',
                'database' => $result,
            ]);
        }

        if ($action === 'test_mail') {
            $mail = $mailPayload($_POST);
            $recipient = EmailAddress::normalize($_POST['admin_email'] ?? '');
            if ($mail['from_email'] === '') {
                $mail['from_email'] = $recipient;
            }
            if ($mail['from_name'] === '') {
                $mail['from_name'] = trim((string) ($_POST['admin_name'] ?? 'Nikki Wilder'));
            }
            $result = $smtpTester->test($mail, $recipient);
            $log->write('mail.test_passed', ['mail' => $mail, 'result' => $result]);
            json_response([
                'ok' => true,
                'message' => $result['message'],
                'mail' => $result,
            ]);
        }

        if ($action === 'install_now') {
            $form = $_POST;
            unset($form['_csrf'], $form['action'], $form['db_password'], $form['admin_password'], $form['admin_password_confirmation'], $form['smtp_password']);
            $_SESSION['installer']['form'] = $form;

            $password = (string) ($_POST['admin_password'] ?? '');
            $passwordConfirmation = (string) ($_POST['admin_password_confirmation'] ?? '');
            if ($password !== $passwordConfirmation) {
                throw new RuntimeException('The owner passwords do not match.');
            }
            if (!isset($_POST['confirm_install'])) {
                throw new RuntimeException('Confirm that the database is ready and that you want to install the website.');
            }

            $systemChecks = $checker->systemChecks();
            $fileResults = $filesystem->prepareAndProbe();
            if (!$checker->requiredPassed($systemChecks) || !$filesystem->allRequiredWritable($fileResults)) {
                throw new RuntimeException('Required server or file checks are not passing. Open Server & File Readiness and correct the failed items.');
            }

            $ownerEmail = EmailAddress::normalize($_POST['admin_email'] ?? '');
            $supportEmail = EmailAddress::normalize($_POST['support_email'] ?? '');
            if ($supportEmail === '') {
                $supportEmail = $ownerEmail;
            }

            $payload = [
                'install_id' => $installId,
                'db' => $databasePayload($_POST),
                'owner' => [
                    'name' => trim((string) ($_POST['admin_name'] ?? '')),
                    'email' => $ownerEmail,
                    'password' => $password,
                ],
                'site' => [
                    'site_name' => trim((string) ($_POST['site_name'] ?? 'Nikki Wilder — The Velvet Vixxen')),
                    'base_url' => rtrim(trim((string) ($_POST['base_url'] ?? $baseGuess)), '/'),
                    'support_email' => $supportEmail,
                    'currency' => strtoupper(trim((string) ($_POST['currency'] ?? 'USD'))),
                    'timezone' => trim((string) ($_POST['timezone'] ?? 'America/Chicago')),
                    'age_gate_enabled' => isset($_POST['age_gate_enabled']),
                ],
                'mail' => $mailPayload($_POST),
            ];

            $progress->reset();
            $progress->update('starting', 'Starting installation', 2, 'running', 'The page can remain open while the installer works.');
            session_write_close();

            $service = new InstallerService(
                ROOT_PATH,
                $log,
                static function (string $stage, string $label, int $percent, string $status, string $detail) use ($progress): void {
                    $progress->update($stage, $label, $percent, $status, $detail);
                },
            );
            $result = $service->install($payload);
            $progress->update('cleanup', 'Removing the public installer', 97, 'running', 'Retiring /install after final verification.');
            $cleanup = $service->cleanup($result['install_id']);
            $redirect = rtrim($result['base_url'], '/') . '/admin/?page=login&installed=1';

            if ($cleanup['public_removed']) {
                $progress->update('complete', 'Installation complete', 100, 'success', 'The database, owner account, configuration, lock, and installer cleanup were verified.');
            } else {
                $progress->update('cleanup-warning', 'Website installed; cleanup needs attention', 100, 'warning', $cleanup['message']);
            }

            json_response([
                'ok' => true,
                'message' => $cleanup['public_removed']
                    ? 'Velvet Vixxen installed successfully and /install was removed.'
                    : 'Velvet Vixxen installed successfully, but the server would not delete /install. The folder was disabled for safety.',
                'redirect' => $redirect,
                'cleanup' => $cleanup,
                'migrations' => $result['migrations'],
                'admin_email' => $result['admin_email'],
            ]);
        }

        throw new RuntimeException('The requested installer action is not recognized.');
    } catch (Throwable $e) {
        $log->write('installer.request_failed', ['action' => $action, 'error' => $e->getMessage()]);
        if ($action === 'install_now') {
            try {
                $progress->update('failed', 'Installation stopped', 100, 'failed', $e->getMessage());
            } catch (Throwable) {
                // The original error is the useful response.
            }
        }
        if ($isAjax()) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $error = $e->getMessage();
        $form = array_merge($form, $_POST);
    }
}

$systemChecks = $installed ? [] : $checker->systemChecks();
$fileResults = $installed ? [] : $filesystem->prepareAndProbe();
$requirementsReady = $installed || ($checker->requiredPassed($systemChecks) && $filesystem->allRequiredWritable($fileResults));
$_SESSION['installer']['requirements_ready'] = $requirementsReady;

if (!$installed && !is_file($progress->path())) {
    $progress->reset();
}

require ROOT_PATH . '/install/views/single-page.php';
