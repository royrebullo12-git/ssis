-- =====================================================================
-- Student Services Information System (SSIS) - Combined fresh-install schema
-- Database: ssis_db  |  Engine: InnoDB  |  Charset: utf8mb4
-- phpMyAdmin: select/create the ssis_db database, then import this file once.
-- CLI: mysql -u root -p ssis_db < database/schema.sql
-- WARNING: destructive fresh install; this script drops existing SSIS tables.
-- Do not import into a database containing data you need to keep.
-- =====================================================================


SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs, document_requests, document_types, payment_transactions,
                     payments, clearances, grades, drop_requests, enrollments,
                     subject_offerings, professors, curriculum_requirements, subjects,
                     students, notifications, password_reset_otps, login_attempts, users, departments;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- ERD SUMMARY (1 = one, N = many)
--   departments 1--N students | subjects | professors | users
--   students    1--N enrollments | clearances | payments | document_requests
--   subjects    1--N subject_offerings
--   professors  1--N subject_offerings
--   subject_offerings 1--N enrollments | enrollments 1--0..1 grades
--   users       1--1 students/professors (optional for professors)
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
  mobile_phone     VARCHAR(20)  NULL,
  password_hash    VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  role             ENUM('admin','registrar','cashier','department','professor','student') NOT NULL,
  department_id    INT UNSIGNED NULL COMMENT 'Set for department and professor roles',
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

