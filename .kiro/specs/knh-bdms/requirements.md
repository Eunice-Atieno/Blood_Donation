# Requirements Document

## Introduction

The KNH Blood Donation Management System (BDMS) is a web-based application for Kenyatta National Hospital to manage the full lifecycle of blood donation — from donor registration and eligibility screening, through blood unit collection and inventory management, to transfusion tracking and donor notifications. The system is built on a PHP/MySQL stack served via Apache (XAMPP/WAMP) and exposes a RESTful API consumed by an HTML/CSS/JS frontend.

---

## Glossary

- **BDMS**: Blood Donation Management System — the system described in this document.
- **Donor**: A registered individual who donates blood at KNH.
- **Blood_Unit**: A discrete unit of collected blood, identified by a unique ID, blood type, and collection date.
- **Inventory**: The aggregate of all available Blood_Units stored in the blood bank.
- **Transfusion**: A recorded event in which a Blood_Unit is assigned and administered to a patient.
- **Patient**: A hospital patient who requires a blood transfusion.
- **Staff**: An authenticated KNH hospital employee (nurse, lab technician, or administrator) who operates the BDMS.
- **Admin**: A Staff member with the Administrator role, capable of managing users and system configuration.
- **Eligibility_Checker**: The subsystem that evaluates whether a Donor meets donation criteria.
- **Compatibility_Engine**: The subsystem that matches a Patient's blood type to available Blood_Units.
- **Notification_Service**: The subsystem that sends messages to Donors via SMS or email.
- **API**: The RESTful HTTP interface through which the frontend communicates with the backend.
- **RBAC**: Role-Based Access Control — the mechanism that restricts system operations based on Staff roles.
- **PDO**: PHP Data Objects — the database abstraction layer used for all database interactions.
- **Session**: A server-side PHP session used to maintain authenticated Staff state.

---

## Requirements

### Requirement 1: Environment & Database Initialization

**User Story:** As an Admin, I want the system database and tables to be initialized with all constraints, so that data integrity is enforced from the start.

#### Acceptance Criteria

1. THE BDMS SHALL use a MySQL database named `knh_bdms_db`.
2. THE BDMS SHALL create the following core tables: `donors`, `blood_units`, `blood_inventory`, `transfusions`, and `notifications`.
3. THE BDMS SHALL enforce primary key constraints on every table.
4. THE BDMS SHALL enforce foreign key constraints between related tables (e.g., `blood_units.donor_id` references `donors.id`).
5. THE BDMS SHALL enforce NOT NULL and data-type constraints on all required columns.
6. WHEN the database schema is applied to a clean MySQL instance, THE BDMS SHALL create all tables and constraints without errors.

---

### Requirement 2: Database Connection

**User Story:** As a developer, I want a centralized database connection module, so that all backend scripts share a single, secure connection configuration.

#### Acceptance Criteria

1. THE BDMS SHALL provide a `config/db.php` file that establishes a PDO connection to `knh_bdms_db`.
2. WHEN the database server is unreachable, THE BDMS SHALL throw a catchable PDO exception and log the error without exposing credentials to the HTTP response.
3. THE BDMS SHALL use PDO prepared statements for all database queries to prevent SQL injection.
4. THE `config/db.php` SHALL read database credentials from a configuration source that is excluded from version control via `.gitignore`.

---

### Requirement 3: Donor Registration

**User Story:** As a walk-in donor, I want to register my personal and medical details, so that the hospital can maintain my donation record.

#### Acceptance Criteria

1. WHEN a registration form is submitted with valid data, THE BDMS SHALL insert a new record into the `donors` table and return a unique donor ID.
2. WHEN a registration form is submitted with a duplicate national ID or email, THE BDMS SHALL return an error response with HTTP status 409 and a descriptive message.
3. WHEN a registration form is submitted with missing required fields, THE BDMS SHALL return an error response with HTTP status 422 listing the missing fields.
4. THE BDMS SHALL store the donor's name, date of birth, blood type, contact information, and medical history flag in the `donors` table.
5. THE BDMS SHALL hash any sensitive authentication credentials before storing them in the database.

---

### Requirement 4: Donor Eligibility Verification

**User Story:** As a lab technician, I want the system to automatically verify donor eligibility before a donation is recorded, so that only safe donations are accepted.

#### Acceptance Criteria

