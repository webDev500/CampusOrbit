<?php
/**
 * components/sidebar.php
 *
 * Role-aware dashboard sidebar.
 * Expected to be rendered inside a .dashboard-wrap container.
 */

$role = current_user_role() ?? '';
$current = basename($_SERVER['SCRIPT_NAME']);
?>
<aside class="sidebar">
    <?php if ($role === 'student'): ?>
        <div class="sidebar-title">Member</div>
        <a class="sidebar-link <?= $current === 'dashboard_student.php' ? 'active' : '' ?>"
           href="<?= base_url('pages/dashboard_student.php') ?>">
            <i class="bi bi-house"></i> My Dashboard
        </a>
        <a class="sidebar-link <?= $current === 'club_list.php' ? 'active' : '' ?>"
           href="<?= base_url('pages/club_list.php') ?>">
            <i class="bi bi-search"></i> Discover Clubs
        </a>
        <a class="sidebar-link" href="<?= base_url('pages/index.php') ?>">
            <i class="bi bi-calendar-event"></i> Public Events
        </a>
    <?php elseif ($role === 'president'): ?>
        <div class="sidebar-title">Club President</div>
        <a class="sidebar-link <?= $current === 'dashboard_president.php' ? 'active' : '' ?>"
           href="<?= base_url('pages/dashboard_president.php') ?>">
            <i class="bi bi-house"></i> My Club
        </a>
        <a class="sidebar-link <?= $current === 'create_event.php' ? 'active' : '' ?>"
           href="<?= base_url('pages/create_event.php') ?>">
            <i class="bi bi-plus-circle"></i> Create Event
        </a>
        <a class="sidebar-link" href="<?= base_url('pages/club_list.php') ?>">
            <i class="bi bi-people"></i> Browse Clubs
        </a>
    <?php elseif ($role === 'admin'): ?>
        <div class="sidebar-title">Administration</div>
        <a class="sidebar-link <?= $current === 'dashboard_admin.php' ? 'active' : '' ?>"
           href="<?= base_url('pages/dashboard_admin.php') ?>">
            <i class="bi bi-speedometer2"></i> Overview
        </a>
        <a class="sidebar-link" href="<?= base_url('pages/club_list.php') ?>">
            <i class="bi bi-collection"></i> All Clubs
        </a>
    <?php endif; ?>
</aside>