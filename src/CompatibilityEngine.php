<?php

require_once __DIR__ . '/../config/db.php';

class CompatibilityEngine
{
    /**
     * ABO/Rh compatibility map: patient blood type → compatible donor blood types.
     *
     * Derived by inverting the standard donor→recipient table:
     *   O-  can donate to everyone, so every patient can receive O-.
     *   AB+ can receive from everyone (universal recipient).
     */
    private const COMPATIBLE_DONORS = [
        'O-'  => ['O-'],
        'O+'  => ['O-', 'O+'],
        'A-'  => ['O-', 'A-'],
        'A+'  => ['O-', 'O+', 'A-', 'A+'],
        'B-'  => ['O-', 'B-'],
        'B+'  => ['O-', 'O+', 'B-', 'B+'],
        'AB-' => ['O-', 'A-', 'B-', 'AB-'],
        'AB+' => ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'],
    ];

    /**
     * Finds available blood units compatible with the given patient blood type.
     *
     * Units are filtered to status='available' and expiry_date > today, then
     * ordered by earliest expiry date first (FIFO). At most $requestedUnits
     * units are returned.
     *
     * @param string $patientBloodType  One of: O-, O+, A-, A+, B-, B+, AB-, AB+
     * @param int    $requestedUnits    Number of units requested
     * @return array{units: array, shortage: bool}|array{error: string}
     */
    public function findCompatibleUnits(string $patientBloodType, int $requestedUnits): array
    {
        if (!array_key_exists($patientBloodType, self::COMPATIBLE_DONORS)) {
            return ['error' => 'invalid blood type'];
        }

        $compatibleDonorTypes = self::COMPATIBLE_DONORS[$patientBloodType];

        $pdo = getDbConnection();

        // Build a parameterised IN clause for the compatible donor blood types.
        $placeholders = implode(', ', array_fill(0, count($compatibleDonorTypes), '?'));

        $sql = "SELECT id, blood_type, expiry_date
                FROM blood_units
                WHERE status = 'available'
                  AND expiry_date > CURDATE()
                  AND blood_type IN ({$placeholders})
                ORDER BY expiry_date ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($compatibleDonorTypes);
        $allUnits = $stmt->fetchAll();

        $shortage = count($allUnits) < $requestedUnits;
        $units    = array_slice($allUnits, 0, $requestedUnits);

        return [
            'units'    => $units,
            'shortage' => $shortage,
        ];
    }
}
