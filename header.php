<?php

// Guard: prevent double session_start() when index.php/trip.php call it before including this file.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF token — generated once per session, never regenerated mid-session
// (avoids breaking browser back button).
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// XSS helper — use h() everywhere user-supplied data is rendered in HTML.
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// Bootstrap DB connection + schema (require_once prevents double-include).
require_once __DIR__ . '/db.php';

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Travel ETA</title>
    <link rel="stylesheet" href="assets/custom.css">
    <link rel="stylesheet" href="assets/bootstrap-icons.css">
    <script src="assets/bootstrap.bundle.min.js" defer></script>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="bi bi-broadcast me-1"></i> Travel ETA
        </a>
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#navbarMain"
                aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>"
                       href="index.php">
                        <i class="bi bi-house-fill me-1"></i> Trips
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
