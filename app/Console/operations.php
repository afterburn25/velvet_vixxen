<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/includes/autoload.php';
require ROOT_PATH . '/includes/version.php';
require ROOT_PATH . '/app/Support/functions.php';

try { $options = App\Core\OperationsFiles::options( ['status','activate','repair','quiesced','gate-revision:','configure-key','key-file:','config-revision:',
    'recovery:','restore-review','restore-apply','archive:','target:','target-db-file:','plan:','confirm-empty-target','help']); } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
if (isset($options['help'])) {
    echo <<<'HELP'
Read-only operations status:
  php app/Console/operations.php --status

After owner setup, drain/restart all earlier PHP and cron workers, then activate:
  php app/Console/operations.php --activate --quiesced --gate-revision=REVIEWED_REVISION

Configure an independent, private 32-byte binary key stored outside the website:
  php app/Console/operations.php --configure-key --key-file=/private/backup.key --config-revision=REVIEWED_REVISION

Set recovery mode without booting extensions:
  php app/Console/operations.php --recovery=on --gate-revision=REVIEWED_REVISION
  php app/Console/operations.php --recovery=off --gate-revision=REVIEWED_REVISION

Repair a missing/damaged gate only after stopping the earlier PHP/cron runtime:
  php app/Console/operations.php --repair --quiesced
This preserves existing lock inodes and leaves first activation pending.

Review a complete authenticated restore to an empty, separate directory:
  php app/Console/operations.php --restore-review --archive=/private/snapshot.vvxbak --key-file=/private/backup.key --target=/private/isolated-restore
For MySQL add --target-db-file=/private/empty-target.json (a private JSON object
with host, port, name, user, password, charset for a different EMPTY schema).

Apply only that exact reviewed plan:
  php app/Console/operations.php --restore-apply --archive=/private/snapshot.vvxbak --key-file=/private/backup.key --target=/private/isolated-restore --plan=REVIEWED_PLAN --confirm-empty-target
Repeat --target-db-file when applicable. No target is cleared or overwritten.
The restored copy remains quarantined, including after a successful full file
and database readback. See app/Docs/BACKUP_OPERATIONS.md before activating any copy.

HELP;
    exit(0);
}
try {
    App\Core\OperationsGate::assertNotQuarantined(ROOT_PATH);
    $config = new App\Core\Config(ROOT_PATH . '/config/app.php');
    $commands = array_intersect(['status','activate','repair','configure-key','recovery','restore-review','restore-apply'], array_keys($options));
    if (count($commands) !== 1) throw new InvalidArgumentException('Choose exactly one operations command. Use --help for its reviewed prerequisites.');
    $required = static function (string $name) use ($options): string {
        if (!is_string($options[$name] ?? null) || $options[$name] === '') throw new InvalidArgumentException('The --' . $name . ' argument is required.');
        return $options[$name];
    };
    $command = reset($commands);
    if ($command === 'status') {
        $gate = null; $problem = null;
        try { $gate = App\Core\OperationsGate::status(ROOT_PATH,$config); } catch (Throwable) { $problem = 'The configured gate needs operator repair. Public requests remain held.'; }
        $service = new App\Core\BackupService($config);
        $result = ['gate'=>$gate,'gate_problem'=>$problem,'config_revision'=>$config->revision(),'diagnostics'=>$service->diagnostics()];
    } elseif ($command === 'activate') $result = App\Core\OperationsGate::activate(ROOT_PATH,$config,$required('gate-revision'),isset($options['quiesced']));
    elseif ($command === 'repair') $result = App\Core\OperationsGate::repair(ROOT_PATH,$config,isset($options['quiesced']));
    elseif ($command === 'configure-key') {
        App\Core\OperationsGate::configureKey(ROOT_PATH,$config,$required('key-file'),$required('config-revision'));
        $result = ['key_configured'=>true,'config_revision'=>$config->revision()];
    } elseif ($command === 'recovery') {
        $value = $required('recovery'); if (!in_array($value,['on','off'],true)) throw new InvalidArgumentException('Choose --recovery=on or --recovery=off.');
        $result = App\Core\OperationsGate::setRecovery(ROOT_PATH,$config,$value === 'on',$required('gate-revision'),static function (): void {});
    } else {
        $restore = new App\Core\BackupRestore($config);
        $dbFile = isset($options['target-db-file']) ? $required('target-db-file') : null;
        $result = $command === 'restore-review'
            ? $restore->review($required('archive'),$required('key-file'),$required('target'),$dbFile)
            : $restore->apply($required('archive'),$required('key-file'),$required('target'),$dbFile,$required('plan'),isset($options['confirm-empty-target']));
    }
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    $message = $error instanceof PDOException || $error->getPrevious() instanceof PDOException
        ? 'Storage could not be read. Check the private hosting logs and database availability; existing data was preserved.'
        : ($error instanceof RuntimeException || $error instanceof InvalidArgumentException ? $error->getMessage() : 'The operation could not finish. Existing source data was preserved.');
    fwrite(STDERR,'Operations: ' . $message . PHP_EOL); exit(1);
}
