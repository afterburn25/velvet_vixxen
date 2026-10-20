<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $db = (array) $this->config->get('db', []);
        $this->pdo = self::connect($db);
        return $this->pdo;
    }

    public static function connect(array $db): PDO
    {
        $host = trim((string) ($db['host'] ?? 'localhost'));
        $port = (int) ($db['port'] ?? 3306);
        $name = trim((string) ($db['name'] ?? ''));
        $charset = (string) ($db['charset'] ?? 'utf8mb4');

        if ($host === '' || $name === '') {
            throw new RuntimeException('Database host and database name are required.');
        }
        if (preg_match('/[;\x00-\x1F\x7F]/', $host) || !preg_match('/^[A-Za-z0-9_.:\-\[\]]+$/', $host)) {
            throw new RuntimeException('The database host contains unsupported characters.');
        }
        if (!preg_match('/^[A-Za-z0-9_$.-]+$/', $name)) {
            throw new RuntimeException('The database name contains unsupported characters.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('The database port is invalid.');
        }
        if (!preg_match('/^[A-Za-z0-9_$.-]+$/', $charset)) {
            throw new RuntimeException('The database character set is invalid.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        try {
            return new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Tests connectivity and the minimum privileges needed by the installer.
     * The temporary table is always removed when possible.
     *
     * @return array{server_version:string,database:string,privileges_verified:bool}
     */
    public static function test(array $db, bool $verifyPrivileges = true): array
    {
        $pdo = self::connect($db);
        $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $verified = false;

        if ($verifyPrivileges) {
            $table = 'vvx_install_probe_' . strtolower(bin2hex(random_bytes(4)));
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            try {
                $pdo->exec("CREATE TABLE {$quoted} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, value_text VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $pdo->exec("INSERT INTO {$quoted} (value_text) VALUES ('write')");
                $pdo->exec("UPDATE {$quoted} SET value_text='update' WHERE id=1");
                $pdo->exec("DELETE FROM {$quoted} WHERE id=1");
                $verified = true;
            } catch (Throwable $e) {
                throw new RuntimeException('The database connection works, but the user cannot create and modify tables: ' . $e->getMessage(), 0, $e);
            } finally {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS {$quoted}");
                } catch (Throwable) {
                    // The original exception contains the useful failure reason.
                }
            }
        }

        return [
            'server_version' => $serverVersion,
            'database' => $database,
            'privileges_verified' => $verified,
        ];
    }
}
