<?php
/**
 * Database Connection Test
 * Run this file to verify backend connects to database.
 * Access: http://localhost:8080/test_db.php
 */

// Load .env manually
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

echo "<h2>Database Connection Test</h2>";

try {
    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $port = $_ENV['DB_PORT'] ?? '3306';
    $database = $_ENV['DB_DATABASE'] ?? 'profile_craft';
    $username = $_ENV['DB_USERNAME'] ?? 'root';
    $password = $_ENV['DB_PASSWORD'] ?? '';

    $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "<p style='color: green;'><strong>✅ Connected successfully!</strong></p>";

} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>❌ Connection Failed:</strong></p>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>