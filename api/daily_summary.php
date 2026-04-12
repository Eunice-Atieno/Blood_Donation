<?php
/**
 * api/daily_summary.php — Daily Appointment Summary
 *
 * GET  ?date=YYYY-MM-DD  → returns today's (or given date's) appointments for lab dashboard
 * POST                   → sends the daily summary email to all Lab Technicians
 *
 * Requires Lab_Technician or Administrator session.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/NotificationService.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Auth::requireRole(['Administrator', 'Lab_Technician']);

$method = $_SERVER['REQUEST_METHOD'];
$date   = $_GET['date'] ?? date('Y-m-d');

// ── GET — return appointments for a given date ────────────────────────────────
if ($method === 'GET') {
    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare(
            "SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.notes,
                    d.name AS donor_name, d.blood_type, d.phone
               FROM appointments a
               JOIN donors d ON d.id = a.donor_id
              WHERE a.appointment_date = :date
              ORDER BY a.appointment_time ASC"
        );
        $stmt->execute([':date' => $date]);
        echo json_encode(['date' => $date, 'appointments' => $stmt->fetchAll()]);
    } catch (\PDOException $e) {
        error_log('daily_summary GET: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
    }
    exit;
}

// ── POST — send daily summary email OR send reminders ────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? 'summary'; // 'summary' | 'reminders'

    try {
        $notif = new NotificationService();

        if ($action === 'reminders') {
            $result = $notif->sendTodayAppointmentReminders();
            echo json_encode([
                'message' => "Reminders sent to {$result['sent']} donor(s).",
                'result'  => $result,
            ]);
        } elseif ($action === 'reminders_lab') {
            // Send today's schedule summary + reminder to all lab techs
            $result = $notif->sendDailyScheduleSummary();
            echo json_encode([
                'message' => "Schedule reminder sent to {$result['sent']} lab technician(s).",
                'result'  => $result,
            ]);
        } else {
            $result = $notif->sendDailyScheduleSummary();
            echo json_encode([
                'message' => "Summary sent to {$result['sent']} lab technician(s).",
                'result'  => $result,
            ]);
        }
    } catch (\Exception $e) {
        error_log('daily_summary POST: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
