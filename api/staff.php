<?php
/**
 * api/staff.php — Staff User Management (Administrator only)
 *
 * GET    → list all staff
 * POST   → create staff account
 * PUT    ?id= → update role / reset password / toggle lock
 * DELETE ?id= → disable (lock) account
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Auth::requireRole('Administrator');

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $pdo = getDbConnection();

        // Ensure email column exists on staff table (added in later migration)
        try {
            $pdo->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS email VARCHAR(100) NULL AFTER role");
        } catch (\PDOException $ignored) {}

        $stmt = $pdo->query(
            'SELECT id, username, role, email, failed_attempts, locked, created_at FROM staff ORDER BY id ASC'
        );
        $rows = $stmt->fetchAll();

        // Add formatted staff code based on role
        foreach ($rows as &$row) {
            $prefix = match($row['role']) {
                'Administrator'  => 'ADM',
                'Doctor'         => 'DOC',
                'Lab_Technician' => 'LAB',
                default          => 'STF',
            };
            $row['staff_code'] = 'KNH-' . $prefix . '-' . str_pad($row['id'], 3, '0', STR_PAD_LEFT);
        }
        unset($row);

        echo json_encode($rows);
    } catch (\PDOException $e) {
        error_log('staff GET: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── POST — create ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    $role     = $body['role']     ?? '';
    $email    = trim($body['email'] ?? '');

    $validRoles = ['Administrator', 'Doctor', 'Lab_Technician'];

    if ($username === '' || $password === '' || !in_array($role, $validRoles, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'username, password, and valid role are required']);
        exit;
    }

    // Validate email format if provided
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid email address format.']);
        exit;
    }

    if (strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['error' => 'password must be at least 8 characters']);
        exit;
    }

    try {
        $pdo  = getDbConnection();
        // Ensure email column exists (for existing installs)
        $pdo->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS email VARCHAR(100) NULL AFTER role");
        $stmt = $pdo->prepare(
            'INSERT INTO staff (username, password_hash, role, email) VALUES (:u, :p, :r, :e)'
        );
        $stmt->execute([
            ':u' => $username,
            ':p' => password_hash($password, PASSWORD_BCRYPT),
            ':r' => $role,
            ':e' => $email !== '' ? $email : null,
        ]);
        http_response_code(201);
        $newId = (int) $pdo->lastInsertId();

        // Send account creation notification email BEFORE sending response
        if ($email !== '') {
            try {
                if (!class_exists('NotificationService')) {
                    require_once __DIR__ . '/../src/NotificationService.php';
                }
                $notif = new NotificationService();
                $result = $notif->sendStaffAccountCreated($email, $username, $role, $username);
                if (!$result['success']) {
                    error_log('Staff welcome email failed: ' . ($result['error'] ?? 'unknown'));
                }
            } catch (\Throwable $e) {
                error_log('Staff welcome email exception: ' . $e->getMessage());
            }
        }

        echo json_encode(['id' => $newId]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['error' => 'username already exists']);
        } else {
            error_log('staff POST: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'internal server error']);
        }
    }
    exit;
}

// ── PUT — update ──────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $id   = (int) ($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true);

    if (!$id) { http_response_code(422); echo json_encode(['error' => 'id required']); exit; }

    $sets   = [];
    $params = [];

    $validRoles = ['Administrator', 'Doctor', 'Lab_Technician'];
    if (isset($body['role']) && in_array($body['role'], $validRoles, true)) {
        $sets[] = 'role = :role'; $params[':role'] = $body['role'];
    }
    if (isset($body['email'])) {
        $e = trim($body['email']);
        if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid email address format.']);
            exit;
        }
        $sets[] = 'email = :email'; $params[':email'] = $e !== '' ? $e : null;
    }
    if (isset($body['locked'])) {
        $sets[] = 'locked = :locked'; $params[':locked'] = (int)(bool)$body['locked'];
        // Reset failed attempts when unlocking
        if (!(bool)$body['locked']) {
            $sets[] = 'failed_attempts = 0';
        }
    }
    if (!empty($body['password'])) {
        if (strlen($body['password']) < 8) {
            http_response_code(422); echo json_encode(['error' => 'password must be at least 8 characters']); exit;
        }
        $sets[] = 'password_hash = :pw'; $params[':pw'] = password_hash($body['password'], PASSWORD_BCRYPT);
    }

    if (empty($sets)) { http_response_code(422); echo json_encode(['error' => 'nothing to update']); exit; }

    try {
        $pdo = getDbConnection();
        $params[':id'] = $id;
        $pdo->prepare('UPDATE staff SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        echo json_encode(['message' => 'updated']);
    } catch (\PDOException $e) {
        error_log('staff PUT: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── DELETE — lock or permanently delete account ───────────────────────────────
if ($method === 'DELETE') {
    $id     = (int) ($_GET['id'] ?? 0);
    $action = $_GET['action'] ?? 'disable'; // 'disable' | 'delete'

    if (!$id) { http_response_code(422); echo json_encode(['error' => 'id required']); exit; }

    // Prevent acting on own account
    $me = Auth::currentStaff();
    if ((int)$me['staff_id'] === $id) {
        http_response_code(422);
        echo json_encode(['error' => 'cannot modify your own account this way']);
        exit;
    }

    try {
        $pdo = getDbConnection();

        if ($action === 'delete') {
            // Remove linked records before deleting the staff account
            // 1. Null out lab_results.tested_by (set to NULL to preserve test records)
            try {
                $pdo->exec("ALTER TABLE lab_results MODIFY COLUMN tested_by INT NULL");
            } catch (\PDOException $ignored) {}
            $pdo->prepare('UPDATE lab_results SET tested_by = NULL WHERE tested_by = :id')->execute([':id' => $id]);

            // 2. Remove blood requests made by this staff member
            $pdo->prepare('DELETE FROM blood_requests WHERE requested_by = :id')->execute([':id' => $id]);

            // 3. Remove transfusions recorded by this staff member
            $pdo->prepare('DELETE FROM transfusions WHERE staff_id = :id')->execute([':id' => $id]);

            // 4. Remove staff notifications
            $pdo->prepare('DELETE FROM staff_notifications WHERE staff_id = :id')->execute([':id' => $id]);

            // 5. Delete the staff account
            $pdo->prepare('DELETE FROM staff WHERE id = :id')->execute([':id' => $id]);
            echo json_encode(['message' => 'account deleted']);
        } else {
            $pdo->prepare('UPDATE staff SET locked = 1 WHERE id = :id')->execute([':id' => $id]);
            echo json_encode(['message' => 'account disabled']);
        }
    } catch (\PDOException $e) {
        error_log('staff DELETE: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Delete failed: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
header('Allow: GET, POST, PUT, DELETE');
echo json_encode(['error' => 'method not allowed']);
