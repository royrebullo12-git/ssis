<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('student');
$stu = student_of($user);
$pdo = db();

$terms = $pdo->prepare(
    "SELECT DISTINCT o.academic_year, o.semester
       FROM grades g
       JOIN enrollments e ON e.id = g.enrollment_id
       JOIN subject_offerings o ON o.id = e.subject_offering_id
      WHERE e.student_id = ? AND e.status = 'enrolled' AND g.status = 'approved'
      ORDER BY o.academic_year DESC, o.semester"
);
$terms->execute([(int)$stu['id']]);
$terms = $terms->fetchAll();

$sel = get_str('term', 20);
[$sy, $sem] = array_pad(explode('|', $sel, 2), 2, '');
if (!$terms) {
    $sy = $sem = '';
} elseif (!in_array(['academic_year' => $sy, 'semester' => $sem], $terms, true)) {
    $sy = $terms[0]['academic_year'];
    $sem = $terms[0]['semester'];
}

$rows = [];
if ($sy !== '') {
    $st = $pdo->prepare(
        "SELECT sub.code, sub.title, sub.units, o.section,
                CONCAT(p.first_name, ' ', p.last_name) AS professor,
                g.computed_final
           FROM grades g
           JOIN enrollments e ON e.id = g.enrollment_id
           JOIN subject_offerings o ON o.id = e.subject_offering_id
           JOIN subjects sub ON sub.id = o.subject_id
           JOIN professors p ON p.id = o.professor_id
          WHERE e.student_id = ? AND e.status = 'enrolled'
            AND g.status = 'approved' AND o.academic_year = ? AND o.semester = ?
          ORDER BY sub.code"
    );
    $st->execute([(int)$stu['id'], $sy, $sem]);
    $rows = $st->fetchAll();
}
$units = 0;
$points = 0.0;
foreach ($rows as $row) {
    if ($row['computed_final'] !== null) {
        $units += (int)$row['units'];
        $points += (float)$row['computed_final'] * (int)$row['units'];
    }
}

render_header($user, 'My approved grades');
if (!$terms): ?>
  <div class="card empty">No approved grades have been posted yet. Draft and submitted grades are not part of your official record.</div>
<?php else: ?>
  <form class="filters" method="get"><div><label for="term">Term</label><select id="term" name="term">
    <?php foreach ($terms as $term): $value = $term['academic_year'] . '|' . $term['semester']; ?>
      <option value="<?= e($value) ?>"<?= $value === "$sy|$sem" ? ' selected' : '' ?>><?= e($term['academic_year'] . ', ' . $term['semester']) ?></option>
    <?php endforeach; ?></select></div><button class="btn" type="submit">View</button></form>
  <div class="tablewrap"><table><thead><tr><th>Code</th><th>Subject</th><th>Section</th><th>Professor</th><th class="num">Units</th><th class="num">Approved grade</th></tr></thead><tbody>
  <?php foreach ($rows as $row): ?>
    <tr><td><?= e($row['code']) ?></td><td><?= e($row['title']) ?></td><td><?= e($row['section']) ?></td>
      <td><?= e($row['professor']) ?></td><td class="num"><?= (int)$row['units'] ?></td>
      <td class="num"><?= $row['computed_final'] === null ? '-' : e(number_format((float)$row['computed_final'], 2)) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p><strong>General weighted average:</strong> <?= $units ? e(number_format($points / $units, 2)) : 'Not available yet' ?>
    <small>(approved and graded subjects only; 1.00 is the highest)</small></p>
<?php endif;
render_footer();
