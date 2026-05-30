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

### Window sizing
- User provides an **estimated journey duration** (minutes) per search run — stored in `searches` table (not `trips`, since each run can target a different time)
- Loop centre: `target_arrival − estimated_duration_minutes`
- Window: centre ± 60 min, 10-minute steps → **13 departure slots**
- This handles journeys of any length (30 min or 5 hours) without blowing up API call count

### Past slot skipping
- Before each API call, check: `if departure_time < NOW(Europe/London) → skip, mark iteration as skipped, do not call API`
- Skipped slots are still recorded in `iterations` with `skipped = 1` so history is complete
- If all slots are in the past, abort the search with a user-facing error

### Execution
- Run **all valid (future) slots**, pick global best from non-skipped results
- `set_time_limit(120)` at top of `search.php` to prevent shared-host 30s timeout
- ~13 API calls max per search run (fewer if some slots are in the past)

---

## Address Handling (Option C — Autocomplete + Place ID)
- Front-end: **`PlaceAutocompleteElement`** (new Places JS API) on every address field
- Script loaded with `v=beta` channel — required for `PlaceAutocompleteElement`:
  ```html
  <script src="https://maps.googleapis.com/maps/api/js?key=PLACES_KEY&libraries=places&v=beta" defer></script>
  ```
- On selection, hidden fields store: `place_id`, `lat`, `lng`, `display_name`
- Session tokens handled automatically by `PlaceAutocompleteElement` — no manual token management needed
- **Dynamic waypoints:** when JS adds a new waypoint input, a new `PlaceAutocompleteElement` instance must be explicitly created and bound to that input — plain `<input>` fields added dynamically will NOT get autocomplete automatically
- Routes API called with `placeId` (not raw text address)
- `trip_hash` = `MD5(origin_place_id + '|' + ordered_waypoint_place_ids + '|' + destination_place_id)` — **route identity only**, no target time in hash
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
| Delta badge | Arrived within ideal window | `badge bg-green-500 text-white` |
| Delta badge | Arrived early but outside window | `badge bg-teal-400 text-white` |
| Delta badge | All valid slots late (least-late picked) | `badge bg-orange-500 text-white` |
| Delta badge | Late arrival | `badge bg-red-600 text-white` |
| Iterations table row | Best slot | `table-green-100` |
| Iterations table row | Skipped (past departure) | `table-secondary` |
| Iterations table row | API error | `table-orange-100` |
| Button | Run Search | `btn btn-teal` + `bi-arrow-repeat` |
| Button | New Trip | `btn btn-primary` |
| Button | Delete trip (future) | `btn btn-danger` |

---

## Database Schema (4 tables)

### `trips` — route identity only (no target time)
```sql
id                          INT AUTO_INCREMENT PRIMARY KEY,
name                        VARCHAR(255),
origin_display              VARCHAR(500),
origin_place_id             VARCHAR(255),
origin_lat                  DECIMAL(10,7),
origin_lng                  DECIMAL(10,7),
destination_display         VARCHAR(500),
destination_place_id        VARCHAR(255),
destination_lat             DECIMAL(10,7),
destination_lng             DECIMAL(10,7),
trip_hash                   VARCHAR(32),        -- MD5(origin_place_id|wp_place_ids|destination_place_id)
created_at                  DATETIME,           -- set in PHP (Europe/London); avoids MySQL UTC session timezone conflict
UNIQUE KEY uq_trip_hash (trip_hash)             -- prevents duplicate routes; on conflict redirect to existing trip
```
> `target_arrival` and `estimated_duration_minutes` live on `searches`, not `trips` — each run can target a different arrival time on the same saved route.

### `trip_waypoints`
```sql
id          INT AUTO_INCREMENT PRIMARY KEY,
trip_id     INT,
display_name VARCHAR(500),
place_id    VARCHAR(255),
lat         DECIMAL(10,7),
lng         DECIMAL(10,7),
stop_order  TINYINT,
FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
```
> Zero rows = direct trip (no waypoints). Maps directly to Routes API `intermediates[]` array ordered by `stop_order`.

### `searches` — one row per search run
```sql
id                          INT AUTO_INCREMENT PRIMARY KEY,
trip_id                     INT,
target_arrival              DATETIME,           -- Europe/London; set per run, can differ between runs
estimated_duration_minutes  SMALLINT UNSIGNED,  -- min 5; centres the ±60min slot window
run_at                      DATETIME,           -- set in PHP (Europe/London); avoids MySQL UTC session timezone conflict
best_departure              DATETIME,           -- NULL if all slots skipped/errored
estimated_arrival           DATETIME,           -- NULL if all slots skipped/errored
delta_seconds               INT,                -- signed: negative = early (good), positive = late (bad); NULL if no result
duration_seconds            INT,
static_duration_seconds     INT,
warning                     VARCHAR(255),       -- e.g. 'All valid slots arrived late'
FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
```

