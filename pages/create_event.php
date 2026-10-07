<?php
/**
 * pages/create_event.php
 *
 * President-only page for creating events with venue booking.
 * - Left panel: form fields
 * - Interactive calendar (right)
 * - AJAX slot inspection (booking_ajax.js)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role('president');

$pdo = get_pdo();
$userId = current_user_id();

// Verify president has a club
$stmt = $pdo->prepare("SELECT id, name FROM clubs WHERE president_id = :u");
$stmt->execute([':u' => $userId]);
$club = $stmt->fetch();
if (!$club) {
    $PAGE_TITLE = 'Create Event — CampusOrbit';
    include __DIR__ . '/../components/header.php';
    include __DIR__ . '/../components/navbar.php';
    echo '<main class="app-main"><div class="container container-narrow py-4">
            <div class="alert alert-warning">You are not yet linked to a club.</div>
          </div></main>';
    include __DIR__ . '/../components/footer.php';
    exit;
}

// Venues
$venues = $pdo->query("SELECT id, name, capacity, location FROM venues ORDER BY name")->fetchAll();

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$PAGE_TITLE = 'Create Event — CampusOrbit';
include __DIR__ . '/../components/header.php';
include __DIR__ . '/../components/navbar.php';
?>
<main class="app-main">
    <div class="container container-narrow py-4">
        <h2 class="section-title">
            <i class="bi bi-plus-circle"></i> Create Event for <?= e($club['name']) ?>
        </h2>

        <?php if ($flash_success): ?>
            <div class="alert alert-success"><?= e($flash_success) ?></div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="alert alert-danger"><?= e($flash_error) ?></div>
        <?php endif; ?>

        <div class="row g-3">
            <!-- LEFT: form -->
            <div class="col-lg-7">
                <div class="form-section">
                    <form method="post" action="<?= base_url('actions/event_handler.php') ?>" id="create-event-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_event">

                        <div class="mb-3">
                            <label class="form-label" for="title">Event Title *</label>
                            <input type="text" class="form-control" id="title" name="title"
                                   minlength="3" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="venue_id">Venue *</label>
                                <select class="form-select" id="venue_id" name="venue_id" required>
                                    <option value="">Select a venue…</option>
                                    <?php foreach ($venues as $v): ?>
                                        <option value="<?= (int)$v['id'] ?>">
                                            <?= e($v['name']) ?> (cap. <?= (int)$v['capacity'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="event_date">Date *</label>
                                <input type="date" class="form-control" id="event_date" name="event_date"
                                       min="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="start_time">Start Time *</label>
                                <input type="time" class="form-control" id="start_time" name="start_time" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="end_time">End Time *</label>
                                <input type="time" class="form-control" id="end_time" name="end_time" required>
                            </div>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" value="1">
                            <label class="form-check-label" for="is_featured">
                                Request Featured Event
                                <small class="text-muted d-block">
                                    The administrator will decide whether to display this event on the public homepage.
                                </small>
                            </label>
                        </div>

                        <div id="client-error" class="alert alert-danger d-none"></div>
                        <div id="client-success" class="alert alert-success d-none"></div>

                        <button type="submit" class="btn btn-primary w-100" id="submit-btn">
                            <i class="bi bi-send"></i> Submit Event for Approval
                        </button>
                    </form>
                </div>
            </div>

            <!-- RIGHT: calendar + schedule -->
            <div class="col-lg-5">
                <div class="calendar-wrap mb-3">
                    <div class="calendar-header">
                        <button class="btn btn-soft btn-sm" id="prev-month" type="button">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <div class="calendar-title" id="calendar-month-label">
                            <?= e(date('F Y')) ?>
                        </div>
                        <button class="btn btn-soft btn-sm" id="next-month" type="button">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                    <div id="calendar"><!-- filled by JS --></div>
                    <div class="calendar-legend">
                        <div class="legend-item"><span class="legend-swatch" style="background:#10B981"></span> Available</div>
                        <div class="legend-item"><span class="legend-swatch" style="background:#F59E0B"></span> Pending</div>
                        <div class="legend-item"><span class="legend-swatch" style="background:#EF4444"></span> Confirmed</div>
                    </div>
                    <p class="small text-muted mt-2 mb-0">
                        Click a date to inspect that day's venue schedule.
                    </p>
                </div>

                <div class="card">
                    <div class="co-card-header">
                        <span><i class="bi bi-clock-history"></i> Schedule for Selected Date</span>
                    </div>
                    <div class="co-card-body">
                        <div id="schedule-panel">
                            <div class="state-block">
                                <div class="state-icon"><i class="bi bi-calendar3"></i></div>
                                Select a venue and a date on the calendar.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// AJAX form submission so the page does not reload.
document.getElementById('create-event-form').addEventListener('submit', function (e) {
    e.preventDefault();

    const form    = this;
    const errBox  = document.getElementById('client-error');
    const okBox   = document.getElementById('client-success');
    const btn     = document.getElementById('submit-btn');

    // Suppress the auto-ack so we don't get a noisy "Processing…" stack
    // alongside the real response feedback.
    if (window.CampusOrbit && CampusOrbit.setSilentClick) {
        CampusOrbit.setSilentClick(btn);
    }

    errBox.classList.add('d-none');
    okBox.classList.add('d-none');

    // Client-side time validation
    const s = document.getElementById('start_time').value;
    const t = document.getElementById('end_time').value;
    if (s && t && s >= t) {
        errBox.textContent = 'End time must be after start time.';
        errBox.classList.remove('d-none');
        if (window.CampusOrbit && CampusOrbit.toast) {
            CampusOrbit.toast({ success: false, message: 'End time must be after start time.' });
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Submit Event for Approval';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="loading-dots"></span> Submitting';

    const payload = new URLSearchParams(new FormData(form));

    fetch('/actions/event_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: payload
    })
    .then(r => r.json().catch(() => ({ success: false, message: 'Invalid server response.' })))
    .then(json => {
        if (json.success) {
            form.reset();

            // Show ONE confirmation modal with the success message and
            // auto-redirect to the president dashboard after 3s.
            if (window.CampusOrbit && CampusOrbit.modal) {
                CampusOrbit.modal({
                    success: true,
                    message: json.message || 'Event submitted! It is now waiting for admin approval.'
                });
            }
            // Auto-redirect after 3s
            setTimeout(() => {
                window.location.href = '/pages/dashboard_president.php';
            }, 3000);
        } else {
            errBox.textContent = json.message || 'Could not submit event.';
            errBox.classList.remove('d-none');
            if (window.CampusOrbit && CampusOrbit.toast) {
                CampusOrbit.toast({ success: false, message: json.message || 'Could not submit event.' });
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send"></i> Submit Event for Approval';
        }
    })
    .catch(() => {
        if (window.CampusOrbit && CampusOrbit.toast) {
            CampusOrbit.toast({ success: false, message: 'Network error. Please try again.' });
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Submit Event for Approval';
    });
});
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>