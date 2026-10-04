<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo  = db();

const REMARKS = ['passed', 'failed', 'incomplete', 'dropped', 'pending'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $sid = post_int('student_id'); $sub = post_int('subject_id');
    $sy = post_str('school_year', 9); $sem = post_str('semester', 6);
    $raw = post_str('grade', 5);
    $remarks = post_str('remarks', 12);
    $grade = $raw === '' ? null : (is_numeric($raw) ? round((float)$raw, 2) : false);

    $ok1 = $pdo->prepare('SELECT 1 FROM students WHERE id = ?'); $ok1->execute([$sid]);
    $ok2 = $pdo->prepare('SELECT 1 FROM subjects WHERE id = ?'); $ok2->execute([$sub]);

    if (!$ok1->fetchColumn() || !$ok2->fetchColumn()) flash('error', 'Choose a valid student and subject.');
    elseif (!preg_match('/^\d{4}-\d{4}$/', $sy) || !in_array($sem, ['1st', '2nd', 'summer'], true)) flash('error', 'Enter the school year as 2025-2026 and pick a semester.');
    elseif ($grade === false || ($grade !== null && ($grade < 1.00 || $grade > 5.00))) flash('error', 'Grade must be between 1.00 and 5.00, or blank if not yet graded.');
    else {
        // A numeric grade decides pass/fail; incomplete/dropped may be chosen by hand only when no grade is given.
        $final = $grade !== null ? remarks_from_grade($grade) : (in_array($remarks, ['incomplete', 'dropped'], true) ? $remarks : 'pending');
        $pdo->prepare(
            'INSERT INTO grades (student_id, subject_id, school_year, semester, grade, remarks, encoded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE grade = VALUES(grade), remarks = VALUES(remarks), encoded_by = VALUES(encoded_by)'
        )->execute([$sid, $sub, $sy, $sem, $grade, $final, (int)$user['id']]);
        audit_log('GRADE_ENCODED', (int)$user['id'], $user['username'], 'student', $sid, "subject {$sub} {$sy} {$sem} = " . ($grade ?? 'none'));
        flash('success', 'Grade saved.');
    }
    redirect_self();
}

$students = $pdo->query('SELECT id, student_no, last_name, first_name FROM students ORDER BY last_name, first_name')->fetchAll();
$subjects = $pdo->query('SELECT id, code, title FROM subjects ORDER BY code')->fetchAll();
$view = get_int('student');
$pre  = ['subject' => get_int('subject'), 'sy' => get_str('sy', 9) ?: CURRENT_SY, 'sem' => get_str('sem', 6) ?: CURRENT_SEM, 'grade' => get_str('grade', 5)];

$rows = [];
if ($view) {
    $st = $pdo->prepare('SELECT g.*, sub.code, sub.title FROM grades g JOIN subjects sub ON sub.id = g.subject_id
                          WHERE g.student_id = ? ORDER BY g.school_year DESC, g.semester, sub.code');
    $st->execute([$view]); $rows = $st->fetchAll();
}

render_header($user, 'Grade entry');
?>
<div class="card"><h2>Encode or correct a grade</h2>
  <?= form_open() ?>
  <div class="row">
    <div><label for="student_id">Student</label><select id="student_id" name="student_id" required>
      <?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $view ? ' selected' : '' ?>><?= e($s['student_no'] . ' - ' . $s['last_name'] . ', ' . $s['first_name']) ?></option><?php endforeach; ?></select></div>
    <div><label for="subject_id">Subject</label><select id="subject_id" name="subject_id" required>
      <?php foreach ($subjects as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $pre['subject'] ? ' selected' : '' ?>><?= e($s['code'] . ' - ' . $s['title']) ?></option><?php endforeach; ?></select></div>
    <div><label for="school_year">School year</label><input id="school_year" name="school_year" type="text" pattern="\d{4}-\d{4}" maxlength="9" required value="<?= e($pre['sy']) ?>"></div>
    <div><label for="semester">Semester</label><select id="semester" name="semester">
      <?php foreach (['1st', '2nd', 'summer'] as $s): ?><option value="<?= $s ?>"<?= $s === $pre['sem'] ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div><label for="grade">Grade (1.00 to 5.00)</label><input id="grade" name="grade" type="number" step="0.25" min="1" max="5" value="<?= e($pre['grade']) ?>"></div>
    <div><label for="remarks">If no grade</label><select id="remarks" name="remarks">
      <option value="pending">Pending</option><option value="incomplete">Incomplete</option><option value="dropped">Dropped</option></select></div>
  </div>
  <p><button class="btn" type="submit">Save grade</button> <small>Saving again for the same student, subject and term replaces the grade.</small></p></form>
</div>
<form class="filters" method="get"><div><label for="v">View grades of</label><select id="v" name="student">
  <option value="0">Choose a student</option>
  <?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $view ? ' selected' : '' ?>><?= e($s['student_no'] . ' - ' . $s['last_name']) ?></option><?php endforeach; ?></select></div>
  <button class="btn" type="submit">Show</button></form>
<?php if ($view && !$rows): ?><div class="card empty">No grades encoded for this student yet.</div><?php elseif ($rows): ?>
<div class="tablewrap"><table><thead><tr><th>Term</th><th>Subject</th><th class="num">Grade</th><th>Remarks</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['school_year'] . ', ' . $r['semester']) ?></td><td><?= e($r['code'] . ' - ' . $r['title']) ?></td>
      <td class="num"><?= $r['grade'] === null ? '-' : e(number_format((float)$r['grade'], 2)) ?></td><td><?= badge($r['remarks']) ?></td>
      <td><a class="btn alt sm" href="?student=<?= $view ?>&subject=<?= (int)$r['subject_id'] ?>&sy=<?= e($r['school_year']) ?>&sem=<?= e($r['semester']) ?>&grade=<?= e((string)($r['grade'] ?? '')) ?>">Edit</a></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; render_footer();
