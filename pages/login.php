<?php
/**
 * pages/login.php
 */
require_once __DIR__ . '/../config/session.php';

if (is_logged_in()) {
    $target = match (current_user_role()) {
        'admin'    => base_url('pages/dashboard_admin.php'),
        'president'=> base_url('pages/dashboard_president.php'),
        default    => base_url('pages/index.php'),
    };
    header('Location: ' . $target);
    exit;
}

$flash_error   = $_SESSION['flash_error']   ?? null;
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$redirect = $_GET['redirect'] ?? '';

$PAGE_TITLE = 'Login — CampusOrbit';
include __DIR__ . '/../components/header.php';
include __DIR__ . '/../components/navbar.php';
?>
<main class="app-main">
    <div class="auth-shell">
        <div class="auth-card">
            <h1>Welcome back</h1>
            <p class="auth-sub">Sign in to manage clubs, events, and bookings.</p>

            <?php if ($flash_error): ?>
                <div class="alert alert-danger"><?= e($flash_error) ?></div>
            <?php endif; ?>
            <?php if ($flash_success): ?>
                <div class="alert alert-success"><?= e($flash_success) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= base_url('actions/auth_handler.php') ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                </button>
            </form>

            <div class="text-center mt-3">
                <small class="text-muted">
                    New here?
                    <a href="<?= base_url('pages/register.php') ?>">Create a member account</a>
                </small>
                <br>
                <small class="text-muted">
                    Want to start a club?
                    <a href="<?= base_url('pages/register_club.php') ?>">Register your club</a>
                </small>
            </div>

            <div class="alert alert-soft mt-4 small">
                <strong>Demo credentials:</strong>
                <div class="mt-2">
                    <button type="button" class="demo-cred-btn"
                            data-email="admin@campusorbit.com" data-password="admin123">
                        <span class="demo-role">Admin</span>
                        <span class="demo-sep">·</span>
                        <code>admin@campusorbit.com</code>
                        <span class="demo-sep">/</span>
                        <code>admin123</code>
                        <i class="bi bi-arrow-right-circle ms-2"></i>
                    </button>
                </div>
                <div class="mt-2">
                    <button type="button" class="demo-cred-btn"
                            data-email="jarif@campusorbit.com" data-password="president123">
                        <span class="demo-role">President</span>
                        <span class="demo-sep">·</span>
                        <code>jarif@campusorbit.com</code>
                        <span class="demo-sep">/</span>
                        <code>president123</code>
                        <i class="bi bi-arrow-right-circle ms-2"></i>
                    </button>
                </div>
                <div class="mt-2">
                    <button type="button" class="demo-cred-btn"
                            data-email="mahmudul@campusorbit.com" data-password="member123">
                        <span class="demo-role">Member</span>
                        <span class="demo-sep">·</span>
                        <code>mahmudul@campusorbit.com</code>
                        <span class="demo-sep">/</span>
                        <code>member123</code>
                        <i class="bi bi-arrow-right-circle ms-2"></i>
                    </button>
                </div>
                <small class="text-muted d-block mt-2">
                    <i class="bi bi-info-circle"></i> Click any row above to autofill the form.
                </small>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    // Click-to-fill demo credentials on the login page.
    document.querySelectorAll('.demo-cred-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var email = btn.getAttribute('data-email') || '';
            var pwd   = btn.getAttribute('data-password') || '';
            var emailEl = document.getElementById('email');
            var pwdEl   = document.getElementById('password');
            if (emailEl) {
                emailEl.value = email;
                emailEl.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (pwdEl) {
                pwdEl.value = pwd;
                pwdEl.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (emailEl) emailEl.focus();
        });
    });
})();
</script>

<style>
.demo-cred-btn {
    display: block;
    width: 100%;
    text-align: left;
    background: #fff;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: .45rem .65rem;
    font-size: .85rem;
    color: #374151;
    cursor: pointer;
    transition: background .15s, border-color .15s, transform .1s;
}
.demo-cred-btn:hover {
    background: #eff6ff;
    border-color: var(--color-primary-l);
}
.demo-cred-btn:active { transform: scale(.99); }
.demo-cred-btn .demo-role {
    font-weight: 700;
    color: var(--color-primary-d);
}
.demo-cred-btn .demo-sep {
    color: var(--color-muted);
    margin: 0 .25rem;
}
.demo-cred-btn i {
    color: var(--color-primary);
    transition: transform .15s;
}
.demo-cred-btn:hover i {
    transform: translateX(2px);
}
</style>

<?php include __DIR__ . '/../components/footer.php'; ?>