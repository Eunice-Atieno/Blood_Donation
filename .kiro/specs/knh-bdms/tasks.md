# Implementation Plan: KNH Blood Donation Management System (BDMS)

## Overview

Incremental implementation of the PHP/MySQL BDMS, building from the database layer upward through business logic, API endpoints, and frontend templates. Each task integrates with the previous, ending with a fully wired system.

## Tasks

- [x] 1. Project structure, environment configuration, and database schema
  - Create directory structure: `config/`, `src/`, `api/`, `tests/Unit/`, `tests/Property/`
  - Create `config/db.example.php` documenting all required environment variables without real values
  - Create `.gitignore` excluding `config/db.php`, `.env`, and any credential-containing files
  - Create `database/schema.sql` with all six tables (`staff`, `donors`, `blood_units`, `blood_inventory`, `transfusions`, `notifications`) including PK, FK, NOT NULL, UNIQUE, and ENUM constraints as specified in the data models
  - Create `README.md` with XAMPP/WAMP setup, database import, and environment variable instructions
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 13.1, 13.2, 13.3_

- [ ] 2. Database connection module
  - [x] 2.1 Implement `config/db.php` with singleton PDO connection reading credentials from environment/config source
    - Wrap PDO constructor in try/catch; log `PDOException` to server log without exposing credentials; re-throw for callers
    - _Requirements: 2.1, 2.2, 2.4_

  - [ ]* 2.2 Write unit tests for DB connection (`tests/Unit/DbConnectionTest.php`)
    - Test successful connection returns PDO instance
    - Test unreachable server throws catchable PDOException without credential leakage
    - _Requirements: 2.2_

- [ ] 3. Authentication and RBAC (`src/Auth.php`)
  - [x] 3.1 Implement `Auth` class with `login`, `logout`, `requireRole`, `currentStaff`, `incrementFailedAttempts`, and `lockAccount` methods
    - Session payload: `['staff_id', 'role', 'last_active']`
    - `requireRole()` checks session timeout (30 min inactivity) and destroys stale sessions with HTTP 401
    - `login()` hashes password with `password_verify`, increments `failed_attempts` on failure, locks account at 5 consecutive failures
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6_

  - [ ]* 3.2 Write unit tests for Auth (`tests/Unit/AuthTest.php`)
    - Test valid login creates session with correct `staff_id` and `role`
    - Test invalid login returns false and increments `failed_attempts`
    - Test account lock after 5 failures
    - Test role enum values (Administrator, Nurse, Lab_Technician)
    - _Requirements: 11.1, 11.2, 11.3, 11.4_

  - [ ]* 3.3 Write property test: valid login creates correct session (P23)
    - `// Feature: knh-bdms, Property 23: Valid login creates session with correct payload`
    - Generator: random valid staff credentials from seeded test data
    - _Requirements: 11.2_

  - [ ]* 3.4 Write property test: invalid login increments counter (P24)
    - `// Feature: knh-bdms, Property 24: Invalid login increments failed-attempt counter`
    - Generator: random incorrect passwords for existing accounts
    - _Requirements: 11.3_

  - [ ]* 3.5 Write property test: account lockout after 5 failures (P25)
    - `// Feature: knh-bdms, Property 25: Account lockout after 5 failed attempts`
    - Simulate exactly 5 consecutive failures; assert `locked=1` and subsequent attempts rejected
    - _Requirements: 11.4_

  - [ ]* 3.6 Write property test: expired session returns 401 (P26)
    - `// Feature: knh-bdms, Property 26: Expired session requires re-authentication`
    - Set `last_active` to > 30 minutes ago; assert HTTP 401 on next request
    - _Requirements: 11.6_

- [x] 4. Checkpoint — Ensure all tests pass, ask the user if questions arise.

