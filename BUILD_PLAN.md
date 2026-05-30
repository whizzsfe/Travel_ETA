# Travel_ETA — Build Plan

Build files in the order listed. Each file depends on those above it.

---

## Build Order

1. `config.sample.php`
2. `.gitignore`
3. `schema.sql`
4. `db.php`
5. `routes_api.php`
6. `header.php`
7. `footer.php`
8. `index.php`
9. `trip.php`
10. `search.php`

---

## 1. `config.sample.php`

**Purpose:** Committed template. Server operators copy this to `config.php` and fill in real values.

**Contents:**
- `date_default_timezone_set('Europe/London');` — must be here so every file that `require_once`s config gets the timezone set immediately
- Define constants:
  - `DB_HOST` — e.g. `'localhost'`
  - `DB_NAME`
  - `DB_USER`
  - `DB_PASS`
  - `ROUTES_API_KEY` — server IP-restricted, Routes API only
  - `PLACES_API_KEY` — HTTP-referrer-restricted, Places API (New) + Maps JavaScript API only; **Routes API must NOT be enabled on this key**

**Notes:**
- All values should be empty strings or obvious placeholders (e.g. `'your-api-key-here'`)
- No real credentials ever in this file

---

## 2. `.gitignore`

**Contents:**
```
config.php
```

**Notes:**
- Single entry is sufficient
- `config.sample.php` is intentionally NOT ignored

---

## 3. `schema.sql`

**Purpose:** Standalone SQL reference. Not used at runtime (db.php self-installs), but useful for inspection and manual setup.

**Contents:** Four `CREATE TABLE IF NOT EXISTS` statements, each with `ENGINE=InnoDB`.

### Table: `trips`
```sql
id                    INT AUTO_INCREMENT PRIMARY KEY
name                  VARCHAR(255) NOT NULL
origin_display        VARCHAR(500) NOT NULL
origin_place_id       VARCHAR(255) NOT NULL
origin_lat            DECIMAL(10,7) NOT NULL
origin_lng            DECIMAL(10,7) NOT NULL
destination_display   VARCHAR(500) NOT NULL
destination_place_id  VARCHAR(255) NOT NULL
destination_lat       DECIMAL(10,7) NOT NULL
destination_lng       DECIMAL(10,7) NOT NULL
trip_hash             VARCHAR(32) NOT NULL    -- MD5 of origin_place_id|wp_place_ids|destination_place_id
created_at            DATETIME NOT NULL       -- set in PHP, not MySQL NOW()
UNIQUE KEY uq_trip_hash (trip_hash)
ENGINE=InnoDB
```

### Table: `trip_waypoints`
```sql
id            INT AUTO_INCREMENT PRIMARY KEY
trip_id       INT NOT NULL
display_name  VARCHAR(500) NOT NULL
place_id      VARCHAR(255) NOT NULL
lat           DECIMAL(10,7) NOT NULL
lng           DECIMAL(10,7) NOT NULL
stop_order    TINYINT UNSIGNED NOT NULL
FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
ENGINE=InnoDB
```

### Table: `searches`
```sql
id                         INT AUTO_INCREMENT PRIMARY KEY
trip_id                    INT NOT NULL
target_arrival             DATETIME NOT NULL
estimated_duration_minutes SMALLINT UNSIGNED NOT NULL
run_at                     DATETIME NOT NULL           -- set in PHP
best_departure             DATETIME                    -- NULL if no valid result
estimated_arrival          DATETIME                    -- NULL if no valid result
delta_seconds              INT                         -- signed; negative=early, positive=late; NULL if no result
duration_seconds           INT                         -- NULL if no result
static_duration_seconds    INT                         -- NULL if no result
warning                    VARCHAR(255)                -- set when all valid slots arrived late
FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
ENGINE=InnoDB
```

