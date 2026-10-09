-- Additive migration for WLS credential recovery, notifications, curriculum,
-- course drop requests, and department-routed document requests.
-- Back up ssis_db before applying. Do not rerun this migration.

ALTER TABLE users
  ADD COLUMN mobile_phone VARCHAR(20) NULL AFTER email,
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

ALTER TABLE students
  ADD COLUMN middle_name VARCHAR(60) NULL AFTER last_name,
  ADD COLUMN date_of_birth DATE NULL AFTER middle_name,
  ADD COLUMN gender VARCHAR(20) NULL AFTER date_of_birth,
  ADD COLUMN home_address VARCHAR(255) NULL AFTER gender,
  ADD COLUMN previous_school VARCHAR(150) NULL AFTER year_level,
  ADD COLUMN admission_year CHAR(9) NULL AFTER previous_school,
  ADD COLUMN emergency_contact_name VARCHAR(120) NULL AFTER contact_no,
  ADD COLUMN emergency_contact_phone VARCHAR(20) NULL AFTER emergency_contact_name;

ALTER TABLE document_types
  ADD COLUMN issuing_office VARCHAR(120) NOT NULL DEFAULT 'Registrar' AFTER processing_days,
  ADD COLUMN issuing_department_id INT UNSIGNED NULL AFTER issuing_office,
  ADD CONSTRAINT fk_doctype_department FOREIGN KEY (issuing_department_id)
    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE document_requests
  ADD COLUMN routed_department_id INT UNSIGNED NULL AFTER fee_amount,
  ADD CONSTRAINT fk_docreq_department FOREIGN KEY (routed_department_id)
    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE;

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

INSERT INTO payment_transactions (payment_id, amount, method, paid_at, processed_by)
SELECT id, amount_paid, method, paid_at, processed_by FROM payments
WHERE amount_paid > 0 AND paid_at IS NOT NULL AND method IS NOT NULL;

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

CREATE TABLE drop_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    INT UNSIGNED NOT NULL,
  enrollment_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  reason        VARCHAR(80) NOT NULL,
  details       VARCHAR(500) NULL,
  status        ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'submitted',
  remarks       VARCHAR(255) NULL,
  reviewed_by   INT UNSIGNED NULL,
  reviewed_at   DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
