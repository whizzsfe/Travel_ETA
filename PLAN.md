# Travel_ETA — Project Plan

## Purpose
Web-hosted PHP app that finds the best departure time for a trip by iterating through departure slots and comparing estimated arrival against a user-defined target arrival time.

---

## Technology Stack
- **Backend:** PHP + cURL
- **API:** Google Maps Routes API v2 (`POST https://routes.googleapis.com/directions/v2:computeRoutes`)
- **Routing preference:** `TRAFFIC_AWARE_OPTIMAL`
- **Field mask header:** `X-Goog-FieldMask: routes.duration,routes.staticDuration`
- **Address resolution:** Google **`PlaceAutocompleteElement`** (new Places API, not deprecated `Autocomplete` widget) → `placeId` passed to Routes API
- **Database:** MySQL via PDO (prepared statements)
- **Front-end:** Bootstrap 5 (local `assets/custom.css` + `assets/bootstrap.bundle.min.js`), Bootstrap Icons (local `assets/bootstrap-icons.css` + `assets/fonts/`), Google Places JS API — **no CDN dependencies**
- **Dark mode:** `custom.css` includes `[data-bs-theme=dark]` support — available if needed later
- **Timezone:** `date_default_timezone_set('Europe/London')` called in `config.php` so it applies to **every page**, not just `search.php`. Handles GMT/BST automatically via PHP `DateTimeZone`. UI displays London local time.
  - `departureTime` sent to the Routes API **must be UTC ISO 8601** (e.g. `2026-06-15T07:00:00Z`)
  - Conversion in PHP: `(new DateTime($slot, new DateTimeZone('Europe/London')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')`
  - This ensures Google uses the correct real-world traffic snapshot for that London departure moment — critical for South London departures where peak-hour traffic patterns (e.g. 08:00 BST vs 08:00 UTC) differ by an hour
  - API `duration` response is returned as a string with an `s` suffix (e.g. `"1234s"`) — strip suffix with `(int)rtrim($duration, 's')` before arithmetic

---

## Search Loop Logic

### Target window
- **Single scoring rule:** pick the slot whose `estimated_arrival` is **closest to `target_arrival − 15min` without `estimated_arrival` exceeding `target_arrival`**
- Any slot where `estimated_arrival > target_arrival` is discarded as invalid
- This naturally favours arriving 15 min early as the sweet spot while never accepting a late result
- If all valid slots overshoot (all late) → pick the least-late slot and record a warning
- Tiebreaker: if two valid slots have equal scoring distance from `target_arrival − 15min`, prefer the **later** `departure_time` (less time waiting at origin)

### Window sizing
- User provides an **estimated journey duration** (minutes) per search run — stored in `searches` table (not `trips`, since each run can target a different time)
- Loop centre: `target_arrival − estimated_duration_minutes`
- Window: centre ± 60 min, 10-minute steps → **13 departure slots**
- This handles journeys of any length (30 min or 5 hours) without blowing up API call count

### Past slot skipping
- Before each API call, check: `if departure_time < NOW(Europe/London) → skip, mark iteration as skipped, do not call API`
- Skipped slots are still recorded in `iterations` with `skipped = 1` so history is complete
- If all slots are in the past, the loop still completes (all 13 iterations recorded as skipped), then the post-loop check triggers a user-facing error

### Execution
- Run **all valid (future) slots**, pick global best from non-skipped results
- `set_time_limit(120)` at top of `search.php` to prevent shared-host 30s timeout
- ~13 API calls max per search run (fewer if some slots are in the past)

---

## Address Handling (Option C — Autocomplete + Place ID)
- Front-end: **`PlaceAutocompleteElement`** (new Places JS API) on every address field
- Script loaded with `v=beta` channel + `async` + `callback=initPlaces` — **do NOT use `defer`** (deferred scripts are not ready when inline footer JS runs):
  ```html
  <script async
    src="https://maps.googleapis.com/maps/api/js?key=PLACES_KEY&libraries=places&v=beta&callback=initPlaces">
  </script>
  ```
  (`PLACES_KEY` = browser-facing Places key only)
