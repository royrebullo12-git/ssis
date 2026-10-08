<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo = db();

function admissions_password_error(string $p): ?string {
    if (strlen($p) < 10 || strlen($p) > 200) return 'Temporary password must be 10 to 200 characters.';
    if (!preg_match('/[a-z]/', $p) || !preg_match('/[A-Z]/', $p) || !preg_match('/\d/', $p)) return 'Password needs an uppercase letter, lowercase letter and a digit.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $first = post_str('first_name', 60);
    $last = post_str('last_name', 60);
    $email = post_str('email', 120);
    $program = post_str('program', 100);
    $year = post_int('year_level');
    $dept = post_int('department_id');
    $contact = post_str('contact_no', 20);
    $status = post_str('enrollment_status', 12);
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    $err = admissions_password_error($password);
    $validStatuses = ['pending', 'active', 'archived'];
    if ($first === '' || $last === '' || $program === '') $err = 'First name, last name and program are required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
    elseif ($year < 1 || $year > 6) $err = 'Year level must be 1 to 6.';
    elseif (!$dept) $err = 'Choose a department.';
    elseif (!in_array($status, $validStatuses, true)) $err = 'Choose a valid student status.';
    elseif ($contact !== '' && !preg_match('/^[0-9+\- ]{7,20}$/', $contact)) $err = 'Contact number may only contain digits, +, - and spaces.';

    if ($err) {
        flash('error', $err);
    } else {
        try {
            $pdo->beginTransaction();
            $yearPrefix = date('Y');
            $next = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(student_no, 6) AS UNSIGNED)), 0) + 1
                                   FROM students WHERE student_no LIKE ? FOR UPDATE");
            $next->execute([$yearPrefix . '-%']);
            $studentNo = $yearPrefix . '-' . str_pad((string)(int)$next->fetchColumn(), 4, '0', STR_PAD_LEFT);

            $pdo->prepare('INSERT INTO users (username, email, password_hash, role, department_id) VALUES (?, ?, ?, ?, NULL)')
                ->execute([$studentNo, $email, password_hash($password, PASSWORD_DEFAULT), 'student']);
            $uid = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO students (user_id, student_no, first_name, last_name, program, year_level, department_id, enrollment_status, contact_no, registered_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$uid, $studentNo, $first, $last, $program, $year, $dept, $status, $contact ?: null, (int)$user['id']]);
            $sid = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare('INSERT INTO clearances (student_id, clearance_type, department_id, school_year, semester) VALUES (?, ?, ?, ?, ?)');
            foreach ([['registrar', null], ['cashier', null], ['department', $dept]] as [$ct, $cd]) {
                $ins->execute([$sid, $ct, $cd, CURRENT_SY, CURRENT_SEM]);
            }
            $pdo->commit();

            audit_log('STUDENT_REGISTERED', (int)$user['id'], $user['username'], 'student', $sid, "{$studentNo} registered as {$status}");
            flash('success', "Student {$studentNo} was registered successfully. The account can now be used with the temporary password.");
            redirect('/registrar/students.php?new=' . urlencode($studentNo));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') flash('error', 'The email or generated student number is already in use. Please submit again.');
            else { error_log('[SSIS] registrar/admissions: ' . $e->getMessage()); flash('error', 'The student could not be registered.'); }
        }
    }
    redirect_self();
}

$depts = $pdo->query('SELECT id, code, name FROM departments ORDER BY code')->fetchAll();
render_header($user, 'Registrar admissions');
?>
<div class="content-grid">
  <section class="card">
    <div class="section-title"><div><p class="card-kicker">NEW STUDENT</p><h2>Register a student</h2></div><span class="badge good">Registrar authorized</span></div>
    <p class="muted">Admissions staff can create student accounts and records directly. No admin hand-off or queue is required.</p>
    <?= form_open() ?>
    <div class="row">
      <div><label for="first_name">First name</label><input id="first_name" name="first_name" required maxlength="60"></div>
      <div><label for="last_name">Last name</label><input id="last_name" name="last_name" required maxlength="60"></div>
      <div><label for="email">Student email</label><input id="email" name="email" type="email" required maxlength="120"></div>
      <div><label for="contact_no">Contact number</label><input id="contact_no" name="contact_no" maxlength="20"></div>
      <div><label for="program">Program</label><input id="program" name="program" required maxlength="100" placeholder="BS Information Technology"></div>
      <div><label for="year_level">Year level</label><input id="year_level" name="year_level" type="number" min="1" max="6" value="1" required></div>
      <div><label for="department_id">Department</label><select id="department_id" name="department_id" required><option value="">Select department</option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['code'] . ' - ' . $d['name']) ?></option><?php endforeach; ?></select></div>
      <div><label for="enrollment_status">Initial status</label><select id="enrollment_status" name="enrollment_status"><option value="active">Active</option><option value="pending">Pending verification</option><option value="archived">Archived</option></select></div>
      <div><label for="password">Temporary password</label><input id="password" name="password" type="password" minlength="10" required autocomplete="new-password" placeholder="At least 10 characters"></div>
    </div>
    <div class="form-actions"><button class="btn" type="submit">Register student</button><a class="btn alt" href="<?= e(url('/registrar/students.php')) ?>">View records</a></div>
    </form>
  </section>
  <aside class="card">
    <p class="card-kicker">WORKFLOW</p><h2>How admissions works</h2>
    <ol class="clean-list">
      <li><b>Capture</b><span>Enter the student's verified information.</span></li>
      <li><b>Account</b><span>SSIS automatically creates the student login.</span></li>
      <li><b>Activate</b><span>Choose Active when the registration is verified.</span></li>
      <li><b>Services</b><span>Clearance records are created automatically for the current term.</span></li>
    </ol>
  </aside>
</div>
<?php render_footer();
