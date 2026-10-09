<?php
/**
 * config/db.php
 *
 * Centralized PostgreSQL connection using PDO.
 * - Loads credentials from environment variables if defined,
 *   otherwise falls back to local development defaults.
 */

declare(strict_types=1);

// Parse Render/Heroku style DATABASE_URL if available
$dbUrl = getenv('DATABASE_URL');
if ($dbUrl) {
    $parsed = parse_url($dbUrl);
    $dbHost = $parsed['host'] ?? 'localhost';
    $dbPort = $parsed['port'] ?? '5432';
    $dbName = ltrim($parsed['path'] ?? '/CampusOrbit', '/');
    $dbUser = $parsed['user'] ?? 'postgres';
    $dbPass = $parsed['pass'] ?? '';
} else {
    // Fallbacks for local development
    $dbHost = getenv('PGHOST') ?: 'localhost';
    $dbPort = getenv('PGPORT') ?: '5432';
    $dbName = getenv('PGDATABASE') ?: 'CampusOrbit';
    $dbUser = getenv('PGUSER') ?: 'postgres';
    $dbPass = getenv('PGPASSWORD') ?: '';
}

// DSN
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $dbName);

// PDO options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

/**
 * Get a singleton PDO instance.
 *
 * @return PDO
 */
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        global $dsn, $dbUser, $dbPass, $options;
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        } catch (PDOException $e) {
            // Do not leak credentials; show friendly error.
            http_response_code(500);
            echo '<h1>Database connection failed</h1>';
            echo '<p>The application could not connect to the database. Please check your configuration.</p>';
            if (defined('DEBUG') && DEBUG) {
                echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            }
            exit;
        }
    }
    return $pdo;
}
