<?php
/**
 * pages/dashboard_admin.php
 *
 * Admin dashboard with AJAX-driven approvals:
 * - Stats
 * - Pending club applications (approve/reject — JS updates the row)
 * - Pending venue reservations (approve/reject — JS updates the row)
 * - User & role management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role('admin');

$pdo = get_pdo();

// Auto-reject any pending event whose calendar day has already passed
sweep_late_pending_events();

// Stats
$stats = [
    'users'             => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'clubs'             => (int)$pdo->query("SELECT COUNT(*) FROM clubs")->fetchColumn(),
    'pending_apps'      => (int)$pdo->query("SELECT COUNT(*) FROM club_applications WHERE status='pending'")->fetchColumn(),
    'pending_evt'       => (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='pending'")->fetchColumn(),
    'confirmed_evt'     => (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='confirmed'")->fetchColumn(),
    'not_confirmed_evt' => (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='not_confirmed'")->fetchColumn(),
];

// Pending club applications
$apps = $pdo->query(
    "SELECT * FROM club_applications WHERE status='pending' ORDER BY created_at DESC"
)->fetchAll();

// Pending events
$pendingEvents = $pdo->prepare(
    "SELECT e.*, c.name AS club_name, v.name AS venue_name, u.full_name AS creator_name
       FROM events e
       JOIN clubs  c ON c.id = e.club_id
       JOIN venues v ON v.id = e.venue_id
       JOIN users  u ON u.id = e.created_by
      WHERE e.status = 'pending'
      ORDER BY e.event_date ASC"
);
$pendingEvents->execute();
$pendingEvents = $pendingEvents->fetchAll();

// All users (for role management)
$users = $pdo->query(
    "SELECT id, full_name, email, role, created_at
       FROM users ORDER BY role, full_name"
)->fetchAll();

// All clubs (for management)
$clubs = $pdo->query(
    "SELECT c.id, c.name, c.category, c.president_id,
            u.full_name AS president_name,
            (SELECT COUNT(*) FROM club_members m WHERE m.club_id = c.id) AS member_count,
            (SELECT COUNT(*) FROM events e WHERE e.club_id = c.id) AS event_count
       FROM clubs c
       LEFT JOIN users u ON u.id = c.president_id
      ORDER BY c.name ASC"
)->fetchAll();

// Email of the currently logged-in admin — used to flag demo accounts.
$viewerEmail = '';
$viewerRow = $pdo->prepare("SELECT email FROM users WHERE id = :i");
$viewerRow->execute([':i' => current_user_id()]);
$viewerEmail = (string)($viewerRow->fetchColumn() ?: '');

// The seeded demo admin can manage clubs but cannot delete them.
// "System admin" means any admin NOT matching the demo email.
$isSystemAdmin = ($viewerEmail !== 'admin@campusorbit.com');

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$PAGE_TITLE = 'Admin Dashboard — CampusOrbit';
include __DIR__ . '/../components/header.php';
include __DIR__ . '/../components/navbar.php';
?>
<main class="app-main">
    <div class="container container-narrow py-4">
        <div id="flash-success" class="alert alert-success d-none"></div>
        <div id="flash-error"   class="alert alert-danger d-none"></div>

        <div>
            <h2 class="mb-3"><i class="bi bi-speedometer2"></i> Admin Overview</h2>

                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-tile d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">Users</div>
                                <div class="stat-value"><?= $stats['users'] ?></div>
                            </div>
                            <div class="stat-icon"><i class="bi bi-people"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-tile d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">Clubs</div>
                                <div class="stat-value"><?= $stats['clubs'] ?></div>
                            </div>
                            <div class="stat-icon"><i class="bi bi-collection"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-tile d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label" id="stat-pending-apps">Pending Apps</div>
                                <div class="stat-value text-warning" id="stat-pending-apps-val">
                                    <?= $stats['pending_apps'] ?>
                                </div>
                            </div>
                            <div class="stat-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-inbox"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-tile d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label" id="stat-pending-evt">Pending Events</div>
                                <div class="stat-value text-warning" id="stat-pending-evt-val">
                                    <?= $stats['pending_evt'] ?>
                                </div>
                            </div>
                            <div class="stat-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-hourglass-split"></i></div>
                        </div>
                    </div>
                </div>

                <!-- Club applications -->
                <div class="co-card mb-4">
                    <div class="co-card-header">
                        <span><i class="bi bi-inbox"></i> Pending Club Applications</span>
                        <span class="badge text-bg-light" id="apps-count"><?= count($apps) ?></span>
                    </div>
                    <div class="co-card-body">
                        <?php if (!$isSystemAdmin): ?>
                            <div class="alert alert-soft small mb-3">
                                <i class="bi bi-info-circle"></i>
                                You are signed in as the <strong>demo admin</strong>. Approving or
                                rejecting club applications is restricted to the
                                <strong>system administrator</strong> only.
                            </div>
                        <?php endif; ?>
                        <?php if (!$apps): ?>
                            <div class="state-block" id="apps-empty">No pending applications.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="co-table" id="apps-table">
                                    <thead>
                                        <tr>
                                            <th>Club</th>
                                            <th>President</th>
                                            <th>Category</th>
                                            <th>Submitted</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($apps as $a): ?>
                                        <tr id="app-row-<?= (int)$a['id'] ?>">
                                            <td>
                                                <strong><?= e($a['club_name']) ?></strong><br>
                                                <small class="text-muted"><?= e(mb_substr($a['mission'],0,80)) ?>…</small>
                                            </td>
                                            <td>
                                                <?= e($a['president_name']) ?><br>
                                                <small class="text-muted"><?= e($a['president_email']) ?></small>
                                            </td>
                                            <td><span class="badge text-bg-light"><?= e($a['category']) ?></span></td>
                                            <td><?= e(date('M j, Y', strtotime($a['created_at']))) ?></td>
                                            <td class="text-end">
                                                <?php if ($isSystemAdmin): ?>
                                                    <button type="button" class="btn btn-primary btn-sm"
                                                            data-action="approve_application"
                                                            data-id="<?= (int)$a['id'] ?>"
                                                            data-csrf="<?= e(csrf_token()) ?>">
                                                        <i class="bi bi-check-circle"></i> Approve
                                                    </button>
                                                    <button class="btn btn-soft btn-sm" data-bs-toggle="modal"
                                                            data-bs-target="#rejectApp<?= (int)$a['id'] ?>">
                                                        <i class="bi bi-x-circle"></i> Reject
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                            class="btn btn-soft btn-sm js-crud-disabled"
                                                            title="Only the system admin can approve or reject"
                                                            aria-label="Approve disabled">
                                                        <i class="bi bi-lock"></i> Approve
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-soft btn-sm js-crud-disabled"
                                                            title="Only the system admin can approve or reject"
                                                            aria-label="Reject disabled">
                                                        <i class="bi bi-lock"></i> Reject
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>

                                        <?php if ($isSystemAdmin): ?>
                                        <div class="modal fade" id="rejectApp<?= (int)$a['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Reject Application</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p>Rejecting <strong><?= e($a['club_name']) ?></strong>.</p>
                                                        <label class="form-label">Notes (optional)</label>
                                                        <textarea class="form-control" id="reject-notes-<?= (int)$a['id'] ?>" rows="3"></textarea>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="button" class="btn btn-primary js-reject-app"
                                                                data-id="<?= (int)$a['id'] ?>"
                                                                data-csrf="<?= e(csrf_token()) ?>">
                                                            Reject
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pending events -->
                <div class="co-card mb-4">
                    <div class="co-card-header">
                        <span><i class="bi bi-hourglass-split"></i> Pending Venue Reservations</span>
                        <span class="badge text-bg-light" id="events-count"><?= count($pendingEvents) ?></span>
                    </div>
                    <div class="co-card-body">
                        <?php if (!$isSystemAdmin): ?>
                            <div class="alert alert-soft small mb-3">
                                <i class="bi bi-info-circle"></i>
                                You are signed in as the <strong>demo admin</strong>. Approving or
                                rejecting venue reservations is restricted to the
                                <strong>system administrator</strong> only.
                            </div>
                        <?php endif; ?>
                        <?php if (!$pendingEvents): ?>
                            <div class="state-block" id="events-empty">No pending reservations.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="co-table" id="events-table">
                                    <thead>
                                        <tr>
                                            <th class="text-center">Event</th>
                                            <th>Club</th>
                                            <th>Venue</th>
                                            <th>Date &amp; Time</th>
                                            <th>Featured?</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($pendingEvents as $e): ?>
                                        <tr id="event-row-<?= (int)$e['id'] ?>">
                                            <td class="text-center">
                                                <strong><?= e($e['title']) ?></strong><br>
                                                <small class="text-muted">by <?= e($e['creator_name']) ?></small>
                                            </td>
                                            <td><?= e($e['club_name']) ?></td>
                                            <td><?= e($e['venue_name']) ?></td>
                                            <td>
                                                <?= e(date('M j, Y', strtotime($e['event_date']))) ?><br>
                                                <small><?= e(format_12h($e['start_time'])) ?> – <?= e(format_12h($e['end_time'])) ?></small>
                                            </td>
                                            <td>
                                                <?php if ($e['is_featured']): ?>
                                                    <span class="badge text-bg-warning">★ Yes</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-light">No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($isSystemAdmin): ?>
                                                    <button type="button" class="btn btn-primary btn-sm icon-btn"
                                                            data-action="approve_event"
                                                            data-id="<?= (int)$e['id'] ?>"
                                                            data-csrf="<?= e(csrf_token()) ?>"
                                                            title="Approve event"
                                                            aria-label="Approve event">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-soft btn-sm icon-btn"
                                                            data-action="reject_event"
                                                            data-id="<?= (int)$e['id'] ?>"
                                                            data-csrf="<?= e(csrf_token()) ?>"
                                                            title="Reject event"
                                                            aria-label="Reject event">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                            class="btn btn-soft btn-sm icon-btn"
                                                            title="Only the system admin can approve"
                                                            aria-label="Approve disabled"
                                                            disabled>
                                                        <i class="bi bi-lock"></i>
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-soft btn-sm icon-btn"
                                                            title="Only the system admin can reject"
                                                            aria-label="Reject disabled"
                                                            disabled>
                                                        <i class="bi bi-lock"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Club management -->
                <div class="co-card mb-4">
                    <div class="co-card-header">
                        <span><i class="bi bi-collection"></i> Club Management</span>
                        <span class="badge text-bg-light"><?= count($clubs) ?></span>
                    </div>
                    <div class="co-card-body">
                        <?php if (!$clubs): ?>
                            <div class="state-block">No clubs yet.</div>
                        <?php else: ?>
                            <?php if (!$isSystemAdmin): ?>
                                <div class="alert alert-soft small mb-3">
                                    <i class="bi bi-info-circle"></i>
                                    You are signed in as the <strong>demo admin</strong>. Club deletion is
                                    restricted to the <strong>system administrator</strong> only.
                                </div>
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="co-table">
                                    <thead>
                                        <tr>
                                            <th>Club</th>
                                            <th>Category</th>
                                            <th>President</th>
                                            <th class="text-center">Members</th>
                                            <th class="text-center">Events</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($clubs as $c): ?>
                                        <tr id="club-row-<?= (int)$c['id'] ?>">
                                            <td><strong><?= e($c['name']) ?></strong></td>
                                            <td><span class="badge text-bg-light"><?= e($c['category']) ?></span></td>
                                            <td><?= e($c['president_name'] ?? 'TBA') ?></td>
                                            <td class="text-center"><?= (int)$c['member_count'] ?></td>
                                            <td class="text-center"><?= (int)$c['event_count'] ?></td>
                                            <td class="text-end">
                                                <?php if ($isSystemAdmin): ?>
                                                    <button type="button" class="btn btn-danger btn-sm icon-btn js-delete-club"
                                                            data-id="<?= (int)$c['id'] ?>"
                                                            data-name="<?= e($c['name']) ?>"
                                                            data-csrf="<?= e(csrf_token()) ?>"
                                                            title="Delete club"
                                                            aria-label="Delete club">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-soft btn-sm icon-btn js-delete-club-disabled"
                                                            title="Only the system admin can delete clubs"
                                                            aria-label="Delete disabled"
                                                            disabled>
                                                        <i class="bi bi-lock"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- User management -->
                <div class="co-card">
                    <div class="co-card-header">
                        <span><i class="bi bi-people"></i> User Management</span>
                        <span class="badge text-bg-light"><?= count($users) ?></span>
                    </div>
                    <div class="co-card-body">
                        <?php if (!$isSystemAdmin): ?>
                            <div class="alert alert-soft small mb-3">
                                <i class="bi bi-info-circle"></i>
                                You are signed in as the <strong>demo admin</strong>. Changing user roles
                                is restricted to the <strong>system administrator</strong> only.
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="co-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th class="text-end">Set Role</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><?= e($u['full_name']) ?></td>
                                        <td><?= e($u['email']) ?></td>
                                        <td><span class="badge text-bg-light"><?= e(ucfirst($u['role'])) ?></span></td>
                                        <td class="text-end">
                                            <?php if ((int)$u['id'] === (int)current_user_id()): ?>
                                                <small class="text-muted">(you)</small>
                                            <?php elseif (!$isSystemAdmin): ?>
                                                <button type="button" class="btn btn-soft btn-sm js-save-disabled"
                                                        title="Only the system admin can change roles"
                                                        aria-label="Save disabled">
                                                    Save
                                                </button>
                                                <select class="form-select form-select-sm d-inline-block" style="width:auto" disabled>
                                                    <?php foreach (['student','president','admin'] as $r): ?>
                                                        <option value="<?= $r ?>" <?= $r === $u['role'] ? 'selected' : '' ?>>
                                                            <?= $r === 'student' ? 'Member' : ucfirst($r) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-soft btn-sm js-set-role"
                                                        data-user-id="<?= (int)$u['id'] ?>"
                                                        data-csrf="<?= e(csrf_token()) ?>">
                                                    Save
                                                </button>
                                                <select class="form-select form-select-sm d-inline-block js-role-select" style="width:auto">
                                                    <?php foreach (['student','president','admin'] as $r): ?>
                                                        <option value="<?= $r ?>" <?= $r === $u['role'] ? 'selected' : '' ?>>
                                                            <?= $r === 'student' ? 'Member' : ucfirst($r) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
    </div>
</main>

<!-- Confirm-delete club modal -->
<div class="modal fade" id="deleteClubModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-trash text-danger"></i> Delete Club</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>
                    Permanently delete <strong id="delete-club-name">this club</strong>?
                    All members, events, and RSVPs associated with this club will also be removed.
                </p>
                <p class="text-danger small mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger js-confirm-delete-club"
                        data-id=""
                        data-csrf="<?= e(csrf_token()) ?>">
                    <i class="bi bi-trash"></i> Delete Club
                </button>
            </div>
        </div>
    </div>
</div>

<script>
/* ============================================================
   AJAX admin actions: approve/reject without page reload.
   ============================================================ */
