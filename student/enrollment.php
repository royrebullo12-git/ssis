<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('student');
$stu = student_of($user);
$unitStatus = student_unit_status($stu);
$classes = db()->prepare(
    'SELECT sub.code, sub.title, o.section, o.academic_year, o.semester, o.schedule,
            CONCAT(p.first_name, \' \', p.last_name) AS professor, e.status
       FROM enrollments e
       JOIN subject_offerings o ON o.id = e.subject_offering_id
       JOIN subjects sub ON sub.id = o.subject_id
       JOIN professors p ON p.id = o.professor_id
      WHERE e.student_id = ?
      ORDER BY o.academic_year DESC, o.semester, sub.code'
);
$classes->execute([(int)$stu['id']]);
$classes = $classes->fetchAll();
render_header($user, 'Enrollment status');
?>
<div class="content-grid">
  <section class="card">
    <p class="card-kicker">CURRENT STATUS</p>
    <div class="large-status"><?= badge($stu['enrollment_status']) ?></div>
    <?php if ($stu['enrollment_status'] === 'active'): ?>
      <h2>Your student record is active</h2>
      <p>Your registration is active for the current student services cycle. You can use grades, clearance, and document services from the portal.</p>
    <?php elseif ($stu['enrollment_status'] === 'pending'): ?>
      <h2>Registration is being verified</h2>
      <p>Your student record has been created, but the Registrar still needs to complete verification.</p>
    <?php else: ?>
      <h2>Record archived</h2>
      <p>This student record is archived. Contact the Registrar if you believe it should be active.</p>
    <?php endif; ?>
  </section>
  <aside class="card">
    <p class="card-kicker">STUDENT RECORD</p>
    <dl class="details">
      <dt>Student number</dt><dd><?= e($stu['student_no']) ?></dd>
      <dt>Program</dt><dd><?= e($stu['program']) ?></dd>
      <dt>Year level</dt><dd><?= (int)$stu['year_level'] ?></dd>
      <dt>Department</dt><dd><?= e($stu['department_name']) ?></dd>
      <dt>Current term standing</dt><dd><?= badge($unitStatus['status']) ?></dd>
      <dt>Enrolled / required units</dt><dd><?= $unitStatus['enrolled_units'] ?><?= $unitStatus['required_units'] !== null ? ' / ' . $unitStatus['required_units'] : ' / Curriculum not configured' ?></dd>
    </dl>
  </aside>
</div>
<?php if ($unitStatus['status'] === 'unconfigured'): ?><div class="msg info" role="status">Your regular/irregular standing will appear once the Registrar configures your program's standard units for the current term.</div><?php endif; ?>
<div class="card"><h2>Registered classes</h2>
  <?php if (!$classes): ?><p class="empty">No subject offerings are currently linked to your student record.</p>
  <?php else: ?><div class="tablewrap table-responsive"><table><thead><tr><th>Subject</th><th>Section</th><th>Term</th><th>Schedule</th><th>Professor</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($classes as $class): ?><tr>
      <td><?= e($class['code'] . ' - ' . $class['title']) ?></td><td><?= e($class['section']) ?></td>
      <td><?= e($class['academic_year'] . ', ' . $class['semester']) ?></td>
      <td><?= e($class['schedule'] ?? '-') ?></td><td><?= e($class['professor']) ?></td><td><?= badge($class['status']) ?></td>
    </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php render_footer();
