<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_role('department');
$pdo = db();
$departmentId = (int)($user['department_id'] ?? 0);
if ($departmentId < 1) { http_response_code(403); exit('Your account is not linked to a department.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $gradeId = post_int('grade_id');
    $action = post_str('action', 12);
    $remarks = post_str('remarks', 500);
    if (!in_array($action, ['approve', 'return', 'reject'], true)) {
        flash('error', 'Choose a valid grade review action.');
    } elseif (in_array($action, ['return', 'reject'], true) && $remarks === '') {
        flash('error', 'A remark is required when returning or rejecting a grade.');
    } else {
        $target = $pdo->prepare(
            'SELECT g.id, g.status, g.computed_final, st.user_id, s.code, o.semester, o.academic_year
               FROM grades g
               JOIN enrollments e ON e.id = g.enrollment_id
               JOIN students st ON st.id = e.student_id
               JOIN subject_offerings o ON o.id = e.subject_offering_id
               JOIN subjects s ON s.id = o.subject_id
              WHERE g.id = ? AND s.department_id = ?'
        );
        $target->execute([$gradeId, $departmentId]);
        $grade = $target->fetch();
        if (!$grade || $grade['status'] !== 'submitted') {
            flash('error', 'Only submitted grades from your department can be reviewed.');
        } elseif ($action === 'approve' && $grade['computed_final'] === null) {
            flash('error', 'A grade requires a computed final mark before it can be approved.');
        } else {
            $nextStatus = ['approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected'][$action];
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare(
                    "UPDATE grades g
                     JOIN enrollments e ON e.id = g.enrollment_id
                     JOIN subject_offerings o ON o.id = e.subject_offering_id
                     JOIN subjects s ON s.id = o.subject_id
                        SET g.status = ?, g.remarks = ?, g.reviewed_by = ?, g.reviewed_at = NOW()
                      WHERE g.id = ? AND g.status = 'submitted' AND s.department_id = ?"
                );
                $st->execute([$nextStatus, $remarks ?: null, (int)$user['id'], $gradeId, $departmentId]);
                if ($st->rowCount() !== 1) {
                    $pdo->rollBack();
                    flash('error', 'The grade changed before your review was saved. Reload and review it again.');
                } else {
                    create_notification(
                        (int)$grade['user_id'],
                        $action === 'approve' ? 'Grade posted' : 'Grade review updated',
                        $action === 'approve'
                            ? 'An approved grade for ' . $grade['code'] . ' (' . $grade['semester'] . ' ' . $grade['academic_year'] . ') is now available.'
                            : 'Your grade for ' . $grade['code'] . ' was ' . $nextStatus . '. ' . ($remarks ?: ''),
                        '/student/grades.php'
                    );
                    audit_log('GRADE_' . strtoupper($nextStatus), (int)$user['id'], $user['username'], 'grade', $gradeId, $remarks);
                    $pdo->commit();
                    flash('success', 'Grade ' . $nextStatus . '.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[WLS] department grade review failed: ' . $e->getMessage());
                flash('error', 'The grade decision could not be saved.');
            }
        }
    }
    redirect_self();
}

$st = $pdo->prepare(
    "SELECT g.id, g.prelim, g.midterm, g.final, g.computed_final, g.updated_at,
            e.id AS enrollment_id, st.student_no, st.first_name, st.last_name,
            s.code, s.title, o.section, o.academic_year, o.semester,
            p.first_name AS professor_first_name, p.last_name AS professor_last_name
       FROM grades g
       JOIN enrollments e ON e.id = g.enrollment_id
       JOIN students st ON st.id = e.student_id
       JOIN subject_offerings o ON o.id = e.subject_offering_id
       JOIN subjects s ON s.id = o.subject_id
       JOIN professors p ON p.id = o.professor_id
      WHERE g.status = 'submitted' AND e.status = 'enrolled' AND s.department_id = ?
      ORDER BY o.academic_year DESC, o.semester, s.code, st.last_name, st.first_name"
);
$st->execute([$departmentId]);
$rows = $st->fetchAll();

render_header($user, 'Department grade review');
if (!$rows): ?>
  <div class="card empty">There are no submitted grades awaiting department review.</div>
<?php else: ?>
  <label for="grade-filter">Search submitted grades</label><input id="grade-filter" type="text" data-filter="#department-grade-review" placeholder="Student, subject, professor, or term">
  <div class="tablewrap table-responsive"><table id="department-grade-review"><thead><tr><th>Student</th><th>Subject offering</th><th>Professor</th><th>Marks</th><th>Review</th></tr></thead><tbody>
  <?php foreach ($rows as $row): ?><tr>
    <td><?= e($row['student_no'] . ' - ' . $row['last_name'] . ', ' . $row['first_name']) ?></td>
    <td><?= e($row['code'] . ' - ' . $row['title'] . ' / ' . $row['section'] . ' / ' . $row['academic_year'] . ' ' . $row['semester']) ?></td>
    <td><?= e($row['professor_last_name'] . ', ' . $row['professor_first_name']) ?></td>
    <td>Prelim <?= e((string)($row['prelim'] ?? '-')) ?><br>Midterm <?= e((string)($row['midterm'] ?? '-')) ?><br>Final <?= e((string)($row['final'] ?? '-')) ?><br>Computed <?= e((string)($row['computed_final'] ?? '-')) ?></td>
    <td><button class="btn alt sm" type="button" data-dialog-open="grade-review-<?= (int)$row['id'] ?>" aria-haspopup="dialog">Review</button>
      <dialog class="review-dialog" id="grade-review-<?= (int)$row['id'] ?>" aria-labelledby="grade-review-title-<?= (int)$row['id'] ?>">
        <h2 id="grade-review-title-<?= (int)$row['id'] ?>">Review grade</h2>
        <p><?= e($row['student_no'] . ' - ' . $row['last_name'] . ', ' . $row['first_name'] . ' / ' . $row['code'] . ' ' . $row['section']) ?></p>
        <?= form_open() ?><input type="hidden" name="grade_id" value="<?= (int)$row['id'] ?>">
        <label for="remarks-<?= (int)$row['id'] ?>">Department remarks (required for Return or Reject)</label>
        <textarea id="remarks-<?= (int)$row['id'] ?>" name="remarks" maxlength="500" rows="3"></textarea>
        <div class="actions"><button class="btn ok sm" name="action" value="approve" type="submit">Approve</button>
          <button class="btn alt sm" name="action" value="return" type="submit">Return</button>
          <button class="btn danger sm" name="action" value="reject" type="submit">Reject</button>
          <button class="btn alt sm" type="button" data-dialog-close>Cancel</button></div></form>
      </dialog>
      <noscript><?= form_open() ?><input type="hidden" name="grade_id" value="<?= (int)$row['id'] ?>">
        <label for="remarks-fallback-<?= (int)$row['id'] ?>">Department remarks</label><textarea id="remarks-fallback-<?= (int)$row['id'] ?>" name="remarks" maxlength="500" rows="2"></textarea>
        <div class="actions"><button class="btn ok sm" name="action" value="approve" type="submit">Approve</button>
          <button class="btn alt sm" name="action" value="return" type="submit">Return</button>
          <button class="btn danger sm" name="action" value="reject" type="submit">Reject</button></div></form>
      </noscript>
    </td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
<?php endif;
render_footer();