- [ ] 5. Donor registration (`src/` + `api/donors.php`)
  - [x] 5.1 Implement donor registration logic in `api/donors.php` (POST handler)
    - Validate required fields (name, date_of_birth, blood_type, national_id, email, phone); return HTTP 422 with missing field list on failure
    - Hash sensitive credentials with `password_hash` before storage
    - Insert into `donors`; return unique donor ID on success
    - Catch duplicate national_id/email and return HTTP 409 with descriptive message
    - Use PDO prepared statements for all queries
    - _Requirements: 2.3, 3.1, 3.2, 3.3, 3.4, 3.5_

  - [ ]* 5.2 Write unit tests for donor registration (`tests/Unit/SchemaTest.php` + inline)
    - Test credential hash differs from plaintext
    - Test first-time donor with NULL `last_donation_date` is stored correctly
    - _Requirements: 3.5_

  - [ ]* 5.3 Write property test: donor registration round-trip (P3)
    - `// Feature: knh-bdms, Property 3: Donor registration round-trip`
    - Generator: random valid donor data (unique national_id, unique email, valid blood type)
    - Assert stored fields match submitted values and returned ID is unique
    - _Requirements: 3.1, 3.4_

  - [ ]* 5.4 Write property test: duplicate donor rejected (P4)
    - `// Feature: knh-bdms, Property 4: Duplicate donor registration is rejected`
    - Generator: random donor, re-submit same national_id or email
    - Assert HTTP 409 and no new record inserted
    - _Requirements: 3.2_

  - [ ]* 5.5 Write property test: incomplete registration rejected (P5)
    - `// Feature: knh-bdms, Property 5: Incomplete registration is rejected with field list`
    - Generator: random subsets of required fields omitted
    - Assert HTTP 422 and response body lists every missing field
    - _Requirements: 3.3_

- [ ] 6. Eligibility checker (`src/EligibilityChecker.php`)
  - [x] 6.1 Implement `EligibilityChecker::verifyEligibility(int $donorId): array`
    - Query `donors` for `last_donation_date` and `medical_history_flag`
    - Return `['eligible' => false, 'reason' => 'minimum interval not met']` if last donation < 56 days ago
    - Return `['eligible' => false, 'reason' => 'medical disqualification']` if `medical_history_flag=1`
    - Return `['eligible' => true, 'reason' => null]` if all criteria met
    - Return `['eligible' => false, 'reason' => 'donor not found']` for non-existent donor ID
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

  - [ ]* 6.2 Write unit tests for EligibilityChecker (`tests/Unit/EligibilityCheckerTest.php`)
    - Test donor not found returns correct error
    - Test first-time donor (NULL `last_donation_date`) is eligible
    - Test boundary: exactly 56 days ago is eligible, 55 days ago is not
    - _Requirements: 4.2, 4.4, 4.5_

  - [ ]* 6.3 Write property test: eligibility check correctness (P6)
    - `// Feature: knh-bdms, Property 6: Eligibility check correctness`
    - Generator: random donors with varied `last_donation_date` (0–200 days ago) and `medical_history_flag`
    - Assert all three outcome branches hold
    - _Requirements: 4.2, 4.3, 4.4_

- [ ] 7. Blood unit collection (`api/blood_units.php` + `src/InventoryManager.php` partial)
  - [x] 7.1 Implement `InventoryManager::updateInventory(int $bloodUnitId): void`
    - Recalculate `unit_count` for the affected blood type by counting `blood_units` with `status='available'`
    - Update `low_stock` flag: set to 1 if `unit_count < 10`, else 0
    - _Requirements: 5.3, 6.1, 6.2, 6.4_

  - [x] 7.2 Implement blood unit collection in `api/blood_units.php` (POST handler)
    - Call `EligibilityChecker::verifyEligibility`; return HTTP 422 with reason if ineligible
    - Insert into `blood_units` with `status='available'`, `collection_date=today`, `expiry_date=today+42 days`
    - Call `InventoryManager::updateInventory` after successful insert
    - _Requirements: 5.1, 5.2, 5.3, 5.4_

  - [ ]* 7.3 Write property test: blood unit fields correct (P7)
    - `// Feature: knh-bdms, Property 7: Blood unit collection sets correct fields`
    - Generator: random eligible donors and valid blood types
    - Assert `status='available'`, `collection_date=today`, `expiry_date=collection_date+42`
    - _Requirements: 5.1, 5.4_

  - [ ]* 7.4 Write property test: ineligible donor collection rejected (P8)
    - `// Feature: knh-bdms, Property 8: Ineligible donor collection is rejected`
    - Generator: random ineligible donors (recent donation or medical flag)
    - Assert HTTP 422 with eligibility reason and no record inserted
    - _Requirements: 5.2_

