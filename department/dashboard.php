<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('department');
$pdo  = db();
if (empty($user['department_id'])) { http_response_code(403); exit('Your account is not linked to a department. Contact the Admin office.'); }

$d = $pdo->prepare('SELECT name FROM departments WHERE id = ?'); $d->execute([(int)$user['department_id']]);
$dept = (string)$d->fetchColumn();
$c = $pdo->prepare("SELECT status, COUNT(*) n FROM clearances WHERE clearance_type = 'department' AND department_id = ? AND school_year = ? AND semester = ? GROUP BY status");
$c->execute([(int)$user['department_id'], CURRENT_SY, CURRENT_SEM]);
$n = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($c->fetchAll() as $r) $n[$r['status']] = (int)$r['n'];

render_header($user, $dept);
?>
<p class="muted">Academic clearance for <?= e(CURRENT_SY) ?>, <?= e(CURRENT_SEM) ?> semester.</p>
<div class="grid">
  <div class="stat"><b><?= $n['pending'] ?></b><span>Waiting for your decision</span></div>
  <div class="stat"><b><?= $n['approved'] ?></b><span>Approved</span></div>
  <div class="stat"><b><?= $n['rejected'] ?></b><span>Rejected</span></div>
</div>
<p><a class="btn" href="<?= e(url('/department/clearances.php')) ?>">Review pending clearances</a></p>
<?php render_footer();
