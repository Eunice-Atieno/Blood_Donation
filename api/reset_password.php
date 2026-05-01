<?php
/**
 * api/reset_password.php — Password Reset Endpoint
 *
 * POST { type: 'staff', username, email, new_password }
 * POST { type: 'donor', email, national_id, new_password }
 *
 * Sends a confirmation email after a successful reset.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/NotificationService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$type        = $body['type']         ?? '';
$newPassword = trim($body['new_password'] ?? '');

if (strlen($newPassword) < 8) {
    http_response_code(422);
    echo json_encode(['error' => 'Password must be at least 8 characters.']);
    exit;
}

$hash = password_hash($newPassword, PASSWORD_BCRYPT);

try {
    $pdo = getDbConnection();

    // ── Staff reset ───────────────────────────────────────────────────────────
    if ($type === 'staff') {
        $username = trim($body['username'] ?? '');
        $email    = trim($body['email']    ?? '');

        if (!$username || !$email) {
            http_response_code(422);
            echo json_encode(['error' => 'Username and email are required.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, username, email FROM staff WHERE username = :u AND email = :e LIMIT 1');
        $stmt->execute([':u' => $username, ':e' => $email]);
        $staff = $stmt->fetch();

        if (!$staff) {
            http_response_code(404);
            echo json_encode(['error' => 'No account found with that username and email combination.']);
            exit;
        }

        $pdo->prepare('UPDATE staff SET password_hash = :h, failed_attempts = 0, locked = 0 WHERE id = :id')
            ->execute([':h' => $hash, ':id' => $staff['id']]);

        // Send confirmation email (soft failure)
        try {
            $notif = new NotificationService();
            $notif->sendPasswordResetConfirmation($staff['email'], $staff['username']);
        } catch (\Throwable $ignored) {}

        echo json_encode(['message' => 'Password reset successfully. You can now sign in.']);

    // ── Donor reset ───────────────────────────────────────────────────────────
    } elseif ($type === 'donor') {
        $email      = trim($body['email']       ?? '');
        $nationalId = trim($body['national_id'] ?? '');

        if (!$email || !$nationalId) {
            http_response_code(422);
            echo json_encode(['error' => 'Email and National ID are required.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, name, email FROM donors WHERE email = :e AND national_id = :n LIMIT 1');
        $stmt->execute([':e' => $email, ':n' => $nationalId]);
        $donor = $stmt->fetch();

        if (!$donor) {
            http_response_code(404);
            echo json_encode(['error' => 'No account found with that email and National ID combination.']);
            exit;
        }

        $pdo->prepare('UPDATE donors SET password_hash = :h WHERE id = :id')
            ->execute([':h' => $hash, ':id' => $donor['id']]);

        // Send confirmation email (soft failure)
        try {
            $notif = new NotificationService();
            $notif->sendPasswordResetConfirmation($donor['email'], $donor['name']);
        } catch (\Throwable $ignored) {}

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
