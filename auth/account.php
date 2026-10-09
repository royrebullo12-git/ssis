<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_login();
$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 20);
    if ($action === 'update_profile') {
        $email = post_str('email', 120);
        $phone = post_str('mobile_phone', 20);
        $normalizedPhone = preg_replace('/[\s()-]/', '', $phone) ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (!preg_match('/^\+?[0-9]{8,15}$/', $normalizedPhone)) {
            $error = 'Enter a valid mobile number with 8 to 15 digits.';
        } else {
            try {
                $pdo->prepare('UPDATE users SET email = ?, mobile_phone = ? WHERE id = ?')
                    ->execute([$email, $normalizedPhone, (int)$user['id']]);
                audit_log('ACCOUNT_CONTACT_UPDATED', (int)$user['id'], $user['username'], 'user', (int)$user['id']);
                flash('success', 'Your email and mobile number were updated.');
                redirect('/auth/account.php');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'That email address is already linked to another account.';
                } else {
                    error_log('[WLS] account contact update failed: ' . $e->getMessage());
                    $error = 'Your contact details could not be saved.';
                }
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirmation = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([(int)$user['id']]);
        $passwordHash = (string)$statement->fetchColumn();
        if (!password_verify($currentPassword, $passwordHash)) {
            $error = 'Your current password is not correct.';
        } else {
            $error = password_error($newPassword);
        }
        if ($error === null && $newPassword !== $confirmation) {
            $error = 'The new password and confirmation do not match.';
        }
        if ($error === null) {
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
            unset($_SESSION['temporary_password_banner_dismissed']);
            audit_log('PASSWORD_CHANGED', (int)$user['id'], $user['username'], 'user', (int)$user['id']);
            flash('success', 'Your password was updated.');
            redirect('/auth/account.php');
        }
    } else {
        $error = 'Unknown account action.';
    }
}

$statement = $pdo->prepare('SELECT email, mobile_phone, must_change_password FROM users WHERE id = ?');
$statement->execute([(int)$user['id']]);
$account = $statement->fetch();
render_header($user, 'My profile and account settings');
?>
<?php if ($error): ?><div class="msg error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="content-grid">
  <section class="card">
    <p class="card-kicker">PROFILE</p><h2>Contact details</h2>
    <p class="muted">Your email and mobile number are used for secure password recovery.</p>
    <?= form_open('update_profile') ?>
      <div class="form-grid">
        <div><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="120" required value="<?= e($account['email']) ?>"></div>
        <div><label for="mobile_phone">Mobile number</label><input id="mobile_phone" name="mobile_phone" type="tel" maxlength="20" required autocomplete="tel" value="<?= e($account['mobile_phone'] ?? '') ?>"><small class="field-help">Include country code for international SMS, e.g. +639171234567.</small></div>
      </div>
      <p class="form-actions"><button class="btn" type="submit">Save profile</button></p>
    </form>
  </section>
  <section class="card">
    <p class="card-kicker">ACCOUNT SECURITY</p><h2>Change password</h2>
    <?php if ((int)$account['must_change_password'] === 1): ?><div class="msg info" role="status">This account still uses a temporary password. Choose a new password now or return later using the reminder.</div><?php endif; ?>
    <?= form_open('change_password') ?>
      <div><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
      <div><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required><small class="field-help">Use at least 12 characters with upper- and lower-case letters and a number.</small></div>
      <div><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></div>
      <p class="form-actions"><button class="btn" type="submit">Update password</button></p>
    </form>
  </section>
</div>
<?php render_footer(); ?>
