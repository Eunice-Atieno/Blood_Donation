<?php
/**
 * api/lab_results.php — Lab Test Results
 *
 * GET    ?unit_id= → get result for a specific blood unit
 * GET    (no param) → list all results (Lab_Technician / Administrator)
 * POST   → record test result for a blood unit
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Auth::requireRole(['Administrator', 'Lab_Technician']);

$method  = $_SERVER['REQUEST_METHOD'];
$staff   = Auth::currentStaff();
$staffId = (int) $staff['staff_id'];

// ── Auto-create lab_results table if it doesn't exist ────────────────────────
function ensureLabResultsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS lab_results (
            id              INT          NOT NULL AUTO_INCREMENT,
            blood_unit_id   INT          NOT NULL,
            tested_by       INT          NOT NULL,
            hiv             TINYINT(1)   NOT NULL DEFAULT 0,
            hepatitis_b     TINYINT(1)   NOT NULL DEFAULT 0,
            hepatitis_c     TINYINT(1)   NOT NULL DEFAULT 0,
            syphilis        TINYINT(1)   NOT NULL DEFAULT 0,
            malaria         TINYINT(1)   NOT NULL DEFAULT 0,
            passed          TINYINT(1)   NOT NULL DEFAULT 0,
            notes           TEXT         NULL,
            tested_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_lab_results_unit (blood_unit_id),
            CONSTRAINT fk_lab_results_unit
                FOREIGN KEY (blood_unit_id) REFERENCES blood_units (id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_lab_results_staff
                FOREIGN KEY (tested_by) REFERENCES staff (id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $pdo = getDbConnection();
        ensureLabResultsTable($pdo);
        if (isset($_GET['unit_id'])) {
            $unitId = (int) $_GET['unit_id'];
            $stmt   = $pdo->prepare(
                'SELECT lr.*, s.username AS tested_by_name
                   FROM lab_results lr
                   LEFT JOIN staff s ON s.id = lr.tested_by
                  WHERE lr.blood_unit_id = :uid LIMIT 1'
            );
            $stmt->execute([':uid' => $unitId]);
            $row = $stmt->fetch();
            echo $row ? json_encode($row) : json_encode(null);
        } else {
            $stmt = $pdo->query(
                'SELECT lr.*, s.username AS tested_by_name,
                        bu.blood_type, bu.collection_date
                   FROM lab_results lr
                   LEFT JOIN staff s ON s.id = lr.tested_by
                   LEFT JOIN blood_units bu ON bu.id = lr.blood_unit_id
                  ORDER BY lr.tested_at DESC'
            );
            echo json_encode($stmt->fetchAll());
        }
    } catch (\PDOException $e) {
        error_log('lab_results GET: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── POST — record result ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $unitId = (int) ($body['blood_unit_id'] ?? 0);

    if (!$unitId) {
        http_response_code(422);
        echo json_encode(['error' => 'blood_unit_id is required']);
        exit;
    }

    $hiv       = (int)(bool)($body['hiv']        ?? false);
    $hepB      = (int)(bool)($body['hepatitis_b'] ?? false);
    $hepC      = (int)(bool)($body['hepatitis_c'] ?? false);
    $syphilis  = (int)(bool)($body['syphilis']    ?? false);
    $malaria   = (int)(bool)($body['malaria']     ?? false);
    $notes     = trim($body['notes'] ?? '');

    // Passed = all tests negative
    $passed = ($hiv + $hepB + $hepC + $syphilis + $malaria === 0) ? 1 : 0;

    try {
        $pdo = getDbConnection();
        ensureLabResultsTable($pdo);

        // Verify unit exists
        $check = $pdo->prepare('SELECT id, status FROM blood_units WHERE id = :id LIMIT 1');
        $check->execute([':id' => $unitId]);
        $unit  = $check->fetch();
        if (!$unit) {
            http_response_code(404);
            echo json_encode(['error' => 'blood unit not found']);
            exit;
        }

        // Insert or update result (uq_lab_results_unit enforces one result per unit)
        $stmt = $pdo->prepare(
            'INSERT INTO lab_results
               (blood_unit_id, tested_by, hiv, hepatitis_b, hepatitis_c, syphilis, malaria, passed, notes)
             VALUES (:uid, :sid, :hiv, :hepb, :hepc, :syph, :mal, :pass, :notes)
             ON DUPLICATE KEY UPDATE
               tested_by=VALUES(tested_by), hiv=VALUES(hiv), hepatitis_b=VALUES(hepatitis_b),
               hepatitis_c=VALUES(hepatitis_c), syphilis=VALUES(syphilis), malaria=VALUES(malaria),
               passed=VALUES(passed), notes=VALUES(notes), tested_at=NOW()'
        );
        $stmt->execute([
            ':uid'   => $unitId,
            ':sid'   => $staffId,
            ':hiv'   => $hiv,
            ':hepb'  => $hepB,
            ':hepc'  => $hepC,
            ':syph'  => $syphilis,
            ':mal'   => $malaria,
            ':pass'  => $passed,
            ':notes' => $notes ?: null,
        ]);

        // If passed, mark blood unit as available; if failed, mark as expired (quarantine)
        $newStatus = $passed ? 'available' : 'expired';
        $pdo->prepare('UPDATE blood_units SET status = :s WHERE id = :id')
            ->execute([':s' => $newStatus, ':id' => $unitId]);

        http_response_code(201);
        echo json_encode(['message' => 'result recorded', 'passed' => (bool)$passed, 'unit_status' => $newStatus]);
    } catch (\PDOException $e) {
        error_log('lab_results POST: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['error' => 'method not allowed']);
