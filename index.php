<?php

// Session + DB must be available before the POST handler runs (before any HTML output).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------------
// POST handler — new trip form submission / delete trip
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid form submission. Please try again.'];
        header('Location: index.php');
        exit;
    }

    // -----------------------------------------------------------------------
    // Delete trip action
    // -----------------------------------------------------------------------
    if (($_POST['action'] ?? '') === 'delete') {
        $tripId = (int) ($_POST['trip_id'] ?? 0);
        if ($tripId > 0) {
            $pdo->prepare('DELETE FROM trips WHERE id = ?')->execute([$tripId]);
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Trip deleted.'];
        header('Location: index.php');
        exit;
    }

    // Trip name
    $tripName = trim($_POST['trip_name'] ?? '');
    if ($tripName === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Trip name is required.'];
        header('Location: index.php');
        exit;
    }

    // Origin
    $originPlaceId   = trim($_POST['origin_place_id']   ?? '');
    $originDisplay   = trim($_POST['origin_display']     ?? '');
    $originLat       = trim($_POST['origin_lat']         ?? '');
    $originLng       = trim($_POST['origin_lng']         ?? '');

    if ($originPlaceId === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please select an origin from the dropdown.'];
        header('Location: index.php');
        exit;
    }

    // Destination
    $destPlaceId   = trim($_POST['destination_place_id']   ?? '');
    $destDisplay   = trim($_POST['destination_display']     ?? '');
    $destLat       = trim($_POST['destination_lat']         ?? '');
    $destLng       = trim($_POST['destination_lng']         ?? '');

    if ($destPlaceId === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please select a destination from the dropdown.'];
        header('Location: index.php');
        exit;
    }

    // Waypoints
    $rawWaypoints = $_POST['waypoints'] ?? [];
    $waypoints    = [];
    foreach ($rawWaypoints as $idx => $wp) {
        $wpPlaceId = trim($wp['place_id'] ?? '');
        if ($wpPlaceId === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please select all waypoints from the dropdown, or remove incomplete ones.'];
            header('Location: index.php');
            exit;
        }
        $waypoints[] = [
            'place_id'     => $wpPlaceId,
            'display_name' => trim($wp['display_name'] ?? ''),
            'lat'          => trim($wp['lat']          ?? ''),
            'lng'          => trim($wp['lng']          ?? ''),
            'stop_order'   => (int) $idx,
        ];
    }

    // Compute trip_hash — MD5(origin_place_id|wp_place_ids|destination_place_id)
    // Zero waypoints → "ORIGIN_ID||DEST_ID" (double pipe — intentional and deterministic)
    $wpPlaceIds = array_column($waypoints, 'place_id');
    $hashInput  = $originPlaceId . '|' . implode('|', $wpPlaceIds) . '|' . $destPlaceId;
    $tripHash   = md5($hashInput);

    // Dedup: redirect to existing trip if same route already saved
    $stmt = $pdo->prepare('SELECT id FROM trips WHERE trip_hash = ?');
    $stmt->execute([$tripHash]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'This exact route is already saved — showing existing trip.'];
        header('Location: trip.php?id=' . (int) $existing);
        exit;
    }

    // Insert trip
    $stmt = $pdo->prepare(
        'INSERT INTO trips
            (name, origin_display, origin_place_id, origin_lat, origin_lng,
             destination_display, destination_place_id, destination_lat, destination_lng,
             trip_hash, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $tripName,
        $originDisplay,
        $originPlaceId,
        (float) $originLat,
        (float) $originLng,
        $destDisplay,
        $destPlaceId,
        (float) $destLat,
        (float) $destLng,
        $tripHash,
        date('Y-m-d H:i:s'),
    ]);
    $newTripId = (int) $pdo->lastInsertId();

    // Insert waypoints
    if (!empty($waypoints)) {
        $wpStmt = $pdo->prepare(
            'INSERT INTO trip_waypoints (trip_id, display_name, place_id, lat, lng, stop_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($waypoints as $wp) {
            $wpStmt->execute([
                $newTripId,
                $wp['display_name'],
                $wp['place_id'],
                (float) $wp['lat'],
                (float) $wp['lng'],
                $wp['stop_order'],
            ]);
        }
    }

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Trip saved successfully.'];
    header('Location: trip.php?id=' . $newTripId);
    exit;
}