(function () {
    'use strict';

    const csrfFallback = <?= json_encode(csrf_token()) ?>;
    const okBox  = document.getElementById('flash-success');
    const errBox = document.getElementById('flash-error');

    function showOk(msg) {
        if (window.CampusOrbit && CampusOrbit.modal) {
            CampusOrbit.modal({ success: true, message: msg });
        } else if (window.CampusOrbit && CampusOrbit.toast) {
            CampusOrbit.toast({ success: true, message: msg });
        }
    }
    function showErr(msg) {
        if (window.CampusOrbit && CampusOrbit.modal) {
            CampusOrbit.modal({ success: false, message: msg });
        } else if (window.CampusOrbit && CampusOrbit.toast) {
            CampusOrbit.toast({ success: false, message: msg });
        }
    }

    function decBadge(which) {
        const el = document.getElementById('stat-' + which + '-val');
        if (el) el.textContent = Math.max(0, parseInt(el.textContent, 10) - 1);
    }

    async function adminAction(payload) {
        const body = new URLSearchParams(payload).toString();
        try {
            const r = await fetch('/actions/' + payload._handler + '.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': payload.csrf_token
                },
                body: body
            });
            return await r.json();
        } catch (e) {
            return { success: false, message: 'Network error.' };
        }
    }

    /* ---- Approve/Reject buttons (event + club application) ---- */
    document.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const action = btn.dataset.action;
            const id     = btn.dataset.id;
            const csrf   = btn.dataset.csrf || csrfFallback;
            const orig   = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<span class="loading-dots"></span>';

            const handler = action.endsWith('_event') ? 'event_handler' : 'club_handler';
            const json = await adminAction({
                _handler: handler,
                action: action,
                id: id,
                event_id: action.endsWith('_event') ? id : undefined,
                application_id: action.endsWith('_application') ? id : undefined,
                csrf_token: csrf
            });

            if (json.success) {
                showOk(json.message || 'Done.');

                // Remove the row from the table
                const row = document.getElementById(
                    (action.endsWith('_event') ? 'event-row-' : 'app-row-') + id
                );
                if (row) row.remove();

                // Update counters
                if (action.endsWith('_event'))   decBadge('pending-evt');
                if (action.endsWith('_application')) decBadge('pending-apps');

                // Update count badges in card headers
                const evtCount = document.getElementById('events-count');
                if (evtCount) {
                    evtCount.textContent = Math.max(0, parseInt(evtCount.textContent, 10) - 1);
                    if (parseInt(evtCount.textContent, 10) === 0) {
                        const tbl = document.getElementById('events-table');
                        if (tbl) tbl.outerHTML = '<div class="state-block" id="events-empty">No pending reservations.</div>';
                    }
                }
                const appCount = document.getElementById('apps-count');
                if (appCount) {
                    appCount.textContent = Math.max(0, parseInt(appCount.textContent, 10) - 1);
                    if (parseInt(appCount.textContent, 10) === 0) {
                        const tbl = document.getElementById('apps-table');
                        if (tbl) tbl.outerHTML = '<div class="state-block" id="apps-empty">No pending applications.</div>';
                    }
                }

                btn.innerHTML = '✓ Done';
                setTimeout(() => { btn.closest('td').innerHTML = '<span class="text-muted small">processed</span>'; }, 800);
            } else {
                showErr(json.message || 'Could not complete action.');
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    });

    /* ---- Reject Application modal confirm buttons ---- */
    document.querySelectorAll('.js-reject-app').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id   = btn.dataset.id;
            const csrf = btn.dataset.csrf || csrfFallback;
            const notesEl = document.getElementById('reject-notes-' + id);
            const notes   = notesEl ? notesEl.value : '';
            const orig    = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<span class="loading-dots"></span>';

            // Close the modal
            const modalEl = document.getElementById('rejectApp' + id);
            const modal   = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();

            const json = await adminAction({
                _handler: 'club_handler',
                action: 'reject_application',
                application_id: id,
                admin_notes: notes,
                csrf_token: csrf
            });

            if (json.success) {
                showOk(json.message || 'Application rejected.');

                const row = document.getElementById('app-row-' + id);
                if (row) row.remove();

                decBadge('pending-apps');
                const appCount = document.getElementById('apps-count');
                if (appCount) {
                    appCount.textContent = Math.max(0, parseInt(appCount.textContent, 10) - 1);
                    if (parseInt(appCount.textContent, 10) === 0) {
                        const tbl = document.getElementById('apps-table');
                        if (tbl) tbl.outerHTML = '<div class="state-block" id="apps-empty">No pending applications.</div>';
                    }
                }
            } else {
                showErr(json.message || 'Could not reject.');
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    });

    /* ---- Save role button ---- */
    document.querySelectorAll('.js-set-role').forEach(btn => {
        btn.addEventListener('click', async () => {
            const tr    = btn.closest('tr');
            const sel   = tr.querySelector('.js-role-select');
            const newRole = sel.value;
            const userId = btn.dataset.userId;
            const csrf   = btn.dataset.csrf || csrfFallback;
            const orig   = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<span class="loading-dots"></span>';

            const json = await adminAction({
                _handler: 'club_handler',
                action: 'set_role',
                user_id: userId,
                new_role: newRole,
                csrf_token: csrf
            });

            if (json.success) {
                showOk(json.message || 'Role updated.');
                const roleText = newRole === 'student' ? 'Member' : (newRole.charAt(0).toUpperCase() + newRole.slice(1));
                tr.querySelector('td:nth-child(3)').innerHTML =
                    '<span class="badge text-bg-light">' + roleText + '</span>';
            } else {
                showErr(json.message || 'Could not update role.');
            }
            btn.disabled = false;
            btn.innerHTML = orig;
        });
    });

    /* ---- Save role button ---- */
    document.querySelectorAll('.js-save-disabled').forEach(btn => {
        btn.addEventListener('click', () => {
            showErr('Only the system admin can change user roles. The demo admin account is read-only for this action.');
        });
    });

    /* ---- Generic read-only feedback for demo admin ---- */
    document.querySelectorAll('.js-crud-disabled').forEach(btn => {
        btn.addEventListener('click', () => {
            showErr('This action is restricted to the system administrator. The demo admin account is read-only.');
        });
    });

    /* ---- Delete Club (system admin only) ---- */
    document.querySelectorAll('.js-delete-club').forEach(btn => {
        btn.addEventListener('click', () => {
            const id   = btn.dataset.id;
            const name = btn.dataset.name || 'this club';
            const csrf = btn.dataset.csrf || csrfFallback;

            // Populate the modal
            const modalEl = document.getElementById('deleteClubModal');
            const nameEl  = document.getElementById('delete-club-name');
            const confirmBtn = modalEl.querySelector('.js-confirm-delete-club');
            if (nameEl) nameEl.textContent = name;
            if (confirmBtn) {
                confirmBtn.dataset.id = id;
                confirmBtn.dataset.csrf = csrf;
            }

            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        });
    });

    document.querySelectorAll('.js-delete-club-disabled').forEach(btn => {
        btn.addEventListener('click', () => {
            showErr('Only the system admin can delete clubs. The demo admin account is read-only for this action.');
        });
    });

    const confirmDeleteBtn = document.querySelector('.js-confirm-delete-club');
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', async () => {
            const id   = confirmDeleteBtn.dataset.id;
            const csrf = confirmDeleteBtn.dataset.csrf || csrfFallback;
            const orig = confirmDeleteBtn.innerHTML;

            confirmDeleteBtn.disabled = true;
            confirmDeleteBtn.innerHTML = '<span class="loading-dots"></span>';

            // Close modal
            const modalEl = document.getElementById('deleteClubModal');
            const modal   = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();

            const json = await adminAction({
                _handler: 'club_handler',
                action:   'delete_club',
                club_id:  id,
                csrf_token: csrf
            });

            if (json.success) {
                showOk(json.message || 'Club deleted.');
                const row = document.getElementById('club-row-' + id);
                if (row) row.remove();

                // Decrement the Clubs stat tile
                const statEl = document.querySelector('.stat-tile .stat-value');
                // Count stat tiles; the second one is Clubs.
                const statTiles = document.querySelectorAll('.stat-tile');
                if (statTiles[1]) {
                    const v = statTiles[1].querySelector('.stat-value');
                    if (v) v.textContent = Math.max(0, parseInt(v.textContent, 10) - 1);
                }
                // Decrement the badge count
                const headerBadge = document.querySelector('.co-card-header .badge');
            } else {
                showErr(json.message || 'Could not delete club.');
            }

            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.innerHTML = orig;
        });
    }
})();
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>