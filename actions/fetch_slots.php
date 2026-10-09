<?php
/**
 * actions/fetch_slots.php
 *
 * Returns the schedule (bookings) for a given venue and date.
 * Used by booking_ajax.js to populate the right-side schedule panel.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Authentication required.'], 401);
}

$venueId = $_GET['venue_id'] ?? '';
$date    = $_GET['date']     ?? '';

if (!positive_int($venueId)) {
    json_response(['success' => false, 'message' => 'Invalid venue.'], 400);
}
if (!valid_date($date)) {
    json_response(['success' => false, 'message' => 'Invalid date.'], 400);
}

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT e.id, e.event_date, e.start_time, e.end_time, e.status,
                c.name AS club_name,
                v.name AS venue_name
           FROM events e
           JOIN clubs c ON c.id = e.club_id
           JOIN venues v ON v.id = e.venue_id
          WHERE e.venue_id = :v
            AND e.event_date = :d
            AND e.status IN ('pending','confirmed')
          ORDER BY e.start_time ASC"
    );
    $stmt->execute([':v' => $venueId, ':d' => $date]);

    json_response(['success' => true, 'data' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Server error.'], 500);
}