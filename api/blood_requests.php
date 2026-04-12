<?php
/**
 * api/blood_requests.php — Blood Request Management
 *
 * GET    → list requests (Doctor sees own; Lab/Admin see all)
 * POST   → submit new request (Doctor only)
 * PUT    ?id= → update status (Lab_Technician/Admin: allocate/complete; Doctor: cancel)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Auth::requireRole(['Administrator', 'Lab_Technician', 'Doctor']);

$method = $_SERVER['REQUEST_METHOD'];
$staff  = Auth::currentStaff();
$role   = $staff['role'];
$staffId = (int) $staff['staff_id'];

// Auto-create blood_requests table if it doesn't exist yet
function ensureBloodRequestsTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS blood_requests (
            id                  INT          NOT NULL AUTO_INCREMENT,
            requested_by        INT          NOT NULL,
            patient_identifier  VARCHAR(100) NOT NULL,
            blood_type          VARCHAR(5)   NOT NULL,
            quantity            TINYINT      NOT NULL DEFAULT 1,
            priority            ENUM('normal','emergency') NOT NULL DEFAULT 'normal',
            status              ENUM('pending','allocated','completed','cancelled') NOT NULL DEFAULT 'pending',
            allocated_unit_id   INT          NULL,
            notes               TEXT         NULL,
            created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_br_staff
                FOREIGN KEY (requested_by) REFERENCES staff (id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $pdo = getDbConnection();
        ensureBloodRequestsTable($pdo);
        if (in_array($role, ['Administrator', 'Lab_Technician'], true)) {
            $stmt = $pdo->query(
                'SELECT r.*, s.username AS requested_by_name
                   FROM blood_requests r
                   LEFT JOIN staff s ON s.id = r.requested_by
                  ORDER BY r.created_at DESC'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT r.*, s.username AS requested_by_name
                   FROM blood_requests r
                   LEFT JOIN staff s ON s.id = r.requested_by
                  WHERE r.requested_by = :sid
                  ORDER BY r.created_at DESC'
            );
            $stmt->execute([':sid' => $staffId]);
        }
        echo json_encode($stmt->fetchAll());
    } catch (\PDOException $e) {
        error_log('blood_requests GET: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── POST — submit request ─────────────────────────────────────────────────────
if ($method === 'POST') {
    if (!in_array($role, ['Administrator', 'Doctor'], true)) {
        http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $bloodType  = trim($body['blood_type']        ?? '');
    $quantity   = (int) ($body['quantity']         ?? 1);
    $patientId  = trim($body['patient_identifier'] ?? '');
    $priority   = ($body['priority'] ?? '') === 'emergency' ? 'emergency' : 'normal';
    $notes      = trim($body['notes'] ?? '');

    if ($bloodType === '' || $quantity < 1 || $patientId === '') {
        http_response_code(422);
        echo json_encode(['error' => 'blood_type, quantity, and patient_identifier are required']);
        exit;
    }

    try {
        $pdo  = getDbConnection();
        ensureBloodRequestsTable($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO blood_requests
               (requested_by, patient_identifier, blood_type, quantity, priority, notes, status)
             VALUES (:sid, :pid, :bt, :qty, :pri, :notes, "pending")'
        );
        $stmt->execute([
            ':sid'   => $staffId,
            ':pid'   => $patientId,
            ':bt'    => $bloodType,
            ':qty'   => $quantity,
            ':pri'   => $priority,
            ':notes' => $notes ?: null,
        ]);
        http_response_code(201);
        echo json_encode(['request_id' => (int) $pdo->lastInsertId()]);
    } catch (\PDOException $e) {
        error_log('blood_requests POST: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── PUT — update status ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    $id   = (int) ($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!$id) { http_response_code(422); echo json_encode(['error' => 'id required']); exit; }

    $newStatus   = $body['status']       ?? '';
    $allocatedUnit = (int) ($body['allocated_unit_id'] ?? 0);

    $allowedTransitions = [
        'Administrator'   => ['pending' => ['allocated','cancelled'], 'allocated' => ['completed','cancelled']],
        'Lab_Technician'  => ['pending' => ['allocated','cancelled'], 'allocated' => ['completed','cancelled']],
        'Doctor'          => ['pending' => ['cancelled']],
    ];

    try {
        $pdo   = getDbConnection();
        ensureBloodRequestsTable($pdo);
        $check = $pdo->prepare('SELECT status, requested_by FROM blood_requests WHERE id = :id LIMIT 1');
        $check->execute([':id' => $id]);
        $req   = $check->fetch();

        if (!$req) { http_response_code(404); echo json_encode(['error' => 'request not found']); exit; }

        $allowed = $allowedTransitions[$role][$req['status']] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            http_response_code(403);
            echo json_encode(['error' => "cannot transition from {$req['status']} to {$newStatus} as {$role}"]);
            exit;
        }

        $sets    = ['status = :status'];
        $params  = [':status' => $newStatus, ':id' => $id];
        if ($allocatedUnit && $newStatus === 'allocated') {
            $sets[] = 'allocated_unit_id = :uid';
            $params[':uid'] = $allocatedUnit;
        }

        $pdo->prepare('UPDATE blood_requests SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);

        echo json_encode(['message' => 'updated']);
    } catch (\PDOException $e) {
        error_log('blood_requests PUT: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

http_response_code(405);
header('Allow: GET, POST, PUT');
echo json_encode(['error' => 'method not allowed']);