// ---------------------------------------------------------------------------
// GET — render dashboard
// ---------------------------------------------------------------------------
require_once __DIR__ . '/header.php';

// Flash message
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    echo '<div class="alert alert-' . h($flash['type']) . ' d-flex align-items-center" role="alert">'
       . h($flash['message'])
       . '</div>';
}

// Trip list — auto-hide trips whose most recent search target has passed.
// Trips with no searches yet are always shown.
$trips = $pdo->query(
    'SELECT t.*,
            s.target_arrival    AS last_target_arrival,
            s.best_departure    AS last_best_departure,
            s.estimated_arrival AS last_est_arrival,
            s.warning           AS last_warning
     FROM trips t
     LEFT JOIN searches s ON s.id = (
         SELECT id FROM searches
         WHERE trip_id = t.id
         ORDER BY run_at DESC
         LIMIT 1
     )
     WHERE s.target_arrival IS NULL
        OR s.target_arrival >= datetime("now")
     ORDER BY t.created_at DESC'
)->fetchAll();

/**
 * Format a UTC/London datetime string for display.
 *
 * @param string|null $dt Datetime string in 'Y-m-d H:i:s' format (stored as Europe/London), or null.
 *
 * @return string Human-readable date such as "Fri 30 May, 08:35", or an em-dash when null.
 */
