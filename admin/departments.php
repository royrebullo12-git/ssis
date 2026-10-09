<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $code = strtoupper(post_str('code', 10));
    $name = post_str('name', 120);
    if (!preg_match('/^[A-Z0-9]{2,10}$/', $code) || $name === '') {
        flash('error', 'Enter a 2–10 character department code using letters/numbers and a department name.');
    } else {
        try {
            $pdo->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute([$code, $name]);
            audit_log('DEPARTMENT_CREATED', (int)$user['id'], $user['username'], 'department', (int)$pdo->lastInsertId(), $code . ' ' . $name);
            flash('success', 'Department added to the university directory.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') flash('error', 'That department code is already in use.');
            else { error_log('[WLS] department creation failed: ' . $e->getMessage()); flash('error', 'The department could not be added.'); }
        }
    }
    redirect_self();
}

$departments = $pdo->query(
    'SELECT d.id, d.code, d.name, COUNT(DISTINCT s.id) AS student_count,
            COUNT(DISTINCT p.id) AS faculty_count
       FROM departments d
       LEFT JOIN students s ON s.department_id = d.id
       LEFT JOIN professors p ON p.department_id = d.id
      GROUP BY d.id, d.code, d.name ORDER BY d.code'
)->fetchAll();
render_header($user, 'Department directory');
?>
<section class="card">
  <p class="card-kicker">UNIVERSITY DIRECTORY</p><h2>Add a department or issuing office</h2>
  <p class="muted">Create department records before assigning department-issued document types or provisioning department portal accounts.</p>
  <?= form_open('create') ?>
    <div class="form-grid">
      <div><label for="code">Department code</label><input id="code" name="code" maxlength="10" pattern="[A-Za-z0-9]{2,10}" required placeholder="GUIDE"></div>
      <div><label for="name">Department name</label><input id="name" name="name" maxlength="120" required placeholder="Guidance Office"></div>
    </div>
    <p class="form-actions"><button class="btn" type="submit">Add department</button></p>
  </form>
</section>
<section class="card">
  <h2>Departments and offices</h2>
  <?php if (!$departments): ?><div class="empty">No departments have been configured.</div><?php else: ?>
  <div class="tablewrap table-responsive"><table><thead><tr><th>Code</th><th>Department / office</th><th>Students</th><th>Faculty profiles</th></tr></thead><tbody>
    <?php foreach ($departments as $department): ?><tr>
      <td><?= e($department['code']) ?></td><td><?= e($department['name']) ?></td>
      <td><?= (int)$department['student_count'] ?></td><td><?= (int)$department['faculty_count'] ?></td>
    </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
