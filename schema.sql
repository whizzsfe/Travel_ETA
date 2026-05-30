-- Travel_ETA — Reference Schema
-- All tables use ENGINE=InnoDB for foreign key enforcement.
-- This file is for reference only — db.php bootstraps the schema automatically
-- with CREATE TABLE IF NOT EXISTS on first page load.

-- ---------------------------------------------------------------------------
-- trips: route identity only (no target time — that lives on searches)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trips (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(255) NOT NULL,
    origin_display       VARCHAR(500) NOT NULL,
    origin_place_id      VARCHAR(255) NOT NULL,
    origin_lat           DECIMAL(10,7) NOT NULL,
    origin_lng           DECIMAL(10,7) NOT NULL,
    destination_display  VARCHAR(500) NOT NULL,
    destination_place_id VARCHAR(255) NOT NULL,
    destination_lat      DECIMAL(10,7) NOT NULL,
    destination_lng      DECIMAL(10,7) NOT NULL,
    trip_hash            VARCHAR(32) NOT NULL,   -- MD5(origin_place_id|wp_place_ids|destination_place_id)
    created_at           DATETIME NOT NULL,       -- set in PHP (Europe/London); avoids MySQL UTC session timezone conflict
    UNIQUE KEY uq_trip_hash (trip_hash)           -- prevents duplicate routes; redirect to existing on conflict
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- trip_waypoints: zero rows = direct trip (no waypoints)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trip_waypoints (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    trip_id      INT NOT NULL,
    display_name VARCHAR(500) NOT NULL,
    place_id     VARCHAR(255) NOT NULL,
    lat          DECIMAL(10,7) NOT NULL,
    lng          DECIMAL(10,7) NOT NULL,
    stop_order   TINYINT UNSIGNED NOT NULL,
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- searches: one row per search run
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS searches (
    id                         INT AUTO_INCREMENT PRIMARY KEY,
    trip_id                    INT NOT NULL,
    target_arrival             DATETIME NOT NULL,           -- Europe/London; set per run
    estimated_duration_minutes SMALLINT UNSIGNED NOT NULL,  -- min 5 enforced in PHP
    run_at                     DATETIME NOT NULL,           -- set in PHP (Europe/London)
    best_departure             DATETIME,                    -- NULL if all slots skipped/errored
    estimated_arrival          DATETIME,                    -- NULL if all slots skipped/errored
    delta_seconds              INT,                         -- signed: estimated_arrival − target_arrival; negative=early; NULL if no result
    duration_seconds           INT,                         -- NULL if no result
    static_duration_seconds    INT,                         -- NULL if no result
    warning                    VARCHAR(255),                -- set when all valid slots arrived late
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- iterations: one row per departure slot per search run (always 13)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS iterations (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    search_id               INT NOT NULL,
    departure_time          DATETIME NOT NULL,   -- always set, even for skipped/error rows
    estimated_arrival       DATETIME,            -- NULL if skipped or error
    duration_seconds        INT,                 -- NULL if skipped or error
    static_duration_seconds INT,                 -- NULL if skipped or error
    delta_seconds           INT,                 -- NULL if skipped or error; signed
    is_best                 TINYINT(1) NOT NULL DEFAULT 0,
    skipped                 TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = departure was in the past
    error                   TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = API call attempted but failed
    error_message           VARCHAR(255),
    FOREIGN KEY (search_id) REFERENCES searches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
