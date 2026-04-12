<?php
/**
 * api/broadcast.php — Broadcast email notifications to all donors
 *
 * Returns immediately to the browser, then sends emails in the background.
 *
 * POST { action: 'emergency', blood_type: 'O-', message: '...' }
 * POST { action: 'eligibility' }
 *
 * Administrator only.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/NotificationService.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Auth::requireRole('Administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if (!in_array($action, ['emergency', 'eligibility'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'action must be emergency or eligibility']);
    exit;
}

if ($action === 'emergency' && empty($body['blood_type'])) {
    http_response_code(422);
    echo json_encode(['error' => 'blood_type is required']);
    exit;
}

// ── Respond immediately so the browser doesn't wait ──────────────────────────
// Count how many donors will be emailed so we can show a meaningful message
try {
    $pdo = getDbConnection();

    if ($action === 'emergency') {
        $bloodType = trim($body['blood_type']);
        $compatibleDonors = [
            'O-'  => ['O-'],
            'O+'  => ['O-', 'O+'],
            'A-'  => ['O-', 'A-'],
            'A+'  => ['O-', 'O+', 'A-', 'A+'],
            'B-'  => ['O-', 'B-'],
            'B+'  => ['O-', 'O+', 'B-', 'B+'],
            'AB-' => ['O-', 'A-', 'B-', 'AB-'],
            'AB+' => ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'],
        ];
        $eligible     = $compatibleDonors[$bloodType] ?? [$bloodType];
        $placeholders = implode(',', array_fill(0, count($eligible), '?'));
        $today        = date('Y-m-d');

        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM donors
              WHERE blood_type IN ({$placeholders})
                AND medical_history_flag = 0 AND email_opt_in = 1
                AND email IS NOT NULL AND email != ''
                AND (last_donation_date IS NULL OR DATEDIFF(?, last_donation_date) >= 56)"
        );
        $count->execute(array_merge($eligible, [$today]));
        $total = (int) $count->fetchColumn();
        $responseMsg = "Sending emergency appeal to {$total} eligible donor(s) in the background.";

    } else {
        $today = date('Y-m-d');
        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM donors
              WHERE medical_history_flag = 0 AND email_opt_in = 1
                AND email IS NOT NULL AND email != ''
                AND (last_donation_date IS NULL OR DATEDIFF(?, last_donation_date) = 56)"
        );
        $count->execute([$today]);
        $total = (int) $count->fetchColumn();
        $responseMsg = "Sending eligibility updates to {$total} donor(s) in the background.";
    }
} catch (\Exception $e) {
    $responseMsg = "Processing in background…";
}

// Send response to browser NOW before doing any email work
http_response_code(200);
echo json_encode(['message' => $responseMsg]);

// Flush output to browser immediately
if (ob_get_level()) ob_end_flush();
flush();

// Tell PHP to keep running even after the browser disconnects
ignore_user_abort(true);
set_time_limit(300); // 5 minutes max for large donor lists

// ── Now send emails in the background ────────────────────────────────────────
try {
    $notif = new NotificationService();

    if ($action === 'emergency') {
        $notif->sendEmergencyAppeal(
            trim($body['blood_type']),
            trim($body['message'] ?? '')
        );
    } else {
        $notif->sendEligibilityUpdates();
    }
} catch (\Exception $e) {
    error_log('broadcast background error: ' . $e->getMessage());
}
