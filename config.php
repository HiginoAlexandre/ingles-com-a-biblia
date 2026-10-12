<?php
declare(strict_types=1);

function loadEnvFile(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '') continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        if (!array_key_exists($key, $_ENV) && getenv($key) === false) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
function envValue(string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}
loadEnvFile(__DIR__ . '/.env');
if (session_status() === PHP_SESSION_NONE) {
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Lax']);
}
date_default_timezone_set((string)envValue('APP_TIMEZONE', 'Africa/Luanda'));
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = (string)envValue('DB_HOST', '127.0.0.1');
    $port = (string)envValue('DB_PORT', '3306');
    $database = (string)envValue('DB_DATABASE', 'bible_reader');
    $charset = (string)envValue('DB_CHARSET', 'utf8mb4');
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    $pdo = new PDO($dsn, (string)envValue('DB_USERNAME', 'root'), (string)envValue('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