### `iterations`
```sql
id                      INT AUTO_INCREMENT PRIMARY KEY,
search_id               INT,
departure_time          DATETIME,
estimated_arrival       DATETIME,
duration_seconds        INT,
static_duration_seconds INT,    -- traffic vs no-traffic comparison per slot
delta_seconds           INT,    -- signed: negative = early, positive = late
is_best                 TINYINT(1) DEFAULT 0,
skipped                 TINYINT(1) DEFAULT 0,  -- 1 = departure was in the past, API not called
error                   TINYINT(1) DEFAULT 0,  -- 1 = API call attempted but failed (network/rate limit/invalid place)
error_message           VARCHAR(255),          -- human-readable reason for error
FOREIGN KEY (search_id) REFERENCES searches(id) ON DELETE CASCADE
```

---

## UX Flow

```
index.php  (dashboard)
  ├── List all saved trips (name, origin → destination)
  └── "New Trip" form
        ├── Trip name           (required, non-empty enforced client + server side)
        ├── Origin              (PlaceAutocompleteElement + hidden place_id/lat/lng)
        ├── Waypoints[]         (dynamic add/remove — each added input gets a new PlaceAutocompleteElement bound to it via JS)
        ├── Destination         (PlaceAutocompleteElement + hidden place_id/lat/lng)
        ├── CSRF token          (hidden field, validated server-side)
        ├── Server-side: reject if trip name empty
        ├── Server-side: reject if any place_id is empty (user typed but didn't select from dropdown)
        ├── Server-side: if trip_hash already exists → redirect to existing trip (no duplicate saved)
        └── Submit → saves trips + trip_waypoints → redirect to trip.php

trip.php  (trip detail)
  ├── Trip summary: name, origin, ordered waypoints, destination
  ├── "Run Search" form (POST to search.php):
  │     ├── Target arrival      (datetime-local — Europe/London)
  │     ├── Est. journey time   (numeric, minutes, min 5 enforced client+server side)
  │     └── CSRF token          (hidden field, validated server-side)
  ├── Flash message display (from $_SESSION['flash'] — cleared after display)
  ├── Freshness indicator on most recent search:
  │     ≤ 2h to best_departure  →  "Live traffic — high confidence"
  │     >  2h to best_departure  →  "Re-run closer to departure for better accuracy"
  ├── Search history table (searches, newest first):
  │     run_at | target_arrival | best_departure | estimated_arrival | delta | duration
  │     Handle NULL best_departure/estimated_arrival gracefully (show "No result — all slots failed")
  └── Drill-down: click a run → show iterations table for that search (show skipped/error states)

search.php  (no UI — processing only)
  ├── session_start() — required for CSRF validation and flash messages
  ├── set_time_limit(120) — prevent shared-host timeout on 13 sequential cURL calls
  ├── Check config.php exists — if not, abort with safe message (no server path disclosed)
  ├── Validate CSRF token from POST against $_SESSION['csrf_token'] — reject if mismatch
  ├── Validate trip_id (positive integer), target_arrival (valid future datetime), estimated_duration_minutes (integer ≥ 5)
  ├── Loads trip + waypoints from DB (ordered by stop_order)
  ├── (timezone already set globally in config.php)
  ├── Builds intermediates[] array for API payload
  ├── Calculates 13 slots: centre = target_arrival − estimated_duration_minutes, ±60min, 10min steps
  ├── For each slot:
  │     ├── If departure_time < NOW → record as skipped=1, do not call API, continue loop
  │     ├── Else → call routes_api.php → on API failure → record error=1, error_message, continue loop
  │     └── On success → parse duration string (strip 's' suffix with rtrim) → compute signed delta vs (target−15min)
  ├── If ALL slots skipped → flash error "All departure times are in the past", save searches row with NULLs
  ├── If ALL slots errored → flash error "All API calls failed — check API key and quota", save searches row with NULLs
  ├── From valid results: discard late arrivals, pick slot closest to (target−15min)
  │     └── If all valid slots are late → pick least-late, set searches.warning
  ├── Saves one searches row (NULLs if no result) + 13 iterations rows (is_best + skipped + error flagged)
  ├── Stores result message in $_SESSION['flash'], unset after display in trip.php
  └── Redirects → trip.php?id={trip_id}
```

---

## File Structure

```
Travel_ETA/
├── index.php         # Dashboard + new trip form (PlaceAutocompleteElement)
├── trip.php          # Trip detail — stops, search history, re-run, drill-down
├── search.php        # POST handler — loop, save results, redirect
├── routes_api.php    # cURL wrapper — builds payload with placeId, converts Europe/London → UTC ISO 8601 for departureTime
├── db.php            # PDO connection + 4-table bootstrap (CREATE TABLE IF NOT EXISTS)
├── header.php        # Shared HTML head, asset links, navbar, session_start(), config include
├── footer.php        # Shared closing tags, JS init for PlaceAutocompleteElement
├── config.php        # API keys + DB credentials (gitignored — never committed)
├── config.sample.php # Committed template — copy to config.php on server and fill in values
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
- **CSRF tokens** — generated per session, embedded as hidden field in all forms (`index.php` new-trip form, `trip.php` run-search form), validated in every POST handler
- All DB writes use PDO prepared statements (no string interpolation in queries)
- **Places API key** — HTTP-referrer restricted (browser) + **Places API permission only** (Routes API must NOT be enabled on this key)
- **Routes API key** — server IP restricted + Routes API permission only
- All POST inputs validated server-side; place_id fields checked non-empty; estimated_duration_minutes ≥ 5 enforced
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
