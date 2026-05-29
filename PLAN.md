# Travel_ETA — Project Plan

## Purpose
Web-hosted PHP app that finds the best departure time for a trip by iterating through departure slots and comparing estimated arrival against a user-defined target arrival time.

---

## Technology Stack
- **Backend:** PHP + cURL
- **API:** Google Maps Routes API v2 (`POST https://routes.googleapis.com/directions/v2:computeRoutes`)
- **Routing preference:** `TRAFFIC_AWARE_OPTIMAL`
- **Field mask header:** `X-Goog-FieldMask: routes.duration,routes.staticDuration`
- **Address resolution:** Google Places Autocomplete (front-end) → `placeId` passed to Routes API
- **Database:** MySQL via PDO (prepared statements)
- **Front-end:** HTML forms + Google Places JS API

---

## Search Loop Logic
- Window: `[target_arrival - 2h, target_arrival]`
- Step: 10-minute increments → 13 departure slots
- Each slot: `estimated_arrival = departure_time + API duration`
- Score: `|estimated_arrival - target_arrival|` (absolute delta in seconds)
- Strategy: Run **all** slots, pick the global best
- ~13 API calls per search run

---

## Address Handling (Option C — Autocomplete + Place ID)
- Front-end: Google Places Autocomplete on every address field
- On selection, hidden fields store: `place_id`, `lat`, `lng`, `display_name`
- Routes API called with `placeId` (not raw text address)
- `trip_hash` = `MD5(origin_place_id + '|' + ordered_waypoint_place_ids + '|' + destination_place_id)`
- Two API keys recommended:
  - **Places key** — HTTP-referrer restricted (browser)
  - **Routes key** — IP restricted (server)

---

## Database Schema (4 tables)

### `trips`
```sql
id                    INT AUTO_INCREMENT PRIMARY KEY,
name                  VARCHAR(255),
origin_display        VARCHAR(500),
origin_place_id       VARCHAR(255),
origin_lat            DECIMAL(10,7),
origin_lng            DECIMAL(10,7),
destination_display   VARCHAR(500),
destination_place_id  VARCHAR(255),
destination_lat       DECIMAL(10,7),
destination_lng       DECIMAL(10,7),
target_arrival        DATETIME,
trip_hash             VARCHAR(32),
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
INDEX idx_trip_hash (trip_hash)
```

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

### `searches`
```sql
id                      INT AUTO_INCREMENT PRIMARY KEY,
trip_id                 INT,
run_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
best_departure          DATETIME,
estimated_arrival       DATETIME,
delta_seconds           INT,
duration_seconds        INT,
static_duration_seconds INT,
FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
```

### `iterations`
```sql
id                INT AUTO_INCREMENT PRIMARY KEY,
search_id         INT,
departure_time    DATETIME,
estimated_arrival DATETIME,
duration_seconds  INT,
delta_seconds     INT,
is_best           TINYINT(1) DEFAULT 0,
FOREIGN KEY (search_id) REFERENCES searches(id) ON DELETE CASCADE
```

---

## UX Flow

```
index.php  (dashboard)
  ├── List all saved trips (name, origin → destination, target arrival)
  └── "New Trip" form
        ├── Trip name
        ├── Origin         (Places Autocomplete + hidden place_id/lat/lng)
        ├── Waypoints[]    (dynamic add/remove, each with Autocomplete)
        ├── Destination    (Places Autocomplete + hidden place_id/lat/lng)
        ├── Target arrival (datetime-local input)
        └── Submit → saves trips + trip_waypoints → redirect to trip.php

trip.php  (trip detail)
  ├── Trip summary: name, origin, ordered waypoints, destination, target arrival
  ├── "Run Search" button → POST to search.php
  ├── Freshness indicator on most recent search:
  │     ≤ 2h to departure  →  "Live traffic — high confidence"
  │     >  2h to departure  →  "Re-run closer to departure for better accuracy"
  ├── Search history table (searches, newest first):
  │     run_at | best_departure | estimated_arrival | delta | duration
  └── Drill-down: click a run → show iterations table for that search

search.php  (no UI — processing only)
  ├── Receives POST (trip_id)
  ├── Loads trip + waypoints from DB (ordered by stop_order)
  ├── Builds intermediates[] array for API payload
  ├── Loops 13 departure slots (target_arrival − 120min … target_arrival, step 10min)
  │     └── Each slot: call routes_api.php → parse duration → compute delta
  ├── Identifies best slot (minimum delta)
  ├── Saves one searches row + 13 iterations rows (is_best flagged)
  └── Redirects → trip.php?id={trip_id}
```

---

## File Structure

```
Travel_ETA/
├── index.php        # Dashboard + new trip form (Places Autocomplete)
├── trip.php         # Trip detail — stops, search history, re-run, drill-down
├── search.php       # POST handler — loop, save results, redirect
├── routes_api.php   # cURL wrapper — builds payload with placeId, returns parsed duration
├── db.php           # PDO connection + 4-table bootstrap (CREATE TABLE IF NOT EXISTS)
├── config.php        # API keys + DB credentials (gitignored, NOT FTP'd)
├── config.sample.php # Committed template — copy to config.php on server and fill in
├── .gitignore        # Excludes config.php
├── schema.sql        # Full reference schema (standalone SQL file)
└── PLAN.md           # This file
```

---

## Security
- `config.php` never committed — listed in `.gitignore`
- All DB writes use PDO prepared statements (no string interpolation in queries)
- Places API key restricted by HTTP referrer (browser-side only)
- Routes API key restricted by server IP (PHP backend only)
- All POST inputs validated/sanitised before use

---

## Deployment (FTP)

- Files uploaded manually via FTP (e.g. FileZilla, WinSCP, or VS Code SFTP extension)
- `config.php` is **never uploaded** — instead, a `config.sample.php` is committed to the repo as a template; the live `config.php` is created manually on the server
- `.ftpignore` (or FTP client exclude rules) should block: `config.php`, `PLAN.md`, `.gitignore`, `schema.sql`
- Recommended upload targets per file:

| Local file | Upload? | Notes |
|---|---|---|
| `index.php` | Yes | |
| `trip.php` | Yes | |
| `search.php` | Yes | |
| `routes_api.php` | Yes | |
| `db.php` | Yes | |
| `config.php` | **No** | Create manually on server |
| `config.sample.php` | Yes | Template only, no real credentials |
| `schema.sql` | Optional | Run once on server DB, then not needed |
| `PLAN.md` | No | Dev reference only |
| `.gitignore` | No | Not relevant on server |

- `db.php` bootstraps tables with `CREATE TABLE IF NOT EXISTS` so the schema self-installs on first page load — no need to manually run SQL unless you want to pre-create tables

---

## Future / Deferred
- Edit waypoints on an existing saved trip
- Post-trip: record actual arrival time, compare vs estimated
- Map display (coordinates already stored — ready for Maps JS API embed)
- User accounts / multi-user support
