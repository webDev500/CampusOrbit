<?php
/**
 * actions/event_handler.php
 *
 * Event-related operations:
 *   - President: create_event (with conflict detection & transaction)
 *   - President: cancel their own pending event
 *   - Admin:    approve_event, reject_event
 *   - Student:  rsvp (with membership verification)
 *
 * Endpoints that can be triggered from regular browser forms
 * (admin approve/reject, president cancel) emit a redirect + flash.
 * Endpoints triggered from the calendar / RSVP widgets emit JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

if (!is_logged_in()) {
    if (is_ajax_request()) {
        json_response(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    $_SESSION['flash_error'] = 'Please log in.';
    header('Location: /pages/login.php');
    exit;
}

csrf_require();

$action = $_POST['action'] ?? '';
$pdo    = get_pdo();
$role   = current_user_role();
$userId = current_user_id();

function is_ajax_request(): bool {
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
}

/**
 * JSON-or-redirect helper.
 * Used by admin / president actions triggered from regular form posts
 * (so the browser is redirected back with a flash message).
 */
function form_respond(array $payload, string $redirect = '/pages/dashboard_admin.php'): void {
    if (is_ajax_request()) {
        $status = !empty($payload['success']) ? 200 : 400;
        json_response($payload, $status);
    }
    if (!empty($payload['success'])) {
        $_SESSION['flash_success'] = $payload['message'] ?? 'Done.';
    } else {
        $_SESSION['flash_error']   = $payload['message'] ?? 'Action failed.';
    }
    header('Location: ' . $redirect);
    exit;
}

/* ============================================================
 *  President: create_event  (always JSON — used by AJAX form)
 * ============================================================ */
if ($action === 'create_event') {
    header('Content-Type: application/json; charset=utf-8');

    if ($role !== 'president') {
        json_response(['success' => false, 'message' => 'Only Club Presidents can create events.'], 403);
    }

    $title       = trim($_POST['title']        ?? '');
    $description = trim($_POST['description']  ?? '');
    $venueId     = $_POST['venue_id']         ?? '';
    $eventDate   = $_POST['event_date']       ?? '';
    $startTime   = $_POST['start_time']       ?? '';
    $endTime     = $_POST['end_time']         ?? '';
    $isFeatured  = !empty($_POST['is_featured']) ? 1 : 0;

    // Validate
    if ($title === '' || mb_strlen($title) < 3) {
        json_response(['success' => false, 'message' => 'Please provide an event title.'], 400);
    }
    if (!positive_int($venueId)) {
        json_response(['success' => false, 'message' => 'Invalid venue.'], 400);
    }
    if (!valid_date($eventDate)) {
        json_response(['success' => false, 'message' => 'Invalid date.'], 400);
    }
    if (!valid_time($startTime) || !valid_time($endTime)) {
        json_response(['success' => false, 'message' => 'Invalid start or end time.'], 400);
    }
    if ($startTime >= $endTime) {
        json_response(['success' => false, 'message' => 'End time must be after start time.'], 400);
    }
    if (strtotime($eventDate) < strtotime(date('Y-m-d'))) {
        json_response(['success' => false, 'message' => 'Event date cannot be in the past.'], 400);
    }

    // Find club owned by this president
    $stmt = $pdo->prepare("SELECT id FROM clubs WHERE president_id = :u");
    $stmt->execute([':u' => $userId]);
    $clubId = $stmt->fetchColumn();
    if (!$clubId) {
        json_response(['success' => false, 'message' => 'You are not assigned to a club.'], 403);
    }

    // Verify venue exists
    $stmt = $pdo->prepare("SELECT id FROM venues WHERE id = :v");
    $stmt->execute([':v' => $venueId]);
    if (!$stmt->fetchColumn()) {
        json_response(['success' => false, 'message' => 'Venue not found.'], 400);
    }

    // Transactional conflict detection
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM events
              WHERE venue_id = :v
                AND event_date = :d
                AND status IN ('pending','confirmed')
                AND (
                      (start_time <= :s AND end_time > :s)
                   OR (start_time < :e AND end_time >= :e)
                   OR (start_time >= :s AND end_time <= :e)
                )"
        );
        $stmt->execute([
            ':v' => $venueId, ':d' => $eventDate,
            ':s' => $startTime, ':e' => $endTime,
        ]);
        $count = (int)$stmt->fetchColumn();

        if ($count > 0) {
            $pdo->rollBack();
            json_response([
                'success' => false,
                'message' => 'This time slot is already booked or pending approval. Please select a different time.'
            ], 409);
        }

        $ins = $pdo->prepare(
            "INSERT INTO events
                (club_id, venue_id, title, description, event_date,
                 start_time, end_time, status, is_featured, created_by)
             VALUES (:c, :v, :t, :d, :dt, :s, :e, 'pending', :f, :u)"
        );
        $ins->execute([
            ':c' => $clubId, ':v' => $venueId, ':t' => $title,
            ':d' => $description, ':dt' => $eventDate,
            ':s' => $startTime, ':e' => $endTime,
            ':f' => $isFeatured, ':u' => $userId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['success' => false, 'message' => 'Could not create event.'], 500);
    }

    json_response(['success' => true, 'message' => 'Event submitted! It is now pending admin approval.']);
}