function fmtDt(?string $dt): string {
    if ($dt === null) return '—';
    return (new DateTime($dt, new DateTimeZone('Europe/London')))->format('D j M, H:i');
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-clock-history me-2"></i>Saved Trips</h1>
</div>

<?php if (empty($trips)): ?>
    <p class="text-muted">No trips yet — add your first trip below.</p>
<?php else: ?>
    <div class="list-group mb-4">
        <?php foreach ($trips as $trip): ?>
            <div class="list-group-item p-0 d-flex align-items-stretch">
                <a href="trip.php?id=<?= $trip['id'] ?>"
                   class="flex-grow-1 p-3 text-decoration-none text-body d-flex justify-content-between align-items-start">
                    <div>
                        <strong><?= h($trip['name']) ?></strong>
                        <div class="text-muted small">
                            <i class="bi bi-geo-alt-fill me-1"></i><?= h($trip['origin_display']) ?>
                            <i class="bi bi-arrow-right mx-1"></i>
                            <i class="bi bi-flag-fill me-1"></i><?= h($trip['destination_display']) ?>
                        </div>
                        <?php if ($trip['last_best_departure'] !== null): ?>
                            <div class="small mt-1">
                                <span class="text-muted">Target:</span> <?= fmtDt($trip['last_target_arrival']) ?>
                                &nbsp;<i class="bi bi-arrow-right"></i>&nbsp;
                                <span class="text-muted">Depart:</span> <strong><?= fmtDt($trip['last_best_departure']) ?></strong>
                            </div>
                        <?php elseif ($trip['last_target_arrival'] !== null): ?>
                            <div class="small mt-1 text-muted fst-italic">Last search: no result</div>
                        <?php endif; ?>
                    </div>
                    <i class="bi bi-chevron-right text-muted ms-2"></i>
                </a>
                <form method="post" action="index.php"
                      class="d-flex align-items-center px-2 border-start"
                      onsubmit="return confirm('Delete \'<?= addslashes(h($trip['name'])) ?>\' and all its search history?');">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action"     value="delete">
                    <input type="hidden" name="trip_id"    value="<?= $trip['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger border-0"
                            title="Delete trip">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<hr>

<h2 class="h4 mb-3"><i class="bi bi-plus-circle me-2"></i>New Trip</h2>

<form method="post" action="index.php" id="newTripForm">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

    <div class="mb-3">
        <label for="trip_name" class="form-label">Trip name</label>
        <input type="text" class="form-control" id="trip_name" name="trip_name"
               placeholder="e.g. Home to Office" required maxlength="255">
    </div>

    <!-- Origin -->
    <div class="mb-3">
        <label class="form-label"><i class="bi bi-geo-alt-fill me-1"></i>Origin</label>
        <div class="autocomplete-wrapper">
            <input type="text" class="form-control autocomplete-input"
                   data-prefix="origin" placeholder="Search for origin…">
        </div>
        <input type="hidden" name="origin_place_id"  value="">
        <input type="hidden" name="origin_display"   value="">
        <input type="hidden" name="origin_lat"       value="">
        <input type="hidden" name="origin_lng"       value="">
    </div>

    <!-- Waypoints -->
    <div id="waypointsContainer" class="mb-3"></div>
    <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="addWaypointBtn">
        <i class="bi bi-pin-map-fill me-1"></i>Add waypoint
    </button>

    <!-- Destination -->
    <div class="mb-3">
        <label class="form-label"><i class="bi bi-flag-fill me-1"></i>Destination</label>
        <div class="autocomplete-wrapper">
            <input type="text" class="form-control autocomplete-input"
                   data-prefix="destination" placeholder="Search for destination…">
        </div>
        <input type="hidden" name="destination_place_id"  value="">
        <input type="hidden" name="destination_display"   value="">
        <input type="hidden" name="destination_lat"       value="">
        <input type="hidden" name="destination_lng"       value="">
    </div>

    <div id="tripFormError" class="alert alert-danger d-none" role="alert"></div>

    <button type="submit" class="btn btn-primary">
        <i class="bi bi-floppy-fill me-1"></i>Save Trip
    </button>
</form>

<script>
    var waypointIndex = 0;

    document.getElementById('addWaypointBtn').addEventListener('click', function () {
        var container = document.getElementById('waypointsContainer');
        var idx       = waypointIndex++;
        var prefix    = 'waypoints[' + idx + ']';

        var row = document.createElement('div');
        row.className = 'mb-2';
        row.innerHTML =
            '<label class="form-label"><i class="bi bi-pin-map-fill me-1"></i>Waypoint ' + (idx + 1) + '</label>' +
            '<div class="d-flex gap-2">' +
                '<div class="autocomplete-wrapper flex-grow-1">' +
                    '<input type="text" class="form-control autocomplete-input" data-prefix="' + prefix + '" placeholder="Search for waypoint…">' +
                '</div>' +
                '<button type="button" class="btn btn-outline-danger btn-sm remove-wp"><i class="bi bi-x-lg"></i></button>' +
            '</div>' +
            '<input type="hidden" name="' + prefix + '[place_id]"     value="">' +
            '<input type="hidden" name="' + prefix + '[display_name]" value="">' +
            '<input type="hidden" name="' + prefix + '[lat]"          value="">' +
            '<input type="hidden" name="' + prefix + '[lng]"          value="">';

        container.appendChild(row);

        // Bind autocomplete to the new input
        var newInput = row.querySelector('.autocomplete-input');
        if (typeof attachAutocomplete === 'function') {
            attachAutocomplete(newInput);
        }

        // Remove waypoint
        row.querySelector('.remove-wp').addEventListener('click', function () {
            container.removeChild(row);
        });
    });
</script>

<script>
    // Client-side guard: prevent form submission if place_id hidden fields are empty.
    function showFormError(msg) {
        var el = document.getElementById('tripFormError');
        el.textContent = msg;
        el.classList.remove('d-none');
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function hideFormError() {
        var el = document.getElementById('tripFormError');
        el.classList.add('d-none');
        el.textContent = '';
    }

    document.getElementById('newTripForm').addEventListener('submit', function(e) {
        hideFormError();

        var originId = document.querySelector('input[name="origin_place_id"]');
        var destId   = document.querySelector('input[name="destination_place_id"]');

        if (!originId || !originId.value.trim()) {
            e.preventDefault();
            showFormError('Please select an origin from the autocomplete dropdown.');
            return;
        }
        if (!destId || !destId.value.trim()) {
            e.preventDefault();
            showFormError('Please select a destination from the autocomplete dropdown.');
            return;
        }

        // Validate any waypoints added dynamically.
        var wpInputs = document.querySelectorAll('#waypointsContainer input[type="hidden"][name$="[place_id]"]');
        for (var i = 0; i < wpInputs.length; i++) {
            if (!wpInputs[i].value.trim()) {
                e.preventDefault();
                showFormError('Please select all waypoints from the dropdown, or remove incomplete ones.');
                return;
            }
        }
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