### Table: `iterations`
```sql
id                      INT AUTO_INCREMENT PRIMARY KEY
search_id               INT NOT NULL
departure_time          DATETIME NOT NULL   -- always set, even for skipped/error rows
estimated_arrival       DATETIME            -- NULL if skipped or error
duration_seconds        INT                 -- NULL if skipped or error
static_duration_seconds INT                 -- NULL if skipped or error
delta_seconds           INT                 -- NULL if skipped or error; signed
is_best                 TINYINT(1) NOT NULL DEFAULT 0
skipped                 TINYINT(1) NOT NULL DEFAULT 0
error                   TINYINT(1) NOT NULL DEFAULT 0
error_message           VARCHAR(255)
FOREIGN KEY (search_id) REFERENCES searches(id) ON DELETE CASCADE
ENGINE=InnoDB
```

---

## 4. `db.php`

**Purpose:** Safe config loader + PDO connector + schema bootstrapper.

**Logic in order:**
1. `if (!file_exists('config.php')) { die('Application not configured. Please contact the administrator.'); }` — safe message, no path disclosed
2. `require_once 'config.php';` — constants + timezone now available
3. Create PDO connection using `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - DSN: `"mysql:host=DB_HOST;dbname=DB_NAME;charset=utf8mb4"`
   - Options: `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
   - Assign to `$pdo` (global scope — included files access via `global $pdo` or same scope)
4. Execute all four `CREATE TABLE IF NOT EXISTS ... ENGINE=InnoDB` statements (exact same SQL as `schema.sql`)

**Notes:**
- `require_once` prevents double-include if header.php and index.php/trip.php both include it
- Do not `die()` on PDO exception — let it bubble (error display handled by PHP error settings on host)

---

## 5. `routes_api.php`

**Purpose:** Single public function that calls the Routes API for one departure slot.

**Function signature:**
```php
function call_routes_api(
    string $originPlaceId,
    string $destPlaceId,
    array  $intermediates,    // array of ['placeId' => '...'] objects; empty array = direct trip
    string $departureLondon   // format: 'Y-m-d H:i:s' (Europe/London time)
): array                      // returns ['duration' => int, 'static_duration' => int]
                              // throws Exception on any failure
```

**Implementation steps:**

1. **Convert departure time to UTC ISO 8601:**
   ```php
   $utc = (new DateTime($departureLondon, new DateTimeZone('Europe/London')))
              ->setTimezone(new DateTimeZone('UTC'))
              ->format('Y-m-d\TH:i:s\Z');
   ```

2. **Build JSON payload:**
   ```json
   {
     "origin":      { "placeId": "..." },
     "destination": { "placeId": "..." },
     "intermediates": [ { "placeId": "..." }, ... ],
     "travelMode": "DRIVE",
     "routingPreference": "TRAFFIC_AWARE_OPTIMAL",
     "departureTime": "2026-06-15T07:00:00Z"
   }
   ```
   - `intermediates` key must be omitted (or empty array) when no waypoints

3. **cURL call:**
   - URL: `https://routes.googleapis.com/directions/v2:computeRoutes`
   - Method: POST
   - Headers:
     - `Content-Type: application/json`
     - `X-Goog-Api-Key: ROUTES_API_KEY`
     - `X-Goog-FieldMask: routes.duration,routes.staticDuration`
   - Set `CURLOPT_RETURNTRANSFER => true`
   - Set `CURLOPT_TIMEOUT => 10`

4. **Parse response:**
   - On cURL error: `throw new Exception('cURL error: ' . curl_error($ch));`
   - Decode JSON; on HTTP non-200 or missing `routes[0]`: `throw new Exception('API error: ' . $statusCode);`
   - Duration is returned as a string with `s` suffix: `(int)rtrim($response['routes'][0]['duration'], 's')`
   - Static duration same: `(int)rtrim($response['routes'][0]['staticDuration'], 's')`
   - Return `['duration' => $duration, 'static_duration' => $staticDuration]`

