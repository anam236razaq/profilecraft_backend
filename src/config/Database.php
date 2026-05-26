<?php
/**
 * Database Configuration
 *
 * Handles MySQL connection using PDO.
 */

class Database {
    private static ?PDO $instance = null;

    /**
     * Get database connection instance (Singleton pattern)
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$instance = self::connect();
        }
        return self::$instance;
    }

    /**
     * Create new database connection
     */
    private static function connect(): PDO {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $database = $_ENV['DB_DATABASE'] ?? 'profile_craft';
        $username = $_ENV['DB_USERNAME'] ?? 'root';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Only add SSL options if DB_SSL_CA is set (for cloud databases like TiDB)
        if (!empty($_ENV['DB_SSL_CA'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $_ENV['DB_SSL_CA'];
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        try {
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new Exception("DB ERROR: " . $e->getMessage());
        }
    }

    /**
     * Get a query result (SELECT)
     */
    public static function query(string $sql, array $params = []): array {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     */
    public static function execute(string $sql, array $params = []): int {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Get last inserted ID
     */
    public static function lastInsertId(): string {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool {
        return self::getInstance()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollBack(): bool {
        return self::getInstance()->rollBack();
    }
}
