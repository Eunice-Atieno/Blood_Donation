<?php
/**
 * api/donor_auth.php — Donor Authentication Endpoint
 *
 * Handles login and logout for donors using email + password.
 * On success, stores donor info in $_SESSION['donor'].
 *
 * POST   { "email": "...", "password": "..." } → login
 * DELETE                                        → logout
 */

require_once __DIR__ . '/../config/db.php';

// Tell the client to expect JSON
header('Content-Type: application/json');

// Start the session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── LOGIN (POST) ──────────────────────────────────────────────────────────────
if ($method === 'POST') {

    // Decode the JSON body from the login form
    $body = json_decode(file_get_contents('php://input'), true);

    // Reject malformed JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        exit;
    }

    // Extract and sanitise the submitted credentials
    $email    = trim($body['email']    ?? '');
    $password = $body['password'] ?? '';

    // Both fields are required
    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'email and password are required']);
        exit;
    }

    try {
        $pdo = getDbConnection();

        // Look up the donor by email address
        $stmt = $pdo->prepare(
            'SELECT id, name, blood_type, email, password_hash, last_donation_date
               FROM donors
              WHERE email = :email
              LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $donor = $stmt->fetch();

    } catch (\PDOException $e) {
        // Log the DB error and return a generic 500 to avoid leaking details
        error_log('donor_auth POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
        exit;
    }

    // Reject if donor not found OR password doesn't match the stored hash
    if ($donor === false || !password_verify($password, $donor['password_hash'] ?? '')) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid credentials']);
        exit;
    }

    // Store donor identity in the session so other API endpoints can identify them
    $_SESSION['donor'] = [
        'donor_id'    => (int) $donor['id'],
        'name'        => $donor['name'],
        'blood_type'  => $donor['blood_type'],
        'last_active' => time(), // used for 30-minute inactivity timeout
    ];

    // Return the donor details the frontend needs to populate sessionStorage
    http_response_code(200);
    echo json_encode([
        'donor_id'   => (int) $donor['id'],
        'name'       => $donor['name'],
        'blood_type' => $donor['blood_type'],
    ]);
    exit;
}

// ── LOGOUT (DELETE) ───────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    // Remove only the donor session data (leaves any staff session intact)
    unset($_SESSION['donor']);
    http_response_code(200);
    echo json_encode(['message' => 'logged out']);
    exit;
}

// Any other HTTP method is not supported
http_response_code(405);
header('Allow: POST, DELETE');
echo json_encode(['error' => 'method not allowed']);
