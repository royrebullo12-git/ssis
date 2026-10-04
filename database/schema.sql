-- =====================================================================
-- Student Services Information System (SSIS) - CCS109
-- Database: ssis_db  |  Engine: InnoDB  |  Charset: utf8mb4
-- Run:  mysql -u root -p < database/schema.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS ssis_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ssis_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs, document_requests, document_types, payments,
                     clearances, grades, subjects, students, login_attempts,
                     users, departments;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- ERD SUMMARY (1 = one, N = many)
--   departments 1--N students | departments 1--N subjects
--   users       1--1 students (student accounts only)
--   students    1--N grades | clearances | payments | document_requests
--   subjects    1--N grades
--   document_types 1--N document_requests
--   payments    1--0..1 document_requests (fee payment for a request)
--   users       1--N audit_logs | users 1--N login_attempts (by username)
-- ---------------------------------------------------------------------

CREATE TABLE departments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(10)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_code (code)
) ENGINE=InnoDB;

CREATE TABLE users (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username         VARCHAR(50)  NOT NULL,
  email            VARCHAR(120) NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  role             ENUM('student','registrar','cashier','department','admin') NOT NULL,
  department_id    INT UNSIGNED NULL COMMENT 'Set for role=department only',
  status           ENUM('active','disabled') NOT NULL DEFAULT 'active',
  failed_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until     DATETIME NULL,
  last_login_at    DATETIME NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  CONSTRAINT fk_users_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Brute-force defence: every attempt is recorded for per-IP / per-username throttling
CREATE TABLE login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  ip_address    VARCHAR(45)  NOT NULL,
  success       TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_ip_time (ip_address, attempted_at),
  KEY idx_attempts_user_time (username, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE students (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  student_no        VARCHAR(20)  NOT NULL,
  first_name        VARCHAR(60)  NOT NULL,
  last_name         VARCHAR(60)  NOT NULL,
  program           VARCHAR(100) NOT NULL,
  year_level        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  department_id     INT UNSIGNED NOT NULL,
  enrollment_status ENUM('not_enrolled','queued','for_assessment','for_payment','enrolled')
                    NOT NULL DEFAULT 'not_enrolled',
  queue_number      INT UNSIGNED NULL,
  contact_no        VARCHAR(20)  NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_user (user_id),
  UNIQUE KEY uq_students_no (student_no),
  KEY idx_students_name (last_name, first_name),
  KEY idx_students_enrollment (enrollment_status, queue_number),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_students_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE subjects (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(15)  NOT NULL,
  title          VARCHAR(120) NOT NULL,
  units          TINYINT UNSIGNED NOT NULL DEFAULT 3,
  department_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subjects_code (code),
  CONSTRAINT fk_subjects_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE grades (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id   INT UNSIGNED NOT NULL,
  subject_id   INT UNSIGNED NOT NULL,
  school_year  CHAR(9)      NOT NULL COMMENT 'e.g. 2025-2026',
  semester     ENUM('1st','2nd','summer') NOT NULL,
  grade        DECIMAL(3,2) NULL COMMENT '1.00 (best) to 5.00; NULL = not yet encoded',
  remarks      ENUM('passed','failed','incomplete','dropped','pending') NOT NULL DEFAULT 'pending',
  encoded_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grade_term (student_id, subject_id, school_year, semester),
  KEY idx_grades_term (school_year, semester),
  CONSTRAINT chk_grade_range CHECK (grade IS NULL OR (grade >= 1.00 AND grade <= 5.00)),
  CONSTRAINT fk_grades_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_grades_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_grades_encoder FOREIGN KEY (encoded_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- One row per office a student must be cleared by, per term.
-- clearance_type = registrar | cashier | department (department_id required for department)
-- Duplicate prevention for department rows (NULL-safe) is enforced via the generated column.
CREATE TABLE clearances (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id     INT UNSIGNED NOT NULL,
  clearance_type ENUM('registrar','cashier','department') NOT NULL,
  department_id  INT UNSIGNED NULL,
  school_year    CHAR(9) NOT NULL,
  semester       ENUM('1st','2nd','summer') NOT NULL,
  status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  remarks        VARCHAR(255) NULL,
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    DATETIME NULL,
  dept_key       INT UNSIGNED GENERATED ALWAYS AS (IFNULL(department_id, 0)) STORED,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clearance (student_id, clearance_type, dept_key, school_year, semester),
  KEY idx_clearance_status (clearance_type, status),
  CONSTRAINT fk_clear_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_clear_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE RESTRICT, -- RESTRICT: base column of a stored generated column
  CONSTRAINT fk_clear_reviewer FOREIGN KEY (reviewed_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    INT UNSIGNED NOT NULL,
  reference_no  VARCHAR(30)  NOT NULL,
  description   VARCHAR(150) NOT NULL,
  amount_due    DECIMAL(10,2) NOT NULL,
  amount_paid   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status        ENUM('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
  method        ENUM('cash','gcash','bank','card') NULL,
  paid_at       DATETIME NULL,
  processed_by  INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_ref (reference_no),
  KEY idx_payments_student_status (student_id, status),
  CONSTRAINT chk_pay_amounts CHECK (amount_due >= 0 AND amount_paid >= 0),
  CONSTRAINT fk_pay_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pay_processor FOREIGN KEY (processed_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE document_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  fee         DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  processing_days TINYINT UNSIGNED NOT NULL DEFAULT 3,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doctype_name (name)
) ENGINE=InnoDB;

CREATE TABLE document_requests (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  document_type_id INT UNSIGNED NOT NULL,
  purpose          VARCHAR(255) NOT NULL,
  copies           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  fee_amount       DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  payment_id       INT UNSIGNED NULL,
  status           ENUM('submitted','awaiting_payment','processing','ready','released','rejected','cancelled')
                   NOT NULL DEFAULT 'submitted',
  remarks          VARCHAR(255) NULL,
  processed_by     INT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_docreq_student (student_id, status),
  KEY idx_docreq_status (status, created_at),
  CONSTRAINT chk_docreq_copies CHECK (copies BETWEEN 1 AND 10),
  CONSTRAINT fk_docreq_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_docreq_type FOREIGN KEY (document_type_id)
    REFERENCES document_types(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_docreq_payment FOREIGN KEY (payment_id)
    REFERENCES payments(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_docreq_processor FOREIGN KEY (processed_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(50) NULL COMMENT 'Kept even if the user row is deleted',
  action      VARCHAR(60) NOT NULL COMMENT 'e.g. LOGIN_SUCCESS, LOGIN_FAILED, ACCESS_DENIED',
  entity      VARCHAR(40) NULL,
  entity_id   INT UNSIGNED NULL,
  details     VARCHAR(500) NULL,
  ip_address  VARCHAR(45) NOT NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_action_time (action, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- SEED DATA
-- All seed accounts use the password:  Password@123   (bcrypt, cost 10)
-- CHANGE THESE before any public deployment.
-- =====================================================================

INSERT INTO departments (code, name) VALUES
  ('CCS',  'College of Computer Studies'),
  ('CBA',  'College of Business Administration'),
  ('COE',  'College of Engineering');

INSERT INTO users (username, email, password_hash, role, department_id) VALUES
  ('admin',      'admin@ssis.edu.ph',      '$2b$10$sodcD7jMKoXuJDcqJx6WM.689JO9RxJnT2/S37xIUT/VVg9nkizS2', 'admin',      NULL),
  ('registrar1', 'registrar@ssis.edu.ph',  '$2b$10$sodcD7jMKoXuJDcqJx6WM.689JO9RxJnT2/S37xIUT/VVg9nkizS2', 'registrar',  NULL),
  ('cashier1',   'cashier@ssis.edu.ph',    '$2b$10$sodcD7jMKoXuJDcqJx6WM.689JO9RxJnT2/S37xIUT/VVg9nkizS2', 'cashier',    NULL),
  ('deptccs',    'ccs.head@ssis.edu.ph',   '$2b$10$sodcD7jMKoXuJDcqJx6WM.689JO9RxJnT2/S37xIUT/VVg9nkizS2', 'department', 1),
  ('2024-0001',  'juan.delacruz@ssis.edu.ph', '$2b$10$sodcD7jMKoXuJDcqJx6WM.689JO9RxJnT2/S37xIUT/VVg9nkizS2', 'student',  NULL),
  ('2024-0002',  'maria.santos@ssis.edu.ph',  '$2b$10$sodcD7jMKoXuJDcqJx6WM.689JO9RxJnT2/S37xIUT/VVg9nkizS2', 'student',  NULL);

INSERT INTO students (user_id, student_no, first_name, last_name, program, year_level, department_id, enrollment_status, queue_number, contact_no) VALUES
  (5, '2024-0001', 'Juan',  'Dela Cruz', 'BS Information Technology', 2, 1, 'queued',   14, '09171234567'),
  (6, '2024-0002', 'Maria', 'Santos',    'BS Computer Science',       3, 1, 'enrolled', NULL, '09181234567');

INSERT INTO subjects (code, title, units, department_id) VALUES
  ('CCS101', 'Introduction to Computing',        3, 1),
  ('CCS102', 'Computer Programming 1',           3, 1),
  ('CCS109', 'System Analysis and Design',       3, 1),
  ('GE101',  'Understanding the Self',           3, 1);

INSERT INTO grades (student_id, subject_id, school_year, semester, grade, remarks, encoded_by) VALUES
  (1, 1, '2025-2026', '1st', 1.75, 'passed',  2),
  (1, 2, '2025-2026', '1st', 2.00, 'passed',  2),
  (1, 4, '2025-2026', '1st', 1.50, 'passed',  2),
  (2, 1, '2025-2026', '1st', 1.25, 'passed',  2),
  (2, 3, '2025-2026', '1st', NULL, 'pending', 2);

INSERT INTO clearances (student_id, clearance_type, department_id, school_year, semester, status, remarks, reviewed_by, reviewed_at) VALUES
  (1, 'registrar',  NULL, '2025-2026', '1st', 'pending',  NULL, NULL, NULL),
  (1, 'cashier',    NULL, '2025-2026', '1st', 'pending',  'Balance outstanding', NULL, NULL),
  (1, 'department', 1,    '2025-2026', '1st', 'pending',  NULL, NULL, NULL),
  (2, 'registrar',  NULL, '2025-2026', '1st', 'approved', NULL, 2, NOW()),
  (2, 'cashier',    NULL, '2025-2026', '1st', 'approved', NULL, 3, NOW()),
  (2, 'department', 1,    '2025-2026', '1st', 'approved', NULL, 4, NOW());

INSERT INTO payments (student_id, reference_no, description, amount_due, amount_paid, status, method, paid_at, processed_by) VALUES
  (1, 'PAY-2026-0001', 'Tuition - 1st Sem 2025-2026', 18500.00, 10000.00, 'partial', 'cash',  NOW(), 3),
  (2, 'PAY-2026-0002', 'Tuition - 1st Sem 2025-2026', 18500.00, 18500.00, 'paid',    'gcash', NOW(), 3);

INSERT INTO document_types (name, fee, processing_days) VALUES
  ('Certificate of Enrollment',      50.00,  2),
  ('Transcript of Records',         250.00,  7),
  ('Certificate of Good Moral',     100.00,  3),
  ('Certificate of Grades',          75.00,  2);

INSERT INTO document_requests (student_id, document_type_id, purpose, copies, fee_amount, status) VALUES
  (2, 1, 'Scholarship application', 2, 100.00, 'processing');

INSERT INTO audit_logs (user_id, username, action, entity, details, ip_address) VALUES
  (1, 'admin', 'SYSTEM_SEEDED', 'database', 'Initial seed data loaded', '127.0.0.1');
