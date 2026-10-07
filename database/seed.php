<?php
/**
 * CampusOrbit seed script.
 * Run this AFTER schema.sql to populate demo users, clubs, events.
 *
 * Usage:
 *   php database/seed.php
 */

require_once __DIR__ . '/../config/db.php';

$pdo = get_pdo();

echo "Seeding CampusOrbit...\n";

// Helper to insert user
function insert_user(PDO $pdo, $name, $email, $password, $role) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, role)
         VALUES (:n, :e, :p, :r)
         ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash
         RETURNING id"
    );
    $stmt->execute([':n' => $name, ':e' => $email, ':p' => $hash, ':r' => $role]);
    return $stmt->fetchColumn();
}

// Users
$adminId = insert_user($pdo, 'System Admin', 'admin@campusorbit.test', 'admin123', 'admin');
echo "Admin id=$adminId\n";

$presRoboId = insert_user($pdo, 'Aisha Khan',     'aisha@campusorbit.test',    'president123', 'president');
$presArtId  = insert_user($pdo, 'Diego Martinez', 'diego@campusorbit.test',    'president123', 'president');
$presCodId  = insert_user($pdo, 'Sofia Nakamura', 'sofia@campusorbit.test',    'president123', 'president');
$presLitId  = insert_user($pdo, 'Marcus Johnson', 'marcus@campusorbit.test',   'president123', 'president');

echo "Presidents created\n";

$stu1 = insert_user($pdo, 'Liam Chen',      'liam@campusorbit.test',    'student123', 'student');
$stu2 = insert_user($pdo, 'Emma Rodriguez', 'emma@campusorbit.test',    'student123', 'student');
$stu3 = insert_user($pdo, 'Noah Patel',     'noah@campusorbit.test',    'student123', 'student');
$stu4 = insert_user($pdo, 'Olivia Smith',   'olivia@campusorbit.test',  'student123', 'student');
$stu5 = insert_user($pdo, 'Wei Zhang',      'wei@campusorbit.test',     'student123', 'student');

echo "Students created\n";

// Clubs (only if not exists)
function insert_club(PDO $pdo, $name, $mission, $category, $president_id) {
    $stmt = $pdo->prepare(
        "INSERT INTO clubs (name, mission, category, president_id)
         VALUES (:n, :m, :c, :p)
         ON CONFLICT (name) DO UPDATE SET mission = EXCLUDED.mission
         RETURNING id"
    );
    $stmt->execute([':n' => $name, ':m' => $mission, ':c' => $category, ':p' => $president_id]);
    return $stmt->fetchColumn();
}

$roboId = insert_club($pdo, 'Robotics & AI Society',
    'Exploring robotics, artificial intelligence, and automation through hands-on projects.',
    'Technology', $presRoboId);
$artId = insert_club($pdo, 'Creative Arts Collective',
    'A community for painters, photographers, and visual storytellers.',
    'Arts', $presArtId);
$codId = insert_club($pdo, 'Competitive Coding Club',
    'Sharpen algorithmic thinking and compete in programming contests.',
    'Technology', $presCodId);
$litId = insert_club($pdo, 'Literary Society',
    'Celebrating literature, debate, poetry, and creative writing.',
    'Academic', $presLitId);

echo "Clubs created\n";

// Club memberships
function add_member(PDO $pdo, $club_id, $user_id) {
    $stmt = $pdo->prepare(
        "INSERT INTO club_members (club_id, user_id) VALUES (:c, :u)
         ON CONFLICT DO NOTHING"
    );
    $stmt->execute([':c' => $club_id, ':u' => $user_id]);
}

add_member($pdo, $roboId, $stu1);
add_member($pdo, $roboId, $stu2);
add_member($pdo, $codId, $stu1);
add_member($pdo, $codId, $stu3);
add_member($pdo, $artId, $stu2);
add_member($pdo, $artId, $stu4);
add_member($pdo, $litId, $stu3);
add_member($pdo, $litId, $stu5);
add_member($pdo, $litId, $stu4);

echo "Memberships created\n";

