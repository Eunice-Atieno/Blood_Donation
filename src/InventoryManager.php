<?php
/**
 * InventoryManager — manages blood inventory counts and low-stock flags.
 *
 * Requirements: 5.3, 6.1, 6.2, 6.3, 6.4
 */

require_once __DIR__ . '/../config/db.php';

class InventoryManager
{
    /**
     * Recalculate and update the inventory count for the blood type of the
     * given blood unit.
     *
     * - Counts all blood_units with the same blood_type and status='available'.
     * - Updates blood_inventory.unit_count for that blood type.
     * - Sets low_stock = 1 if unit_count < 10, else low_stock = 0.
     *
     * Requirements: 5.3, 6.1, 6.2, 6.4
     */
    public function updateInventory(int $bloodUnitId): void
    {
        $pdo = getDbConnection();

        // 1. Look up the blood_type of the given blood unit.
        $stmt = $pdo->prepare(
            'SELECT blood_type FROM blood_units WHERE id = :id'
        );
        $stmt->execute([':id' => $bloodUnitId]);
        $row = $stmt->fetch();

        if ($row === false) {
            // Unit not found — nothing to update.
            return;
        }

        $bloodType = $row['blood_type'];

        // 2. Count available units for that blood type.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt
               FROM blood_units
              WHERE blood_type = :blood_type
                AND status = 'available'"
        );
        $stmt->execute([':blood_type' => $bloodType]);
        $count = (int) $stmt->fetchColumn();

        // Determine low-stock flag — threshold from .env (default: 10 units)
        $threshold = (int) ($_ENV['LOW_STOCK_THRESHOLD'] ?? 10);
        $lowStock  = ($count < $threshold) ? 1 : 0;

        // 4. Update blood_inventory for this blood type.
        $stmt = $pdo->prepare(
            'UPDATE blood_inventory
                SET unit_count = :unit_count,
                    low_stock  = :low_stock
              WHERE blood_type = :blood_type'
        );
        $stmt->execute([
            ':unit_count' => $count,
            ':low_stock'  => $lowStock,
            ':blood_type' => $bloodType,
        ]);
    }

    /**
     * Mark all blood units whose expiry_date is on or before today as
     * 'expired', then update inventory counts for each affected blood type.
     *
     * Requirements: 6.3
     */
    public function expireUnits(): void
    {
        $pdo = getDbConnection();

        // 1. Find distinct blood types of units that are about to be expired.
        $stmt = $pdo->prepare(
            "SELECT DISTINCT blood_type
               FROM blood_units
              WHERE expiry_date <= CURDATE()
                AND status = 'available'"
        );
        $stmt->execute();
        $affectedTypes = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($affectedTypes)) {
            return;
        }

        // 2. Bulk-update all qualifying units to 'expired'.
        $stmt = $pdo->prepare(
            "UPDATE blood_units
                SET status = 'expired'
              WHERE expiry_date <= CURDATE()
                AND status = 'available'"
        );
        $stmt->execute();

        // 3. Recalculate inventory for each affected blood type.
        //    We need a unit ID of that type to pass to updateInventory; fetch
        //    any existing unit (status no longer matters — updateInventory only
        //    uses the ID to look up the blood_type).
        foreach ($affectedTypes as $bloodType) {
            $stmt = $pdo->prepare(
                'SELECT id FROM blood_units WHERE blood_type = :blood_type LIMIT 1'
            );
            $stmt->execute([':blood_type' => $bloodType]);
            $unitId = $stmt->fetchColumn();

            if ($unitId !== false) {
                $this->updateInventory((int) $unitId);
            }
        }
    }

    /**
     * Return the current inventory for all blood types.
     *
     * Each element: ['blood_type' => string, 'unit_count' => int, 'low_stock' => bool]
     *
     * Requirements: 6.1, 6.4
     */
    public function getInventory(): array
    {
        $pdo = getDbConnection();

        $stmt = $pdo->query(
            'SELECT blood_type, unit_count, low_stock FROM blood_inventory'
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Return true if the given blood type is currently flagged as low stock.
     *
     * Requirements: 6.4
     */
    public function isLowStock(string $bloodType): bool
    {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare(
            'SELECT low_stock FROM blood_inventory WHERE blood_type = :blood_type'
        );
        $stmt->execute([':blood_type' => $bloodType]);
        $row = $stmt->fetch();

        if ($row === false) {
            return false;
        }

        return (bool) $row['low_stock'];
    }
}
