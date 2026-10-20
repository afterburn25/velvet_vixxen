<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root);
require $root . '/app/bootstrap.php';
foreach (['InstallerLog', 'FileSystemService', 'EmailAddress', 'SmtpTester', 'InstallerService'] as $name) {
    require_once $root . '/install/services/' . $name . '.php';
}

use Velvet\Installer\EmailAddress;
use Velvet\Installer\InstallerLog;
use Velvet\Installer\InstallerService;
use Velvet\Installer\SmtpTester;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) throw new RuntimeException($message);
};
$refuse = static function (callable $action, string $part, string $message) use ($assert): void {
    try {
        $action();
    } catch (Throwable $error) {
        $assert(str_contains($error->getMessage(), $part), $message . ': unexpected diagnostic.');
        return;
    }
    $assert(false, $message . ': input was accepted.');
};

$temporary = sys_get_temp_dir() . '/vvx-installer-mail-' . bin2hex(random_bytes(6));
mkdir($temporary . '/storage/logs', 0755, true);
$service = new InstallerService($temporary, new InstallerLog($temporary, 'mail-validation'));
$ownerMethod = new ReflectionMethod($service, 'validateOwner');
$mailMethod = new ReflectionMethod($service, 'validateMail');
$siteMethod = new ReflectionMethod($service, 'validateSite');
$mail = ['mode' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'encryption' => 'none',
    'username' => 'sender+updates@example.test', 'password' => 'local-fixture-passphrase',
    'from_email' => '', 'from_name' => 'Website updates'];
$validate = static fn(array $values): array => $mailMethod->invoke($service, $values, 'owner@example.test', 'Example website');

