<?php

declare(strict_types=1);

/**
 * TarаkCMS Database Layer
 *
 * PHP 8.2+
 * MySQL / MariaDB
 * PDO
 *
 * Features:
 * - Singleton PDO connection
 * - Configuration validation
 * - Native prepared statements
 * - utf8mb4 support
 * - Optional MySQL TLS configuration
 * - Transaction helper with nested savepoints
 * - Connection reset
 * - Safe error logging
 */

final class Database
{
    private static ?PDO $pdo = null;

    /**
     * Tracks nested transactions initiated through Database::transaction().
     */
    private static int $transactionDepth = 0;

    /**
     * Return the shared PDO connection.
     */
    public static function connection(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = self::validateConfig($config);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        /*
         * Optional MySQL TLS settings.
         *
         * Example:
         * 'ssl_ca' => '/path/to/ca.pem',
         * 'ssl_verify_server_cert' => true,
         */

        if (!empty($db['ssl_ca'])) {
            if (!defined('PDO::MYSQL_ATTR_SSL_CA')) {
                throw new RuntimeException(
                    'The installed PDO MySQL driver does not support the configured TLS option.'
                );
            }

            $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $db['ssl_ca'];

            if (
                !empty($db['ssl_verify_server_cert'])
                && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')
            ) {
                $options[
                    constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')
                ] = true;
            }
        }

        try {
            self::$pdo = new PDO(
                $dsn,
                $db['username'],
                $db['password'],
                $options
            );

            return self::$pdo;
        } catch (PDOException $exception) {
            // Detailed information goes to the server log, not the browser.
            error_log(
                '[TarikCMS][Database] Connection failed. SQLSTATE/code: '
                . (string) $exception->getCode()
                . ' | '
                . $exception->getMessage()
            );

            throw new RuntimeException(
                'Unable to connect to the database. Check the server configuration and logs.',
                (int) $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * Validate database configuration before building the DSN.
     *
     * Required config:
     * $config['db']['host']
     * $config['db']['database']
     * $config['db']['username']
     * $config['db']['password']
     */
    private static function validateConfig(array $config): array
    {
        if (
            !isset($config['db'])
            || !is_array($config['db'])
        ) {
            throw new InvalidArgumentException(
                'Database configuration is missing.'
            );
        }

        $db = $config['db'];

        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (
                !array_key_exists($key, $db)
                || !is_string($db[$key])
            ) {
                throw new InvalidArgumentException(
                    "Invalid database configuration field: {$key}"
                );
            }
        }

        $host = trim($db['host']);
        $database = trim($db['database']);
        $username = $db['username'];
        $password = $db['password'];

        if (
            $host === ''
            || preg_match('/[;\r\n]/', $host)
        ) {
            throw new InvalidArgumentException(
                'Invalid database host.'
            );
        }

        /*
         * Database names are configuration identifiers, not user input.
         * Restricting the format prevents DSN delimiter injection.
         */
        if (
            $database === ''
            || !preg_match('/^[a-zA-Z0-9_$-]+$/', $database)
        ) {
            throw new InvalidArgumentException(
                'Invalid database name.'
            );
        }

        if (trim($username) === '') {
            throw new InvalidArgumentException(
                'Database username cannot be empty.'
            );
        }

        $port = filter_var(
            $db['port'] ?? 3306,
            FILTER_VALIDATE_INT
        );

        if ($port === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException(
                'Database port must be between 1 and 65535.'
            );
        }

        $charset = $db['charset'] ?? 'utf8mb4';

        if (
            !is_string($charset)
            || !preg_match('/^[a-zA-Z0-9_]+$/', $charset)
        ) {
            throw new InvalidArgumentException(
                'Invalid database charset.'
            );
        }

        $sslCa = $db['ssl_ca'] ?? null;

        if ($sslCa !== null && !is_string($sslCa)) {
            throw new InvalidArgumentException(
                'Invalid database TLS CA path.'
            );
        }

        if (is_string($sslCa) && $sslCa !== '' && !is_file($sslCa)) {
            throw new InvalidArgumentException(
                'The configured database TLS CA file does not exist.'
            );
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
            'ssl_ca' => $sslCa,
            'ssl_verify_server_cert' =>
                (bool) ($db['ssl_verify_server_cert'] ?? false),
        ];
    }

    /**
     * Run one or more database operations atomically.
     *
     * Example:
     *
     * Database::transaction(function (PDO $db): void {
     *     // INSERT / UPDATE / DELETE operations
     * });
     *
     * Nested calls use savepoints.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::getConnection();
        $level = self::$transactionDepth;
        $savepoint = 'cms_sp_' . $level;

        if ($level === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        self::$transactionDepth++;

        try {
            $result = $callback($pdo);

            if ($level === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            self::$transactionDepth = $level;

            return $result;
        } catch (Throwable $exception) {
            self::$transactionDepth = $level;

            try {
                if ($level === 0) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            } catch (Throwable $rollbackException) {
                error_log(
                    '[TarikCMS][Database] Transaction rollback failed: '
                    . $rollbackException->getMessage()
                );
            }

            throw $exception;
        }
    }

    /**
     * Return the existing connection or throw a clear error.
     */
    private static function getConnection(): PDO
    {
        if (!(self::$pdo instanceof PDO)) {
            throw new LogicException(
                'Database is not connected. Call Database::connection() first.'
            );
        }

        return self::$pdo;
    }

    /**
     * Close the shared connection.
     *
     * Useful in tests or long-running scripts.
     */
    public static function disconnect(): void
    {
        if (self::$transactionDepth !== 0) {
            throw new LogicException(
                'Cannot disconnect while a Database::transaction() is running.'
            );
        }

        if (self::$pdo instanceof PDO && self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }

        self::$pdo = null;
    }

    /**
     * Check whether the connection has been initialized.
     */
    public static function isConnected(): bool
    {
        return self::$pdo instanceof PDO;
    }

    /**
     * Return the last inserted auto-increment ID.
     */
    public static function lastInsertId(): string
    {
        return self::getConnection()->lastInsertId();
    }
}
