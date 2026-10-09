<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/notifications.php';
start_secure_session();

if (get_str('restart', 1) === '1') {
    unset(
        $_SESSION['password_reset_candidate'],
        $_SESSION['password_reset_step'],
        $_SESSION['password_reset_delivery_failed']
    );
}
$step = ($_SESSION['password_reset_step'] ?? 'request') === 'verify' ? 'verify' : 'request';
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $action = post_str('action', 20);
    if ($action === 'request') {
        $identity = post_str('identity', 120);
        $phoneIdentity = preg_replace('/[\s().-]/', '', $identity) ?? '';
        $validIdentity = filter_var($identity, FILTER_VALIDATE_EMAIL)
            || preg_match('/^\+?[0-9]{8,15}$/', $phoneIdentity)
            || preg_match('/^[A-Za-z0-9._-]{3,50}$/', $identity);
        $account = false;
        if ($validIdentity) {
            $lookup = db()->prepare(
                'SELECT u.id, u.email, u.mobile_phone, u.username, s.contact_no
                   FROM users u LEFT JOIN students s ON s.user_id = u.id
                  WHERE u.status = "active"
                    AND (u.email = ? OR u.username = ? OR u.mobile_phone = ? OR s.contact_no = ? OR s.student_no = ?)
                  LIMIT 1'
            );
            $lookup->execute([$identity, $identity, $phoneIdentity, $phoneIdentity, $identity]);
            $account = $lookup->fetch();
        }
        $_SESSION['password_reset_candidate'] = $account ? (int)$account['id'] : 0;
        $_SESSION['password_reset_step'] = 'verify';
        $_SESSION['password_reset_delivery_failed'] = false;
        if ($account) {
            $rate = db()->prepare(
                'SELECT COUNT(*) FROM password_reset_otps
                  WHERE user_id = ? AND created_at >= NOW() - INTERVAL 10 MINUTE'
            );
            $rate->execute([(int)$account['id']]);
            if ((int)$rate->fetchColumn() < 3) {
                $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                db()->prepare('UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                    ->execute([(int)$account['id']]);
                $insert = db()->prepare(
                    'INSERT INTO password_reset_otps (user_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
                );
                $insert->execute([(int)$account['id'], password_hash($otp, PASSWORD_DEFAULT)]);
                $phone = $account['mobile_phone'] ?: $account['contact_no'];
                $delivery = otp_delivery($account['email'], (string)$phone, $otp);
                if (!$delivery['sms_sent']) {
                    db()->prepare('UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                        ->execute([(int)$account['id']]);
                    $_SESSION['password_reset_delivery_failed'] = true;
                }
            } else {
                $_SESSION['password_reset_delivery_failed'] = true;
            }
        }
        $step = 'verify';
        $message = 'If the details match an active account, a verification code has been sent by SMS to its registered mobile number.';
    } elseif ($action === 'verify') {
        $candidate = (int)($_SESSION['password_reset_candidate'] ?? 0);
        $code = post_str('code', 6);
        $verified = false;
        if ($candidate > 0 && preg_match('/^\d{6}$/', $code)) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare(
                    'SELECT id, code_hash FROM password_reset_otps
                      WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < 5
                      ORDER BY id DESC LIMIT 1 FOR UPDATE'
                );
                $statement->execute([$candidate]);
                $otp = $statement->fetch();
                if ($otp) {
                    $matches = password_verify($code, $otp['code_hash']);
                    $pdo->prepare('UPDATE password_reset_otps SET attempts = attempts + 1, used_at = IF(?, NOW(), used_at) WHERE id = ?')
                        ->execute([$matches ? 1 : 0, (int)$otp['id']]);
                    $verified = $matches;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[WLS] Password reset OTP verification failed: ' . $e->getMessage());
                $error = 'The code could not be verified. Request a new code or contact the Registrar.';
            }
        }
        if ($verified) {
            $_SESSION['password_reset_user_id'] = $candidate;
            $_SESSION['password_reset_expires'] = time() + 900;
            unset($_SESSION['password_reset_candidate'], $_SESSION['password_reset_step']);
            redirect('/auth/reset_password.php');
        }
        if ($error === null) {
            $error = 'The verification code is invalid, expired, or no longer available.';
        }
        $step = 'verify';
    } else {
        $error = 'Unknown password recovery action.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot password - Wilson University</title>
  <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/wls-logo.svg')) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= e(url('/assets/css/auth.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/wls.css')) ?>?v=<?= (string)filemtime(__DIR__ . '/../assets/css/wls.css') ?>">
</head>
<body class="plain">
  <main class="denied">
    <a class="auth-brand auth-brand-light" href="<?= e(url('/')) ?>"><img src="<?= e(url('/assets/wls-logo.svg')) ?>" alt=""><span>Wilson University</span></a>
    <h1><?= $step === 'verify' ? 'Verify your identity' : 'Reset your password' ?></h1>
    <?php if ($message): ?><div class="msg info" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($step === 'verify' && !empty($_SESSION['password_reset_delivery_failed'])): ?>
      <div class="msg error" role="alert">SMS delivery is not currently available, so this verification code cannot be used. Contact the Registrar or try again after TextBee has been configured.</div>
    <?php endif; ?>
    <?php if ($step === 'request'): ?>
      <p>Enter your university email, mobile number, student/employee ID, or username. A code will be sent by SMS to the registered mobile number.</p>
      <?= form_open('request') ?>
        <label for="identity">Email, mobile, or student / employee ID</label>
        <input id="identity" name="identity" type="text" maxlength="120" autocomplete="username" required>
        <button class="btn" type="submit">Send verification code</button>
      </form>
    <?php else: ?>
      <p>Enter the six-digit code sent to the registered mobile number. Codes expire after 10 minutes.</p>
      <?= form_open('verify') ?>
        <label for="code">Verification code</label>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required>
        <button class="btn" type="submit">Verify code</button>
      </form>
      <p class="foot"><a href="<?= e(url('/auth/forgot_password.php?restart=1')) ?>">Start over</a></p>
    <?php endif; ?>
    <p class="foot"><a href="<?= e(url('/auth/login.php')) ?>">Back to sign in</a></p>
  </main>
</body>
</html>
