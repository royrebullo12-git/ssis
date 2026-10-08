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
    $program = post_str('program', 100);
    $contact = post_str('contact_no', 20);
    $year = post_int('year_level');
    $dept = post_int('department_id');
    $status = post_str('enrollment_status', 12);
    $dok = $pdo->prepare('SELECT 1 FROM departments WHERE id = ?'); $dok->execute([$dept]);

    if (!$id || $first === '' || $last === '' || $program === '') flash('error', 'Name and program are required.');
    elseif ($year < 1 || $year > 6 || !$dok->fetchColumn()) flash('error', 'Enter a valid year level and department.');
    elseif (!in_array($status, STUDENT_STATUSES, true)) flash('error', 'Invalid student status.');
    elseif ($contact !== '' && !preg_match('/^[0-9+\- ]{7,20}$/', $contact)) flash('error', 'Contact number may only contain digits, +, - and spaces.');
    else {
        $u = $pdo->prepare('UPDATE students SET first_name=?, last_name=?, program=?, contact_no=?, year_level=?, department_id=?, enrollment_status=? WHERE id=?');
        $u->execute([$first, $last, $program, $contact ?: null, $year, $dept, $status, $id]);
        audit_log('STUDENT_UPDATED', (int)$user['id'], $user['username'], 'student', $id, "Status: {$status}");
        flash('success', 'Student record saved.');
    }
    redirect_self();
}

$q = get_str('q', 60);
$status = get_str('status', 12);
if (!in_array($status, STUDENT_STATUSES, true)) $status = '';
$like = '%' . addcslashes($q, '%_\\') . '%';
$st = $pdo->prepare('SELECT s.*, d.code FROM students s JOIN departments d ON d.id=s.department_id
                     WHERE (?="" OR s.student_no LIKE ? OR s.last_name LIKE ? OR s.first_name LIKE ?)
                     AND (?="" OR s.enrollment_status=?) ORDER BY s.last_name,s.first_name LIMIT 200');
$st->execute([$q,$like,$like,$like,$status,$status]);
$rows = $st->fetchAll();
$depts = $pdo->query('SELECT id, code, name FROM departments ORDER BY code')->fetchAll();

$edit = null;
if (get_int('edit')) {
    $e = $pdo->prepare('SELECT * FROM students WHERE id=?'); $e->execute([get_int('edit')]); $edit = $e->fetch() ?: null;
}
render_header($user, 'Student records');
?>
<?php if (get_str('new', 20)): ?><div class="msg success" role="status">Student <?= e(get_str('new',20)) ?> was added by Registrar Admissions.</div><?php endif; ?>

<?php if ($edit): ?>
<div class="card">
  <div class="section-title"><div><p class="card-kicker">RECORD MANAGEMENT</p><h2>Edit <?= e($edit['student_no']) ?></h2></div><?= badge($edit['enrollment_status']) ?></div>
  <?= form_open() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <div class="row">
    <div><label for="first_name">First name</label><input id="first_name" name="first_name" required maxlength="60" value="<?= e($edit['first_name']) ?>"></div>
    <div><label for="last_name">Last name</label><input id="last_name" name="last_name" required maxlength="60" value="<?= e($edit['last_name']) ?>"></div>
    <div><label for="program">Program</label><input id="program" name="program" required maxlength="100" value="<?= e($edit['program']) ?>"></div>
    <div><label for="year_level">Year level</label><input id="year_level" name="year_level" type="number" min="1" max="6" required value="<?= (int)$edit['year_level'] ?>"></div>
    <div><label for="department_id">Department</label><select id="department_id" name="department_id"><?php foreach($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)$d['id']===(int)$edit['department_id']?' selected':'' ?>><?= e($d['code'].' - '.$d['name']) ?></option><?php endforeach; ?></select></div>
    <div><label for="contact_no">Contact number</label><input id="contact_no" name="contact_no" maxlength="20" value="<?= e($edit['contact_no'] ?? '') ?>"></div>
    <div><label for="enrollment_status">Status</label><select id="enrollment_status" name="enrollment_status"><?php foreach(STUDENT_STATUSES as $s): ?><option value="<?= $s ?>"<?= $s===$edit['enrollment_status']?' selected':'' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="form-actions"><button class="btn" type="submit">Save changes</button><a class="btn alt" href="<?= e(url('/registrar/students.php')) ?>">Cancel</a></div>
  </form>
</div>
<?php endif; ?>

<div class="toolbar"><form class="filters" method="get">
  <div><label for="q">Search students</label><input id="q" name="q" value="<?= e($q) ?>" placeholder="Student no. or name"></div>
  <div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><?php foreach(STUDENT_STATUSES as $s): ?><option value="<?= $s ?>"<?= $s===$status?' selected':'' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
  <button class="btn" type="submit">Filter</button></form>
  <a class="btn" href="<?= e(url('/registrar/admissions.php')) ?>">+ Register student</a>
</div>

<div class="tablewrap"><table><thead><tr><th>Student</th><th>Program</th><th>Department</th><th>Status</th><th>Registered</th><th>Action</th></tr></thead><tbody>
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