**Notes:**
- No `require_once` of config.php here — `ROUTES_API_KEY` is already defined before this file is included by `search.php`
- All errors must throw `Exception` (not return false/null) — caller catches per-slot

---

## 6. `header.php`

**Purpose:** Session init, CSRF setup, helper function, DB include, HTML head + navbar.

**Logic in order:**
1. `if (session_status() === PHP_SESSION_NONE) session_start();` — guard prevents double-start when index.php/trip.php call `session_start()` before including this file
2. `if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }` — generate once per session
3. Define `function h($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }` — shorthand used everywhere in HTML output
4. `require_once 'db.php';` — makes `$pdo` available to the page (guard in db.php prevents re-include)
5. HTML `<!DOCTYPE html>`, `<html>`, `<head>`:
   - `<meta charset="utf-8">`
   - `<meta name="viewport" content="width=device-width, initial-scale=1">`
   - `<title>Travel ETA</title>`
   - `<link rel="stylesheet" href="assets/custom.css">`
   - `<link rel="stylesheet" href="assets/bootstrap-icons.css">`
   - `<script src="assets/bootstrap.bundle.min.js" defer></script>`
6. `<body>` + Bootstrap navbar with "Travel ETA" brand linking to `index.php`

**Notes:**
- `search.php` does NOT include `header.php` — it handles its own `session_start()`
- Navbar active state: check `basename($_SERVER['PHP_SELF'])` to highlight current page if needed

---

## 7. `footer.php`

**Purpose:** Close HTML, define Places JS functions, load Google Maps script.

**Structure (order is critical):**

1. Closing content `</div>` (if main container opened in header.php)
2. **Define `window.initPlaces` and `attachAutocomplete` in a `<script>` block BEFORE the Maps `<script>` tag:**

   ```javascript
   function attachAutocomplete(inputEl) {
       // Create PlaceAutocompleteElement and append after inputEl (or in a wrapper)
       const pac = new google.maps.places.PlaceAutocompleteElement();
       // Insert into DOM adjacent to inputEl
       // On 'gmp-placeselect': populate hidden fields (place_id, display_name, lat, lng)
       //   - Read field name prefix from inputEl's data attribute to find paired hidden fields
       // On inputEl's 'input' event: clear hidden fields (stale place_id prevention)
   }

   function initPlaces() {
       // Called by Google as callback= once library is ready
       document.querySelectorAll('.autocomplete-input').forEach(el => {
           attachAutocomplete(el);
       });
       // Do NOT call initPlaces() again from anywhere else
   }
   ```

3. **Google Maps `<script>` tag — AFTER the JS block above:**
   ```html
   <script async
     src="https://maps.googleapis.com/maps/api/js?key=<?= h(PLACES_API_KEY) ?>&libraries=places&v=beta&callback=initPlaces">
   </script>
   ```
   - `async` not `defer` — deferred scripts are not ready when inline footer JS runs
   - `callback=initPlaces` — Google fires this after the library loads

4. `</body></html>`

**Hidden field naming convention (`attachAutocomplete` must follow this):**
- Origin: `origin_place_id`, `origin_display`, `origin_lat`, `origin_lng`
- Destination: `destination_place_id`, `destination_display`, `destination_lat`, `destination_lng`
- Waypoints: `waypoints[N][place_id]`, `waypoints[N][display_name]`, `waypoints[N][lat]`, `waypoints[N][lng]`

**Waypoint add button JS (in index.php, not footer.php):**
- Clone a waypoint input row, assign new index N
- Call `attachAutocomplete(newInputEl)` only — never re-call `initPlaces()`

---

## 8. `index.php`

**Purpose:** Dashboard (trip list) + new trip form.

**Top of file (before any HTML):**
```php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
```