- [ ] 8. Inventory management (`src/InventoryManager.php` complete + `api/inventory.php`)
  - [x] 8.1 Implement `InventoryManager::expireUnits(): void`
    - Mark all `blood_units` with `expiry_date <= today` and `status='available'` as `status='expired'`
    - Call `updateInventory` for each affected blood type
    - _Requirements: 6.3_

  - [x] 8.2 Implement `InventoryManager::getInventory(): array` and `isLowStock(string $bloodType): bool`
    - `getInventory` returns all blood types with `unit_count` and `low_stock` flag
    - _Requirements: 6.1, 6.4_

  - [x] 8.3 Implement `api/inventory.php` (GET and PUT handlers)
    - GET: call `getInventory()`, return JSON array; accessible to Admin and Lab_Technician
    - PUT: trigger `expireUnits()` or manual inventory update; enforce RBAC
    - _Requirements: 6.5, 10.1, 10.2_

  - [ ]* 8.4 Write property test: inventory count accuracy (P9)
    - `// Feature: knh-bdms, Property 9: Inventory count reflects available units`
    - Generator: random blood unit insertions and status changes
    - Assert `unit_count` equals count of `status='available'` records for each blood type
    - _Requirements: 5.3, 6.2_

  - [ ]* 8.5 Write property test: expired units marked and decremented (P10)
    - `// Feature: knh-bdms, Property 10: Expired units are marked and inventory decremented`
    - Generator: random units with past expiry dates
    - Assert `status='expired'` and inventory count excludes them
    - _Requirements: 6.3_

  - [ ]* 8.6 Write property test: low-stock flag invariant (P11)
    - `// Feature: knh-bdms, Property 11: Low-stock flag invariant`
    - Generator: random inventory counts (0–50)
    - Assert `low_stock=1` iff `unit_count < 10`
    - _Requirements: 6.4_

- [x] 9. Checkpoint — Ensure all tests pass, ask the user if questions arise.

- [ ] 10. Compatibility engine (`src/CompatibilityEngine.php`)
  - [x] 10.1 Implement `CompatibilityEngine::findCompatibleUnits(string $patientBloodType, int $requestedUnits): array`
    - Apply ABO/Rh compatibility table from design to filter available, non-expired units
    - Order results by `expiry_date` ascending (FIFO)
    - Return `['units' => [...], 'shortage' => bool]`
    - Return `['error' => 'invalid blood type']` for unrecognized blood types
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

  - [ ]* 10.2 Write unit tests for CompatibilityEngine (`tests/Unit/CompatibilityEngineTest.php`)
    - Test specific blood type pair examples (e.g., O- donor compatible with all recipients)
    - Test invalid blood type returns error
    - _Requirements: 7.2, 7.5_

  - [ ]* 10.3 Write property test: compatibility returns only compatible units (P12)
    - `// Feature: knh-bdms, Property 12: Compatibility engine returns only compatible units`
    - Generator: random patient blood types and pools of available units
    - Assert all returned units are ABO/Rh compatible, available, and non-expired
    - _Requirements: 7.1, 7.2_

  - [ ]* 10.4 Write property test: compatible units ordered by earliest expiry (P13)
    - `// Feature: knh-bdms, Property 13: Compatible units are ordered by earliest expiry`
    - Generator: random compatible unit sets with varied expiry dates
    - Assert result is sorted ascending by `expiry_date`
    - _Requirements: 7.3_

  - [ ]* 10.5 Write property test: shortage indicator when supply insufficient (P14)
    - `// Feature: knh-bdms, Property 14: Shortage indicator when supply is insufficient`
    - Generator: random requests where `$requestedUnits` exceeds available compatible supply
    - Assert all available units returned and `shortage=true`
    - _Requirements: 7.4_

