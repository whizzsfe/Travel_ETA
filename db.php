<?php

// Guard: config.php must exist before we can do anything.
// Safe message — no path or system info disclosed.
if (!file_exists(__DIR__ . '/config.php')) {
    die('Application not configured. Please contact the administrator.');
}

require_once __DIR__ . '/config.php';

// PDO connection
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed. Please contact the administrator.');
}

// Bootstrap schema — all tables use ENGINE=InnoDB for foreign key enforcement.
$pdo->exec("
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
        trip_hash            VARCHAR(32) NOT NULL,
        created_at           DATETIME NOT NULL,
        UNIQUE KEY uq_trip_hash (trip_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS trip_waypoints (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        trip_id      INT NOT NULL,
        display_name VARCHAR(500) NOT NULL,
        place_id     VARCHAR(255) NOT NULL,
        lat          DECIMAL(10,7) NOT NULL,
        lng          DECIMAL(10,7) NOT NULL,
        stop_order   TINYINT UNSIGNED NOT NULL,
        FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS searches (
        id                         INT AUTO_INCREMENT PRIMARY KEY,
        trip_id                    INT NOT NULL,
        target_arrival             DATETIME NOT NULL,
        estimated_duration_minutes SMALLINT UNSIGNED NOT NULL,
        run_at                     DATETIME NOT NULL,
        best_departure             DATETIME,
        estimated_arrival          DATETIME,
        delta_seconds              INT,
        duration_seconds           INT,
        static_duration_seconds    INT,
        warning                    VARCHAR(255),
        FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS iterations (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        search_id               INT NOT NULL,
        departure_time          DATETIME NOT NULL,
        estimated_arrival       DATETIME,
        duration_seconds        INT,
        static_duration_seconds INT,
        delta_seconds           INT,
        is_best                 TINYINT(1) NOT NULL DEFAULT 0,
        skipped                 TINYINT(1) NOT NULL DEFAULT 0,
        error                   TINYINT(1) NOT NULL DEFAULT 0,
        error_message           VARCHAR(255),
        FOREIGN KEY (search_id) REFERENCES searches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
