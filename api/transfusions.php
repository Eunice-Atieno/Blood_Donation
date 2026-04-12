<?php
/**
 * api/transfusions.php — Transfusion Recording Endpoint
 *
 * POST — Record a new transfusion (Admin, Doctor, or Lab_Technician)
 * GET  — Retrieve all transfusion records (Admin, Doctor, or Lab_Technician)
 *
 * Requirements: 8.1, 8.2, 8.3, 8.4
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/InventoryManager.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

// -------------------------------------------------------------------------
// Route by HTTP method
// -------------------------------------------------------------------------

if ($method === 'POST') {
    handlePost();
} elseif ($method === 'GET') {
    handleGet();
} else {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

// -------------------------------------------------------------------------
// POST — Record a new transfusion
// -------------------------------------------------------------------------

function handlePost(): void
{
    Auth::requireRole(['Administrator', 'Doctor', 'Lab_Technician']);

    // Parse JSON body
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if ($data === null && $raw !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        exit;
    }

    // Treat empty body as empty array
    if ($data === null) {
        $data = [];
    }

    // Validate required fields
    $required = ['blood_unit_id', 'patient_identifier'];
    $missing  = [];

    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        http_response_code(422);
        echo json_encode(['error' => 'missing fields', 'fields' => $missing]);
        exit;
    }

    $bloodUnitId        = (int) $data['blood_unit_id'];
    $patientIdentifier  = $data['patient_identifier'];

    try {
        $pdo = getDbConnection();

        // Check that the blood unit exists and is available
        $stmt = $pdo->prepare(
            'SELECT status FROM blood_units WHERE id = :id'
        );
        $stmt->execute([':id' => $bloodUnitId]);
        $unit = $stmt->fetch();

        if ($unit === false || $unit['status'] !== 'available') {
            http_response_code(409);
            echo json_encode(['error' => 'unit not available']);
            exit;
        }

        // Get the current staff ID from the session
        $staff   = Auth::currentStaff();
        $staffId = (int) $staff['staff_id'];

        // Insert the transfusion record
        $stmt = $pdo->prepare(
            'INSERT INTO transfusions
                (blood_unit_id, patient_identifier, transfusion_date, staff_id)
             VALUES
                (:blood_unit_id, :patient_identifier, NOW(), :staff_id)'
        );
        $stmt->execute([
            ':blood_unit_id'       => $bloodUnitId,
            ':patient_identifier'  => $patientIdentifier,
            ':staff_id'            => $staffId,
        ]);

        $transfusionId = (int) $pdo->lastInsertId();

        // Mark the blood unit as transfused
        $pdo->prepare(
            "UPDATE blood_units SET status = 'transfused' WHERE id = :id"
        )->execute([':id' => $bloodUnitId]);

        // Decrement inventory count for the affected blood type
        $manager = new InventoryManager();
        $manager->updateInventory($bloodUnitId);

        http_response_code(201);
        echo json_encode(['transfusion_id' => $transfusionId]);

    } catch (\PDOException $e) {
        error_log('BDMS transfusions POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
        exit;
    }
}

// -------------------------------------------------------------------------
// GET — Return all transfusion records
// -------------------------------------------------------------------------

function handleGet(): void
{
    Auth::requireRole(['Administrator', 'Doctor', 'Lab_Technician']);

    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->query(
            'SELECT id, blood_unit_id, patient_identifier, transfusion_date, staff_id
               FROM transfusions
              ORDER BY transfusion_date DESC'
        );

        $transfusions = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode($transfusions);

    } catch (\PDOException $e) {
        error_log('BDMS transfusions GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
        exit;
    }
}
