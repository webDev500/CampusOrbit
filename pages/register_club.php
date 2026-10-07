<?php
/**
 * pages/register_club.php
 *
 * Public form to apply for a new club (creates a club_application row).
 */
require_once __DIR__ . '/../config/session.php';

$flash_error   = $_SESSION['flash_error']   ?? null;
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_old     = $_SESSION['flash_old']     ?? [];
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_old']);

$categories = ['Technology', 'Arts', 'Academic', 'Sports', 'Cultural', 'Social', 'Entrepreneurship', 'Other'];

$PAGE_TITLE = 'Register a Club — CampusOrbit';
include __DIR__ . '/../components/header.php';
include __DIR__ . '/../components/navbar.php';
?>
<main class="app-main">
    <div class="auth-shell">
        <div class="auth-card" style="max-width: 540px;">
            <h1>Register a Club</h1>
            <p class="auth-sub">Submit your club application. An admin will review it.</p>

            <?php if ($flash_error): ?>
                <div class="alert alert-danger"><?= e($flash_error) ?></div>
            <?php endif; ?>
            <?php if ($flash_success): ?>
                <div class="alert alert-success"><?= e($flash_success) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= base_url('actions/club_handler.php') ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit_application">

                <h6 class="mt-2 text-muted text-uppercase small">Club Details</h6>
                <div class="mb-3">
                    <label class="form-label" for="club_name">Club Name</label>
                    <input type="text" class="form-control" id="club_name" name="club_name"
                           value="<?= e($flash_old['club_name'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="mission">Mission / Description</label>
                    <textarea class="form-control" id="mission" name="mission" rows="3" required><?= e($flash_old['mission'] ?? '') ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category" name="category" required>
                        <option value="">Select a category…</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat) ?>"
                                <?= ($flash_old['category'] ?? '') === $cat ? 'selected' : '' ?>>
                                <?= e($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <h6 class="mt-3 text-muted text-uppercase small">President Account</h6>
                <div class="mb-3">
                    <label class="form-label" for="president_name">Full Name</label>
                    <input type="text" class="form-control" id="president_name" name="president_name"
                           value="<?= e($flash_old['president_name'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="president_email">Email</label>
                    <input type="email" class="form-control" id="president_email" name="president_email"
                           value="<?= e($flash_old['president_email'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="president_password">Password</label>
                    <input type="password" class="form-control" id="president_password"
                           name="president_password" minlength="6" required>
                    <div class="form-text">This account will be created if your application is approved.</div>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-send"></i> Submit Application
                </button>
            </form>

            <div class="text-center mt-3">
                <small class="text-muted">
                    Already registered? <a href="<?= base_url('pages/login.php') ?>">Sign in</a>
                </small>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../components/footer.php'; ?>