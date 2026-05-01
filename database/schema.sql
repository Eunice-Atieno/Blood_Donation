-- KNH Blood Donation Management System — Database Schema
-- Target: MySQL 8.x
-- Usage: mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS knh_bdms_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE knh_bdms_db;

-- -------------------------------------------------------
-- Table: staff
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS staff (
    id               INT            NOT NULL AUTO_INCREMENT,
    username         VARCHAR(50)    NOT NULL,
    password_hash    VARCHAR(255)   NOT NULL,
    role             ENUM('Administrator','Doctor','Lab_Technician') NOT NULL,
    email            VARCHAR(100)   NULL,
    failed_attempts  TINYINT        NOT NULL DEFAULT 0,
    locked           TINYINT(1)     NOT NULL DEFAULT 0,
    created_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_username (username),
    UNIQUE KEY uq_staff_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add email column to existing staff table if it doesn't exist
ALTER TABLE staff ADD COLUMN IF NOT EXISTS email VARCHAR(100) NULL AFTER role;

-- -------------------------------------------------------
-- Table: donors
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS donors (
    id                   INT          NOT NULL AUTO_INCREMENT,
    name                 VARCHAR(100) NOT NULL,
    date_of_birth        DATE         NOT NULL,
    blood_type           ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    national_id          VARCHAR(20)  NOT NULL,
    email                VARCHAR(100) NOT NULL,
    phone                VARCHAR(20)  NOT NULL,
    password_hash        VARCHAR(255) NULL,
    medical_history_flag TINYINT(1)   NOT NULL DEFAULT 0,
    last_donation_date   DATE         NULL,
    sms_opt_in           TINYINT(1)   NOT NULL DEFAULT 1,
    email_opt_in         TINYINT(1)   NOT NULL DEFAULT 1,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_donors_national_id (national_id),
    UNIQUE KEY uq_donors_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: blood_units
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS blood_units (
    id              INT      NOT NULL AUTO_INCREMENT,
    donor_id        INT      NOT NULL,
    blood_type      ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    collection_date DATE     NOT NULL,
    expiry_date     DATE     NOT NULL,
    status          ENUM('available','transfused','expired') NOT NULL DEFAULT 'available',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_blood_units_donor
        FOREIGN KEY (donor_id) REFERENCES donors (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: blood_inventory
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS blood_inventory (
    id         INT      NOT NULL AUTO_INCREMENT,
    blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    unit_count INT      NOT NULL DEFAULT 0,
    low_stock  TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blood_inventory_type (blood_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed one row per blood type so inventory records always exist
INSERT IGNORE INTO blood_inventory (blood_type, unit_count, low_stock)
VALUES
    ('A+',  0, 1),
    ('A-',  0, 1),
    ('B+',  0, 1),
    ('B-',  0, 1),
    ('AB+', 0, 1),
    ('AB-', 0, 1),
    ('O+',  0, 1),
    ('O-',  0, 1);

-- -------------------------------------------------------
-- Table: transfusions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS transfusions (
    id                 INT         NOT NULL AUTO_INCREMENT,
    blood_unit_id      INT         NOT NULL,
    patient_identifier VARCHAR(50) NOT NULL,
    transfusion_date   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    staff_id           INT         NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_transfusions_blood_unit
        FOREIGN KEY (blood_unit_id) REFERENCES blood_units (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_transfusions_staff
        FOREIGN KEY (staff_id) REFERENCES staff (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: appointments
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointments (
    id               INT      NOT NULL AUTO_INCREMENT,
    donor_id         INT      NOT NULL,
    appointment_date DATE     NOT NULL,
    appointment_time TIME     NOT NULL,
    status           ENUM('scheduled','cancelled','completed') NOT NULL DEFAULT 'scheduled',
    notes            TEXT     NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_appointments_donor
        FOREIGN KEY (donor_id) REFERENCES donors (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: notifications
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id               INT      NOT NULL AUTO_INCREMENT,
    donor_id         INT      NOT NULL,
    message_type     ENUM('eligibility_reminder','low_stock_alert') NOT NULL,
    sent_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivery_status  ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (id),
    CONSTRAINT fk_notifications_donor
        FOREIGN KEY (donor_id) REFERENCES donors (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Alter staff table: remove Nurse, keep Administrator, Doctor, Lab_Technician
-- -------------------------------------------------------
ALTER TABLE staff MODIFY COLUMN role ENUM('Administrator','Doctor','Lab_Technician') NOT NULL;

-- -------------------------------------------------------
-- Table: blood_requests
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS blood_requests (
    id                  INT          NOT NULL AUTO_INCREMENT,
    requested_by        INT          NOT NULL,
    patient_identifier  VARCHAR(100) NOT NULL,
    blood_type          VARCHAR(5)   NOT NULL,
    quantity            TINYINT      NOT NULL DEFAULT 1,
    priority            ENUM('normal','emergency') NOT NULL DEFAULT 'normal',
    status              ENUM('pending','allocated','completed','cancelled') NOT NULL DEFAULT 'pending',
    allocated_unit_id   INT          NULL,
    notes               TEXT         NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_blood_requests_staff
        FOREIGN KEY (requested_by) REFERENCES staff (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_blood_requests_unit
        FOREIGN KEY (allocated_unit_id) REFERENCES blood_units (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: lab_results
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS lab_results (
    id              INT          NOT NULL AUTO_INCREMENT,
    blood_unit_id   INT          NOT NULL,
    tested_by       INT          NOT NULL,
    hiv             TINYINT(1)   NOT NULL DEFAULT 0,
    hepatitis_b     TINYINT(1)   NOT NULL DEFAULT 0,
    hepatitis_c     TINYINT(1)   NOT NULL DEFAULT 0,
    syphilis        TINYINT(1)   NOT NULL DEFAULT 0,
    malaria         TINYINT(1)   NOT NULL DEFAULT 0,
    passed          TINYINT(1)   NOT NULL DEFAULT 0,
    notes           TEXT         NULL,
    tested_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lab_results_unit (blood_unit_id),
    CONSTRAINT fk_lab_results_unit
        FOREIGN KEY (blood_unit_id) REFERENCES blood_units (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lab_results_staff
        FOREIGN KEY (tested_by) REFERENCES staff (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: messages
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id               INT          NOT NULL AUTO_INCREMENT,
    sender_type      ENUM('staff','donor') NOT NULL,
    sender_id        INT          NOT NULL,
    recipient_type   ENUM('staff','donor') NOT NULL,
    recipient_id     INT          NOT NULL,
    message          TEXT         NOT NULL,
    sent_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read          TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_recipient (recipient_type, recipient_id),
    KEY idx_sender    (sender_type, sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: staff_notifications
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS staff_notifications (
    id               INT          NOT NULL AUTO_INCREMENT,
    staff_id         INT          NOT NULL,
    message          TEXT         NOT NULL,
    sent_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivery_status  ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (id),
    CONSTRAINT fk_staff_notif_staff
        FOREIGN KEY (staff_id) REFERENCES staff (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Initial Admin Account (run setup_admin.php instead,
-- or insert manually with a bcrypt-hashed password)
-- -------------------------------------------------------
-- Example (replace the hash with a real bcrypt hash):
-- INSERT IGNORE INTO staff (username, password_hash, role, email)
-- VALUES ('admin', '$2y$10$REPLACE_WITH_REAL_HASH', 'Administrator', 'admin@knh.go.ke');