- `window.initPlaces` function defined in `footer.php` BEFORE the Maps `<script>` tag; Google fires the callback once the library is ready
- On selection, hidden fields store: `place_id`, `lat`, `lng`, `display_name`
- Session tokens handled automatically by `PlaceAutocompleteElement` — no manual token management needed
- JS exposes two functions:
  - `initPlaces()` — fired by Google `callback=initPlaces`; calls `attachAutocomplete(el)` for every `.autocomplete-input` on the page at load; must NOT be called again after load
  - `attachAutocomplete(inputEl)` — creates and binds one `PlaceAutocompleteElement` to `inputEl`; on `gmp-placeselect` populates the paired hidden fields; on element's `input` event **clears hidden fields** so stale `place_id` cannot reach the server if user edits text without re-selecting
  - Waypoint-add JS calls **`attachAutocomplete(newInputEl)`** only — never re-calls `initPlaces()` (would double-bind existing elements)
- Hidden field naming:
  - Origin/destination: `origin_place_id`, `origin_display`, `origin_lat`, `origin_lng`; `destination_place_id`, `destination_display`, `destination_lat`, `destination_lng`
  - Waypoints: PHP array syntax `waypoints[N][place_id]`, `waypoints[N][display_name]`, `waypoints[N][lat]`, `waypoints[N][lng]` — N = 0-based JS index → becomes `stop_order` in DB
- Routes API called with `placeId` (not raw text address)
- `trip_hash` = `MD5(origin_place_id + '|' + ordered_waypoint_place_ids + '|' + destination_place_id)` — **route identity only**, no target time in hash
  - `ordered_waypoint_place_ids` = waypoint place_ids joined with `|`, ordered by `stop_order` ascending
  - Zero waypoints: input string = `"ORIGIN_ID||DEST_ID"` (double pipe — intentional and deterministic)
- Two API keys recommended:
  - **Places key** — HTTP-referrer restricted (browser) + **Places API (New) + Maps JavaScript API** permissions only (Routes API must NOT be on this key)
  - **Routes key** — server IP restricted + Routes API permission only
- Approximate cost: `TRAFFIC_AWARE_OPTIMAL` + 13 calls per search run ≈ $0.07–$0.13 per search at current Routes API pricing (Advanced tier)

---

## Front-end Assets & Styling

### Asset loading (all local — no CDN)
```html
<link rel="stylesheet" href="assets/custom.css">
<link rel="stylesheet" href="assets/bootstrap-icons.css">
<script src="assets/bootstrap.bundle.min.js" defer></script>
```

### Bootstrap Icons — key usage
| Context | Icon class |
|---|---|
| Origin | `bi-geo-alt-fill` |
| Destination | `bi-flag-fill` |
| Waypoint | `bi-pin-map-fill` |
| Departure time | `bi-box-arrow-right` |
| Arrival time / clock | `bi-clock-fill` |
| Re-run / refresh | `bi-arrow-repeat` |
| Warning | `bi-exclamation-triangle-fill` |
| Error / failed | `bi-x-circle-fill` |
| Skipped slot | `bi-skip-forward-fill` |
| Live / broadcast | `bi-broadcast` |
| History | `bi-clock-history` |
| Best result star | `bi-star-fill` |

### Context class map
| UI element | State | Class |
|---|---|---|
| Flash / alert | Search succeeded | `alert alert-success` |
| Flash / alert | All slots late (warning) | `alert alert-warning` |
| Flash / alert | All slots failed/errored | `alert alert-danger` |
| Flash / alert | All slots in past | `alert alert-danger` |
| Freshness banner | ≤ 2h to departure — live data | `alert alert-teal` |
| Freshness banner | > 2h to departure — stale | `alert alert-orange` |
| Delta badge | `delta_seconds` in [−900, 0] — arrived 0–15 min early (ideal) | `badge bg-green-500 text-white` |
| Delta badge | `delta_seconds` < −900 — arrived > 15 min early | `badge bg-teal-400 text-white` |
| Delta badge | `delta_seconds` > 0 + `searches.warning` set — least-late result | `badge bg-orange-500 text-white` |
| Delta badge | `delta_seconds` > 0 — arrived late | `badge bg-red-600 text-white` |

