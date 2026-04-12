<?php
/**
 * api/staff_notifications.php — Staff Self-Service Notifications
 *
 * Allows any logged-in staff member to view notifications sent to them by an Administrator.
 *
 * GET              → returns all notifications for the current staff member
 * GET ?search=word → filters notifications by keyword in the message body
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';

// Tell the client to expect JSON
header('Content-Type: application/json');

// Start the session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only authenticated staff (any role) can access their own notifications
Auth::requireRole(['Administrator', 'Doctor', 'Lab_Technician']);

// This endpoint only supports GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

try {
    $pdo = getDbConnection();

    // Auto-create the staff_notifications table if it doesn't exist yet.
    // This avoids needing a manual SQL migration on first use.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_notifications (
            id               INT          NOT NULL AUTO_INCREMENT,
            staff_id         INT          NOT NULL,           -- which staff member this notification belongs to
            message          TEXT         NOT NULL,           -- the notification text
            sent_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            delivery_status  ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent',
            PRIMARY KEY (id),
            CONSTRAINT fk_sn_staff
                FOREIGN KEY (staff_id) REFERENCES staff (id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Get the ID of the currently logged-in staff member from the session
    $staff   = Auth::currentStaff();
    $staffId = (int) $staff['staff_id'];

    // Optional keyword filter passed as ?search=...
    $search = trim($_GET['search'] ?? '');

    if ($search !== '') {
        // Filter notifications that contain the search keyword
        $stmt = $pdo->prepare(
            'SELECT id, message, sent_at, delivery_status
               FROM staff_notifications
              WHERE staff_id = :sid AND message LIKE :q
              ORDER BY sent_at DESC'
        );
        $stmt->execute([':sid' => $staffId, ':q' => '%' . $search . '%']);
    } else {
        // Return all notifications for this staff member, newest first
        $stmt = $pdo->prepare(
            'SELECT id, message, sent_at, delivery_status
               FROM staff_notifications
              WHERE staff_id = :sid
              ORDER BY sent_at DESC'
        );
        $stmt->execute([':sid' => $staffId]);
    }

    echo json_encode($stmt->fetchAll());

} catch (\PDOException $e) {
    error_log('staff_notifications GET: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal server error']);
}
