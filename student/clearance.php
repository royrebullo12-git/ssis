<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('student');
$stu  = student_of($user);
$pdo  = db();

$st = $pdo->prepare(
    "SELECT c.clearance_type, c.status, c.remarks, c.reviewed_at, c.school_year, c.semester, d.name AS dept
       FROM clearances c LEFT JOIN departments d ON d.id = c.department_id
      WHERE c.student_id = ? ORDER BY c.school_year DESC, c.semester, FIELD(c.clearance_type,'registrar','cashier','department')"
);
$st->execute([$stu['id']]);
$all = $st->fetchAll();
$cur = array_values(array_filter($all, fn($c) => $c['school_year'] === CURRENT_SY && $c['semester'] === CURRENT_SEM));
$done = count(array_filter($cur, fn($c) => $c['status'] === 'approved'));
$office = fn(array $c) => $c['clearance_type'] === 'department' ? ($c['dept'] ?? 'Department') : label($c['clearance_type']);

render_header($user, 'Clearance status');
?>
<div class="card">
  <p><strong><?= e(CURRENT_SY) ?>, <?= e(CURRENT_SEM) ?> semester:</strong> <?= $done ?> of <?= count($cur) ?> offices cleared</p>
  <progress max="<?= max(1, count($cur)) ?>" value="<?= $done ?>"></progress>
  <?php if ($cur && $done === count($cur)): ?><p class="msg success">You are fully cleared for this term.</p><?php endif; ?>
</div>
<?php if (!$all): ?>
  <div class="card empty">No clearance records yet. The Registrar creates them at the start of each term.</div>
<?php else: ?>
  <div class="tablewrap"><table><thead><tr><th>Term</th><th>Office</th><th>Status</th><th>Remarks</th><th>Updated</th></tr></thead><tbody>
  <?php foreach ($all as $c): ?>
    <tr><td><?= e($c['school_year'] . ', ' . $c['semester']) ?></td><td><?= e($office($c)) ?></td><td><?= badge($c['status']) ?></td>
        <td><?= e($c['remarks'] ?? '') ?></td><td><?= e(fmt_date($c['reviewed_at'])) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif;
render_footer();
