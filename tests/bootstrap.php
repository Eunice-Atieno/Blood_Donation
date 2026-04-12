<?php
/**
 * PHPUnit bootstrap file.
 * Loads the DB configuration and overrides DB_NAME to use the test database.
 */

// Load the real db.php which defines DB_HOST, DB_USER, DB_PASS, DB_PORT, and DB_NAME,
// plus the getDbConnection() helper.
require_once __DIR__ . '/../config/db.php';
