<?php

require_once __DIR__ . '/../config/db.php';

class EligibilityChecker
{
    /**
     * Verifies whether a donor is eligible to donate blood.
     *
     * Priority order:
     *   1. donor not found
     *   2. medical disqualification (medical_history_flag = 1)
     *   3. minimum interval not met (last_donation_date < 56 days ago)
     *   4. eligible
     *
     * @param int $donorId
     * @return array{eligible: bool, reason: string|null}
     */
    public function verifyEligibility(int $donorId): array
    {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare(
            'SELECT last_donation_date, medical_history_flag FROM donors WHERE id = :id'
        );
        $stmt->execute([':id' => $donorId]);
        $donor = $stmt->fetch();

        if ($donor === false) {
            return ['eligible' => false, 'reason' => 'donor not found'];
        }

        if ((int) $donor['medical_history_flag'] === 1) {
            return ['eligible' => false, 'reason' => 'medical disqualification'];
        }

        if ($donor['last_donation_date'] !== null) {
            $lastDonation = new DateTimeImmutable($donor['last_donation_date']);
            $today        = new DateTimeImmutable('today');
            $daysSince    = (int) $lastDonation->diff($today)->days;

            if ($daysSince < 56) {
                return ['eligible' => false, 'reason' => 'minimum interval not met'];
            }
        }

        return ['eligible' => true, 'reason' => null];
    }
}
