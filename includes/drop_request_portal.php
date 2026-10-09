<?php
declare(strict_types=1);

if (!defined('SSIS_BOOT') || !isset($user)) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

$pdo = db();
$isDepartment = $user['role'] === 'department';
$departmentId = (int)($user['department_id'] ?? 0);
if ($isDepartment && $departmentId < 1) {
    http_response_code(403);
    exit('Your account is not linked to a department.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $requestId = post_int('id');
    $action = post_str('action', 10);
    $remarks = post_str('remarks', 255);
    if (!in_array($action, ['approve', 'reject'], true) || ($action === 'reject' && $remarks === '')) {
        flash('error', 'Choose a valid decision and provide remarks when rejecting a request.');
        redirect_self();
    }
    $pdo->beginTransaction();
    try {
        $scope = $isDepartment ? ' AND dr.department_id = ?' : '';
        $params = $isDepartment ? [$requestId, $departmentId] : [$requestId];
        $statement = $pdo->prepare(
            "SELECT dr.*, e.status AS enrollment_status, e.student_id, s.user_id AS student_user_id,
                    sub.code, sub.title, o.section
               FROM drop_requests dr
               JOIN enrollments e ON e.id = dr.enrollment_id
               JOIN students s ON s.id = dr.student_id
               JOIN subject_offerings o ON o.id = e.subject_offering_id
               JOIN subjects sub ON sub.id = o.subject_id
              WHERE dr.id = ? AND dr.status = 'submitted'{$scope} FOR UPDATE"
        );
        $statement->execute($params);
        $request = $statement->fetch();
        if (!$request) {
            $pdo->rollBack();
            flash('error', 'That pending course drop request was not found in your review queue.');
        } elseif ($action === 'approve' && $request['enrollment_status'] !== 'enrolled') {
            $pdo->rollBack();
            flash('error', 'The enrollment is no longer active, so the request cannot be approved.');
        } else {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            if ($action === 'approve') {
                $pdo->prepare("UPDATE enrollments SET status = 'dropped' WHERE id = ? AND status = 'enrolled'")
                    ->execute([(int)$request['enrollment_id']]);
            }
            $pdo->prepare('UPDATE drop_requests SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
                ->execute([$newStatus, $remarks ?: null, (int)$user['id'], $requestId]);
            create_notification(
                (int)$request['student_user_id'],
                'Course drop request ' . $newStatus,
                'Your request for ' . $request['code'] . ' was ' . $newStatus . '.' . ($remarks !== '' ? ' ' . $remarks : ''),
                '/student/requests.php'
            );
            $pdo->commit();
            audit_log('COURSE_DROP_' . strtoupper($newStatus), (int)$user['id'], $user['username'], 'drop_request', $requestId);
            flash('success', 'Course drop request ' . $newStatus . '.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[WLS] drop request review failed: ' . $e->getMessage());
        flash('error', 'The course drop request could not be reviewed.');
    }
    redirect_self();
}

$status = get_str('status', 12);
if (!in_array($status, ['submitted', 'approved', 'rejected', 'all'], true)) {
    $status = 'submitted';
}
$where = ['1 = 1'];
$params = [];
if ($isDepartment) {
    $where[] = 'dr.department_id = ?';
    $params[] = $departmentId;
}
if ($status !== 'all') {
    $where[] = 'dr.status = ?';
    $params[] = $status;
}
$statement = $pdo->prepare(
    'SELECT dr.*, s.student_no, s.first_name, s.last_name, sub.code, sub.title, o.section,
            d.name AS department_name
       FROM drop_requests dr
       JOIN students s ON s.id = dr.student_id
       JOIN enrollments e ON e.id = dr.enrollment_id
       JOIN subject_offerings o ON o.id = e.subject_offering_id
       JOIN subjects sub ON sub.id = o.subject_id
       JOIN departments d ON d.id = dr.department_id
      WHERE ' . implode(' AND ', $where) . ' ORDER BY dr.created_at DESC LIMIT 200'
);
$statement->execute($params);
$requests = $statement->fetchAll();

render_header($user, 'Course drop requests');
?>
<form class="filters card" method="get">
  <div><label for="status">Request status</label><select id="status" name="status">
    <?php foreach (['submitted', 'approved', 'rejected', 'all'] as $option): ?><option value="<?= e($option) ?>"<?= $status === $option ? ' selected' : '' ?>><?= e(label($option)) ?></option><?php endforeach; ?>
  </select></div><button class="btn" type="submit">Filter requests</button>
</form>
<?php if (!$requests): ?><div class="card empty">No course drop requests match this view.</div><?php else: ?>
<div class="tablewrap table-responsive"><table><thead><tr><th>Student</th><th>Subject</th><th>Reason</th><th>Department</th><th>Status</th><th>Decision</th></tr></thead><tbody>
<?php foreach ($requests as $request): ?>
  <tr><td><?= e($request['student_no'] . ' · ' . $request['last_name'] . ', ' . $request['first_name']) ?></td>
    <td><?= e($request['code'] . ' - ' . $request['title'] . ' / ' . $request['section']) ?></td>
    <td><?= e($request['reason']) ?><?= $request['details'] ? '<br><small>' . e($request['details']) . '</small>' : '' ?></td>
    <td><?= e($request['department_name']) ?></td><td><?= badge($request['status']) ?><?= $request['remarks'] ? '<br><small>' . e($request['remarks']) . '</small>' : '' ?></td>
    <td><?php if ($request['status'] === 'submitted'): ?>
      <?= form_open() ?><input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
        <label class="sr-only" for="remarks-<?= (int)$request['id'] ?>">Remarks</label>
        <input id="remarks-<?= (int)$request['id'] ?>" name="remarks" type="text" maxlength="255" placeholder="Remarks (required to reject)">
        <button class="btn ok sm" name="action" value="approve" type="submit" data-confirm="Approve and drop this enrollment?">Approve drop</button>
        <button class="btn danger sm" name="action" value="reject" type="submit">Reject</button>
      </form>
    <?php else: ?><?= e(fmt_date($request['reviewed_at'])) ?><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; render_footer(); ?>
