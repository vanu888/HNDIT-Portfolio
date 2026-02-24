<?php
// Start Session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- SECURITY: CSRF Protection ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- SECURITY: Clickjacking Protection ---
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");

// --- CONFIGURATION ---
// Copy this file to db_connect.php and fill in your database credentials.
$servername = "localhost";
$username   = "your_db_username";
$password   = "your_db_password";
$dbname     = "hndit_portfolio";

// Disable verbose error reporting to screen (Security Best Practice)
mysqli_report(MYSQLI_REPORT_OFF);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("<div style='text-align:center;padding:50px;color:#7f1d1d;font-family:serif;'><h1>System Unavailable</h1><p>Database connection failed. Please contact the system administrator.</p></div>");
}
?>
