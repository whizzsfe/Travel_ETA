<?php

// Set application timezone — must be here so every file that requires config.php
// gets the timezone applied immediately (handles GMT/BST automatically).
date_default_timezone_set('Europe/London');

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Google Routes API key — server IP-restricted; Routes API permission only.
// Do NOT enable Places API on this key.
define('ROUTES_API_KEY', 'your-routes-api-key-here');

// Google Places API key — HTTP-referrer-restricted (browser-facing).
// Enable: Places API (New) + Maps JavaScript API only.
// Do NOT enable Routes API on this key.
define('PLACES_API_KEY', 'your-places-api-key-here');
