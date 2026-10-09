<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('registrar');
$pdo = db();

$one = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s=$pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$students = $one('SELECT COUNT(*) FROM students');
$pending = $one("SELECT COUNT(*) FROM students WHERE enrollment_status='pending'");
$active = $one("SELECT COUNT(*) FROM students WHERE enrollment_status='active'");
$archived = $one("SELECT COUNT(*) FROM students WHERE enrollment_status='archived'");
$req = $one("SELECT COUNT(*) FROM document_requests WHERE status IN ('submitted','processing','ready')");
$gr = $one("SELECT COUNT(*) FROM enrollments WHERE status='enrolled'");

$recent = $pdo->query("SELECT s.student_no,s.first_name,s.last_name,s.program,s.enrollment_status,s.created_at
                       FROM students s ORDER BY s.created_at DESC LIMIT 8")->fetchAll();

render_header($user, 'Registrar overview');
?>
<div class="grid">
  <div class="stat"><span class="stat-label">TOTAL STUDENTS</span><b><?= $students ?></b><span>All registered records</span></div>
  <div class="stat"><span class="stat-label">PENDING REGISTRATIONS</span><b><?= $pending ?></b><span>Needs verification</span></div>
  <div class="stat"><span class="stat-label">ACTIVE STUDENTS</span><b><?= $active ?></b><span>Ready for services</span></div>
  <div class="stat"><span class="stat-label">ARCHIVED</span><b><?= $archived ?></b><span>Inactive records</span></div>
</div>
<div class="content-grid">
  <section class="card">
    <div class="section-title"><div><p class="card-kicker">ADMISSIONS</p><h2>Registration workload</h2></div><a class="btn" href="<?= e(url('/registrar/admissions.php')) ?>">Register student</a></div>
    <p>Registrar and Admissions staff can create accounts, student records, and current-term clearance records without waiting for an Admin operator.</p>
    <div class="quick-actions">
      <a href="<?= e(url('/registrar/students.php?status=pending')) ?>"><b><?= $pending ?></b><span>Pending verification</span></a>
      <a href="<?= e(url('/registrar/students.php?status=active')) ?>"><b><?= $active ?></b><span>Active records</span></a>
      <a href="<?= e(url('/registrar/requests.php')) ?>"><b><?= $req ?></b><span>Document requests</span></a>
      <a href="<?= e(url('/registrar/enrollments.php')) ?>"><b><?= $gr ?></b><span>Active enrollments</span></a>
    </div>
  </section>
  <aside class="card">
    <p class="card-kicker">STREAMLINED WORKFLOW</p>
    <h2>Registration → Active</h2>
    <ol class="clean-list">
      <li><b>Register</b><span>Admissions captures verified student information.</span></li>
      <li><b>Verify</b><span>Set status to Active when requirements are complete.</span></li>
      <li><b>Serve</b><span>Student immediately accesses portal services.</span></li>
    </ol>
  </aside>
</div>
<div class="card"><div class="section-title"><div><p class="card-kicker">RECENT</p><h2>Recently registered students</h2></div><a href="<?= e(url('/registrar/students.php')) ?>">View all</a></div>
<div class="tablewrap table-responsive"><table><thead><tr><th>Student</th><th>Program</th><th>Status</th><th>Registered</th></tr></thead><tbody>
<?php foreach($recent as $r): ?><tr><td><strong><?= e($r['student_no']) ?></strong><br><small><?= e($r['last_name'].', '.$r['first_name']) ?></small></td><td><?= e($r['program']) ?></td><td><?= badge($r['enrollment_status']) ?></td><td><?= e(fmt_date($r['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php render_footer();
