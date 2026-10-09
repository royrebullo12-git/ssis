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
    } elseif ($action === 'drop') {
        $enrollmentId = post_int('enrollment_id');
        $reason = post_str('reason', 80);
        $details = post_str('details', 500);
        $reasons = ['Academic workload', 'Health or personal reasons', 'Financial reasons', 'Schedule conflict', 'Change of program', 'Other'];
        if (!in_array($reason, $reasons, true) || ($reason === 'Other' && mb_strlen($details) < 10)) {
            flash('error', 'Choose a valid reason. For Other, provide at least 10 characters of detail.');
        } else {
            $pdo->beginTransaction();
            try {
                $enrollment = $pdo->prepare(
                    "SELECT e.id, sub.code, sub.title, o.section, sub.department_id
                       FROM enrollments e
                       JOIN subject_offerings o ON o.id = e.subject_offering_id
                       JOIN subjects sub ON sub.id = o.subject_id
                      WHERE e.id = ? AND e.student_id = ? AND e.status = 'enrolled'
                      FOR UPDATE"
                );
                $enrollment->execute([$enrollmentId, (int)$stu['id']]);
                $class = $enrollment->fetch();
                $pending = $pdo->prepare("SELECT 1 FROM drop_requests WHERE enrollment_id = ? AND status = 'submitted' LIMIT 1");
                $pending->execute([$enrollmentId]);
                if (!$class) {
                    $pdo->rollBack();
                    flash('error', 'Choose one of your currently enrolled subjects.');
                } elseif ($pending->fetchColumn()) {
                    $pdo->rollBack();
                    flash('error', 'A drop request is already pending for that subject.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO drop_requests (student_id, enrollment_id, department_id, reason, details)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([(int)$stu['id'], $enrollmentId, (int)$class['department_id'], $reason, $details ?: null]);
                    $requestId = (int)$pdo->lastInsertId();
                    $pdo->commit();
                    notify_role('department', 'Course drop request', $stu['student_no'] . ' requested to drop ' . $class['code'] . '.', '/department/drop_requests.php', (int)$class['department_id']);
                    create_notification((int)$user['id'], 'Drop request submitted', 'Your request for ' . $class['code'] . ' was sent to ' . $stu['department_name'] . '.', '/student/requests.php');
                    audit_log('COURSE_DROP_REQUESTED', (int)$user['id'], $user['username'], 'drop_request', $requestId);
                    flash('success', 'Your official drop request was routed to ' . $stu['department_name'] . '.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[WLS] student drop request failed: ' . $e->getMessage());
                flash('error', 'The drop request could not be saved.');
            }
        }
    }
    redirect_self();
}

$types = doc_types();
$classesStmt = $pdo->prepare(
    "SELECT e.id, sub.code, sub.title, o.section, o.academic_year, o.semester
       FROM enrollments e
       JOIN subject_offerings o ON o.id = e.subject_offering_id
       JOIN subjects sub ON sub.id = o.subject_id
      WHERE e.student_id = ? AND e.status = 'enrolled'
        AND NOT EXISTS (SELECT 1 FROM drop_requests dr WHERE dr.enrollment_id = e.id AND dr.status = 'submitted')
      ORDER BY o.academic_year DESC, FIELD(o.semester, '1st', '2nd', 'summer'), sub.code"
);
$classesStmt->execute([(int)$stu['id']]);
$dropClasses = $classesStmt->fetchAll();
$dropRequestsStmt = $pdo->prepare(
    'SELECT dr.*, sub.code, sub.title, o.section, d.name AS department_name
       FROM drop_requests dr
       JOIN enrollments e ON e.id = dr.enrollment_id
       JOIN subject_offerings o ON o.id = e.subject_offering_id
       JOIN subjects sub ON sub.id = o.subject_id
       JOIN departments d ON d.id = dr.department_id
      WHERE dr.student_id = ? ORDER BY dr.created_at DESC'
);
$dropRequestsStmt->execute([(int)$stu['id']]);
$dropRequests = $dropRequestsStmt->fetchAll();
$st = $pdo->prepare(
    'SELECT r.*, t.name AS type_name, t.issuing_office, p.reference_no, p.status AS pay_status, p.amount_paid
       FROM document_requests r JOIN document_types t ON t.id = r.document_type_id
       LEFT JOIN payments p ON p.id = r.payment_id
      WHERE r.student_id = ? ORDER BY r.created_at DESC'
);
$st->execute([$stu['id']]);
$reqs = $st->fetchAll();

render_header($user, 'Student requests');
?>
<div class="card">
  <p class="card-kicker">OFFICIAL DOCUMENTS</p><h2>Request a document</h2>
  <?= form_open('create') ?>
    <div class="row g-3">
      <div class="col-12 col-md-8"><label for="type">Document</label><select id="type" name="type" required>
        <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?> (<?= e(money($t['fee'])) ?> each, about <?= (int)$t['processing_days'] ?> days)</option><?php endforeach; ?>
      </select></div>
      <div class="col-12 col-md-4"><label for="copies">Copies</label><input id="copies" name="copies" type="number" min="1" max="10" value="1" required></div>
    </div>
    <label for="purpose">Purpose</label><input id="purpose" name="purpose" type="text" maxlength="255" required placeholder="e.g. Scholarship application">
    <p><button class="btn" type="submit">Submit request</button></p>
  </form>
</div>
<h2>My document requests</h2>
<?php if (!$reqs): ?><div class="card empty">You have not requested any documents yet.</div><?php else: ?>
<div class="tablewrap table-responsive"><table><thead><tr><th>Document</th><th>Copies</th><th>Fee</th><th>Payment</th><th>Status</th><th>Filed</th><th></th></tr></thead><tbody>
<?php foreach ($reqs as $r): ?>
  <tr><td><?= e($r['type_name']) ?><br><small><?= e($r['purpose']) ?></small></td><td><?= (int)$r['copies'] ?></td>
      <td class="num"><?= e(money($r['fee_amount'])) ?></td>
      <td><?= $r['fee_amount'] > 0 ? ($r['pay_status'] ? badge($r['pay_status']) . '<br><small>' . e($r['reference_no']) . '</small>' : '<small>Billed after review</small>') : 'Free' ?></td>
      <td><?= badge($r['status']) ?><br><small>Issuing office: <?= e($r['issuing_office']) ?></small><?= $r['remarks'] ? '<br><small>' . e($r['remarks']) . '</small>' : '' ?></td>
      <td><?= e(fmt_date($r['created_at'])) ?></td>
      <td><?php if (in_array($r['status'], ['submitted', 'awaiting_payment'], true) && (float)$r['amount_paid'] == 0.0): ?>
        <?= form_open('cancel') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn danger sm" type="submit" data-confirm="Cancel this request?">Cancel</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif;
?>
<section class="card">
  <p class="card-kicker">REGISTRAR / DEPARTMENT WORKFLOW</p><h2>Drop a course</h2>
  <p class="muted">Submit an official request with your reason. It will be routed to the department responsible for the subject; enrollment changes only after approval.</p>
  <?php if (!$dropClasses): ?><div class="empty">No currently enrolled subjects are available for a new drop request.</div>
  <?php else: ?>
    <?= form_open('drop') ?>
      <div class="form-grid">
        <div><label for="enrollment_id">Subject</label><select id="enrollment_id" name="enrollment_id" required><option value="">Choose an enrolled subject</option>
          <?php foreach ($dropClasses as $class): ?><option value="<?= (int)$class['id'] ?>"><?= e($class['code'] . ' - ' . $class['title'] . ' / ' . $class['section'] . ' / ' . $class['academic_year'] . ' ' . $class['semester']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label for="reason">Reason for request</label><select id="reason" name="reason" required><option value="">Choose a reason</option>
          <?php foreach (['Academic workload', 'Health or personal reasons', 'Financial reasons', 'Schedule conflict', 'Change of program', 'Other'] as $reason): ?><option value="<?= e($reason) ?>"><?= e($reason) ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <label for="details">Additional details</label><textarea id="details" name="details" rows="3" maxlength="500" placeholder="Provide context to help your department review the request."></textarea>
      <p class="form-actions"><button class="btn" type="submit" data-confirm="Submit this official course drop request?">Submit drop request</button></p>
    </form>
  <?php endif; ?>
</section>
<h2>My course drop requests</h2>
<?php if (!$dropRequests): ?><div class="card empty">You have not submitted a course drop request.</div><?php else: ?>
<div class="tablewrap table-responsive"><table><thead><tr><th>Subject</th><th>Reason</th><th>Routed to</th><th>Status</th><th>Filed</th></tr></thead><tbody>
<?php foreach ($dropRequests as $request): ?><tr>
  <td><?= e($request['code'] . ' - ' . $request['title'] . ' / ' . $request['section']) ?></td>
  <td><?= e($request['reason']) ?><?= $request['details'] ? '<br><small>' . e($request['details']) . '</small>' : '' ?></td>
  <td><?= e($request['department_name']) ?></td><td><?= badge($request['status']) ?><?= $request['remarks'] ? '<br><small>' . e($request['remarks']) . '</small>' : '' ?></td>
  <td><?= e(fmt_date($request['created_at'])) ?></td>
</tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php render_footer(); ?>
