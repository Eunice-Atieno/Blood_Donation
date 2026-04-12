<?php
/**
 * Database configuration example.
 * Copy this file to config/db.php and fill in your actual values.
 * NEVER commit config/db.php to version control.
 */

// Database host (e.g., 'localhost' for XAMPP/WAMP)
define('DB_HOST', 'your_db_host');

// MySQL username (e.g., 'root' for local XAMPP/WAMP)
define('DB_USER', 'your_db_username');

// MySQL password (leave empty string '' for default XAMPP root with no password)
define('DB_PASS', 'your_db_password');

// Database name — must match the schema created from database/schema.sql
define('DB_NAME', 'knh_bdms_db');

// MySQL port (default: 3306)
define('DB_PORT', '3306');

/**
 * Returns a singleton PDO connection to knh_bdms_db.
 * Throws PDOException on failure (caller must catch and return HTTP 500).
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // PDOException is intentionally not caught here.
        // The caller (api endpoint) is responsible for catching it,
        // logging the error server-side, and returning HTTP 500
        // WITHOUT exposing credentials in the response.
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}
