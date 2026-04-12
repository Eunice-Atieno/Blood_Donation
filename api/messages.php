<?php
/**
 * api/messages.php — Unified Messaging Between All Users
 *
 * Any authenticated user (staff or donor) can send and receive messages.
 * Donors are restricted to messaging staff only (not other donors).
 *
 * GET  ?find=name    → search for message recipients by name (autocomplete)
 * GET               → list messages received by the current user (inbox)
 * GET  ?sent=1      → list messages sent by the current user
 * POST { recipient_type, recipient_id, message } → send a message
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';

// Tell the client to expect JSON
header('Content-Type: application/json');

// Start the session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Identify the current user (staff or donor) ────────────────────────────────
// We check both session types so both staff and donors can use this endpoint.
$senderType = null;
$senderId   = null;

// Check for an active staff session (30-minute inactivity timeout)
if (!empty($_SESSION['auth'])) {
    $auth = $_SESSION['auth'];
    if ((time() - (int)$auth['last_active']) < 1800) {
        $_SESSION['auth']['last_active'] = time(); // refresh the timeout
        $senderType = 'staff';
        $senderId   = (int) $auth['staff_id'];
    }
}

// If no staff session, check for an active donor session
if ($senderType === null && !empty($_SESSION['donor'])) {
    $donor = $_SESSION['donor'];
    if ((time() - (int)$donor['last_active']) < 1800) {
        $_SESSION['donor']['last_active'] = time(); // refresh the timeout
        $senderType = 'donor';
        $senderId   = (int) $donor['donor_id'];
    }
}

// Reject unauthenticated requests
if ($senderType === null) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

/**
 * Auto-create the messages table if it doesn't exist yet.
 * This avoids needing a manual SQL migration on first use.
 */
function ensureMessagesTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS messages (
            id               INT          NOT NULL AUTO_INCREMENT,
            sender_type      ENUM('staff','donor') NOT NULL,   -- who sent the message
            sender_id        INT          NOT NULL,
            recipient_type   ENUM('staff','donor') NOT NULL,   -- who receives the message
            recipient_id     INT          NOT NULL,
            message          TEXT         NOT NULL,
            sent_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_read          TINYINT(1)   NOT NULL DEFAULT 0,  -- 0 = unread, 1 = read
            PRIMARY KEY (id),
            KEY idx_recipient (recipient_type, recipient_id),  -- fast inbox lookup
            KEY idx_sender    (sender_type, sender_id)         -- fast sent lookup
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $pdo = getDbConnection();
        ensureMessagesTable($pdo);

        // ── Recipient search for autocomplete (?find=...) ─────────────────────
        if (isset($_GET['find'])) {
            $q       = '%' . trim($_GET['find']) . '%';
            $results = [];

            // Always search staff — exclude self if the sender is also staff
            $sql = 'SELECT id, username AS name, role, \'staff\' AS type FROM staff WHERE username LIKE :q';
            if ($senderType === 'staff') $sql .= ' AND id != :self'; // don't show yourself
            $sql .= ' ORDER BY username LIMIT 8';
            $s = $pdo->prepare($sql);
            $s->bindValue(':q', $q);
            if ($senderType === 'staff') $s->bindValue(':self', $senderId, PDO::PARAM_INT);
            $s->execute();
            $results = array_merge($results, $s->fetchAll());

            // Only staff can message donors — donors cannot message other donors
            if ($senderType === 'staff') {
                $sql2 = 'SELECT id, name, blood_type AS role, \'donor\' AS type FROM donors WHERE name LIKE :q ORDER BY name LIMIT 8';
                $s2   = $pdo->prepare($sql2);
                $s2->bindValue(':q', $q);
                $s2->execute();
                $results = array_merge($results, $s2->fetchAll());
            }

            echo json_encode($results);
            exit;
        }

        $search = trim($_GET['search'] ?? '');
        $sent   = !empty($_GET['sent']); // true = sent box, false = inbox

        if ($sent) {
            // ── Sent messages ─────────────────────────────────────────────────
            // Fetch messages where the current user is the sender.
            // Use a CASE expression to resolve the recipient's display name.
            $sql = 'SELECT m.id, m.recipient_type, m.recipient_id, m.message, m.sent_at, m.is_read,
                           CASE m.recipient_type
                               WHEN \'staff\' THEN (SELECT username FROM staff  WHERE id = m.recipient_id)
                               WHEN \'donor\' THEN (SELECT name    FROM donors WHERE id = m.recipient_id)
                           END AS recipient_name
                      FROM messages m
                     WHERE m.sender_type = :stype AND m.sender_id = :sid';
            if ($search !== '') $sql .= ' AND m.message LIKE :q';
            $sql .= ' ORDER BY m.sent_at DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':stype', $senderType);
            $stmt->bindValue(':sid',   $senderId, PDO::PARAM_INT);
            if ($search !== '') $stmt->bindValue(':q', '%' . $search . '%');
            $stmt->execute();

        } else {
            // ── Inbox ─────────────────────────────────────────────────────────
            // Fetch messages where the current user is the recipient.
            // Use a CASE expression to resolve the sender's display name.
            $sql = 'SELECT m.id, m.sender_type, m.sender_id, m.message, m.sent_at, m.is_read,
                           CASE m.sender_type
                               WHEN \'staff\' THEN (SELECT username FROM staff  WHERE id = m.sender_id)
                               WHEN \'donor\' THEN (SELECT name    FROM donors WHERE id = m.sender_id)
                           END AS sender_name
                      FROM messages m
                     WHERE m.recipient_type = :rtype AND m.recipient_id = :rid';
            if ($search !== '') $sql .= ' AND m.message LIKE :q';
            $sql .= ' ORDER BY m.sent_at DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':rtype', $senderType);
            $stmt->bindValue(':rid',   $senderId, PDO::PARAM_INT);
            if ($search !== '') $stmt->bindValue(':q', '%' . $search . '%');
            $stmt->execute();

            // Mark all retrieved inbox messages as read
            $pdo->prepare(
                'UPDATE messages SET is_read = 1
                  WHERE recipient_type = :rtype AND recipient_id = :rid AND is_read = 0'
            )->execute([':rtype' => $senderType, ':rid' => $senderId]);
        }

        echo json_encode($stmt->fetchAll());

    } catch (\PDOException $e) {
        error_log('messages GET: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    // Read and parse the JSON body
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $recipientType = $body['recipient_type'] ?? '';       // 'staff' or 'donor'
    $recipientId   = (int) ($body['recipient_id'] ?? 0);
    $message       = trim($body['message'] ?? '');

    // All three fields are required
    if (!in_array($recipientType, ['staff', 'donor'], true) || !$recipientId || $message === '') {
        http_response_code(422);
        echo json_encode(['error' => 'recipient_type, recipient_id, and message are required']);
        exit;
    }

    // Enforce donor restriction: donors can only message staff, not other donors
    if ($senderType === 'donor' && $recipientType !== 'staff') {
        http_response_code(403);
        echo json_encode(['error' => 'donors can only send messages to staff']);
        exit;
    }

    try {
        $pdo = getDbConnection();
        ensureMessagesTable($pdo);

        // Verify the recipient actually exists in the database
        if ($recipientType === 'staff') {
            $check = $pdo->prepare('SELECT id FROM staff  WHERE id = :id LIMIT 1');
        } else {
            $check = $pdo->prepare('SELECT id FROM donors WHERE id = :id LIMIT 1');
        }
        $check->execute([':id' => $recipientId]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'recipient not found']);
            exit;
        }

        // Prevent a user from sending a message to themselves
        if ($recipientType === $senderType && $recipientId === $senderId) {
            http_response_code(422);
            echo json_encode(['error' => 'cannot send a message to yourself']);
            exit;
        }

        // Insert the message into the database
        $stmt = $pdo->prepare(
            'INSERT INTO messages (sender_type, sender_id, recipient_type, recipient_id, message)
             VALUES (:stype, :sid, :rtype, :rid, :msg)'
        );
        $stmt->execute([
            ':stype' => $senderType,
            ':sid'   => $senderId,
            ':rtype' => $recipientType,
            ':rid'   => $recipientId,
            ':msg'   => $message,
        ]);

        // Return the new message's ID so the frontend can confirm success
        http_response_code(201);
        echo json_encode(['message_id' => (int) $pdo->lastInsertId()]);

    } catch (\PDOException $e) {
        error_log('messages POST: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// Any other HTTP method is not supported
http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['error' => 'method not allowed']);