1. WHEN `verifyEligibility($donorId)` is called, THE Eligibility_Checker SHALL retrieve the donor's last donation date and medical history flag from the `donors` table.
2. WHEN the donor's last donation date is fewer than 56 days before the current date, THE Eligibility_Checker SHALL return an ineligible status with the reason "minimum interval not met".
3. WHEN the donor's medical history flag indicates a disqualifying condition, THE Eligibility_Checker SHALL return an ineligible status with the reason "medical disqualification".
4. WHEN the donor meets all eligibility criteria, THE Eligibility_Checker SHALL return an eligible status.
5. WHEN `verifyEligibility($donorId)` is called with a non-existent donor ID, THE Eligibility_Checker SHALL return an error status with the reason "donor not found".

---

### Requirement 5: Blood Unit Collection & Recording

**User Story:** As a nurse, I want to record a new blood unit after a successful donation, so that the inventory is kept accurate.

#### Acceptance Criteria

1. WHEN a blood unit collection is submitted with a valid donor ID and blood type, THE BDMS SHALL insert a new record into `blood_units` with a unique unit ID, collection date, expiry date (collection date + 42 days), and status "available".
2. WHEN a blood unit collection is submitted for a donor who is ineligible, THE BDMS SHALL reject the request and return HTTP status 422 with the eligibility failure reason.
3. WHEN a new blood unit is successfully recorded, THE BDMS SHALL update the corresponding entry in `blood_inventory` to reflect the new unit count for that blood type.
4. THE BDMS SHALL set the expiry date of every Blood_Unit to exactly 42 days after the collection date.

---

### Requirement 6: Blood Inventory Management

**User Story:** As a lab technician, I want to view and manage the blood inventory in real time, so that I can monitor stock levels and act on shortages.

#### Acceptance Criteria

1. THE BDMS SHALL maintain a `blood_inventory` table that stores the current count of available units per blood type.
2. WHEN `updateInventory($bloodUnitId)` is called after a unit status change, THE BDMS SHALL recalculate and update the unit count for the affected blood type in `blood_inventory`.
3. WHEN a Blood_Unit's expiry date is reached, THE BDMS SHALL mark the unit's status as "expired" and decrement the inventory count for that blood type.
4. WHEN the available unit count for any blood type falls below 10 units, THE BDMS SHALL flag that blood type as "low stock" in the inventory record.
5. THE BDMS SHALL expose a `GET /api/inventory.php` endpoint that returns the current inventory for all blood types as a JSON array.
6. WHEN the inventory dashboard is open, THE Frontend SHALL refresh the inventory display every 30 seconds by polling `GET /api/inventory.php`.

---

### Requirement 7: Blood Compatibility Matching

**User Story:** As a lab technician, I want the system to find compatible blood units for a patient, so that transfusions are safe and efficient.

#### Acceptance Criteria

1. WHEN `findCompatibleUnits($patientBloodType, $requestedUnits)` is called, THE Compatibility_Engine SHALL query `blood_units` for available, non-expired units compatible with the patient's blood type.
2. THE Compatibility_Engine SHALL apply standard ABO/Rh compatibility rules when selecting units.
3. WHEN sufficient compatible units are found, THE Compatibility_Engine SHALL return a list of unit IDs up to the `$requestedUnits` count, ordered by earliest expiry date first.
4. WHEN fewer compatible units are available than requested, THE Compatibility_Engine SHALL return all available compatible units and include a shortage indicator in the response.
5. WHEN `findCompatibleUnits` is called with an unrecognized blood type, THE Compatibility_Engine SHALL return an error with the reason "invalid blood type".

---

### Requirement 8: Transfusion Recording

**User Story:** As a nurse, I want to record a transfusion event, so that blood unit usage is tracked and inventory is updated.

#### Acceptance Criteria

1. WHEN a transfusion is recorded with a valid unit ID and patient identifier, THE BDMS SHALL insert a record into the `transfusions` table with the unit ID, patient identifier, transfusion date, and administering staff ID.
2. WHEN a transfusion is recorded, THE BDMS SHALL call `updateInventory($bloodUnitId)` to decrement the inventory count for the affected blood type.
3. WHEN a transfusion is recorded for a unit with status other than "available", THE BDMS SHALL reject the request and return HTTP status 409 with the reason "unit not available".
4. WHEN a transfusion is successfully recorded, THE BDMS SHALL update the Blood_Unit status to "transfused".

---

### Requirement 9: Donor Notifications

**User Story:** As a donor, I want to receive notifications about my donation eligibility and blood shortage alerts, so that I can donate when needed.

