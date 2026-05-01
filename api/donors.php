<?php
/**
 * api/donors.php — Donor Registration Endpoint
 *
 * POST  — Register a new donor (Admin or Lab_Technician only)
 * GET   — Retrieve donor records
 * PUT   — Update donor record
 *
 * Requirements: 2.3, 3.1, 3.2, 3.3, 3.4, 3.5, 10.2, 10.3, 10.5, 10.6
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/NotificationService.php';

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
} elseif ($method === 'PUT') {
    handlePut();
} elseif ($method === 'DELETE') {
    handleDelete();
} else {
    http_response_code(405);
    header('Allow: GET, POST, PUT, DELETE');
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

// -------------------------------------------------------------------------
// POST — Register a new donor
// -------------------------------------------------------------------------

function handlePost(): void
{
    // Allow self-registration (no session required) OR staff creating a donor
    $isStaff = !empty($_SESSION['auth']);
    if (!$isStaff) {
        // Public self-registration — start session if needed
        if (session_status() === PHP_SESSION_NONE) session_start();
    } else {
        Auth::requireRole(['Administrator', 'Lab_Technician']);
    }

    // Parse JSON body
    $raw = file_get_contents('php://input');
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
    $required = ['name', 'date_of_birth', 'blood_type', 'national_id', 'email', 'phone'];
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

    // Validate email format
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid email address format.']);
        exit;
    }

    $passwordHash = !empty($data['password'])
        ? password_hash($data['password'], PASSWORD_BCRYPT)
        : null;

    // Insert into donors table
    try {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare(
            'INSERT INTO donors
                (name, date_of_birth, blood_type, national_id, email, phone, medical_history_flag, password_hash)
             VALUES
                (:name, :date_of_birth, :blood_type, :national_id, :email, :phone, :medical_history_flag, :password_hash)'
        );

        $stmt->execute([
            ':name'                 => $data['name'],
            ':date_of_birth'        => $data['date_of_birth'],
            ':blood_type'           => $data['blood_type'],
            ':national_id'          => $data['national_id'],
            ':email'                => $data['email'],
            ':phone'                => $data['phone'],
            ':medical_history_flag' => isset($data['medical_history_flag']) ? (int) $data['medical_history_flag'] : 0,
            ':password_hash'        => $passwordHash,
        ]);

        $donorId = (int) $pdo->lastInsertId();

        // Send welcome email in a fully isolated block — never affects registration
        try {
            if (class_exists('NotificationService')) {
                $notif = new NotificationService();
                @$notif->sendWelcomeEmail($data['email'], $data['name'], $data['blood_type']);
            }
        } catch (\Throwable $ignored) {
            error_log('Welcome email failed (non-fatal): ' . $ignored->getMessage());
        }

        http_response_code(201);
        echo json_encode(['donor_id' => $donorId]);

    } catch (\PDOException $e) {
        // Duplicate national_id or email (MySQL error 1062 — unique constraint violation)
        if ($e->getCode() === '23000') {
            $message = 'A donor with the same national ID or email already exists.';

            // Provide a more specific message when possible
            if (str_contains($e->getMessage(), 'national_id')) {
                $message = 'A donor with this national ID already exists.';
            } elseif (str_contains($e->getMessage(), 'email')) {
                $message = 'A donor with this email already exists.';
            }

            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'message' => $message]);
            exit;
        }

        // Unexpected database error
        error_log('BDMS donors POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
        exit;
    }
}

// -------------------------------------------------------------------------
// GET — Retrieve donor records
// -------------------------------------------------------------------------

function handleGet(): void
{
    Auth::requireRole(['Administrator', 'Lab_Technician', 'Doctor']);

    try {
        $pdo = getDbConnection();

        // ?id=<int> — single donor lookup
        if (isset($_GET['id'])) {
            $id   = (int) $_GET['id'];
            $stmt = $pdo->prepare('SELECT * FROM donors WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $donor = $stmt->fetch();

            if ($donor === false) {
                http_response_code(404);
                echo json_encode(['error' => 'donor not found']);
                exit;
            }

            http_response_code(200);
            echo json_encode($donor);
            exit;
        }

        // ?search=<string> — optional filter by name or national_id
        if (isset($_GET['search']) && $_GET['search'] !== '') {
            $like = '%' . $_GET['search'] . '%';
            $stmt = $pdo->prepare(
                'SELECT * FROM donors WHERE name LIKE ? OR national_id LIKE ? ORDER BY id ASC'
            );
            $stmt->execute([$like, $like]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM donors ORDER BY id ASC');
            $stmt->execute();
        }

        $donors = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode($donors);

    } catch (\PDOException $e) {
        error_log('BDMS donors GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
        exit;
    }
}

// -------------------------------------------------------------------------
// PUT — Update an existing donor record
// -------------------------------------------------------------------------

function handlePut(): void
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

    if ($data === null) {
        $data = [];
    }

    // Require ?id query param
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'missing id parameter']);
        exit;
    }

    $id = (int) $_GET['id'];

    // Updateable fields
    $allowed = ['name', 'date_of_birth', 'blood_type', 'phone', 'medical_history_flag', 'last_donation_date'];
    $setClauses = [];
    $params     = [];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $setClauses[] = "`{$field}` = ?";
            $params[]     = $data[$field];
        }
    }

    // Nothing to update — still verify donor exists and return success
    try {
        $pdo = getDbConnection();

        // Verify donor exists
        $check = $pdo->prepare('SELECT id FROM donors WHERE id = ? LIMIT 1');
        $check->execute([$id]);

        if ($check->fetch() === false) {
            http_response_code(404);
            echo json_encode(['error' => 'donor not found']);
            exit;
        }

        if (!empty($setClauses)) {
            $params[] = $id;
            $sql      = 'UPDATE donors SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $stmt     = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        http_response_code(200);
        echo json_encode(['message' => 'donor updated']);

    } catch (\PDOException $e) {
        error_log('BDMS donors PUT error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
        exit;
    }
}

// -------------------------------------------------------------------------
// DELETE — Permanently remove a donor record
// -------------------------------------------------------------------------

function handleDelete(): void
{
    // Only Admin or Lab_Technician can delete donors
    Auth::requireRole(['Administrator', 'Lab_Technician']);

    // Require ?id query param
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'missing id parameter']);
        exit;
    }

    $id = (int) $_GET['id'];

    try {
        $pdo = getDbConnection();

        // Verify the donor exists before attempting deletion
        $check = $pdo->prepare('SELECT id FROM donors WHERE id = ? LIMIT 1');
        $check->execute([$id]);
        if ($check->fetch() === false) {
            http_response_code(404);
            echo json_encode(['error' => 'donor not found']);
            exit;
        }

        // Step 1: Delete lab results linked to this donor's blood units
        $pdo->prepare(
            'DELETE FROM lab_results WHERE blood_unit_id IN (
                SELECT id FROM blood_units WHERE donor_id = ?
             )'
        )->execute([$id]);

        // Step 2: Delete transfusions linked to this donor's blood units
        $pdo->prepare(
            'DELETE FROM transfusions WHERE blood_unit_id IN (
                SELECT id FROM blood_units WHERE donor_id = ?
             )'
        )->execute([$id]);

        // Step 3: Delete the donor's blood units
        $pdo->prepare('DELETE FROM blood_units WHERE donor_id = ?')->execute([$id]);

        // Step 4: Delete any notifications linked to this donor
        $pdo->prepare('DELETE FROM notifications WHERE donor_id = ?')->execute([$id]);

        // Step 5: Delete the donor's appointments
        $pdo->prepare('DELETE FROM appointments WHERE donor_id = ?')->execute([$id]);

        // Step 6: Delete messages sent to or from this donor
        $pdo->prepare("DELETE FROM messages WHERE (sender_type='donor' AND sender_id=?) OR (recipient_type='donor' AND recipient_id=?)")->execute([$id, $id]);

        // Step 7: Delete the donor record itself
        $pdo->prepare('DELETE FROM donors WHERE id = ?')->execute([$id]);

        http_response_code(200);
        echo json_encode(['message' => 'donor deleted']);

    } catch (\PDOException $e) {
        error_log('BDMS donors DELETE error [' . $e->getCode() . ']: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Delete failed: ' . $e->getMessage()]);
        exit;
    }
}
