<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('admin');
$pdo  = db();

const PER_PAGE = 25;
$action = get_str('action', 40); $uname = get_str('username', 50);
$from = get_str('from', 10); $to = get_str('to', 10);
$valid = fn(string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
if ($from !== '' && !$valid($from)) $from = '';
if ($to !== '' && !$valid($to)) $to = '';

$where = []; $p = [];
if ($action !== '') { $where[] = 'action = ?'; $p[] = $action; }
if ($uname !== '')  { $where[] = 'username LIKE ?'; $p[] = '%' . addcslashes($uname, '%_\\') . '%'; }
if ($from !== '')   { $where[] = 'created_at >= ?'; $p[] = $from . ' 00:00:00'; }
if ($to !== '')     { $where[] = 'created_at <= ?'; $p[] = $to . ' 23:59:59'; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$c = $pdo->prepare("SELECT COUNT(*) FROM audit_logs {$w}"); $c->execute($p);
$total = (int)$c->fetchColumn();
$pages = max(1, (int)ceil($total / PER_PAGE));
$page  = min(max(1, get_int('page')), $pages);

$st = $pdo->prepare("SELECT * FROM audit_logs {$w} ORDER BY id DESC LIMIT " . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE));
$st->execute($p);
$rows = $st->fetchAll();
$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$qs = fn(int $pg) => '?' . http_build_query(['action' => $action, 'username' => $uname, 'from' => $from, 'to' => $to, 'page' => $pg]);

render_header($user, 'Audit log');
?>
<form class="filters" method="get">
  <div><label for="action">Action</label><select id="action" name="action"><option value="">All</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= $a === $action ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
  <div><label for="username">Username</label><input id="username" name="username" type="text" value="<?= e($uname) ?>"></div>
  <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>"></div>
  <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>"></div>
  <button class="btn" type="submit">Filter</button></form>
<p class="muted"><?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?>. Audit entries cannot be edited or deleted from the application.</p>
<?php if (!$rows): ?><div class="card empty">No log entries match.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e(fmt_date($r['created_at'])) ?></td><td><?= e($r['username'] ?? '-') ?></td><td><?= e($r['action']) ?></td>
  <td><?= e(($r['entity'] ?? '') . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')) ?></td><td><?= e($r['details'] ?? '') ?></td><td><?= e($r['ip_address']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="pager"><?php if ($page > 1): ?><a class="btn alt sm" href="<?= e($qs($page - 1)) ?>">Previous</a><?php endif; ?>
  <span>Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn alt sm" href="<?= e($qs($page + 1)) ?>">Next</a><?php endif; ?></div>
<?php endif; render_footer();
