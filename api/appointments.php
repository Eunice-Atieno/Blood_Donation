<?php
/**
 * api/appointments.php — Donor Appointment Management
 *
 * GET    → list donor's appointments
 * POST   → schedule new appointment  { appointment_date, appointment_time, notes }
 * PUT    → reschedule                { id, appointment_date, appointment_time, notes }
 * DELETE → cancel                   ?id=<int>
 *
 * Requires active donor session.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/NotificationService.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isDonor = !empty($_SESSION['donor']);
$isStaff = !empty($_SESSION['auth']);

if (!$isDonor && !$isStaff) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$donorId = $isDonor ? (int) $_SESSION['donor']['donor_id'] : null;
$method  = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $pdo = getDbConnection();

        if ($isStaff) {
            // Staff: return all appointments joined with donor name
            $stmt = $pdo->query(
                'SELECT a.id, a.donor_id, d.name AS donor_name, a.appointment_date,
                        a.appointment_time, a.status, a.notes, a.created_at
                   FROM appointments a
                   JOIN donors d ON d.id = a.donor_id
                  ORDER BY a.appointment_date DESC, a.appointment_time DESC
                  LIMIT 200'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, appointment_date, appointment_time, status, notes, created_at
                   FROM appointments
                  WHERE donor_id = :donor_id
                  ORDER BY appointment_date DESC, appointment_time DESC'
            );
            $stmt->execute([':donor_id' => $donorId]);
        }

        echo json_encode($stmt->fetchAll());
    } catch (\PDOException $e) {
        error_log('appointments GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    if (!$isDonor) { http_response_code(403); echo json_encode(['error' => 'donors only']); exit; }
    $body = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        exit;
    }

    $date  = trim($body['appointment_date'] ?? '');
    $time  = trim($body['appointment_time'] ?? '');
    $notes = trim($body['notes'] ?? '');

    if ($date === '' || $time === '') {
        http_response_code(422);
        echo json_encode(['error' => 'appointment_date and appointment_time are required']);
        exit;
    }

    // Must be a future date
    if ($date < date('Y-m-d')) {
        http_response_code(422);
        echo json_encode(['error' => 'appointment_date must be today or in the future']);
        exit;
    }

    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO appointments (donor_id, appointment_date, appointment_time, notes)
             VALUES (:donor_id, :date, :time, :notes)'
        );
        $stmt->execute([
            ':donor_id' => $donorId,
            ':date'     => $date,
            ':time'     => $time,
            ':notes'    => $notes ?: null,
        ]);

        http_response_code(201);
        echo json_encode(['id' => (int) $pdo->lastInsertId(), 'message' => 'appointment scheduled']);

        // Send confirmation email to donor and alert lab technicians (soft failure)
        try {
            if (class_exists('NotificationService')) {
                $donorStmt = $pdo->prepare('SELECT name, email FROM donors WHERE id = ? LIMIT 1');
                $donorStmt->execute([$donorId]);
                $donor = $donorStmt->fetch();
                if ($donor) {
                    $notif = new NotificationService();
                    @$notif->sendAppointmentConfirmation($donor['email'], $donor['name'], $date, $time, $notes);
                    @$notif->notifyLabTechsOfAppointment($donor['name'], $date, $time, $notes);
                }
            }
        } catch (\Throwable $ignored) {
            error_log('Appointment email failed (non-fatal): ' . $ignored->getMessage());
        }
    } catch (\PDOException $e) {
        error_log('appointments POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── PUT ──────────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        exit;
    }

    // ── Staff: update appointment status (completed / missed) ─────────────────
    if ($isStaff) {
        $id     = (int) ($body['id'] ?? 0);
        $status = $body['status'] ?? '';

        if (!$id || !in_array($status, ['completed', 'missed', 'scheduled', 'cancelled'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'id and valid status are required']);
            exit;
        }

        try {
            $pdo  = getDbConnection();

            // Ensure missed status column exists (ALTER TABLE if needed)
            try {
                $pdo->exec(
                    "ALTER TABLE appointments MODIFY COLUMN status
                     ENUM('scheduled','cancelled','completed','missed') NOT NULL DEFAULT 'scheduled'"
                );
            } catch (\PDOException $ignored) {}

            $stmt = $pdo->prepare('UPDATE appointments SET status = :status WHERE id = :id');
            $stmt->execute([':status' => $status, ':id' => $id]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'appointment not found']);
                exit;
            }

            echo json_encode(['message' => 'appointment status updated']);
        } catch (\PDOException $e) {
            error_log('appointments PUT staff error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'internal server error']);
        }
        exit;
    }

    // ── Donor: reschedule ─────────────────────────────────────────────────────
    if (!$isDonor) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

    $id    = (int) ($body['id'] ?? 0);
    $date  = trim($body['appointment_date'] ?? '');
    $time  = trim($body['appointment_time'] ?? '');
    $notes = trim($body['notes'] ?? '');

    if (!$id || $date === '' || $time === '') {
        http_response_code(422);
        echo json_encode(['error' => 'id, appointment_date, and appointment_time are required']);
        exit;
    }

    if ($date < date('Y-m-d')) {
        http_response_code(422);
        echo json_encode(['error' => 'appointment_date must be today or in the future']);
        exit;
    }

    try {
        $pdo  = getDbConnection();

        // Verify ownership
        $check = $pdo->prepare('SELECT id FROM appointments WHERE id=:id AND donor_id=:donor_id LIMIT 1');
        $check->execute([':id' => $id, ':donor_id' => $donorId]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'appointment not found']);
            exit;
        }

        $stmt = $pdo->prepare(
            'UPDATE appointments
                SET appointment_date=:date, appointment_time=:time, notes=:notes, status="scheduled"
              WHERE id=:id AND donor_id=:donor_id'
        );
        $stmt->execute([
            ':date'     => $date,
            ':time'     => $time,
            ':notes'    => $notes ?: null,
            ':id'       => $id,
            ':donor_id' => $donorId,
        ]);

        echo json_encode(['message' => 'appointment rescheduled']);
    } catch (\PDOException $e) {
        error_log('appointments PUT error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$isDonor) { http_response_code(403); echo json_encode(['error' => 'donors only']); exit; }
    $id = (int) ($_GET['id'] ?? 0);

    if (!$id) {
        http_response_code(422);
        echo json_encode(['error' => 'id is required']);
        exit;
    }

    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare(
            'UPDATE appointments SET status="cancelled"
              WHERE id=:id AND donor_id=:donor_id AND status="scheduled"'
        );
        $stmt->execute([':id' => $id, ':donor_id' => $donorId]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'appointment not found or already cancelled']);
            exit;
        }

        echo json_encode(['message' => 'appointment cancelled']);
    } catch (\PDOException $e) {
        error_log('appointments DELETE error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

http_response_code(405);
header('Allow: GET, POST, PUT, DELETE');
echo json_encode(['error' => 'method not allowed']);
