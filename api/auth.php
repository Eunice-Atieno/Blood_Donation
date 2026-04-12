<?php
/**
 * api/auth.php — Staff Authentication Endpoint
 *
 * Handles login and logout for staff members (Administrator, Doctor, Lab_Technician).
 *
 * POST  { "username": "...", "password": "..." }
 *       → validates credentials, starts a session, returns staff_id and role
 *
 * DELETE
 *       → destroys the current session (logout)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';

// Tell the browser/client to expect JSON back
header('Content-Type: application/json');

// Start the PHP session if it hasn't been started yet
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── LOGIN (POST) ──────────────────────────────────────────────────────────────
if ($method === 'POST') {

    // Read and decode the JSON body sent by the login form
    $body = json_decode(file_get_contents('php://input'), true);

    // Reject malformed JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        exit;
    }

    // Extract and sanitise credentials
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    // Both fields are required
    if ($username === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'username and password are required']);
        exit;
    }

    // Delegate to Auth::login() which checks the DB, verifies the password hash,
    // handles failed-attempt counting, and starts the session on success
    $result = Auth::login($username, $password);

    if ($result === false) {
        // Wrong credentials or account locked
        http_response_code(401);
        echo json_encode(['error' => 'invalid credentials']);
        exit;
    }

    // Return the staff ID and role so the frontend can route to the right dashboard
    // Return the staff ID, role, and formatted staff code
    $prefix = match($result['role']) {
        'Administrator'  => 'ADM',
        'Doctor'         => 'DOC',
        'Lab_Technician' => 'LAB',
        default          => 'STF',
    };
    $staffCode = 'KNH-' . $prefix . '-' . str_pad($result['staff_id'], 3, '0', STR_PAD_LEFT);

    http_response_code(200);
    echo json_encode([
        'staff_id'   => $result['staff_id'],
        'role'       => $result['role'],
        'staff_code' => $staffCode,
    ]);
    exit;
}

// ── LOGOUT (DELETE) ───────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    // Destroy the session and clear the session cookie
    Auth::logout();
    http_response_code(200);
    echo json_encode(['message' => 'logged out']);
    exit;
}

// Any other HTTP method is not supported
http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
