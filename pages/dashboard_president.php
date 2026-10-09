<?php
/**
 * pages/dashboard_president.php
 *
 * Club president dashboard: club info, members, events.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role('president');

$pdo = get_pdo();
$userId = current_user_id();

// Auto-reject any pending event whose calendar day has already passed
sweep_late_pending_events();

// Find the club owned by this president
$stmt = $pdo->prepare("SELECT * FROM clubs WHERE president_id = :u");
$stmt->execute([':u' => $userId]);
$club = $stmt->fetch();

if (!$club) {
    // President without club — show empty state.
    $PAGE_TITLE = 'President Dashboard — CampusOrbit';
    include __DIR__ . '/../components/header.php';
    include __DIR__ . '/../components/navbar.php';
    ?>
    <main class="app-main"><div class="container container-narrow py-4">
        <div class="alert alert-warning">
            You are not yet linked to a club. Please contact an administrator.
        </div>
    </div></main>
    <?php
    include __DIR__ . '/../components/footer.php';
    exit;
}

$clubId = (int)$club['id'];

// Members
$stmt = $pdo->prepare(
    "SELECT u.id, u.full_name, u.email, m.joined_at
       FROM club_members m
       JOIN users u ON u.id = m.user_id
      WHERE m.club_id = :c
      ORDER BY m.joined_at DESC"
);
$stmt->execute([':c' => $clubId]);
$members = $stmt->fetchAll();

// Events for this club
// Sort: chronological by event_date ASC (first-upcoming first), but
// push status='not_confirmed' (auto-rejected because event day passed)
// to the very bottom. Within each group, sort by start_time.
$stmt = $pdo->prepare(
    "SELECT e.*, v.name AS venue_name,
            (SELECT COUNT(*) FROM event_attendees a WHERE a.event_id = e.id) AS attendee_count
       FROM events e
       JOIN venues v ON v.id = e.venue_id
      WHERE e.club_id = :c
      ORDER BY (CASE WHEN e.status = 'not_confirmed' THEN 1 ELSE 0 END) ASC,
               e.event_date ASC,
               e.start_time ASC"
);
$stmt->execute([':c' => $clubId]);
$events = $stmt->fetchAll();

// Event stats
$eventStats = [
    'total'         => count($events),
    'pending'       => count(array_filter($events, fn($e) => $e['status'] === 'pending')),
    'confirmed'     => count(array_filter($events, fn($e) => $e['status'] === 'confirmed')),
    'rejected'      => count(array_filter($events, fn($e) => $e['status'] === 'rejected')),
    'not_confirmed' => count(array_filter($events, fn($e) => $e['status'] === 'not_confirmed')),
];

// Venues (for the Edit Event modal)
$venues = $pdo->query("SELECT id, name, capacity FROM venues ORDER BY name")->fetchAll();

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$PAGE_TITLE = $club['name'] . ' — Dashboard';
include __DIR__ . '/../components/header.php';
include __DIR__ . '/../components/navbar.php';
?>
<main class="app-main">
    <div class="container container-narrow py-4">
        <?php if ($flash_success): ?>
            <div class="alert alert-success"><?= e($flash_success) ?></div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="alert alert-danger"><?= e($flash_error) ?></div>
        <?php endif; ?>

        <div>
            <div class="co-card mb-4">
                <div class="co-card-header">
                    <span><i class="bi bi-collection"></i> <?= e($club['name']) ?></span>
                    <span class="badge text-bg-light"><?= e($club['category']) ?></span>
                </div>
                <div class="co-card-body">
                    <p class="text-muted mb-0"><?= e($club['mission']) ?></p>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-tile d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Total Events</div>
                            <div class="stat-value"><?= $eventStats['total'] ?></div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-calendar-event"></i></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-tile d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Pending Events</div>
                            <div class="stat-value text-warning"><?= $eventStats['pending'] ?></div>
                        </div>
                        <div class="stat-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-hourglass-split"></i></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-tile d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Confirmed Events</div>
                            <div class="stat-value text-success"><?= $eventStats['confirmed'] ?></div>
                        </div>
                        <div class="stat-icon" style="background:#d1fae5;color:#065f46;"><i class="bi bi-check-circle"></i></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-tile d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Members</div>
                            <div class="stat-value"><?= count($members) ?></div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-people"></i></div>
                    </div>
                </div>
            </div>

            <div class="co-card mb-4">
                <div class="co-card-header">
                    <span><i class="bi bi-calendar-event"></i> Events</span>
                    <a href="<?= base_url('pages/create_event.php') ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg"></i> Create Event
                    </a>
                </div>
                <div class="co-card-body">
                    <?php if (!$events): ?>
                        <div class="state-block">
                            <div class="state-icon"><i class="bi bi-calendar-x"></i></div>
                            No events yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="co-table" id="president-events-table">
                                <thead>
                                    <tr>
                                        <th class="text-center">Title</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Venue</th>
                                        <th>RSVPs</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($events as $ev): ?>
                                    <tr id="event-row-<?= (int)$ev['id'] ?>">
                                        <td class="text-center">
                                            <strong><?= e($ev['title']) ?></strong>
                                            <?php if ($ev['is_featured']): ?>
                                                <span class="badge text-bg-warning ms-1">★ Featured</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e(date('M j, Y', strtotime($ev['event_date']))) ?></td>
                                        <td><?= e(format_12h($ev['start_time'])) ?> – <?= e(format_12h($ev['end_time'])) ?></td>
                                        <td><?= e($ev['venue_name']) ?></td>
                                        <td><?= (int)$ev['attendee_count'] ?></td>
                                        <td><span class="status-badge <?= e($ev['status']) ?>"><?= e($ev['status']) ?></span></td>
                                        <td class="text-end">
                                            <?php if (in_array($ev['status'], ['pending','confirmed'], true)): ?>
                                                <button type="button" class="btn btn-soft btn-sm js-edit-event icon-btn"
                                                        data-id="<?= (int)$ev['id'] ?>"
                                                        data-csrf="<?= e(csrf_token()) ?>"
                                                        title="Edit event"
                                                        aria-label="Edit event">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($ev['status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-soft btn-sm js-cancel-event icon-btn"
                                                            data-id="<?= (int)$ev['id'] ?>"
                                                            data-csrf="<?= e(csrf_token()) ?>"
                                                            title="Cancel pending event"
                                                            aria-label="Cancel event">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-danger btn-sm js-delete-event icon-btn"
                                                        data-id="<?= (int)$ev['id'] ?>"
                                                        data-csrf="<?= e(csrf_token()) ?>"
                                                        title="Delete event"
                                                        aria-label="Delete event">
                                                    <i class="bi bi-trash"></i>
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

            <div class="co-card">
                <div class="co-card-header">
                    <span><i class="bi bi-people"></i> Members (<?= count($members) ?>)</span>
                </div>
                <div class="co-card-body">
                    <?php if (!$members): ?>
                        <div class="state-block">No members yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="co-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($members as $m): ?>
                                    <tr>
                                        <td><?= e($m['full_name']) ?></td>
                                        <td><?= e($m['email']) ?></td>
                                        <td><?= e(date('M j, Y', strtotime($m['joined_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit event modal (populated by JS) -->
    <div class="modal fade" id="editEventModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Event</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="edit-event-error" class="alert alert-danger d-none"></div>
                    <form id="edit-event-form" class="js-ajax-form">
                        <input type="hidden" name="action" value="edit_event">
                        <input type="hidden" name="event_id" id="edit-event-id">
                        <div class="mb-3">
                            <label class="form-label" for="edit-title">Title</label>
                            <input type="text" class="form-control" id="edit-title" name="title" minlength="3" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="edit-description">Description</label>
                            <textarea class="form-control" id="edit-description" name="description" rows="3"></textarea>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="edit-venue">Venue</label>
                                <select class="form-select" id="edit-venue" name="venue_id" required>
                                    <?php foreach ($venues ?? [] as $v): ?>
                                        <option value="<?= (int)$v['id'] ?>">
                                            <?= e($v['name']) ?> (cap. <?= (int)$v['capacity'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="edit-date">Date</label>
                                <input type="date" class="form-control" id="edit-date" name="event_date" min="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="edit-start">Start</label>
                                <input type="time" class="form-control" id="edit-start" name="start_time" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="edit-end">End</label>
                                <input type="time" class="form-control" id="edit-end" name="end_time" required>
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="edit-featured" name="is_featured" value="1">
                            <label class="form-check-label" for="edit-featured">Featured</label>
                        </div>
                        <div class="small text-muted">
                            <i class="bi bi-info-circle"></i> Changing the venue, date, or time will reset status to <strong>pending</strong> and require admin re-approval.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="js-save-edit-event">
                        <i class="bi bi-check"></i> Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
/* ============================================================
   President event actions: edit / delete / cancel via AJAX,
   with the same modal/toast feedback as the admin dashboard.
   ============================================================ */
