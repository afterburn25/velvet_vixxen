<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/** The provider endpoint and method are fixed; only server-fetched OVER results count. */
final class YotiAgeProvider
{
    public const SESSION_TTL = 900;
    private const API = 'https://age.yoti.com/api/v1/sessions';
    private readonly ?Closure $transport;

    public function __construct(private readonly Config $config, private readonly string $sdkId, private readonly string $encryptedKey, ?callable $transport = null)
    {
        self::uuid($sdkId); $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public static function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di', $value) !== 1) throw new RuntimeException('Age verification is unavailable. Please try again later.', 503);
        return strtolower($value);
    }

    public static function timestamp(mixed $value): int
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) throw new RuntimeException('Age verification returned an unrecognised time.', 503);
        try { $date = new DateTimeImmutable($value); $errors = DateTimeImmutable::getLastErrors(); }
        catch (Throwable) { throw new RuntimeException('Age verification returned an unrecognised time.', 503); }
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) throw new RuntimeException('Age verification returned an unrecognised time.', 503);
        return $date->getTimestamp();
    }

    public function create(string $reference, int $threshold, string $callback, int $now): array
    {
        if (preg_match('/\Avvx-[a-f0-9]{48}\z/D', $reference) !== 1 || $threshold < 18 || $threshold > 99) throw new RuntimeException('Age verification is unavailable.', 503);
        $parts = parse_url($callback);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $callback)) throw new RuntimeException('Age verification needs the configured HTTPS website address.', 503);
        $body = ['type' => 'OVER', 'ttl' => self::SESSION_TTL, 'reference_id' => $reference,
            'doc_scan' => ['allowed' => true, 'threshold' => $threshold, 'authenticity' => 'AUTO', 'level' => 'PASSIVE', 'retry_limit' => 3],
            'age_estimation' => ['allowed' => false], 'digital_id' => ['allowed' => false],
            'credit_card' => ['allowed' => false], 'mobile' => ['allowed' => false],
            'callback' => ['auto' => false, 'url' => $callback], 'synchronous_checks' => true];
        $result = $this->request('POST', self::API, $body);
        $id = self::uuid($result['id'] ?? null); $expires = self::timestamp($result['expires_at'] ?? null);
        if ($expires <= $now || $expires > $now + self::SESSION_TTL + 30) throw new RuntimeException('Age verification returned an invalid session lifetime.', 503);
        return ['id' => $id, 'expires_at' => $expires];
    }

    public function browserUrl(string $id): string
    {
        return 'https://age.yoti.com?sessionId=' . rawurlencode(self::uuid($id)) . '&sdkId=' . rawurlencode($this->sdkId);
    }

    /** PENDING is a retryable non-grant; every other unaccepted state fails closed. */
    public function verified(array $attempt, int $now): bool
    {
        $id = self::uuid($attempt['id'] ?? null);
        $result = $this->request('GET', self::API . '/' . $id . '/result');
        $invalid = static function (): never { throw new RuntimeException('Age verification could not confirm this browser session. Start a new check or try again later.', 403); };
        if (($result['id'] ?? null) !== $id || ($result['sdk_id'] ?? null) !== $this->sdkId
            || ($result['reference_id'] ?? null) !== ($attempt['reference'] ?? null) || ($result['type'] ?? null) !== 'OVER') $invalid();
        $created = self::timestamp($result['created_at'] ?? null); $updated = self::timestamp($result['updated_at'] ?? null); $expires = self::timestamp($result['expires_at'] ?? null);
        if (!is_int($attempt['started_at'] ?? null) || !is_int($attempt['expires_at'] ?? null)
            || $created < $attempt['started_at'] - 30 || $created > $attempt['started_at'] + 30 || $created > $now + 30
            || $expires !== $attempt['expires_at'] || $expires <= $now || $expires <= $created || $expires > $attempt['started_at'] + self::SESSION_TTL + 30
            || $updated < $created || $updated > $now + 30 || $updated >= $expires) $invalid();
        if (in_array($result['status'] ?? null, ['PENDING', 'IN_PROGRESS'], true)) return false;
        $method = $result['doc_scan'] ?? null;
        if (($result['status'] ?? null) !== 'COMPLETE' || ($result['method'] ?? null) !== 'DOC_SCAN'
            || !is_int($result['age'] ?? null) || $result['age'] !== ($attempt['threshold'] ?? null)
            || !is_array($method) || ($method['allowed'] ?? null) !== true || ($method['threshold'] ?? null) !== $attempt['threshold']
            || ($method['level'] ?? null) !== 'PASSIVE' || (isset($method['authenticity']) && $method['authenticity'] !== 'AUTO')) $invalid();
        return true;
    }

    private function request(string $method, string $url, ?array $data = null): array
    {
        try {
            $key = (new SecureValue($this->config))->decrypt($this->encryptedKey);
            if (strlen($key) < 8 || strlen($key) > 4096 || preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/D', $key) !== 1) throw new RuntimeException();
            $headers = ['Authorization: Bearer ' . $key, 'Yoti-SDK-Id: ' . $this->sdkId, 'Content-Type: application/json', 'Accept: application/json'];
            $body = $data === null ? null : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($this->transport !== null) $response = ($this->transport)($method, $url, $headers, $body);
            else {
                if (!function_exists('curl_init')) throw new RuntimeException();
                $handle = curl_init($url); if ($handle === false) throw new RuntimeException(); $output = '';
                try {
                    curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$output): int {
                            if (strlen($output) + strlen($chunk) > 262144) return 0; $output .= $chunk; return strlen($chunk);
                        }]);
                    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
                    if (curl_exec($handle) === false) throw new RuntimeException();
                    $response = ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $output];
                } finally { curl_close($handle); }
            }
            if (!is_array($response) || ($response['status'] ?? null) !== 200 || !is_string($response['body'] ?? null) || strlen($response['body']) > 262144) throw new RuntimeException();
            $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) throw new RuntimeException();
            return $decoded;
        } catch (Throwable) { throw new RuntimeException('The age verification service is unavailable. No access was granted. Please try again later.', 503); }
    }
}