**POST handler (runs before `require_once 'header.php'`):**
1. Verify `$_POST['csrf_token'] === $_SESSION['csrf_token']` — if mismatch: flash danger, `header('Location: index.php'); exit;`
2. Validate trip name non-empty — if empty: flash danger, redirect, exit
3. Validate `origin_place_id`, `destination_place_id` non-empty — if empty: flash danger, redirect, exit
4. Validate each waypoint's `place_id` non-empty (if any waypoints submitted) — if empty: flash danger, redirect, exit
5. Compute `trip_hash`:
   ```php
   $wpPlaceIds = array_column($waypoints, 'place_id'); // already sorted by stop_order from form
   $hashInput = $originPlaceId . '|' . implode('|', $wpPlaceIds) . '|' . $destPlaceId;
   // zero waypoints: "ORIGIN_ID||DEST_ID" (double pipe — intentional)
   $tripHash = md5($hashInput);
   ```
6. Check for existing trip with same hash — if found: flash "This exact route is already saved — showing existing trip", `header('Location: trip.php?id=' . $existingId); exit;`
7. INSERT into `trips` (set `created_at` = `date('Y-m-d H:i:s')` — Europe/London from config)
8. INSERT each waypoint into `trip_waypoints` with `stop_order` = 0-based index
9. Flash success, `header('Location: trip.php?id=' . $newTripId); exit;`

**GET handler (after POST block):**
```php
require_once 'header.php';
```
Then:
1. Fetch flash from `$_SESSION['flash']`, unset immediately, render if set (wrapped in `h()`)
2. Query all trips `ORDER BY created_at DESC`
3. Render trip list: name, origin → destination, link to `trip.php?id={id}`
4. Empty state: "No trips yet — add your first trip below"
5. New Trip form:
   - Trip name text input (required)
   - Origin: `<input class="autocomplete-input" ...>` + hidden fields (`origin_place_id`, `origin_display`, `origin_lat`, `origin_lng`)
   - Waypoints section: initially empty; "Add waypoint" button appends a new row with `.autocomplete-input` + hidden fields (`waypoints[N][place_id]`, `waypoints[N][display_name]`, `waypoints[N][lat]`, `waypoints[N][lng]`)
   - Destination: same pattern as origin
   - CSRF hidden: `<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">`
   - Submit button: `btn btn-primary`

```php
require_once 'footer.php';
```

---

## 9. `trip.php`

**Purpose:** Trip detail — summary, run-search form, freshness indicator, search history, drill-down.

**Top of file (before any HTML):**
```php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
```

**Validation + redirect (before `require_once 'header.php'`):**
1. Read `$_GET['id']`, cast to int; if `<= 0`: flash "Trip not found", `header('Location: index.php'); exit;`
2. Query trips by id; if not found: flash "Trip not found", `header('Location: index.php'); exit;`

**After validation:**
```php
require_once 'header.php';
```

**Page body:**
1. Trip summary: name, origin → destination (with waypoints in `stop_order` order)
2. Fetch flash, unset, render if set
3. Run Search form (POST to `search.php`):
   - `<input type="hidden" name="trip_id" value="<?= $trip['id'] ?>">`
   - Target arrival: `<input type="datetime-local" name="target_arrival" required>`
   - Est. journey time: `<input type="number" name="estimated_duration_minutes" min="5" required>` (minutes)
   - CSRF hidden: `<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">`
   - Submit button: `btn btn-teal` + `bi-arrow-repeat`
4. Freshness banner (only when most recent search has `best_departure IS NOT NULL`):
   - `best_departure` in past → no banner
   - `best_departure` within 2h → `alert alert-teal` "Live traffic — high confidence"
   - `best_departure` > 2h away → `alert alert-orange` "Re-run closer to departure for better accuracy"
5. Search history table (newest first): `run_at | target_arrival | best_departure | estimated_arrival | delta | duration`
   - `best_departure` NULL → show "No result — all slots failed" in that row
   - Delta badge classes: see context class map in PLAN.md
   - Each row: link to `?id={trip_id}&search={search_id}` for drill-down
