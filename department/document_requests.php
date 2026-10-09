<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('department');
$departmentId = (int)($user['department_id'] ?? 0);
if ($departmentId < 1) {
    http_response_code(403);
    exit('Your account is not linked to a department.');
}
require_once __DIR__ . '/../includes/documents.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $error = doc_transition(post_int('id'), post_str('action', 10), post_str('remarks', 255), (int)$user['id'], $departmentId);
    $error ? flash('error', $error) : flash('success', 'Department document request updated.');
    redirect_self();
}

$status = get_str('status', 20);
$allowed = ['submitted', 'awaiting_payment', 'processing', 'ready', 'released', 'rejected', 'cancelled', 'all'];
if (!in_array($status, $allowed, true)) $status = 'open';
$condition = $status === 'open' ? "r.status IN ('submitted','awaiting_payment','processing','ready')" : ($status === 'all' ? '1=1' : 'r.status = ?');
$statement = $pdo->prepare(
    "SELECT r.*, t.name AS type_name, t.issuing_office, s.student_no, s.first_name, s.last_name,
            p.reference_no, p.status AS pay_status
       FROM document_requests r
       JOIN document_types t ON t.id = r.document_type_id
       JOIN students s ON s.id = r.student_id
       LEFT JOIN payments p ON p.id = r.payment_id
      WHERE {$condition} AND r.routed_department_id = ?
      ORDER BY r.created_at DESC LIMIT 200"
);
$params = in_array($status, ['open', 'all'], true) ? [$departmentId] : [$status, $departmentId];
$statement->execute($params);
$rows = $statement->fetchAll();
$labels = ['accept' => 'Accept', 'reject' => 'Reject', 'ready' => 'Mark ready', 'released' => 'Mark released'];

render_header($user, 'Department document requests');
?>
<form class="filters card" method="get"><div><label for="status">Show</label><select id="status" name="status">
  <option value="open"<?= $status === 'open' ? ' selected' : '' ?>>Open requests</option>
  <?php foreach ($allowed as $option): ?><option value="<?= e($option) ?>"<?= $option === $status ? ' selected' : '' ?>><?= e(label($option)) ?></option><?php endforeach; ?>
</select></div><button class="btn" type="submit">Filter</button></form>
<?php if (!$rows): ?><div class="card empty">No department-routed document requests match this view.</div><?php else: ?>
<div class="tablewrap table-responsive"><table><thead><tr><th>#</th><th>Student</th><th>Document</th><th>Fee</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
  <tr><td><?= (int)$row['id'] ?></td><td><?= e($row['student_no']) ?><br><small><?= e($row['last_name'] . ', ' . $row['first_name']) ?></small></td>
    <td><?= e($row['type_name']) ?> × <?= (int)$row['copies'] ?><br><small><?= e($row['purpose']) ?> · <?= e($row['issuing_office']) ?></small></td>
    <td class="num"><?= e(money($row['fee_amount'])) ?><?= $row['pay_status'] ? '<br>' . badge($row['pay_status']) : '' ?></td>
    <td><?= badge($row['status']) ?><?= $row['remarks'] ? '<br><small>' . e($row['remarks']) . '</small>' : '' ?></td>
    <td><?php if (isset(DOC_ACTIONS[$row['status']])): ?><div class="actions"><?= form_open() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
      <label class="sr-only" for="remarks-<?= (int)$row['id'] ?>">Remarks</label><input id="remarks-<?= (int)$row['id'] ?>" type="text" name="remarks" maxlength="255" placeholder="Remarks">
      <?php foreach (DOC_ACTIONS[$row['status']] as $action): ?><button class="btn sm<?= $action === 'reject' ? ' danger' : ' ok' ?>" name="action" value="<?= e($action) ?>" type="submit"><?= e($labels[$action]) ?></button><?php endforeach; ?>
    </form></div><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php render_footer(); ?>
