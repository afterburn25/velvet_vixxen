<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }require ROOT_PATH.'/app/bootstrap.php';
if (!is_installed()) redirect(base_url('install/'));
$pageTitle='Confirm Email';$activeNav='membership';$token=(string)($_GET['token']??'');$member=$token!==''?member_auth()->verify($token):null;
require root_path('app/Views/public/partials/header.php');
?>
<div class="member-main"><section class="member-auth-shell"><div class="member-auth-card"><span class="eyebrow pink">Email confirmation</span><?php if($member):?><h1>You're confirmed.</h1><div class="member-alert success">Your email is verified and you are signed in.</div><a class="button button--primary" href="<?=e(base_url('account/'))?>">Open My Account</a><?php else:?><h1>This link is not valid.</h1><div class="member-alert error">The confirmation link may have expired or already been used.</div><a class="button button--primary" href="<?=e(base_url('account/login.php'))?>">Return to Sign In</a><?php endif;?></div><div class="member-auth-art"><div><h2>Welcome inside.</h2><p>Your private account is protected by verified email and device-session records.</p></div></div></section></div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
