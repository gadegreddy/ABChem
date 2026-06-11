<?php
/** Database Configuration - AB Chem India * Compatible with PHP 8.2, 8.3, 8.4, 8.5+  * SECURITY: All credentials are loaded from the .env file located OUTSIDE public_html. */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

// Central error log path (home folder, outside public_html)
define('ERROR_LOG_PATH', 'error_log');

// ── Load .env file (must be outside public_html) ─────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && $line[0] !== '#') {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

// ── Require each credential — throw if missing, NEVER use hardcoded fallback ─
function requireEnv(string $key): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        error_log(date('Y-m-d H:i:s') . " | FATAL: Environment variable '{$key}' is not set. Check .env file.\n", 3, ERROR_LOG_PATH);
        http_response_code(503);
        die('Service configuration error. Please contact support.');
    }
    return $value;
}

define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost'); // host is safe to default
define('DB_NAME',    requireEnv('DB_NAME'));
define('DB_USER',    requireEnv('DB_USER'));
define('DB_PASS',    requireEnv('DB_PASS'));
define('DB_CHARSET', 'utf8mb4');
define('DB_DEBUG',   false);  // NEVER set true in production

class Database {
    private static ?self $instance = null;
    private PDO $connection;

    private function __construct() {
        $this->connect();
    }

    private function connect(): void {
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=%s",
            DB_HOST, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false, // SECURITY: disabled — prevents stale connections on shared hosting
        ];

        if (defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) {
            $options[Pdo\Mysql::ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+05:30'";
        } elseif (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+05:30'";
        } else {
            $needsInit = true;
        }

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            if (isset($needsInit) && $needsInit) {
                $this->connection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                $this->connection->exec("SET time_zone = '+05:30'");
            }
        } catch (PDOException $e) {
            $this->handleConnectionError($e);
        }
    }

    private function handleConnectionError(PDOException $e): void {
        error_log(sprintf(
            "[%s] Database connection failed: %s\n",
            date('Y-m-d H:i:s'),
            $e->getMessage()
        ), 3, ERROR_LOG_PATH);

        // Never expose connection details to the browser
        http_response_code(503);
        die("Service temporarily unavailable. Please try again later.");
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        try {
            $this->connection->query('SELECT 1');
        } catch (PDOException $e) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Alias for getConnection() — returns the live PDO handle for callers that
     * need raw prepared statements (e.g. dynamic IN(...) lists in product.php
     * and the pharmacopeia sync cron). Keeps the auto-reconnect behaviour.
     */
    public function getPdo(): PDO {
        return $this->getConnection();
    }

    public function query(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log(sprintf(
                "[%s] DB query failed: %s | SQL: %s\n",
                date('Y-m-d H:i:s'),
                $e->getMessage(),
                $sql
            ), 3, ERROR_LOG_PATH);
            throw $e;
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params)->fetch();
        return $result !== false ? $result : null;
    }

    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchValue(string $sql, array $params = []): mixed {
        $result = $this->query($sql, $params)->fetchColumn();
        return $result !== false ? $result : null;
    }

    public function insert(string $table, array $data): int {
        $fields       = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        $sql = sprintf(
            "INSERT INTO `%s` (`%s`) VALUES (%s)",
            $table,
            implode('`, `', $fields),
            $placeholders
        );
        $this->query($sql, $data);
        return (int)$this->connection->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = [];
        foreach (array_keys($data) as $field) {
            $set[] = "`$field` = :$field";
        }
        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE %s",
            $table,
            implode(', ', $set),
            $where
        );
        return $this->query($sql, array_merge($data, $whereParams))->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM `$table` WHERE $where";
        return $this->query($sql, $params)->rowCount();
    }

    public function beginTransaction(): bool { return $this->getConnection()->beginTransaction(); }
    public function commit(): bool           { return $this->getConnection()->commit(); }
    public function rollback(): bool         { return $this->getConnection()->rollBack(); }

    public function tableExists(string $tableName): bool {
        $result = $this->fetchOne(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = :db AND table_name = :table LIMIT 1",
            ['db' => DB_NAME, 'table' => $tableName]
        );
        return !empty($result);
    }

    private function __clone() {}
    public function __wakeup() { throw new Exception("Cannot unserialize singleton"); }
}