CREATE TABLE password_reset_otps (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  code_hash    VARCHAR(255) NOT NULL,
  expires_at   DATETIME NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at      DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_password_otp_user_created (user_id, created_at),
  KEY idx_password_otp_expiry (expires_at),
  CONSTRAINT fk_password_otp_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  title      VARCHAR(120) NOT NULL,
  body       VARCHAR(500) NOT NULL,
  link       VARCHAR(255) NULL,
  read_at    DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE students (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  student_no        VARCHAR(20)  NOT NULL,
  first_name        VARCHAR(60)  NOT NULL,
  last_name         VARCHAR(60)  NOT NULL,
  middle_name       VARCHAR(60)  NULL,
  date_of_birth     DATE NULL,
  gender            VARCHAR(20) NULL,
  home_address      VARCHAR(255) NULL,
  program           VARCHAR(100) NOT NULL,
  year_level        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  previous_school   VARCHAR(150) NULL,
  admission_year    CHAR(9) NULL,
  department_id     INT UNSIGNED NOT NULL,
  enrollment_status ENUM('pending','active','archived')
                    NOT NULL DEFAULT 'pending',
  queue_number      INT UNSIGNED NULL COMMENT 'Legacy field retained for backward compatibility; not used by SSIS',
  contact_no        VARCHAR(20)  NULL,
  emergency_contact_name  VARCHAR(120) NULL,
  emergency_contact_phone VARCHAR(20) NULL,
  registered_by     INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_user (user_id),
  UNIQUE KEY uq_students_no (student_no),
  KEY idx_students_name (last_name, first_name),
  KEY idx_students_enrollment (enrollment_status),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_students_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_students_registered_by FOREIGN KEY (registered_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE subjects (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(15)  NOT NULL,
  title          VARCHAR(120) NOT NULL,
  units          TINYINT UNSIGNED NOT NULL DEFAULT 3,
  department_id  INT UNSIGNED NOT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subjects_code (code),
  CONSTRAINT fk_subjects_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE curriculum_requirements (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  program         VARCHAR(100) NOT NULL,
  year_level      TINYINT UNSIGNED NOT NULL,
  academic_year   CHAR(9) NOT NULL,
  semester        ENUM('1st','2nd','summer') NOT NULL,
  required_units  SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_curriculum_program_term (program, year_level, academic_year, semester)
) ENGINE=InnoDB;

CREATE TABLE professors (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NULL,
  department_id  INT UNSIGNED NOT NULL,
  first_name     VARCHAR(60) NOT NULL,
  last_name      VARCHAR(60) NOT NULL,
  email          VARCHAR(120) NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_professors_user (user_id),
  KEY idx_professors_department (department_id, status),
  CONSTRAINT fk_professor_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_professor_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE subject_offerings (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_id         INT UNSIGNED NOT NULL,
  professor_id       INT UNSIGNED NOT NULL,
  academic_year      CHAR(9) NOT NULL COMMENT 'e.g. 2025-2026',
  semester           ENUM('1st','2nd','summer') NOT NULL,
  section            VARCHAR(30) NOT NULL,
  schedule           VARCHAR(120) NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_offering_subject_term_section (subject_id, academic_year, semester, section),
  KEY idx_offering_professor_term (professor_id, academic_year, semester),
  CONSTRAINT fk_offering_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_offering_professor FOREIGN KEY (professor_id)
    REFERENCES professors(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE enrollments (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id          INT UNSIGNED NOT NULL,
  subject_offering_id INT UNSIGNED NOT NULL,
  status              ENUM('pending','enrolled','dropped') NOT NULL DEFAULT 'enrolled',
  enrolled_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment_student_offering (student_id, subject_offering_id),
  KEY idx_enrollment_offering_status (subject_offering_id, status),
  CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_enrollment_offering FOREIGN KEY (subject_offering_id)
    REFERENCES subject_offerings(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE drop_requests (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id   INT UNSIGNED NOT NULL,
  enrollment_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  reason       VARCHAR(80) NOT NULL,
  details      VARCHAR(500) NULL,
  status       ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'submitted',
  remarks      VARCHAR(255) NULL,
  reviewed_by  INT UNSIGNED NULL,
  reviewed_at  DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_drop_requests_department_status (department_id, status, created_at),
  KEY idx_drop_requests_student (student_id, created_at),
  CONSTRAINT fk_drop_request_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_drop_request_enrollment FOREIGN KEY (enrollment_id)
    REFERENCES enrollments(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_drop_request_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_drop_request_reviewer FOREIGN KEY (reviewed_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE grades (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id  INT UNSIGNED NOT NULL,
  prelim         DECIMAL(5,2) NULL,
  midterm        DECIMAL(5,2) NULL,
  final          DECIMAL(5,2) NULL,
  computed_final DECIMAL(5,2) NULL,
  status         ENUM('draft','submitted','approved','returned','rejected') NOT NULL DEFAULT 'draft',
  remarks        VARCHAR(500) NULL,
  encoded_by     INT UNSIGNED NULL COMMENT 'Professor user who last encoded the grade',
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grade_enrollment (enrollment_id),
  KEY idx_grades_status (status, updated_at),
  CONSTRAINT chk_grade_range CHECK (
    (prelim IS NULL OR (prelim >= 1.00 AND prelim <= 5.00)) AND
    (midterm IS NULL OR (midterm >= 1.00 AND midterm <= 5.00)) AND
    (final IS NULL OR (final >= 1.00 AND final <= 5.00)) AND
    (computed_final IS NULL OR (computed_final >= 1.00 AND computed_final <= 5.00))
  ),
  CONSTRAINT fk_grades_enrollment FOREIGN KEY (enrollment_id)
    REFERENCES enrollments(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_grades_encoder FOREIGN KEY (encoded_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_grades_reviewer FOREIGN KEY (reviewed_by)
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

CREATE TABLE payment_transactions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id   INT UNSIGNED NOT NULL,
  amount       DECIMAL(10,2) NOT NULL,
  method       ENUM('cash','gcash','bank','card') NOT NULL,
  paid_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_by INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_payment_transactions_paid_at (paid_at, payment_id),
  CONSTRAINT chk_payment_transaction_amount CHECK (amount > 0),
  CONSTRAINT fk_payment_transaction_payment FOREIGN KEY (payment_id)
    REFERENCES payments(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_payment_transaction_processor FOREIGN KEY (processed_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE document_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  fee         DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  processing_days TINYINT UNSIGNED NOT NULL DEFAULT 3,
  issuing_office VARCHAR(120) NOT NULL DEFAULT 'Registrar',
  issuing_department_id INT UNSIGNED NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doctype_name (name),
  CONSTRAINT fk_doctype_department FOREIGN KEY (issuing_department_id)
    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE document_requests (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  document_type_id INT UNSIGNED NOT NULL,
  purpose          VARCHAR(255) NOT NULL,
  copies           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  fee_amount       DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  routed_department_id INT UNSIGNED NULL,
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
  CONSTRAINT fk_docreq_department FOREIGN KEY (routed_department_id)
    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE,
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

INSERT INTO users (username, email, password_hash, role, department_id) VALUES
  ('prof.ccs1', 'prof.ccs1@ssis.edu.ph', '$2b$10$sodcD7jMKoXuJDcqJx6WM.689JO9RxJnT2/S37xIUT/VVg9nkizS2', 'professor', 1);

INSERT INTO professors (user_id, department_id, first_name, last_name, email) VALUES
  (7, 1, 'Alex', 'Reyes', 'prof.ccs1@ssis.edu.ph');

INSERT INTO students
(user_id, student_no, first_name, last_name, program, year_level, department_id, enrollment_status, queue_number, contact_no, registered_by)
VALUES
(5, '2024-0001', 'Juan', 'Dela Cruz', 'BS Information Technology', 2, 1, 'pending', NULL, '09171234567', 2),
(6, '2024-0002', 'Maria', 'Santos', 'BS Computer Science', 3, 1, 'active', NULL, '09181234567', 2);

INSERT INTO subjects (code, title, units, department_id) VALUES
  ('CCS101', 'Introduction to Computing',        3, 1),
  ('CCS102', 'Computer Programming 1',           3, 1),
  ('CCS109', 'System Analysis and Design',       3, 1),
  ('GE101',  'Understanding the Self',           3, 1);

INSERT INTO subject_offerings (subject_id, professor_id, academic_year, semester, section, schedule) VALUES
  (1, 1, '2025-2026', '1st', 'A', 'Mon/Wed 8:00-9:30'),
  (2, 1, '2025-2026', '1st', 'A', 'Tue/Thu 9:30-11:00'),
  (3, 1, '2025-2026', '1st', 'A', 'Mon/Wed 10:00-11:30'),
  (4, 1, '2025-2026', '1st', 'A', 'Fri 8:00-11:00');

INSERT INTO enrollments (student_id, subject_offering_id, status) VALUES
  (1, 1, 'enrolled'), (1, 2, 'enrolled'), (1, 4, 'enrolled'),
  (2, 1, 'enrolled'), (2, 3, 'enrolled');

INSERT INTO grades (enrollment_id, prelim, midterm, final, computed_final, status, encoded_by, reviewed_by, reviewed_at) VALUES
  (1, 1.75, 1.75, 1.75, 1.75, 'approved', 7, 4, NOW()),
  (2, 2.00, 2.00, 2.00, 2.00, 'approved', 7, 4, NOW()),
  (3, 1.50, 1.50, 1.50, 1.50, 'approved', 7, 4, NOW()),
  (4, 1.25, 1.25, 1.25, 1.25, 'approved', 7, 4, NOW()),
  (5, NULL, NULL, NULL, NULL, 'draft', 7, NULL, NULL);

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
INSERT INTO payment_transactions (payment_id, amount, method, paid_at, processed_by)
SELECT id, amount_paid, method, paid_at, processed_by FROM payments
WHERE amount_paid > 0 AND paid_at IS NOT NULL AND method IS NOT NULL;

INSERT INTO document_types (name, fee, processing_days) VALUES
  ('Certificate of Enrollment',      50.00,  2),
  ('Transcript of Records',         250.00,  7),
  ('Certificate of Good Moral',     100.00,  3),
  ('Certificate of Grades',          75.00,  2);

INSERT INTO document_requests (student_id, document_type_id, purpose, copies, fee_amount, status) VALUES
  (2, 1, 'Scholarship application', 2, 100.00, 'processing');

INSERT INTO audit_logs (user_id, username, action, entity, details, ip_address) VALUES
  (1, 'admin', 'SYSTEM_SEEDED', 'database', 'Initial seed data loaded', '127.0.0.1');
