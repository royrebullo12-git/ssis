# SSIS Professional Upgrade

## Updated workflow

Public Landing Page → role-specific login → RBAC dashboard

Registrar/Admissions:
1. Register student
2. SSIS creates the student account automatically
3. SSIS creates current-term clearance records
4. Registrar chooses `Pending` or `Active`
5. Registrar enrolls active students into department-created subject offerings

The old student self-service queue is removed. `queue_number` is retained only as a legacy database column during migration.

Department-led academic workflow:
1. Department manages active subjects and faculty profiles.
2. Department creates subject offerings, assigns professors, and defines sections.
3. Registrar enrolls students in active offerings.
4. Assigned professors save draft grades, then submit them to their Department.
5. Department approves, returns with remarks, or rejects each submitted grade.
6. Students see approved grades only; draft and submitted grades are excluded from official records and GPA.

## RBAC

- **Admin** — user management, audit/security monitoring, system analytics and overrides.
- **Registrar/Admissions** — student registration, student records, enrollment processing, clearances and document requests; no grade actions.
- **Department** — subject, faculty and offering management, class lists, grade review and department clearances.
- **Professor** — assigned class rosters, grade drafts, submissions, and returned/rejected grade corrections.
- **Student** — personal dashboard, approved grades, clearance, enrollment status and document requests.
- **Cashier** — payments and fee clearances.

All protected pages continue to use `require_role()`.

## New/changed files

- `index.php` — public pre-login university landing page.
- `registrar/admissions.php` — distributed student registration portal.
- `registrar/dashboard.php` — workload/registration metrics.
- `registrar/students.php` — searchable student records with Pending/Active/Archived status.
- `registrar/enrollments.php` — enrollment into valid department offerings.
- `registrar/curriculum.php` — expected term units used for Regular/Irregular standing.
- `registrar/document_types.php` — document fees, issuing offices, and department routes.
- `admin/departments.php` — university department and issuing-office directory.
- `department/academics.php` — subject, faculty, and subject-offering management.
- `department/faculty.php` — faculty profiles and instructor assignment management.
- `department/classes.php` — department class lists.
- `department/grades.php` — department grade approval / return / rejection.
- `professor/grades.php` — assigned rosters, grade drafts, and submissions.
- `student/enrollment.php` — queue-free registration status page.
- `student/grades.php` — approved grades and approved-only weighted average.
- `student/dashboard.php` — queue references removed.
- `admin/dashboard.php` — system-wide student/staff/security metrics.
- `includes/layout.php` — enterprise-style sidebar/topbar navigation.
- `assets/css/style.css` and `assets/css/wls.css` — responsive portal/landing-page UI and WLS design tokens.
- `cashier/collections.php` — date-filtered payment transaction and daily collection report.
- `auth/account.php`, `auth/forgot_password.php`, and `auth/reset_password.php` — contact settings, password change, and email/SMS OTP recovery.
- `includes/notifications.php` — password policy, SMS credential/OTP messages, and in-app notification helpers.
- `includes/notification_helper.php` — TextBee SMS delivery.
- `database/002_wls_modernization.sql` — additive migration for an existing database.
- `auth/login.php` — supports Student and Registrar portal entry points.
- `database/schema.sql` — fresh-install schema and seed data.

## Production notes

1. Change seeded passwords before deployment.
2. For an existing installation, back up the database and run `database/002_wls_modernization.sql` once. For a fresh or disposable install, import `database/schema.sql`; it drops the named SSIS tables before creating and seeding the complete schema. Do not import it into a database containing data you need to keep.
3. Install PHP dependencies from the project root with `composer install` if Composer is used by the deployment.
4. Configure the following server environment variables; never put credentials in source control:
   - SMTP: `WLS_MAIL_HOST`, `WLS_MAIL_PORT`, `WLS_MAIL_USERNAME`, `WLS_MAIL_PASSWORD`, `WLS_MAIL_FROM`, `WLS_MAIL_FROM_NAME`, `WLS_MAIL_ENCRYPTION` (`tls` or `ssl`), `WLS_PUBLIC_URL` (HTTPS portal URL).
   - SMS: Set `WLS_SMS_API_KEY` and `WLS_SMS_DEVICE_ID` in the Apache/PHP process environment, then restart Apache. In XAMPP, use Windows system environment variables so the Apache service can read them; never put live keys in the repository. `WLS_SMS_GATEWAY_URL` is optional and defaults to `https://api.textbee.dev/api/v1/gateway/send-sms`. TextBee receives the device ID in the JSON payload along with `recipients` and `message`. Phone numbers must be internationally routable; Philippine local mobile numbers beginning with `09` are normalized to `+639...`.
   - Database: use the environment-backed settings in `config/database.php`.
5. Before enabling student services, configure program/year/term unit requirements in **Registrar → Curriculum requirements**, and set issuing offices/department routes in **Registrar → Document types & routing**.
6. The migration backfills each existing payment's current cumulative paid amount as one historical transaction. Earlier installments cannot be reconstructed because the previous schema did not store individual payment events; new payments are recorded as individual transactions.
7. Keep `display_errors=0`, review PHP/server logs, add real university contact information, and use HTTPS in production.
