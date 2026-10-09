<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('cashier');
$pdo = db();

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
$conditions = $invalidDateRange ? ['1 = 0'] : [];
$params = [];
if ($dateFrom !== '') { $conditions[] = 'pt.paid_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $conditions[] = 'pt.paid_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $dateTo; }
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$summary = $pdo->prepare(
    'SELECT COUNT(*) AS transaction_count, COALESCE(SUM(amount), 0) AS total_collected,
            COALESCE(SUM(amount * (method = \'cash\')), 0) AS cash_total,
            COALESCE(SUM(amount * (method = \'gcash\')), 0) AS gcash_total,
            COALESCE(SUM(amount * (method = \'bank\')), 0) AS bank_total,
            COALESCE(SUM(amount * (method = \'card\')), 0) AS card_total
       FROM payment_transactions pt' . $where
);
$summary->execute($params);
$totals = $summary->fetch();
$transactions = $pdo->prepare(
    'SELECT pt.id, pt.amount, pt.method, pt.paid_at, pt.payment_id, p.reference_no, p.description,
            s.student_no, s.first_name, s.last_name
       FROM payment_transactions pt
       JOIN payments p ON p.id = pt.payment_id
       JOIN students s ON s.id = p.student_id' . $where . '
      ORDER BY pt.paid_at DESC, pt.id DESC LIMIT 1000'
);
$transactions->execute($params);
$rows = $transactions->fetchAll();

render_header($user, 'Daily collection report');
?>
<div class="grid">
  <div class="stat"><b><?= e(money($totals['total_collected'])) ?></b><span>Total collected</span></div>
  <div class="stat"><b><?= (int)$totals['transaction_count'] ?></b><span>Transactions</span></div>
  <div class="stat"><b><?= e(money($totals['cash_total'])) ?></b><span>Cash</span></div>
  <div class="stat"><b><?= e(money((float)$totals['gcash_total'] + (float)$totals['bank_total'] + (float)$totals['card_total'])) ?></b><span>GCash, bank & card</span></div>
</div>
<form class="filters card" method="get">
  <div><label for="date_from">Paid from</label><input id="date_from" name="date_from" type="date" value="<?= e($dateFrom) ?>"></div>
  <div><label for="date_to">Paid to</label><input id="date_to" name="date_to" type="date" value="<?= e($dateTo) ?>"></div>
  <button class="btn" type="submit">Run report</button>
  <a class="btn alt" href="<?= e(url('/cashier/collections.php')) ?>">Clear</a>
  <a class="btn alt" href="<?= e(url('/cashier/payments.php')) ?>">Payments</a>
</form>
<?php if ($invalidDateRange): ?><div class="msg error" role="alert">Enter valid payment dates and ensure the start date is not after the end date.</div><?php endif; ?>
<section class="card">
  <h2>Payment transactions</h2>
  <?php if (!$rows): ?><div class="empty">No collections were recorded for the selected date range.</div><?php else: ?>
  <div class="tablewrap table-responsive"><table><thead><tr><th>Date paid</th><th>Receipt</th><th>Student</th><th>Description</th><th>Method</th><th class="num">Amount</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr>
      <td><?= e(fmt_date($row['paid_at'])) ?></td><td><?= e($row['reference_no']) ?></td>
      <td><?= e($row['student_no'] . ' · ' . $row['last_name'] . ', ' . $row['first_name']) ?></td>
      <td><?= e($row['description']) ?></td><td><?= e(strtoupper($row['method'])) ?></td>
      <td class="num"><?= e(money($row['amount'])) ?></td>
    </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
