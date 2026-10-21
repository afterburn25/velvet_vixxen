<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Confirm owner access · Velvet Vixxen Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#130b16;color:#faeef8;font:16px/1.6 system-ui,sans-serif}.repair-auth{width:min(100%,560px);margin:6vh auto;padding:clamp(20px,5vw,36px)}.repair-auth h1{font-size:clamp(1.7rem,6vw,2.2rem);line-height:1.2;color:#ff62bd}.repair-auth p{color:#d8bed2}.repair-auth form{background:#211226;border:1px solid #60334f;border-radius:18px;padding:24px;margin-top:24px}.repair-auth label{display:block;margin:16px 0 6px;font-weight:650}.repair-auth input,.repair-auth button{font:inherit;width:100%;min-height:48px;border-radius:9px;padding:10px 12px}.repair-auth input{background:#140b18;border:1px solid #91677f;color:#fff}.repair-auth button{border:0;margin-top:24px;background:#ff4aae;color:#240419;font-weight:750;cursor:pointer}.repair-auth :focus-visible{outline:3px solid #fff;outline-offset:3px}.repair-auth .error{padding:14px;border:1px solid #ff62bd;border-radius:10px;color:#fff;overflow-wrap:anywhere}.repair-auth small{display:block;color:#d8bed2;margin-top:8px}.repair-auth .kicker{color:#dda2c7;font-size:.85rem;letter-spacing:.05em}
</style></head><body><main class="repair-auth" data-repair-auth="<?= e($access['mode']) ?>">
<div class="kicker">Nikki Wilder · The Velvet Vixxen</div>
<h1><?= $access['mode'] === 'factor' ? 'Confirm your authenticator' : ($access['mode'] === 'reauth' ? 'Confirm owner access' : 'Sign in to verify the update') ?></h1>
<p>The update uses the current owner security settings. Confirm your password and, when enabled, your authenticator before changing application PHP or folders.</p>
<?php if ($access['error'] !== ''): ?><div class="error" role="alert"><?= e($access['error']) ?></div><?php endif; ?>
<form method="post" action="repair.php"><?= csrf_field() ?><input type="hidden" name="action" value="repair_<?= e($access['mode']) ?>">
<?php if ($access['mode'] === 'signin'): ?><label for="repair-email">Email address</label><input id="repair-email" name="email" type="email" autocomplete="username" maxlength="254" required><?php endif; ?>
<?php if ($access['mode'] !== 'factor'): ?><label for="repair-password">Password</label><input id="repair-password" name="password" type="password" autocomplete="current-password" required><?php endif; ?>
<?php if ($access['mode'] === 'factor' || $access['factor']): ?><label for="repair-code">Authenticator or recovery code</label><input id="repair-code" name="code" type="text" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" maxlength="64" required><small>Enter the current code from your authenticator, or one unused recovery code.</small><?php endif; ?>
<button type="submit"><?= $access['mode'] === 'signin' ? 'Sign in' : 'Confirm owner access' ?></button>
</form></main></body></html>
