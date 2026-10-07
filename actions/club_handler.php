<?php
/**
 * actions/club_handler.php
 *
 * Club-related actions:
 *   - Public: submit_application  (registers a club application)
 *   - Admin:  approve_application, reject_application
 *   - Student: join_club
 *   - Admin:  set_role
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

csrf_require();

$action = $_POST['action'] ?? '';
$pdo    = get_pdo();

/**
 * Was this request issued via fetch() / AJAX?
 * If not (regular browser form POST), send a redirect with a flash message.
 */
function is_ajax(): bool {
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
}

/**
 * Send a JSON response OR a redirect with flash, depending on caller type.
 */
function respond($payload, int $status = 200, string $redirectOnForm = '/pages/dashboard_admin.php'): void {
    if (is_ajax()) {
        json_response($payload, $status);
    }
    $success = ($status >= 200 && $status < 300);
    $_SESSION[$success ? 'flash_success' : 'flash_error'] =
        $payload['message'] ?? ($success ? 'Done.' : 'Action failed.');
    header('Location: ' . $redirectOnForm);
    exit;
}

/* ============================================================
 *  Public: submit club application
 * ============================================================ */
if ($action === 'submit_application') {
    $clubName   = trim($_POST['club_name']   ?? '');
    $mission    = trim($_POST['mission']     ?? '');
    $category   = trim($_POST['category']    ?? '');
    $pName      = trim($_POST['president_name']  ?? '');
    $pEmail     = trim($_POST['president_email'] ?? '');
    $pPassword  = $_POST['president_password']  ?? '';

    $errors = [];
    if (mb_strlen($clubName) < 3) $errors[] = 'Club name is required.';
    if (mb_strlen($mission)  < 5) $errors[] = 'Please describe the club mission.';
    if ($category === '')        $errors[] = 'Please select a category.';
    if (mb_strlen($pName) < 2)   $errors[] = 'President name is required.';
    if (!valid_email($pEmail))   $errors[] = 'Please enter a valid president email.';
    if (strlen($pPassword) < 6)  $errors[] = 'Password must be at least 6 characters.';

    if ($errors) {
        $_SESSION['flash_error'] = implode(' ', $errors);
        $_SESSION['flash_old']   = [
            'club_name' => $clubName, 'mission' => $mission, 'category' => $category,
            'president_name' => $pName, 'president_email' => $pEmail,
        ];
        if (is_ajax()) {
            json_response(['success' => false, 'redirect' => '/pages/register_club.php'], 400);
        }
        header('Location: /pages/register_club.php');
        exit;
    }

    // Make sure email is not already registered
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e");
    $stmt->execute([':e' => $pEmail]);
    if ($stmt->fetchColumn()) {
        $_SESSION['flash_error'] = 'An account with this email already exists.';
        if (is_ajax()) {
            json_response(['success' => false, 'redirect' => '/pages/register_club.php'], 400);
        }
        header('Location: /pages/register_club.php');
        exit;
    }

    $hash = password_hash($pPassword, PASSWORD_BCRYPT);
    $ins = $pdo->prepare(
        "INSERT INTO club_applications
            (club_name, mission, category, president_name, president_email, president_password)
         VALUES (:n, :m, :c, :pn, :pe, :pp)"
    );
    $ins->execute([
        ':n'  => $clubName, ':m' => $mission, ':c' => $category,
        ':pn' => $pName, ':pe' => $pEmail, ':pp' => $hash,
    ]);

    $_SESSION['flash_success'] = 'Application submitted. An admin will review it shortly.';
    if (is_ajax()) {
        json_response(['success' => true, 'message' => 'Application submitted.']);
    }
    header('Location: /pages/index.php');
    exit;
}

/* ============================================================
 *  Admin: approve_application
 *  - Creates user (president), creates club, links them.
 * ============================================================ */
if ($action === 'approve_application') {
    if (!is_logged_in() || current_user_role() !== 'admin') {
        respond(['success' => false, 'message' => 'Admin only.'], 403);
    }
    $appId = $_POST['application_id'] ?? '';
    if (!positive_int($appId)) {
        respond(['success' => false, 'message' => 'Invalid application.'], 400);
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM club_applications WHERE id = :i AND status = 'pending'"
    );
    $stmt->execute([':i' => $appId]);
    $app = $stmt->fetch();
    if (!$app) {
        respond(['success' => false, 'message' => 'Application is not pending.'], 400);
    }

    try {
        $pdo->beginTransaction();

        // Create user
        $ins = $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, role)
             VALUES (:n, :e, :p, 'president')
             ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash
             RETURNING id"
        );
        $ins->execute([
            ':n' => $app['president_name'],
            ':e' => $app['president_email'],
            ':p' => $app['president_password'],
        ]);
        $userId = (int)$ins->fetchColumn();

        // Create club
        $ins = $pdo->prepare(
            "INSERT INTO clubs (name, mission, category, president_id)
             VALUES (:n, :m, :c, :p)
             ON CONFLICT (name) DO UPDATE SET mission = EXCLUDED.mission,
                                              president_id = EXCLUDED.president_id
             RETURNING id"
        );
        $ins->execute([
            ':n' => $app['club_name'], ':m' => $app['mission'],
            ':c' => $app['category'], ':p' => $userId,
        ]);

        // Mark application approved
        $pdo->prepare(
            "UPDATE club_applications SET status = 'approved', reviewed_at = NOW() WHERE id = :i"
        )->execute([':i' => $appId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['success' => false, 'message' => 'Could not approve.'], 500);
    }

    respond(['success' => true, 'message' => 'Club "' . $app['club_name'] . '" approved. President account created.'], 200);
}

