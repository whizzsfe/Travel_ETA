<?php
// TEMPORARY — remove after diagnosis
ini_set('display_errors', '1');
error_reporting(E_ALL);

// No HTML output — this file only processes POST and redirects.

session_start();

// ---------------------------------------------------------------------------
// Config guard — must happen before db.php (which also checks, but we need
// to handle the failure here since no header() redirect is safe yet).
// ---------------------------------------------------------------------------
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo 'Application not configured. Please contact the administrator.';
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routes_api.php';

// Allow up to 120 seconds — shared hosts cap at 30s by default.
set_time_limit(120);

// ---------------------------------------------------------------------------
// CSRF validation
// ---------------------------------------------------------------------------
if (
    empty($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid form submission. Please try again.'];
    header('Location: index.php');
    exit;
}

// ---------------------------------------------------------------------------
// Input validation
// ---------------------------------------------------------------------------
$tripId = isset($_POST['trip_id']) ? (int) $_POST['trip_id'] : 0;
if ($tripId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid trip.'];
    header('Location: index.php');
    exit;
}

// target_arrival comes from datetime-local: "YYYY-MM-DDTHH:MM"
$rawTarget     = $_POST['target_arrival'] ?? '';
$targetArrival = DateTime::createFromFormat('Y-m-d\TH:i', $rawTarget, new DateTimeZone('Europe/London'));
if ($targetArrival === false) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid target arrival time.'];
    header('Location: index.php');
    exit;
}
$targetArrivalStr = $targetArrival->format('Y-m-d H:i:s');

$estDuration = isset($_POST['estimated_duration_minutes']) ? (int) $_POST['estimated_duration_minutes'] : 0;
if ($estDuration < 5) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Estimated journey time must be at least 5 minutes.'];
    header('Location: index.php');
    exit;
}

// ---------------------------------------------------------------------------
// Load trip
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT * FROM trips WHERE id = ?');
$stmt->execute([$tripId]);
$trip = $stmt->fetch();

if (!$trip) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Trip not found.'];
    header('Location: index.php');
    exit;
}

// ---------------------------------------------------------------------------
// Load waypoints
// ---------------------------------------------------------------------------
$wpStmt = $pdo->prepare('SELECT * FROM trip_waypoints WHERE trip_id = ? ORDER BY stop_order ASC');
$wpStmt->execute([$tripId]);
$waypoints = $wpStmt->fetchAll();

// Build intermediates array for Routes API
$intermediates = array_map(fn($wp) => ['placeId' => $wp['place_id']], $waypoints);

// ---------------------------------------------------------------------------
// Calculate 13 departure slots
// centre = target_arrival − estimated_duration_minutes
// window = centre ± 60 min, 10-minute steps
// ---------------------------------------------------------------------------
$centre = clone $targetArrival;
$centre->modify('-' . $estDuration . ' minutes');

