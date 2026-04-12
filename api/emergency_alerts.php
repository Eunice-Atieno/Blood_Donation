<?php
/**
 * api/emergency_alerts.php — Blood shortage emergency alerts
 *
 * GET → returns blood types currently flagged low_stock=1
 *       Public endpoint (no auth required — donors need to see this).
 *
 * POST ?action=volunteer → donor volunteers to donate (requires donor session)
 *      Records a notification of type 'low_stock_alert' for the donor.
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->query(
            'SELECT blood_type, unit_count FROM blood_inventory
              WHERE low_stock = 1
              ORDER BY unit_count ASC'
        );
        $alerts = $stmt->fetchAll();

        echo json_encode([
            'alerts'      => $alerts,
            'total_types' => count($alerts),
        ]);
    } catch (\PDOException $e) {
        error_log('emergency_alerts GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── POST — donor volunteers ───────────────────────────────────────────────────
if ($method === 'POST') {
    if (empty($_SESSION['donor'])) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }

    $donorId = (int) $_SESSION['donor']['donor_id'];

    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (donor_id, message_type, delivery_status)
             VALUES (:donor_id, 'low_stock_alert', 'pending')"
        );
        $stmt->execute([':donor_id' => $donorId]);

        echo json_encode(['message' => 'Thank you! Staff will be in touch to confirm your appointment.']);
    } catch (\PDOException $e) {
        error_log('emergency_alerts POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['error' => 'method not allowed']);
