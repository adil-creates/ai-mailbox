<?php
/**
 * AI Mailbox Pro - Configuration File
 * Loads environment variables, configures CORS, and sets up session & security headers.
 */

// Simple lightweight .env reader
if (!function_exists('app_env')) {
    function app_env($key, $default = null) {
        static $envCache = null;
        if ($envCache === null) {
            $envCache = [];
            $envFile = __DIR__ . '/.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '#') === 0) continue;
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $name = trim($parts[0]);
                        $value = trim($parts[1]);
                        if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match("/^'(.*)'$/", $value, $matches)) {
                            $value = $matches[1];
                        }
                        $envCache[$name] = $value;
                    }
                }
            }
        }
        return $envCache[$key] ?? getenv($key) ?: $default;
    }
}

// Error handling: return JSON on error
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function ($exception) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $exception->getMessage()
    ]);
    error_log("Mailbox API Error: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
    exit;
});

// Session Lifetime
define('SESSION_LIFETIME', (int)app_env('SESSION_LIFETIME', 86400));
ini_set('session.cookie_lifetime', SESSION_LIFETIME);
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CORS Configuration
$allowedOrigin = app_env('ALLOWED_ORIGIN', 'http://localhost:5173');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin && ($origin === $allowedOrigin || strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: $allowedOrigin");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Security Keys
define('ENCRYPTION_KEY', app_env('ENCRYPTION_KEY', 'default_change_this_in_production_key!'));
define('GEMINI_API_KEY', app_env('GEMINI_API_KEY', ''));
define('ADMIN_WEBHOOK_URL', app_env('ADMIN_WEBHOOK_URL', ''));
