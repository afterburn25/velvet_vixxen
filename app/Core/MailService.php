<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class MailService
{
    public function __construct(private readonly Config $config, private readonly Store $store)
    {
    }

    /** @param array<string,mixed> $metadata */
    public function send(?string $memberId, string $recipient, string $subject, string $template, string $body, array $metadata = []): array
    {
        if (!EmailAddress::isValid($recipient)) throw new RuntimeException('A valid recipient email address without line breaks is required.');
        if (EmailAddress::hasForbiddenControls($subject)) throw new RuntimeException('The email subject must not contain line breaks or control characters.');
        $recipient = EmailAddress::normalize($recipient);
        $message = $this->store->queueMemberMail($memberId, $recipient, $subject, $template, $body, $metadata);
        $result = $this->deliver($recipient, $subject, $body);
        try {
            // The existing transactional outbox schema has four statuses. Preserve that contract,
            // but expose unknown transport outcomes to callers and in its diagnostic text.
            $this->store->updateMailStatus((string) $message['id'], $result['status'] === 'unknown' ? 'failed' : $result['status'], $result['lastError'] ?? null);
        } catch (Throwable) {
            $result['auditWarning'] = 'The mail outcome could not be recorded in the transactional outbox.';
        }
        return array_replace($message, $result);
    }

    /** A non-null reason holds campaign queues without consuming a delivery attempt. */
    public function configurationProblem(): ?string
    {
        $mode = strtolower(trim((string) $this->config->get('mail.mode', 'disabled')));
        if ($mode === '' || $mode === 'disabled') return 'Email delivery is disabled in this installation. Configure it in System → Email & SMTP.';
        if (!in_array($mode, ['php', 'smtp'], true)) return 'The configured email delivery mode is unsupported.';
        try {
            $this->senderAddress();
        } catch (RuntimeException $error) {
            return $error->getMessage();
        }
        $fromName = $this->config->get('mail.from_name', 'Nikki Wilder');
        if (!is_string($fromName) || EmailAddress::hasForbiddenControls($fromName)) return 'Configure a sender name without line breaks or control characters.';
        if ($mode === 'php' && !function_exists('mail')) return 'PHP mail() is unavailable on this server.';
        if ($mode === 'smtp') {
            try { $host = MailSettingsService::normalizeHost($this->config->get('mail.host', '')); }
            catch (RuntimeException) { return 'Configure a valid SMTP hostname or IP address.'; }
            $port = $this->config->get('mail.port', 587);
            $encryption = strtolower(trim((string) $this->config->get('mail.encryption', 'tls')));
            if ($host === '' || (!is_string($port) && !is_int($port)) || preg_match('/\A[0-9]{1,5}\z/D', (string) $port) !== 1 || (int) $port < 1 || (int) $port > 65535
                || !in_array($encryption, ['none', 'tls', 'ssl'], true)) return 'SMTP is not fully configured.';
            $username = $this->config->get('mail.username', '');
            if (!is_string($username) || EmailAddress::hasForbiddenControls($username) || strlen($username) > 512
                || !is_string($this->config->get('mail.password', ''))) return 'Configure valid SMTP credentials.';
        }
        return null;
    }

    /**
     * Transport only. Campaigns keep their own durable claim journal and must not
     * rewrite the separate member store. beforeSend runs immediately before body
     * transmission, after an SMTP handshake, so a revoked claim cannot send.
     */
    public function deliver(string $recipient, string $subject, string $body, ?callable $beforeSend = null): array
    {
        if (!EmailAddress::isValid($recipient)) return ['status' => 'failed', 'lastError' => 'A valid recipient email address without line breaks is required.', 'retryable' => false];
        if (EmailAddress::hasForbiddenControls($subject)) return ['status' => 'failed', 'lastError' => 'The email subject must not contain line breaks or control characters.', 'retryable' => false];
        $recipient = EmailAddress::normalize($recipient);
        if (($problem = $this->configurationProblem()) !== null) return ['status' => 'skipped', 'lastError' => $problem, 'retryable' => false];
        try {
            if (strtolower(trim((string) $this->config->get('mail.mode'))) === 'php') {
                $this->sendPhpMail($recipient, $subject, $body, $beforeSend);
            } else {
                $this->sendSmtp($recipient, $subject, $body, $beforeSend);
            }
            return ['status' => 'sent', 'lastError' => null, 'retryable' => false];
        } catch (MailDeliveryException $error) {
            return ['status' => $error->canceled ? 'skipped' : ($error->uncertain ? 'unknown' : 'failed'),
                'lastError' => $error->getMessage(), 'retryable' => !$error->uncertain && !$error->canceled && $error->retryable];
        } catch (Throwable) {
            // An unexpected exception may have occurred after a transport accepted
            // the message. It is never safe to silently turn it into a retry.
            return ['status' => 'unknown', 'lastError' => 'Email outcome is unknown. Do not automatically retry this delivery.', 'retryable' => false];
        }
    }

    private function authorize(?callable $beforeSend): void
    {
        if ($beforeSend !== null && !$beforeSend()) {
            throw new MailDeliveryException('Delivery was canceled before message transmission.', retryable: false, canceled: true);
        }
    }

    private function sendPhpMail(string $recipient, string $subject, string $body, ?callable $beforeSend = null): void
    {
        if (!function_exists('mail')) {
            throw new MailDeliveryException('PHP mail() is unavailable.');
        }
        $fromEmail = $this->senderAddress();
        $fromName = (string) $this->config->get('mail.from_name', 'Nikki Wilder');
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $this->encodeDisplayName($fromName) . ' <' . $fromEmail . '>',
        ];
        $this->authorize($beforeSend);
        if (!@mail($recipient, $this->encodeHeader($subject), $body, implode("\r\n", $headers))) {
            throw new MailDeliveryException('PHP mail() did not accept the message.');
        }
    }

    private function sendSmtp(string $recipient, string $subject, string $body, ?callable $beforeSend = null): void
    {
        $host = MailSettingsService::normalizeHost($this->config->get('mail.host', ''));
        $port = (int) $this->config->get('mail.port', 587);
        $encryption = strtolower(trim((string) $this->config->get('mail.encryption', 'tls')));
        $username = trim((string) $this->config->get('mail.username', ''));
        $password = (string) $this->config->get('mail.password', '');
        $fromEmail = $this->senderAddress();
        $fromName = trim((string) $this->config->get('mail.from_name', 'Nikki Wilder'));
        if ($host === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new MailDeliveryException('SMTP is not fully configured.');
        }
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        $errno = 0;
        $error = '';
        $socketHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $socket = @stream_socket_client($transport . $socketHost . ':' . $port, $errno, $error, 15, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new MailDeliveryException('Could not connect to the configured SMTP server.');
        }
        stream_set_timeout($socket, 15);
        try {
            $this->expect($socket, [220]);
            $hostname = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            $this->command($socket, 'EHLO ' . $hostname, [250]);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new MailDeliveryException('SMTP TLS could not be established.');
                }
                $this->command($socket, 'EHLO ' . $hostname, [250]);
            }
            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }
            $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $this->authorize($beforeSend);
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s O'),
                'From: ' . $this->encodeDisplayName($fromName) . ' <' . $fromEmail . '>',
                'To: <' . $recipient . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $hostname . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $safeBody = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", (string) $safeBody) . "\r\n.";
            $this->writeAll($socket, $payload . "\r\n", true);
            $this->expect($socket, [250], true);
            @fwrite($socket, "QUIT\r\n");
        } finally {
            @fclose($socket);
        }
    }

    private function command($socket, string $command, array $expected): void
    {
        $this->writeAll($socket, $command . "\r\n");
        $this->expect($socket, $expected);
    }

    private function writeAll($socket, string $payload, bool $bodyStarted = false): void
    {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = @fwrite($socket, substr($payload, $offset));
            if (!is_int($written) || $written < 1) {
                throw new MailDeliveryException($bodyStarted
                    ? 'SMTP acknowledgement is unknown after transmission began. Do not automatically retry this delivery.'
                    : 'SMTP connection closed before message transmission.', uncertain: $bodyStarted);
            }
            $offset += $written;
        }
    }

    private function expect($socket, array $expected, bool $bodyStarted = false): void
    {
        $code = 0;
        $complete = false;
        for ($count = 0; $count < 50 && ($line = @fgets($socket, 2048)) !== false; $count++) {
            if (preg_match('/^(\d{3})([ -])/', $line, $match)) {
                $code = (int) $match[1];
                if ($match[2] === ' ') { $complete = true; break; }
            }
        }
        if ($complete && in_array($code, $expected, true)) return;
        $rejected = $complete && $code >= 400 && $code < 600;
        if ($bodyStarted && !$rejected) {
            throw new MailDeliveryException('SMTP acknowledgement is unknown after transmission. Do not automatically retry this delivery.', uncertain: true, retryable: false);
        }
        throw new MailDeliveryException($rejected ? 'SMTP rejected the message with status ' . $code . '.' : 'SMTP did not return a complete expected response.', retryable: !$rejected || $code < 500);
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]+$/', $value)) return $value;
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function encodeDisplayName(string $value): string
    {
        if (preg_match('/\A[A-Za-z0-9 ._-]+\z/D', $value)) return $value;
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function senderAddress(): string
    {
        $mode = strtolower(trim((string) $this->config->get('mail.mode', 'disabled')));
        return MailSettingsService::resolveSender($this->config->get('mail.from_email', ''),
            $mode === 'smtp' ? $this->config->get('mail.username', '') : '', $this->config->get('support_email', ''));
    }
}
