<?php
/**
 * pages/dashboard_student.php
 *
 * Personalized dashboard for students.
 * - Shows upcoming events hosted by clubs where the student is an active member.
 * - Sorted ascending by date/time.
 * - Excludes past events.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

require_role('student');

$pdo = get_pdo();
$userId = current_user_id();

// My clubs
$stmt = $pdo->prepare(
    "SELECT c.id, c.name, c.category
       FROM clubs c
       JOIN club_members m ON m.club_id = c.id
      WHERE m.user_id = :u
      ORDER BY c.name"
);
$stmt->execute([':u' => $userId]);
$myClubs = $stmt->fetchAll();

// Upcoming events from clubs I'm a member of
$stmt = $pdo->prepare(
    "SELECT e.id, e.title, e.description, e.event_date, e.start_time, e.end_time,
            e.status, e.is_featured,
            c.name AS club_name, c.id AS club_id,
            v.name AS venue_name,
            (SELECT 1 FROM event_attendees a WHERE a.event_id = e.id AND a.user_id = :u) AS rsvped
       FROM events e
       JOIN clubs c        ON c.id = e.club_id
       JOIN club_members m ON m.club_id = c.id
       JOIN venues v       ON v.id = e.venue_id
      WHERE m.user_id = :u
        AND e.status = 'confirmed'
        AND e.event_date >= CURRENT_DATE
      ORDER BY e.event_date ASC, e.start_time ASC
      LIMIT 25"
);
$stmt->execute([':u' => $userId]);
$upcoming = $stmt->fetchAll();

// Recommended (from other clubs)
$stmt = $pdo->prepare(
    "SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, e.description,
            c.name AS club_name, c.category, c.id AS club_id,
            v.name AS venue_name
       FROM events e
       JOIN clubs c ON c.id = e.club_id
       JOIN venues v ON v.id = e.venue_id
      WHERE e.status = 'confirmed'
        AND e.event_date >= CURRENT_DATE
        AND e.club_id NOT IN (SELECT club_id FROM club_members WHERE user_id = :u)
      ORDER BY e.event_date ASC
      LIMIT 6"
);
$stmt->execute([':u' => $userId]);
$recommended = $stmt->fetchAll();

$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$PAGE_TITLE = 'My Dashboard — CampusOrbit';
include __DIR__ . '/../components/header.php';
include __DIR__ . '/../components/navbar.php';
?>
<main class="app-main">
    <div class="container container-narrow py-4">
        <?php if ($flash_success): ?>
            <div class="alert alert-success"><?= e($flash_success) ?></div>
        <?php endif; ?>

        <div>
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <h2 class="m-0"><i class="bi bi-house-door"></i> Hi, <?= e(current_user_name()) ?></h2>
            </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="stat-tile d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">My Clubs</div>
                                <div class="stat-value"><?= count($myClubs) ?></div>
                            </div>
                            <div class="stat-icon"><i class="bi bi-collection"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-tile d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">Upcoming Events</div>
                                <div class="stat-value"><?= count($upcoming) ?></div>
                            </div>
                            <div class="stat-icon"><i class="bi bi-calendar-event"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-tile d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-label">Attending</div>
                                <div class="stat-value">
                                    <?= count(array_filter($upcoming, fn($e) => $e['rsvped'])) ?>
                                </div>
                            </div>
                            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
                        </div>
                    </div>
                </div>

                <div class="co-card mb-4">
                    <div class="co-card-header">
                        <span><i class="bi bi-calendar-event"></i> Upcoming Events From My Clubs</span>
                        <a href="<?= base_url('pages/club_list.php') ?>" class="btn btn-soft btn-sm">
                            <i class="bi bi-plus"></i> Join more clubs
                        </a>
                    </div>
                    <div class="co-card-body">
                        <?php if (!$upcoming): ?>
                            <div class="state-block">
                                <div class="state-icon"><i class="bi bi-calendar-x"></i></div>
                                No upcoming events from your clubs.
                                <div class="mt-3">
                                    <a href="<?= base_url('pages/club_list.php') ?>" class="btn btn-primary btn-sm">
                                        Explore Clubs
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="co-table">
                                    <thead>
                                        <tr>
                                            <th>Event</th>
                                            <th>Club</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Venue</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($upcoming as $ev): ?>
                                        <tr>
                                            <td>
                                                <strong><?= e($ev['title']) ?></strong>
                                                <?php if ($ev['is_featured']): ?>
                                                    <span class="badge text-bg-warning ms-1">★</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e($ev['club_name']) ?></td>
                                            <td><?= e(date('M j, Y', strtotime($ev['event_date']))) ?></td>
                                            <td><?= e(substr($ev['start_time'],0,5)) ?>–<?= e(substr($ev['end_time'],0,5)) ?></td>
                                            <td><?= e($ev['venue_name']) ?></td>
                                            <td class="text-end">
                                                <?php if ($ev['rsvped']): ?>
                                                    <form method="post" action="<?= base_url('actions/event_handler.php') ?>" class="d-inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="cancel_rsvp">
                                                        <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                                                        <button class="btn btn-soft btn-sm">
                                                            <i class="bi bi-check2-circle"></i> Attending
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button class="btn btn-primary btn-sm"
                                                            data-csrf="<?= e(csrf_token()) ?>"
                                                            onclick="CampusOrbit.rsvp(<?= (int)$ev['id'] ?>, this)">
                                                        <i class="bi bi-calendar-plus"></i> Attend
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
                        <span><i class="bi bi-stars"></i> Recommended Events</span>
                    </div>
                    <div class="co-card-body">
                        <?php if (!$recommended): ?>
                            <div class="state-block">No recommendations yet.</div>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($recommended as $ev): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="event-card h-100">
                                            <div class="event-card-top">
                                                <div style="font-size:.8rem; opacity:.85;">
                                                    <i class="bi bi-calendar3"></i> <?= e(date('M j, Y', strtotime($ev['event_date']))) ?>
                                                </div>
                                                <div style="font-size:.8rem; opacity:.85; margin-top:.25rem;">
                                                    <i class="bi bi-clock"></i>
                                                    <?= e(substr($ev['start_time'],0,5)) ?>–<?= e(substr($ev['end_time'],0,5)) ?>
                                                </div>
                                            </div>
                                            <div class="event-card-body">
                                                <div class="event-title"><?= e($ev['title']) ?></div>
                                                <div class="event-meta">
                                                    <i class="bi bi-people"></i><?= e($ev['club_name']) ?>
                                                    <br><i class="bi bi-geo-alt"></i><?= e($ev['venue_name']) ?>
                                                </div>
                                                <div class="event-desc"><?= e(mb_substr($ev['description'] ?? '', 0, 120)) ?></div>
                                                <div class="mt-auto pt-2">
                                                    <form method="post" action="<?= base_url('actions/event_handler.php') ?>" class="m-0">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="join_and_rsvp">
                                                        <input type="hidden" name="club_id" value="<?= (int)$ev['club_id'] ?>">
                                                        <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                                                        <button class="btn btn-outline-primary btn-sm w-100 rounded-pill fw-medium">
                                                            <i class="bi bi-person-plus"></i> Join to Attend
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
        </div>
    </div>

    <!-- Join club modal -->
    <div class="modal fade" id="joinClubModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Join Club to Attend</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>You must join <strong id="join-club-name">this club</strong> first to attend this event.</p>
                    <button id="join-club-btn"
                            class="btn btn-primary w-100"
                            data-csrf="<?= e(csrf_token()) ?>"
                            onclick="CampusOrbit.joinClub(this)">
                        <i class="bi bi-person-plus"></i> Join Club
                    </button>
                </div>
            </div>
        </div>
    </div>

</main>
<?php include __DIR__ . '/../components/footer.php'; ?>