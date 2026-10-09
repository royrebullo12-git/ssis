<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('cashier');
$pdo  = db();
require_once __DIR__ . '/../includes/documents.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 10);
    try {
        if ($action === 'charge') {
            $sid = post_int('student_id'); $desc = post_str('description', 150); $amt = round((float)post_str('amount', 12), 2);
            $ok = $pdo->prepare('SELECT 1 FROM students WHERE id = ?'); $ok->execute([$sid]);
            if (!$ok->fetchColumn()) flash('error', 'Choose a valid student.');
            elseif (mb_strlen($desc) < 3) flash('error', 'Enter a description for the charge.');
            elseif ($amt <= 0 || $amt > 1000000) flash('error', 'Amount must be between 0.01 and 1,000,000.');
            else {
                $pdo->beginTransaction();
                $ref = new_reference('PAY');
                $pdo->prepare("INSERT INTO payments (student_id, reference_no, description, amount_due, status, processed_by) VALUES (?, ?, ?, ?, 'unpaid', ?)")
                    ->execute([$sid, $ref, $desc, $amt, (int)$user['id']]);
                $studentUser = $pdo->prepare('SELECT user_id FROM students WHERE id = ?');
                $studentUser->execute([$sid]);
                $studentUserId = (int)$studentUser->fetchColumn();
                if ($studentUserId > 0) {
                    create_notification($studentUserId, 'New balance reminder', 'A new charge of ' . money($amt) . ' was added: ' . $desc . '.', '/student/clearance.php');
                }
                audit_log('PAYMENT_CHARGE_CREATED', (int)$user['id'], $user['username'], 'payment', (int)$pdo->lastInsertId(), "{$ref} {$amt}");
                $pdo->commit();
                flash('success', "Charge {$ref} created for " . money($amt) . '.');
            }
        } elseif ($action === 'pay') {
            $id = post_int('id'); $amt = round((float)post_str('amount', 12), 2); $method = post_str('method', 6);
            if (!in_array($method, ['cash', 'gcash', 'bank', 'card'], true)) { flash('error', 'Choose a payment method.'); }
            else {
                $pdo->beginTransaction();
                $p = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE'); $p->execute([$id]); $pay = $p->fetch();
                $left = $pay ? round((float)$pay['amount_due'] - (float)$pay['amount_paid'], 2) : 0;
                if (!$pay || !in_array($pay['status'], ['unpaid', 'partial'], true)) { $pdo->rollBack(); flash('error', 'This payment cannot accept further payments.'); }
                elseif ($amt <= 0 || $amt > $left) { $pdo->rollBack(); flash('error', 'Amount must be between 0.01 and the remaining ' . money($left) . '.'); }
                else {
                    $newPaid = round((float)$pay['amount_paid'] + $amt, 2);
                    $status  = $newPaid >= (float)$pay['amount_due'] ? 'paid' : 'partial';
                    $pdo->prepare('UPDATE payments SET amount_paid = ?, status = ?, method = ?, paid_at = NOW(), processed_by = ? WHERE id = ?')
                        ->execute([$newPaid, $status, $method, (int)$user['id'], $id]);
                    $pdo->prepare('INSERT INTO payment_transactions (payment_id, amount, method, processed_by) VALUES (?, ?, ?, ?)')
                        ->execute([$id, $amt, $method, (int)$user['id']]);
                    doc_after_payment($pdo, $id);
                    $pdo->commit();
                    audit_log('PAYMENT_RECORDED', (int)$user['id'], $user['username'], 'payment', $id, "{$pay['reference_no']} +{$amt} via {$method}");
                    flash('success', 'Payment of ' . money($amt) . ' recorded (' . label($status) . ').');
                }
            }
        } elseif ($action === 'void') {
            $id = post_int('id');
            $n = $pdo->prepare("UPDATE payments SET status = 'void', processed_by = ? WHERE id = ? AND amount_paid = 0 AND status = 'unpaid'");
            $n->execute([(int)$user['id'], $id]);
            if ($n->rowCount()) { audit_log('PAYMENT_VOIDED', (int)$user['id'], $user['username'], 'payment', $id); flash('success', 'Charge voided.'); }
            else flash('error', 'Only charges with no payment can be voided.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[SSIS] cashier: ' . $e->getMessage());
        flash('error', 'The transaction could not be saved.');
    }
    redirect_self();
}

$q = get_str('q', 60); $status = get_str('status', 10);
if (!in_array($status, ['unpaid', 'partial', 'paid', 'void'], true)) $status = '';
$validDate = static function (string $value): string {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
};
$dateFromInput = get_str('date_from', 10);
$dateToInput = get_str('date_to', 10);
$dateFrom = $validDate($dateFromInput);
$dateTo = $validDate($dateToInput);
$invalidDateRange = ($dateFromInput !== '' && $dateFrom === '') || ($dateToInput !== '' && $dateTo === '')
    || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo);
