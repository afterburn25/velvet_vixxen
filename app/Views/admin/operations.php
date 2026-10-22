<?php
declare(strict_types=1);

// This dedicated controller never initializes extensions, repairs, or backups on GET.
require_once root_path('app/Support/operations_admin.php');
header('Cache-Control: no-store, private, max-age=0'); header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer');
try { if (!is_installed()) throw new RuntimeException('Installation unavailable.',403); $user = vvx_operations_owner(); }
catch (Throwable) { http_response_code(403); exit('Only the active site owner can manage Operations.'); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','POST'],true)) { header('Allow: GET, POST'); http_response_code(405); exit('Method not allowed.'); }
if (($_GET['page'] ?? null) !== 'operations') { http_response_code(400); exit('Open Operations before changing a backup or recovery setting.'); }
if (($_GET['guide'] ?? null) === '1' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    vvx_operations_owner(); header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="BACKUP_OPERATIONS.md"');
    readfile(root_path('app/Docs/BACKUP_OPERATIONS.md')); exit;
}
$config = app_config(); $operations = new App\Core\BackupService($config); $error = ''; $success = flash('operations_success');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        vvx_operations_owner(true);
        if (!verify_csrf()) throw new RuntimeException('Your session token expired. Reload Operations and try again.',419);
        $authorize = static function (): void { vvx_operations_owner(true); };
        $action = vvx_operations_field('action');
        if ($action === 'operations_setup') {
            App\Core\OperationsGate::setup(ROOT_PATH,$config,vvx_operations_field('config_revision'),$authorize);
            flash('operations_success','Operations setup is saved. Public recovery mode stays on until your operator drains earlier workers and completes CLI activation.');
        } elseif ($action === 'operations_policy') {
            $operations->savePolicy(['enabled'=>($_POST['enabled'] ?? '') === '1','utc_time'=>vvx_operations_field('utc_time'),
                'keep_count'=>vvx_operations_number('keep_count'),'keep_days'=>vvx_operations_number('keep_days')],vvx_operations_field('revision'),$user,$authorize);
            flash('operations_success','Backup schedule and retention saved. The hosting worker will check what is due.');
        } elseif ($action === 'operations_queue') {
            $operations->queue(vvx_operations_field('revision'),$user,$authorize);
            flash('operations_success','Backup queued. The hosting worker will drain requests, capture the installation, and verify the encrypted archive.');
        } elseif ($action === 'operations_recovery') {
            $value = vvx_operations_field('recovery');
            if (!in_array($value,['on','off'],true)) throw new InvalidArgumentException('Choose whether recovery mode is on or off.');
            App\Core\OperationsGate::setRecovery(ROOT_PATH,$config,$value === 'on',vvx_operations_field('gate_revision'),$authorize);
            flash('operations_success',$value === 'on' ? 'Recovery mode is on. Public requests and ordinary workers are paused; owner sign-in, Security and Operations remain available.' : 'Recovery mode is off. Ordinary requests can resume.');
        } elseif ($action === 'operations_download') {
            $archive = $operations->archive(vvx_operations_field('id'),$authorize);
            header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.$archive['filename'].'"');
            header('Content-Length: '.(string)$archive['bytes']); header('X-Archive-SHA256: '.$archive['sha256']);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            try { fpassthru($archive['handle']); } finally { fclose($archive['handle']); }
            exit;
        } else throw new InvalidArgumentException('Choose an Operations action from this page.');
        redirect(base_url('admin/?page=operations'));
    } catch (Throwable $caught) {
        if ($caught->getCode() === 403) { http_response_code(403); exit('Only the active site owner can manage Operations.'); }
        $code = (int)$caught->getCode(); vvx_response_status(in_array($code,[409,419,428,503],true) ? $code : ($caught instanceof InvalidArgumentException ? 422 : 409));
        $error = $caught instanceof PDOException || $caught->getPrevious() instanceof PDOException
            ? 'Storage is unavailable. Your saved settings and archives were preserved. Check database availability before retrying.'
            : ($caught instanceof RuntimeException || $caught instanceof InvalidArgumentException ? $caught->getMessage() : 'The action could not finish. Existing data was preserved.');
    }
}
$gate = null; $gateProblem = null; $snapshot = null; $journalProblem = null;
try { $gate = App\Core\OperationsGate::status(ROOT_PATH,$config); } catch (Throwable) { $gateProblem = 'The configured request gate needs an operator repair. Public requests remain held until the matching files are repaired.'; }
try { $snapshot = $operations->snapshot(); } catch (Throwable) { $journalProblem = 'The backup journal could not be read. Check storage and pending database updates before changing backups. Existing records were preserved.'; }
$diagnostics = $operations->diagnostics(); $user = vvx_operations_owner();
$ready = !empty($gate['ready']); $policy = $snapshot['policy'] ?? App\Core\BackupJobs::initial()['policy'];
$open = $snapshot === null ? null : App\Core\BackupJobs::open($snapshot);
$jobs = array_reverse($snapshot['jobs'] ?? [], true);
$page = 'operations'; $nav = ['dashboard'=>['⌂','Dashboard'],'operations'=>['◈','Operations'],'security'=>['◇','Security'],
    'database-updates'=>['↑','Database Updates'],'repair-backups'=>['↺','Repair Backups']];