/* ============================================================
 *  Admin: reject_application
 * ============================================================ */
if ($action === 'reject_application') {
    if (!is_logged_in() || current_user_role() !== 'admin') {
        respond(['success' => false, 'message' => 'Admin only.'], 403);
    }
    $appId   = $_POST['application_id'] ?? '';
    $notes   = trim($_POST['admin_notes'] ?? '');
    if (!positive_int($appId)) {
        respond(['success' => false, 'message' => 'Invalid application.'], 400);
    }
    $stmt = $pdo->prepare(
        "UPDATE club_applications
            SET status = 'rejected', admin_notes = :n, reviewed_at = NOW()
          WHERE id = :i AND status = 'pending'"
    );
    $stmt->execute([':n' => $notes, ':i' => $appId]);
    if ($stmt->rowCount() === 0) {
        respond(['success' => false, 'message' => 'Application is not pending.'], 400);
    }
    respond(['success' => true, 'message' => 'Application rejected.'], 200);
}

/* ============================================================
 *  Student: join_club
 * ============================================================ */
if ($action === 'join_club') {
    if (!is_logged_in()) {
        if (is_ajax()) json_response(['success' => false, 'message' => 'Login required.'], 401);
        $_SESSION['flash_error'] = 'Please log in to join clubs.';
        header('Location: /pages/login.php');
        exit;
    }
    $clubId = $_POST['club_id'] ?? '';
    if (!positive_int($clubId)) {
        if (is_ajax()) json_response(['success' => false, 'message' => 'Invalid club.'], 400);
        header('Location: /pages/club_list.php');
        exit;
    }
    // Make sure club exists
    $stmt = $pdo->prepare("SELECT id FROM clubs WHERE id = :c");
    $stmt->execute([':c' => $clubId]);
    if (!$stmt->fetchColumn()) {
        if (is_ajax()) json_response(['success' => false, 'message' => 'Club not found.'], 404);
        $_SESSION['flash_error'] = 'Club not found.';
        header('Location: /pages/club_list.php');
        exit;
    }

    // Already member?
    $stmt = $pdo->prepare(
        "SELECT 1 FROM club_members WHERE club_id = :c AND user_id = :u"
    );
    $stmt->execute([':c' => $clubId, ':u' => current_user_id()]);
    if ($stmt->fetchColumn()) {
        if (is_ajax()) json_response(['success' => false, 'message' => 'You are already a member.'], 409);
        $_SESSION['flash_error'] = 'You are already a member of this club.';
        header('Location: /pages/club_list.php');
        exit;
    }

    try {
        $pdo->prepare(
            "INSERT INTO club_members (club_id, user_id) VALUES (:c, :u)"
        )->execute([':c' => $clubId, ':u' => current_user_id()]);
    } catch (PDOException $e) {
        if (is_ajax()) json_response(['success' => false, 'message' => 'Could not join.'], 500);
        $_SESSION['flash_error'] = 'Could not join the club.';
        header('Location: /pages/club_list.php');
        exit;
    }
    if (is_ajax()) json_response(['success' => true, 'message' => 'Welcome to the club!']);
    $_SESSION['flash_success'] = 'Welcome to the club!';
    header('Location: /pages/club_list.php');
    exit;
}

/* ============================================================
 *  Student: leave_club
 * ============================================================ */
if ($action === 'leave_club') {
    if (!is_logged_in()) {
        if (is_ajax()) json_response(['success' => false, 'message' => 'Login required.'], 401);
        header('Location: /pages/login.php');
        exit;
    }
    $clubId = $_POST['club_id'] ?? '';
    if (!positive_int($clubId)) {
        if (is_ajax()) json_response(['success' => false, 'message' => 'Invalid club.'], 400);
        header('Location: /pages/club_list.php');
        exit;
    }
    $pdo->prepare(
        "DELETE FROM club_members WHERE club_id = :c AND user_id = :u"
    )->execute([':c' => $clubId, ':u' => current_user_id()]);
    if (is_ajax()) json_response(['success' => true, 'message' => 'You have left the club.']);
    $_SESSION['flash_success'] = 'You have left the club.';
    header('Location: /pages/club_list.php');
    exit;
}

/* ============================================================
 *  Admin: set_role (manage elevated roles)
 * ============================================================ */
if ($action === 'set_role') {
    if (!is_logged_in() || current_user_role() !== 'admin') {
        respond(['success' => false, 'message' => 'Admin only.'], 403);
    }
    $userId = $_POST['user_id'] ?? '';
    $newRole = $_POST['new_role'] ?? '';
    if (!positive_int($userId)) {
        respond(['success' => false, 'message' => 'Invalid user.'], 400);
    }
    if (!in_array($newRole, ['student', 'president', 'admin'], true)) {
        respond(['success' => false, 'message' => 'Invalid role.'], 400);
    }
    if ((int)$userId === (int)current_user_id()) {
        respond(['success' => false, 'message' => 'You cannot change your own role.'], 400);
    }
    $pdo->prepare("UPDATE users SET role = :r WHERE id = :i")
        ->execute([':r' => $newRole, ':i' => $userId]);
    respond(['success' => true, 'message' => 'Role updated.'], 200);
}

if (is_ajax()) json_response(['success' => false, 'message' => 'Unknown action.'], 400);
$_SESSION['flash_error'] = 'Unknown action.';
header('Location: /pages/dashboard_admin.php');
exit;