$like = '%' . addcslashes($q, '%_\\') . '%';
$conditions = ["(? = '' OR s.student_no LIKE ? OR s.last_name LIKE ? OR p.reference_no LIKE ?)", "(? = '' OR p.status = ?)"];
$params = [$q, $like, $like, $like, $status, $status];
$transactionDateConditions = [];
if ($invalidDateRange) $conditions[] = '1 = 0';
if ($dateFrom !== '') {
    $transactionDateConditions[] = 'pt.paid_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $transactionDateConditions[] = 'pt.paid_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo;
}
if ($transactionDateConditions) {
    $conditions[] = 'EXISTS (SELECT 1 FROM payment_transactions pt WHERE pt.payment_id = p.id AND '
        . implode(' AND ', $transactionDateConditions) . ')';
}
$st = $pdo->prepare(
    'SELECT p.*, s.student_no, s.first_name, s.last_name FROM payments p JOIN students s ON s.id = p.student_id
      WHERE ' . implode(' AND ', $conditions) . ' ORDER BY p.created_at DESC LIMIT 100'
);
$st->execute($params);
$rows = $st->fetchAll();
$students = $pdo->query('SELECT id, student_no, last_name, first_name FROM students ORDER BY last_name, first_name')->fetchAll();
$balanceBreakdown = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN balance_due > balance_paid AND balance_paid = 0 THEN 1 ELSE 0 END), 0) AS unpaid,
        COALESCE(SUM(CASE WHEN balance_due > balance_paid AND balance_paid > 0 THEN 1 ELSE 0 END), 0) AS partial,
        COALESCE(SUM(CASE WHEN balance_due > 0 AND balance_paid >= balance_due THEN 1 ELSE 0 END), 0) AS fully_paid
       FROM (
         SELECT student_id, SUM(amount_due) AS balance_due, SUM(amount_paid) AS balance_paid
           FROM payments WHERE status <> 'void' GROUP BY student_id
       ) AS student_balances"
)->fetch();

render_header($user, 'Payments');
?>
<div class="grid">
  <div class="stat"><b><?= (int)$balanceBreakdown['unpaid'] ?></b><span>Unpaid student balances</span></div>
  <div class="stat"><b><?= (int)$balanceBreakdown['partial'] ?></b><span>Partially paid student balances</span></div>
  <div class="stat"><b><?= (int)$balanceBreakdown['fully_paid'] ?></b><span>Fully paid student balances</span></div>
</div>
<div class="row g-4">
  <div class="col-lg-6">
    <section class="card h-100">
      <div class="card-body">
        <h2>New charge</h2>
        <?= form_open('charge') ?>
          <div class="mb-3">
            <label class="form-label" for="student_id">Student</label>
            <select class="form-select w-100" id="student_id" name="student_id" required>
              <?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['student_no'] . ' - ' . $s['last_name'] . ', ' . $s['first_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="description">Description</label>
            <input class="form-control w-100" id="description" name="description" type="text" maxlength="150" required placeholder="e.g. Laboratory fee">
          </div>
          <div class="mb-3">
            <label class="form-label" for="amount">Amount (₱)</label>
            <input class="form-control w-100" id="amount" name="amount" type="number" step="0.01" min="0.01" required>
          </div>
          <button class="btn btn-primary w-100" type="submit">Create charge</button>
        </form>
      </div>
    </section>
  </div>
  <div class="col-lg-6">
    <form class="card h-100" method="get">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="q">Search</label>
          <input class="form-control w-100" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Student no., name or reference">
        </div>
        <div class="mb-3">
          <label class="form-label" for="status">Status</label>
          <select class="form-select w-100" id="status" name="status">
            <option value="">All</option>
            <?php foreach (['unpaid', 'partial', 'paid', 'void'] as $s): ?><option value="<?= $s ?>"<?= $s === $status ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label" for="date_from">Date paid from</label>
            <input class="form-control w-100" id="date_from" name="date_from" type="date" value="<?= e($dateFrom) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="date_to">Date paid to</label>
            <input class="form-control w-100" id="date_to" name="date_to" type="date" value="<?= e($dateTo) ?>">
          </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-nowrap">
          <button class="btn btn-primary btn-sm text-nowrap" type="submit">Filter</button>
          <a class="btn btn-outline-secondary btn-sm text-nowrap" href="<?= e(url('/cashier/payments.php')) ?>">Clear</a>
          <a class="btn btn-outline-secondary btn-sm text-nowrap" href="<?= e(url('/cashier/collections.php')) ?>">Collection report</a>
        </div>
      </div>
    </form>
  </div>
</div>
  <?php if ($invalidDateRange): ?><div class="msg error" role="alert">Enter valid payment dates and ensure the start date is not after the end date.</div><?php endif; ?>
<?php if (!$rows): ?><div class="card empty">No payments match.</div><?php else: ?>
<div class="tablewrap table-responsive"><table><thead><tr><th>Reference</th><th>Student</th><th>Description</th><th class="num">Due</th><th class="num">Paid</th><th>Status</th><th>Record payment</th></tr></thead><tbody>
<?php foreach ($rows as $r): $left = (float)$r['amount_due'] - (float)$r['amount_paid']; ?>
  <tr><td><?= e($r['reference_no']) ?></td><td><?= e($r['student_no']) ?><br><small><?= e($r['last_name']) ?></small></td><td><?= e($r['description']) ?></td>
    <td class="num"><?= e(money($r['amount_due'])) ?></td><td class="num"><?= e(money($r['amount_paid'])) ?></td><td><?= badge($r['status']) ?></td>
    <td><?php if (in_array($r['status'], ['unpaid', 'partial'], true)): ?><div class="actions"><?= form_open('pay') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="number" name="amount" step="0.01" min="0.01" max="<?= e(number_format($left, 2, '.', '')) ?>" value="<?= e(number_format($left, 2, '.', '')) ?>" required aria-label="Amount">
      <select name="method" aria-label="Method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank">Bank</option><option value="card">Card</option></select>
      <button class="btn ok sm" type="submit">Record</button></form>
      <?php if ($r['status'] === 'unpaid'): ?><?= form_open('void') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn danger sm" type="submit" data-confirm="Void this charge?">Void</button></form><?php endif; ?></div><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; render_footer();
