<?php
/**
 * pages/index.php
 *
 * Public landing page with featured events.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

$pdo = get_pdo();

/**
 * Renders the call-to-action button for an event card consistently across
 * Featured and Upcoming sections, based on viewer role and RSVP state.
 *
 * Possible states:
 *   - Guest (not logged in):   "Sign in to Join"
 *   - Member, not in club:     "Join [Club] to Attend" (links to club page)
 *   - Member, in club:         "Attend" or "Attending" (RSVP toggle)
 *   - President:               "Manage in Dashboard"
 *   - Admin:                   "Review in Dashboard"
 */
function render_event_action(array $ev, ?string $viewerRole, bool $isMember, ?int $viewerId): string {
    if (!$viewerRole) {
        return '<a href="' . base_url('pages/login.php?redirect=pages/index.php') . '" '
             . 'class="btn card-btn card-btn-soft w-100">'
             . '<i class="bi bi-box-arrow-in-right"></i> Sign in to Join</a>';
    }

    if ($viewerRole === 'student') {
        $alreadyAttending = !empty($ev['attending']) && $ev['attending'] !== 'f';
        $inClub           = !empty($ev['is_member']) && $ev['is_member'] !== 'f';

        if ($alreadyAttending) {
            // Member already RSVP'd — show "Attending" with an option to cancel.
            // Green outlined pill so it's instantly recognizable as a positive state.
            return '<button class="btn card-btn card-btn-attending js-cancel-rsvp" '
                 . 'data-id="' . (int)$ev['id'] . '" '
                 . 'data-csrf="' . e(csrf_token()) . '" '
                 . 'onclick="CampusOrbit.cancelRsvp(' . (int)$ev['id'] . ', this)">'
                 . '<i class="bi bi-check2-circle"></i> Attending</button>';
        }

        if (!$inClub) {
            return '<form method="post" action="'.base_url('actions/event_handler.php').'" class="m-0">'
                 . csrf_field()
                 . '<input type="hidden" name="action" value="join_and_rsvp">'
                 . '<input type="hidden" name="club_id" value="'.(int)$ev['club_id'].'">'
                 . '<input type="hidden" name="event_id" value="'.(int)$ev['id'].'">'
                 . '<button type="submit" class="btn card-btn card-btn-soft w-100">'
                 . '<i class="bi bi-person-plus"></i> Join ' . e($ev['club_name']) . ' to Attend</button>'
                 . '</form>';
        }

        // Member of the club, hasn't RSVP'd yet — primary action.
        return '<button class="btn card-btn card-btn-attend js-rsvp" '
             . 'data-id="' . (int)$ev['id'] . '" '
             . 'data-csrf="' . e(csrf_token()) . '" '
             . 'onclick="CampusOrbit.rsvp(' . (int)$ev['id'] . ', this)">'
             . '<i class="bi bi-calendar-plus"></i> Attend</button>';
    }

    if ($viewerRole === 'president') {
        return '<a href="' . base_url('pages/dashboard_president.php') . '" '
             . 'class="btn card-btn card-btn-soft w-100">'
             . '<i class="bi bi-speedometer2"></i> Manage in Dashboard</a>';
    }

    // admin
    return '<a href="' . base_url('pages/dashboard_admin.php') . '" '
         . 'class="btn card-btn card-btn-soft w-100">'
         . '<i class="bi bi-shield-check"></i> Review in Dashboard</a>';
}

// Flash banners (auto-replayed as a centered modal by feedback.js)
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Public featured events: confirmed, is_featured, future
// For logged-in members, include a flag indicating whether they're already attending.
$viewerId   = current_user_id();
$viewerRole = current_user_role();
$isMember   = is_logged_in() && $viewerRole === 'student';

$attendSelect = $isMember
    ? ", EXISTS(SELECT 1 FROM event_attendees a WHERE a.event_id = e.id AND a.user_id = :viewer) AS attending"
    : "";
$memberSelect = $isMember
    ? ", EXISTS(SELECT 1 FROM club_members m WHERE m.club_id = e.club_id AND m.user_id = :viewer) AS is_member"
    : "";

$stmt = $pdo->prepare(
    "SELECT e.id, e.title, e.description, e.event_date, e.start_time, e.end_time,
            c.id AS club_id, c.name AS club_name, v.name AS venue_name
            $attendSelect
            $memberSelect
       FROM events e
       JOIN clubs c   ON c.id = e.club_id
       JOIN venues v  ON v.id = e.venue_id
      WHERE e.is_featured = TRUE
        AND e.status = 'confirmed'
        AND e.event_date >= CURRENT_DATE
      ORDER BY e.event_date ASC
      LIMIT 3"
);
$stmt->execute($isMember ? [':viewer' => $viewerId] : []);
$featured = $stmt->fetchAll();

// Other upcoming events (not featured, but recent)
$stmt = $pdo->prepare(
    "SELECT e.id, e.title, e.description, e.event_date, e.start_time, e.end_time,
            c.id AS club_id, c.name AS club_name, v.name AS venue_name, e.is_featured
            $attendSelect
            $memberSelect
       FROM events e
       JOIN clubs c   ON c.id = e.club_id
       JOIN venues v  ON v.id = e.venue_id
      WHERE e.status = 'confirmed'
        AND e.event_date >= CURRENT_DATE
      ORDER BY e.is_featured DESC, e.event_date ASC
      LIMIT 9"
);
$stmt->execute($isMember ? [':viewer' => $viewerId] : []);
$upcoming = $stmt->fetchAll();

