<?php
/**
 * api/donor_notifications.php — Fetch notifications for a specific donor.
 *
 * GET ?donor_id=<int>
 * Requires an active donor session matching the requested donor_id.
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

// Require active donor session
if (empty($_SESSION['donor'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$requestedId = (int) ($_GET['donor_id'] ?? 0);
$sessionId   = (int) $_SESSION['donor']['donor_id'];

// Donors can only fetch their own notifications
if ($requestedId !== $sessionId) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

try {
    $pdo  = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT id, message_type, sent_at, delivery_status
           FROM notifications
          WHERE donor_id = :donor_id
          ORDER BY sent_at DESC'
    );
    $stmt->execute([':donor_id' => $sessionId]);
    echo json_encode($stmt->fetchAll());
} catch (\PDOException $e) {
    error_log('donor_notifications GET error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal server error']);
}
