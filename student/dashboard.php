<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('student');
$stu  = student_of($user);
$pdo  = db();

$term = $pdo->prepare('SELECT clearance_type, status FROM clearances WHERE student_id = ? AND school_year = ? AND semester = ?');
$term->execute([$stu['id'], CURRENT_SY, CURRENT_SEM]);
$cl = $term->fetchAll();
$approved = count(array_filter($cl, fn($c) => $c['status'] === 'approved'));
$balance  = student_balance((int)$stu['id']);
$open = $pdo->prepare("SELECT COUNT(*) FROM document_requests WHERE student_id = ? AND status IN ('submitted','awaiting_payment','processing','ready')");
$open->execute([$stu['id']]);

render_header($user, 'Welcome, ' . $stu['first_name']);
?>
<p class="muted"><?= e($stu['student_no']) ?> · <?= e($stu['program']) ?>, year <?= (int)$stu['year_level'] ?> · <?= e($stu['department_name']) ?></p>
<div class="grid">
  <div class="stat"><b><?= badge($stu['enrollment_status']) ?></b><span>Enrollment<?= $stu['queue_number'] ? ' · queue no. ' . (int)$stu['queue_number'] : '' ?></span></div>
  <div class="stat"><b><?= $approved ?> of <?= count($cl) ?></b><span>Clearances approved this term</span></div>
  <div class="stat"><b><?= e(money($balance)) ?></b><span>Outstanding balance</span></div>
  <div class="stat"><b><?= (int)$open->fetchColumn() ?></b><span>Open document requests</span></div>
</div>
<div class="card">
  <h2>Next steps</h2>
  <ul>
    <?php if ($balance > 0): ?><li>Settle your balance at the Cashier so your fee clearance can be approved.</li><?php endif; ?>
    <?php if ($cl && $approved < count($cl)): ?><li><a href="<?= e(url('/student/clearance.php')) ?>">Check which offices still need to clear you.</a></li><?php endif; ?>
    <?php if ($stu['enrollment_status'] === 'not_enrolled'): ?><li><a href="<?= e(url('/student/enrollment.php')) ?>">Join the enrollment queue.</a></li><?php endif; ?>
    <li><a href="<?= e(url('/student/requests.php')) ?>">Request a certificate or transcript.</a></li>
  </ul>
</div>
<?php render_footer();
