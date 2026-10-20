<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }require ROOT_PATH.'/app/bootstrap.php';
if(!is_installed())redirect(base_url('install/'));$pageTitle='Reset Password';$activeNav='membership';$error='';$success='';$debugUrl='';
if($_SERVER['REQUEST_METHOD']==='POST'){try{if(!verify_csrf())throw new RuntimeException('Your form session expired.');$r=member_auth()->requestPasswordReset((string)($_POST['email']??''));$success='If that email belongs to an eligible account, a reset message has been prepared.';$debugUrl=(string)($r['debugUrl']??'');}catch(Throwable $e){$error=$e->getMessage();}}
$authLayout = true; $hidePersistentPlayer = true;
require root_path('app/Views/public/partials/header.php');
?>
<div class="member-main"><section class="member-auth-shell"><div class="member-auth-card"><span class="eyebrow pink">Account recovery</span><h1>Reset your password.</h1><p>Enter your account email. Reset links expire after one hour.</p><?php if($error):?><div class="member-alert error"><?=e($error)?></div><?php endif;?><?php if($success):?><div class="member-alert success"><?=e($success)?><?php if($debugUrl):?><br><a class="member-debug-link" href="<?=e($debugUrl)?>">Open local reset link</a><?php endif;?></div><?php endif;?><form method="post" class="member-form"><?=csrf_field()?><label>Email address<input type="email" name="email" required autofocus></label><button class="button button--primary">Send Reset Link</button></form><div class="member-links"><a href="<?=e(base_url('account/login.php'))?>">Back to sign in</a></div></div><div class="member-auth-art"><div><h2>Secure recovery.</h2><p>Reset tokens are single-use, expire automatically, and revoke previous member sessions after a successful password change.</p></div></div></section></div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