> **Delta badge in iterations drill-down:** `searches.warning` is a searches-level field, not per-iteration. For individual iteration rows, use orange when `is_best = 1 AND searches.warning IS NOT NULL`; use red when `delta_seconds > 0 AND NOT (is_best = 1 AND searches.warning IS NOT NULL)`.
| Iterations table row | Best slot | `table-green-100` |
| Iterations table row | Skipped (past departure) | `table-secondary` |
| Iterations table row | API error | `table-orange-100` |
| Button | Run Search | `btn btn-teal` + `bi-arrow-repeat` |
| Button | New Trip | `btn btn-primary` |
| Button | Delete trip (future) | `btn btn-danger` |

---

## Database Schema (4 tables)

> **All tables must use `ENGINE=InnoDB`** — required for foreign key enforcement. Some shared hosts default to MyISAM which silently ignores FK constraints. Specify in each `CREATE TABLE` statement.

### `trips` — route identity only (no target time)
```sql
id                          INT AUTO_INCREMENT PRIMARY KEY,
name                        VARCHAR(255) NOT NULL,
origin_display              VARCHAR(500) NOT NULL,
origin_place_id             VARCHAR(255) NOT NULL,
origin_lat                  DECIMAL(10,7) NOT NULL,
origin_lng                  DECIMAL(10,7) NOT NULL,
destination_display         VARCHAR(500) NOT NULL,
destination_place_id        VARCHAR(255) NOT NULL,
destination_lat             DECIMAL(10,7) NOT NULL,
destination_lng             DECIMAL(10,7) NOT NULL,
trip_hash                   VARCHAR(32) NOT NULL,    -- MD5(origin_place_id|wp_place_ids|destination_place_id)
created_at                  DATETIME NOT NULL,        -- set in PHP (Europe/London); avoids MySQL UTC session timezone conflict
UNIQUE KEY uq_trip_hash (trip_hash)                  -- prevents duplicate routes; on conflict redirect to existing trip
```
> `target_arrival` and `estimated_duration_minutes` live on `searches`, not `trips` — each run can target a different arrival time on the same saved route.

### `trip_waypoints`
```sql
id           INT AUTO_INCREMENT PRIMARY KEY,
trip_id      INT NOT NULL,
display_name VARCHAR(500) NOT NULL,
place_id     VARCHAR(255) NOT NULL,
lat          DECIMAL(10,7) NOT NULL,
lng          DECIMAL(10,7) NOT NULL,
stop_order   TINYINT UNSIGNED NOT NULL,
FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
```
> Zero rows = direct trip (no waypoints). Maps directly to Routes API `intermediates[]` array ordered by `stop_order`.

### `searches` — one row per search run
```sql
id                          INT AUTO_INCREMENT PRIMARY KEY,
trip_id                     INT NOT NULL,
target_arrival              DATETIME NOT NULL,  -- Europe/London; set per run, can differ between runs
estimated_duration_minutes  SMALLINT UNSIGNED NOT NULL,  -- min 5; UNSIGNED prevents negatives but values 1–4 not blocked by MySQL — PHP must enforce ≥ 5 before insert; centres the ±60min slot window
run_at                      DATETIME NOT NULL,  -- set in PHP (Europe/London); avoids MySQL UTC session timezone conflict
best_departure              DATETIME,           -- NULL if all slots skipped/errored
estimated_arrival           DATETIME,           -- NULL if all slots skipped/errored
delta_seconds               INT,                -- signed: (estimated_arrival − target_arrival) in seconds; negative = early (arrived before target), positive = late; NULL if no result
duration_seconds            INT,                -- NULL if no result
static_duration_seconds     INT,                -- NULL if no result
warning                     VARCHAR(255),       -- e.g. 'All valid slots arrived late'
FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
```

