<?php
/**
 * actions/auth_handler.php
 *
 * Handles registration, login, and logout.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$action = $_POST['action'] ?? '';

$pdo = get_pdo();

/* ============================================================
 *  Registration
 * ============================================================ */
if ($action === 'register') {
    csrf_require();

    $name     = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $errors = [];
    if ($name === '' || mb_strlen($name) < 2) {
        $errors[] = 'Please enter your full name.';
    }
    if (!valid_email($email)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if ($errors) {
        $_SESSION['flash_error'] = implode(' ', $errors);
        $_SESSION['flash_old']    = ['name' => $name, 'email' => $email];
        header('Location: /pages/register.php');
        exit;
    }

    // Check duplicate
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e");
    $stmt->execute([':e' => $email]);
    if ($stmt->fetchColumn()) {
        $_SESSION['flash_error'] = 'An account with this email already exists.';
        $_SESSION['flash_old']    = ['name' => $name, 'email' => $email];
        header('Location: /pages/register.php');
        exit;
    }

    // Insert as student
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $ins = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, role)
         VALUES (:n, :e, :p, 'student')"
    );
    $ins->execute([':n' => $name, ':e' => $email, ':p' => $hash]);

    // Auto-login
    $userId = (int)$pdo->lastInsertId();
    login_user($userId, 'student', $name);

    $_SESSION['flash_success'] = 'Welcome to CampusOrbit!';
    header('Location: /pages/index.php');
    exit;
}

/* ============================================================
 *  Login
 * ============================================================ */
if ($action === 'login') {
    csrf_require();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirect = $_POST['redirect'] ?? '';

    if (!valid_email($email) || $password === '') {
        $_SESSION['flash_error'] = 'Please enter a valid email and password.';
        header('Location: /pages/login.php?redirect=' . urlencode($redirect));
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT id, full_name, password_hash, role FROM users WHERE email = :e"
    );
    $stmt->execute([':e' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $_SESSION['flash_error'] = 'Invalid email or password.';
        header('Location: /pages/login.php?redirect=' . urlencode($redirect));
        exit;
    }

    login_user((int)$user['id'], $user['role'], $user['full_name']);

    // Role-based redirect
    $target = '/pages/' . match ($user['role']) {
        'admin'    => 'dashboard_admin.php',
        'president'=> 'dashboard_president.php',
        default    => 'index.php',
    };

    // Honor ?redirect only if it points inside the app and avoids '..'
    if ($redirect && str_starts_with($redirect, '/') && !str_contains($redirect, '..')) {
        $target = $redirect;
    }

    $_SESSION['flash_success'] = 'Welcome back, ' . $user['full_name'] . '!';
    header('Location: ' . $target);
    exit;
}

/* ============================================================
 *  Logout
 * ============================================================ */
if ($action === 'logout') {
    csrf_require();
    logout_user();
    header('Location: /pages/index.php');
    exit;
}

http_response_code(400);
echo 'Unknown action.';