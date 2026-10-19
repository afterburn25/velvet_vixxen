<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) { redirect(base_url('install/')); }
$returnTo=member_return_path((string)($_GET['return']??$_POST['return']??'/account/'));
if (member_auth()->check()) { redirect($returnTo); }
$pageTitle='Member Sign In';$activeNav='membership';$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf()) $error='Your sign-in session expired. Refresh and try again.';
    else {
        $result=member_auth()->attempt((string)($_POST['email']??''),(string)($_POST['password']??''));
        if (!empty($result['ok'])) redirect($returnTo);
        $error=(string)($result['message']??'Sign-in failed.');
    }
}
require root_path('app/Views/public/partials/header.php');
?>
<div class="member-main"><section class="member-auth-shell"><div class="member-auth-card"><span class="eyebrow pink">Private member access</span><h1>Welcome back.</h1><p>Sign in to open My Library, continue your media, and manage your account.</p><?php if($error):?><div class="member-alert error"><?=e($error)?></div><?php endif;?><form method="post" class="member-form"><?=csrf_field()?><input type="hidden" name="return" value="<?=e($returnTo)?>"><label>Email address<input type="email" name="email" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="button button--primary">Sign In</button></form><div class="member-links"><a href="<?=e(base_url('account/forgot.php'))?>">Forgot password?</a><a href="<?=e(base_url('account/register.php'))?>">Create account</a></div></div><div class="member-auth-art"><div><h2>More than content.</h2><p>Your member account keeps access, favorites, history, subscriptions, rentals, and individually purchased releases together.</p></div></div></section></div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