// Venues: insert if missing, then load map
$venueSeeds = [
    ['Main Auditorium',  500, 'Building A, Ground Floor'],
    ['Seminar Hall 101', 80,  'Building B, First Floor'],
    ['Open Air Theater', 300, 'Central Quad'],
    ['Conference Room',   40, 'Admin Block, Second Floor'],
    ['Sports Complex',    200,'East Campus'],
];
$insVenue = $pdo->prepare(
    "INSERT INTO venues (name, capacity, location)
     VALUES (:n, :c, :l)
     ON CONFLICT DO NOTHING"
);
foreach ($venueSeeds as $v) {
    $insVenue->execute([':n' => $v[0], ':c' => $v[1], ':l' => $v[2]]);
}
$venues = $pdo->query("SELECT id, name FROM venues ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$venueByName = [];
foreach ($venues as $v) { $venueByName[$v['name']] = $v['id']; }

// Helper to add events. We compute future dates relative to today.
function insert_event(PDO $pdo, $club_id, $venue_id, $creator, $title, $desc, $date_offset, $start, $end, $status, $featured = false) {
    $date = (new DateTime('today'))->modify("+$date_offset days")->format('Y-m-d');
    $stmt = $pdo->prepare(
        "INSERT INTO events (club_id, venue_id, title, description, event_date, start_time, end_time, status, is_featured, created_by)
         VALUES (:c, :v, :t, :d, :dt, :s, :e, :st, :f, :cr)
         ON CONFLICT DO NOTHING
         RETURNING id"
    );
    $stmt->execute([
        ':c' => $club_id, ':v' => $venue_id, ':t' => $title, ':d' => $desc,
        ':dt' => $date, ':s' => $start, ':e' => $end, ':st' => $status,
        ':f' => $featured ? 'true' : 'false', ':cr' => $creator
    ]);
    return $stmt->fetchColumn();
}

$ev1 = insert_event($pdo, $roboId, $venueByName['Main Auditorium'], $presRoboId,
    'AI & Robotics Expo 2026',
    'A showcase of student-built robots and AI demos. Live demos and Q&A with research teams.',
    7, '10:00:00', '13:00:00', 'confirmed', true);

$ev2 = insert_event($pdo, $codId, $venueByName['Seminar Hall 101'], $presCodId,
    'ICPC-style Coding Contest',
    'A 3-hour team programming contest with prizes for top teams.',
    10, '14:00:00', '17:00:00', 'confirmed', true);

$ev3 = insert_event($pdo, $artId, $venueByName['Open Air Theater'], $presArtId,
    'Open Air Art Festival',
    'Live painting, photography exhibition, and music performances.',
    14, '16:00:00', '20:00:00', 'pending', false);

$ev4 = insert_event($pdo, $litId, $venueByName['Conference Room'], $presLitId,
    'Poetry Slam Night',
    'Open mic poetry slam featuring student writers and invited guests.',
    5, '19:00:00', '21:30:00', 'confirmed', false);

$ev5 = insert_event($pdo, $roboId, $venueByName['Seminar Hall 101'], $presRoboId,
    'Drone Workshop',
    'Hands-on drone building and flight basics workshop.',
    21, '09:00:00', '12:00:00', 'pending', false);

// One rejected event
$ev6 = insert_event($pdo, $artId, $venueByName['Conference Room'], $presArtId,
    'Rejected Workshop',
    'This event was rejected by admin.',
    30, '10:00:00', '11:00:00', 'rejected', false);

// A past event (should NOT appear in upcoming)
$pastEv = insert_event($pdo, $roboId, $venueByName['Conference Room'], $presRoboId,
    'Past Robotics Talk',
    'Past event for testing.',
    -10, '10:00:00', '11:00:00', 'confirmed', false);

echo "Events created\n";

// Sample attendees (RSVP). Active members attend upcoming events.
function add_attendee(PDO $pdo, $event_id, $user_id) {
    if (!$event_id) return;
    $stmt = $pdo->prepare(
        "INSERT INTO event_attendees (event_id, user_id) VALUES (:e, :u)
         ON CONFLICT DO NOTHING"
    );
    $stmt->execute([':e' => $event_id, ':u' => $user_id]);
}

add_attendee($pdo, $ev1, $stu1);
add_attendee($pdo, $ev1, $stu2);
add_attendee($pdo, $ev2, $stu1);
add_attendee($pdo, $ev2, $stu3);
add_attendee($pdo, $ev4, $stu3);
add_attendee($pdo, $ev4, $stu4);
add_attendee($pdo, $ev4, $stu5);

echo "Attendees added\n";

// Pending club applications
$hash1 = password_hash('president123', PASSWORD_BCRYPT);
$hash2 = password_hash('president123', PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    "INSERT INTO club_applications
       (club_name, mission, category, president_name, president_email, president_password, status)
     VALUES (:n, :m, :c, :pn, :pe, :pp, 'pending')"
);
$stmt->execute([
    ':n' => 'Music Enthusiasts Club',
    ':m' => 'Bringing together student musicians across genres.',
    ':c' => 'Arts',
    ':pn' => 'Priya Singh',
    ':pe' => 'priya@campusorbit.test',
    ':pp' => $hash1
]);
$stmt->execute([
    ':n' => 'Debate Society',
    ':m' => 'Promoting civil discourse and competitive debating.',
    ':c' => 'Academic',
    ':pn' => 'Tariq Hassan',
    ':pe' => 'tariq@campusorbit.test',
    ':pp' => $hash2
]);

echo "Pending club applications added\n";

echo "\n===========================================\n";
echo "Seed complete. Demo credentials:\n";
echo "===========================================\n";
echo "Admin:    admin@campusorbit.test  / admin123\n";
echo "President: aisha@campusorbit.test / president123  (Robotics & AI)\n";
echo "President: diego@campusorbit.test / president123  (Creative Arts)\n";
echo "President: sofia@campusorbit.test / president123  (Competitive Coding)\n";
echo "President: marcus@campusorbit.test / president123  (Literary Society)\n";
echo "Student:  liam@campusorbit.test   / student123\n";
echo "Student:  emma@campusorbit.test   / student123\n";
echo "Student:  noah@campusorbit.test   / student123\n";
echo "===========================================\n";