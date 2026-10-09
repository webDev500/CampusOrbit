<?php
/**
 * pages/club_list.php
 *
 * Public club directory with search / category filter.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

$pdo = get_pdo();

// Filters
$q        = trim($_GET['q']        ?? '');
$category = trim($_GET['category'] ?? '');

$sql = "SELECT c.id, c.name, c.mission, c.category, c.president_id,
               u.full_name AS president_name,
               (SELECT COUNT(*) FROM club_members m WHERE m.club_id = c.id) AS member_count
          FROM clubs c
          LEFT JOIN users u ON u.id = c.president_id
         WHERE 1=1";
$params = [];

// Search and category filters are independent — combined with OR.
// Each filter, when active, scans the full club table; the result is the union.
$orClauses = [];
if ($q !== '') {
    $orClauses[] = "(c.name ILIKE :q OR c.mission ILIKE :q OR c.category ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($category !== '') {
    $orClauses[] = "c.category = :cat";
    $params[':cat'] = $category;
}
if ($orClauses) {
    $sql .= " AND (" . implode(' OR ', $orClauses) . ")";
}

// Logged-in president's own club pinned to the top.
// PostgreSQL ORDER BY treats a bare integer literal as a 1-indexed SELECT-list
// reference, so we cast to int (e.g. 0::int) which forces it to be evaluated
// as an expression rather than a column position.
$ownClubOrderExpr = "(0::int)";
if (is_logged_in() && current_user_role() === 'president') {
    $ownClubOrderExpr = "(CASE WHEN c.president_id = " . (int)current_user_id() . " THEN 0::int ELSE 1::int END)";
}
$sql .= " ORDER BY " . $ownClubOrderExpr . " ASC, c.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clubs = $stmt->fetchAll();

// Suggest pool: every club with name, mission, category — used by the live search box.
// We pull it independently so suggestions can show even when the main result list is filtered.
$suggestPool = [];
$sugStmt = $pdo->query(
    "SELECT c.id, c.name, c.category, c.mission FROM clubs c ORDER BY c.name ASC"
);
foreach ($sugStmt->fetchAll() as $row) {
    $tokens = [];
    foreach (preg_split('/[\s,.;:()\/]+/u', strtolower($row['name'] . ' ' . ($row['category'] ?? '') . ' ' . ($row['mission'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        if (mb_strlen($t) >= 3) $tokens[$t] = true;
    }
    $suggestPool[] = [
        'id'       => (int)$row['id'],
        'name'     => $row['name'],
        'category' => $row['category'] ?? '',
        'tokens'   => array_keys($tokens),
    ];
}

// Distinct categories
$catStmt = $pdo->query("SELECT DISTINCT category FROM clubs WHERE category IS NOT NULL ORDER BY category");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Memberships (if student)
$myClubs = [];
if (is_logged_in() && current_user_role() === 'student') {
    $stmt = $pdo->prepare("SELECT club_id FROM club_members WHERE user_id = :u");
    $stmt->execute([':u' => current_user_id()]);
    $myClubs = array_column($stmt->fetchAll(), 'club_id');
}

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$PAGE_TITLE = 'Clubs — CampusOrbit';
include __DIR__ . '/../components/header.php';
include __DIR__ . '/../components/navbar.php';
?>
<main class="app-main">
    <div class="container container-narrow py-4">
        <h2 class="section-title"><i class="bi bi-collection"></i> Discover Clubs</h2>

        <?php if ($flash_success): ?>
            <div class="alert alert-success"><?= e($flash_success) ?></div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="alert alert-danger"><?= e($flash_error) ?></div>
        <?php endif; ?>

        <form method="get" class="form-section mb-4" id="club-filter-form" autocomplete="off">
            <div class="row g-2 align-items-end">
                <div class="col-md-8 position-relative">
                    <label class="form-label" for="q">Search</label>
                    <input type="text" class="form-control" id="q" name="q"
                           value="<?= e($q) ?>" placeholder="Search by name, mission, or keyword (e.g. tech, music)…"
                           autocomplete="off">
                    <div id="club-suggest" class="club-suggest" hidden></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category" name="category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                <?= e($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>

        <div id="club-results">
            <?php if (!$clubs): ?>
                <div class="state-block" id="club-empty">
                    <div class="state-icon"><i class="bi bi-emoji-frown"></i></div>
                    No clubs match your search.
                </div>
            <?php else: ?>
                <div class="state-block" id="club-empty-live" style="display:none;">
                    <div class="state-icon"><i class="bi bi-emoji-frown"></i></div>
                    No clubs match your current filters.
                </div>
                <div class="row g-3" id="club-grid">
                <?php foreach ($clubs as $c): ?>
                    <?php
                        $searchBlob = strtolower(($c['name'] ?? '') . ' ' . ($c['category'] ?? '') . ' ' . ($c['mission'] ?? ''));
                    ?>
                    <div class="col-md-6 col-lg-4 club-cell"
                         data-id="<?= (int)$c['id'] ?>"
                         data-category="<?= e($c['category']) ?>"
                         data-search="<?= e($searchBlob) ?>">
                        <div class="co-card h-100 club-card-ui" onclick="window.location.href='<?= base_url('pages/club_details.php?id=' . (int)$c['id']) ?>';" style="cursor: pointer; border-top: 4px solid var(--color-primary-l);">
                            <div class="co-card-body d-flex flex-column h-100">
                                <div>
                                    <span class="badge bg-primary bg-opacity-10 text-primary mb-2 px-2 py-1 border border-primary-subtle rounded-pill"><?= e($c['category']) ?></span>
                                    <h5 class="mb-2 fw-bold">
                                        <a href="<?= base_url('pages/club_details.php?id=' . (int)$c['id']) ?>" class="text-decoration-none text-dark">
                                            <?= e($c['name']) ?>
                                        </a>
                                    </h5>
                                    <div class="d-flex align-items-center text-muted small mb-3 gap-3">
                                        <span><i class="bi bi-person-badge text-primary"></i> <?= e($c['president_name'] ?? 'TBA') ?></span>
                                        <span><i class="bi bi-people text-primary"></i> <?= (int)$c['member_count'] ?></span>
                                    </div>
                                    <p class="small text-secondary mb-4" style="line-height: 1.6;">
                                        <?= e(mb_substr($c['mission'], 0, 140)) ?><?= mb_strlen($c['mission']) > 140 ? '…' : '' ?>
                                    </p>
                                </div>
                                <div class="mt-auto position-relative border-top pt-3 d-flex justify-content-center align-items-center w-100" style="z-index: 2;" onclick="event.stopPropagation()">
                                    <?php
                                    $viewerRole = current_user_role();
                                    $viewerId   = current_user_id();
                                    $isOwnClub  = $viewerRole === 'president' && (int)$c['president_id'] === (int)$viewerId;
                                    ?>
                                    <?php if ($viewerRole === 'student'): ?>
                                        <?php if (in_array((int)$c['id'], $myClubs, true)): ?>
                                            <form method="post" action="<?= base_url('actions/club_handler.php') ?>" class="m-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="leave_club">
                                                <input type="hidden" name="club_id" value="<?= (int)$c['id'] ?>">
                                                <button class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-medium">
                                                    <i class="bi bi-box-arrow-right"></i> Leave
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="<?= base_url('actions/club_handler.php') ?>" class="m-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="join_club">
                                                <input type="hidden" name="club_id" value="<?= (int)$c['id'] ?>">
                                                <button class="btn btn-primary btn-sm rounded-pill px-3 fw-medium shadow-sm">
                                                    <i class="bi bi-person-plus"></i> Join Club
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif ($viewerRole === 'president'): ?>
                                        <?php if ($isOwnClub): ?>
                                            <a class="btn btn-soft btn-sm rounded-pill px-3 fw-medium" href="<?= base_url('pages/dashboard_president.php') ?>">
                                                <i class="bi bi-gear"></i> Manage
                                            </a>
                                        <?php else: ?>
                                            <span class="badge text-bg-light rounded-pill px-3 py-2 text-muted fw-medium border">
                                                <i class="bi bi-info-circle"></i> Other Club
                                            </span>
                                        <?php endif; ?>
                                    <?php elseif ($viewerRole === 'admin'): ?>
                                        <span class="badge text-bg-light rounded-pill px-3 py-2 text-muted fw-medium border">
                                            <i class="bi bi-shield-check text-primary"></i> Admin View
                                        </span>
                                    <?php else: ?>
                                        <a class="btn btn-soft btn-sm rounded-pill px-3 fw-medium" href="<?= base_url('pages/login.php?redirect=pages/club_list.php') ?>">
                                            Login to join
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
.club-suggest {
    position: absolute;
    top: 100%;
    left: 0; right: 0;
    margin-top: .25rem;
    background: #fff;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
    max-height: 280px;
    overflow-y: auto;
    z-index: 1050;
}
.club-suggest .suggest-item {
    padding: .55rem .75rem;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    font-size: .9rem;
    color: #374151;
    transition: background .1s;
}
.club-suggest .suggest-item:last-child { border-bottom: 0; }
.club-suggest .suggest-item:hover,
.club-suggest .suggest-item.active {
    background: #eff6ff;
    color: var(--color-primary-d);
}
.club-suggest .suggest-meta {
    font-size: .75rem;
    color: var(--color-muted);
    margin-top: .15rem;
}
.club-suggest .suggest-empty {
    padding: .75rem;
    color: var(--color-muted);
    font-size: .85rem;
    text-align: center;
}
.club-suggest mark {
    background: #fef3c7;
    color: #92400e;
    padding: 0 .1rem;
    border-radius: 2px;
}
.club-cell { transition: opacity .15s; }
.club-card-ui {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.club-card-ui:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}
</style>

<script id="club-suggest-pool-data" type="application/json"><?= json_encode($suggestPool, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?></script>

<script>
(function () {
    var form    = document.getElementById('club-filter-form');
    var qInput  = document.getElementById('q');
    var catSel  = document.getElementById('category');
    var suggest = document.getElementById('club-suggest');
    if (!form || !qInput || !catSel) return;

    // Pool of all clubs (with tokens) for keyword suggestions.
    var poolNode = document.getElementById('club-suggest-pool-data');
    var pool = [];
    try { pool = poolNode ? JSON.parse(poolNode.textContent || '[]') : []; } catch (e) { pool = []; }

    var submitTimer = null;
    var filterTimer = null;
    var suggestTimer = null;
    var lastQuery = '';
    var activeIdx = -1;
    var currentItems = [];

    // Page reload on category change (committed filter).
    function submitSoon(delay) {
        clearTimeout(submitTimer);
        submitTimer = setTimeout(function () { form.submit(); }, delay || 0);
    }

    // In-place live filtering of the result grid (no reload).
    // Filters are independent and combined with OR (union):
    //   - search matches the typed query against name/mission/category tokens
    //   - category matches the selected category exactly
    // A card is shown if it matches EITHER filter. If only one filter is active
    // (q empty or category empty), the other alone decides visibility.
    function applyFilters() {
        var q = qInput.value.trim().toLowerCase();
        var cat = catSel.value;
        var hasQ = !!q;
        var hasCat = !!cat;
        
        var cells = document.querySelectorAll('.club-cell');
        var visible = 0;
        cells.forEach(function (cell) {
            var blob   = cell.getAttribute('data-search')   || '';
            var cellCat = cell.getAttribute('data-category') || '';
            
            var matchSearch = hasQ && (blob.indexOf(q) !== -1);
            var matchCat    = hasCat && (cellCat === cat);
            
            var show = false;
            if (hasQ && hasCat) {
                show = matchSearch || matchCat;
            } else if (hasQ) {
                show = matchSearch;
            } else if (hasCat) {
                show = matchCat;
            } else {
                show = true;
            }
            
            cell.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        // Live empty-state: shown when cards exist server-side but JS hides them all.
        var liveEmpty = document.getElementById('club-empty-live');
        if (liveEmpty) liveEmpty.style.display = (visible === 0 && cells.length > 0) ? '' : 'none';
    }

    catSel.addEventListener('change', function () {
        // Live-update locally and reload server-side too (commit filter).
        applyFilters();
        submitSoon(0);
    });
    qInput.addEventListener('input', function () {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(applyFilters, 80);
        // Suggestions ignore the active category so they always suggest across the full pool.
        fetchSuggest();
    });
    qInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            // Pick active suggestion if any; otherwise just commit (page reload)
            // so the URL reflects the typed query (good for sharing/bookmarking).
            if (activeIdx >= 0 && currentItems[activeIdx]) {
                e.preventDefault();
                pickItem(currentItems[activeIdx]);
            } else {
                e.preventDefault();
                hideSuggest();
                submitSoon(0);
            }
            return;
        }
        if (suggest.hidden || !currentItems.length) {
            if (e.key === 'Escape') hideSuggest();
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIdx = Math.min(activeIdx + 1, currentItems.length - 1);
            highlight();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIdx = Math.max(activeIdx - 1, 0);
            highlight();
        } else if (e.key === 'Escape') {
            hideSuggest();
        }
    });

    document.addEventListener('click', function (e) {
        if (!suggest.contains(e.target) && e.target !== qInput) hideSuggest();
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }
    function escapeRegex(s) {
        return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
    function highlightMatch(text, q) {
        if (!q) return escapeHtml(text);
        var safe = escapeHtml(text);
        var pat = escapeRegex(escapeHtml(q));
        return safe.replace(new RegExp('(' + pat + ')', 'ig'), '<mark>$1</mark>');
    }

    function hideSuggest() {
        suggest.hidden = true;
        suggest.innerHTML = '';
        activeIdx = -1;
        currentItems = [];
    }
    function highlight() {
        var nodes = suggest.querySelectorAll('.suggest-item');
        nodes.forEach(function (n, i) {
            if (i === activeIdx) n.classList.add('active');
            else n.classList.remove('active');
        });
        if (activeIdx >= 0 && nodes[activeIdx]) {
            nodes[activeIdx].scrollIntoView({ block: 'nearest' });
        }
    }
    function pickItem(item) {
        // Picking a suggestion fills the search box with the matched club name
        // and live-filters the result grid (no page reload).
        qInput.value = item.label;
        hideSuggest();
        applyFilters();
    }

    // Match a club to the typed query by any token (name word, category, mission).
    function matchClub(club, qLower) {
        if ((club.name || '').toLowerCase().indexOf(qLower) !== -1) return true;
        if ((club.category || '').toLowerCase().indexOf(qLower) !== -1) return true;
        for (var i = 0; i < club.tokens.length; i++) {
            if (club.tokens[i].indexOf(qLower) !== -1) return true;
        }
        return false;
    }

    function fetchSuggest() {
        clearTimeout(suggestTimer);
        var q = qInput.value.trim();
        if (q.length < 2) { hideSuggest(); return; }
        if (q === lastQuery) return;
        lastQuery = q;
        var qLower = q.toLowerCase();

        // Suggestions are independent of the category filter:
        // always scan the entire pool, ignoring catSel.value.
        suggestTimer = setTimeout(function () {
            var matches = [];
            for (var i = 0; i < pool.length && matches.length < 8; i++) {
                if (matchClub(pool[i], qLower)) {
                    matches.push({
                        label:    pool[i].name,
                        category: pool[i].category,
                        keyword:  q
                    });
                }
            }
            renderSuggest(matches, q);
        }, 90);
    }

    function renderSuggest(items, q) {
        currentItems = items;
        activeIdx = -1;
        if (!items.length) {
            suggest.innerHTML = '<div class="suggest-empty">No matches for "' + escapeHtml(q) + '"</div>';
            suggest.hidden = false;
            return;
        }
        var html = items.map(function (it, idx) {
            return '<div class="suggest-item" data-idx="' + idx + '">'
                + '<div>' + highlightMatch(it.label, q) + '</div>'
                + '<div class="suggest-meta"><i class="bi bi-tag"></i> '
                + escapeHtml(it.category || 'Uncategorized') + '</div>'
                + '</div>';
        }).join('');
        suggest.innerHTML = html;
        suggest.hidden = false;
        suggest.querySelectorAll('.suggest-item').forEach(function (node) {
            node.addEventListener('mouseenter', function () {
                activeIdx = parseInt(node.getAttribute('data-idx'), 10);
                highlight();
            });
            node.addEventListener('click', function () {
                pickItem(items[parseInt(node.getAttribute('data-idx'), 10)]);
            });
        });
    }
})();
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>