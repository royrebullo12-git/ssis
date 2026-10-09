<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo = db();

const STUDENT_STATUSES = ['pending', 'active', 'archived'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $id = post_int('id');
    $first = post_str('first_name', 60);
    $last = post_str('last_name', 60);
    $middle = post_str('middle_name', 60);
    $birthDate = post_str('date_of_birth', 10);
    $gender = post_str('gender', 20);
    $address = post_str('home_address', 255);
    $program = post_str('program', 100);
    $contact = preg_replace('/[\s()-]/', '', post_str('contact_no', 20)) ?? '';
    $email = post_str('email', 120);
    $previousSchool = post_str('previous_school', 150);
    $admissionYear = post_str('admission_year', 9);
    $emergencyName = post_str('emergency_contact_name', 120);
    $emergencyPhone = preg_replace('/[\s()-]/', '', post_str('emergency_contact_phone', 20)) ?? '';
    $year = post_int('year_level');
    $dept = post_int('department_id');
    $status = post_str('enrollment_status', 12);
    $dok = $pdo->prepare('SELECT 1 FROM departments WHERE id = ?'); $dok->execute([$dept]);

    if (!$id || $first === '' || $last === '' || $program === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('error', 'Name, program, and a valid email address are required.');
    elseif ($year < 1 || $year > 6 || !$dok->fetchColumn()) flash('error', 'Enter a valid year level and department.');
    elseif (!in_array($status, STUDENT_STATUSES, true)) flash('error', 'Invalid student status.');
    elseif (!preg_match('/^\+?[0-9]{8,15}$/', $contact)) flash('error', 'Enter a valid student mobile number.');
    elseif ($emergencyPhone !== '' && !preg_match('/^\+?[0-9]{8,15}$/', $emergencyPhone)) flash('error', 'Enter a valid emergency contact mobile number.');
    elseif ($birthDate !== '' && (!DateTime::createFromFormat('!Y-m-d', $birthDate) || DateTime::createFromFormat('!Y-m-d', $birthDate)->format('Y-m-d') !== $birthDate)) flash('error', 'Enter a valid date of birth.');
    elseif ($admissionYear !== '' && !preg_match('/^\d{4}-\d{4}$/', $admissionYear)) flash('error', 'Admission school year must use YYYY-YYYY.');
    else {
        try {
            $studentUser = $pdo->prepare('SELECT user_id FROM students WHERE id = ?');
            $studentUser->execute([$id]);
            $studentUserId = (int)$studentUser->fetchColumn();
            if ($studentUserId < 1) {
                flash('error', 'Student record not found.');
            } else {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'UPDATE students SET first_name=?, last_name=?, middle_name=?, date_of_birth=?, gender=?, home_address=?,
                         program=?, year_level=?, previous_school=?, admission_year=?, department_id=?, enrollment_status=?,
                         contact_no=?, emergency_contact_name=?, emergency_contact_phone=? WHERE id=?'
                )->execute([
                    $first, $last, $middle ?: null, $birthDate ?: null, $gender ?: null, $address ?: null,
                    $program, $year, $previousSchool ?: null, $admissionYear ?: null, $dept, $status, $contact,
                    $emergencyName ?: null, $emergencyPhone ?: null, $id,
                ]);
                $pdo->prepare('UPDATE users SET email = ?, mobile_phone = ? WHERE id = ?')
                    ->execute([$email, preg_replace('/[\s()-]/', '', $contact), $studentUserId]);
                $pdo->commit();
                audit_log('STUDENT_UPDATED', (int)$user['id'], $user['username'], 'student', $id, "Status: {$status}");
                flash('success', 'Student record saved.');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') flash('error', 'That email address is already linked to another account.');
            else { error_log('[WLS] registrar student update failed: ' . $e->getMessage()); flash('error', 'Student record could not be saved.'); }
        }
    }
    redirect_self();
}

$depts = $pdo->query('SELECT id, code, name FROM departments ORDER BY code')->fetchAll();
$terms = $pdo->query("SELECT DISTINCT academic_year, semester FROM subject_offerings ORDER BY academic_year DESC, FIELD(semester, '1st', '2nd', 'summer')")->fetchAll();
$readMulti = static function (string $key): array {
    $value = $_GET[$key] ?? [];
    if (is_string($value) && $value !== '') $value = [$value];
    if (!is_array($value)) return [];
    return array_values(array_unique(array_filter($value, static fn($item): bool => is_string($item))));
};
$q = get_str('q', 60);
$selectedStatuses = array_values(array_intersect($readMulti('status'), STUDENT_STATUSES));
$validDeptIds = array_map(static fn(array $department): string => (string)$department['id'], $depts);
$selectedDepartments = array_values(array_intersect($readMulti('department'), $validDeptIds));
$validYears = array_values(array_unique(array_column($terms, 'academic_year')));
$selectedYears = array_values(array_intersect($readMulti('academic_year'), $validYears));
$validSemesters = ['1st', '2nd', 'summer'];
$selectedSemesters = array_values(array_intersect($readMulti('semester'), $validSemesters));

$conditions = [];
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $conditions[] = '(s.student_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.program LIKE ? OR u.email LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($selectedStatuses) {
    $conditions[] = 's.enrollment_status IN (' . implode(',', array_fill(0, count($selectedStatuses), '?')) . ')';
    array_push($params, ...$selectedStatuses);
}
if ($selectedDepartments) {
    $conditions[] = 's.department_id IN (' . implode(',', array_fill(0, count($selectedDepartments), '?')) . ')';
    array_push($params, ...array_map('intval', $selectedDepartments));
}
if ($selectedYears || $selectedSemesters) {
    $termConditions = ["e.status = 'enrolled'"];
    if ($selectedYears) {
        $termConditions[] = 'o.academic_year IN (' . implode(',', array_fill(0, count($selectedYears), '?')) . ')';
        array_push($params, ...$selectedYears);
    }
    if ($selectedSemesters) {
        $termConditions[] = 'o.semester IN (' . implode(',', array_fill(0, count($selectedSemesters), '?')) . ')';
        array_push($params, ...$selectedSemesters);
    }
    $conditions[] = 'EXISTS (SELECT 1 FROM enrollments e JOIN subject_offerings o ON o.id = e.subject_offering_id WHERE e.student_id = s.id AND ' . implode(' AND ', $termConditions) . ')';
}
$query = 'SELECT s.*, d.code FROM students s JOIN departments d ON d.id = s.department_id JOIN users u ON u.id = s.user_id';
if ($conditions) $query .= ' WHERE ' . implode(' AND ', $conditions);
$query .= ' ORDER BY s.last_name, s.first_name LIMIT 1000';
$statement = $pdo->prepare($query);
$statement->execute($params);
$rows = $statement->fetchAll();

