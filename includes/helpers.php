<?php
declare(strict_types=1);
if (!defined('SSIS_BOOT')) { http_response_code(403); exit('Direct access forbidden.'); }


/* ---- mbstring fallbacks: the app works even when ext-mbstring is not enabled ---- */
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null): string
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, $start, $length));
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int { return count(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []); }
}

/* ---- flash messages (survive one redirect) ---- */
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flash_take(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function money(float|string|null $n): string { return '₱' . number_format((float)$n, 2); }

function fmt_date(?string $d): string { return $d ? date('M j, Y g:i A', strtotime($d)) : '-'; }

/** Pretty label for ENUM values: awaiting_payment -> Awaiting payment */
function label(string $v): string { return ucfirst(str_replace('_', ' ', $v)); }

function badge(string $status): string
{
    $gradeStatuses = [
        'draft' => 'secondary', 'submitted' => 'info', 'approved' => 'good',
        'returned' => 'warn', 'rejected' => 'bad',
    ];
    if (isset($gradeStatuses[$status])) {
        return '<span class="badge ' . $gradeStatuses[$status] . '">' . e(label($status)) . '</span>';
    }
    $good = ['paid', 'enrolled', 'passed', 'released', 'ready', 'active', 'regular'];
    $warn = ['pending', 'partial', 'awaiting_payment', 'processing', 'incomplete', 'unconfigured'];
    $cls  = in_array($status, $good, true) ? 'good' : (in_array($status, $warn, true) ? 'warn' : 'bad');
    return '<span class="badge ' . $cls . '">' . e(label($status)) . '</span>';
}

/** Every POST handler calls this first: enforces POST + CSRF (broken-access/CSRF defence). */
function post_guard(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, reload the page and try again.');
    }
}

function post_str(string $k, int $max = 255): string
{
    $v = $_POST[$k] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}
function post_int(string $k): int { return filter_var($_POST[$k] ?? null, FILTER_VALIDATE_INT) ?: 0; }
function get_int(string $k): int { return filter_var($_GET[$k] ?? null, FILTER_VALIDATE_INT) ?: 0; }
function get_str(string $k, int $max = 100): string
{
    $v = $_GET[$k] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

/** Student row for the signed-in student. ID comes from the session, never from the request (no IDOR). */
function student_of(array $user): array
{
    $st = db()->prepare(
        'SELECT s.*, d.name AS department_name FROM students s
           JOIN departments d ON d.id = s.department_id WHERE s.user_id = ? LIMIT 1'
    );
    $st->execute([(int)$user['id']]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(403);
        exit('No student record is linked to this account. Contact the Registrar.');
    }
    return $row;
}

function student_balance(int $studentId): float
{
    $st = db()->prepare(
        "SELECT COALESCE(SUM(amount_due - amount_paid), 0) FROM payments
          WHERE student_id = ? AND status <> 'void'"
    );
    $st->execute([$studentId]);
    return max(0.0, (float)$st->fetchColumn());
}

/** Compare registered units against the entered curriculum requirement for the current term. */
function student_unit_status(array $student, string $schoolYear = CURRENT_SY, string $semester = CURRENT_SEM): array
{
    $pdo = db();
    $units = $pdo->prepare(
        "SELECT COALESCE(SUM(sub.units), 0)
           FROM enrollments e
           JOIN subject_offerings o ON o.id = e.subject_offering_id
           JOIN subjects sub ON sub.id = o.subject_id
          WHERE e.student_id = ? AND e.status = 'enrolled'
            AND o.academic_year = ? AND o.semester = ?"
    );
    $units->execute([(int)$student['id'], $schoolYear, $semester]);
    $enrolled = (int)$units->fetchColumn();
    $requirement = $pdo->prepare(
        'SELECT required_units FROM curriculum_requirements
          WHERE program = ? AND year_level = ? AND academic_year = ? AND semester = ? LIMIT 1'
    );
    $requirement->execute([$student['program'], (int)$student['year_level'], $schoolYear, $semester]);
    $required = $requirement->fetchColumn();
    return [
        'status' => $required === false ? 'unconfigured' : ($enrolled >= (int)$required ? 'regular' : 'irregular'),
        'enrolled_units' => $enrolled,
        'required_units' => $required === false ? null : (int)$required,
    ];
}

function new_reference(string $prefix): string
{
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function next_student_number(PDO $pdo, ?string $academicYear = null): string
{
    $yearPrefix = $academicYear !== null && preg_match('/^\d{4}-\d{4}$/', $academicYear)
        ? substr($academicYear, 0, 4)
        : substr(CURRENT_SY, 0, 4);
    $lockName = 'wls-student-number-' . $yearPrefix;
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not reserve a student number. Please try again.');
    }

    try {
        $statement = $pdo->prepare(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(student_no, 6) AS UNSIGNED)), 0)
               FROM students WHERE student_no LIKE ?'
        );
        $statement->execute([$yearPrefix . '-%']);
        $nextNumber = (int)$statement->fetchColumn() + 1;
        return $yearPrefix . '-' . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
        throw $e;
    }
}

function release_student_number_lock(PDO $pdo, ?string $academicYear = null): void
{
    $yearPrefix = $academicYear !== null && preg_match('/^\d{4}-\d{4}$/', $academicYear)
        ? substr($academicYear, 0, 4)
        : substr(CURRENT_SY, 0, 4);
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute(['wls-student-number-' . $yearPrefix]);
}

function redirect_self(): void
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#'));
    exit;
}
