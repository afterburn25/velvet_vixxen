<?php
$enabled = (bool) $addon->setting('enabled', true);
if (!$enabled) return;
$headline = (string) $addon->setting('headline', 'Welcome to the Velvet Vixxen.');
$copy = (string) $addon->setting('copy', 'This banner, its admin page, and its public route are provided by the installed starter add-on.');
$button = (string) $addon->setting('button_label', 'Learn More');
?>
<section class="addon-welcome-widget">
  <div class="content-wrap">
    <span class="eyebrow pink">Installed add-on</span>
    <h2><?= e($headline) ?></h2>
    <p><?= e($copy) ?></p>
    <a class="button button--primary" href="<?= e(base_url('extension/?route=welcome')) ?>"><?= e($button) ?></a>
  </div>
</section>
<style>
.addon-welcome-widget{padding:74px 0;background:radial-gradient(circle at 88% 16%,rgba(255,45,141,.13),transparent 30%),linear-gradient(135deg,#170710,#090509);border-top:1px solid rgba(255,96,171,.18);border-bottom:1px solid rgba(255,96,171,.18);color:#fff}.addon-welcome-widget h2{max-width:760px;margin:10px 0 14px;font:500 clamp(2.4rem,5vw,4.8rem)/1.04 Georgia,serif}.addon-welcome-widget p{max-width:720px;color:#cfbac6;line-height:1.7;margin:0 0 25px}
</style>
