<?php
/**
 * api/blood_units.php — Blood Unit Collection Endpoint
 *
 * POST — Record a new blood unit collection (Admin or Nurse only)
 * GET  — Retrieve blood unit records (implemented in a later task)
 *
 * Requirements: 5.1, 5.2, 5.3, 5.4, 10.2, 10.3, 10.5, 10.6
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/EligibilityChecker.php';
require_once __DIR__ . '/../src/InventoryManager.php';
require_once __DIR__ . '/../src/CompatibilityEngine.php';

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
// GET — Compatibility search (?patient_blood_type=A+&requested=2)
//       or list all blood units
// -------------------------------------------------------------------------

function handleGet(): void
{
    // Compatibility search — staff only
    if (isset($_GET['patient_blood_type'])) {
        Auth::requireRole(['Administrator', 'Lab_Technician', 'Doctor']);

        $patientType    = $_GET['patient_blood_type'];
        $requestedUnits = max(1, (int) ($_GET['requested'] ?? 1));

        $engine = new CompatibilityEngine();
        $result = $engine->findCompatibleUnits($patientType, $requestedUnits);

        if (isset($result['error'])) {
            http_response_code(422);
            echo json_encode($result);
            exit;
        }

        http_response_code(200);
        echo json_encode($result);
        exit;
    }

    // List blood units — optionally filtered by donor_id (donor portal self-service)
    // Donor session OR staff session required
    $isDonorSession = !empty($_SESSION['donor']);
    $isStaffSession = !empty($_SESSION['auth']);

    if (!$isDonorSession && !$isStaffSession) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }

    try {
        $pdo = getDbConnection();

        if (isset($_GET['donor_id'])) {
            $donorId = (int) $_GET['donor_id'];

            // Donors can only see their own units
            if ($isDonorSession && (int) $_SESSION['donor']['donor_id'] !== $donorId) {
                http_response_code(403);
                echo json_encode(['error' => 'forbidden']);
                exit;
            }

            $stmt = $pdo->prepare(
                'SELECT * FROM blood_units WHERE donor_id = :donor_id ORDER BY collection_date DESC'
            );
            $stmt->execute([':donor_id' => $donorId]);
        } else {
            // Staff only for full list
            if (!$isStaffSession) {
                http_response_code(403);
                echo json_encode(['error' => 'forbidden']);
                exit;
            }
            $stmt = $pdo->query('SELECT * FROM blood_units ORDER BY id ASC');
        }

        echo json_encode($stmt->fetchAll());
    } catch (\PDOException $e) {
        error_log('BDMS blood_units GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
}

// -------------------------------------------------------------------------
// POST — Record a new blood unit collection
// -------------------------------------------------------------------------

function handlePost(): void
{
    // Require Admin or Lab_Technician role
    Auth::requireRole(['Administrator', 'Lab_Technician']);

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
    $required = ['donor_id', 'blood_type'];
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

    $donorId   = (int) $data['donor_id'];
    $bloodType = $data['blood_type'];

    // Check donor eligibility before recording the unit
    $checker = new EligibilityChecker();
    $eligibility = $checker->verifyEligibility($donorId);

    if (!$eligibility['eligible']) {
        http_response_code(422);
        echo json_encode(['error' => 'ineligible', 'reason' => $eligibility['reason']]);
        exit;
    }

    // Insert the blood unit
    try {
        $pdo = getDbConnection();

        $today      = (new DateTimeImmutable('today'))->format('Y-m-d');
        $expiryDate = (new DateTimeImmutable('today'))->modify('+42 days')->format('Y-m-d');

        $stmt = $pdo->prepare(
            "INSERT INTO blood_units
                (donor_id, blood_type, collection_date, expiry_date, status)
             VALUES
                (:donor_id, :blood_type, :collection_date, :expiry_date, 'available')"
        );

        $stmt->execute([
            ':donor_id'        => $donorId,
            ':blood_type'      => $bloodType,
            ':collection_date' => $today,
            ':expiry_date'     => $expiryDate,
        ]);

        $newUnitId = (int) $pdo->lastInsertId();

        // Update inventory to reflect the new unit
        $manager = new InventoryManager();
        $manager->updateInventory($newUnitId);

        http_response_code(201);
        echo json_encode(['unit_id' => $newUnitId]);

    } catch (\PDOException $e) {
        error_log('BDMS blood_units POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
        exit;
    }
}
