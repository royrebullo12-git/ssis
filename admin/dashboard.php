<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('admin');
$pdo  = db();

$val = function (string $sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn(); };
$byRole = $pdo->query('SELECT role, COUNT(*) n FROM users WHERE status = "active" GROUP BY role')->fetchAll(PDO::FETCH_KEY_PAIR);
$locked = $val('SELECT COUNT(*) FROM users WHERE locked_until > NOW()');
$failed = $val("SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN_FAILED' AND created_at > NOW() - INTERVAL 1 DAY");
$denied = $val("SELECT COUNT(*) FROM audit_logs WHERE action = 'ACCESS_DENIED' AND created_at > NOW() - INTERVAL 1 DAY");
$recent = $pdo->query('SELECT created_at, username, action, details, ip_address FROM audit_logs ORDER BY id DESC LIMIT 10')->fetchAll();

render_header($user, 'Admin dashboard');
?>
<div class="grid">
  <?php foreach (ALL_ROLES as $r): ?><div class="stat"><b><?= (int)($byRole[$r] ?? 0) ?></b><span>Active <?= e($r) ?> accounts</span></div><?php endforeach; ?>
  <div class="stat"><b><?= $locked ?></b><span>Locked accounts</span></div>
  <div class="stat"><b><?= $failed ?></b><span>Failed sign-ins, last 24 hours</span></div>
  <div class="stat"><b><?= $denied ?></b><span>Blocked access attempts, last 24 hours</span></div>
</div>
<h2>Latest activity</h2>
<div class="tablewrap"><table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td><?= e(fmt_date($r['created_at'])) ?></td><td><?= e($r['username'] ?? '-') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['details'] ?? '') ?></td><td><?= e($r['ip_address']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p><a class="btn alt" href="<?= e(url('/admin/audit.php')) ?>">Open the full audit log</a></p>
<?php render_footer();
