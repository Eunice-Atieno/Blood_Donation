<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * SchemaTest — verifies that knh_bdms_test_db contains the expected tables
 * and foreign-key relationships defined in database/schema.sql.
 *
 * Requirements: 1.2, 1.4
 */
class SchemaTest extends TestCase
{
    private static PDO $pdo;

    /** Name of the test database (overrides the production DB_NAME). */
    private const TEST_DB = 'knh_bdms_test_db';

    public static function setUpBeforeClass(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            self::TEST_DB
        );

        self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Returns true when the given table exists in knh_bdms_test_db.
     */
    private function tableExists(string $tableName): bool
    {
        $stmt = self::$pdo->prepare(
            "SELECT COUNT(*) AS cnt
               FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = :db
                AND TABLE_NAME   = :tbl"
        );
        $stmt->execute([':db' => self::TEST_DB, ':tbl' => $tableName]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Returns true when a FK constraint exists that maps
     * $childTable.$childColumn → $parentTable.$parentColumn.
     */
    private function fkExists(
        string $childTable,
        string $childColumn,
        string $parentTable,
        string $parentColumn
    ): bool {
        $stmt = self::$pdo->prepare(
            "SELECT COUNT(*) AS cnt
               FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
               JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                 ON rc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
                AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
              WHERE kcu.TABLE_SCHEMA            = :db
                AND kcu.TABLE_NAME              = :child_table
                AND kcu.COLUMN_NAME             = :child_col
                AND kcu.REFERENCED_TABLE_NAME   = :parent_table
                AND kcu.REFERENCED_COLUMN_NAME  = :parent_col"
        );
        $stmt->execute([
            ':db'           => self::TEST_DB,
            ':child_table'  => $childTable,
            ':child_col'    => $childColumn,
            ':parent_table' => $parentTable,
            ':parent_col'   => $parentColumn,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // -----------------------------------------------------------------------
    // Table-existence tests  (Requirement 1.2)
    // -----------------------------------------------------------------------

    public function testStaffTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('staff'),
            'Table "staff" should exist in ' . self::TEST_DB
        );
    }

    public function testDonorsTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('donors'),
            'Table "donors" should exist in ' . self::TEST_DB
        );
    }

    public function testBloodUnitsTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('blood_units'),
            'Table "blood_units" should exist in ' . self::TEST_DB
        );
    }

    public function testBloodInventoryTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('blood_inventory'),
            'Table "blood_inventory" should exist in ' . self::TEST_DB
        );
    }

    public function testTransfusionsTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('transfusions'),
            'Table "transfusions" should exist in ' . self::TEST_DB
        );
    }

    public function testNotificationsTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('notifications'),
            'Table "notifications" should exist in ' . self::TEST_DB
        );
    }

    // -----------------------------------------------------------------------
    // Foreign-key tests  (Requirement 1.4)
    // -----------------------------------------------------------------------

    public function testBloodUnitsDonorIdFkReferencesDonors(): void
    {
        $this->assertTrue(
            $this->fkExists('blood_units', 'donor_id', 'donors', 'id'),
            'blood_units.donor_id should have a FK referencing donors.id'
        );
    }

    public function testTransfusionsBloodUnitIdFkReferencesBloodUnits(): void
    {
        $this->assertTrue(
            $this->fkExists('transfusions', 'blood_unit_id', 'blood_units', 'id'),
            'transfusions.blood_unit_id should have a FK referencing blood_units.id'
        );
    }

    public function testTransfusionsStaffIdFkReferencesStaff(): void
    {
        $this->assertTrue(
            $this->fkExists('transfusions', 'staff_id', 'staff', 'id'),
            'transfusions.staff_id should have a FK referencing staff.id'
        );
    }

    public function testNotificationsDonorIdFkReferencesDonors(): void
    {
        $this->assertTrue(
            $this->fkExists('notifications', 'donor_id', 'donors', 'id'),
            'notifications.donor_id should have a FK referencing donors.id'
        );
    }
}
