<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_role('admin');
$pdo  = db();

const STAFF = ['registrar', 'cashier', 'department', 'professor', 'admin'];

function pw_error(string $p): ?string
{
    return password_error($p);
}
function active_admins(PDO $pdo): int { return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 12);
    $id = post_int('id');
    $self = $id === (int)$user['id'];
    $target = null;
    $studentNumberYear = null;
    if ($id) { $t = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $t->execute([$id]); $target = $t->fetch() ?: null; }

    try {
        if ($action === 'create') {
            $username = post_str('username', 50); $email = post_str('email', 120);
            $phone = preg_replace('/[\s()-]/', '', post_str('mobile_phone', 20)) ?? '';
            $role = post_str('role', 12); $dept = post_int('department_id') ?: null;
            $first = post_str('first_name', 60); $last = post_str('last_name', 60); $prog = post_str('program', 100); $year = post_int('year_level') ?: 1;
            $admissionYear = post_str('admission_year', 9);
            $err = null;
            if (!in_array($role, ALL_ROLES, true)) $err = 'Choose a role.';
            elseif ($role !== 'student' && !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) $err = 'Username: 3 to 50 letters, digits, dots, dashes or underscores.';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
            elseif (!preg_match('/^\+?[0-9]{8,15}$/', $phone)) $err = 'Enter a valid mobile number (8 to 15 digits, optionally with a country code).';
            elseif (in_array($role, ['department', 'professor', 'student'], true) && !$dept) $err = 'Choose a department for this role.';
            elseif ($role === 'student' && ($first === '' || $last === '' || $prog === '' || $year < 1 || $year > 6)) $err = 'Students need a first name, last name, program and year level (1-6).';
            elseif ($role === 'student' && $admissionYear !== '' && !preg_match('/^\d{4}-\d{4}$/', $admissionYear)) $err = 'Admission school year must use YYYY-YYYY.';
            elseif ($role === 'professor' && ($first === '' || $last === '')) $err = 'Professors need a first and last name.';
            if ($err) { flash('error', $err); }
            else {
                $pw = temporary_password();
                $pdo->beginTransaction();
                if ($role === 'student') {
                    $studentNumberYear = $admissionYear !== '' ? $admissionYear : CURRENT_SY;
                    $username = next_student_number($pdo, $studentNumberYear);
                }
                $pdo->prepare('INSERT INTO users (username, email, mobile_phone, password_hash, must_change_password, role, department_id) VALUES (?, ?, ?, ?, 1, ?, ?)')
                    ->execute([$username, $email, $phone, password_hash($pw, PASSWORD_DEFAULT), $role, in_array($role, ['department', 'professor'], true) ? $dept : null]);
                $uid = (int)$pdo->lastInsertId();
                if ($role === 'professor') {
                    $profile = $pdo->prepare('SELECT id FROM professors WHERE user_id IS NULL AND department_id = ? AND email = ? ORDER BY id LIMIT 1 FOR UPDATE');
                    $profile->execute([$dept, $email]);
                    $professorId = $profile->fetchColumn();
                    if ($professorId) {
                        $pdo->prepare("UPDATE professors SET user_id = ?, first_name = ?, last_name = ?, status = 'active' WHERE id = ?")
                            ->execute([$uid, $first, $last, $professorId]);
                    } else {
                        $pdo->prepare('INSERT INTO professors (user_id, department_id, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$uid, $dept, $first, $last, $email]);
                    }
                }
                if ($role === 'student') {
                    $pdo->prepare('INSERT INTO students (user_id, student_no, first_name, last_name, program, year_level, admission_year, department_id, contact_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$uid, $username, $first, $last, $prog, $year, $admissionYear ?: null, $dept, $phone]);
                    $sid = (int)$pdo->lastInsertId();
                    $ins = $pdo->prepare('INSERT INTO clearances (student_id, clearance_type, department_id, school_year, semester) VALUES (?, ?, ?, ?, ?)');
                    foreach ([['registrar', null], ['cashier', null], ['department', $dept]] as [$ct, $cd]) $ins->execute([$sid, $ct, $cd, CURRENT_SY, CURRENT_SEM]);
                }
                $pdo->commit();
                audit_log('USER_CREATED', (int)$user['id'], $user['username'], 'user', $uid, "{$username} as {$role}");
                $delivery = credential_delivery($email, $phone, $username, $pw);
                if ($delivery['sms_sent']) {
                    flash('success', "Account {$username} created; temporary credentials were sent by SMS.");
                } else {
                    flash('error', "Account {$username} was created, but SMS delivery failed. Check the registered mobile number and TextBee configuration, then use Send new credentials.");
                }
            }
        } elseif (!$target) {
            flash('error', 'Account not found.');
        } elseif ($action === 'toggle') {
            $new = $target['status'] === 'active' ? 'disabled' : 'active';
            if ($self) flash('error', 'You cannot disable your own account.');
            elseif ($new === 'disabled' && $target['role'] === 'admin' && active_admins($pdo) <= 1) flash('error', 'At least one active admin must remain.');
            else {
                $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $id]);
                if ($target['role'] === 'professor') {
                    $pdo->prepare('UPDATE professors SET status = ? WHERE user_id = ?')
                        ->execute([$new === 'active' ? 'active' : 'inactive', $id]);
                }
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
                $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND success = 0')->execute([$target['username']]);
                audit_log('PASSWORD_RESET', (int)$user['id'], $user['username'], 'user', $id, $target['username']);
                flash('success', "Password reset for {$target['username']}.");
            }
        } elseif ($action === 'resend_credentials') {
            $password = temporary_password();
            $phone = $target['mobile_phone'] ?? '';
            if ($phone === '' && $target['role'] === 'student') {
                $contact = $pdo->prepare('SELECT contact_no FROM students WHERE user_id = ?');
                $contact->execute([$id]);
                $phone = (string)$contact->fetchColumn();
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            audit_log('CREDENTIALS_REISSUED', (int)$user['id'], $user['username'], 'user', $id);
            $pdo->commit();
            $delivery = credential_delivery($target['email'], (string)$phone, $target['username'], $password);
            if ($delivery['sms_sent']) {
                flash('success', "New temporary credentials were delivered to {$target['username']} by SMS.");
            } else {
                flash('error', "The temporary password was changed for {$target['username']}, but SMS delivery failed. Verify the registered mobile number and TextBee configuration before sending credentials again.");
            }
        } elseif ($action === 'role') {
            $new = post_str('role', 12); $dept = post_int('department_id') ?: null;
            if ($self) flash('error', 'You cannot change your own role.');
            elseif ($target['role'] === 'student' || !in_array($new, STAFF, true)) flash('error', 'Student accounts keep the student role; staff roles can only be changed among staff roles.');
            elseif (in_array($new, ['department', 'professor'], true) && !$dept) flash('error', 'Choose a department for this role.');
            elseif ($target['role'] === 'admin' && $new !== 'admin' && active_admins($pdo) <= 1) flash('error', 'At least one active admin must remain.');
            else {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET role = ?, department_id = ? WHERE id = ?')
                    ->execute([$new, in_array($new, ['department', 'professor'], true) ? $dept : null, $id]);
                if ($target['role'] === 'professor' && $new !== 'professor') {
                    $pdo->prepare("UPDATE professors SET user_id = NULL, status = 'inactive' WHERE user_id = ?")->execute([$id]);
                } elseif ($new === 'professor') {
                    $profile = $pdo->prepare('SELECT id FROM professors WHERE user_id = ?');
                    $profile->execute([$id]);
                    $professorId = $profile->fetchColumn();
                    if ($professorId) {
                        $pdo->prepare("UPDATE professors SET department_id = ?, status = 'active' WHERE id = ?")->execute([$dept, $professorId]);
                    } else {
                        $unlinked = $pdo->prepare('SELECT id FROM professors WHERE user_id IS NULL AND department_id = ? AND email = ? ORDER BY id LIMIT 1 FOR UPDATE');
                        $unlinked->execute([$dept, $target['email']]);
                        $unlinkedId = $unlinked->fetchColumn();
                        if ($unlinkedId) {
                            $pdo->prepare("UPDATE professors SET user_id = ?, status = 'active' WHERE id = ?")->execute([$id, $unlinkedId]);
                        } else {
                            $pdo->prepare("INSERT INTO professors (user_id, department_id, first_name, last_name, email) VALUES (?, ?, ?, 'Faculty', ?)")
                                ->execute([$id, $dept, $target['username'], $target['email']]);
                        }
                    }
                }
                $pdo->commit();
                audit_log('ROLE_CHANGED', (int)$user['id'], $user['username'], 'user', $id, "{$target['username']}: {$target['role']} -> {$new}");
                flash('success', "{$target['username']} is now {$new}.");
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') flash('error', 'That username, email or student number is already in use.');
        else { error_log('[SSIS] admin/users: ' . $e->getMessage()); flash('error', 'The change could not be saved.'); }
    } finally {
        if ($studentNumberYear !== null) release_student_number_lock($pdo, $studentNumberYear);
    }
    if ($action === 'create') {
        header('Location: ' . url('/admin/create_account.php'));
        exit;
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

render_header($user, 'User management');
?>
<form class="filters card admin-user-filters" method="get"><div><label for="q">Search</label><input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Username or email"></div>
  <div><label for="frole">Role</label><select id="frole" name="role"><option value="">All</option><?php foreach (ALL_ROLES as $r): ?><option value="<?= $r ?>"<?= $r === $role ? ' selected' : '' ?>><?= e(label($r)) ?></option><?php endforeach; ?></select></div>
  <button class="btn" type="submit">Filter</button></form>
<div class="tablewrap table-responsive"><table><thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Manage</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['username']) ?></td><td><?= e($r['email']) ?></td><td><?= e(label($r['role'])) ?><?= $r['dept'] ? ' <small>(' . e($r['dept']) . ')</small>' : '' ?></td>
    <td><?= badge($r['status']) ?><?= (int)$r['is_locked'] ? ' <span class="badge bad">Locked</span>' : '' ?></td><td><?= e(fmt_date($r['last_login_at'])) ?></td>
    <td><details class="row-actions"><summary>Manage</summary><div class="actions">
      <?php if ((int)$r['id'] !== (int)$user['id']): ?>
        <?= form_open('toggle') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn alt sm" type="submit" data-confirm="<?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?> this account?"><?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form>
      <?php endif; ?>
      <?php if ((int)$r['is_locked']): ?><?= form_open('unlock') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn alt sm" type="submit">Unlock</button></form><?php endif; ?>
      <?= form_open('reset') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="password" name="password" placeholder="New password" aria-label="New password" autocomplete="new-password" required><button class="btn alt sm" type="submit">Reset</button></form>
      <?= form_open('resend_credentials') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn alt sm" type="submit" data-confirm="Generate a new temporary password and send it to both contact channels?">Send new credentials</button></form>
      <?php if ($r['role'] !== 'student' && (int)$r['id'] !== (int)$user['id']): ?>
        <?= form_open('role') ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <select name="role" aria-label="Role"><?php foreach (STAFF as $s): ?><option value="<?= $s ?>"<?= $s === $r['role'] ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select>
        <select name="department_id" aria-label="Department"><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)$d['id'] === (int)$r['department_id'] ? ' selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?></select><button class="btn alt sm" type="submit" data-confirm="Change this user's role?">Set role</button></form>
      <?php endif; ?>
    </div></details></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php render_footer();
