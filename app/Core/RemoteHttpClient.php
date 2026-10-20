<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/** Small bounded HTTP client used only for owner-configured license/update services. */
final class RemoteHttpClient
{
    private ?Closure $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
    {
        $this->assertUrl($url);
        $method = strtoupper($method);
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $url, $payload, $headers);
            if (!is_array($response)) {
                throw new RuntimeException('The configured remote transport returned an invalid response.');
            }
            if (isset($response['payload']) || isset($response['signature'])) {
                return $response;
            }
            $status = (int) ($response['status'] ?? 200);
            $body = (string) ($response['body'] ?? '');
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('The remote service returned HTTP ' . $status . '.');
            }
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('The remote service returned invalid JSON.');
            }
            return $decoded;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headerLines = ['Accept: application/json', 'Content-Type: application/json', 'User-Agent: Velvet-Vixxen/' . VELVET_VIXXEN_VERSION];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('The remote licensing request could not be initialized.');
            }
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $method === 'GET' ? null : $body,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if (!is_string($raw)) {
                throw new RuntimeException('The remote licensing service could not be reached' . ($error !== '' ? ': ' . $error : '.'));
            }
        } else {
            $context = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $method === 'GET' ? '' : $body,
                'ignore_errors' => true,
                'timeout' => 15,
                'max_redirects' => 0,
            ]]);
            $raw = @file_get_contents($url, false, $context);
            if (!is_string($raw)) {
                throw new RuntimeException('The remote licensing service could not be reached.');
            }
            $status = self::statusFromHeaders($http_response_header ?? []);
        }
        if ($status < 200 || $status >= 300) {
            $message = 'The remote service returned HTTP ' . $status . '.';
            $decodedError = json_decode($raw, true);
            if (is_array($decodedError) && is_string($decodedError['message'] ?? null)) {
                $message = (string) $decodedError['message'];
            }
            throw new RuntimeException($message);
        }
        if (strlen($raw) > 2 * 1024 * 1024) {
            throw new RuntimeException('The remote licensing response exceeded the 2 MB limit.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The remote service returned invalid JSON.');
        }
        return $decoded;
    }

    /** @param array<string,string> $headers */
    public function download(string $url, string $target, array $headers = [], int $maxBytes = 26214400): void
    {
        $this->assertUrl($url);
        if ($this->transport !== null) {
            $response = ($this->transport)('GET', $url, [], $headers);
            if (!is_array($response) || (int) ($response['status'] ?? 200) < 200 || (int) ($response['status'] ?? 200) >= 300) {
                throw new RuntimeException('The remote update package could not be downloaded.');
            }
            $body = $response['body'] ?? null;
            if (!is_string($body) || strlen($body) < 1 || strlen($body) > $maxBytes) {
                throw new RuntimeException('The remote update package is empty or exceeds the package limit.');
            }
            if (file_put_contents($target, $body, LOCK_EX) !== strlen($body)) {
                throw new RuntimeException('The downloaded update package could not be staged.');
            }
            @chmod($target, 0640);
            return;
        }

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The update staging directory could not be created.');
        }
        $file = fopen($target, 'wb');
        if (!is_resource($file)) {
            throw new RuntimeException('The update staging file could not be opened.');
        }
        $received = 0;
        try {
            if (function_exists('curl_init')) {
                $headerLines = ['Accept: application/zip', 'User-Agent: Velvet-Vixxen/' . VELVET_VIXXEN_VERSION];
                foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
                $handle = curl_init($url);
                if ($handle === false) throw new RuntimeException('The update download could not be initialized.');
                curl_setopt_array($handle, [
                    CURLOPT_HTTPHEADER => $headerLines,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($file, &$received, $maxBytes): int {
                        $length = strlen($chunk);
                        $received += $length;
                        if ($received > $maxBytes) return 0;
                        return fwrite($file, $chunk) ?: 0;
                    },
                ]);
                $ok = curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $error = curl_error($handle);
                curl_close($handle);
                if ($ok === false || $status < 200 || $status >= 300 || $received < 1 || $received > $maxBytes) {
                    throw new RuntimeException('The update download failed' . ($error !== '' ? ': ' . $error : '.'));
                }
            } else {
                fclose($file);
                $file = null;
                $headerLines = ['Accept: application/zip', 'User-Agent: Velvet-Vixxen/' . VELVET_VIXXEN_VERSION];
                foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
                $context = stream_context_create(['http' => ['header' => implode("\r\n", $headerLines), 'timeout' => 60, 'ignore_errors' => true, 'max_redirects' => 0]]);
                $source = @fopen($url, 'rb', false, $context);
                if (!is_resource($source)) throw new RuntimeException('The update download could not be opened.');
                $output = fopen($target, 'wb');
                if (!is_resource($output)) { fclose($source); throw new RuntimeException('The update staging file could not be opened.'); }
                while (!feof($source)) {
                    $chunk = fread($source, 65536);
                    if (!is_string($chunk)) break;
                    $received += strlen($chunk);
                    if ($received > $maxBytes) { fclose($source); fclose($output); throw new RuntimeException('The update package exceeds the package limit.'); }
                    fwrite($output, $chunk);
                }
                fclose($source); fclose($output);
                if ($received < 1) throw new RuntimeException('The update package was empty.');
            }
        } catch (\Throwable $e) {
            if (is_resource($file)) fclose($file);
            @unlink($target);
            throw $e;
        }
        if (is_resource($file)) fclose($file);
        @chmod($target, 0640);
    }

    private function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new RuntimeException('The licensing or update service URL is invalid.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test');
        if ($scheme !== 'https' && !$local) {
            throw new RuntimeException('Remote licensing and update services must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Credentials must not be embedded in the remote service URL.');
        }
    }

    /** @param list<string> $headers */
    private static function statusFromHeaders(array $headers): int
    {
        foreach (array_reverse($headers) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $match)) return (int) $match[1];
        }
        return 0;
    }
}
