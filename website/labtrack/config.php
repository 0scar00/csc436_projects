<?php
// Database configuration for venthaim_labtrack on cPanel
define('DB_HOST', 'localhost');
define('DB_NAME', 'oscaraqu_labtrack');
define('DB_USER', 'oscaraqu_labtrack_user');
define('DB_PASS', 'YOUR_WORKING_CPANEL_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