$edit = null;
if (get_int('edit')) {
    $e = $pdo->prepare('SELECT s.*, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.id=?');
    $e->execute([get_int('edit')]); $edit = $e->fetch() ?: null;
}
render_header($user, 'Student records');
?>
<?php if (get_str('new', 20)): ?><div class="msg success" role="status">Student <?= e(get_str('new',20)) ?> was added by Registrar Admissions.</div><?php endif; ?>

<?php if ($edit): ?>
<div class="card">
  <div class="section-title"><div><p class="card-kicker">RECORD MANAGEMENT</p><h2>Edit <?= e($edit['student_no']) ?></h2></div><?= badge($edit['enrollment_status']) ?></div>
  <?= form_open() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <div class="tabs" data-tabs role="tablist" aria-label="Student information sections">
    <button type="button" role="tab" id="tab-personal" aria-controls="panel-personal" aria-selected="true">Personal info</button>
    <button type="button" role="tab" id="tab-academic" aria-controls="panel-academic" aria-selected="false">Academic history</button>
    <button type="button" role="tab" id="tab-contact" aria-controls="panel-contact" aria-selected="false">Contact & emergency</button>
  </div>
  <section class="tab-panel" id="panel-personal" role="tabpanel" aria-labelledby="tab-personal">
    <div class="form-grid form-grid-3">
      <div><label for="first_name">First name</label><input id="first_name" name="first_name" required maxlength="60" value="<?= e($edit['first_name']) ?>"></div>
      <div><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" maxlength="60" value="<?= e($edit['middle_name'] ?? '') ?>"></div>
      <div><label for="last_name">Last name</label><input id="last_name" name="last_name" required maxlength="60" value="<?= e($edit['last_name']) ?>"></div>
      <div><label for="date_of_birth">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date" value="<?= e($edit['date_of_birth'] ?? '') ?>"></div>
      <div><label for="gender">Gender (optional)</label><input id="gender" name="gender" maxlength="20" value="<?= e($edit['gender'] ?? '') ?>"></div>
      <div><label for="home_address">Home address</label><input id="home_address" name="home_address" maxlength="255" value="<?= e($edit['home_address'] ?? '') ?>"></div>
    </div>
  </section>
  <section class="tab-panel" id="panel-academic" role="tabpanel" aria-labelledby="tab-academic" hidden>
    <div class="form-grid form-grid-3">
      <div><label for="program">Program</label><input id="program" name="program" required maxlength="100" value="<?= e($edit['program']) ?>"></div>
      <div><label for="year_level">Year level</label><input id="year_level" name="year_level" type="number" min="1" max="6" required value="<?= (int)$edit['year_level'] ?>"></div>
      <div><label for="department_id">Department</label><select id="department_id" name="department_id" required><?php foreach($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)$d['id']===(int)$edit['department_id']?' selected':'' ?>><?= e($d['code'].' - '.$d['name']) ?></option><?php endforeach; ?></select></div>
      <div><label for="previous_school">Previous school</label><input id="previous_school" name="previous_school" maxlength="150" value="<?= e($edit['previous_school'] ?? '') ?>"></div>
      <div><label for="admission_year">Admission school year</label><input id="admission_year" name="admission_year" pattern="\d{4}-\d{4}" maxlength="9" value="<?= e($edit['admission_year'] ?? '') ?>"></div>
      <div><label for="enrollment_status">Status</label><select id="enrollment_status" name="enrollment_status"><?php foreach(STUDENT_STATUSES as $s): ?><option value="<?= e($s) ?>"<?= $s===$edit['enrollment_status']?' selected':'' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
    </div>
  </section>
  <section class="tab-panel" id="panel-contact" role="tabpanel" aria-labelledby="tab-contact" hidden>
    <div class="form-grid">
      <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120" required value="<?= e($edit['email']) ?>"></div>
      <div><label for="contact_no">Mobile number</label><input id="contact_no" name="contact_no" type="tel" maxlength="20" required value="<?= e($edit['contact_no'] ?? '') ?>"></div>
      <div><label for="emergency_contact_name">Emergency contact name</label><input id="emergency_contact_name" name="emergency_contact_name" maxlength="120" value="<?= e($edit['emergency_contact_name'] ?? '') ?>"></div>
      <div><label for="emergency_contact_phone">Emergency contact mobile</label><input id="emergency_contact_phone" name="emergency_contact_phone" type="tel" maxlength="20" value="<?= e($edit['emergency_contact_phone'] ?? '') ?>"></div>
    </div>
  </section>
  <div class="form-actions"><button class="btn" type="submit">Save changes</button><a class="btn alt" href="<?= e(url('/registrar/students.php')) ?>">Cancel</a></div>
  </form>
