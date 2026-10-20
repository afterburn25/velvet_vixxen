<?php
/** $sceneButtonSection selects the Hero or Footer group in $publicScenes. */
$sceneButtons = \App\Core\SceneButton::contentButtons((array) ($publicScenes[$sceneButtonSection] ?? []), $sceneButtonSection);
?>
<div class="scene-content-buttons<?= $sceneButtonSection === 'hero' ? ' hero-buttons' : '' ?>" data-content-buttons-section="<?= e($sceneButtonSection) ?>">
<?php foreach ($sceneButtons as $sceneButton):
    $action = $sceneButton['action'];
    $hasTrailer = $action === 'trailer' && $trailerSrc !== '';
    $automaticLabel = match ($action) {
        'account' => $sceneButtonSection === 'footer'
            ? ($member ? 'Open My Library' : 'Join My World')
            : ($member ? 'My Account' : 'Join Now'),
        'trailer' => $hasTrailer ? 'Watch Trailer' : 'Browse Videos',
        default => '',
    };
    $label = $sceneButton['label'] !== '' ? $sceneButton['label'] : $automaticLabel;
    $url = match ($action) {
        'account' => base_url($member ? 'account/' : 'account/register.php'),
        'trailer' => base_url('videos/'),
        default => vvx_public_destination(base_url(), $sceneButton['url']),
    };
    $class = 'button button--' . $sceneButton['style'];
    $identity = ' data-content-button-id="' . e($sceneButton['id']) . '"';
    if ($hasTrailer): ?>
  <button class="<?= e($class) ?>" type="button" data-open-trailer<?= $identity ?>>
<?php else: ?>
  <a class="<?= e($class) ?>" href="<?= e($url) ?>"<?= $identity ?><?= $sceneButton['newTab'] ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
<?php endif; ?>
    <?php if ($action === 'trailer'): ?><span class="play" aria-hidden="true">▶</span><?php endif; ?>
    <span class="scene-content-button-label"><?= e($label) ?></span>
    <?php if ($action === 'account' && $sceneButtonSection === 'hero'): ?><span aria-hidden="true">→</span><?php endif; ?>
  <?= $hasTrailer ? '</button>' : '</a>' ?>
<?php endforeach; ?>
</div>
