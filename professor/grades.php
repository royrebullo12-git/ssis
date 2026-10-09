<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('professor');
$pdo = db();
$professorStmt = $pdo->prepare("SELECT * FROM professors WHERE user_id = ? AND status = 'active' LIMIT 1");
$professorStmt->execute([(int)$user['id']]);
$professor = $professorStmt->fetch();
if (!$professor) { http_response_code(403); exit('Your account is not linked to an active professor profile. Contact your Department.'); }
$professorId = (int)$professor['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $enrollmentId = post_int('enrollment_id');
    $action = post_str('action', 12);
    $input = [];
    $invalid = false;
    foreach (['prelim', 'midterm', 'final'] as $field) {
        $raw = post_str($field, 8);
        if ($raw === '') $input[$field] = null;
        elseif (!is_numeric($raw) || (float)$raw < 1.00 || (float)$raw > 5.00) $invalid = true;
        else $input[$field] = round((float)$raw, 2);
    }
    if (!in_array($action, ['save_draft', 'submit'], true) || $invalid) {
        flash('error', 'Choose a valid action and enter marks between 1.00 and 5.00.');
        redirect_self();
    }
    if ($action === 'submit' && in_array(null, $input, true)) {
        flash('error', 'All three marks are required before submitting grades to the Department.');
        redirect_self();
    }

    $pdo->beginTransaction();
    try {
        $enrollment = $pdo->prepare(
            "SELECT e.id
               FROM enrollments e
               JOIN subject_offerings o ON o.id = e.subject_offering_id
              WHERE e.id = ? AND o.professor_id = ? AND e.status = 'enrolled'
              FOR UPDATE"
        );
        $enrollment->execute([$enrollmentId, $professorId]);
        if (!$enrollment->fetchColumn()) {
            $pdo->rollBack();
            audit_log('ACCESS_DENIED', (int)$user['id'], $user['username'], 'enrollment', $enrollmentId, 'Grade submission outside assigned class');
            http_response_code(403);
            exit('You cannot encode grades for this enrollment.');
        }
        $existingStmt = $pdo->prepare('SELECT id, status FROM grades WHERE enrollment_id = ? FOR UPDATE');
        $existingStmt->execute([$enrollmentId]);
        $existing = $existingStmt->fetch();
        if ($existing && !in_array($existing['status'], ['draft', 'returned', 'rejected'], true)) {
            $pdo->rollBack();
            flash('error', 'Submitted and approved grades are locked. Returned or rejected grades can be corrected.');
            redirect_self();
        }
        $computed = count(array_filter($input, static fn(?float $mark): bool => $mark !== null)) === 3
            ? round(array_sum($input) / 3, 2)
            : null;
        $status = $action === 'submit' ? 'submitted' : 'draft';
        if ($existing) {
            $save = $pdo->prepare(
                'UPDATE grades SET prelim = ?, midterm = ?, final = ?, computed_final = ?, status = ?, encoded_by = ?
                  WHERE id = ?'
            );
            $save->execute([$input['prelim'], $input['midterm'], $input['final'], $computed, $status, (int)$user['id'], (int)$existing['id']]);
            $gradeId = (int)$existing['id'];
        } else {
            $save = $pdo->prepare(
                'INSERT INTO grades (enrollment_id, prelim, midterm, final, computed_final, status, encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $save->execute([$enrollmentId, $input['prelim'], $input['midterm'], $input['final'], $computed, $status, (int)$user['id']]);
            $gradeId = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        audit_log('GRADE_' . strtoupper($status), (int)$user['id'], $user['username'], 'grade', $gradeId);
        flash('success', $status === 'submitted' ? 'Grades submitted to your Department for review.' : 'Draft saved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[SSIS] professor/grades: ' . $e->getMessage());
        flash('error', 'The grade could not be saved.');
    }
    redirect_self();
}

$offeringId = get_int('offering');
$offeringsStmt = $pdo->prepare(
    'SELECT o.id, o.academic_year, o.semester, o.section, s.code, s.title
       FROM subject_offerings o JOIN subjects s ON s.id = o.subject_id
      WHERE o.professor_id = ? ORDER BY o.academic_year DESC, o.semester, s.code, o.section'
);
$offeringsStmt->execute([$professorId]);
$offerings = $offeringsStmt->fetchAll();
$rows = [];
if ($offeringId) {
    $roster = $pdo->prepare(
        "SELECT e.id AS enrollment_id, st.student_no, st.first_name, st.last_name,
                g.id AS grade_id, g.prelim, g.midterm, g.final, g.computed_final,
                g.status AS grade_status, g.remarks
           FROM subject_offerings o
           JOIN enrollments e ON e.subject_offering_id = o.id AND e.status = 'enrolled'
           JOIN students st ON st.id = e.student_id
           LEFT JOIN grades g ON g.enrollment_id = e.id
          WHERE o.id = ? AND o.professor_id = ?
          ORDER BY st.last_name, st.first_name"
    );
    $roster->execute([$offeringId, $professorId]);
    $rows = $roster->fetchAll();
}

render_header($user, 'Class roster and grade encoding');
?>
<form class="filters" method="get"><div><label for="offering">Assigned offering</label><select id="offering" name="offering" required>
  <option value="">Choose an offering</option>
  <?php foreach ($offerings as $offering): ?><option value="<?= (int)$offering['id'] ?>"<?= (int)$offering['id'] === $offeringId ? ' selected' : '' ?>><?= e($offering['code'] . ' - ' . $offering['title'] . ' / ' . $offering['academic_year'] . ' ' . $offering['semester'] . ' / ' . $offering['section']) ?></option><?php endforeach; ?>
</select></div><button class="btn" type="submit">View roster</button></form>
<?php if ($offeringId && !$rows): ?><div class="card empty">No enrolled students in this offering, or this offering is not assigned to your profile.</div>
<?php elseif ($rows): ?><label for="roster-filter">Search roster</label><input id="roster-filter" type="text" data-filter="#professor-roster" placeholder="Student number or name">
<div class="tablewrap table-responsive"><table id="professor-roster"><thead><tr><th>Student</th><th>Prelim</th><th>Midterm</th><th>Final</th><th>Computed</th><th>Status / feedback</th><th>Save / submit</th></tr></thead><tbody>
<?php foreach ($rows as $row):
    $status = $row['grade_status'] ?? 'draft';
    $locked = in_array($status, ['submitted', 'approved'], true);
    $formId = 'grade-form-' . (int)$row['enrollment_id'];
?>
  <tr><td><?= e($row['student_no'] . ' - ' . $row['last_name'] . ', ' . $row['first_name']) ?></td>
    <?php foreach (['prelim' => 'Prelim', 'midterm' => 'Midterm', 'final' => 'Final'] as $field => $caption): ?>
      <td><label class="sr-only" for="<?= $field ?>-<?= (int)$row['enrollment_id'] ?>"><?= $caption ?> (1.00 to 5.00)</label>
        <input id="<?= $field ?>-<?= (int)$row['enrollment_id'] ?>" form="<?= e($formId) ?>" name="<?= $field ?>" type="number" step="0.01" min="1" max="5" value="<?= e((string)($row[$field] ?? '')) ?>"<?= $locked ? ' disabled' : '' ?>></td>
    <?php endforeach; ?>
    <td><?= $row['computed_final'] === null ? '-' : e(number_format((float)$row['computed_final'], 2)) ?></td>
    <td><?= badge($status) ?><?php if ($row['remarks']): ?><p class="msg info">Department feedback: <?= e($row['remarks']) ?></p><?php endif; ?></td>
    <td><?php if (!$locked): ?><?= form_open('', 'id="' . e($formId) . '"') ?><input type="hidden" name="enrollment_id" value="<?= (int)$row['enrollment_id'] ?>">
      <div class="actions"><button class="btn alt sm" name="action" value="save_draft" type="submit">Save draft</button>
        <button class="btn sm" name="action" value="submit" type="submit">Submit</button></div></form>
    <?php else: ?><small>Locked pending Department review.</small><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php render_footer();
