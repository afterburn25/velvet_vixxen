<?php
declare(strict_types=1);
header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
if (app_auth()->check()) redirect(base_url(\App\Core\OperationsGate::recovery() ? 'admin/?page=operations' : 'admin/'));
$installComplete = isset($_GET['installed']) && is_installed();
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!verify_csrf()) { vvx_response_status(419); throw new RuntimeException('Your sign-in session expired. Refresh and try again.'); }
        if (($_POST['action'] ?? '') === 'restart_login') { unset($_SESSION['admin_pending']); redirect(base_url('admin/?page=login')); }
        if (app_auth()->pending()) {
            if (app_auth()->completeChallenge(is_string($_POST['code'] ?? null) ? $_POST['code'] : '')) redirect(base_url(\App\Core\OperationsGate::recovery() ? 'admin/?page=operations' : 'admin/'));
            $error = 'The authentication code was not accepted. Use a current code or an unused recovery code. Repeated attempts require a 15-minute wait.';
        } else {
            $ok = app_auth()->attempt(is_string($_POST['email'] ?? null) ? $_POST['email'] : '', is_string($_POST['password'] ?? null) ? $_POST['password'] : '');
            if ($ok) redirect(base_url(\App\Core\OperationsGate::recovery() ? 'admin/?page=operations' : 'admin/'));
            if (!app_auth()->pending()) $error = 'The email address or password was not accepted. Repeated attempts require a 15-minute wait.';
        }
    } catch (Throwable $caught) {
        if (http_response_code() < 400) http_response_code(403);
        $error = $caught instanceof RuntimeException && !$caught instanceof PDOException ? $caught->getMessage() : 'Sign-in is temporarily unavailable. Check storage and try again.';
    } finally { unset($_POST['password'], $_POST['code']); }
}
$pendingFactor = app_auth()->pending();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Sign In</title><link rel="stylesheet" href="<?= e(asset_url('css/admin.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/admin-ux.css')) ?>"><link rel="icon" href="<?= e(base_url('images/defaults/brand-mark.png')) ?>" type="image/png"></head><body class="login-body"><main class="login-shell"><div class="login-brand"><span>Nikki Wilder</span><small>The Velvet Vixxen</small></div><form method="post" class="login-card"><?= csrf_field() ?><div class="admin-kicker">Private administration</div><h1><?= $pendingFactor ? 'Verify your sign-in.' : 'Welcome back.' ?></h1><p><?= $pendingFactor ? 'Enter the six-digit code from your authenticator or one unused recovery code.' : 'Sign in to manage the live design, Canva templates, and media.' ?></p><?php if ($installComplete): ?><div class="admin-alert success"><strong>Installation complete.</strong> The database, owner account, organized folders, security secrets, and installation lock were verified. The installer cleanup was completed or safely locked by the server.</div><?php endif; ?><?php if ($error): ?><div class="admin-alert error" role="alert"><?= e($error) ?></div><?php endif; ?><?php if ($pendingFactor): ?><label>Authenticator or recovery code<input name="code" autocomplete="one-time-code" maxlength="64" required autofocus></label><button class="admin-button primary">Verify code</button><button class="admin-button" name="action" value="restart_login" formnovalidate>Use email and password</button><?php else: ?><label>Email address<input type="email" name="email" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="admin-button primary">Sign in</button><?php endif; ?><a class="back-link" href="<?= e(base_url()) ?>">← Return to the website</a></form></main><script src="<?= e(asset_url('js/admin-ux.js')) ?>" defer></script></body></html>
