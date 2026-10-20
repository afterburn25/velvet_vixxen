<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2);
$worker = ($argv[1] ?? '') === '--writer';
$root = $worker ? (string) ($argv[2] ?? '') : sys_get_temp_dir() . '/vvx-mail-settings-' . bin2hex(random_bytes(8));
if ($worker && (!str_starts_with($root, sys_get_temp_dir() . '/vvx-mail-settings-') || !is_file($root . '/.vvx-test-runtime'))) throw new RuntimeException('A marked disposable mail fixture is required.');
if (!$worker) { mkdir($root, 0700, true); file_put_contents($root . '/.vvx-test-runtime', 'Disposable mail settings fixture.'); }
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require_once $source . '/app/Support/functions.php';
if ($worker) {
    $deadline = microtime(true) + 5;
    while (!is_file($root . '/start-writers') && microtime(true) < $deadline) usleep(1000);
    if (!is_file($root . '/start-writers')) throw new RuntimeException('Concurrent fixture did not start.');
    try {
        $service = new App\Core\MailSettingsService(new App\Core\Config($root . '/config/app.php'), 'owner@example.test');
        $service->save(['from_name' => 'Concurrent writer ' . (string) $argv[4]], (string) $argv[3]);
        echo "saved\n";
    } catch (RuntimeException) { echo "rejected\n"; }
    exit;
}
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $operation, string $message) use ($assert): string {
    try { $operation(); }
    catch (RuntimeException $error) { $assert(true, $message); return $error->getMessage(); }
    $assert(false, $message);
    return '';
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    @rmdir($path);
};
try {
    $config = new App\Core\Config($root . '/config/app.php');
    $savedPassword = 'synthetic-' . bin2hex(random_bytes(10));
    $appKey = bin2hex(random_bytes(32));
    $initial = ['installed' => true, 'installation_id' => 'fixture-' . bin2hex(random_bytes(16)),
        'app_name' => 'Disposable Site', 'base_url' => 'http://127.0.0.1/nested/site',
        'storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'support_email' => 'support@example.test',
        'db' => ['name' => 'disposable_fixture', 'password' => bin2hex(random_bytes(12))],
        'security' => ['app_key' => $appKey, 'signing_key' => bin2hex(random_bytes(24)), 'media_key' => bin2hex(random_bytes(24))],
        'future' => ['preserved' => ['nested' => true]],
        'mail' => ['mode' => 'disabled', 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '',
            'password' => $savedPassword, 'from_email' => 'sender@example.test', 'from_name' => 'Fixture', 'future_option' => ['preserved' => true]]];
    $config->write($initial);
    $service = new App\Core\MailSettingsService($config, 'Owner@Example.Test');
    $snapshot = $service->snapshot();
    $assert(array_keys($snapshot) === ['mode', 'host', 'port', 'encryption', 'username', 'from_email', 'from_name', 'password_set', 'revision'], 'The form snapshot exposed unexpected fields.');
    $assert($snapshot['password_set'] === true && !str_contains(json_encode($snapshot), $savedPassword) && !str_contains(json_encode($snapshot), $appKey), 'The safe snapshot exposed a saved secret.');
    $assert($snapshot['revision'] === hash_hmac('sha256', serialize($initial), $appKey), 'The revision is not keyed by the installation secret.');
    $service->assertRevision($snapshot['revision']);
    $reject(fn() => $service->assertRevision(''), 'An empty revision was accepted.');
    $reject(fn() => $service->assertRevision(str_repeat('0', 64)), 'A forged revision was accepted.');

    foreach ([' Owner+News@GMAIL.COM ' => 'owner+news@gmail.com', 'Fixture <Owner+News@Gmail.com>' => 'owner+news@gmail.com',
        'mailto:owner@example.test' => 'owner@example.test', "owner\u{200B}＠example。test" => 'owner@example.test',
        'owner&nbsp;@example.test' => 'owner@example.test'] as $input => $expected) {
        $assert(App\Core\EmailAddress::normalize($input) === $expected && App\Core\EmailAddress::isValid($input), 'Ordinary clipboard/Gmail/plus normalization failed.');
    }
    foreach (["\r", "\n", "\r\n", "\0", "\t", "\x7f", "\u{0085}", "\u{2028}", "\u{2029}", '&#13;', '&#xA;', '&NewLine;', "\xff"] as $control) {
        $input = 'owner@example.test' . $control;
        $assert(App\Core\EmailAddress::hasForbiddenControls($input), 'A raw or encoded control was not recognized.');
        $assert(!App\Core\EmailAddress::isValid($input) && App\Core\EmailAddress::normalize($input) === '', 'Normalization erased forbidden controls and accepted a mailbox.');
        $reject(fn() => App\Core\MailSettingsService::resolveSender($input, 'fallback@example.test', 'owner@example.test'), 'An explicit injected sender silently fell back.');
    }
    foreach ([[], new stdClass(), true] as $invalidType) {
        $assert(!App\Core\EmailAddress::isValid($invalidType), 'A non-text mailbox input was accepted.');
    }
    $assert(App\Core\MailSettingsService::resolveSender('', 'Smtp+Sender@Example.Test', 'owner@example.test') === 'smtp+sender@example.test', 'SMTP-username fallback did not take precedence.');
    foreach (['   ', "\u{00a0}\u{200b}", '&nbsp;'] as $blank) {
        $assert(App\Core\EmailAddress::isBlank($blank) && App\Core\MailSettingsService::resolveSender($blank, 'smtp@example.test', 'owner@example.test') === 'smtp@example.test', 'Blank clipboard spacing failed to use sender fallback.');
    }
    foreach (['mailto:', '<>', "\r"] as $invalid) {
        $assert(!App\Core\EmailAddress::isBlank($invalid), 'Explicit invalid sender content was treated as blank.');
        $reject(fn() => App\Core\MailSettingsService::resolveSender($invalid, 'smtp@example.test', 'owner@example.test'), 'Explicit invalid sender content triggered fallback.');
    }
    $assert(App\Core\MailSettingsService::resolveSender('', 'not-a-mailbox', 'Owner@Example.Test') === 'owner@example.test', 'A non-mailbox SMTP username prevented owner fallback.');
    $assert(App\Core\MailSettingsService::resolveSender('Explicit@Example.Test', 'smtp@example.test', 'owner@example.test') === 'explicit@example.test', 'An explicit From was replaced by fallback.');
    $reject(fn() => App\Core\MailSettingsService::resolveSender('wrong-address', 'smtp@example.test', 'owner@example.test'), 'An invalid explicit From silently fell back.');

    $smtp = $service->save(['mode' => 'smtp', 'host' => 'LOCALHOST', 'port' => '1025', 'encryption' => 'none',
        'username' => 'Smtp+Sender@Example.Test', 'from_email' => '', 'from_name' => 'Owner, Newsletter', 'password' => '',
        'db' => ['password' => 'do-not-apply'], 'future_option' => 'do-not-apply'], $snapshot['revision']);
    $current = App\Core\Config::loadFresh($config->path());
    $assert($smtp['host'] === 'localhost' && $smtp['port'] === 1025 && $smtp['from_email'] === 'smtp+sender@example.test', 'Mail settings were not normalized and saved.');
    $assert($current['mail']['password'] === $savedPassword, 'A blank password cleared the saved credential.');
    $assert($current['mail']['future_option'] === $initial['mail']['future_option'], 'A future mail field was dropped or overwritten from input.');
    $outsideMail = $current; unset($outsideMail['mail']); $originalOutsideMail = $initial; unset($originalOutsideMail['mail']);
    $assert($outsideMail === $originalOutsideMail, 'Saving mail changed database, keys, installation identity or unrelated settings.');
    $assert($config->all() === $current, 'The running Config object did not receive the saved settings.');
    $assert(!is_file($root . '/storage/data.json'), 'Saving settings wrote an outbox or other member records.');
    $before = file_get_contents($config->path());
    $reject(fn() => $service->save(['from_name' => 'Stale overwrite'], $snapshot['revision']), 'A stale form overwrote newer mail settings.');
    $assert(file_get_contents($config->path()) === $before, 'A rejected stale form changed configuration bytes.');
    $secretReplacement = '  replacement-' . bin2hex(random_bytes(12)) . '  ';
    $changed = $service->save(['password' => $secretReplacement], $smtp['revision']);
    $assert(App\Core\Config::loadFresh($config->path())['mail']['password'] === $secretReplacement, 'A replacement credential lost leading or trailing spaces.');
    $assert(!str_contains(json_encode($changed), $secretReplacement) && !array_key_exists('password', $changed), 'A replacement credential was returned to the UI.');
    $cleared = $service->save(['clear_password' => '1'], $changed['revision']);
    $assert($cleared['password_set'] === false && App\Core\Config::loadFresh($config->path())['mail']['password'] === '', 'Explicit password clearing did not persist.');
    $disabled = $service->save(['mode' => 'disabled', 'username' => 'not-a-mailbox', 'from_email' => '', 'password' => $savedPassword], $cleared['revision']);
    $assert($disabled['from_email'] === 'owner@example.test' && $disabled['host'] === 'localhost' && $disabled['password_set'], 'Disabled mode lost saved transport settings or owner fallback.');
    $noOwner = new App\Core\MailSettingsService($config);
    $support = $noOwner->save(['from_email' => ''], $disabled['revision']);
    $assert($support['from_email'] === 'support@example.test', 'Support fallback without a constructor owner failed.');

    $invalidInputs = [
        ['mode' => 'other'], ['mode' => []], ['host' => 'https://smtp.example.test'], ['host' => 'smtp.example.test:25'],
        ['host' => 'smtp.example.test/path'], ['host' => 'smtp.example.test?secret=anything'], ['host' => "smtp.example.test\n"],
        ['host' => 'bad_host'], ['host' => '-smtp.example.test'], ['host' => str_repeat('a', 64) . '.example.test'],
        ['port' => 0], ['port' => 65536], ['port' => '587.5'], ['port' => '587junk'], ['port' => true],
        ['encryption' => 'starttls-downgrade'], ['username' => "user\r\nAUTH LOGIN"], ['username' => []],
        ['from_email' => 'wrong-address'], ['from_email' => "sender@example.test\r"], ['from_email' => 'sender@example.test&#13;'],
        ['from_name' => "Owner\nBcc: other@example.test"], ['from_name' => 'Owner&NewLine;Bcc: other@example.test'],
        ['password' => []], ['password' => str_repeat('a', 4097)], ['clear_password' => []], ['clear_password' => true, 'password' => $savedPassword],
        ['mode' => 'smtp', 'host' => ''],
    ];
    foreach ($invalidInputs as $input) {
        $before = file_get_contents($config->path());
        $message = $reject(fn() => $service->save($input, $support['revision']), 'Invalid mail configuration was saved.');
        $assert(file_get_contents($config->path()) === $before, 'Invalid settings changed the existing configuration.');
        $assert(!str_contains($message, $root) && !str_contains($message, $savedPassword), 'An error exposed a path or saved secret.');
    }
    foreach (['smtp.example.test', 'localhost', '127.0.0.1', '::1', '[::1]'] as $host) {
        $assert(App\Core\MailSettingsService::normalizeHost($host) !== '', 'A valid SMTP host or IP was rejected.');
    }
    $current = App\Core\Config::loadFresh($config->path());
    $current['future']['concurrent'] = 'Preserve this newer non-mail setting';
    $config->write($current);
    $reject(fn() => $service->save(['from_name' => 'Unsafe stale merge'], $support['revision']), 'A configuration change outside mail was overwritten by a stale form.');
    $assert(App\Core\Config::loadFresh($config->path())['future']['concurrent'] === $current['future']['concurrent'], 'A newer unrelated configuration setting was lost.');

    $mail = new App\Core\MailService($config, new App\Core\Store($config));
    $assert($mail->deliver('owner@example.test', 'Fixed synthetic subject', 'No external delivery')['status'] === 'skipped', 'Disabled mode attempted to transmit.');
    foreach (["owner@example.test\r", "owner@example.test\n", 'owner@example.test&#13;'] as $recipient) {
        $result = $mail->deliver($recipient, 'Fixed synthetic subject', 'No delivery');
        $assert($result['status'] === 'failed' && !$result['retryable'], 'Recipient header controls reached transport or became retryable.');
        $reject(fn() => $mail->send(null, $recipient, 'Synthetic subject', 'fixture', 'No delivery'), 'Invalid recipient reached transactional outbox.');
    }
    foreach (["Subject\nBcc: other@example.test", 'Subject&NewLine;Bcc: other@example.test'] as $subject) {
        $assert($mail->deliver('owner@example.test', $subject, 'No delivery')['status'] === 'failed', 'Subject injection reached transport.');
        $reject(fn() => $mail->send(null, 'owner@example.test', $subject, 'fixture', 'No delivery'), 'Subject injection reached transactional outbox.');
    }
    $assert(!is_file($root . '/storage/data.json'), 'Rejected headers mutated transactional storage.');
    $current = App\Core\Config::loadFresh($config->path());
    $current['mail'] = array_replace($current['mail'], ['mode' => 'smtp', 'host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'none', 'from_email' => '', 'username' => 'smtp@example.test']);
    $config->write($current);
    $assert($mail->configurationProblem() === null, 'Valid saved SMTP sender fallback was not ready.');
    foreach ([['from_email' => "sender@example.test\r"], ['from_name' => "Owner\nInjected"], ['username' => "user\r"], ['port' => '1025junk'], ['host' => 'localhost:1025']] as $input) {
        $config->write(array_replace($current, ['mail' => array_replace($current['mail'], $input)]));
        $assert($mail->configurationProblem() !== null, 'Malformed legacy mail configuration was treated as ready.');
    }
    $config->write($current);

    $race = $service->snapshot();
    $processes = [];
    foreach (['A', 'B'] as $label) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--writer', $root, $race['revision'], $label],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start concurrent mail writers.');
        fclose($pipes[0]); $processes[] = [$process, $pipes];
    }
    file_put_contents($root . '/start-writers', 'Start disposable writers');
    $outcomes = [];
    foreach ($processes as [$process, $pipes]) {
        $outcomes[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $assert(proc_close($process) === 0 && $error === '', 'Concurrent writer did not complete cleanly.');
    }
    sort($outcomes);
    $assert($outcomes === ['rejected', 'saved'], 'Two same-revision concurrent forms were both accepted or both lost.');
    $raced = App\Core\Config::loadFresh($config->path());
    $assert(in_array($raced['mail']['from_name'], ['Concurrent writer A', 'Concurrent writer B'], true), 'The winner of concurrent settings edits was lost.');
    $assert($raced['security'] === $initial['security'] && $raced['db'] === $initial['db'] && $raced['future'] === $current['future'], 'Concurrent settings edits changed private or unrelated state.');
    $assert((fileperms($config->path()) & 0007) === 0 && (fileperms($root . '/config/.mail-settings.lock') & 0077) === 0, 'Saved configuration or lock permissions became public.');
    $before = file_get_contents($config->path());
    $config->write(array_replace($raced, ['installer_pending' => true]));
    $reject(fn() => $service->snapshot(), 'A pending installation exposed mutable mail settings.');
    $config->write($raced);
    $backup = file_get_contents($config->path() . '.previous');
    unlink($config->path() . '.previous');
    file_put_contents($root . '/preserved-external-fixture', 'Must remain unchanged');
    symlink($root . '/preserved-external-fixture', $config->path() . '.previous');
    $reject(fn() => $service->save(['from_name' => 'Linked backup'], $service->snapshot()['revision']), 'A linked configuration backup was overwritten.');
    $assert(file_get_contents($root . '/preserved-external-fixture') === 'Must remain unchanged', 'A linked external file was changed.');
    unlink($config->path() . '.previous'); file_put_contents($config->path() . '.previous', $backup);
    rename($config->path(), $root . '/preserved-config.php'); symlink($root . '/preserved-config.php', $config->path());
    $reject(fn() => $service->snapshot(), 'A linked live configuration was followed.');
    unlink($config->path()); rename($root . '/preserved-config.php', $config->path());
    file_put_contents($config->path(), '<?php return "malformed fixture";');
    $message = $reject(fn() => $service->snapshot(), 'Malformed configuration was silently replaced.');
    $assert(file_get_contents($config->path()) === '<?php return "malformed fixture";' && !str_contains($message, $root), 'Malformed configuration was changed or its path exposed.');
    file_put_contents($config->path(), $before);
    echo "Mail settings regressions passed ($checks assertions; concurrent writers and header controls included).\n";
} finally {
    $remove($root);
}
