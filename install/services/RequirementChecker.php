<?php
declare(strict_types=1);

namespace Velvet\Installer;

final class RequirementChecker
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return list<array{label:string,status:string,detail:string,required:bool}> */
    public function systemChecks(): array
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $free = @disk_free_space($this->root);
        $freeText = is_float($free) || is_int($free) ? $this->bytes((float) $free) . ' available' : 'Could not determine';
        $rewrite = function_exists('apache_get_modules')
            ? in_array('mod_rewrite', apache_get_modules(), true)
            : null;

        return [
            $this->check('PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>='), 'Running PHP ' . PHP_VERSION, true),
            $this->check('PDO extension', extension_loaded('pdo'), extension_loaded('pdo') ? 'Loaded' : 'Missing', true),
            $this->check('PDO MySQL driver', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'Loaded' : 'Missing', true),
            $this->check('Fileinfo extension', extension_loaded('fileinfo'), extension_loaded('fileinfo') ? 'Loaded' : 'Missing', true),
            $this->check('JSON support', function_exists('json_encode'), function_exists('json_encode') ? 'Available' : 'Missing', true),
            $this->check('OpenSSL', extension_loaded('openssl'), extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : 'Missing', true),
            $this->check('Sessions', extension_loaded('session'), extension_loaded('session') ? 'Available' : 'Missing', true),
            $this->check('Random-byte security', function_exists('random_bytes'), function_exists('random_bytes') ? 'Available' : 'Missing', true),
            $this->check('ZIP extension', extension_loaded('zip'), extension_loaded('zip') ? 'Available for add-ons and upgrades' : 'Optional now; needed for in-admin ZIP installs', false),
            $this->check('GD or Imagick', extension_loaded('gd') || extension_loaded('imagick'), extension_loaded('gd') || extension_loaded('imagick') ? 'Image processing available' : 'Optional; original image can be used until enabled', false),
            $this->check('cURL', extension_loaded('curl'), extension_loaded('curl') ? 'Available for external services' : 'Optional until payments/Canva sync are enabled', false),
            $this->check('Mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'Available' : 'Recommended for international text', false),
            $this->check('HTTPS', $https, $https ? 'Secure connection detected' : 'Not detected; use HTTPS in production', false),
            $this->check('URL rewriting', $rewrite !== false, $rewrite === true ? 'Apache mod_rewrite loaded' : ($rewrite === false ? 'mod_rewrite not loaded' : 'Server did not expose module details; .htaccess will be written'), false),
            $this->check('Disk space', !is_numeric($free) || (float) $free >= 250 * 1024 * 1024, $freeText, true),
            $this->check('File uploads enabled', filter_var((string) ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN), 'upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size'), true),
        ];
    }

    public function requiredPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['required'] ?? false) && ($check['status'] ?? 'fail') !== 'pass') {
                return false;
            }
        }
        return true;
    }

    private function check(string $label, bool $passed, string $detail, bool $required): array
    {
        return [
            'label' => $label,
            'status' => $passed ? 'pass' : ($required ? 'fail' : 'warn'),
            'detail' => $detail,
            'required' => $required,
        ];
    }

    private function bytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return number_format($bytes, $index > 1 ? 1 : 0) . ' ' . $units[$index];
    }
}
