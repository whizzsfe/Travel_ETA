<?php

// Session + DB must be available before validation/redirect (before any HTML output).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------------
// Validate ?id= and redirect before any HTML is emitted
// ---------------------------------------------------------------------------
$tripId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($tripId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Trip not found.'];
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM trips WHERE id = ?');
$stmt->execute([$tripId]);
$trip = $stmt->fetch();

if (!$trip) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Trip not found.'];
    header('Location: index.php');
    exit;
}

// Load waypoints ordered by stop_order
$wpStmt = $pdo->prepare('SELECT * FROM trip_waypoints WHERE trip_id = ? ORDER BY stop_order ASC');
$wpStmt->execute([$tripId]);
$waypoints = $wpStmt->fetchAll();

// Load search history (newest first)
$histStmt = $pdo->prepare(
    'SELECT * FROM searches WHERE trip_id = ? ORDER BY run_at DESC'
);
$histStmt->execute([$tripId]);
$searchHistory = $histStmt->fetchAll();

// Most recent search for freshness banner
$latestSearch = $searchHistory[0] ?? null;

// Drill-down: load iterations for a specific search
$drillSearch     = null;
$drillIterations = [];
$drillSearchId   = isset($_GET['search']) ? (int) $_GET['search'] : 0;

if ($drillSearchId > 0) {
    // IDOR check: ensure this search belongs to the current trip
    $dsStmt = $pdo->prepare('SELECT * FROM searches WHERE id = ? AND trip_id = ?');
    $dsStmt->execute([$drillSearchId, $tripId]);
    $drillSearch = $dsStmt->fetch();

    if ($drillSearch) {
        $iterStmt = $pdo->prepare(
            'SELECT * FROM iterations WHERE search_id = ? ORDER BY departure_time ASC'
        );
        $iterStmt->execute([$drillSearchId]);
        $drillIterations = $iterStmt->fetchAll();
    }
}

// ---------------------------------------------------------------------------
// Helper: format delta_seconds as a human-readable string with a badge
// ---------------------------------------------------------------------------
function deltaBadge(array $search, ?int $deltaSec, bool $isIteration = false): string {
    if ($deltaSec === null) {
        return '<span class="badge bg-secondary">—</span>';
    }

    $warning = $search['warning'] ?? null;
    $isBest  = $isIteration ? ($search['is_best'] ?? 0) : false;

    $abs  = abs($deltaSec);
    $mins = intdiv($abs, 60);
    $secs = $abs % 60;
    $label = ($mins > 0 ? $mins . 'm ' : '') . $secs . 's';

    if ($deltaSec >= -900 && $deltaSec <= 0) {
        // Ideal: arrived 0–15 min early
        $class = 'badge bg-green-500 text-white';
        $prefix = '-';
    } elseif ($deltaSec < -900) {
        // More than 15 min early
        $class = 'badge bg-teal-400 text-white';
        $prefix = '-';
    } elseif ($deltaSec > 0 && ($isIteration ? ($isBest && $warning) : $warning)) {
        // Least-late result when warning is set
        $class = 'badge bg-orange-500 text-white';
        $prefix = '+';
    } else {
        // Late
        $class = 'badge bg-red-600 text-white';
        $prefix = '+';
    }

    return '<span class="' . $class . '">' . $prefix . $label . '</span>';
}

// ---------------------------------------------------------------------------
// Freshness banner logic
// ---------------------------------------------------------------------------
function freshnessBanner(?array $search): string {
    if (!$search || $search['best_departure'] === null) {
        return '';
    }

    $bestDt = new DateTime($search['best_departure'], new DateTimeZone('Europe/London'));
    $now    = new DateTime('now',                     new DateTimeZone('Europe/London'));

    if ($bestDt <= $now) {
        // Already departed
        return '';
    }

    $diffSeconds = $bestDt->getTimestamp() - $now->getTimestamp();
    $diffHours   = $diffSeconds / 3600;

    if ($diffHours <= 2) {
        return '<div class="alert alert-teal d-flex align-items-center" role="alert">'
             . '<i class="bi bi-broadcast me-2"></i>'
             . '<strong>Live traffic</strong>&nbsp;— high confidence. Data reflects current conditions.'
             . '</div>';
    } else {
        return '<div class="alert alert-orange d-flex align-items-center" role="alert">'
             . '<i class="bi bi-exclamation-triangle-fill me-2"></i>'
             . 'Re-run closer to departure for better accuracy — traffic data may not reflect conditions at departure.'
             . '</div>';
    }
}

require_once __DIR__ . '/header.php';
?>

<!-- Trip summary -->
<div class="mb-4">
    <h1 class="h3"><?= h($trip['name']) ?></h1>
    <p class="mb-1">
        <i class="bi bi-geo-alt-fill me-1 text-primary"></i>
        <strong>From:</strong> <?= h($trip['origin_display']) ?>
    </p>
    <?php foreach ($waypoints as $wp): ?>
        <p class="mb-1 ms-3">
            <i class="bi bi-pin-map-fill me-1 text-secondary"></i>
            <strong>via:</strong> <?= h($wp['display_name']) ?>
        </p>
    <?php endforeach; ?>
    <p class="mb-1">
        <i class="bi bi-flag-fill me-1 text-danger"></i>
        <strong>To:</strong> <?= h($trip['destination_display']) ?>
    </p>
</div>

