<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('department');
$departmentId = (int)($user['department_id'] ?? 0);
if ($departmentId < 1) { http_response_code(403); exit('Your account is not linked to a department.'); }

$offeringId = get_int('offering');
$offerings = db()->prepare(
    'SELECT o.id, o.academic_year, o.semester, o.section, s.code, s.title
       FROM subject_offerings o JOIN subjects s ON s.id = o.subject_id
      WHERE s.department_id = ? ORDER BY o.academic_year DESC, o.semester, s.code, o.section'
);
$offerings->execute([$departmentId]);
$offerings = $offerings->fetchAll();
$rows = [];
if ($offeringId) {
    $st = db()->prepare(
        'SELECT e.status AS enrollment_status, s.student_no, s.first_name, s.last_name, s.program,
                g.status AS grade_status
           FROM subject_offerings o
           JOIN subjects sub ON sub.id = o.subject_id
           JOIN enrollments e ON e.subject_offering_id = o.id
           JOIN students s ON s.id = e.student_id
           LEFT JOIN grades g ON g.enrollment_id = e.id
          WHERE o.id = ? AND sub.department_id = ?
          ORDER BY s.last_name, s.first_name'
    );
    $st->execute([$offeringId, $departmentId]);
    $rows = $st->fetchAll();
}

render_header($user, 'Department class lists');
?>
<form class="filters" method="get"><div><label for="offering">Subject offering</label><select id="offering" name="offering" required>
  <option value="">Choose an offering</option>
  <?php foreach ($offerings as $offering): ?><option value="<?= (int)$offering['id'] ?>"<?= (int)$offering['id'] === $offeringId ? ' selected' : '' ?>><?= e($offering['code'] . ' - ' . $offering['title'] . ' / ' . $offering['academic_year'] . ' ' . $offering['semester'] . ' / ' . $offering['section']) ?></option><?php endforeach; ?>
</select></div><button class="btn" type="submit">View class</button></form>
<?php if ($offeringId && !$rows): ?><div class="card empty">No enrolled students in this offering.</div>
<?php elseif ($rows): ?><div class="card"><h2>Enrolled students</h2><label for="class-filter">Search students</label><input id="class-filter" type="text" data-filter="#department-class-list" placeholder="Student number, name, or program">
  <div class="tablewrap table-responsive"><table id="department-class-list"><thead><tr><th>Student number</th><th>Student</th><th>Program</th><th>Enrollment</th><th>Grade status</th></tr></thead><tbody>
  <?php foreach ($rows as $row): ?>
    <tr><td><?= e($row['student_no']) ?></td><td><?= e($row['last_name'] . ', ' . $row['first_name']) ?></td><td><?= e($row['program']) ?></td>
      <td><?= badge($row['enrollment_status']) ?></td><td><?= $row['grade_status'] ? badge($row['grade_status']) : '-' ?></td></tr>
  <?php endforeach; ?>
</tbody></table></div></div><?php endif; ?>
<?php render_footer();
