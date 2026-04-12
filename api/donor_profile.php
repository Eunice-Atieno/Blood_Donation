<?php
/**
 * api/donor_profile.php — Donor Profile Endpoint
 *
 * GET  → fetch own profile
 * PUT  → update name, phone, email; optionally change password
 *        body: { name, phone, email, current_password, new_password,
 *                sms_opt_in, email_opt_in }
 *
 * Requires active donor session.
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['donor'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$donorId = (int) $_SESSION['donor']['donor_id'];
$method  = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT id, name, date_of_birth, blood_type, national_id,
                    email, phone, medical_history_flag, last_donation_date,
                    sms_opt_in, email_opt_in, created_at
               FROM donors WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $donorId]);
        $donor = $stmt->fetch();

        if (!$donor) {
            http_response_code(404);
            echo json_encode(['error' => 'donor not found']);
            exit;
        }

        // Compute next eligibility date (56-day rule — matches EligibilityChecker)
        $donor['next_eligible_date'] = null;
        if ($donor['last_donation_date']) {
            $donor['next_eligible_date'] = date(
                'Y-m-d',
                strtotime($donor['last_donation_date'] . ' +56 days')
            );
        }

        // Unique donor ID formatted as KNH-DNR-XXXXX
        $donor['donor_code'] = 'KNH-DNR-' . str_pad($donor['id'], 3, '0', STR_PAD_LEFT);

        echo json_encode($donor);
    } catch (\PDOException $e) {
        error_log('donor_profile GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── PUT ──────────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        exit;
    }

    try {
        $pdo = getDbConnection();

        // Fetch current record
        $stmt = $pdo->prepare('SELECT * FROM donors WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $donorId]);
        $current = $stmt->fetch();

        if (!$current) {
            http_response_code(404);
            echo json_encode(['error' => 'donor not found']);
            exit;
        }

        // Editable fields
        $name     = trim($body['name']  ?? $current['name']);
        $phone    = trim($body['phone'] ?? $current['phone']);
        $email    = trim($body['email'] ?? $current['email']);
        $emailOpt = isset($body['email_opt_in']) ? (int)(bool)$body['email_opt_in'] : (int)$current['email_opt_in'];

        if ($name === '' || $phone === '' || $email === '') {
            http_response_code(422);
            echo json_encode(['error' => 'name, phone, and email cannot be empty']);
            exit;
        }

        // Password change (optional)
        $newHash = null;
        if (!empty($body['new_password'])) {
            $currentPw = $body['current_password'] ?? '';

            // Verify current password if one is already set
            if (!empty($current['password_hash'])) {
                if (!password_verify($currentPw, $current['password_hash'])) {
                    http_response_code(422);
                    echo json_encode(['error' => 'current password is incorrect']);
                    exit;
                }
            }

            if (strlen($body['new_password']) < 8) {
                http_response_code(422);
                echo json_encode(['error' => 'new password must be at least 8 characters']);
                exit;
            }

            $newHash = password_hash($body['new_password'], PASSWORD_BCRYPT);
        }

        // Build update query
        if ($newHash !== null) {
            $upd = $pdo->prepare(
                'UPDATE donors SET name=:name, phone=:phone, email=:email,
                        email_opt_in=:eml, password_hash=:pw
                  WHERE id=:id'
            );
            $upd->execute([
                ':name' => $name, ':phone' => $phone, ':email' => $email,
                ':eml'  => $emailOpt, ':pw' => $newHash, ':id' => $donorId,
            ]);
        } else {
            $upd = $pdo->prepare(
                'UPDATE donors SET name=:name, phone=:phone, email=:email,
                        email_opt_in=:eml
                  WHERE id=:id'
            );
            $upd->execute([
                ':name' => $name, ':phone' => $phone, ':email' => $email,
                ':eml'  => $emailOpt, ':id' => $donorId,
            ]);
        }

        // Update session name if changed
        $_SESSION['donor']['name'] = $name;

        echo json_encode(['message' => 'profile updated']);
    } catch (\PDOException $e) {
        error_log('donor_profile PUT error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

http_response_code(405);
header('Allow: GET, PUT');
echo json_encode(['error' => 'method not allowed']);
