<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('department');
$pdo = db();
$departmentId = (int)($user['department_id'] ?? 0);
if ($departmentId < 1) {
    http_response_code(403);
    exit('Your account is not linked to a department.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 24);
    try {
        if ($action === 'save_subject') {
            $id = post_int('id');
            $code = strtoupper(post_str('code', 15));
            $title = post_str('title', 120);
            $units = post_int('units');
            if (!preg_match('/^[A-Z0-9-]{2,15}$/', $code) || $title === '' || $units < 1 || $units > 30) {
                flash('error', 'Enter a valid subject code, title, and units (1-30).');
            } elseif ($id) {
                $update = $pdo->prepare('UPDATE subjects SET code = ?, title = ?, units = ? WHERE id = ? AND department_id = ?');
                $update->execute([$code, $title, $units, $id, $departmentId]);
                flash($update->rowCount() ? 'success' : 'error', $update->rowCount() ? 'Subject saved.' : 'Subject not found or unchanged.');
            } else {
                $pdo->prepare('INSERT INTO subjects (code, title, units, department_id) VALUES (?, ?, ?, ?)')
                    ->execute([$code, $title, $units, $departmentId]);
                flash('success', 'Subject created.');
            }
        } elseif ($action === 'toggle_subject') {
            $update = $pdo->prepare('UPDATE subjects SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND department_id = ?');
            $update->execute([post_int('id'), $departmentId]);
            flash($update->rowCount() ? 'success' : 'error', $update->rowCount() ? 'Subject status updated.' : 'Subject not found.');
        } else {
            flash('error', 'Unknown academic-management action.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'That subject code already exists.');
        } else {
            error_log('[WLS] department academic setup failed: ' . $e->getMessage());
            flash('error', 'The subject could not be saved.');
        }
    }
    redirect_self();
}

$statement = $pdo->prepare('SELECT * FROM subjects WHERE department_id = ? ORDER BY code');
$statement->execute([$departmentId]);
$subjects = $statement->fetchAll();
render_header($user, 'Department academic setup');
?>
<div class="toolbar">
  <p class="muted">Manage the subject catalog for your department. Faculty profiles and instructor assignments are managed separately.</p>
  <a class="btn gold" href="<?= e(url('/department/faculty.php')) ?>">Manage faculty and assignments</a>
</div>
<section class="card">
  <h2>Create a subject</h2>
  <?= form_open('save_subject', 'class="department-subject-form"') ?>
    <div class="form-grid form-grid-3 department-subject-fields">
      <div><label for="code">Subject code</label><input id="code" name="code" type="text" maxlength="15" required></div>
      <div><label for="title">Title</label><input id="title" name="title" type="text" maxlength="120" required></div>
      <div><label for="units">Units</label><input id="units" name="units" type="number" min="1" max="30" value="3" required></div>
      <div class="department-subject-submit"><button class="btn" type="submit">Create subject</button></div>
    </div>
  </form>
</section>
<section class="card">
  <h2>Department subjects</h2>
  <?php if (!$subjects): ?><div class="empty">No subjects have been added to this department.</div><?php else: ?>
  <div class="tablewrap table-responsive"><table><thead><tr><th>Code</th><th>Title</th><th>Units</th><th>Status</th><th>Update</th><th></th></tr></thead><tbody>
  <?php foreach ($subjects as $subject): ?><tr>
    <td><?= e($subject['code']) ?></td><td><?= e($subject['title']) ?></td><td><?= (int)$subject['units'] ?></td>
    <td><?= badge((int)$subject['is_active'] ? 'active' : 'inactive') ?></td>
    <td><?= form_open('save_subject') ?><input type="hidden" name="id" value="<?= (int)$subject['id'] ?>">
      <input type="text" name="code" value="<?= e($subject['code']) ?>" aria-label="Subject code" required>
      <input type="text" name="title" value="<?= e($subject['title']) ?>" aria-label="Subject title" required>
      <input type="number" name="units" min="1" max="30" value="<?= (int)$subject['units'] ?>" aria-label="Units" required>
      <button class="btn alt sm" type="submit">Save</button></form></td>
    <td><?= form_open('toggle_subject') ?><input type="hidden" name="id" value="<?= (int)$subject['id'] ?>">
      <button class="btn alt sm" type="submit"><?= (int)$subject['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
  </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
