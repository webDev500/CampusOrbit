<?php
/**
 * pages/register.php — student registration
 */
require_once __DIR__ . '/../config/session.php';

if (is_logged_in()) {
    header('Location: ' . base_url('pages/dashboard_student.php'));
    exit;
}

$flash_error = $_SESSION['flash_error'] ?? null;
$flash_old   = $_SESSION['flash_old']   ?? [];
unset($_SESSION['flash_error'], $_SESSION['flash_old']);

$PAGE_TITLE = 'Sign Up — CampusOrbit';
include __DIR__ . '/../components/header.php';
include __DIR__ . '/../components/navbar.php';
?>
<main class="app-main">
    <div class="auth-shell">
        <div class="auth-card">
            <h1>Create your account</h1>
            <p class="auth-sub">Join CampusOrbit as a member to discover clubs and events.</p>

            <?php if ($flash_error): ?>
                <div class="alert alert-danger"><?= e($flash_error) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= base_url('actions/auth_handler.php') ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">

                <div class="mb-3">
                    <label class="form-label" for="full_name">Full Name</label>
                    <input type="text" class="form-control" id="full_name" name="full_name"
                           value="<?= e($flash_old['name'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?= e($flash_old['email'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="6" required>
                    <div class="form-text">At least 6 characters.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="6" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-person-plus"></i> Create Account
                </button>
            </form>

            <div class="text-center mt-3">
                <small class="text-muted">
                    Already have an account?
                    <a href="<?= base_url('pages/login.php') ?>">Sign in</a>
                </small>
                <br>
                <small class="text-muted">
                    Want to register a club?
                    <a href="<?= base_url('pages/register_club.php') ?>">Register your club</a>
                </small>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../components/footer.php'; ?>