### `iterations`
```sql
id                      INT AUTO_INCREMENT PRIMARY KEY,
search_id               INT NOT NULL,
departure_time          DATETIME NOT NULL,  -- always set (the slot time, even for skipped/error rows)
estimated_arrival       DATETIME,           -- NULL if skipped or error
duration_seconds        INT,                -- NULL if skipped or error
static_duration_seconds INT,                -- NULL if skipped or error; traffic vs no-traffic comparison per slot
delta_seconds           INT,                -- NULL if skipped or error; signed: (estimated_arrival − target_arrival) in seconds; negative = early, positive = late
is_best                 TINYINT(1) NOT NULL DEFAULT 0,
skipped                 TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = departure was in the past, API not called
error                   TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = API call attempted but failed (network/rate limit/invalid place)
error_message           VARCHAR(255),          -- human-readable reason for error
FOREIGN KEY (search_id) REFERENCES searches(id) ON DELETE CASCADE
```

---

## UX Flow

```
index.php  (dashboard — handles its own POST at top of file before any HTML output)
  ├── GET: List all saved trips ordered newest first (ORDER BY created_at DESC): name, origin → destination
  │     └── Empty state: if no trips saved, show “No trips yet — add your first trip below”
  ├── Flash message display (from $_SESSION['flash'] — cleared after display)
  └── "New Trip" form (POST handled at top of same file)
        ├── Trip name           (required, non-empty enforced client + server side)
        ├── Origin              (PlaceAutocompleteElement + hidden place_id/display_name/lat/lng)
        ├── Waypoints[]         (dynamic add/remove — each added input gets a new PlaceAutocompleteElement bound to it via JS)
        ├── Destination         (PlaceAutocompleteElement + hidden place_id/display_name/lat/lng)
        ├── CSRF token          (hidden field, validated server-side)
        ├── Server-side: validate CSRF token — if mismatch or absent: flash danger, redirect to index.php, exit
        ├── Server-side: reject if trip name empty
        ├── Server-side: reject if any place_id is empty (user typed but didn't select from dropdown)
        ├── Server-side: if trip_hash already exists → set flash "This exact route is already saved — showing existing trip", redirect to existing trip (no duplicate saved)
        ├── POST validation failure → set flash danger error, redirect to index.php (form state lost — unavoidable with PlaceAutocompleteElement)
        └── POST success → saves trips row + trip_waypoints rows → redirect to trip.php?id={new_trip_id}

trip.php  (trip detail)
  ├── Validate ?id= — must be positive integer and exist in trips; if absent/invalid/not found: set flash "Trip not found", redirect to index.php
  ├── Trip summary: name, origin, ordered waypoints, destination
  ├── "Run Search" form (POST to search.php):
  │     ├── Target arrival      (datetime-local — Europe/London)
  │     ├── Est. journey time   (numeric, minutes, min 5 enforced client+server side)
  │     └── CSRF token          (hidden field, validated server-side)
  ├── Flash message display (from $_SESSION['flash'] — cleared after display)
  ├── Freshness indicator on most recent search (only shown when best_departure IS NOT NULL):
  │     ├── best_departure in the past     →  no banner shown (trip already departed)
  │     ├── ≤ 2h to best_departure         →  "Live traffic — high confidence"  (alert-teal)
  │     └── > 2h to best_departure         →  "Re-run closer to departure for better accuracy"  (alert-orange)
  ├── Search history table (searches, newest first):
  │     run_at | target_arrival | best_departure | estimated_arrival | delta | duration
  │     Handle NULL best_departure/estimated_arrival gracefully (show "No result — all slots failed")
  └── Drill-down: same page, query param ?search={search_id}
        ├── trip.php?id={trip_id}&search={search_id} — no separate file needed
        ├── Validate search_id as positive integer; verify searches.trip_id = current trip_id (IDOR prevention — blocks reading another trip's iterations by guessing IDs)
        ├── When search_id param present and verified: load that search’s iterations, display table below history
        └── Iterations table: departure_time | estimated_arrival | delta | duration | static_duration | flags (best/skipped/error)

search.php  (no UI — processing only — does NOT include header.php or footer.php)
  ├── session_start() — called directly at top (not via header.php)
  ├── Config bootstrap — checks config.php exists before including it; if missing, abort with safe error message (no server path disclosed); must include before any header() redirect
  ├── set_time_limit(120) — prevent shared-host timeout on 13 sequential cURL calls
  ├── Validate CSRF token from POST against $_SESSION['csrf_token'] — reject if mismatch; if mismatch or token absent: flash error, redirect to index.php, exit
  ├── Validate trip_id (positive integer), target_arrival (valid datetime — format check only, need not be future; past-slot skipping handles the rest), estimated_duration_minutes (integer ≥ 5)
  ├── Load trip from DB — if not found: flash "Trip not found", redirect to index.php, exit
  ├── Load waypoints from DB ordered by stop_order
  ├── (timezone already set globally in config.php)
  ├── Builds intermediates[] array for API payload
  ├── Calculates 13 slots: centre = target_arrival − estimated_duration_minutes, ±60min, 10min steps
  ├── For each slot:
  │     ├── If departure_time < NOW → record as skipped=1, do not call API, continue loop
  │     ├── Else → call routes_api.php → on API failure → record error=1, error_message, continue loop
  │     └── On success → parse duration string (strip 's' suffix with rtrim)
  │           → compute estimated_arrival = departure_time + duration_seconds
  │           → compute and store delta_seconds = estimated_arrival − target_arrival (signed seconds)
  ├── If ALL slots skipped → flash "All departure times are in the past", save searches row with NULLs, redirect to trip.php
  ├── If ALL slots errored → flash "All API calls failed — check API key and quota", save searches row with NULLs, redirect to trip.php
  ├── If zero valid results (mixed skipped+errored) → flash "No valid departure times — all slots were in the past or returned an API error", save searches row with NULLs, redirect to trip.php
  ├── Post-loop — from successful (non-skipped, non-error) iterations:
  │     ├── On-time slots: where delta_seconds ≤ 0 — pick the one minimising abs(delta_seconds + 900) (closest to 15min early; tiebreak = later departure)
  │     └── If no on-time slots → pick least-late (minimum positive delta_seconds), set searches.warning = 'All valid slots arrived late'
  ├── Saves one searches row (NULLs if no result) + 13 iterations rows (is_best + skipped + error flagged)
  ├── Set $_SESSION['flash'] with result; rendered by trip.php on next load then unset
  ├── Redirect target summary:
  │     CSRF fail / input validation fail  →  index.php (trip_id not yet trusted)
  │     Trip not found in DB  →  index.php
  │     config.php missing  →  output plain-text safe error and exit (header() impossible — config not loaded)
  │     All other outcomes (no-result or success)  →  trip.php?id={trip_id}
  └── Always call exit() immediately after header('Location: ...')
```

