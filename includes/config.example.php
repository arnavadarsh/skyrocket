<?php
// Database configuration
// Copy this file to config.php and fill in your local credentials.
// config.php is gitignored — never commit real credentials.
define('DB_HOST', 'localhost');
define('DB_USER', 'your_mysql_user');
define('DB_PASS', 'your_mysql_password');
define('DB_NAME', 'flight_booking');

// Website configuration
define('SITE_NAME', 'SkyConnect');
define('SITE_URL', 'http://localhost/flight');

// Session configuration
session_start();

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
