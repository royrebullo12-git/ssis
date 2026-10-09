<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/notifications.php';
start_secure_session();

$userId = (int)($_SESSION['password_reset_user_id'] ?? 0);
$expires = (int)($_SESSION['password_reset_expires'] ?? 0);
if ($userId < 1 || $expires < time()) {
    unset($_SESSION['password_reset_user_id'], $_SESSION['password_reset_expires']);
    flash('error', 'Your password reset session expired. Request a new verification code.');
    redirect('/auth/forgot_password.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmation = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $error = password_error($password);
    if ($error === null && $password !== $confirmation) {
        $error = 'The password and confirmation do not match.';
    }
    if ($error === null) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'UPDATE users SET password_hash = ?, must_change_password = 0, failed_attempts = 0, locked_until = NULL WHERE id = ? AND status = "active"'
            );
            $statement->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The account is no longer active.');
            }
            $pdo->prepare('DELETE FROM password_reset_otps WHERE user_id = ?')->execute([$userId]);
            $pdo->commit();
            audit_log('PASSWORD_RESET_COMPLETED', $userId, null, 'user', $userId);
            unset($_SESSION['password_reset_user_id'], $_SESSION['password_reset_expires'], $_SESSION['password_reset_candidate'], $_SESSION['password_reset_step'], $_SESSION['password_reset_delivery_failed']);
            session_regenerate_id(true);
            flash('success', 'Your password was reset. Sign in with your new password.');
            redirect('/auth/login.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[WLS] Password reset failed: ' . $e->getMessage());
            $error = 'Your password could not be reset. Please request a new code.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set a new password - Wilson University</title>
  <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/wls-logo.svg')) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= e(url('/assets/css/auth.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/wls.css')) ?>?v=<?= (string)filemtime(__DIR__ . '/../assets/css/wls.css') ?>">
</head>
<body class="plain">
  <main class="denied">
    <a class="auth-brand auth-brand-light" href="<?= e(url('/')) ?>"><img src="<?= e(url('/assets/wls-logo.svg')) ?>" alt=""><span>Wilson University</span></a>
    <h1>Set a new password</h1>
    <p>Choose a unique password with at least 12 characters, including uppercase and lowercase letters and a number.</p>
    <?php if ($error): ?><div class="msg error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= form_open('reset') ?>
      <label for="password">New password</label><input id="password" name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required>
      <label for="confirm_password">Confirm password</label><input id="confirm_password" name="confirm_password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required>
      <button class="btn" type="submit">Reset password</button>
    </form>
  </main>
</body>
</html>
