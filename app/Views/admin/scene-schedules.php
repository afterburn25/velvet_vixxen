<?php
declare(strict_types=1);

// Controller loads this read-only snapshot and rechecks ownership before output.
if (!isset($sceneScheduleSnapshot) || !is_array($sceneScheduleSnapshot)) {
    throw new RuntimeException('The saved scene schedules could not be read. Reload this page.');
}
$sceneSectionLabel = $sceneScheduleSection === 'hero' ? 'Homepage Hero' : 'Visual Footer';
$sceneScheduleUrl = base_url('admin/?page=scene-schedules&section=' . $sceneScheduleSection);
$sceneComposerUrl = base_url('admin/?page=composer&section=' . $sceneScheduleSection);
$sceneScheduleCurrent = $sceneScheduleSnapshot['schedule'];
$sceneScheduleOpen = is_array($sceneScheduleCurrent)
    && in_array($sceneScheduleCurrent['status'] ?? '', ['scheduled', 'processing', 'active'], true);
$sceneScheduleCanCancel = $sceneScheduleOpen && is_string($sceneScheduleCurrent['id'] ?? null)
    && preg_match('/\A[a-f0-9]{32}\z/D', $sceneScheduleCurrent['id']) === 1;
$sceneScheduleForm = is_array($sceneScheduleAttempted ?? null) ? $sceneScheduleAttempted : [
    'startsAt' => '', 'endsAt' => '', 'returnPolicy' => 'keep',
    'expectedRevision' => $sceneScheduleSnapshot['revision'],
    'expectedDraftRevision' => $sceneScheduleSnapshot['draftRevision'],
    'expectedPublishedRevision' => $sceneScheduleSnapshot['publishedRevision'],
];
$sceneScheduleHistory = is_array($sceneScheduleSnapshot['history'] ?? null) ? $sceneScheduleSnapshot['history'] : [];
$sceneScheduleTimezone = date_default_timezone_get();
?>
<div class="scene-schedule-page" data-scene-schedules data-scene-section="<?= e($sceneScheduleSection) ?>">
  <section class="admin-card scene-schedule-intro">
    <div><span class="admin-kicker">Site &amp; Design</span><h2>A scene for the right moment.</h2>
      <p>Plan a Hero or Visual Footer change using your saved Composer draft. Choose when it starts and what happens when it ends.</p>
      <p class="scene-schedule-zone">All times use <strong><?= e($sceneScheduleTimezone) ?></strong>.</p>
    </div>
    <a class="admin-button secondary" href="<?= e(base_url('admin/?page=marketing&tab=calendar&type=scenes')) ?>">View publishing calendar</a>
    <nav class="scene-schedule-tabs" aria-label="Choose scene">
      <?php foreach (['hero' => 'Homepage Hero', 'footer' => 'Visual Footer'] as $key => $label): ?>
        <a class="admin-button secondary" <?= $key === $sceneScheduleSection ? 'aria-current="page"' : '' ?> href="<?= e(base_url('admin/?page=scene-schedules&section=' . $key)) ?>" data-scene-section-link="<?= $key ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
  </section>

  <div class="scene-schedule-layout">
    <section class="admin-card scene-schedule-editor" aria-labelledby="scene-schedule-heading">
      <span class="admin-kicker"><?= e($sceneSectionLabel) ?></span><h2 id="scene-schedule-heading">Schedule your saved draft</h2>
      <div class="scene-schedule-draft"><span>Saved draft</span><strong data-scene-schedule-draft-title><?= e($sceneScheduleSnapshot['hasDraft'] ? $sceneScheduleSnapshot['draftTitle'] : 'No saved draft yet') ?></strong><a href="<?= e($sceneComposerUrl) ?>">Open in Composer <span aria-hidden="true">↗</span></a></div>
      <p class="scene-schedule-help">Scheduling keeps a copy of this saved draft, including all three device layouts. You can keep editing in Composer afterward.</p>
      <?php if ($sceneScheduleOpen): ?><p class="scene-schedule-note">There is already an open schedule for this scene. Cancel it before creating another.</p><?php endif; ?>
      <?php if (!$sceneScheduleSnapshot['hasDraft']): ?><p class="scene-schedule-note">Save a draft in Composer first, then return here to choose its dates.</p><?php endif; ?>
      <form method="post" action="<?= e($sceneScheduleUrl) ?>" data-scene-schedule-form<?= is_array($sceneScheduleAttempted ?? null) ? ' data-scene-schedule-attempted="true"' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="scene_schedule_create"><input type="hidden" name="section" value="<?= e($sceneScheduleSection) ?>">
        <?php foreach (['expectedRevision', 'expectedDraftRevision', 'expectedPublishedRevision'] as $field): ?><input type="hidden" name="<?= $field ?>" value="<?= e($sceneScheduleForm[$field]) ?>"><?php endforeach; ?>
        <div class="scene-schedule-dates">
          <label for="scene-starts">Starts at<input id="scene-starts" name="startsAt" type="datetime-local" step="1" value="<?= e($sceneScheduleForm['startsAt']) ?>" required aria-describedby="scene-schedule-time-help"></label>
          <label for="scene-ends">Ends at (optional)<input id="scene-ends" name="endsAt" type="datetime-local" step="1" value="<?= e($sceneScheduleForm['endsAt']) ?>" aria-describedby="scene-schedule-end-help"></label>
        </div>
        <p id="scene-schedule-time-help" class="scene-schedule-help">Choose when this scene should start in <?= e($sceneScheduleTimezone) ?>. A start time already due runs on the next check. A time skipped or repeated during a daylight-saving change must be replaced with an unambiguous time.</p>
        <div class="scene-schedule-choice"><label for="scene-return-policy">After the end time</label><select id="scene-return-policy" name="returnPolicy" aria-describedby="scene-schedule-end-help">
          <option value="keep" <?= $sceneScheduleForm['returnPolicy'] === 'keep' ? 'selected' : '' ?>>Keep this scene live</option>
          <option value="restore_previous" <?= $sceneScheduleForm['returnPolicy'] === 'restore_previous' ? 'selected' : '' ?>>Restore the previous scene</option>
        </select></div>
        <p id="scene-schedule-end-help" class="scene-schedule-help">To return to the previous scene, choose an end time. It restores the scene that was live before the scheduled change. Publishing or restoring another scene manually cancels the automatic change.</p>
        <div class="scene-schedule-form-actions"><button class="admin-button primary" type="submit"<?= $sceneScheduleOpen || !$sceneScheduleSnapshot['hasDraft'] ? ' disabled' : '' ?>>Schedule saved draft</button><a class="mini-button" href="<?= e($sceneScheduleUrl) ?>">Reload saved scene</a></div>
        <?php if (is_array($sceneScheduleAttempted ?? null)): ?><p class="scene-schedule-help" role="status">Your attempted dates are kept above. If the saved scene changed, reload it and review the new draft before scheduling.</p><?php endif; ?>
      </form>
    </section>

    <aside class="scene-schedule-side">
      <section class="admin-card scene-schedule-current" data-scene-schedule-current data-schedule-status="<?= e((string) ($sceneScheduleCurrent['status'] ?? 'none')) ?>" data-schedule-id="<?= e((string) ($sceneScheduleCurrent['id'] ?? '')) ?>">
        <span class="admin-kicker">Current schedule</span>
        <?php if (is_array($sceneScheduleCurrent)): ?>
          <div class="scene-schedule-status" data-schedule-status-label><?= e(vvx_scene_schedule_status($sceneScheduleCurrent['status'] ?? null)) ?></div>
          <h2 data-schedule-title><?= e((string) ($sceneScheduleCurrent['snapshotTitle'] ?? $sceneSectionLabel)) ?></h2>
          <dl class="scene-schedule-facts">
            <div><dt>Starts</dt><dd><?= e(vvx_scene_schedule_date($sceneScheduleCurrent['startsAt'] ?? null)) ?></dd></div>
            <div><dt>Ends</dt><dd><?= empty($sceneScheduleCurrent['endsAt']) ? 'No end time' : e(vvx_scene_schedule_date($sceneScheduleCurrent['endsAt'])) ?></dd></div>
            <div><dt>Afterward</dt><dd><?= ($sceneScheduleCurrent['returnPolicy'] ?? '') === 'restore_previous' ? 'Return to the previous live scene' : 'Keep this scene on the website' ?></dd></div>
            <?php if (!empty($sceneScheduleCurrent['activatedAt'])): ?><div><dt>Started</dt><dd><?= e(vvx_scene_schedule_date($sceneScheduleCurrent['activatedAt'])) ?></dd></div><?php endif; ?>
          </dl>
          <?php if (!empty($sceneScheduleCurrent['reason'])): ?><p class="scene-schedule-note" data-schedule-reason><?= e((string) $sceneScheduleCurrent['reason']) ?></p><?php endif; ?>
          <?php if ($sceneScheduleCanCancel): ?>
          <form method="post" action="<?= e($sceneScheduleUrl) ?>" data-scene-schedule-cancel>
            <?= csrf_field() ?><input type="hidden" name="action" value="scene_schedule_cancel"><input type="hidden" name="section" value="<?= e($sceneScheduleSection) ?>"><input type="hidden" name="id" value="<?= e((string) $sceneScheduleCurrent['id']) ?>"><input type="hidden" name="expectedRevision" value="<?= e($sceneScheduleSnapshot['revision']) ?>">
            <button type="submit" class="admin-button secondary full"><?= ($sceneScheduleCurrent['status'] ?? '') === 'active' && ($sceneScheduleCurrent['returnPolicy'] ?? '') === 'restore_previous' ? 'Cancel return to previous scene' : 'Cancel schedule' ?></button>
          </form>
          <p class="scene-schedule-help">Cancelling keeps the current live scene in place.</p>
          <?php elseif ($sceneScheduleOpen): ?><p class="scene-schedule-note">This saved schedule needs review. Use Run due scenes now to check it before scheduling another scene.</p>
          <?php endif; ?>
        <?php else: ?><h2>Ready when you are</h2><p class="scene-schedule-help">No scene is scheduled here. Your current live <?= e(strtolower($sceneSectionLabel)) ?> remains unchanged.</p><?php endif; ?>
      </section>
      <section class="admin-card scene-schedule-runner">
        <span class="admin-kicker">Run scheduled changes</span><h2>Check what is due</h2>
        <p>Run due Hero and Footer changes now, or use your hosting control panel to check automatically every minute. Opening a page does not start or end a scene.</p>
        <form method="post" action="<?= e($sceneScheduleUrl) ?>" data-scene-schedule-run>
          <?= csrf_field() ?><input type="hidden" name="action" value="scene_schedule_run"><input type="hidden" name="section" value="<?= e($sceneScheduleSection) ?>"><button class="admin-button secondary full" type="submit">Run due scenes now</button>
        </form>
        <details class="scene-schedule-hosting"><summary>Automatic checks on your host</summary><p>In your hosting control panel, add a cron job to run this command every minute. Use your host’s PHP executable and this website’s full directory.</p><code>php /path/to/website/app/Console/run_scenes.php --limit=10</code><p>Changes take effect when the command runs. If an entire start/end window has passed, that scene is skipped.</p></details>
      </section>
    </aside>
  </div>

  <section class="admin-card scene-schedule-history" aria-labelledby="scene-schedule-history-heading">
    <span class="admin-kicker">Recent activity</span><h2 id="scene-schedule-history-heading">Schedule history</h2>
    <p class="scene-schedule-help">The most recent completed, cancelled and held schedules for this scene. Published scene versions remain available in Composer.</p>
    <ol data-scene-schedule-list>
      <?php foreach ($sceneScheduleHistory as $item): ?>
        <li data-schedule-history-id="<?= e((string) ($item['id'] ?? '')) ?>"><div><strong><?= e((string) ($item['snapshotTitle'] ?? $sceneSectionLabel)) ?></strong><span><?= e(vvx_scene_schedule_status($item['status'] ?? null)) ?></span></div><time><?= e(vvx_scene_schedule_date($item['finishedAt'] ?? $item['updatedAt'] ?? $item['createdAt'] ?? null)) ?></time><?php if (!empty($item['reason'])): ?><p><?= e((string) $item['reason']) ?></p><?php endif; ?></li>
      <?php endforeach; ?>
      <?php if ($sceneScheduleHistory === []): ?><li class="scene-schedule-empty">Finished schedules will appear here.</li><?php endif; ?>
    </ol>
  </section>
</div>