// Stats
$stats = [
    'clubs'   => (int)$pdo->query("SELECT COUNT(*) FROM clubs")->fetchColumn(),
    'events'  => (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='confirmed' AND event_date >= CURRENT_DATE")->fetchColumn(),
    'members' => (int)$pdo->query("SELECT COUNT(*) FROM club_members")->fetchColumn(),
    'students'=> (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn(),
];

$PAGE_TITLE = 'CampusOrbit — Discover Campus Life';
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
        <!-- Stats row -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-tile d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Active Clubs</div>
                        <div class="stat-value"><?= $stats['clubs'] ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-collection"></i></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-tile d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Upcoming Events</div>
                        <div class="stat-value"><?= $stats['events'] ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-calendar-event"></i></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-tile d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Memberships</div>
                        <div class="stat-value"><?= $stats['members'] ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-people"></i></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-tile d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Members</div>
                        <div class="stat-value"><?= $stats['students'] ?></div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-mortarboard"></i></div>
                </div>
            </div>
        </div>

        <!-- Featured events -->
        <h2 class="section-title"><i class="bi bi-star-fill text-warning"></i> Featured Events</h2>
        <?php if (!$featured): ?>
            <div class="state-block">
                <div class="state-icon"><i class="bi bi-calendar-x"></i></div>
                No featured events at the moment. Check back soon!
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <?php foreach ($featured as $ev): ?>
                    <div class="col-md-4">
                        <div class="featured-event p-4 h-100 d-flex flex-column">
                            <div class="fe-title"><?= e($ev['title']) ?></div>
                            <div class="fe-meta">
                                <i class="bi bi-people"></i><?= e($ev['club_name']) ?><br>
                                <i class="bi bi-geo-alt"></i><?= e($ev['venue_name']) ?><br>
                                <i class="bi bi-calendar3"></i><?= e(date('M j, Y', strtotime($ev['event_date']))) ?>
                                &middot; <?= e(substr($ev['start_time'],0,5)) ?>–<?= e(substr($ev['end_time'],0,5)) ?>
                            </div>
                            <div class="fe-desc"><?= e(mb_substr($ev['description'] ?? '', 0, 110)) ?></div>
                            <div class="mt-auto pt-3">
                                <?= render_event_action($ev, $viewerRole, $isMember, $viewerId) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Upcoming events -->
        <h2 class="section-title"><i class="bi bi-calendar-event"></i> Upcoming Events</h2>
        <?php if (!$upcoming): ?>
            <div class="state-block">
                <div class="state-icon"><i class="bi bi-calendar-x"></i></div>
                No upcoming events scheduled.
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($upcoming as $ev): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="event-card">
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
                                    <?= render_event_action($ev, $viewerRole, $isMember, $viewerId) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Join club modal (used by RSVP flow when student is not a member) -->
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

<style>
/* Card CTA buttons — shared by Featured (dark gradient) and Upcoming (white)
   sections. Each variant has a colored border + subtle hover, so they read
   as proper action buttons on either background. */
.card-btn,
.btn.card-btn,
.btn.card-btn-attend,
.btn.card-btn-attending,
.btn.card-btn-soft {
    width: 100%;
    font-weight: 600;
    border-radius: 999px !important;
    padding: .55rem .9rem;
    border: 1.5px solid transparent;
    transition: transform .1s, background .15s, border-color .15s, color .15s;
}
.card-btn:hover { transform: translateY(-1px); }

/* Attend (primary) — blue tinted pill */
.card-btn-attend {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #3b82f6;
}
.card-btn-attend:hover {
    background: #dbeafe;
    border-color: #2563eb;
    color: #1e40af;
}

/* Attending — green tinted pill */
.card-btn-attending {
    background: #ecfdf5;
    color: #047857;
    border-color: #10b981;
}
.card-btn-attending:hover {
    background: #d1fae5;
    border-color: #059669;
    color: #065f46;
}

/* Soft / informational CTA — violet/indigo tinted pill to stand out */
.card-btn-soft {
    background: #f5f3ff;
    color: #6d28d9;
    border-color: #8b5cf6;
}
.card-btn-soft:hover {
    background: #ede9fe;
    border-color: #7c3aed;
    color: #5b21b6;
}

/* On the dark featured gradient, adjust colors for contrast */
.featured-event .card-btn-soft {
    background: transparent;
    color: #ffffff;
    border-color: rgba(255, 255, 255, 0.5);
}
.featured-event .card-btn-soft:hover {
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
    border-color: #ffffff;
}

.featured-event .card-btn-attend {
    background: #ffffff;
    color: var(--color-primary-d);
    border-color: #ffffff;
}
.featured-event .card-btn-attend:hover {
    background: rgba(255, 255, 255, 0.85);
    color: var(--color-primary-d);
    border-color: rgba(255, 255, 255, 0.85);
}

.featured-event .card-btn-attending {
    background: rgba(16, 185, 129, .15);
    color: #ecfdf5;
    border-color: #34d399;
}
.featured-event .card-btn-attending:hover {
    background: rgba(16, 185, 129, .28);
    color: #ffffff;
    border-color: #6ee7b7;
}
</style>

<?php include __DIR__ . '/../components/footer.php'; ?>