6. Drill-down (if `?search=` param present):
   - Cast to int, validate `> 0`
   - Query `searches` where `id = search_id AND trip_id = $tripId` — **IDOR check**: if not found or trip_id mismatch, silently ignore (do not load iterations)
   - If valid: load iterations, display table below history: `departure_time | estimated_arrival | delta | duration | static_duration | flags`
   - Flags: best (`bi-star-fill`), skipped (`bi-skip-forward-fill`), error (`bi-x-circle-fill`)
   - Row classes: `table-green-100` (best), `table-secondary` (skipped), `table-orange-100` (error)
   - Delta badge per iteration: orange when `is_best = 1 AND searches.warning IS NOT NULL`; red when `delta_seconds > 0 AND NOT (is_best AND warning)` — join `searches` row to get `warning` field

```php
require_once 'footer.php';
```

---

## 10. `search.php`

**Purpose:** POST-only processor. No HTML output. Always redirects.

**Top of file:**
```php
session_start();
if (!file_exists('config.php')) {
    http_response_code(500);
    echo 'Application not configured. Please contact the administrator.';
    exit;
}
require_once 'db.php';
require_once 'routes_api.php';
set_time_limit(120);
```

**Processing steps (in order):**

**1. CSRF validation:**
```php
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid form submission.'];
    header('Location: index.php'); exit;
}
```

**2. Input validation:**
- `trip_id`: cast to int, must be `> 0` — else flash danger, redirect `index.php`, exit
- `target_arrival`: validate with `DateTime::createFromFormat('Y-m-d\TH:i', $_POST['target_arrival'])` (datetime-local format); if invalid: flash danger, redirect `index.php`, exit; re-format as `'Y-m-d H:i:s'`
- `estimated_duration_minutes`: cast to int, must be `>= 5` — else flash danger, redirect `index.php`, exit

**3. Load trip:**
```php
$trip = $pdo->prepare('SELECT * FROM trips WHERE id = ?')->execute([$tripId])...;
if (!$trip) { $_SESSION['flash'] = [...'Trip not found'...]; header('Location: index.php'); exit; }
```

**4. Load waypoints:**
```php
$waypoints = ...'SELECT * FROM trip_waypoints WHERE trip_id = ? ORDER BY stop_order ASC'...;
```

**5. Build intermediates array:**
```php
$intermediates = array_map(fn($wp) => ['placeId' => $wp['place_id']], $waypoints);
```

**6. Calculate 13 departure slots:**
```php
$centre = new DateTime($targetArrival, new DateTimeZone('Europe/London'));
$centre->modify("-{$estimatedDurationMinutes} minutes");
$slots = [];
for ($i = -6; $i <= 6; $i++) {
    $slot = clone $centre;
    $slot->modify("{$i} minutes" * 10); // i.e. ($i * 10) minutes
    $slots[] = $slot->format('Y-m-d H:i:s');
}
// Result: 13 slots from (centre − 60min) to (centre + 60min) in 10min steps
```

**7. Loop over slots:**
```php
$now = new DateTime('now', new DateTimeZone('Europe/London'));
$results = []; // accumulate successful iteration data

foreach ($slots as $slotLondon) {
    $slotDt = new DateTime($slotLondon, new DateTimeZone('Europe/London'));

    if ($slotDt <= $now) {
        // Record skipped iteration — departure_time set, all others NULL, skipped=1
        continue;
    }

    try {
        $apiResult = call_routes_api(
            $trip['origin_place_id'],
            $trip['destination_place_id'],
            $intermediates,
            $slotLondon
        );
        // Parse durations
        $durationSec       = $apiResult['duration'];
        $staticDurationSec = $apiResult['static_duration'];
        // Compute estimated arrival
        $estArrival = clone $slotDt;
        $estArrival->modify("+{$durationSec} seconds");
        $estArrivalStr = $estArrival->format('Y-m-d H:i:s');
        // Compute delta
        $targetDt = new DateTime($targetArrival, new DateTimeZone('Europe/London'));
        $deltaSec = $estArrival->getTimestamp() - $targetDt->getTimestamp();
        // Store result for post-loop scoring
        $results[] = [
            'departure_time'         => $slotLondon,
            'estimated_arrival'      => $estArrivalStr,
            'duration_seconds'       => $durationSec,
            'static_duration_seconds'=> $staticDurationSec,
            'delta_seconds'          => $deltaSec,
        ];
    } catch (Exception $e) {
        // Record error iteration — error=1, error_message set
    }
}
```