$url = base_url('admin/?page=operations');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Operations · Velvet Vixxen Admin</title>
<link rel="stylesheet" href="<?= e(asset_url('css/admin.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/admin-ux.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/operations.css')) ?>"></head>
<body class="admin-body"><a class="admin-skip-link" href="#adminContent">Skip to content</a><?php require root_path('app/Views/admin/navigation.php'); ?><div class="admin-shell">
<header class="admin-topbar"><button class="sidebar-toggle" type="button" aria-label="Toggle menu" aria-controls="adminSidebar" aria-expanded="false">☰</button><div><div class="admin-kicker">System</div><h1>Operations</h1></div><a class="admin-button secondary" href="<?= e(base_url('admin/?page=security')) ?>">Security</a></header>
<main class="admin-content operations-page" id="adminContent" tabindex="-1" data-operations>
<?php if ($success): ?><div class="admin-alert success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert error" role="alert" data-operations-error><?= e($error) ?></div><?php endif; ?>
<section class="admin-card operations-intro"><span class="admin-kicker">Backups &amp; recovery</span><h2>Keep a verified way back.</h2><p>Capture this installation, its settings, private records, database and uploads in one encrypted archive. A verified restore drill checks a separate empty destination before you consider a live recovery.</p><p><a class="admin-button secondary" href="<?= e($url . '&guide=1') ?>">Download deployment guide</a></p><p>Protected changes require a recent owner sign-in. <a href="<?= e(base_url('admin/?page=security')) ?>">Confirm your password in Security</a> if this session is older than 15 minutes.</p></section>
<div class="operations-grid">
<section class="admin-card" aria-labelledby="operations-gate"><span class="admin-kicker">Request protection</span><h2 id="operations-gate">Recovery mode</h2>
<?php if ($gateProblem): ?><p class="admin-alert error"><?= e($gateProblem) ?></p><p>Your operator can use the reviewed CLI repair command after draining PHP and cron processes.</p>
<?php elseif (empty($gate['configured'])): ?><p>Set up the request gate before scheduling backups. Setup turns on recovery mode while your operator restarts or drains earlier PHP and cron workers. This one-time step makes later snapshots consistent.</p>
<form method="post" action="<?= e($url) ?>" data-operations-setup><?= csrf_field() ?><input type="hidden" name="action" value="operations_setup"><input type="hidden" name="config_revision" value="<?= e($config->revision()) ?>"><button class="admin-button primary" type="submit">Set up Operations</button></form>
<?php elseif (!$ready): ?><p class="operations-badge" data-gate-status="pending">Waiting for operator activation</p><p>Public recovery mode is on. Drain or restart all PHP and cron workers that predate setup, then run the activation command shown below with the reviewed gate revision.</p>
<?php else: ?><p class="operations-badge" data-gate-status="<?= $gate['recovery'] ? 'recovery' : 'ready' ?>"><?= $gate['recovery'] ? 'Recovery mode is on' : 'Requests are running normally' ?></p><p>Recovery mode pauses public PHP requests and ordinary workers. Owner sign-in, Security and Operations stay available with extensions disabled.</p>
<form method="post" action="<?= e($url) ?>" data-operations-recovery><?= csrf_field() ?><input type="hidden" name="action" value="operations_recovery"><input type="hidden" name="gate_revision" value="<?= e($gate['revision']) ?>"><input type="hidden" name="recovery" value="<?= $gate['recovery'] ? 'off' : 'on' ?>"><button class="admin-button secondary" type="submit"><?= $gate['recovery'] ? 'Resume normal requests' : 'Turn on recovery mode' ?></button></form>
<?php endif; ?>
<?php if (!empty($gate['configured'])): ?><details><summary>Reviewed gate revision</summary><code data-gate-revision><?= e($gate['revision']) ?></code></details><?php endif; ?>
</section>
<section class="admin-card" aria-labelledby="operations-backup"><span class="admin-kicker">Complete snapshot</span><h2 id="operations-backup">Request a backup</h2><p>The CLI worker briefly drains active requests, writes the encrypted archive, and reads it back in full. Opening this page does not run a backup.</p>
<?php if ($journalProblem): ?><p class="admin-alert error"><?= e($journalProblem) ?></p><?php elseif ($open !== null): ?><p class="operations-badge" data-open-backup="<?= e($open['status']) ?>"><?= e(vvx_operations_status($open['status'])) ?></p><p>Requested <?= e(vvx_operations_date($open['created_at'])) ?>. Let the hosting worker finish or recover this attempt before requesting another.</p><?php else: ?>
<form method="post" action="<?= e($url) ?>" data-operations-queue><?= csrf_field() ?><input type="hidden" name="action" value="operations_queue"><input type="hidden" name="revision" value="<?= e($snapshot['revision']) ?>"><button class="admin-button primary" type="submit"<?= !$ready ? ' disabled' : '' ?>>Queue encrypted backup</button></form><?php endif; ?>
<p class="operations-muted">Keep an off-site copy and store the encryption key separately. Losing the key makes the archive unreadable.</p></section>
<section class="admin-card" aria-labelledby="operations-schedule"><span class="admin-kicker">Automatic copies</span><h2 id="operations-schedule">Schedule &amp; retention</h2><p>Daily checks use UTC. The worker creates at most one scheduled attempt per UTC day and does not replay a backlog after downtime.</p>
<?php if ($snapshot !== null): ?><form method="post" action="<?= e($url) ?>" data-operations-policy><?= csrf_field() ?><input type="hidden" name="action" value="operations_policy"><input type="hidden" name="revision" value="<?= e($snapshot['revision']) ?>">
<label class="operations-check"><input type="checkbox" name="enabled" value="1"<?= $policy['enabled'] ? ' checked' : '' ?>> Schedule daily backups</label>
<label>Daily time (UTC)<input type="time" name="utc_time" value="<?= e($policy['utc_time']) ?>" required></label>
<div class="operations-fields"><label>Keep newest copies<input type="number" name="keep_count" min="1" max="100" value="<?= $policy['keep_count'] ?>" required></label><label>Keep copies for days<input type="number" name="keep_days" min="1" max="3650" value="<?= $policy['keep_days'] ?>" required></label></div>
<p class="operations-muted">Retention keeps both the newest <?= $policy['keep_count'] ?> successful copies and every copy from the last <?= $policy['keep_days'] ?> days. It removes an older archive only after checking the newest retained copy and that archive.</p><button class="admin-button secondary" type="submit"<?= !$ready ? ' disabled' : '' ?>>Save schedule &amp; retention</button></form><?php endif; ?></section>
<section class="admin-card" aria-labelledby="operations-checks"><span class="admin-kicker">Read-only checks</span><h2 id="operations-checks">Diagnostics</h2><ul class="operations-checks">
<?php foreach ($diagnostics as $check): ?><li data-diagnostic="<?= e($check['key']) ?>" data-status="<?= e($check['status']) ?>"><strong><?= e($check['label']) ?><span><?= $check['status'] === 'ready' ? 'Ready' : ($check['status'] === 'operator_check' ? 'Host check' : 'Needs review') ?></span></strong><p><?= e($check['detail']) ?></p></li><?php endforeach; ?>
</ul></section></div>
<section class="admin-card" aria-labelledby="operations-history"><span class="admin-kicker">Durable activity</span><h2 id="operations-history">Backup history</h2><p>Each attempt remains recorded, including incomplete snapshots and copies removed by retention. Only complete verified archives can be downloaded.</p>
<?php if ($jobs === []): ?><p class="operations-muted">No backup attempts yet.</p><?php endif; ?><div class="operations-history">
<?php foreach ($jobs as $job): ?><article data-backup-id="<?= e($job['id']) ?>" data-backup-status="<?= e($job['status']) ?>"><div><h3><?= e(vvx_operations_status($job['status'])) ?></h3><p><?= e(vvx_operations_date($job['created_at'])) ?> · <?= $job['source'] === 'scheduled' ? 'Scheduled' : 'Owner request' ?></p>
<?php if ($job['reason'] !== null): ?><p><?= e(App\Core\BackupJobs::reason($job['reason'])) ?></p><?php endif; ?>
<?php if ($job['manifest'] !== null): ?><p><?= number_format($job['manifest']['entries']) ?> entries · <?= number_format($job['archive_bytes']/1048576,2) ?> MiB encrypted · <?= number_format($job['manifest']['database']['rows'] ?? 0) ?> database rows</p><details><summary>Archive checksum</summary><code><?= e($job['archive_sha256']) ?></code><p>Last verified <?= e(vvx_operations_date($job['verified_at'])) ?></p></details><?php endif; ?></div>
<?php if ($job['status'] === 'complete'): ?><form method="post" action="<?= e($url) ?>" data-operations-download><?= csrf_field() ?><input type="hidden" name="action" value="operations_download"><input type="hidden" name="id" value="<?= e($job['id']) ?>"><button class="admin-button secondary" type="submit">Download encrypted archive</button></form><?php endif; ?></article><?php endforeach; ?></div></section>
<section class="admin-card operations-hosting"><span class="admin-kicker">Hosting setup</span><h2>Connect the worker, then prove a restore.</h2><p>Use your host’s PHP executable and the full website directory. Keep the key file outside this directory with owner-only read/write permissions. See <strong>app/Docs/BACKUP_OPERATIONS.md</strong> in your deployment for key creation, prerequisites and the exact restore procedure.</p>
<details><summary>Commands for your operator</summary><p>First inspect status without changing files:</p><code>php /path/to/website/app/Console/operations.php --status</code>
<p>After draining earlier workers, activate the reviewed gate:</p><code>php /path/to/website/app/Console/operations.php --activate --quiesced --gate-revision=REVIEWED_REVISION</code>
<p>Configure the independent private key using the current configuration revision:</p><code>php /path/to/website/app/Console/operations.php --configure-key --key-file=/private/backup.key --config-revision=REVIEWED_REVISION</code>
<p>Add this command to cron every five minutes:</p><code>php /path/to/website/app/Console/run_backups.php</code>
<p>Review and apply a restore only into an empty separate directory and, for MySQL, an empty separate database. The restored copy stays quarantined until its connection, URL, authority records and provider credentials have been reviewed.</p><code>php /path/to/website/app/Console/operations.php --help</code></details></section>
</main></div><script src="<?= e(asset_url('js/admin.js')) ?>" defer></script><script src="<?= e(asset_url('js/admin-ux.js')) ?>" defer></script></body></html>
