# Travel ETA

[![PHP 7.4 Syntax Check](https://github.com/whizzsfe/Travel_ETA/actions/workflows/php-lint.yml/badge.svg)](https://github.com/whizzsfe/Travel_ETA/actions/workflows/php-lint.yml)
[![Generate API Docs](https://github.com/whizzsfe/Travel_ETA/actions/workflows/phpdoc.yml/badge.svg)](https://github.com/whizzsfe/Travel_ETA/actions/workflows/phpdoc.yml)
[![PHP 7.4](https://img.shields.io/badge/PHP-7.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)](https://www.sqlite.org/)
[![Google Routes API](https://img.shields.io/badge/Google-Routes%20API%20v2-4285F4?logo=googlemaps&logoColor=white)](https://developers.google.com/maps/documentation/routes)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Find the best time to leave for a car journey. Given a target arrival time, Travel ETA queries the Google Maps Routes API across 13 departure slots, picks the slot that gets you there closest to on time, and saves the history so you can re-run as departure approaches and traffic data improves.

---

## Features

- **Multi-slot search** — tests 13 evenly-spaced departure windows around an estimated journey time and picks the best one.
- **Traffic-aware** — uses `TRAFFIC_AWARE_OPTIMAL` routing so results reflect predicted real-world conditions.
- **Freshness banner** — warns when the last result is more than 2 hours old and live traffic data would be more reliable.
- **Search history** — every run is saved with its best departure, estimated arrival, drive time, and early/late delta.
- **Dashboard** — trips auto-disappear once their target arrival passes; past trips can also be deleted manually.
- **Waypoints** — supports optional intermediate stops.
- **Dedup** — re-using the same origin/destination pair reopens the existing trip rather than creating a duplicate.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP ≥ 7.4 (no composer required) |
| Database | SQLite 3 via PDO (schema auto-created on first load) |
| Routing | Google Maps Routes API v2 (`computeRoutes`) |
| Places | Google Places API (New, `v=beta`) — `PlaceAutocompleteElement` |
| Frontend | Bootstrap 5, Bootstrap Icons |
| Hosting | cPanel shared hosting with Git Version Control |
| CI | GitHub Actions |

---

## Setup

### 1. Clone and configure

```bash
git clone https://github.com/whizzsfe/Travel_ETA.git
cd Travel_ETA
cp config.sample.php config.php
```

Edit `config.php` and fill in:

| Constant | Description |
|---|---|
| `DB_PATH` | Absolute path to the SQLite file — keep it outside the web root |
| `ROUTES_API_KEY` | Server-side key; restrict to your server's IP address; enable Routes API only |
| `PLACES_API_KEY` | Browser-side key; restrict to your domain (HTTP referrer); enable Places API (New) + Maps JavaScript API only |

> **Two separate keys are required.** The Routes API is called server-side via cURL (no `Referer` header), so its key must use IP-address restrictions, not HTTP-referrer restrictions.

### 2. Google Cloud Console

1. **Routes API key** → Application restrictions: **IP addresses** → add your server's IP → API restrictions: **Routes API** only.
2. **Places API key** → Application restrictions: **HTTP referrers** → add your domain → API restrictions: **Maps JavaScript API** + **Places API (New)** only.

### 3. Point your web server at the repo root

The schema (`trips`, `trip_waypoints`, `searches`, `iterations`) is created automatically on the first page load via `db.php`.

---

## How it works

```
User sets target arrival time
        │
        ▼
search.php builds 13 departure candidates
        │  (spaced around estimated journey time)
        ▼
For each candidate ──► Google Routes API v2
                             returns duration + staticDuration
        │
        ▼
Best departure = slot whose estimated arrival is closest to (but not after) target
        │
        ▼
Result saved to SQLite; user redirected to trip detail page
```

---

## Project Structure

```
Travel_ETA/
├── config.sample.php   # Template — copy to config.php and fill in secrets
├── db.php              # PDO SQLite connection + schema bootstrap
├── routes_api.php      # call_routes_api() — cURL wrapper for Routes API v2
├── header.php          # HTML head, Bootstrap, h() XSS helper, CSRF token
├── footer.php          # Google Maps JS loader + PlaceAutocompleteElement wiring
├── index.php           # Dashboard (trip list) + new-trip form
├── trip.php            # Trip detail: run-search form + search history
├── search.php          # POST-only: runs 13-slot search, saves results, redirects
├── schema.sql          # Reference schema (db.php bootstraps this automatically)
├── phpdoc.dist.xml     # phpDocumentor configuration
└── .github/
    └── workflows/
        ├── php-lint.yml  # PHP 7.4 syntax check on every push/PR
        └── phpdoc.yml    # Generate API docs → GitHub Pages
```

---

## CI / GitHub Actions

| Workflow | Trigger | What it does |
|---|---|---|
| **PHP 7.4 Syntax Check** | push + PR to `main` | Runs `php -l` on every `.php` file under PHP 7.4 |
| **Generate API Docs** | push to `main` | Runs phpDocumentor and deploys output to the `gh-pages` branch |

API docs are published at **https://whizzsfe.github.io/Travel_ETA/** once GitHub Pages is enabled (Settings → Pages → Source: `gh-pages` branch).

---

## API key security notes

- `config.php` is listed in `.gitignore` and never committed.
- `ROUTES_API_KEY` is only ever used in server-side PHP — it is never sent to the browser.
- `PLACES_API_KEY` is embedded in the Maps script URL; restrict it tightly by HTTP referrer to limit exposure.
