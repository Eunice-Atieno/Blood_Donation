<?php
/**
 * api/notifications.php — Notifications Endpoint (Administrator only)
 *
 * Allows Administrators to send email notifications to donors or staff,
 * and to view the notification history.
 *
 * GET  ?type=donor|staff              → list all sent notifications
 * GET  ?search=name&recipient_type=.. → search recipients by name (for autocomplete)
 * POST { recipient_type, donor_id, message_type }  → send donor notification
 * POST { recipient_type, staff_id,  message }      → send staff notification
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/NotificationService.php';

// Tell the client to expect JSON
header('Content-Type: application/json');

// Start the session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only Administrators can send or view notifications
Auth::requireRole('Administrator');

/**
 * Auto-create the staff_notifications table if it doesn't exist.
 * Called before any query that touches that table.
 */
function ensureStaffNotifTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_notifications (
            id               INT          NOT NULL AUTO_INCREMENT,
            staff_id         INT          NOT NULL,
            message          TEXT         NOT NULL,
            sent_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            delivery_status  ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent',
            PRIMARY KEY (id),
            CONSTRAINT fk_staff_notif_staff
                FOREIGN KEY (staff_id) REFERENCES staff (id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $pdo = getDbConnection();
        ensureStaffNotifTable($pdo);

        // ── Recipient name search (used by the autocomplete in the admin UI) ──
        if (isset($_GET['search'])) {
            $q    = '%' . trim($_GET['search']) . '%';
            $type = $_GET['recipient_type'] ?? 'donor';

            if ($type === 'staff') {
                // Search staff by username
                $stmt = $pdo->prepare(
                    'SELECT id, username AS name, role, email
                       FROM staff
                      WHERE username LIKE :q
                      ORDER BY username LIMIT 10'
                );
            } else {
                // Search donors by name
                $stmt = $pdo->prepare(
                    'SELECT id, name, email
                       FROM donors
                      WHERE name LIKE :q
                      ORDER BY name LIMIT 10'
                );
            }
            $stmt->execute([':q' => $q]);
            echo json_encode($stmt->fetchAll());
            exit;
        }

        // ── Notification history list ─────────────────────────────────────────
        $type = $_GET['type'] ?? 'donor';

        if ($type === 'staff') {
            // Join staff_notifications with staff to get the recipient's username
            $stmt = $pdo->query(
                'SELECT sn.id, s.username AS recipient_name, sn.message, sn.sent_at, sn.delivery_status
                   FROM staff_notifications sn
                   JOIN staff s ON s.id = sn.staff_id
                  ORDER BY sn.sent_at DESC'
            );
        } else {
            // Join notifications with donors to get the recipient's name
            $stmt = $pdo->query(
                'SELECT n.id, d.name AS recipient_name, n.message_type, n.sent_at, n.delivery_status
                   FROM notifications n
                   JOIN donors d ON d.id = n.donor_id
                  ORDER BY n.sent_at DESC'
            );
        }
        echo json_encode($stmt->fetchAll());

    } catch (\PDOException $e) {
        error_log('notifications GET: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    // Read the JSON body from the request
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $recipientType = $body['recipient_type'] ?? 'donor'; // 'donor' or 'staff'

    try {
        $pdo = getDbConnection();
        ensureStaffNotifTable($pdo);

        // Instantiate the email service
        $service = new NotificationService();

        // ── Send notification to a STAFF member ───────────────────────────────
        if ($recipientType === 'staff') {
            $staffId = (int) ($body['staff_id'] ?? 0);
            $message = trim($body['message'] ?? '');

            // Both staff ID and message text are required
            if (!$staffId || $message === '') {
                http_response_code(422);
                echo json_encode(['error' => 'staff_id and message are required']);
                exit;
            }

            // Verify the staff member exists and get their email
            $check = $pdo->prepare('SELECT id, username, email FROM staff WHERE id = :id LIMIT 1');
            $check->execute([':id' => $staffId]);
            $staff = $check->fetch();

            if (!$staff) {
                http_response_code(404);
                echo json_encode(['error' => 'staff member not found']);
                exit;
            }

            // Save the notification record with 'pending' status before sending
            $stmt = $pdo->prepare(
                "INSERT INTO staff_notifications (staff_id, message, delivery_status)
                 VALUES (:sid, :msg, 'pending')"
            );
            $stmt->execute([':sid' => $staffId, ':msg' => $message]);
            $notifId = (int) $pdo->lastInsertId();

            // Attempt to send the email
            $emailStatus = 'failed'; // default to failed
            $emailNote   = null;

            if (!empty($staff['email'])) {
                try {
                    $result = $service->sendStaffNotification($staff['email'], $staff['username'], $message);
                    if ($result['success']) {
                        $emailStatus = 'sent';
                    } else {
                        $emailNote = $result['error'] ?? 'Email delivery failed';
                        error_log('Staff notification email failed: ' . $emailNote);
                    }
                } catch (\Throwable $e) {
                    $emailNote = $e->getMessage();
                    error_log('Staff notification exception: ' . $emailNote);
                }
            } else {
                $emailNote = 'Staff member has no email address on file';
            }

            // Always update — never leave as pending
            $pdo->prepare('UPDATE staff_notifications SET delivery_status = :s WHERE id = :id')
                ->execute([':s' => $emailStatus, ':id' => $notifId]);

            http_response_code(201);
            echo json_encode([
                'notification_id' => $notifId,
                'email_status'    => $emailStatus,
                'note'            => $emailNote,
            ]);

        // ── Send notification to a DONOR ──────────────────────────────────────
        } else {
            $donorId     = (int) ($body['donor_id'] ?? 0);
            $messageType = trim($body['message_type'] ?? ''); // e.g. 'eligibility_reminder'

            // Both donor ID and message type are required
            if (!$donorId || $messageType === '') {
                http_response_code(422);
                echo json_encode(['error' => 'donor_id and message_type are required']);
                exit;
            }

            // Verify the donor exists
            $check = $pdo->prepare('SELECT id FROM donors WHERE id = :id LIMIT 1');
            $check->execute([':id' => $donorId]);
            if (!$check->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'donor not found']);
                exit;
            }

            // Save the notification record with 'pending' status before sending
            $insert = $pdo->prepare(
                "INSERT INTO notifications (donor_id, message_type, delivery_status)
                 VALUES (:did, :type, 'pending')"
            );
            $insert->execute([':did' => $donorId, ':type' => $messageType]);
            $notifId = (int) $pdo->lastInsertId();

            // Attempt to send the email — soft failure (won't crash if email fails)
            $emailStatus = 'failed'; // default to failed; only set to sent on success
            $emailNote   = null;

            try {
                $result = $service->sendDonorNotification($donorId, $messageType);
                if ($result['success']) {
                    $emailStatus = 'sent';
                } else {
                    $emailNote = $result['error'] ?? 'Email delivery failed';
                    error_log('Donor notification email failed: ' . $emailNote);
                }
            } catch (\Throwable $e) {
                $emailNote = $e->getMessage();
                error_log('Donor notification exception: ' . $emailNote);
            }

            // Always update the notification record — never leave it as pending
            $pdo->prepare('UPDATE notifications SET delivery_status = :s WHERE id = :id')
                ->execute([':s' => $emailStatus, ':id' => $notifId]);

            http_response_code(201);
            echo json_encode([
                'notification_id' => $notifId,
                'email_status'    => $emailStatus,
                'note'            => $emailNote,
            ]);
        }

    } catch (\PDOException $e) {
        error_log('notifications POST: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Any other HTTP method is not supported
http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['error' => 'method not allowed']);