try {
    $accepted = [
        'owner@example.test' => 'owner@example.test',
        ' OWNER@EXAMPLE.TEST ' => 'owner@example.test',
        'name+newsletter@gmail.com' => 'name+newsletter@gmail.com',
        'Name <name+tag@gmail.com>' => 'name+tag@gmail.com',
        'mailto:Name+tag@gmail.com' => 'name+tag@gmail.com',
        'Name &lt;name@example.test&gt;' => 'name@example.test',
        "\u{00A0}name\u{200B}@example.test\u{00A0}" => 'name@example.test',
        'name＠example。test' => 'name@example.test',
    ];
    foreach ($accepted as $input => $expected) {
        $assert(EmailAddress::isValid($input), 'A normal or safely pasted mailbox was rejected.');
        $assert(EmailAddress::normalize($input) === $expected, 'Pasted mailbox normalized incorrectly.');
        $assert($validate(array_replace($mail, ['from_email' => $input]))['from_email'] === $expected, 'Explicit valid From was not preserved.');
    }
    // Installer repair stays self-contained, so exercise both validators on
    // the same externally defined mailbox/control contract to prevent drift.
    foreach (['Name <name+tag@gmail.com>', "\u{00A0}name\u{200B}@example.test", "\rowner@example.test",
              'owner@example.test&#13;', 'owner@example.test&#x0a;', "owner@example.test\u{2028}", "owner\xFF@example.test"] as $input) {
        $assert(EmailAddress::normalize($input) === App\Core\EmailAddress::normalize($input), 'Installer and running-site mail normalization drifted.');
        $assert(EmailAddress::isValid($input) === App\Core\EmailAddress::isValid($input), 'Installer and running-site mail validation drifted.');
    }

    $bad = ["\rowner@example.test", "owner@example.test\n", "owner@\r\nexample.test",
        "Nikki\r\nBcc: other@example.test <owner@example.test>",
        'owner@example.test&#13;', '&#10;owner@example.test', 'owner&#x0d;@example.test',
        "owner@example.test\u{0085}", "owner@example.test\u{2028}", "owner@example.test\u{2029}",
        "owner\xFF@example.test"];
    for ($byte = 0; $byte < 32; $byte++) $bad[] = 'owner' . chr($byte) . '@example.test';
    $bad[] = "owner\x7f@example.test";
    foreach ($bad as $input) {
        $assert(!EmailAddress::isValid($input), 'A mailbox containing a control or malformed UTF-8 was accepted.');
        $assert(EmailAddress::normalize($input) === '', 'Normalization erased a forbidden character into a mailbox.');
        $refuse(static fn() => $ownerMethod->invoke($service, ['name' => 'Owner', 'email' => $input, 'password' => 'TwelveChars!']), 'owner email', 'Invalid owner email');
        $refuse(static fn() => $validate(array_replace($mail, ['from_email' => $input])), 'From email', 'Invalid explicit From must not fall back to username');
    }

    foreach (['not-an-address', 'mailto:', 'Name <>', 'bad@@example.test', ['owner@example.test']] as $input) {
        $refuse(static fn() => $validate(array_replace($mail, ['from_email' => $input])), 'From email', 'Explicit invalid From must not fall back');
    }
    foreach (['', ' ', "\u{00A0}\u{200B}"] as $blank) {
        $assert($validate(array_replace($mail, ['from_email' => $blank]))['from_email'] === 'sender+updates@example.test', 'Blank SMTP From did not prefer the username mailbox.');
    }
    $assert($validate(array_replace($mail, ['username' => 'login-name']))['from_email'] === 'owner@example.test', 'A non-email SMTP login did not fall back to the owner.');
    $assert($validate(array_replace($mail, ['username' => '', 'password' => '']))['from_email'] === 'owner@example.test', 'An unauthenticated relay did not use the owner mailbox.');
    $assert($validate(array_replace($mail, ['mode' => 'php']))['from_email'] === 'owner@example.test', 'PHP mail incorrectly used the SMTP username.');

    foreach (['host' => 'SMTP hostname', 'username' => 'SMTP username', 'from_name' => 'From name'] as $field => $label) {
        foreach (["\r\n", '&#13;', "\u{2028}"] as $control) {
            $values = array_replace($mail, [$field => $control . $mail[$field]]);
            $refuse(static fn() => $validate($values), $label, 'Installer accepted a control in ' . $field);
            $refuse(static fn() => (new SmtpTester())->test($values, 'owner@example.test'), $label, 'SMTP tester failed to validate ' . $field . ' before connecting');
        }
    }
    foreach (['smtp://example.test', 'example.test:587', 'example.test/path', 'example test', 'example.test@127.0.0.1'] as $host) {
        $values = array_replace($mail, ['host' => $host]);
        $refuse(static fn() => $validate($values), 'valid SMTP hostname', 'Installer accepted a URL or ambiguous hostname');
        $refuse(static fn() => (new SmtpTester())->test($values, 'owner@example.test'), 'valid SMTP hostname', 'SMTP tester attempted an ambiguous hostname');
    }
    foreach (['127.0.0.1', 'localhost', 'smtp.example.test', '::1', '[::1]'] as $host) {
        $assert($validate(array_replace($mail, ['host' => $host]))['host'] === $host, 'Valid SMTP hostname or address was refused.');
    }
    foreach (['from_email' => 'From email', 'recipient' => 'recipient email'] as $field => $message) {
        foreach (["\rowner@example.test", 'owner@example.test&#10;', 'not-an-address'] as $input) {
            $values = $field === 'recipient' ? $mail : array_replace($mail, [$field => $input]);
            $recipient = $field === 'recipient' ? $input : 'owner@example.test';
            $refuse(static fn() => (new SmtpTester())->test($values, $recipient), $message, 'SMTP test silently accepted an invalid ' . $field);
        }
    }
    $site = ['base_url' => 'https://example.test', 'site_name' => 'Example', 'timezone' => 'America/Chicago', 'currency' => 'USD', 'support_email' => "\nowner@example.test"];
    $refuse(static fn() => $siteMethod->invoke($service, $site), 'support email', 'Invalid support email');

    $disabled = array_replace($mail, ['mode' => 'disabled', 'host' => ["\r\n"], 'username' => "\n", 'from_email' => "\r\n", 'from_name' => "\r\n", 'password' => ['stale-autofill']]);
    $clean = $validate($disabled);
    $assert($clean['mode'] === 'disabled' && $clean['from_email'] === 'owner@example.test' && $clean['from_name'] === 'Example website', 'Optional disabled email blocked installation or saved malformed headers.');
    $assert($clean['password'] === '' && $clean['host'] === '' && $clean['username'] === '', 'Disabled installer mail retained stale sensitive fields.');
    $assert((new SmtpTester())->test($disabled)['mode'] === 'disabled', 'Disabled SMTP test did not remain optional.');
    $assert(!is_file($temporary . '/config/app.php') && !is_file($temporary . '/storage/install.lock'), 'Validation changed installation state.');
    echo 'Installer mail validation passed (' . $count . " assertions).\n";
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
        rmdir($path);
    };
    $remove($temporary);
}
