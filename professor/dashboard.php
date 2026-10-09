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

$st = $pdo->prepare(
    "SELECT o.id, o.academic_year, o.semester, o.section, o.schedule,
            s.code, s.title,
            COUNT(DISTINCT e.id) AS student_count,
            SUM(CASE WHEN g.status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count
       FROM subject_offerings o
       JOIN subjects s ON s.id = o.subject_id
       LEFT JOIN enrollments e ON e.subject_offering_id = o.id AND e.status = 'enrolled'
       LEFT JOIN grades g ON g.enrollment_id = e.id
      WHERE o.professor_id = ?
      GROUP BY o.id, o.academic_year, o.semester, o.section, o.schedule, s.code, s.title
      ORDER BY o.academic_year DESC, o.semester, s.code, o.section"
);
$st->execute([(int)$professor['id']]);
$offerings = $st->fetchAll();

render_header($user, 'Professor overview');
?>
<p class="muted">Your assigned subject offerings and grade workload.</p>
<?php if (!$offerings): ?><div class="card empty">No subject offerings are assigned to your profile.</div>
<?php else: ?><div class="card"><h2>Assigned classes</h2><div class="tablewrap table-responsive"><table><thead><tr><th>Subject</th><th>Term / section</th><th>Schedule</th><th>Students</th><th>Submitted for review</th><th></th></tr></thead><tbody>
  <?php foreach ($offerings as $offering): ?><tr>
    <td><?= e($offering['code'] . ' - ' . $offering['title']) ?></td>
    <td><?= e($offering['academic_year'] . ', ' . $offering['semester'] . ' / ' . $offering['section']) ?></td>
    <td><?= e($offering['schedule'] ?? '-') ?></td><td><?= (int)$offering['student_count'] ?></td><td><?= (int)$offering['submitted_count'] ?></td>
    <td><a class="btn sm" href="<?= e(url('/professor/grades.php?offering=' . (int)$offering['id'])) ?>">Open class roster</a></td>
  </tr><?php endforeach; ?>
</tbody></table></div></div><?php endif; ?>
<?php render_footer();
