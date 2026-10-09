<?php
/**
 * actions/fetch_calendar.php
 *
 * Returns a date->status map for the given venue and month
 * so the calendar can color cells dynamically.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Authentication required.'], 401);
}

$venueId = $_GET['venue_id'] ?? '';
$year    = $_GET['year']      ?? '';
$month   = $_GET['month']     ?? '';

if (!positive_int($venueId)) {
    json_response(['success' => false, 'message' => 'Invalid venue.'], 400);
}
if (!ctype_digit((string)$year) || !ctype_digit((string)$month)) {
    json_response(['success' => false, 'message' => 'Invalid period.'], 400);
}
$year  = (int)$year;
$month = (int)$month;
if ($month < 1 || $month > 12) {
    json_response(['success' => false, 'message' => 'Invalid month.'], 400);
}

try {
    $pdo = get_pdo();
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-t', strtotime($start));

    $stmt = $pdo->prepare(
        "SELECT event_date, status FROM events
          WHERE venue_id = :v
            AND event_date BETWEEN :s AND :e
            AND status IN ('pending','confirmed')"
    );
    $stmt->execute([':v' => $venueId, ':s' => $start, ':e' => $end]);

    // Build map: prioritize 'confirmed' over 'pending' if both occur.
    $map = [];
    while ($row = $stmt->fetch()) {
        $d = $row['event_date'];
        if (!isset($map[$d]) || $row['status'] === 'confirmed') {
            $map[$d] = $row['status'];
        }
    }
    json_response(['success' => true, 'data' => $map]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Server error.'], 500);
}