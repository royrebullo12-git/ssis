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
- `department/academics.php` — subject, faculty, and subject-offering management.
- `department/classes.php` — department class lists.
- `department/grades.php` — department grade approval / return / rejection.
- `professor/grades.php` — assigned rosters, grade drafts, and submissions.
- `student/enrollment.php` — queue-free registration status page.
- `student/grades.php` — approved grades and approved-only weighted average.
- `student/dashboard.php` — queue references removed.
- `admin/dashboard.php` — system-wide student/staff/security metrics.
- `includes/layout.php` — enterprise-style sidebar/topbar navigation.
- `assets/css/style.css` — responsive portal/landing-page UI.
- `auth/login.php` — supports Student and Registrar portal entry points.
- `database/ssis_enhanced_schema.sql` — combined fresh-install schema and seed data; import this one file once in phpMyAdmin.
- `database/schema.sql` — equivalent fresh-install schema retained for compatibility with existing setup instructions.
- `database/001_professional_upgrade.sql` — migration for an existing database.

## Production notes

1. Change seeded passwords before deployment.
2. The combined SQL import drops same-named tables; use it only for a new or disposable database.
3. Set database credentials through environment variables.
4. Keep `display_errors=0` and review PHP/server logs.
5. Add real university contact information before deployment.
6. Use HTTPS in production.
