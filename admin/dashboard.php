<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('admin');
$pdo = db();

$val = function(string $sql) use($pdo){return (int)$pdo->query($sql)->fetchColumn();};
$byRole = $pdo->query('SELECT role,COUNT(*) n FROM users WHERE status="active" GROUP BY role')->fetchAll(PDO::FETCH_KEY_PAIR);
$students = $val('SELECT COUNT(*) FROM students');
$pending = $val("SELECT COUNT(*) FROM students WHERE enrollment_status='pending'");
$activeStudents = $val("SELECT COUNT(*) FROM students WHERE enrollment_status='active'");
$locked = $val('SELECT COUNT(*) FROM users WHERE locked_until > NOW()');
$failed = $val("SELECT COUNT(*) FROM audit_logs WHERE action='LOGIN_FAILED' AND created_at > NOW()-INTERVAL 1 DAY");
$denied = $val("SELECT COUNT(*) FROM audit_logs WHERE action='ACCESS_DENIED' AND created_at > NOW()-INTERVAL 1 DAY");
$recent = $pdo->query('SELECT created_at,username,action,details,ip_address FROM audit_logs ORDER BY id DESC LIMIT 10')->fetchAll();

render_header($user,'Administrator overview');
?>
<div class="grid">
  <div class="stat"><span class="stat-label">TOTAL STUDENTS</span><b><?= $students ?></b><span>Registered student records</span></div>
  <div class="stat"><span class="stat-label">PENDING REGISTRATIONS</span><b><?= $pending ?></b><span>Registrar verification queue</span></div>
  <div class="stat"><span class="stat-label">ACTIVE STUDENTS</span><b><?= $activeStudents ?></b><span>Currently active</span></div>
  <div class="stat"><span class="stat-label">ACTIVE STAFF</span><b><?= array_sum(array_map('intval',$byRole)) - (int)($byRole['student']??0) ?></b><span>All non-student roles</span></div>
</div>
<div class="content-grid">
<section class="card"><p class="card-kicker">SYSTEM CONTROL</p><h2>Role-based operations</h2>
<div class="quick-actions">
<a href="<?= e(url('/admin/users.php?role=registrar')) ?>"><b><?= (int)($byRole['registrar']??0) ?></b><span>Registrar accounts</span></a>
<a href="<?= e(url('/admin/users.php')) ?>"><b><?= array_sum(array_map('intval',$byRole)) ?></b><span>Active accounts</span></a>
<a href="<?= e(url('/admin/audit.php')) ?>"><b><?= $denied ?></b><span>Blocked attempts today</span></a>
</div></section>
<aside class="card"><p class="card-kicker">SECURITY</p><h2>Access monitoring</h2><p><strong><?= $locked ?></strong> locked account(s)</p><p><strong><?= $failed ?></strong> failed sign-in attempts in the last 24 hours</p><a class="btn alt" href="<?= e(url('/admin/audit.php')) ?>">Open audit log</a></aside>
</div>
<h2>Latest activity</h2>
<div class="tablewrap"><table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach($recent as $r): ?><tr><td><?= e(fmt_date($r['created_at'])) ?></td><td><?= e($r['username']??'-') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['details']??'') ?></td><td><?= e($r['ip_address']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php render_footer();
