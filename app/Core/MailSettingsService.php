<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Edits only the installed configuration's mail section. Endpoint authorization
 * and CSRF remain mandatory; this service never sends a message as a side effect.
 */
final class MailSettingsService
{
    public function __construct(private readonly Config $config, private readonly string $ownerEmail = '')
    {
    }

    /** Safe form state: no saved password or other installation secrets. */
    public function snapshot(): array
    {
        return $this->publicSnapshot($this->readCurrent());
    }

    public function assertRevision(string $expectedRevision): void
    {
        $this->checkRevision($this->readCurrent(), $expectedRevision);
    }

    /** Blank password means retain; clear_password is a separate explicit action. */
    public function save(array $input, string $expectedRevision): array
    {
        $path = $this->config->path();
        $this->assertSafePath($path);
        $lockPath = dirname($path) . '/.mail-settings.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('The email settings lock is unavailable. Check configuration permissions.');
        }
        $lock = @fopen($lockPath, 'c+b');
        if (!is_resource($lock)) throw new RuntimeException('Email settings cannot be saved. Check configuration permissions.');
        try {
            if (is_link($lockPath) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another email settings change is in progress. Please retry.');
            }
            @chmod($lockPath, 0600);
            $current = $this->readCurrent();
            $this->checkRevision($current, $expectedRevision);
            $mail = $this->validate($input, $current);
            $next = $current;
            // Unknown future mail options and all non-mail fields remain intact.
            $next['mail'] = array_replace((array) ($current['mail'] ?? []), $mail);
            if ($next !== $current) {
                try {
                    $this->config->write($next);
                } catch (Throwable) {
                    throw new RuntimeException('Email settings could not be confirmed as saved. Reload this page and review the current settings before retrying.');
                }
            }
            return $this->publicSnapshot($next);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Blank explicit From may use SMTP username, then the owner/support address. */
    public static function resolveSender(mixed $explicit, mixed $smtpUsername, mixed $fallback): string
    {
        if (EmailAddress::hasForbiddenControls($explicit)) throw new RuntimeException('Enter a valid From email address without line breaks or control characters.');
        if (!EmailAddress::isBlank($explicit)) {
            if (!EmailAddress::isValid($explicit)) throw new RuntimeException('Enter a valid From email address. Ordinary Gmail and plus addresses are accepted.');
            return EmailAddress::normalize($explicit);
        }
        if (EmailAddress::isValid($smtpUsername)) return EmailAddress::normalize($smtpUsername);
        if (EmailAddress::isValid($fallback)) return EmailAddress::normalize($fallback);
        throw new RuntimeException('Enter a valid From email address before enabling email delivery.');
    }

    /** Hostnames and bare/bracketed IP addresses; never a URL, path or port suffix. */
    public static function normalizeHost(mixed $value): string
    {
        if (!is_string($value) || EmailAddress::hasForbiddenControls($value)) throw new RuntimeException('Enter an SMTP hostname or IP address without a URL, path or port.');
        $host = strtolower(trim($value));
        if ($host === '') return '';
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $literal = substr($host, 1, -1);
            if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) return $literal;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) return $host;
        if (strlen($host) <= 253 && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?\z/D', $host) === 1) return $host;
        throw new RuntimeException('Enter an SMTP hostname or IP address without a URL, path or port.');
    }

