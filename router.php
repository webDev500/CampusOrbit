<?php
/**
 * router.php
 *
 * Used with PHP's built-in web server:
 *   php -S localhost:8000 -t . router.php
 *
 * - Serves static files directly (CSS, JS, images).
 * - Routes everything else through the application.
 *
 * For Apache/nginx, just point the document root at this directory.
 */

// Strip query string
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// Serve real files (CSS, JS, images, etc.) directly
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // PHP built-in server will serve it
}

// Block access to private PHP include directories
if (preg_match('#^/(config|components|database)/#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Root → public homepage
if ($path === '/' || $path === '/CampusOrbit/' || $path === '/CampusOrbit') {
    require __DIR__ . '/index.php';
    return true;
}

// Default: let PHP handle it (matches a real .php file in pages/ or actions/)
return false;