#### Acceptance Criteria

1. WHEN `sendNotification($donorId, $messageType)` is called, THE Notification_Service SHALL retrieve the donor's contact details from the `donors` table.
2. WHEN `$messageType` is "eligibility_reminder", THE Notification_Service SHALL send a message informing the donor that they are eligible to donate again.
3. WHEN `$messageType` is "low_stock_alert", THE Notification_Service SHALL send a message informing the donor of a blood shortage for their blood type.
4. WHEN a notification is successfully dispatched, THE Notification_Service SHALL insert a record into the `notifications` table with the donor ID, message type, timestamp, and delivery status.
5. WHEN a notification dispatch fails, THE Notification_Service SHALL update the notification record's delivery status to "failed" and log the error.
6. WHEN `sendNotification` is called with a non-existent donor ID, THE Notification_Service SHALL return an error with the reason "donor not found" without inserting a notification record.

---

### Requirement 10: RESTful API

**User Story:** As a frontend developer, I want a RESTful API, so that the frontend can perform CRUD operations on donors, inventory, and transfusions.

#### Acceptance Criteria

1. THE API SHALL expose the following endpoint files: `api/donors.php`, `api/inventory.php`, `api/blood_units.php`, `api/transfusions.php`, and `api/notifications.php`.
2. WHEN an API request is received, THE API SHALL return responses in JSON format with appropriate HTTP status codes.
3. WHEN an API request includes an invalid or malformed JSON body, THE API SHALL return HTTP status 400 with a descriptive error message.
4. THE API SHALL support the HTTP methods GET, POST, PUT, and DELETE on applicable endpoints.
5. WHEN an unauthenticated request is made to a protected endpoint, THE API SHALL return HTTP status 401.
6. WHEN an authenticated Staff member makes a request outside their role's permissions, THE API SHALL return HTTP status 403.

---

### Requirement 11: Role-Based Access Control (RBAC)

**User Story:** As an Admin, I want role-based access control, so that Staff members can only perform actions appropriate to their role.

#### Acceptance Criteria

1. THE BDMS SHALL define at least three roles: Administrator, Nurse, and Lab_Technician.
2. WHEN a Staff member logs in with valid credentials, THE BDMS SHALL create a server-side Session storing the staff ID and role.
3. WHEN a Staff member logs in with invalid credentials, THE BDMS SHALL return HTTP status 401 and increment a failed-attempt counter for that account.
4. WHEN a Staff account accumulates 5 consecutive failed login attempts, THE BDMS SHALL lock the account and require Admin intervention to unlock.
5. THE BDMS SHALL enforce the following role permissions:
   - Administrator: full access to all endpoints.
   - Nurse: access to donor registration, blood unit collection, and transfusion recording.
   - Lab_Technician: access to eligibility verification, compatibility matching, and inventory management.
6. WHEN a Session expires after 30 minutes of inactivity, THE BDMS SHALL invalidate the session and require re-authentication.

---

### Requirement 12: Frontend Interface

**User Story:** As a Staff member, I want a web-based interface, so that I can interact with the BDMS without using raw API calls.

#### Acceptance Criteria

1. THE BDMS SHALL provide the following HTML templates: `index.html` (login), `register_donor.html`, `donor_dashboard.html`, `inventory_dashboard.html`, and `transfusion_form.html`.
2. THE Frontend SHALL use the JavaScript Fetch API to communicate with all backend API endpoints.
3. WHEN a form submission succeeds, THE Frontend SHALL display a success message to the Staff member without a full page reload.
4. WHEN an API call returns an error, THE Frontend SHALL display the error message returned by the API to the Staff member.
5. WHEN the inventory dashboard is active, THE Frontend SHALL automatically refresh inventory data every 30 seconds.
6. THE Frontend SHALL be usable on screen widths from 320px to 1920px without horizontal scrolling.

---

### Requirement 13: Version Control & Environment Configuration

**User Story:** As a developer, I want version control and environment separation, so that credentials are not committed and the codebase is maintainable.

#### Acceptance Criteria

1. THE BDMS SHALL include a `.gitignore` file that excludes `config/db.php`, `.env` files, and any file containing database credentials.
2. THE BDMS SHALL include a `config/db.example.php` or `.env.example` file documenting all required environment variables without real values.
3. THE BDMS SHALL include a `README.md` with setup instructions covering XAMPP/WAMP configuration, database import, and environment variable setup.