- [ ] 11. Transfusion recording (`api/transfusions.php`)
  - [x] 11.1 Implement `api/transfusions.php` (GET and POST handlers)
    - POST: validate unit ID and patient identifier; check `blood_units.status='available'`; return HTTP 409 if not available
    - Insert into `transfusions` with `blood_unit_id`, `patient_identifier`, `transfusion_date`, `staff_id`
    - Update `blood_units.status` to `'transfused'`
    - Call `InventoryManager::updateInventory` to decrement count
    - GET: return transfusion records (Admin/Nurse only)
    - _Requirements: 8.1, 8.2, 8.3, 8.4_

  - [ ]* 11.2 Write property test: transfusion recording side effects (P15)
    - `// Feature: knh-bdms, Property 15: Transfusion recording side effects`
    - Generator: random available units and patient identifiers
    - Assert record in `transfusions`, unit `status='transfused'`, inventory decremented by 1
    - _Requirements: 8.1, 8.2, 8.4_

  - [ ]* 11.3 Write property test: unavailable unit transfusion rejected (P16)
    - `// Feature: knh-bdms, Property 16: Transfusion of unavailable unit is rejected`
    - Generator: random units with `status='transfused'` or `status='expired'`
    - Assert HTTP 409 and no record inserted into `transfusions`
    - _Requirements: 8.3_

- [ ] 12. Notification service (`src/NotificationService.php` + `api/notifications.php`)
  - [x] 12.1 Implement `NotificationService::sendNotification(int $donorId, string $messageType): array`
    - Retrieve donor contact details; return `['error' => 'donor not found']` for non-existent donor without inserting a record
    - Insert `notifications` record with `delivery_status='pending'` before dispatch
    - On success: update `delivery_status='sent'`; return `['success' => true, 'notification_id' => int]`
    - On failure: update `delivery_status='failed'`, log error; return `['success' => false, 'error' => '...']`
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6_

  - [x] 12.2 Implement `api/notifications.php` (GET and POST handlers, Admin only)
    - POST: call `sendNotification`; return JSON result with appropriate status code
    - GET: return notification records
    - _Requirements: 10.1, 10.2_

  - [ ]* 12.3 Write unit tests for NotificationService (`tests/Unit/NotificationServiceTest.php`)
    - Test non-existent donor ID returns error without inserting a record
    - _Requirements: 9.6_

  - [ ]* 12.4 Write property test: notification dispatch creates record (P17)
    - `// Feature: knh-bdms, Property 17: Notification dispatch creates a record`
    - Generator: random existing donors and valid message types
    - Assert exactly one `notifications` record with correct fields and `delivery_status='sent'`
    - _Requirements: 9.2, 9.3, 9.4_

  - [ ]* 12.5 Write property test: failed dispatch updates status (P18)
    - `// Feature: knh-bdms, Property 18: Failed notification dispatch updates status`
    - Simulate dispatch failure (mock external service)
    - Assert `delivery_status='failed'` in `notifications` record
    - _Requirements: 9.5_

- [x] 13. Checkpoint — Ensure all tests pass, ask the user if questions arise.

- [ ] 14. Database schema and constraint property tests (`tests/Unit/SchemaTest.php` + `tests/Property/`)
  - [x] 14.1 Write unit tests for schema (`tests/Unit/SchemaTest.php`)
    - Verify all six tables exist in `knh_bdms_test_db`
    - Verify FK relationships (e.g., `blood_units.donor_id` → `donors.id`)
    - _Requirements: 1.2, 1.4_

  - [ ]* 14.2 Write property test: every table has a primary key (P1)
    - `// Feature: knh-bdms, Property 1: Every table has a primary key`
    - Enumerate tables from `INFORMATION_SCHEMA.TABLE_CONSTRAINTS`; assert PK exists for each
    - _Requirements: 1.3_

  - [ ]* 14.3 Write property test: NOT NULL constraints enforced (P2)
    - `// Feature: knh-bdms, Property 2: NOT NULL constraints are enforced on required columns`
    - Generator: null-insertion attempts per required column per table
    - Assert database error (constraint violation) and table unchanged
    - _Requirements: 1.5_

