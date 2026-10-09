<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('cashier');
$pdo  = db();

$val = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$outstanding = (float)$val("SELECT COALESCE(SUM(amount_due - amount_paid), 0) FROM payments WHERE status IN ('unpaid','partial')");
$open   = (int)$val("SELECT COUNT(*) FROM payments WHERE status IN ('unpaid','partial')");
$paid   = (int)$val("SELECT COUNT(*) FROM payments WHERE status = 'paid'");
$pendCl = (int)$val("SELECT COUNT(*) FROM clearances WHERE clearance_type = 'cashier' AND status = 'pending' AND school_year = ? AND semester = ?", [CURRENT_SY, CURRENT_SEM]);

$recent = $pdo->query("SELECT p.reference_no, p.description, p.amount_paid, p.status, p.paid_at, s.student_no FROM payments p JOIN students s ON s.id = p.student_id
                        WHERE p.paid_at IS NOT NULL ORDER BY p.paid_at DESC LIMIT 8")->fetchAll();
render_header($user, 'Cashier dashboard');
?>
<div class="grid">
  <div class="stat"><b><?= e(money($outstanding)) ?></b><span>Outstanding balances</span></div>
  <div class="stat"><b><?= $open ?></b><span>Unpaid or partial accounts</span></div>
  <div class="stat"><b><?= $paid ?></b><span>Fully paid accounts</span></div>
  <div class="stat"><b><?= $pendCl ?></b><span>Fee clearances waiting</span></div>
</div>
<h2>Latest payments</h2>
<?php if (!$recent): ?><div class="card empty">No payments recorded yet.</div><?php else: ?>
<div class="tablewrap table-responsive"><table><thead><tr><th>Reference</th><th>Student</th><th>Description</th><th class="num">Paid</th><th>Status</th><th>When</th></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td><?= e($r['reference_no']) ?></td><td><?= e($r['student_no']) ?></td><td><?= e($r['description']) ?></td>
  <td class="num"><?= e(money($r['amount_paid'])) ?></td><td><?= badge($r['status']) ?></td><td><?= e(fmt_date($r['paid_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; render_footer();
