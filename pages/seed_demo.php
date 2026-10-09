<?php
/**
 * pages/seed_demo.php
 *
 * One-shot demo seeder driven by /Users/mahmudul/Desktop/WebAppLab/CampusOrbit/club_and_member.md
 *
 * Wipes clubs / members / events / demo users and inserts:
 *   - 7 clubs (Programming, Career, Science, Robotics, Debate, Music, Culture)
 *   - 7 presidents (@campusorbit.com)
 *   - 12 students (@campusorbit.com) with Mahmudul as the demo member
 *   - Mahmudul + a few others distributed as members across clubs
 *   - 12 events (1-2 per club), all confirmed, 4 marked featured
 *   - admin user
 *
 * Visit once: /pages/seed_demo.php
 * Safe to re-run — deletes demo data first.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = get_pdo();

// Guard against accidental public execution: only allow when ?confirm=1 is present
if (($_GET['confirm'] ?? '') !== '1') {
    echo '<h1>Demo Seeder</h1>';
    echo '<p>This page wipes clubs, members, events, and demo users, then re-seeds the database from <code>club_and_member.md</code>.</p>';
    echo '<p><strong>Add <code>?confirm=1</code> to run it.</strong></p>';
    echo '<p><a href="?confirm=1" style="color:#1d4ed8;">Run seeder now →</a></p>';
    exit;
}

@set_time_limit(120);

function out(string $msg): void {
    echo '<div>' . htmlspecialchars($msg) . '</div>';
    @ob_flush();
    @flush();
}

out('Starting demo seed…');

try {
    $pdo->beginTransaction();

    /* --------------------------------------------------------
     * 1. Wipe existing demo data
     * -------------------------------------------------------- */
    out('Wiping existing demo data…');
    $pdo->exec("DELETE FROM event_attendees");
    $pdo->exec("DELETE FROM events");
    $pdo->exec("DELETE FROM club_members");
    // clubs reference users (president_id) — clear first
    $pdo->exec("UPDATE clubs SET president_id = NULL");
    $pdo->exec("DELETE FROM clubs");
    // delete demo users (admin, president, and student-role users)
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@campusorbit.com'");

    /* --------------------------------------------------------
     * 2. Ensure at least one venue exists (idempotent)
     * -------------------------------------------------------- */
    out('Ensuring venues exist…');
    $venueCount = (int)$pdo->query("SELECT COUNT(*) FROM venues")->fetchColumn();
    if ($venueCount === 0) {
        $pdo->exec("INSERT INTO venues (name, capacity, location) VALUES
            ('Main Auditorium',  500, 'Building A, Ground Floor'),
            ('Seminar Hall 101', 80,  'Building B, First Floor'),
            ('Open Air Theater', 300, 'Central Quad'),
            ('Conference Room',   40, 'Admin Block, Second Floor'),
            ('Sports Complex',    200,'East Campus')");
    }

    /* --------------------------------------------------------
     * 3. Insert admins (demo admin + system admin)
     * -------------------------------------------------------- */
    out('Creating admins…');
    $adminPwd     = password_hash('admin123',   PASSWORD_BCRYPT);
    $systemPwd    = password_hash('system1234', PASSWORD_BCRYPT);

    $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, role)
         VALUES ('Admin', 'admin@campusorbit.com', :p, 'admin')"
    )->execute([':p' => $adminPwd]);

    $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, role)
         VALUES ('System Admin', 'systemadmin@campusorbit.com', :p, 'admin')"
    )->execute([':p' => $systemPwd]);

    /* --------------------------------------------------------
     * 4. Source data from club_and_member.md
     * -------------------------------------------------------- */
    $clubsData = [
        ['name' => 'Programming Club', 'category' => 'Programming',
         'mission' => 'A community for students interested in programming, problem-solving, and software development. Members can improve their coding skills through practice sessions, workshops, contests, and collaborative projects.'],
        ['name' => 'Career Club',      'category' => 'Career',
         'mission' => 'A platform for students to develop career-ready skills through workshops, seminars, networking opportunities, CV building, interview preparation, and professional development activities.'],
        ['name' => 'Science Club',     'category' => 'Science',
         'mission' => 'A community for students passionate about science, research, and innovation. Members can explore scientific topics, participate in discussions, organize exhibitions, and engage in experiments and research-oriented activities.'],
        ['name' => 'Robotics Club',    'category' => 'Robotics',
         'mission' => 'A hands-on community focused on robotics, automation, electronics, and emerging technologies. Members can learn by building projects, participating in competitions, conducting workshops, and collaborating on innovative robotic solutions.'],
        ['name' => 'Debating Club',    'category' => 'Debate',
         'mission' => 'A platform for students to develop public speaking, critical thinking, argumentation, and communication skills through debates, discussions, competitions, and constructive exchanges of ideas on diverse topics.'],
        ['name' => 'Music Club',       'category' => 'Music',
         'mission' => 'A creative community for students interested in singing, instrumental music, songwriting, and musical performance. Members can participate in rehearsals, workshops, cultural programs, and collaborative performances.'],
        ['name' => 'Cultural Club',    'category' => 'Culture',
         'mission' => 'A vibrant community that celebrates creativity, heritage, traditions, and cultural diversity through events, performances, exhibitions, and various cultural activities that encourage student participation and collaboration.'],
    ];

    // President first names (one per club)
    $presidents = [
        'Programming' => ['first' => 'Jarif',  'email' => 'jarif@campusorbit.com'],
        'Career'      => ['first' => 'Rup',    'email' => 'rup@campusorbit.com'],
        'Science'     => ['first' => 'Samiul', 'email' => 'samiul@campusorbit.com'],
        'Robotics'    => ['first' => 'Nadim',  'email' => 'nadim@campusorbit.com'],
        'Debate'      => ['first' => 'Sahed',  'email' => 'sahed@campusorbit.com'],
        'Music'       => ['first' => 'Alamin', 'email' => 'alamin@campusorbit.com'],
        'Culture'     => ['first' => 'Shuvon', 'email' => 'shuvon@campusorbit.com'],
    ];

    // Member distribution: Mahmudul is the demo member, others spread across clubs
    $memberRoster = [
        'Mahmudul', 'Rakib', 'Mahim', 'Ruf', 'Shishir',
        'Mahfuj', 'Arefin', 'Faysal', 'Joy', 'Siyam',
        'Naiem', 'Tajul',
    ];

    // Which members (by first name) belong to which club (by category key)
    $membershipMap = [
        'Programming' => ['Mahmudul', 'Rakib', 'Mahim', 'Ruf'],
        'Career'      => ['Mahmudul', 'Shishir', 'Mahfuj'],
        'Science'     => ['Mahmudul', 'Arefin', 'Faysal'],
        'Robotics'    => ['Mahmudul', 'Joy', 'Siyam'],
        'Debate'      => ['Mahmudul', 'Naiem'],
        'Music'       => ['Mahmudul', 'Tajul'],
        'Culture'     => ['Mahmudul', 'Rakib'],
    ];

    /* --------------------------------------------------------
     * 5. Insert presidents (one per club)
     * -------------------------------------------------------- */
    out('Creating 7 presidents…');
    $presidentPwd = password_hash('president123', PASSWORD_BCRYPT);
    $presidentIds = [];
    foreach ($clubsData as $c) {
        $info = $presidents[$c['category']];
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, role)
             VALUES (:n, :e, :p, 'president') RETURNING id"
        )->execute([
            ':n' => $info['first'],
            ':e' => $info['email'],
            ':p' => $presidentPwd,
        ]);
        $presidentIds[$c['category']] = (int)$pdo->lastInsertId();
    }

    /* --------------------------------------------------------
     * 6. Insert member students
     * -------------------------------------------------------- */
    out('Creating 12 student members…');
    $memberPwd = password_hash('member123', PASSWORD_BCRYPT);
    $memberIds = [];
    foreach ($memberRoster as $first) {
        $email = strtolower($first) . '@campusorbit.com';
        $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, role)
             VALUES (:n, :e, :p, 'student') RETURNING id"
        )->execute([
            ':n' => $first,
            ':e' => $email,
            ':p' => $memberPwd,
        ]);
        $memberIds[$first] = (int)$pdo->lastInsertId();
    }

    /* --------------------------------------------------------
     * 7. Insert clubs
     * -------------------------------------------------------- */
    out('Creating 7 clubs…');
    $clubIds = [];
    foreach ($clubsData as $c) {
        $pdo->prepare(
            "INSERT INTO clubs (name, mission, category, president_id)
             VALUES (:n, :m, :cat, :pid) RETURNING id"
        )->execute([
            ':n'   => $c['name'],
            ':m'   => $c['mission'],
            ':cat' => $c['category'],
            ':pid' => $presidentIds[$c['category']],
        ]);
        $clubIds[$c['category']] = (int)$pdo->lastInsertId();
    }

    /* --------------------------------------------------------
     * 8. Wire members to clubs (with Mahmudul in every club)
     * -------------------------------------------------------- */
    out('Linking members to clubs…');
    $seenPairs = [];
    foreach ($membershipMap as $cat => $names) {
        $cid = $clubIds[$cat];
        foreach ($names as $first) {
            $uid = $memberIds[$first];
            $key = $cid . ':' . $uid;
            if (isset($seenPairs[$key])) continue;
            $seenPairs[$key] = true;
            $pdo->prepare(
                "INSERT INTO club_members (club_id, user_id) VALUES (:c, :u)"
            )->execute([':c' => $cid, ':u' => $uid]);
        }
    }

    /* --------------------------------------------------------
     * 9. Insert 12 events (1–2 per club)
     *    4 marked featured.
     *    All confirmed so they show up on the public homepage.
     * -------------------------------------------------------- */
    out('Creating 12 events (4 featured)…');

    // Venues we cycle through
    $venueIds = [];
    foreach ($pdo->query("SELECT id FROM venues ORDER BY id")->fetchAll() as $v) {
        $venueIds[] = (int)$v['id'];
    }
    if (!$venueIds) {
        throw new RuntimeException('No venues available; please run schema.sql before seeding.');
    }
    $vCount = count($venueIds);

    // Build events: distributed across next 30 days starting from today
    $today = new DateTimeImmutable('today');

    $eventsSpec = [
        // [category, dayOffset, startHour, endHour, title, description, featured]
        ['Programming', 2,  10, 12, 'Code Sprint: Logic Building',
         'A two-hour focused coding session tackling classic algorithmic problems with peer review.', true],
        ['Programming', 9,  15, 17, 'Web Stack Workshop',
         'Hands-on workshop building a small full-stack application end-to-end.', false],

        ['Career',      3,  14, 16, 'CV Polish Lab',
         'Bring your CV for a one-on-one review and live feedback from senior peers.', true],
        ['Career',     11,  11, 13, 'Industry Talk: Tech Careers',
         'Panel discussion with professionals on breaking into the tech industry.', false],

        ['Science',     4,  10, 12, 'Research Showcase',
         'Members present recent experiments, with Q&A and prizes for top projects.', false],
        ['Science',    12,  13, 15, 'Open Lab Day',
         'Drop-in session to explore active research projects and meet the team.', true],

        ['Robotics',    5,  10, 13, 'Line Follower Build-off',
         'Teams build and race line-following robots on a custom track.', false],
        ['Robotics',   14,  15, 17, 'Intro to Embedded Systems',
         'Workshop on programming microcontrollers and reading sensor data.', false],

        ['Debate',      6,  16, 18, 'Parliamentary Practice',
         'Friendly parliamentary-style debate open to all skill levels.', false],
        ['Debate',     16,  14, 17, 'Inter-Club Debate Tournament',
         'Head-to-head debates on contemporary topics with audience vote.', false],

        ['Music',       7,  17, 19, 'Open Mic Night',
         'Singers, bands, and solo acts showcase their music in a relaxed setting.', true],
        ['Culture',    10,  12, 14, 'Heritage Festival',
         'A vibrant celebration of cultural heritage with food, art, and performances.', false],
    ];

    $featuredCount = 0;
    foreach ($eventsSpec as $i => $e) {
        [$cat, $dayOffset, $sh, $eh, $title, $desc, $isFeatured] = $e;
        $date = $today->modify("+{$dayOffset} days")->format('Y-m-d');
        $start = sprintf('%02d:00', $sh);
        $end   = sprintf('%02d:00', $eh);
        $vid = $venueIds[$i % $vCount];
        $cid = $clubIds[$cat];
        $creator = $presidentIds[$cat];
        $pdo->prepare(
            "INSERT INTO events (club_id, venue_id, title, description, event_date,
                                 start_time, end_time, status, is_featured, created_by)
             VALUES (:c, :v, :t, :d, :date, :st, :et, 'confirmed', :f, :cb)"
        )->execute([
            ':c'    => $cid,
            ':v'    => $vid,
            ':t'    => $title,
            ':d'    => $desc,
            ':date' => $date,
            ':st'   => $start,
            ':et'   => $end,
            ':f'    => $isFeatured ? 'true' : 'false',
            ':cb'   => $creator,
        ]);
        if ($isFeatured) $featuredCount++;
    }

    $pdo->commit();

    out("✔ Seed complete.");
    out("  Clubs:        7");
    out("  Presidents:   7");
    out("  Members:      " . count($memberRoster));
    out("  Memberships:  " . count($seenPairs));
    out("  Events:       12 (featured: {$featuredCount})");
    out('');
    out('Demo credentials:');
    out('  Demo admin:    admin@campusorbit.com      / admin123');
    out('  System admin:  systemadmin@campusorbit.com / system1234');
    out('  President:     jarif@campusorbit.com     / president123');
    out('  Member:        mahmudul@campusorbit.com  / member123');
    out('');
    out('Visit /pages/login.php to log in.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo '<h1>Seed error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}