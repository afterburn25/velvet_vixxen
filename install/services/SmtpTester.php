<?php
declare(strict_types=1);

namespace Velvet\Installer;

use RuntimeException;

final class SmtpTester
{
    /**
     * @return array{mode:string,message:string,server_response:string}
     */
    public function test(array $settings, string $recipient = ''): array
    {
        $mode = strtolower(trim((string) ($settings['mode'] ?? 'disabled')));
        if ($mode === 'disabled') {
            return [
                'mode' => 'disabled',
                'message' => 'Email setup is skipped. It can be configured later in administration.',
                'server_response' => '',
            ];
        }
        if ($mode === 'php') {
            if (!function_exists('mail')) {
                throw new RuntimeException('PHP mail() is unavailable on this server. Choose SMTP or configure email later.');
            }
            return [
                'mode' => 'php',
                'message' => 'PHP mail() is available. Delivery still depends on the hosting provider mail configuration.',
                'server_response' => 'mail() available',
            ];
        }
        if ($mode !== 'smtp') {
            throw new RuntimeException('Choose Disabled, PHP mail, or SMTP for email delivery.');
        }

        $host = trim((string) ($settings['host'] ?? ''));
        $port = (int) ($settings['port'] ?? 587);
        $encryption = strtolower(trim((string) ($settings['encryption'] ?? 'tls')));
        $username = trim((string) ($settings['username'] ?? ''));
        $password = (string) ($settings['password'] ?? '');
        $fromEmail = EmailAddress::normalize($settings['from_email'] ?? '');
        $fromName = trim((string) ($settings['from_name'] ?? 'Nikki Wilder'));
        $recipient = EmailAddress::normalize($recipient);

        if ($host === '') {
            throw new RuntimeException('Enter the SMTP server hostname.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('The SMTP port must be between 1 and 65535.');
        }
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new RuntimeException('SMTP encryption must be None, STARTTLS, or SSL/TLS.');
        }
        if (!EmailAddress::isValid($fromEmail)) {
            throw new RuntimeException('Enter a valid From email address.');
        }
        if (($username === '') xor ($password === '')) {
            throw new RuntimeException('Enter both the SMTP username and password, or leave both blank.');
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $error,
            12,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('Could not connect to the SMTP server: ' . ($error !== '' ? $error : 'connection failed') . ' (' . $errno . ').');
        }
        stream_set_timeout($socket, 12);

        try {
            $greeting = $this->expect($socket, [220]);
            $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $this->command($socket, 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', (string) $hostname), [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new RuntimeException('The SMTP server accepted STARTTLS, but the encrypted connection could not be established.');
                }
                $this->command($socket, 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', (string) $hostname), [250]);
            }

            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }

            $sent = false;
            if ($recipient !== '' && EmailAddress::isValid($recipient)) {
                $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
                $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
                $this->command($socket, 'DATA', [354]);
                $subject = 'Velvet Vixxen installer email test';
                $body = "Your Velvet Vixxen website can connect to this SMTP server.\r\n\r\nTested at " . gmdate('c') . ".";
                $headers = [
                    'Date: ' . gmdate('D, d M Y H:i:s O'),
                    'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . '>',
                    'To: <' . $recipient . '>',
                    'Subject: ' . $subject,
                    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($hostname ?: 'localhost') . '>',
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ];
                $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
                fwrite($socket, $message . "\r\n");
                $this->expect($socket, [250]);
                $sent = true;
            }

            @fwrite($socket, "QUIT\r\n");
            return [
                'mode' => 'smtp',
                'message' => $sent
                    ? 'SMTP authentication succeeded and a test message was accepted for delivery.'
                    : 'SMTP connection and authentication succeeded.',
                'server_response' => trim($greeting),
            ];
        } finally {
            @fclose($socket);
        }
    }

    private function command($socket, string $command, array $expected): string
    {
        if (@fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('The SMTP connection closed while sending a command.');
        }
        return $this->expect($socket, $expected);
    }

    private function expect($socket, array $expected): string
    {
        $lines = [];
        $code = 0;
        while (($line = fgets($socket, 2048)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            if (preg_match('/^(\d{3})([ -])/', $line, $match)) {
                $code = (int) $match[1];
                if ($match[2] === ' ') {
                    break;
                }
            }
        }
        if ($lines === []) {
            throw new RuntimeException('The SMTP server did not return a response.');
        }
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP server rejected the request: ' . implode(' | ', $lines));
        }
        return implode(' | ', $lines);
    }

    private function encodeHeader(string $value): string
    {
        if ($value === '') {
            return 'Velvet Vixxen';
        }
        return preg_match('/^[\x20-\x7E]+$/', $value)
            ? str_replace(["\r", "\n"], '', $value)
            : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