    private function validate(array $input, array $current): array
    {
        $existing = (array) ($current['mail'] ?? []);
        $value = static fn(string $key, mixed $default = ''): mixed => $input[$key] ?? $existing[$key] ?? $default;
        foreach (['mode', 'host', 'encryption', 'username', 'from_email', 'from_name'] as $key) {
            if (!is_string($value($key)) || EmailAddress::hasForbiddenControls($value($key))) {
                throw new RuntimeException('Email settings must contain plain text without line breaks or control characters.');
            }
        }
        $mode = strtolower(trim($value('mode', 'disabled')));
        if (!in_array($mode, ['disabled', 'php', 'smtp'], true)) throw new RuntimeException('Choose Disabled, PHP mail, or SMTP for email delivery.');
        $encryption = strtolower(trim($value('encryption', 'tls')));
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) throw new RuntimeException('Choose STARTTLS, SSL/TLS, or no encryption.');
        $port = $value('port', 587);
        if ((!is_string($port) && !is_int($port)) || preg_match('/\A[0-9]{1,5}\z/D', (string) $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Enter an SMTP port between 1 and 65535.');
        }
        $host = self::normalizeHost($value('host'));
        if ($mode === 'smtp' && $host === '') throw new RuntimeException('Enter the SMTP server hostname before enabling SMTP.');
        $username = trim($value('username'));
        if (strlen($username) > 512) throw new RuntimeException('The SMTP username is too long.');
        $fromName = trim($value('from_name'));
        if ($fromName === '') $fromName = is_string($current['app_name'] ?? null) ? trim($current['app_name']) : 'Nikki Wilder';
        $nameLength = function_exists('mb_strlen') ? mb_strlen($fromName, 'UTF-8') : strlen($fromName);
        if ($fromName === '' || $nameLength > 200 || EmailAddress::hasForbiddenControls($fromName)) throw new RuntimeException('Enter a sender name of 200 characters or fewer without line breaks.');
        $fallback = EmailAddress::isValid($this->ownerEmail) ? $this->ownerEmail : ($current['support_email'] ?? '');
        $fromEmail = self::resolveSender($value('from_email'), $mode === 'smtp' ? $username : '', $fallback);
        $password = $input['password'] ?? '';
        $savedPassword = $existing['password'] ?? '';
        if (!is_string($password) || !is_string($savedPassword) || strlen($password) > 4096) throw new RuntimeException('Enter a valid SMTP password.');
        $clear = $input['clear_password'] ?? false;
        if (!in_array($clear, [true, false, 0, 1, '0', '1', ''], true)) throw new RuntimeException('Choose whether to clear the saved SMTP password.');
        $clear = $clear === true || $clear === 1 || $clear === '1';
        if ($clear && $password !== '') throw new RuntimeException('Enter a replacement SMTP password or choose Clear saved password, not both.');
        $password = $clear ? '' : ($password !== '' ? $password : $savedPassword);
        return ['mode' => $mode, 'host' => $host, 'port' => (int) $port, 'encryption' => $encryption,
            'username' => $username, 'password' => $password, 'from_email' => $fromEmail, 'from_name' => $fromName];
    }

    private function readCurrent(): array
    {
        $this->assertSafePath($this->config->path());
        try {
            $current = Config::loadFresh($this->config->path());
            $this->config->reload();
        } catch (Throwable) {
            throw new RuntimeException('The current email settings could not be read safely. Check configuration permissions and reload the page.');
        }
        if ($current !== $this->config->all()) throw new RuntimeException('PHP is still using an older configuration. Refresh application PHP before editing email settings.');
        if (($current['installed'] ?? false) !== true || ($current['installer_pending'] ?? false) === true
            || !is_string($current['installation_id'] ?? null) || trim($current['installation_id']) === '') {
            throw new RuntimeException('Finish or restore the existing installation before editing email settings.');
        }
        if (!is_string($current['security']['app_key'] ?? null) || trim($current['security']['app_key']) === '') {
            throw new RuntimeException('The application key is unavailable. Restore the existing configuration before editing email settings.');
        }
        return $current;
    }

    private function assertSafePath(string $path): void
    {
        if (!is_file($path) || is_link($path) || is_link($path . '.previous')
            || (file_exists($path . '.previous') && !is_file($path . '.previous'))) throw new RuntimeException('The existing configuration is unavailable or linked. Email settings were not changed.');
        $directory = dirname($path);
        for ($cursor = $directory; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) throw new RuntimeException('The configuration directory is linked. Email settings were not changed.');
        }
    }

    private function checkRevision(array $current, string $expectedRevision): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $expectedRevision) !== 1 || !hash_equals($this->revision($current), $expectedRevision)) {
            throw new RuntimeException('These email settings changed in another request. Reload the page and review the current settings before trying again.');
        }
    }

    private function revision(array $values): string
    {
        return hash_hmac('sha256', serialize($values), (string) $values['security']['app_key']);
    }

    private function publicSnapshot(array $values): array
    {
        $mail = (array) ($values['mail'] ?? []);
        $result = [];
        foreach (['mode' => 'disabled', 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'from_email' => '', 'from_name' => 'Nikki Wilder'] as $key => $default) {
            $candidate = $mail[$key] ?? $default;
            $result[$key] = is_string($candidate) || is_int($candidate) ? $candidate : $default;
        }
        $result['password_set'] = is_string($mail['password'] ?? null) && $mail['password'] !== '';
        $result['revision'] = $this->revision($values);
        return $result;
    }
}
