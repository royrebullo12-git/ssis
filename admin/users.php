<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_role('admin');
$pdo  = db();

const STAFF = ['registrar', 'cashier', 'department', 'admin'];

function pw_error(string $p): ?string
{
    if (strlen($p) < 10 || strlen($p) > 200) return 'Password must be 10 to 200 characters.';
    if (!preg_match('/[a-z]/', $p) || !preg_match('/[A-Z]/', $p) || !preg_match('/\d/', $p)) return 'Password needs an uppercase letter, a lowercase letter and a digit.';
    return null;
}
function active_admins(PDO $pdo): int { return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 12);
    $id = post_int('id');
    $self = $id === (int)$user['id'];
    $target = null;
    if ($id) { $t = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $t->execute([$id]); $target = $t->fetch() ?: null; }

    try {
        if ($action === 'create') {
            $username = post_str('username', 50); $email = post_str('email', 120); $pw = $_POST['password'] ?? '';
            $role = post_str('role', 12); $dept = post_int('department_id') ?: null;
            $first = post_str('first_name', 60); $last = post_str('last_name', 60); $prog = post_str('program', 100); $year = post_int('year_level') ?: 1;
            $err = null;
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) $err = 'Username: 3 to 50 letters, digits, dots, dashes or underscores.';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
            elseif (!is_string($pw) || ($e1 = pw_error($pw))) $err = $e1 ?? 'Invalid password.';
            elseif (!in_array($role, ALL_ROLES, true)) $err = 'Choose a role.';
            elseif (in_array($role, ['department', 'student'], true) && !$dept) $err = 'Choose a department for this role.';
            elseif ($role === 'student' && ($first === '' || $last === '' || $prog === '' || $year < 1 || $year > 6)) $err = 'Students need a first name, last name, program and year level (1-6). The username is the student number.';
            if ($err) { flash('error', $err); }
            else {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO users (username, email, password_hash, role, department_id) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$username, $email, password_hash($pw, PASSWORD_DEFAULT), $role, in_array($role, ['department'], true) ? $dept : null]);
                $uid = (int)$pdo->lastInsertId();
                if ($role === 'student') {
                    $pdo->prepare('INSERT INTO students (user_id, student_no, first_name, last_name, program, year_level, department_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$uid, $username, $first, $last, $prog, $year, $dept]);
                    $sid = (int)$pdo->lastInsertId();
                    $ins = $pdo->prepare('INSERT INTO clearances (student_id, clearance_type, department_id, school_year, semester) VALUES (?, ?, ?, ?, ?)');
                    foreach ([['registrar', null], ['cashier', null], ['department', $dept]] as [$ct, $cd]) $ins->execute([$sid, $ct, $cd, CURRENT_SY, CURRENT_SEM]);
                }
                $pdo->commit();
                audit_log('USER_CREATED', (int)$user['id'], $user['username'], 'user', $uid, "{$username} as {$role}");
                flash('success', "Account {$username} created. Give the password to the user in person and ask them to keep it private.");
            }
        } elseif (!$target) {
            flash('error', 'Account not found.');
        } elseif ($action === 'toggle') {
            $new = $target['status'] === 'active' ? 'disabled' : 'active';
            if ($self) flash('error', 'You cannot disable your own account.');
            elseif ($new === 'disabled' && $target['role'] === 'admin' && active_admins($pdo) <= 1) flash('error', 'At least one active admin must remain.');
            else {
                $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $id]);
                audit_log('USER_' . strtoupper($new), (int)$user['id'], $user['username'], 'user', $id, $target['username']);
                flash('success', "{$target['username']} is now {$new}.");
            }
        } elseif ($action === 'unlock') {
            $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND success = 0')->execute([$target['username']]);  // clear the throttle too
            audit_log('USER_UNLOCKED', (int)$user['id'], $user['username'], 'user', $id, $target['username']);
            flash('success', "{$target['username']} unlocked.");
        } elseif ($action === 'reset') {
            $pw = $_POST['password'] ?? '';
            if (!is_string($pw) || ($e2 = pw_error($pw))) flash('error', $e2 ?? 'Invalid password.');
            else {
                $pdo->prepare('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND success = 0')->execute([$target['username']]);
                audit_log('PASSWORD_RESET', (int)$user['id'], $user['username'], 'user', $id, $target['username']);
                flash('success', "Password reset for {$target['username']}.");
            }
        } elseif ($action === 'role') {
            $new = post_str('role', 12); $dept = post_int('department_id') ?: null;
            if ($self) flash('error', 'You cannot change your own role.');
            elseif ($target['role'] === 'student' || !in_array($new, STAFF, true)) flash('error', 'Student accounts keep the student role; staff roles can only be changed among staff roles.');
            elseif ($new === 'department' && !$dept) flash('error', 'Choose a department for the department role.');
            elseif ($target['role'] === 'admin' && $new !== 'admin' && active_admins($pdo) <= 1) flash('error', 'At least one active admin must remain.');
            else {
                $pdo->prepare('UPDATE users SET role = ?, department_id = ? WHERE id = ?')->execute([$new, $new === 'department' ? $dept : null, $id]);
                audit_log('ROLE_CHANGED', (int)$user['id'], $user['username'], 'user', $id, "{$target['username']}: {$target['role']} -> {$new}");
                flash('success', "{$target['username']} is now {$new}.");
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') flash('error', 'That username, email or student number is already in use.');
        else { error_log('[SSIS] admin/users: ' . $e->getMessage()); flash('error', 'The change could not be saved.'); }
    }
    redirect_self();
}

