<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('student');
$stu  = student_of($user);
$pdo  = db();
require_once __DIR__ . '/../includes/documents.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 10);
    if ($action === 'create') {
        $err = doc_create((int)$stu['id'], post_int('type'), post_str('purpose'), post_int('copies'));
        $err ? flash('error', $err) : flash('success', 'Request submitted. The Registrar will review it.');
    } elseif ($action === 'cancel') {
        $err = doc_cancel((int)$stu['id'], post_int('id'));
        $err ? flash('error', $err) : flash('success', 'Request cancelled.');
    }
    redirect_self();
}

$types = doc_types();
$st = $pdo->prepare(
    'SELECT r.*, t.name AS type_name, p.reference_no, p.status AS pay_status, p.amount_paid
       FROM document_requests r JOIN document_types t ON t.id = r.document_type_id
       LEFT JOIN payments p ON p.id = r.payment_id
      WHERE r.student_id = ? ORDER BY r.created_at DESC'
);
$st->execute([$stu['id']]);
$reqs = $st->fetchAll();

render_header($user, 'Document requests');
?>
<div class="card">
  <h2>New request</h2>
  <?= form_open('create') ?>
    <div class="row">
      <div><label for="type">Document</label><select id="type" name="type" required>
        <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?> (<?= e(money($t['fee'])) ?> each, about <?= (int)$t['processing_days'] ?> days)</option><?php endforeach; ?>
      </select></div>
      <div><label for="copies">Copies</label><input id="copies" name="copies" type="number" min="1" max="10" value="1" required></div>
    </div>
    <label for="purpose">Purpose</label><input id="purpose" name="purpose" type="text" maxlength="255" required placeholder="e.g. Scholarship application">
    <p><button class="btn" type="submit">Submit request</button></p>
  </form>
</div>
<h2>My requests</h2>
<?php if (!$reqs): ?><div class="card empty">You have not requested any documents yet.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Document</th><th>Copies</th><th>Fee</th><th>Payment</th><th>Status</th><th>Filed</th><th></th></tr></thead><tbody>
<?php foreach ($reqs as $r): ?>
  <tr><td><?= e($r['type_name']) ?><br><small><?= e($r['purpose']) ?></small></td><td><?= (int)$r['copies'] ?></td>
      <td class="num"><?= e(money($r['fee_amount'])) ?></td>
      <td><?= $r['fee_amount'] > 0 ? ($r['pay_status'] ? badge($r['pay_status']) . '<br><small>' . e($r['reference_no']) . '</small>' : '<small>Billed after review</small>') : 'Free' ?></td>
      <td><?= badge($r['status']) ?><?= $r['remarks'] ? '<br><small>' . e($r['remarks']) . '</small>' : '' ?></td>
      <td><?= e(fmt_date($r['created_at'])) ?></td>
      <td><?php if (in_array($r['status'], ['submitted', 'awaiting_payment'], true) && (float)$r['amount_paid'] == 0.0): ?>
        <?= form_open('cancel') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn danger sm" type="submit" data-confirm="Cancel this request?">Cancel</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif;
render_footer();
