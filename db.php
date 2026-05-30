<?php

// Guard: config.php must exist before we can do anything.
// Safe message — no path or system info disclosed.
if (!file_exists(__DIR__ . '/config.php')) {
    die('Application not configured. Please contact the administrator.');
}

require_once __DIR__ . '/config.php';

// PDO connection (SQLite)
try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Database connection failed. Please contact the administrator.');
}

// Enable foreign key enforcement (must be set per-connection in SQLite)
$pdo->exec('PRAGMA foreign_keys = ON');
// WAL mode gives better read concurrency on shared hosts
$pdo->exec('PRAGMA journal_mode = WAL');

// Bootstrap schema
$pdo->exec("
    CREATE TABLE IF NOT EXISTS trips (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        name                 TEXT NOT NULL,
        origin_display       TEXT NOT NULL,
        origin_place_id      TEXT NOT NULL,
        origin_lat           REAL NOT NULL,
        origin_lng           REAL NOT NULL,
        destination_display  TEXT NOT NULL,
        destination_place_id TEXT NOT NULL,
        destination_lat      REAL NOT NULL,
        destination_lng      REAL NOT NULL,
        trip_hash            TEXT NOT NULL UNIQUE,
        created_at           TEXT NOT NULL
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS trip_waypoints (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        trip_id      INTEGER NOT NULL,
        display_name TEXT NOT NULL,
        place_id     TEXT NOT NULL,
        lat          REAL NOT NULL,
        lng          REAL NOT NULL,
        stop_order   INTEGER NOT NULL,
        FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS searches (
        id                         INTEGER PRIMARY KEY AUTOINCREMENT,
        trip_id                    INTEGER NOT NULL,
        target_arrival             TEXT NOT NULL,
        estimated_duration_minutes INTEGER NOT NULL,
        run_at                     TEXT NOT NULL,
        best_departure             TEXT,
        estimated_arrival          TEXT,
        delta_seconds              INTEGER,
        duration_seconds           INTEGER,
        static_duration_seconds    INTEGER,
        warning                    TEXT,
        FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS iterations (
        id                      INTEGER PRIMARY KEY AUTOINCREMENT,
        search_id               INTEGER NOT NULL,
        departure_time          TEXT NOT NULL,
        estimated_arrival       TEXT,
        duration_seconds        INTEGER,
        static_duration_seconds INTEGER,
        delta_seconds           INTEGER,
        is_best                 INTEGER NOT NULL DEFAULT 0,
        skipped                 INTEGER NOT NULL DEFAULT 0,
        error                   INTEGER NOT NULL DEFAULT 0,
        error_message           TEXT,
        FOREIGN KEY (search_id) REFERENCES searches(id) ON DELETE CASCADE
    )
");
