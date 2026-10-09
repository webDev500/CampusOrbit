<?php
/**
 * pages/create_system_admin.php
 *
 * One-shot helper that creates the system admin account without wiping any data.
 *
 * Visit: /pages/create_system_admin.php?confirm=1
 *
 * Credentials created:
 *   systemadmin@campusorbit.com / system1234
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = get_pdo();

if (($_GET['confirm'] ?? '') !== '1') {
    echo '<h1>Create system admin</h1>';
    echo '<p>This page creates the system administrator account without touching any existing data.</p>';
    echo '<p><strong>Add <code>?confirm=1</code> to run it.</strong></p>';
    echo '<p><a href="?confirm=1" style="color:#1d4ed8;">Create system admin now →</a></p>';
    exit;
}

$email    = 'systemadmin@campusorbit.com';
$fullName = 'System Admin';
$password = 'system1234';
$role     = 'admin';

try {
    $existing = $pdo->prepare("SELECT id, full_name, role FROM users WHERE email = :e");
    $existing->execute([':e' => $email]);
    $row = $existing->fetch();

    if ($row) {
        echo '<p>System admin already exists (id: ' . htmlspecialchars((string)$row['id']) . ').</p>';
        echo '<p>Updating password to keep credentials consistent…</p>';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $upd = $pdo->prepare(
            "UPDATE users SET password_hash = :p, role = :r, full_name = :n WHERE email = :e"
        );
        $upd->execute([':p' => $hash, ':r' => $role, ':n' => $fullName, ':e' => $email]);
        echo '<p><strong>Password reset for existing system admin.</strong></p>';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, role)
             VALUES (:n, :e, :p, :r)"
        )->execute([':n' => $fullName, ':e' => $email, ':p' => $hash, ':r' => $role]);
        echo '<p><strong>System admin created.</strong></p>';
    }

    echo '<h2>Credentials</h2>';
    echo '<pre>Email:    ' . htmlspecialchars($email) . "\n";
    echo 'Password: ' . htmlspecialchars($password) . "\n";
    echo 'Role:     ' . htmlspecialchars($role) . "</pre>";
    echo '<p><a href="' . htmlspecialchars(base_url('pages/login.php')) . '">Go to login →</a></p>';
} catch (Throwable $e) {
    echo '<h1>Error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}