<?php
/**
 * pages/club_details.php
 *
 * Detailed view of a single club.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

$pdo = get_pdo();

$club_id = (int)($_GET['id'] ?? 0);
if (!$club_id) {
    header("Location: " . base_url('pages/club_list.php'));
    exit;
}

// Fetch club details and president
$stmt = $pdo->prepare("
    SELECT c.*, u.full_name AS president_name, u.email AS president_email
    FROM clubs c
    LEFT JOIN users u ON u.id = c.president_id
    WHERE c.id = :id
");
$stmt->execute([':id' => $club_id]);
$club = $stmt->fetch();

if (!$club) {
    die("Club not found.");
}

// Check membership status for the current user
$is_member = false;
$viewerRole = current_user_role();
$viewerId = current_user_id();
if (is_logged_in() && $viewerRole === 'student') {
    $mstmt = $pdo->prepare("SELECT 1 FROM club_members WHERE club_id = ? AND user_id = ?");
    $mstmt->execute([$club_id, $viewerId]);
    $is_member = (bool)$mstmt->fetch();
}

// Fetch members
$mstmt = $pdo->prepare("
    SELECT u.full_name 
    FROM users u 
    JOIN club_members cm ON cm.user_id = u.id 
    WHERE cm.club_id = :cid
    ORDER BY u.full_name ASC
");
$mstmt->execute([':cid' => $club_id]);
$members = $mstmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch upcoming events
$attendSelect = (is_logged_in() && $viewerRole === 'student')
    ? ", EXISTS(SELECT 1 FROM event_attendees a WHERE a.event_id = e.id AND a.user_id = :viewer) AS attending"
    : "";

$estmt = $pdo->prepare("
    SELECT e.*, v.name as venue_name $attendSelect
    FROM events e
    JOIN venues v ON v.id = e.venue_id
    WHERE e.club_id = :cid AND e.event_date >= CURRENT_DATE AND e.status = 'confirmed'
    ORDER BY e.event_date ASC, e.start_time ASC
");
$params = [':cid' => $club_id];
if ($attendSelect) $params[':viewer'] = $viewerId;
$estmt->execute($params);
$upcoming_events = $estmt->fetchAll();

// Fetch past events
$pstmt = $pdo->prepare("
    SELECT e.*, v.name as venue_name
    FROM events e
    JOIN venues v ON v.id = e.venue_id
    WHERE e.club_id = :cid AND e.event_date < CURRENT_DATE AND e.status = 'confirmed'
    ORDER BY e.event_date DESC, e.start_time DESC
");
$pstmt->execute([':cid' => $club_id]);
$past_events = $pstmt->fetchAll();

$PAGE_TITLE = e($club['name']) . ' — CampusOrbit';
include __DIR__ . '/../components/header.php';
include __DIR__ . '/../components/navbar.php';
?>
<main class="app-main">
    <div class="container container-narrow py-4">
        <div class="row flex-md-row-reverse">
            <!-- Right Sidebar (formerly left) -->
            <div class="col-md-4">
                <div class="co-card mb-4">
                    <div class="co-card-body text-center">
                        <h3 class="mb-1"><?= e($club['name']) ?></h3>
                        <span class="badge text-bg-light mb-3"><?= e($club['category']) ?></span>
                        
                        <div class="mb-3">
                            <?php if ($viewerRole === 'student'): ?>
                                <?php if ($is_member): ?>
                                    <form method="post" action="<?= base_url('actions/club_handler.php') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="leave_club">
                                        <input type="hidden" name="club_id" value="<?= $club_id ?>">
                                        <button class="btn btn-outline-danger w-100">
                                            <i class="bi bi-box-arrow-right"></i> Leave Club
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= base_url('actions/club_handler.php') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="join_club">
                                        <input type="hidden" name="club_id" value="<?= $club_id ?>">
                                        <button class="btn btn-primary w-100">
                                            <i class="bi bi-person-plus"></i> Join Club
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($viewerRole === 'president' && (int)$club['president_id'] === (int)$viewerId): ?>
                                <a class="btn btn-soft w-100" href="<?= base_url('pages/dashboard_president.php') ?>">
                                    <i class="bi bi-gear"></i> Manage Club
                                </a>
                            <?php elseif (!is_logged_in()): ?>
                                <a class="btn btn-soft w-100" href="<?= base_url('pages/login.php?redirect=pages/club_details.php?id=' . $club_id) ?>">
                                    Login to join
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="co-card mb-4">
                    <div class="co-card-body">
                        <h5>President</h5>
                        <p class="mb-0"><i class="bi bi-person-badge"></i> <?= e($club['president_name'] ?? 'TBA') ?></p>
                        <?php if (!empty($club['president_email'])): ?>
                            <small class="text-muted"><i class="bi bi-envelope"></i> <?= e($club['president_email']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="co-card mb-4">
                    <div class="co-card-body">
                        <h5>Members (<?= count($members) ?>)</h5>
                        <?php if ($members): ?>
                            <ul class="list-unstyled mb-0" style="max-height: 200px; overflow-y: auto;">
                                <?php foreach ($members as $m): ?>
                                    <li><i class="bi bi-person"></i> <?= e($m) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted small mb-0">No members yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Right Content -->
            <div class="col-md-8">
                <div class="co-card mb-4">
                    <div class="co-card-body">
                        <h5>About Us</h5>
                        <p class="mb-0" style="white-space: pre-wrap;"><?= e($club['mission']) ?></p>
                    </div>
                </div>

                <h4 class="mb-3">Upcoming Events</h4>
                <?php if (!$upcoming_events): ?>
                    <div class="state-block mb-4">
                        <div class="state-icon"><i class="bi bi-calendar-x"></i></div>
                        No upcoming events scheduled.
                    </div>
                <?php else: ?>
                    <div class="row g-3 mb-4">
                        <?php foreach ($upcoming_events as $e): ?>
                            <div class="col-12">
                                <div class="co-card h-100 border-start border-4 border-primary">
                                    <div class="co-card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="mb-1"><?= e($e['title']) ?></h5>
                                                <div class="text-muted small mb-2">
                                                    <i class="bi bi-calendar"></i> <?= date('F j, Y', strtotime($e['event_date'])) ?>
                                                    &middot;
                                                    <i class="bi bi-clock"></i> <?= date('g:i A', strtotime($e['start_time'])) ?> - <?= date('g:i A', strtotime($e['end_time'])) ?>
                                                    &middot;
                                                    <i class="bi bi-geo-alt"></i> <?= e($e['venue_name']) ?>
                                                </div>
                                            </div>
                                            <div>
                                                <?php if ($viewerRole === 'student'): ?>
                                                    <?php if ($is_member): ?>
                                                        <?php if (!empty($e['attending'])): ?>
                                                            <button class="btn btn-sm btn-outline-success js-cancel-rsvp" 
                                                                    data-id="<?= $e['id'] ?>" data-csrf="<?= e(csrf_token()) ?>" 
                                                                    onclick="CampusOrbit.cancelRsvp(<?= $e['id'] ?>, this)">
                                                                <i class="bi bi-check-circle"></i> Already Joined
                                                            </button>
                                                        <?php else: ?>
                                                            <button class="btn btn-sm btn-primary js-rsvp" 
                                                                    data-id="<?= $e['id'] ?>" data-csrf="<?= e(csrf_token()) ?>" 
                                                                    onclick="CampusOrbit.rsvp(<?= $e['id'] ?>, this)">
                                                                <i class="bi bi-calendar-plus"></i> Attend Event
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-light text-muted border px-2 py-1"><i class="bi bi-info-circle"></i> Join club to attend</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($e['description']): ?>
                                            <p class="small text-muted mt-2 mb-0"><?= e($e['description']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h4 class="mb-3">Past Events</h4>
                <?php if (!$past_events): ?>
                    <div class="state-block">
                        <div class="state-icon"><i class="bi bi-clock-history"></i></div>
                        No past events.
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($past_events as $e): ?>
                            <div class="col-12">
                                <div class="co-card h-100 bg-light border-0">
                                    <div class="co-card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1 text-muted"><?= e($e['title']) ?></h6>
                                                <div class="text-muted small mb-0">
                                                    <i class="bi bi-calendar"></i> <?= date('F j, Y', strtotime($e['event_date'])) ?>
                                                </div>
                                            </div>
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
</main>
<?php include __DIR__ . '/../components/footer.php'; ?>
