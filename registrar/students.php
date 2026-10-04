<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo  = db();

const ENROLL = ['not_enrolled', 'queued', 'for_assessment', 'for_payment', 'enrolled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $id = post_int('id');
    $f = ['first_name' => post_str('first_name', 60), 'last_name' => post_str('last_name', 60), 'program' => post_str('program', 100),
          'contact_no' => post_str('contact_no', 20)];
    $year = post_int('year_level'); $dept = post_int('department_id'); $enr = post_str('enrollment_status', 20);
    $queue = post_str('queue_number', 6) === '' ? null : post_int('queue_number');
    $dok = $pdo->prepare('SELECT 1 FROM departments WHERE id = ?'); $dok->execute([$dept]);

    if ($f['first_name'] === '' || $f['last_name'] === '' || $f['program'] === '') flash('error', 'Name and program are required.');
    elseif ($year < 1 || $year > 6) flash('error', 'Year level must be 1 to 6.');
    elseif (!in_array($enr, ENROLL, true) || !$dok->fetchColumn()) flash('error', 'Invalid enrollment status or department.');
    elseif ($f['contact_no'] !== '' && !preg_match('/^[0-9+\- ]{7,20}$/', $f['contact_no'])) flash('error', 'Contact number may only contain digits, +, - and spaces.');
    else {
        $u = $pdo->prepare('UPDATE students SET first_name=?, last_name=?, program=?, contact_no=?, year_level=?, department_id=?, enrollment_status=?, queue_number=? WHERE id=?');
        $u->execute([$f['first_name'], $f['last_name'], $f['program'], $f['contact_no'] ?: null, $year, $dept, $enr, $queue, $id]);
        audit_log('STUDENT_UPDATED', (int)$user['id'], $user['username'], 'student', $id, "Enrollment: {$enr}");
        flash('success', 'Student record saved.');
    }
    redirect_self();
}

$q = get_str('q', 60);
$like = '%' . addcslashes($q, '%_\\') . '%';
$st = $pdo->prepare('SELECT s.*, d.code FROM students s JOIN departments d ON d.id = s.department_id
                      WHERE (? = "" OR s.student_no LIKE ? OR s.last_name LIKE ? OR s.first_name LIKE ?) ORDER BY s.last_name, s.first_name LIMIT 100');
$st->execute([$q, $like, $like, $like]);
$rows = $st->fetchAll();
$depts = $pdo->query('SELECT id, code, name FROM departments ORDER BY code')->fetchAll();

$edit = null;
if (get_int('edit')) {
    $e = $pdo->prepare('SELECT * FROM students WHERE id = ?'); $e->execute([get_int('edit')]); $edit = $e->fetch() ?: null;
}

render_header($user, 'Student records');
if ($edit): ?>
<div class="card"><h2>Edit <?= e($edit['student_no']) ?></h2>
  <?= form_open() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <div class="row">
    <div><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" maxlength="60" required value="<?= e($edit['first_name']) ?>"></div>
    <div><label for="last_name">Last name</label><input id="last_name" name="last_name" type="text" maxlength="60" required value="<?= e($edit['last_name']) ?>"></div>
    <div><label for="program">Program</label><input id="program" name="program" type="text" maxlength="100" required value="<?= e($edit['program']) ?>"></div>
    <div><label for="year_level">Year level</label><input id="year_level" name="year_level" type="number" min="1" max="6" required value="<?= (int)$edit['year_level'] ?>"></div>
    <div><label for="department_id">Department</label><select id="department_id" name="department_id">
      <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)$d['id'] === (int)$edit['department_id'] ? ' selected' : '' ?>><?= e($d['code'] . ' - ' . $d['name']) ?></option><?php endforeach; ?></select></div>
    <div><label for="contact_no">Contact no.</label><input id="contact_no" name="contact_no" type="text" maxlength="20" value="<?= e($edit['contact_no'] ?? '') ?>"></div>
    <div><label for="enrollment_status">Enrollment status</label><select id="enrollment_status" name="enrollment_status">
      <?php foreach (ENROLL as $s): ?><option value="<?= $s ?>"<?= $s === $edit['enrollment_status'] ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
    <div><label for="queue_number">Queue number</label><input id="queue_number" name="queue_number" type="number" min="1" value="<?= e((string)($edit['queue_number'] ?? '')) ?>"></div>
  </div>
  <p class="actions"><button class="btn" type="submit">Save changes</button><a class="btn alt" href="<?= e(url('/registrar/students.php')) ?>">Cancel</a></p></form>
  <p class="muted">Student numbers and accounts are managed by the Admin office.</p>
</div>
<?php endif; ?>
<form class="filters" method="get"><div><label for="q">Search</label><input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="Student no. or name"></div><button class="btn" type="submit">Search</button></form>
<?php if (!$rows): ?><div class="card empty">No students found.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Student no.</th><th>Name</th><th>Program</th><th>Year</th><th>Dept.</th><th>Enrollment</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['student_no']) ?></td><td><?= e($r['last_name'] . ', ' . $r['first_name']) ?></td><td><?= e($r['program']) ?></td><td><?= (int)$r['year_level'] ?></td>
      <td><?= e($r['code']) ?></td><td><?= badge($r['enrollment_status']) ?><?= $r['queue_number'] ? ' <small>#' . (int)$r['queue_number'] . '</small>' : '' ?></td>
      <td><a class="btn alt sm" href="?edit=<?= (int)$r['id'] ?>">Edit</a></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; render_footer();
