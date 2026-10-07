<?php
/**
 * components/navbar.php
 *
 * Public, role-aware navigation bar.
 * Home/Dashboard removed from main nav; Dashboard lives inside the
 * user-name dropdown (role-specific URL).
 */
$nav_role = current_user_role();

// UI-friendly role label (DB role stays 'student', but we display 'Member')
$role_label = ($nav_role === 'student') ? 'Member' : ucfirst((string)$nav_role);

// Map role -> dashboard URL (used in the user dropdown)
$dashboard_url = null;
if ($nav_role === 'student')   $dashboard_url = base_url('pages/dashboard_student.php');
if ($nav_role === 'president') $dashboard_url = base_url('pages/dashboard_president.php');
if ($nav_role === 'admin')     $dashboard_url = base_url('pages/dashboard_admin.php');
?>
<nav class="navbar navbar-expand-lg co-navbar">
    <div class="container container-narrow">
        <a class="navbar-brand" href="<?= base_url('pages/index.php') ?>">
            <span class="brand-mark"><i class="bi bi-globe2"></i></span>
            CampusOrbit
        </a>
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= base_url('pages/club_list.php') ?>">Clubs</a>
                </li>
                <?php if ($nav_role === 'president'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= base_url('pages/create_event.php') ?>">Create Event</a>
                    </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <?php if (is_logged_in()): ?>
                    <li class="nav-item dropdown me-lg-2 mb-2 mb-lg-0">
                        <a class="nav-link dropdown-toggle co-user-chip" href="#" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle"></i>
                            <span class="user-text"><?= e(current_user_name()) ?></span>
                            <span class="badge text-bg-light ms-1"><?= e($role_label) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($dashboard_url): ?>
                                <li>
                                    <a class="dropdown-item" href="<?= $dashboard_url ?>">
                                        <i class="bi bi-speedometer2"></i> Dashboard
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($nav_role === 'president'): ?>
                                <li>
                                    <a class="dropdown-item" href="<?= base_url('pages/create_event.php') ?>">
                                        <i class="bi bi-plus-circle"></i> Create Event
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="#"
                                   onclick="document.getElementById('logout-form').submit(); return false;">
                                    <i class="bi bi-box-arrow-right"></i> Logout
                                </a>
                                <form id="logout-form" method="post"
                                      action="<?= base_url('actions/auth_handler.php') ?>" class="d-none">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="logout">
                                </form>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item me-lg-2 mb-2 mb-lg-0">
                        <a class="btn btn-soft btn-sm" href="<?= base_url('pages/login.php') ?>">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary btn-sm" href="<?= base_url('pages/register.php') ?>">Sign Up</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>