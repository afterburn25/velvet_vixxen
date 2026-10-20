<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) { redirect(base_url('install/')); }
if (member_auth()->check()) { redirect(base_url('account/')); }
$pageTitle='Create Account';$activeNav='membership';$error='';$success='';$debugUrl='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your form session expired. Refresh and try again.');
        if (empty($_POST['terms'])) throw new RuntimeException('Confirm that you are 18 or older and accept the site terms.');
        $password=(string)($_POST['password']??'');
        if ($password!==(string)($_POST['password_confirmation']??'')) throw new RuntimeException('The password confirmation does not match.');
        $result=member_auth()->register((string)($_POST['display_name']??''),(string)($_POST['email']??''),$password);
        $success='Your account was created. Check your email to confirm it before signing in.';
        $debugUrl=(string)($result['verificationUrl']??'');
    } catch (Throwable $e) { $error=$e->getMessage(); }
}
$authLayout = true; $hidePersistentPlayer = true;
require root_path('app/Views/public/partials/header.php');
?>
<div class="member-main"><section class="member-auth-shell"><div class="member-auth-card"><span class="eyebrow pink">Become a member</span><h1>Create your account.</h1><p class="member-note">A free account lets you save favorites, keep playback progress, and purchase or receive access to premium releases later.</p><?php if($error):?><div class="member-alert error"><?=e($error)?></div><?php endif;?><?php if($success):?><div class="member-alert success"><?=e($success)?><?php if($debugUrl):?><br><a class="member-debug-link" href="<?=e($debugUrl)?>">Open local verification link</a><?php endif;?></div><div class="member-links"><a href="<?=e(base_url('account/login.php'))?>">Continue to sign in</a></div><?php else:?><form method="post" class="member-form"><?=csrf_field()?><label>Display name<input name="display_name" maxlength="120" value="<?=e((string)($_POST['display_name']??''))?>" autocomplete="name" required></label><label>Email address<input type="email" name="email" maxlength="190" value="<?=e((string)($_POST['email']??''))?>" autocomplete="email" required></label><div class="member-form-row"><label>Password<input type="password" name="password" minlength="12" maxlength="255" autocomplete="new-password" required><small>At least 12 characters. Long passphrases are welcome.</small></label><label>Confirm password<input type="password" name="password_confirmation" minlength="12" maxlength="255" autocomplete="new-password" required></label></div><label class="member-form-check"><input type="checkbox" name="terms" value="1" required><span>I confirm I am 18 or older where I live and accept the Terms and Privacy Policy.</span></label><button class="button button--primary">Create Account</button></form><div class="member-links"><span>Already a member?</span><a href="<?=e(base_url('account/login.php'))?>">Sign in</a></div><?php endif;?></div><div class="member-auth-art"><div><h2>Join Nikki's world.</h2><p>Keep your favorites, continue watching across devices, and collect subscription or individually unlocked releases in one private library.</p></div></div></section></div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