$q = get_str('q', 60); $role = get_str('role', 12);
if (!in_array($role, ALL_ROLES, true)) $role = '';
$like = '%' . addcslashes($q, '%_\\') . '%';
$st = $pdo->prepare('SELECT u.*, d.code AS dept, (u.locked_until > NOW()) AS is_locked FROM users u LEFT JOIN departments d ON d.id = u.department_id
                      WHERE (? = "" OR u.username LIKE ? OR u.email LIKE ?) AND (? = "" OR u.role = ?) ORDER BY u.role, u.username LIMIT 200');
$st->execute([$q, $like, $like, $role, $role]);
$rows = $st->fetchAll();
$depts = $pdo->query('SELECT id, code, name FROM departments ORDER BY code')->fetchAll();
$deptOpts = '<option value="">None</option>';
foreach ($depts as $d) $deptOpts .= '<option value="' . (int)$d['id'] . '">' . e($d['code'] . ' - ' . $d['name']) . '</option>';

render_header($user, 'User accounts');
?>
<div class="card"><h2>Create an account</h2>
  <?= form_open('create') ?><div class="row">
    <div><label for="username">Username (student number for students)</label><input id="username" name="username" type="text" maxlength="50" required autocomplete="off"></div>
    <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120" required></div>
    <div><label for="password">Temporary password</label><input id="password" name="password" type="password" maxlength="200" required autocomplete="new-password"></div>
    <div><label for="role">Role</label><select id="role" name="role" required><?php foreach (ALL_ROLES as $r): ?><option value="<?= $r ?>"><?= e(label($r)) ?></option><?php endforeach; ?></select></div>
    <div><label for="department_id">Department (students and department staff)</label><select id="department_id" name="department_id"><?= $deptOpts ?></select></div>
  </div>
  <div class="row">
    <div><label for="first_name">First name (students)</label><input id="first_name" name="first_name" type="text" maxlength="60"></div>
    <div><label for="last_name">Last name (students)</label><input id="last_name" name="last_name" type="text" maxlength="60"></div>
    <div><label for="program">Program (students)</label><input id="program" name="program" type="text" maxlength="100"></div>
    <div><label for="year_level">Year level (students)</label><input id="year_level" name="year_level" type="number" min="1" max="6" value="1"></div>
  </div>
  <p><button class="btn" type="submit">Create account</button> <small>Passwords need 10+ characters with upper case, lower case and a digit.</small></p></form>
</div>
<form class="filters" method="get"><div><label for="q">Search</label><input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="Username or email"></div>
  <div><label for="frole">Role</label><select id="frole" name="role"><option value="">All</option><?php foreach (ALL_ROLES as $r): ?><option value="<?= $r ?>"<?= $r === $role ? ' selected' : '' ?>><?= e(label($r)) ?></option><?php endforeach; ?></select></div>
  <button class="btn" type="submit">Filter</button></form>
<div class="tablewrap"><table><thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Manage</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['username']) ?></td><td><?= e($r['email']) ?></td><td><?= e(label($r['role'])) ?><?= $r['dept'] ? ' <small>(' . e($r['dept']) . ')</small>' : '' ?></td>
    <td><?= badge($r['status']) ?><?= (int)$r['is_locked'] ? ' <span class="badge bad">Locked</span>' : '' ?></td><td><?= e(fmt_date($r['last_login_at'])) ?></td>
    <td><div class="actions">
      <?php if ((int)$r['id'] !== (int)$user['id']): ?>
        <?= form_open('toggle') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn alt sm" type="submit" data-confirm="<?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?> this account?"><?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form>
      <?php endif; ?>
      <?php if ((int)$r['is_locked']): ?><?= form_open('unlock') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn alt sm" type="submit">Unlock</button></form><?php endif; ?>
      <?= form_open('reset') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="password" name="password" placeholder="New password" aria-label="New password" autocomplete="new-password" required><button class="btn alt sm" type="submit">Reset</button></form>
      <?php if ($r['role'] !== 'student' && (int)$r['id'] !== (int)$user['id']): ?>
        <?= form_open('role') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <select name="role" aria-label="Role"><?php foreach (STAFF as $s): ?><option value="<?= $s ?>"<?= $s === $r['role'] ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select>
        <select name="department_id" aria-label="Department"><?= $deptOpts ?></select><button class="btn alt sm" type="submit" data-confirm="Change this user's role?">Set role</button></form>
      <?php endif; ?>
    </div></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php render_footer();
