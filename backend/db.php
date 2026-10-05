<?php
/**
 * AI Mailbox Pro - Database Connector
 * Connects via PDO MySQL using environment variables.
 */

require_once __DIR__ . '/config.php';

$host = app_env('DB_HOST', '127.0.0.1');
$port = app_env('DB_PORT', '3306');
$db   = app_env('DB_NAME', 'ai_mailbox_db');
$user = app_env('DB_USER', 'root');
$pass = app_env('DB_PASS', '');
$charset = 'utf8mb4';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed. Please check your .env configuration.',
        'details' => app_env('APP_ENV') === 'development' ? $e->getMessage() : null
    ]);
    exit;
}