---

## File Structure

```
Travel_ETA/
├── index.php         # Dashboard + new trip form (PlaceAutocompleteElement)
├── trip.php          # Trip detail — stops, search history, re-run, drill-down
├── search.php        # POST handler — loop, save results, redirect
├── routes_api.php    # cURL wrapper — builds Routes API payload, converts Europe/London → UTC ISO 8601 for departureTime
│                     #   Returns: ['duration' => int, 'static_duration' => int] on success
│                     #   Throws: Exception (message = human-readable reason; caught per-slot in search.php)
├── db.php            # PDO connect + CREATE TABLE IF NOT EXISTS ENGINE=InnoDB; does require_once 'config.php' internally
├── header.php        # session_start(), CSRF init (if not set), function h(), require_once 'db.php', HTML head + asset links + navbar (index.php + trip.php only)
├── footer.php        # closing HTML, window.initPlaces + attachAutocomplete JS, Maps <script async> tag (index.php + trip.php only — NOT search.php)
├── config.php        # API keys + DB credentials (gitignored — never committed)
├── config.sample.php # Committed template — copy to config.php on server; defines:
│                     #   DB_HOST, DB_NAME, DB_USER, DB_PASS, ROUTES_API_KEY, PLACES_API_KEY
├── .gitignore        # Excludes config.php
├── schema.sql        # Full reference schema (standalone SQL file)
├── assets/
│   ├── custom.css          # Bootstrap 5 + full named colour scale + context classes
│   ├── bootstrap.bundle.min.js
│   ├── bootstrap-icons.css
│   └── fonts/              # bootstrap-icons.woff / .woff2
└── PLAN.md           # This file
```