/* ============================================================
 *  Admin: approve_event / reject_event   (form-post → redirect)
 * ============================================================ */
if ($action === 'approve_event' || $action === 'reject_event') {
    if ($role !== 'admin') {
        form_respond(['success' => false, 'message' => 'Admin only.']);
    }
    $eventId = $_POST['event_id'] ?? '';
    if (!positive_int($eventId)) {
        form_respond(['success' => false, 'message' => 'Invalid event.']);
    }
    $newStatus = $action === 'approve_event' ? 'confirmed' : 'rejected';
    $stmt = $pdo->prepare("UPDATE events SET status = :s WHERE id = :i");
    $stmt->execute([':s' => $newStatus, ':i' => $eventId]);
    form_respond([
        'success' => true,
        'message' => 'Event ' . ($newStatus === 'confirmed' ? 'approved and confirmed' : 'rejected') . '.'
    ]);
}

/* ============================================================
 *  President: cancel_event (their own pending event)
 * ============================================================ */
if ($action === 'cancel_event') {
    if ($role !== 'president') {
        form_respond(
            ['success' => false, 'message' => 'Forbidden.'],
            '/pages/dashboard_president.php'
        );
    }
    $eventId = $_POST['event_id'] ?? '';
    if (!positive_int($eventId)) {
        form_respond(
            ['success' => false, 'message' => 'Invalid event.'],
            '/pages/dashboard_president.php'
        );
    }
    $stmt = $pdo->prepare(
        "SELECT id FROM events WHERE id = :i AND created_by = :u AND status = 'pending'"
    );
    $stmt->execute([':i' => $eventId, ':u' => $userId]);
    if (!$stmt->fetchColumn()) {
        form_respond(
            ['success' => false, 'message' => 'Cannot cancel this event.'],
            '/pages/dashboard_president.php'
        );
    }
    $pdo->prepare("DELETE FROM events WHERE id = :i")
        ->execute([':i' => $eventId]);
    form_respond(
        ['success' => true, 'message' => 'Event cancelled.'],
        '/pages/dashboard_president.php'
    );
}

/* ============================================================
 *  President: edit_event (own club, pending or confirmed)
 *  - If event was confirmed and venue/time/date changes,
 *    status flips back to 'pending' so admin re-approves.
 * ============================================================ */