$slots = [];
for ($i = -6; $i <= 6; $i++) {
    $slot = clone $centre;
    $slot->modify(($i * 10) . ' minutes');
    $slots[] = $slot->format('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Loop over slots
// ---------------------------------------------------------------------------
$now = new DateTime('now', new DateTimeZone('Europe/London'));

// Accumulate iteration data (keyed by slot string for is_best flagging later)
$iterationData  = []; // full record for every slot
$successResults = []; // only successful (non-skipped, non-error) results

foreach ($slots as $slotStr) {
    $slotDt = new DateTime($slotStr, new DateTimeZone('Europe/London'));
    $record = [
        'departure_time'         => $slotStr,
        'estimated_arrival'      => null,
        'duration_seconds'       => null,
        'static_duration_seconds'=> null,
        'delta_seconds'          => null,
        'is_best'                => 0,
        'skipped'                => 0,
        'error'                  => 0,
        'error_message'          => null,
    ];

    if ($slotDt <= $now) {
        $record['skipped'] = 1;
        $iterationData[] = $record;
        continue;
    }

    try {
        $apiResult = call_routes_api(
            $trip['origin_place_id'],
            $trip['destination_place_id'],
            $intermediates,
            $slotStr
        );

        $durationSec       = $apiResult['duration'];
        $staticDurationSec = $apiResult['static_duration'];

        $estArrival = clone $slotDt;
        $estArrival->modify('+' . $durationSec . ' seconds');
        $estArrivalStr = $estArrival->format('Y-m-d H:i:s');

        $deltaSec = $estArrival->getTimestamp() - $targetArrival->getTimestamp();

        $record['estimated_arrival']       = $estArrivalStr;
        $record['duration_seconds']        = $durationSec;
        $record['static_duration_seconds'] = $staticDurationSec;
        $record['delta_seconds']           = $deltaSec;

    } catch (Exception $e) {
        $record['error']         = 1;
        $record['error_message'] = mb_substr($e->getMessage(), 0, 255);
    }

    $iterationData[] = $record;

    // Keep reference-free copy of success results
    if (!$record['skipped'] && !$record['error'] && $record['delta_seconds'] !== null) {
        $successResults[] = [
            'slot'        => $slotStr,
            'delta_seconds' => $record['delta_seconds'],
        ];
    }
}

// ---------------------------------------------------------------------------
// Post-loop scoring
// ---------------------------------------------------------------------------
$warning      = null;
$bestSlot     = null; // the slot string of the winning iteration

$onTimeResults = array_filter($successResults, fn($r) => $r['delta_seconds'] <= 0);

if (!empty($onTimeResults)) {
    // Pick slot minimising abs(delta + 900); tiebreak = later departure_time (later = greater string)
    usort($onTimeResults, function ($a, $b) {
        $scoreA = abs($a['delta_seconds'] + 900);
        $scoreB = abs($b['delta_seconds'] + 900);
        if ($scoreA !== $scoreB) {
            return $scoreA - $scoreB;
        }
        // Later departure preferred — descending string compare
        return strcmp($b['slot'], $a['slot']);
    });
    $bestSlot = $onTimeResults[0]['slot'];

} elseif (!empty($successResults)) {
    // All valid slots arrived late — pick least-late (smallest positive delta)
    usort($successResults, fn($a, $b) => $a['delta_seconds'] - $b['delta_seconds']);
    $bestSlot = $successResults[0]['slot'];
    $warning  = 'All valid slots arrived late';
}

// Mark is_best on the winning iteration record
if ($bestSlot !== null) {
    foreach ($iterationData as &$record) {
        if ($record['departure_time'] === $bestSlot) {
            $record['is_best'] = 1;
            break;
        }
    }
    unset($record);
}

// Resolve best search-level values from the winning iteration
$bestDeparture      = null;
$bestEstArrival     = null;
$bestDelta          = null;
$bestDuration       = null;
$bestStaticDuration = null;

if ($bestSlot !== null) {
    foreach ($iterationData as $record) {
        if ($record['departure_time'] === $bestSlot && $record['is_best']) {
            $bestDeparture      = $record['departure_time'];
            $bestEstArrival     = $record['estimated_arrival'];
            $bestDelta          = $record['delta_seconds'];
            $bestDuration       = $record['duration_seconds'];
            $bestStaticDuration = $record['static_duration_seconds'];
            break;
        }
    }
}

// ---------------------------------------------------------------------------
// Save searches row
// ---------------------------------------------------------------------------
$insertSearch = $pdo->prepare(
    'INSERT INTO searches
        (trip_id, target_arrival, estimated_duration_minutes, run_at,
         best_departure, estimated_arrival, delta_seconds,
         duration_seconds, static_duration_seconds, warning)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertSearch->execute([
    $tripId,
    $targetArrivalStr,
    $estDuration,
    date('Y-m-d H:i:s'),
    $bestDeparture,
    $bestEstArrival,
    $bestDelta,
    $bestDuration,
    $bestStaticDuration,
    $warning,
]);
$searchId = (int) $pdo->lastInsertId();

// ---------------------------------------------------------------------------
// Save all 13 iteration rows — always, regardless of outcome
// ---------------------------------------------------------------------------
$insertIter = $pdo->prepare(
    'INSERT INTO iterations
        (search_id, departure_time, estimated_arrival, duration_seconds,
         static_duration_seconds, delta_seconds, is_best, skipped, error, error_message)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($iterationData as $iter) {
    $insertIter->execute([
        $searchId,
        $iter['departure_time'],
        $iter['estimated_arrival'],
        $iter['duration_seconds'],
        $iter['static_duration_seconds'],
        $iter['delta_seconds'],
        $iter['is_best'],
        $iter['skipped'],
        $iter['error'],
        $iter['error_message'],
    ]);
}

// ---------------------------------------------------------------------------
// Determine outcome and set flash message
// ---------------------------------------------------------------------------
$countTotal   = count($iterationData);
$countSkipped = count(array_filter($iterationData, fn($r) => $r['skipped']));
$countError   = count(array_filter($iterationData, fn($r) => $r['error']));
$countSuccess = count($successResults);

function formatDeparture(string $dt): string {
    return (new DateTime($dt))->format('D j M, H:i');
}

if ($countSkipped === $countTotal) {
    // All slots in the past
    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'All departure times are in the past. Try a later target arrival time.',
    ];

} elseif ($countError === ($countTotal - $countSkipped)) {
    // All non-skipped slots errored
    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'All API calls failed — check your API key and quota.',
    ];

} elseif ($countSuccess === 0) {
    // Mix of skipped + errors, nothing succeeded
    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'No valid departure times — all slots were in the past or returned an API error.',
    ];

} elseif ($bestSlot !== null && $warning !== null) {
    // Result found but all valid slots arrived late
    $_SESSION['flash'] = [
        'type'    => 'warning',
        'message' => 'Best departure: ' . formatDeparture($bestDeparture)
                   . ' → est. arrival: ' . formatDeparture($bestEstArrival)
                   . '. Note: all valid slots arrived late — consider an earlier target arrival time.',
    ];

} else {
    // Clean success
    $_SESSION['flash'] = [
        'type'    => 'success',
        'message' => 'Best departure: ' . formatDeparture($bestDeparture)
                   . ' → est. arrival: ' . formatDeparture($bestEstArrival) . '.',
    ];
}

// ---------------------------------------------------------------------------
// Redirect to trip page
// ---------------------------------------------------------------------------
header('Location: trip.php?id=' . $tripId);
exit;