---

## Security
- `config.php` never committed — listed in `.gitignore`
- All pages call `session_start()` at top; `config.php` checked for existence before include; missing file shows safe error (no path disclosed)
- **CSRF tokens** — token generated once per session and stored in `$_SESSION['csrf_token']` by `header.php` (if not already set); embedded as hidden field in all forms (`index.php` new-trip form, `trip.php` run-search form); validated in every POST handler; never regenerated mid-session (avoids breaking browser back button)
- All DB writes use PDO prepared statements (no string interpolation in queries)
- **`$_SESSION['flash']` structure**: `['type' => 'success'|'warning'|'danger', 'message' => '...']`; always rendered through `htmlspecialchars()` before output; unset immediately after display
- **XSS prevention** — all user-supplied data rendered in HTML wrapped in `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`; applies to trip name, display names, `warning` text, `error_message`; define `function h($v){ return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }` in `header.php` for shorthand
- **Places API key** — HTTP-referrer restricted (browser) + **Places API (New) + Maps JavaScript API** permissions only (Routes API must NOT be enabled on this key)
- **Routes API key** — server IP restricted + Routes API permission only
- All POST inputs validated server-side; place_id fields checked non-empty; estimated_duration_minutes ≥ 5 enforced (PHP, not MySQL)
- `PlaceAutocompleteElement` handles session tokens automatically — no manual token management needed
- **HTTPS required** — SSL certificate must be active on host; API keys and POST data must not travel in plain text

---

## Deployment (cPanel Git Version Control)

- GitHub repo (`whizzsfe/Travel_ETA`) is already linked to the host via cPanel Git Version Control ✓
- **To deploy:** cPanel → Git Version Control → "Pull or Deploy"
- `config.php` is **never committed** — it must be created manually on the server (copy `config.sample.php`, fill in credentials)
- `db.php` bootstraps all 4 tables with `CREATE TABLE IF NOT EXISTS` — schema self-installs on first page load

### File status in repo

| File | In repo? | Notes |
|---|---|---|
| `index.php` | Yes | |
| `trip.php` | Yes | |
| `search.php` | Yes | |
| `routes_api.php` | Yes | |
| `db.php` | Yes | |
| `config.php` | **No** | Gitignored — create manually on server |
| `config.sample.php` | Yes | Template only, no real credentials |
| `schema.sql` | Yes | Reference only — not needed if using db.php bootstrap |
| `header.php` | Yes | |  
| `footer.php` | Yes | |
| `PLAN.md` | Yes | Dev reference |
| `.gitignore` | Yes | |
| `assets/` | Yes | All local — no CDN |

### Workflow
1. Edit files locally
2. `git push` to `main`
3. cPanel → Git Version Control → Pull or Deploy

---

## Future / Deferred
- Edit waypoints on an existing saved trip
- Delete a trip (and cascade-delete all searches + iterations)
- Post-trip: record actual arrival time, compare vs estimated
- Map display (coordinates already stored — ready for Maps JS API embed)
- User accounts / multi-user support
