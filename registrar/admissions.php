<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_role('registrar');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $first = post_str('first_name', 60);
    $last = post_str('last_name', 60);
    $middle = post_str('middle_name', 60);
    $birthDate = post_str('date_of_birth', 10);
    $gender = post_str('gender', 20);
    $address = post_str('home_address', 255);
    $email = post_str('email', 120);
    $program = post_str('program', 100);
    $year = post_int('year_level');
    $previousSchool = post_str('previous_school', 150);
    $admissionYear = post_str('admission_year', 9);
    $dept = post_int('department_id');
    $contact = preg_replace('/[\s()-]/', '', post_str('contact_no', 20)) ?? '';
    $emergencyName = post_str('emergency_contact_name', 120);
    $emergencyPhone = preg_replace('/[\s()-]/', '', post_str('emergency_contact_phone', 20)) ?? '';
    $status = post_str('enrollment_status', 12);
    $err = null;
    $validStatuses = ['pending', 'active', 'archived'];
    if ($first === '' || $last === '' || $program === '') $err = 'First name, last name and program are required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
    elseif ($year < 1 || $year > 6) $err = 'Year level must be 1 to 6.';
    elseif ($birthDate !== '' && !DateTime::createFromFormat('Y-m-d', $birthDate)) $err = 'Enter a valid date of birth.';
    elseif ($admissionYear !== '' && !preg_match('/^\d{4}-\d{4}$/', $admissionYear)) $err = 'Admission year must use the YYYY-YYYY format.';
    elseif (!$dept) $err = 'Choose a department.';
    elseif (!in_array($status, $validStatuses, true)) $err = 'Choose a valid student status.';
    elseif (!preg_match('/^\+?[0-9]{8,15}$/', $contact)) $err = 'Enter a valid mobile number (8 to 15 digits, optionally with a country code).';
    elseif ($emergencyPhone !== '' && !preg_match('/^\+?[0-9]{8,15}$/', $emergencyPhone)) $err = 'Enter a valid emergency contact mobile number.';

    if ($err) {
        flash('error', $err);
    } else {
        $studentNumberYear = $admissionYear !== '' ? $admissionYear : CURRENT_SY;
        $registeredStudentNo = null;
        try {
            $password = temporary_password();
            $pdo->beginTransaction();
            $studentNo = next_student_number($pdo, $studentNumberYear);

            $pdo->prepare('INSERT INTO users (username, email, mobile_phone, password_hash, must_change_password, role, department_id) VALUES (?, ?, ?, ?, 1, ?, NULL)')
                ->execute([$studentNo, $email, $contact, password_hash($password, PASSWORD_DEFAULT), 'student']);
            $uid = (int)$pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO students (user_id, student_no, first_name, last_name, middle_name, date_of_birth, gender, home_address,
                                       program, year_level, previous_school, admission_year, department_id, enrollment_status,
                                       contact_no, emergency_contact_name, emergency_contact_phone, registered_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $uid, $studentNo, $first, $last, $middle ?: null, $birthDate ?: null, $gender ?: null, $address ?: null,
                $program, $year, $previousSchool ?: null, $admissionYear ?: null, $dept, $status, $contact,
                $emergencyName ?: null, $emergencyPhone ?: null, (int)$user['id'],
            ]);
            $sid = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare('INSERT INTO clearances (student_id, clearance_type, department_id, school_year, semester) VALUES (?, ?, ?, ?, ?)');
            foreach ([['registrar', null], ['cashier', null], ['department', $dept]] as [$ct, $cd]) {
                $ins->execute([$sid, $ct, $cd, CURRENT_SY, CURRENT_SEM]);
            }
            $pdo->commit();

            audit_log('STUDENT_REGISTERED', (int)$user['id'], $user['username'], 'student', $sid, "{$studentNo} registered as {$status}");
            $delivery = credential_delivery($email, $contact, $studentNo, $password);
            if ($delivery['sms_sent']) {
                flash('success', "Student {$studentNo} was registered and credentials were sent by SMS.");
            } else {
                flash('error', "Student {$studentNo} was registered, but SMS delivery failed. Ask an Admin to verify the mobile number and TextBee configuration before resending credentials.");
            }
            $registeredStudentNo = $studentNo;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') flash('error', 'The email or generated student number is already in use. Please submit again.');
            else { error_log('[SSIS] registrar/admissions: ' . $e->getMessage()); flash('error', 'The student could not be registered.'); }
        } finally {
            release_student_number_lock($pdo, $studentNumberYear);
        }
        if ($registeredStudentNo !== null) {
            redirect('/registrar/students.php?new=' . urlencode($registeredStudentNo));
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
    <div class="tabs" data-tabs role="tablist" aria-label="Student information sections">
      <button type="button" role="tab" id="tab-personal" aria-controls="panel-personal" aria-selected="true">Personal info</button>
      <button type="button" role="tab" id="tab-academic" aria-controls="panel-academic" aria-selected="false">Academic history</button>
      <button type="button" role="tab" id="tab-contact" aria-controls="panel-contact" aria-selected="false">Contact & emergency</button>
    </div>
    <section class="tab-panel" id="panel-personal" role="tabpanel" aria-labelledby="tab-personal">
      <div class="form-grid form-grid-3">
        <div><label for="first_name">First name</label><input id="first_name" name="first_name" required maxlength="60"></div>
        <div><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" maxlength="60"></div>
        <div><label for="last_name">Last name</label><input id="last_name" name="last_name" required maxlength="60"></div>
        <div><label for="date_of_birth">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date"></div>
        <div><label for="gender">Gender (optional)</label><input id="gender" name="gender" maxlength="20"></div>
        <div><label for="home_address">Home address</label><input id="home_address" name="home_address" maxlength="255"></div>
      </div>
    </section>
    <section class="tab-panel" id="panel-academic" role="tabpanel" aria-labelledby="tab-academic" hidden>
      <div class="form-grid form-grid-3">
        <div><label for="program">Program</label><input id="program" name="program" required maxlength="100" placeholder="BS Information Technology"></div>
        <div><label for="year_level">Year level</label><input id="year_level" name="year_level" type="number" min="1" max="6" value="1" required></div>
        <div><label for="department_id">Department</label><select id="department_id" name="department_id" required><option value="">Select department</option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['code'] . ' - ' . $d['name']) ?></option><?php endforeach; ?></select></div>
        <div><label for="previous_school">Previous school</label><input id="previous_school" name="previous_school" maxlength="150"></div>
        <div><label for="admission_year">Admission school year</label><input id="admission_year" name="admission_year" pattern="\d{4}-\d{4}" maxlength="9" placeholder="2025-2026"><small class="field-help">The first year becomes the student ID prefix (for example, 2024-0002).</small></div>
        <div><label for="enrollment_status">Initial status</label><select id="enrollment_status" name="enrollment_status"><option value="active">Active</option><option value="pending">Pending verification</option><option value="archived">Archived</option></select></div>
      </div>
    </section>
    <section class="tab-panel" id="panel-contact" role="tabpanel" aria-labelledby="tab-contact" hidden>
      <div class="form-grid">
        <div><label for="email">Student email</label><input id="email" name="email" type="email" required maxlength="120"></div>
        <div><label for="contact_no">Mobile number</label><input id="contact_no" name="contact_no" type="tel" maxlength="20" required autocomplete="tel" placeholder="+639171234567"><small class="field-help">Used to deliver temporary credentials and password reset codes.</small></div>
        <div><label for="emergency_contact_name">Emergency contact name</label><input id="emergency_contact_name" name="emergency_contact_name" maxlength="120"></div>
        <div><label for="emergency_contact_phone">Emergency contact mobile</label><input id="emergency_contact_phone" name="emergency_contact_phone" type="tel" maxlength="20" placeholder="+639171234567"></div>
      </div>
    </section>
    <div class="form-actions"><button class="btn" type="submit">Register and send credentials</button><a class="btn alt" href="<?= e(url('/registrar/students.php')) ?>">View records</a></div>
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
