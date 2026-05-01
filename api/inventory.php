<?php
/**
 * api/inventory.php — Blood Inventory Endpoint
 *
 * GET — Return current inventory for all blood types (Admin or Lab_Technician)
 * PUT — Expire stale units and update inventory counts (Admin or Lab_Technician)
 *
 * Requirements: 6.5, 10.1, 10.2
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

if ($method === 'GET') {
    handleGet();
} elseif ($method === 'PUT') {
    handlePut();
} else {
    http_response_code(405);
    header('Allow: GET, PUT');
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

// -------------------------------------------------------------------------
// GET — Return current inventory for all blood types
// -------------------------------------------------------------------------

function handleGet(): void
{
    Auth::requireRole(['Administrator', 'Lab_Technician', 'Doctor']);

    try {
        $pdo     = getDbConnection();
        $manager = new InventoryManager();

        // Auto-expire stale units
        $manager->expireUnits();

        // Recalculate inventory counts for ALL blood types to ensure cache is in sync
        $bloodTypes = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
        foreach ($bloodTypes as $bt) {
            // Get any unit of this type to pass to updateInventory (it only needs the type)
            $s = $pdo->prepare('SELECT id FROM blood_units WHERE blood_type = ? LIMIT 1');
            $s->execute([$bt]);
            $row = $s->fetch();
            if ($row) {
                $manager->updateInventory((int) $row['id']);
            } else {
                // No units of this type — ensure count is 0
                $pdo->prepare('UPDATE blood_inventory SET unit_count = 0, low_stock = 1 WHERE blood_type = ?')
                    ->execute([$bt]);
            }
        }

        $inventory = $manager->getInventory();

        http_response_code(200);
        echo json_encode($inventory);
    } catch (\PDOException $e) {
        error_log('BDMS inventory GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
        exit;
    }
}

// -------------------------------------------------------------------------
// PUT — Expire stale units and update inventory counts
// -------------------------------------------------------------------------

function handlePut(): void
{
    Auth::requireRole(['Administrator', 'Lab_Technician']);

    // Parse JSON body (required to be valid JSON if a body is present)
    $raw = file_get_contents('php://input');

    if ($raw !== '' && json_decode($raw, true) === null) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        exit;
    }

    try {
        $manager = new InventoryManager();
        $manager->expireUnits();

        http_response_code(200);
        echo json_encode(['message' => 'inventory updated']);
    } catch (\PDOException $e) {
        error_log('BDMS inventory PUT error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal server error']);
        exit;
    }
}