**8. Post-loop scoring:**
```php
$onTime  = array_filter($results, fn($r) => $r['delta_seconds'] <= 0);
$warning = null;
$best    = null;

if (!empty($onTime)) {
    // Pick slot minimising abs(delta_seconds + 900); tiebreak = later departure_time
    usort($onTime, function($a, $b) {
        $scoreA = abs($a['delta_seconds'] + 900);
        $scoreB = abs($b['delta_seconds'] + 900);
        if ($scoreA !== $scoreB) return $scoreA - $scoreB;
        return strcmp($b['departure_time'], $a['departure_time']); // later = better
    });
    $best = $onTime[0];
} elseif (!empty($results)) {
    // All arrived late — pick least-late
    usort($results, fn($a, $b) => $a['delta_seconds'] - $b['delta_seconds']);
    $best    = $results[0];
    $warning = 'All valid slots arrived late';
}
// $best = null means no valid results (all skipped or all errored)
```

**9. Save to DB (always — regardless of outcome):**
- INSERT `searches` row — `best_departure`, `estimated_arrival`, `delta_seconds`, `duration_seconds`, `static_duration_seconds` = NULL when `$best === null`; `warning` column from `$warning`
- INSERT all 13 `iterations` rows — `is_best = 1` on the row matching `$best['departure_time']`; `skipped = 1` / `error = 1` as flagged during loop; all timestamps and NULLs as computed

**10. Set flash and redirect:**

| Condition | Flash type | Flash message |
|---|---|---|
| All 13 slots skipped | `danger` | "All departure times are in the past" |
| All called slots errored | `danger` | "All API calls failed — check API key and quota" |
| Mix of skipped + errored, zero successful | `danger` | "No valid departure times — all slots were in the past or returned an API error" |
| `$best` found + `$warning` set | `warning` | Summarise best departure + est. arrival + "Note: all slots arrived late — consider an earlier target time" |
| `$best` found, no warning | `success` | Summarise best departure + estimated arrival |

```php
header('Location: trip.php?id=' . $tripId); exit;
```

---

## Flash message rendering (both `index.php` and `trip.php`)

```php
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    echo '<div class="alert alert-' . h($flash['type']) . '">' . h($flash['message']) . '</div>';
}
```

---

## Key cross-cutting rules

| Rule | Detail |
|---|---|
| All `header('Location: ...')` calls | Must be immediately followed by `exit;` |
| All DB writes | PDO prepared statements only — no string interpolation |
| All HTML output of user data | Wrapped in `h()` (= `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) |
| `created_at` / `run_at` | `date('Y-m-d H:i:s')` in PHP — NOT MySQL `NOW()` (avoids MySQL UTC session timezone conflict) |
| `trip_hash` with zero waypoints | `md5("ORIGIN_ID||DEST_ID")` — double pipe is intentional |
| Routes API duration string | `(int)rtrim($val, 's')` to strip suffix before arithmetic |
| `departureTime` to API | Must be UTC ISO 8601: use `DateTime` + `DateTimeZone` conversion, not string manipulation |
| `estimated_duration_minutes` | PHP must enforce `>= 5`; MySQL SMALLINT UNSIGNED does not enforce this lower bound |
| CSRF token | Generated once per session in `header.php`; never regenerated mid-session |
