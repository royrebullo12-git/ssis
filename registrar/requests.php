<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo  = db();
require_once __DIR__ . '/../includes/documents.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $err = doc_transition(post_int('id'), post_str('action', 10), post_str('remarks', 255), (int)$user['id']);
    $err ? flash('error', $err) : flash('success', 'Request updated.');
    redirect_self();
}

$status = get_str('status', 20);
$allowed = ['submitted', 'awaiting_payment', 'processing', 'ready', 'released', 'rejected', 'cancelled', 'all'];
if (!in_array($status, $allowed, true)) $status = 'open';
$where = $status === 'open' ? "r.status IN ('submitted','awaiting_payment','processing','ready')" : ($status === 'all' ? '1=1' : 'r.status = ?');
$departments = $pdo->query('SELECT id, code FROM departments ORDER BY code')->fetchAll();
$departmentValues = $_GET['department'] ?? [];
if (is_string($departmentValues) && $departmentValues !== '') $departmentValues = [$departmentValues];
if (!is_array($departmentValues)) $departmentValues = [];
$validDepartmentIds = array_map(static fn(array $department): string => (string)$department['id'], $departments);
$selectedDepartments = array_values(array_intersect(
    array_values(array_unique(array_filter($departmentValues, static fn($value): bool => is_string($value)))),
    $validDepartmentIds
));
$query = "SELECT r.*, t.name AS type_name, t.issuing_office, s.student_no, s.first_name, s.last_name, s.department_id, p.reference_no, p.status AS pay_status
       FROM document_requests r JOIN document_types t ON t.id = r.document_type_id
       JOIN students s ON s.id = r.student_id LEFT JOIN payments p ON p.id = r.payment_id
      WHERE {$where} AND r.routed_department_id IS NULL";
$queryParams = in_array($status, ['open', 'all'], true) ? [] : [$status];
if ($selectedDepartments) {
    $query .= ' AND s.department_id IN (' . implode(',', array_fill(0, count($selectedDepartments), '?')) . ')';
    array_push($queryParams, ...array_map('intval', $selectedDepartments));
}
$query .= ' ORDER BY r.created_at LIMIT 200';
$st = $pdo->prepare(
    $query
);
$st->execute($queryParams);
$rows = $st->fetchAll();
$labels = ['accept' => 'Accept', 'reject' => 'Reject', 'ready' => 'Mark ready', 'released' => 'Mark released'];

render_header($user, 'Document requests');
?>
<form class="filters card" method="get"><div><label for="status">Show</label><select id="status" name="status">
  <option value="open"<?= $status === 'open' ? ' selected' : '' ?>>Open requests</option>
  <?php foreach ($allowed as $s): ?><option value="<?= $s ?>"<?= $s === $status ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
  <div><label for="department">Student department</label><select id="department" name="department"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>"<?= in_array((string)$department['id'], $selectedDepartments, true) ? ' selected' : '' ?>><?= e($department['code']) ?></option><?php endforeach; ?></select></div>
  <button class="btn" type="submit">Apply filters</button><a class="btn alt" href="<?= e(url('/registrar/requests.php')) ?>">Clear</a></form>
<?php if (!$rows): ?><div class="card empty">No requests in this view.</div><?php else: ?>
<div class="tablewrap table-responsive"><table><thead><tr><th>#</th><th>Student</th><th>Document</th><th>Issuing office</th><th>Fee</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td><?= (int)$r['id'] ?></td><td><?= e($r['student_no']) ?><br><small><?= e($r['last_name'] . ', ' . $r['first_name']) ?></small></td>
      <td><?= e($r['type_name']) ?> × <?= (int)$r['copies'] ?><br><small><?= e($r['purpose']) ?></small></td><td><?= e($r['issuing_office']) ?></td>
      <td class="num"><?= e(money($r['fee_amount'])) ?><?= $r['pay_status'] ? '<br>' . badge($r['pay_status']) : '' ?></td>
      <td><?= badge($r['status']) ?><?= $r['remarks'] ? '<br><small>' . e($r['remarks']) . '</small>' : '' ?></td>
      <td><?php if (isset(DOC_ACTIONS[$r['status']])): ?><div class="actions"><?= form_open() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="text" name="remarks" maxlength="255" placeholder="Remarks" aria-label="Remarks">
        <?php foreach (DOC_ACTIONS[$r['status']] as $a): ?><button class="btn sm<?= $a === 'reject' ? ' danger' : ' ok' ?>" name="action" value="<?= $a ?>" type="submit"><?= e($labels[$a]) ?></button><?php endforeach; ?>
        </form></div><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; render_footer();
