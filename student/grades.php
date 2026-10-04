<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('student');
$stu  = student_of($user);
$pdo  = db();

$terms = $pdo->prepare('SELECT DISTINCT school_year, semester FROM grades WHERE student_id = ? ORDER BY school_year DESC, semester');
$terms->execute([$stu['id']]);
$terms = $terms->fetchAll();

$sel = get_str('term', 20);                       // "2025-2026|1st"
[$sy, $sem] = array_pad(explode('|', $sel, 2), 2, '');
if (!$terms) { $sy = $sem = ''; }
elseif (!in_array(['school_year' => $sy, 'semester' => $sem], $terms, true)) { $sy = $terms[0]['school_year']; $sem = $terms[0]['semester']; }

$rows = [];
if ($sy !== '') {
    $st = $pdo->prepare(
        'SELECT sub.code, sub.title, sub.units, g.grade, g.remarks FROM grades g
           JOIN subjects sub ON sub.id = g.subject_id
          WHERE g.student_id = ? AND g.school_year = ? AND g.semester = ? ORDER BY sub.code'
    );
    $st->execute([$stu['id'], $sy, $sem]);
    $rows = $st->fetchAll();
}
$units = 0; $pts = 0.0;
foreach ($rows as $r) { if ($r['grade'] !== null) { $units += (int)$r['units']; $pts += (float)$r['grade'] * (int)$r['units']; } }

render_header($user, 'My grades');
if (!$terms): ?>
  <div class="card empty">No grades have been posted yet. They appear here once the Registrar encodes them.</div>
<?php else: ?>
  <form class="filters" method="get"><div><label for="term">Term</label><select id="term" name="term">
    <?php foreach ($terms as $t): $v = $t['school_year'] . '|' . $t['semester']; ?>
      <option value="<?= e($v) ?>"<?= $v === "$sy|$sem" ? ' selected' : '' ?>><?= e($t['school_year'] . ', ' . $t['semester']) ?></option>
    <?php endforeach; ?></select></div><button class="btn" type="submit">View</button></form>
  <div class="tablewrap"><table><thead><tr><th>Code</th><th>Subject</th><th class="num">Units</th><th class="num">Grade</th><th>Remarks</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e($r['code']) ?></td><td><?= e($r['title']) ?></td><td class="num"><?= (int)$r['units'] ?></td>
        <td class="num"><?= $r['grade'] === null ? '-' : e(number_format((float)$r['grade'], 2)) ?></td><td><?= badge($r['remarks']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p><strong>General weighted average:</strong> <?= $units ? e(number_format($pts / $units, 2)) : 'Not available yet' ?>
     <small>(graded subjects only; 1.00 is the highest)</small></p>
<?php endif;
render_footer();
