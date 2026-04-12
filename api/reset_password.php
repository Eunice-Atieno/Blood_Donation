<?php
/**
 * api/reset_password.php — Password Reset Endpoint
 *
 * POST { type: 'staff', username, email, new_password }
 *   → verifies username + email match, then updates password
 *
 * POST { type: 'donor', email, national_id, new_password }
 *   → verifies email + national_id match, then updates password
 *
 * No session required — this is a public endpoint.
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$type       = $body['type']         ?? '';
$newPassword = trim($body['new_password'] ?? '');

// Validate new password length
if (strlen($newPassword) < 8) {
    http_response_code(422);
    echo json_encode(['error' => 'Password must be at least 8 characters.']);
    exit;
}

$hash = password_hash($newPassword, PASSWORD_BCRYPT);

try {
    $pdo = getDbConnection();

    // ── Staff reset: verify username + email ──────────────────────────────
    if ($type === 'staff') {
        $username = trim($body['username'] ?? '');
        $email    = trim($body['email']    ?? '');

        if (!$username || !$email) {
            http_response_code(422);
            echo json_encode(['error' => 'Username and email are required.']);
            exit;
        }

        // Check that username and email belong to the same account
        $stmt = $pdo->prepare(
            'SELECT id FROM staff WHERE username = :u AND email = :e LIMIT 1'
        );
        $stmt->execute([':u' => $username, ':e' => $email]);
        $staff = $stmt->fetch();

        if (!$staff) {
            http_response_code(404);
            echo json_encode(['error' => 'No account found with that username and email combination.']);
            exit;
        }

        // Update the password
        $pdo->prepare('UPDATE staff SET password_hash = :h, failed_attempts = 0, locked = 0 WHERE id = :id')
            ->execute([':h' => $hash, ':id' => $staff['id']]);

        echo json_encode(['message' => 'Password reset successfully. You can now sign in.']);

    // ── Donor reset: verify email + national_id ───────────────────────────
    } elseif ($type === 'donor') {
        $email      = trim($body['email']       ?? '');
        $nationalId = trim($body['national_id'] ?? '');

        if (!$email || !$nationalId) {
            http_response_code(422);
            echo json_encode(['error' => 'Email and National ID are required.']);
            exit;
        }

        // Check that email and national_id belong to the same donor
        $stmt = $pdo->prepare(
            'SELECT id FROM donors WHERE email = :e AND national_id = :n LIMIT 1'
        );
        $stmt->execute([':e' => $email, ':n' => $nationalId]);
        $donor = $stmt->fetch();

        if (!$donor) {
            http_response_code(404);
            echo json_encode(['error' => 'No account found with that email and National ID combination.']);
            exit;
        }

        // Update the password
        $pdo->prepare('UPDATE donors SET password_hash = :h WHERE id = :id')
            ->execute([':h' => $hash, ':id' => $donor['id']]);

        echo json_encode(['message' => 'Password reset successfully. You can now sign in.']);

    } else {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid type. Must be staff or donor.']);
    }

} catch (\PDOException $e) {
    error_log('reset_password: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal server error']);
}
