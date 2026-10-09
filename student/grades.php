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
       FROM enrollments e
       JOIN subject_offerings o ON o.id = e.subject_offering_id
      WHERE e.student_id = ? AND e.status = 'enrolled'
      ORDER BY o.academic_year DESC, FIELD(o.semester, '1st', '2nd', 'summer')"
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
                CASE WHEN g.status = 'approved' THEN g.computed_final ELSE NULL END AS computed_final,
                g.status AS grade_status
           FROM enrollments e
           JOIN subject_offerings o ON o.id = e.subject_offering_id
           JOIN subjects sub ON sub.id = o.subject_id
           JOIN professors p ON p.id = o.professor_id
           LEFT JOIN grades g ON g.enrollment_id = e.id
          WHERE e.student_id = ? AND e.status = 'enrolled'
            AND o.academic_year = ? AND o.semester = ?
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

render_header($user, 'My grades');
if (!$terms): ?>
  <div class="card empty">No enrolled subjects were found for any term.</div>
<?php else: ?>
  <section class="card grades-table-card">
  <form class="filters grades-term-filter" method="get"><div><label for="term">Term</label><select id="term" name="term">
    <?php foreach ($terms as $term): $value = $term['academic_year'] . '|' . $term['semester']; ?>
      <option value="<?= e($value) ?>"<?= $value === "$sy|$sem" ? ' selected' : '' ?>><?= e($term['academic_year'] . ', ' . ['1st' => '1st Semester', '2nd' => '2nd Semester', 'summer' => 'Summer Term'][$term['semester']]) ?></option>
    <?php endforeach; ?></select></div><button class="btn" type="submit">View</button></form>
  <div class="tablewrap table-responsive"><table class="table table-hover table-bordered align-middle"><thead><tr><th>Code</th><th>Subject</th><th>Section</th><th>Professor</th><th class="num">Units</th><th class="num">Approved grade</th></tr></thead><tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="6" class="text-center text-secondary py-4">No grade records were found for this term.</td></tr>
  <?php else: foreach ($rows as $row): ?>
    <tr><td><?= e($row['code']) ?></td><td><?= e($row['title']) ?></td><td><?= e($row['section']) ?></td>
      <td><?= e($row['professor']) ?></td><td class="num"><?= (int)$row['units'] ?></td>
      <td class="num"><?php if ($row['computed_final'] === null): ?><span class="badge secondary">Pending</span><?php else: $gradeValue = (float)$row['computed_final']; ?><span class="badge <?= $gradeValue <= 3.0 ? 'good' : 'bad' ?>"><?= e(number_format($gradeValue, 2)) ?> · <?= $gradeValue <= 3.0 ? 'Pass' : 'Failed' ?></span><?php endif; ?></td></tr>
  <?php endforeach; endif; ?></tbody></table></div>
  </section>
  <p><strong>General weighted average:</strong> <?= $units ? e(number_format($points / $units, 2)) : 'Not available yet' ?>
    <small>(approved and graded subjects only; 1.00 is the highest)</small></p>
  <section class="grade-legend-footer" aria-label="Grading scale legend">
    <div class="grade-legend-heading"><strong>Grading legend</strong><small>Confirm the applicable grading policy with your department.</small></div>
    <div class="grade-legend d-flex flex-wrap gap-2 align-items-center">
      <?php foreach (['1.00' => '98–100', '1.25' => '95–97', '1.50' => '92–94', '1.75' => '89–91', '2.00' => '86–88', '2.25' => '83–85', '2.50' => '80–82', '2.75' => '77–79', '3.00' => '75', '5.00' => 'Failed'] as $grade => $range): ?>
        <span class="grade-legend-pill"><strong><?= e($grade) ?></strong> = <?= e($range) ?></span>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif;
render_footer();