if ($action === 'edit_event') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_require();

    if ($role !== 'president') {
        json_response(['success' => false, 'message' => 'Only presidents can edit events.'], 403);
    }

    $eventId   = $_POST['event_id']   ?? '';
    $title     = trim($_POST['title']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $venueId   = $_POST['venue_id']          ?? '';
    $eventDate = $_POST['event_date']        ?? '';
    $startTime = $_POST['start_time']        ?? '';
    $endTime   = $_POST['end_time']          ?? '';
    $isFeatured = !empty($_POST['is_featured']) ? 1 : 0;

    if (!positive_int($eventId)) {
        json_response(['success' => false, 'message' => 'Invalid event.'], 400);
    }

    // Ownership check
    $stmt = $pdo->prepare(
        "SELECT id, club_id, status, venue_id, event_date, start_time, end_time
           FROM events WHERE id = :i AND created_by = :u"
    );
    $stmt->execute([':i' => $eventId, ':u' => $userId]);
    $ev = $stmt->fetch();
    if (!$ev) {
        json_response(['success' => false, 'message' => 'Event not found.'], 404);
    }

    // Field validation
    if ($title === '' || mb_strlen($title) < 3) {
        json_response(['success' => false, 'message' => 'Title is required.'], 400);
    }
    if (!positive_int($venueId)) {
        json_response(['success' => false, 'message' => 'Invalid venue.'], 400);
    }
    if (!valid_date($eventDate)) {
        json_response(['success' => false, 'message' => 'Invalid date.'], 400);
    }
    if (!valid_time($startTime) || !valid_time($endTime)) {
        json_response(['success' => false, 'message' => 'Invalid start or end time.'], 400);
    }
    if ($startTime >= $endTime) {
        json_response(['success' => false, 'message' => 'End time must be after start time.'], 400);
    }
    if (strtotime($eventDate) < strtotime(date('Y-m-d'))) {
        json_response(['success' => false, 'message' => 'Event date cannot be in the past.'], 400);
    }

    // Detect if venue/date/time changed -> requires re-approval
    // Normalize HH:MM:SS -> HH:MM so the comparison ignores second precision.
    $dbStart = substr((string)$ev['start_time'], 0, 5);
    $dbEnd   = substr((string)$ev['end_time'],   0, 5);
    $venueChanged = ((int)$venueId   !== (int)$ev['venue_id']);
    $dateChanged  = ($eventDate      !== $ev['event_date']);
    $timeChanged  = ($startTime !== $dbStart) || ($endTime !== $dbEnd);
    $requiresReapproval = $venueChanged || $dateChanged || $timeChanged;

    // If re-approval needed and venue/time conflicts with another
    // pending/confirmed booking (excluding this event), reject.
    if ($requiresReapproval) {
        try {
            $pdo->beginTransaction();

            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM events
                  WHERE venue_id = :v
                    AND event_date = :d
                    AND id <> :i
                    AND status IN ('pending','confirmed')
                    AND (
                          (start_time <= :s AND end_time > :s)
                       OR (start_time < :e AND end_time >= :e)
                       OR (start_time >= :s AND end_time <= :e)
                    )"
            );
            $check->execute([
                ':v' => $venueId, ':d' => $eventDate,
                ':i' => $eventId,
                ':s' => $startTime, ':e' => $endTime,
            ]);
            if ((int)$check->fetchColumn() > 0) {
                $pdo->rollBack();
                json_response([
                    'success' => false,
                    'message' => 'This time slot is already booked or pending approval.'
                ], 409);
            }

            $newStatus = ($ev['status'] === 'confirmed' && $requiresReapproval)
                ? 'pending'
                : $ev['status'];

            $upd = $pdo->prepare(
                "UPDATE events SET
                    title = :t,
                    description = :d,
                    venue_id = :v,
                    event_date = :dt,
                    start_time = :s,
                    end_time = :e,
                    is_featured = :f,
                    status = :st
                  WHERE id = :i"
            );
            $upd->execute([
                ':t' => $title, ':d' => $description,
                ':v' => $venueId, ':dt' => $eventDate,
                ':s' => $startTime, ':e' => $endTime,
                ':f' => $isFeatured, ':st' => $newStatus, ':i' => $eventId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Could not update event.'], 500);
        }

        json_response([
            'success' => true,
            'message' => $newStatus === 'pending'
                ? 'Event updated. Venue/time changed — re-submitted for admin approval.'
                : 'Event updated.',
            'new_status' => $newStatus
        ]);
    }

    // No venue/time change -> simple field update (status preserved)
    $newStatus = $ev['status'];
    $upd = $pdo->prepare(
        "UPDATE events SET
            title = :t, description = :d,
            is_featured = :f
          WHERE id = :i"
    );
    $upd->execute([
        ':t' => $title, ':d' => $description,
        ':f' => $isFeatured, ':i' => $eventId,
    ]);

    json_response([
        'success' => true,
        'message' => 'Event updated.',
        'new_status' => $newStatus
    ]);
}

/* ============================================================
 *  President: delete_event (own club, pending or confirmed)
 * ============================================================ */
if ($action === 'delete_event') {
    if ($role !== 'president') {
        form_respond(
            ['success' => false, 'message' => 'Forbidden.'],
            '/pages/dashboard_president.php'
        );
    }
    $eventId = $_POST['event_id'] ?? '';
    if (!positive_int($eventId)) {
        form_respond(
            ['success' => false, 'message' => 'Invalid event.'],
            '/pages/dashboard_president.php'
        );
    }
    $stmt = $pdo->prepare(
        "SELECT id FROM events WHERE id = :i AND created_by = :u"
    );
    $stmt->execute([':i' => $eventId, ':u' => $userId]);
    if (!$stmt->fetchColumn()) {
        form_respond(
            ['success' => false, 'message' => 'Cannot delete this event.'],
            '/pages/dashboard_president.php'
        );
    }
    // event_attendees rows cascade via FK ON DELETE CASCADE
    $pdo->prepare("DELETE FROM events WHERE id = :i")
        ->execute([':i' => $eventId]);
    form_respond(
        ['success' => true, 'message' => 'Event deleted.'],
        '/pages/dashboard_president.php'
    );
}

/* ============================================================
 *  Student: rsvp   (always — used from AJAX)
 * ============================================================ */
if ($action === 'rsvp') {
    header('Content-Type: application/json; charset=utf-8');

    if ($role !== 'student') {
        json_response(['success' => false, 'message' => 'Only members can RSVP.'], 403);
    }
    $eventId = $_POST['event_id'] ?? '';
    if (!positive_int($eventId)) {
        json_response(['success' => false, 'message' => 'Invalid event.'], 400);
    }

    // Verify event and host club
    $stmt = $pdo->prepare(
        "SELECT e.id, e.club_id, e.status, c.name AS club_name
           FROM events e JOIN clubs c ON c.id = e.club_id
          WHERE e.id = :i"
    );
    $stmt->execute([':i' => $eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        json_response(['success' => false, 'message' => 'Event not found.'], 404);
    }
    if ($event['status'] !== 'confirmed') {
        json_response(['success' => false, 'message' => 'You can only RSVP to confirmed events.'], 400);
    }

    // Check membership
    $stmt = $pdo->prepare(
        "SELECT 1 FROM club_members WHERE club_id = :c AND user_id = :u"
    );
    $stmt->execute([':c' => $event['club_id'], ':u' => $userId]);
    if (!$stmt->fetchColumn()) {
        json_response([
            'success'     => false,
            'require_join'=> true,
            'club_id'     => (int)$event['club_id'],
            'club_name'   => $event['club_name'],
            'message'     => 'You must join ' . $event['club_name'] . ' first to attend this event.'
        ], 403);
    }

    // Check duplicate
    $stmt = $pdo->prepare(
        "SELECT 1 FROM event_attendees WHERE event_id = :e AND user_id = :u"
    );
    $stmt->execute([':e' => $eventId, ':u' => $userId]);
    if ($stmt->fetchColumn()) {
        json_response(['success' => false, 'message' => 'You have already RSVP\'d.'], 409);
    }

    // Insert
    try {
        $pdo->prepare(
            "INSERT INTO event_attendees (event_id, user_id) VALUES (:e, :u)"
        )->execute([':e' => $eventId, ':u' => $userId]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Could not RSVP.'], 500);
    }

    json_response(['success' => true, 'message' => 'You are now attending!']);
}

/* ============================================================
 *  Student: cancel_rsvp
 * ============================================================ */
if ($action === 'cancel_rsvp') {
    header('Content-Type: application/json; charset=utf-8');

    if ($role !== 'student') {
        json_response(['success' => false, 'message' => 'Forbidden.'], 403);
    }
    $eventId = $_POST['event_id'] ?? '';
    if (!positive_int($eventId)) {
        json_response(['success' => false, 'message' => 'Invalid event.'], 400);
    }
    $pdo->prepare(
        "DELETE FROM event_attendees WHERE event_id = :e AND user_id = :u"
    )->execute([':e' => $eventId, ':u' => $userId]);
    json_response(['success' => true, 'message' => 'RSVP cancelled.']);
}

/* ============================================================
 *  Student: join_and_rsvp (from home page)
 * ============================================================ */
if ($action === 'join_and_rsvp') {
    if ($role !== 'student') {
        form_respond(['success' => false, 'message' => 'Forbidden.'], '/pages/index.php');
    }
    $eventId = (int)($_POST['event_id'] ?? 0);
    $clubId  = (int)($_POST['club_id'] ?? 0);
    
    if ($eventId > 0 && $clubId > 0) {
        // Join club if not already a member
        $stmt = $pdo->prepare("SELECT 1 FROM club_members WHERE club_id = :c AND user_id = :u");
        $stmt->execute([':c' => $clubId, ':u' => $userId]);
        if (!$stmt->fetchColumn()) {
            $pdo->prepare("INSERT INTO club_members (club_id, user_id) VALUES (:c, :u)")
                ->execute([':c' => $clubId, ':u' => $userId]);
        }
        
        // Attend event if not already attending
        $stmt = $pdo->prepare("SELECT 1 FROM event_attendees WHERE event_id = :e AND user_id = :u");
        $stmt->execute([':e' => $eventId, ':u' => $userId]);
        if (!$stmt->fetchColumn()) {
            $pdo->prepare("INSERT INTO event_attendees (event_id, user_id) VALUES (:e, :u)")
                ->execute([':e' => $eventId, ':u' => $userId]);
        }
    }
    
    form_respond(
        ['success' => true, 'message' => 'Successfully joined the club and RSVP\'d to the event!'],
        '/pages/club_details.php?id=' . $clubId
    );
}

/* ============================================================
 *  Fallback
 * ============================================================ */
if (is_ajax_request()) {
    json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}
$_SESSION['flash_error'] = 'Unknown action.';
header('Location: /pages/dashboard_admin.php');
exit;