(function () {
    'use strict';

    const csrfFallback = <?= json_encode(csrf_token()) ?>;

    // Serialize the events data so the modal can populate fields
    const eventsData = <?php
        $jsEvents = [];
        foreach ($events as $ev) {
            $jsEvents[(int)$ev['id']] = [
                'id'          => (int)$ev['id'],
                'title'       => $ev['title'],
                'description' => $ev['description'],
                'venue_id'    => (int)$ev['venue_id'],
                'event_date'  => $ev['event_date'],
                'start_time'  => $ev['start_time'],
                'end_time'    => $ev['end_time'],
                'is_featured' => (int)$ev['is_featured'],
                'status'      => $ev['status'],
            ];
        }
        echo json_encode($jsEvents, JSON_HEX_TAG | JSON_HEX_AMP);
    ?>;

    function showOk(msg) {
        if (window.CampusOrbit && CampusOrbit.modal) CampusOrbit.modal({ success: true, message: msg });
        else if (window.CampusOrbit && CampusOrbit.toast) CampusOrbit.toast({ success: true, message: msg });
    }
    function showErr(msg) {
        if (window.CampusOrbit && CampusOrbit.modal) CampusOrbit.modal({ success: false, message: msg });
        else if (window.CampusOrbit && CampusOrbit.toast) CampusOrbit.toast({ success: false, message: msg });
    }

    async function postAction(payload) {
        try {
            const r = await fetch('/actions/event_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': payload.csrf_token || csrfFallback
                },
                body: new URLSearchParams(payload).toString()
            });
            return await r.json();
        } catch (e) {
            return { success: false, message: 'Network error.' };
        }
    }

    /* ---- Edit button: populate modal, open it ---- */
    document.querySelectorAll('.js-edit-event').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const ev = eventsData[id];
            if (!ev) return;
            document.getElementById('edit-event-id').value     = ev.id;
            document.getElementById('edit-title').value        = ev.title;
            document.getElementById('edit-description').value  = ev.description || '';
            document.getElementById('edit-venue').value        = ev.venue_id;
            document.getElementById('edit-date').value         = ev.event_date;
            document.getElementById('edit-start').value        = ev.start_time.slice(0,5);
            document.getElementById('edit-end').value          = ev.end_time.slice(0,5);
            document.getElementById('edit-featured').checked   = ev.is_featured === 1;
            document.getElementById('edit-event-error').classList.add('d-none');
            const m = new bootstrap.Modal(document.getElementById('editEventModal'));
            m.show();
        });
    });

    /* ---- Save (modal submit) ---- */
    const saveBtn = document.getElementById('js-save-edit-event');
    if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
            const form  = document.getElementById('edit-event-form');
            const errEl = document.getElementById('edit-event-error');
            errEl.classList.add('d-none');

            // Client validation
            const s = document.getElementById('edit-start').value;
            const t = document.getElementById('edit-end').value;
            if (s && t && s >= t) {
                errEl.textContent = 'End time must be after start time.';
                errEl.classList.remove('d-none');
                return;
            }

            saveBtn.disabled = true;
            const orig = saveBtn.innerHTML;
            saveBtn.innerHTML = '<span class="loading-dots"></span>';

            const payload = {
                action: 'edit_event',
                event_id: document.getElementById('edit-event-id').value,
                title: document.getElementById('edit-title').value,
                description: document.getElementById('edit-description').value,
                venue_id: document.getElementById('edit-venue').value,
                event_date: document.getElementById('edit-date').value,
                start_time: document.getElementById('edit-start').value,
                end_time: document.getElementById('edit-end').value,
                is_featured: document.getElementById('edit-featured').checked ? '1' : '',
                csrf_token: csrfFallback
            };

            const json = await postAction(payload);
            saveBtn.disabled = false;
            saveBtn.innerHTML = orig;

            if (json.success) {
                // Close modal
                const m = bootstrap.Modal.getInstance(document.getElementById('editEventModal'));
                if (m) m.hide();
                showOk(json.message || 'Event updated.');
                // Reload so all fields / statuses / counters reflect the change
                setTimeout(() => window.location.reload(), 800);
            } else {
                errEl.textContent = json.message || 'Could not update event.';
                errEl.classList.remove('d-none');
            }
        });
    }

    /* ---- Cancel button ---- */
    document.querySelectorAll('.js-cancel-event').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Cancel this pending event?')) return;
            const id = btn.dataset.id;
            const csrf = btn.dataset.csrf || csrfFallback;
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading-dots"></span>';
            const json = await postAction({
                action: 'cancel_event', event_id: id, csrf_token: csrf
            });
            if (json.success) {
                showOk(json.message || 'Event cancelled.');
                const row = document.getElementById('event-row-' + id);
                if (row) row.remove();
            } else {
                showErr(json.message || 'Could not cancel event.');
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    });

    /* ---- Delete button ---- */
    document.querySelectorAll('.js-delete-event').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Delete this event permanently?')) return;
            const id = btn.dataset.id;
            const csrf = btn.dataset.csrf || csrfFallback;
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading-dots"></span>';
            const json = await postAction({
                action: 'delete_event', event_id: id, csrf_token: csrf
            });
            if (json.success) {
                showOk(json.message || 'Event deleted.');
                const row = document.getElementById('event-row-' + id);
                if (row) row.remove();
            } else {
                showErr(json.message || 'Could not delete event.');
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    });
})();
</script>
<?php include __DIR__ . '/../components/footer.php'; ?>