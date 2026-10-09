<?php
/**
 * config/session.php
 *
 * Centralized session, authentication, CSRF, and authorization helpers.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Secure session config (only effective before session_start)
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }
    session_name('CAMPUSORBIT_SID');
    session_start();
}

/* ============================================================
 *  CSRF
 * ============================================================ */

/**
 * Return the current CSRF token, generating one if needed.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a provided CSRF token against the session token.
 */
function csrf_validate(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require a valid CSRF token, otherwise stop with a 403.
 */
function csrf_require(): void {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_validate($token)) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }
}

/**
 * Output a hidden input field with the current CSRF token.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/* ============================================================
 *  Authentication
 * ============================================================ */

/**
 * Is the current visitor logged in?
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Get current user id (or null).
 */
function current_user_id(): ?int {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user's role (or null).
 */
function current_user_role(): ?string {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Get current user's full name.
 */
function current_user_name(): ?string {
    return $_SESSION['user_name'] ?? null;
}

/**
 * Log in a user (sets session and regenerates id).
 */
function login_user(int $user_id, string $role, string $name): void {
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user_id;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = $name;
    $_SESSION['login_time']= time();
}

/**
 * Destroy the session.
 */
function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/* ============================================================
 *  Authorization / Role-based access control
 * ============================================================ */

/**
 * Redirect to login if not authenticated.
 */
function require_login(): void {
    if (!is_logged_in()) {
        $redirect = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: /pages/login.php?redirect=' . urlencode($redirect));
        exit;
    }
}

/**
 * Require a specific role (or one of multiple).
 */
function require_role(string|array $roles): void {
    require_login();
    $allowed = (array) $roles;
    if (!in_array(current_user_role(), $allowed, true)) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
        exit;
    }
}

/* ============================================================
 *  Output helpers (XSS)
 * ============================================================ */

/**
 * Escape for HTML output.
 */
function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
 *  Sweep helper: late-pending events
 *  An event that is still 'pending' when its calendar day has passed
 *  is automatically rejected (status = 'not_confirmed'). Idempotent —
 *  safe to call from any page load.
 * ============================================================ */
function sweep_late_pending_events(): int {
    static $ran = false;
    if ($ran) return 0;
    $ran = true;
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            "UPDATE events
                SET status = 'not_confirmed'
              WHERE status = 'pending'
                AND event_date < CURRENT_DATE"
        );
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/* ============================================================
 *  URL helpers
 * ============================================================ */

/**
 * Compute a root-relative URL (begins with /).
 *
 * The PHP built-in server (php -S 0.0.0.0:8000 -t CampusOrbit)
 * uses CampusOrbit as document root, meaning /pages/... etc.
 * Apache/nginx with DocumentRoot pointing to CampusOrbit/
 * behaves identically.
 *
 * Emitting /pages/index.php works correctly from any
 * subdirectory (pages/, actions/) without further rewrites.
 */
function base_url(string $path = ''): string {
    $path = ltrim($path, '/');
    if ($path === '') return '/';
    return '/' . $path;
}

/* ============================================================
 *  JSON response helper
 * ============================================================ */

/**
 * Send a JSON response and exit.
 */
function json_response($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  Validation helpers
 * ============================================================ */

function valid_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function valid_date(string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function valid_time(string $time): bool {
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time);
}

function positive_int($value): bool {
    return is_numeric($value) && (int)$value > 0;
}

/**
 * Convert "HH:MM" or "HH:MM:SS" (24h) into "h:MM AM/PM" (12h).
 * Returns the original string if it can't parse.
 */
function format_12h(?string $hhmm): string {
    if (!$hhmm) return '';
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $hhmm, $m)) {
        $h = (int)$m[1];
        $min = $m[2];
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h = $h % 12;
        if ($h === 0) $h = 12;
        return $h . ':' . $min . ' ' . $ampm;
    }
    return $hhmm;
}