<?php
// Flash message
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    echo '<div class="alert alert-' . h($flash['type']) . ' d-flex align-items-center" role="alert">'
       . h($flash['message'])
       . '</div>';
}
?>

<!-- Run Search form -->
<div class="card mb-4">
    <div class="card-header fw-bold">
        <i class="bi bi-arrow-repeat me-1"></i>Run Search
    </div>
    <div class="card-body">
        <form method="post" action="search.php">
            <input type="hidden" name="trip_id"    value="<?= $trip['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="target_arrival" class="form-label">
                        <i class="bi bi-clock-fill me-1"></i>Target arrival (London time)
                    </label>
                    <input type="datetime-local" class="form-control" id="target_arrival"
                           name="target_arrival" required>
                </div>
                <div class="col-md-4">
                    <label for="estimated_duration_minutes" class="form-label">
                        <i class="bi bi-box-arrow-right me-1"></i>Est. journey time (minutes)
                    </label>
                    <input type="number" class="form-control" id="estimated_duration_minutes"
                           name="estimated_duration_minutes" min="5" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-teal w-100">
                        <i class="bi bi-arrow-repeat me-1"></i>Search
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Freshness banner -->
<?= freshnessBanner($latestSearch) ?>

<!-- Search history -->
<h2 class="h5 mb-3"><i class="bi bi-clock-history me-2"></i>Search History</h2>

<?php if (empty($searchHistory)): ?>
    <p class="text-muted">No searches run yet. Use the form above to find the best departure time.</p>
<?php else: ?>
    <div class="table-responsive mb-4">
        <table class="table table-hover table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th>Run at</th>
                    <th>Target arrival</th>
                    <th>Best departure</th>
                    <th>Est. arrival</th>
                    <th title="Actual drive time with traffic (from Routes API)">Drive time</th>
                    <th title="How early (−) or late (+) vs. your target arrival time">Early / Late</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($searchHistory as $s): ?>
                    <tr>
                        <td><?= h($s['run_at']) ?></td>
                        <td><?= (new DateTime($s['target_arrival'], new DateTimeZone('Europe/London')))->format('D j M, H:i') ?></td>
                        <td>
                            <?php if ($s['best_departure'] === null): ?>
                                <span class="text-muted fst-italic">No result — all slots failed</span>
                            <?php else: ?>
                                <?= (new DateTime($s['best_departure'], new DateTimeZone('Europe/London')))->format('D j M, H:i') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $s['estimated_arrival'] !== null ? (new DateTime($s['estimated_arrival'], new DateTimeZone('Europe/London')))->format('D j M, H:i') : '—' ?></td>
                        <td>
                            <?php if ($s['duration_seconds'] !== null): ?>
                                <?php $dm = (int)($s['duration_seconds'] / 60); $ds = (int)($s['duration_seconds'] % 60); ?>
                                <?= $dm ?>m<?= $ds > 0 ? ' ' . $ds . 's' : '' ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= deltaBadge($s, $s['delta_seconds'] !== null ? (int) $s['delta_seconds'] : null) ?></td>
                        <td>
                            <a href="trip.php?id=<?= $tripId ?>&search=<?= $s['id'] ?>"
                               class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-list-ul"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Drill-down: iterations for selected search -->
<?php if ($drillSearch): ?>
    <h2 class="h5 mb-3">
        <i class="bi bi-list-ul me-2"></i>Iterations — Search run at <?= h($drillSearch['run_at']) ?>
    </h2>

    <div class="table-responsive mb-4">
        <table class="table table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th>Departure</th>
                    <th>Est. arrival</th>
                    <th title="How early (−) or late (+) vs. your target arrival time">Early / Late</th>
                    <th>Drive time</th>
                    <th>Without traffic</th>
                    <th>Flags</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($drillIterations as $iter): ?>
                    <?php
                    // Row class
                    if ($iter['is_best']) {
                        $rowClass = 'table-green-100';
                    } elseif ($iter['skipped']) {
                        $rowClass = 'table-secondary';
                    } elseif ($iter['error']) {
                        $rowClass = 'table-orange-100';
                    } else {
                        $rowClass = '';
                    }

                    // Build a synthetic array for deltaBadge when used per-iteration
                    $iterCtx = [
                        'warning' => $drillSearch['warning'],
                        'is_best' => $iter['is_best'],
                    ];
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= h($iter['departure_time']) ?></td>
                        <td><?= $iter['estimated_arrival'] !== null ? h($iter['estimated_arrival']) : '—' ?></td>
                        <td><?= deltaBadge($iterCtx, $iter['delta_seconds'] !== null ? (int) $iter['delta_seconds'] : null, true) ?></td>
                        <td>
                            <?php if ($iter['duration_seconds'] !== null): ?>
                                <?= round($iter['duration_seconds'] / 60, 1) ?> min
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($iter['static_duration_seconds'] !== null): ?>
                                <?= round($iter['static_duration_seconds'] / 60, 1) ?> min
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($iter['is_best']): ?>
                                <i class="bi bi-star-fill text-warning" title="Best slot"></i>
                            <?php endif; ?>
                            <?php if ($iter['skipped']): ?>
                                <i class="bi bi-skip-forward-fill text-secondary" title="Skipped — departure in past"></i>
                            <?php endif; ?>
                            <?php if ($iter['error']): ?>
                                <i class="bi bi-x-circle-fill text-danger"></i>
                                <small class="text-danger ms-1"><?= h($iter['error_message'] ?? 'API error') ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