</div>
<?php endif; ?>

<form class="filters card students-filters" method="get">
  <div class="student-search-field"><label for="q">Search students</label><input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="ID, name, program, or email"></div>
  <div><label for="academic_year">Academic year</label><select id="academic_year" name="academic_year"><option value="">All years</option><?php foreach($validYears as $year): ?><option value="<?= e($year) ?>"<?= in_array($year,$selectedYears,true)?' selected':'' ?>><?= e($year) ?></option><?php endforeach; ?></select></div>
  <div><label for="semester">Term / semester</label><select id="semester" name="semester"><option value="">All terms</option><?php foreach($validSemesters as $semester): ?><option value="<?= e($semester) ?>"<?= in_array($semester,$selectedSemesters,true)?' selected':'' ?>><?= e(label($semester)) ?></option><?php endforeach; ?></select></div>
  <div><label for="department">Department</label><select id="department" name="department"><option value="">All departments</option><?php foreach($depts as $department): ?><option value="<?= (int)$department['id'] ?>"<?= in_array((string)$department['id'],$selectedDepartments,true)?' selected':'' ?>><?= e($department['code']) ?></option><?php endforeach; ?></select></div>
  <div><label for="status">Enrollment status</label><select id="status" name="status"><option value="">All statuses</option><?php foreach(STUDENT_STATUSES as $s): ?><option value="<?= e($s) ?>"<?= in_array($s,$selectedStatuses,true)?' selected':'' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
  <div class="filter-actions student-filter-actions"><button class="btn" type="submit">Apply filters</button><a class="btn alt" href="<?= e(url('/registrar/students.php')) ?>">Clear</a><a class="btn gold" href="<?= e(url('/registrar/admissions.php')) ?>">+ Register student</a></div>
</form>

<div class="tablewrap table-responsive"><table><thead><tr><th>Student</th><th>Program</th><th>Department</th><th>Status</th><th>Registered</th><th>Action</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
  <td><strong><?= e($r['student_no']) ?></strong><br><small><?= e($r['last_name'].', '.$r['first_name']) ?></small></td>
  <td><?= e($r['program']) ?><br><small>Year <?= (int)$r['year_level'] ?></small></td>
  <td><?= e($r['code']) ?></td>
  <td><?= badge($r['enrollment_status']) ?></td>
  <td><?= e(fmt_date($r['created_at'])) ?></td>
  <td><a class="btn alt sm" href="<?= e(url('/registrar/students.php?edit='.(int)$r['id'])) ?>">Edit</a></td>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="empty">No student records match your filters.</td></tr><?php endif; ?>
</tbody></table></div>
<?php render_footer();