- [ ] 15. API cross-cutting concerns and remaining endpoint wiring
  - [x] 15.1 Implement `api/donors.php` GET and PUT handlers
    - GET: search/retrieve donor records (Admin, Nurse, Lab_Technician per design)
    - PUT: update donor record; enforce RBAC
    - All handlers: validate JSON body, set `Content-Type: application/json`, return correct HTTP status codes
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6_

  - [x] 15.2 Add RBAC enforcement (`Auth::requireRole`) to all API endpoint files
    - Unauthenticated requests → HTTP 401 `{"error": "unauthenticated"}`
    - Insufficient role → HTTP 403 `{"error": "forbidden"}`
    - Malformed JSON body → HTTP 400 `{"error": "invalid JSON"}`
    - _Requirements: 10.3, 10.5, 10.6, 11.5_

  - [ ]* 15.3 Write property test: all API responses are JSON (P19)
    - `// Feature: knh-bdms, Property 19: All API responses are JSON`
    - Generator: random requests to all `api/*.php` endpoints
    - Assert `Content-Type: application/json` and valid JSON body
    - _Requirements: 10.2_

  - [ ]* 15.4 Write property test: malformed JSON returns HTTP 400 (P20)
    - `// Feature: knh-bdms, Property 20: Malformed JSON body returns HTTP 400`
    - Generator: random malformed strings as request body to each endpoint
    - Assert HTTP 400 with descriptive error message
    - _Requirements: 10.3_

  - [ ]* 15.5 Write property test: unauthenticated requests return 401 (P21)
    - `// Feature: knh-bdms, Property 21: Unauthenticated requests to protected endpoints return HTTP 401`
    - Generator: all protected endpoints, no session
    - Assert HTTP 401
    - _Requirements: 10.5_

  - [ ]* 15.6 Write property test: unauthorized role returns 403 (P22)
    - `// Feature: knh-bdms, Property 22: Unauthorized role access returns HTTP 403`
    - Generator: random role/endpoint combinations outside permitted roles
    - Assert HTTP 403
    - _Requirements: 10.6, 11.5_

- [ ] 16. Frontend HTML templates and JavaScript
  - [x] 16.1 Create `index.html` (login form) with Fetch API POST to `api/auth.php` (or session endpoint)
    - Display error message on failed login without page reload
    - _Requirements: 12.1, 12.2, 12.3, 12.4_

  - [x] 16.2 Create `register_donor.html` with Fetch API POST to `api/donors.php`
    - Display success message on 201; display API error message on failure
    - Responsive layout: usable from 320px to 1920px without horizontal scrolling
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.6_

  - [x] 16.3 Create `donor_dashboard.html` with donor search and eligibility check UI
    - Fetch donor data via GET `api/donors.php`; display eligibility status
    - _Requirements: 12.1, 12.2, 12.3, 12.4_

  - [x] 16.4 Create `inventory_dashboard.html` with 30-second auto-refresh polling `GET /api/inventory.php`
    - Update inventory display in-place without page reload
    - _Requirements: 6.6, 12.1, 12.2, 12.5, 12.6_

  - [x] 16.5 Create `transfusion_form.html` with compatibility search and transfusion recording
    - POST to `api/transfusions.php`; display result without page reload
    - _Requirements: 12.1, 12.2, 12.3, 12.4_

- [x] 17. Final checkpoint — Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- Each task references specific requirements for traceability
- Property tests use the [eris](https://github.com/giorgiosironi/eris) library on top of PHPUnit 10.x; each must run a minimum of 100 iterations
- All property tests must include the tag comment: `// Feature: knh-bdms, Property {N}: {property_text}`
- A separate `knh_bdms_test_db` database is used for all tests; it is seeded and torn down per test suite run
- All database queries use PDO prepared statements (Requirement 2.3)
