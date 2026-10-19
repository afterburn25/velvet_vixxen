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
        $recipient = strtolower(trim($recipient));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid recipient email address is required.');
        }
        $message = $this->store->queueMemberMail($memberId, $recipient, $subject, $template, $body, $metadata);
        $mode = strtolower((string) $this->config->get('mail.mode', 'disabled'));
        try {
            if ($mode === 'disabled' || $mode === '') {
                $this->store->updateMailStatus((string) $message['id'], 'skipped', 'Email delivery is disabled.');
                $message['status'] = 'skipped';
                return $message;
            }
            if ($mode === 'php') {
                $this->sendPhpMail($recipient, $subject, $body);
            } elseif ($mode === 'smtp') {
                $this->sendSmtp($recipient, $subject, $body);
            } else {
                throw new RuntimeException('Unsupported mail delivery mode.');
            }
            $this->store->updateMailStatus((string) $message['id'], 'sent');
            $message['status'] = 'sent';
            return $message;
        } catch (Throwable $e) {
            $this->store->updateMailStatus((string) $message['id'], 'failed', $e->getMessage());
            $message['status'] = 'failed';
            $message['lastError'] = $e->getMessage();
            return $message;
        }
    }

    private function sendPhpMail(string $recipient, string $subject, string $body): void
    {
        if (!function_exists('mail')) {
            throw new RuntimeException('PHP mail() is unavailable.');
        }
        $fromEmail = (string) $this->config->get('mail.from_email', $this->config->get('support_email', ''));
        $fromName = (string) $this->config->get('mail.from_name', 'Nikki Wilder');
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The configured From email address is invalid.');
        }
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . '>',
        ];
        if (!@mail($recipient, $this->encodeHeader($subject), $body, implode("\r\n", $headers))) {
            throw new RuntimeException('PHP mail() did not accept the message.');
        }
    }

    private function sendSmtp(string $recipient, string $subject, string $body): void
    {
        $host = trim((string) $this->config->get('mail.host', ''));
        $port = (int) $this->config->get('mail.port', 587);
        $encryption = strtolower(trim((string) $this->config->get('mail.encryption', 'tls')));
        $username = trim((string) $this->config->get('mail.username', ''));
        $password = (string) $this->config->get('mail.password', '');
        $fromEmail = strtolower(trim((string) $this->config->get('mail.from_email', $this->config->get('support_email', ''))));
        $fromName = trim((string) $this->config->get('mail.from_name', 'Nikki Wilder'));
        if ($host === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP is not fully configured.');
        }
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $error, 15, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new RuntimeException('Could not connect to the SMTP server: ' . ($error !== '' ? $error : 'connection failed') . '.');
        }
        stream_set_timeout($socket, 15);
        try {
            $this->expect($socket, [220]);
            $hostname = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            $this->command($socket, 'EHLO ' . $hostname, [250]);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('SMTP TLS could not be established.');
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
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s O'),
                'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . '>',
                'To: <' . $recipient . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $hostname . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $safeBody = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", (string) $safeBody) . "\r\n.";
            if (@fwrite($socket, $payload . "\r\n") === false) {
                throw new RuntimeException('SMTP connection closed while sending the message.');
            }
            $this->expect($socket, [250]);
            @fwrite($socket, "QUIT\r\n");
        } finally {
            @fclose($socket);
        }
    }

    private function command($socket, string $command, array $expected): void
    {
        if (@fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('SMTP connection closed while sending a command.');
        }
        $this->expect($socket, $expected);
    }

    private function expect($socket, array $expected): void
    {
        $code = 0;
        $lines = [];
        while (($line = fgets($socket, 2048)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            if (preg_match('/^(\d{3})([ -])/', $line, $match)) {
                $code = (int) $match[1];
                if ($match[2] === ' ') break;
            }
        }
        if ($lines === [] || !in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP server rejected the request' . ($lines !== [] ? ': ' . implode(' | ', $lines) : '.'));
        }
    }

    private function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        if (preg_match('/^[\x20-\x7E]+$/', $value)) return $value;
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
