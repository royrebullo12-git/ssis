<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $studentId = post_int('student_id');
    $offeringId = post_int('subject_offering_id');
    try {
        $pdo->beginTransaction();
        $student = $pdo->prepare("SELECT id FROM students WHERE id = ? AND enrollment_status = 'active' FOR UPDATE");
        $student->execute([$studentId]);
        $offering = $pdo->prepare(
            "SELECT o.subject_id, o.academic_year, o.semester
               FROM subject_offerings o
               JOIN subjects s ON s.id = o.subject_id
               JOIN professors p ON p.id = o.professor_id
              WHERE o.id = ? AND o.is_active = 1 AND s.is_active = 1 AND p.status = 'active'"
        );
        $offering->execute([$offeringId]);
        $offeringRow = $offering->fetch();
        if (!$student->fetchColumn() || !$offeringRow) {
            $pdo->rollBack();
            flash('error', 'Choose an active student and a valid active subject offering.');
        } else {
            $duplicate = $pdo->prepare(
                "SELECT COUNT(*) FROM enrollments e
                 JOIN subject_offerings o ON o.id = e.subject_offering_id
                 WHERE e.student_id = ? AND o.subject_id = ?
                   AND o.academic_year = ? AND o.semester = ?
                   AND e.status IN ('pending','enrolled')"
            );
            $duplicate->execute([$studentId, $offeringRow['subject_id'], $offeringRow['academic_year'], $offeringRow['semester']]);
            if ((int)$duplicate->fetchColumn() > 0) {
                $pdo->rollBack();
                flash('error', 'This student is already enrolled in the subject for that term.');
            } else {
                $pdo->prepare(
                    "INSERT INTO enrollments (student_id, subject_offering_id, status) VALUES (?, ?, 'enrolled')
                     ON DUPLICATE KEY UPDATE status = 'enrolled'"
                )
                    ->execute([$studentId, $offeringId]);
                $idStmt = $pdo->prepare('SELECT id FROM enrollments WHERE student_id = ? AND subject_offering_id = ?');
                $idStmt->execute([$studentId, $offeringId]);
                $enrollmentId = (int)$idStmt->fetchColumn();
                $pdo->commit();
                audit_log('ENROLLMENT_CREATED', (int)$user['id'], $user['username'], 'enrollment', $enrollmentId);
                flash('success', 'Student enrolled in the selected offering.');
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') flash('error', 'This student is already enrolled in that offering.');
        else { error_log('[SSIS] registrar/enrollments: ' . $e->getMessage()); flash('error', 'Enrollment could not be saved.'); }
    }
    redirect_self();
}

$students = $pdo->query(
    "SELECT id, student_no, first_name, last_name FROM students
      WHERE enrollment_status = 'active' ORDER BY last_name, first_name"
)->fetchAll();
$offerings = $pdo->query(
    "SELECT o.id, s.code, s.title, o.academic_year, o.semester, o.section,
            CONCAT(p.first_name, ' ', p.last_name) AS professor
       FROM subject_offerings o
       JOIN subjects s ON s.id = o.subject_id
       JOIN professors p ON p.id = o.professor_id
      WHERE o.is_active = 1 AND s.is_active = 1 AND p.status = 'active'
      ORDER BY o.academic_year DESC, o.semester, s.code, o.section"
)->fetchAll();
$rows = $pdo->query(
    "SELECT e.status, e.enrolled_at, s.student_no, s.first_name, s.last_name,
            sub.code, sub.title, o.academic_year, o.semester, o.section,
            CONCAT(p.first_name, ' ', p.last_name) AS professor
       FROM enrollments e
       JOIN students s ON s.id = e.student_id
       JOIN subject_offerings o ON o.id = e.subject_offering_id
       JOIN subjects sub ON sub.id = o.subject_id
       JOIN professors p ON p.id = o.professor_id
      ORDER BY e.enrolled_at DESC LIMIT 200"
)->fetchAll();

render_header($user, 'Enrollment processing');
?>
<div class="card"><h2>Enroll a student in an offering</h2>
  <?php if (!$students || !$offerings): ?><p class="empty">An active student and an active department offering are required before enrollment.</p>
  <?php else: ?>
  <?= form_open() ?><div class="row">
    <div><label for="student_id">Student</label><select id="student_id" name="student_id" required>
      <?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['student_no'] . ' - ' . $s['last_name'] . ', ' . $s['first_name']) ?></option><?php endforeach; ?>
    </select></div>
    <div><label for="subject_offering_id">Subject offering</label><select id="subject_offering_id" name="subject_offering_id" required>
      <?php foreach ($offerings as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['code'] . ' - ' . $o['title'] . ' / ' . $o['academic_year'] . ' ' . $o['semester'] . ' / ' . $o['section'] . ' / ' . $o['professor']) ?></option><?php endforeach; ?>
    </select></div>
  </div><p><button class="btn" type="submit">Enroll student</button></p></form>
  <?php endif; ?>
</div>
<div class="card"><h2>Recent enrollments</h2>
  <label for="enrollment-filter">Search enrollments</label><input id="enrollment-filter" type="text" data-filter="#registrar-enrollments" placeholder="Student, subject, section, or term">
  <div class="tablewrap"><table id="registrar-enrollments"><thead><tr><th>Student</th><th>Subject offering</th><th>Professor</th><th>Term</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td><?= e($r['student_no'] . ' - ' . $r['last_name'] . ', ' . $r['first_name']) ?></td>
    <td><?= e($r['code'] . ' - ' . $r['title'] . ' / ' . $r['section']) ?></td><td><?= e($r['professor']) ?></td>
    <td><?= e($r['academic_year'] . ', ' . $r['semester']) ?></td><td><?= badge($r['status']) ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php render_footer();
