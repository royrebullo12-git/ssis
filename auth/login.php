<?php
declare(strict_types=1);

define('SSIS_BOOT', true);
require_once __DIR__ . '/auth_check.php';

// Already signed in? Go straight to the role's dashboard.
$current = auth_user();
if ($current !== null) {
    redirect(ROLE_HOME[$current['role']]);
}
// auth_user() may have destroyed an expired session; make sure a fresh one exists.
start_secure_session();

$notices = [
    'timeout'   => 'You were signed out after 30 minutes of inactivity. Sign in again to continue.',
    'expired'   => 'Your session reached its time limit. Sign in again to continue.',
    'disabled'  => 'This account is no longer active. Contact the Registrar if you think this is a mistake.',
    'invalid'   => 'Your session could not be verified. Sign in again.',
    'loggedout' => 'You have been signed out.',
];
$reason = $_GET['reason'] ?? '';
$notice = is_string($reason) && isset($notices[$reason]) ? $notices[$reason] : null;

$error    = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        http_response_code(400);
        $error = 'Your form expired. Reload the page and try again.';
    } else {
        $result = attempt_login($username, $password);
        if ($result['ok']) {
            redirect(ROLE_HOME[$result['user']['role']]);
        }
        $error = $result['message'];
        http_response_code($result['blocked'] ? 429 : 401);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in - Student Services Information System</title>
  <link rel="stylesheet" href="<?= e(url('/assets/css/auth.css')) ?>">
  <script src="<?= e(url('/assets/js/login.js')) ?>" defer></script>
</head>
<body>
  <div class="shell">
    <aside class="intro" aria-labelledby="intro-title">
      <p class="brand">SSIS</p>
      <h1 id="intro-title">Clearance, grades and records in one place.</h1>
      <p class="lead">Check where your clearance stands, see your grades, and track document requests without queuing at three windows.</p>

      <div class="slip" aria-hidden="true">
        <div class="slip-head">Clearance slip</div>
        <ul>
          <li><span>Registrar</span><b class="stamp ok">Cleared</b></li>
          <li><span>Cashier</span><b class="stamp ok">Cleared</b></li>
          <li><span>Department</span><b class="stamp wait">Pending</b></li>
        </ul>
      </div>
    </aside>

    <main class="panel">
      <form method="post" action="<?= e(url('/auth/login.php')) ?>" id="login-form" novalidate>
        <h2>Sign in</h2>
        <p class="hint">Students use their student number. Staff use the username given by the Admin office.</p>

        <?php if ($notice): ?>
          <div class="msg info" role="status"><?= e($notice) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="msg error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?= csrf_field() ?>

        <label for="username">Username or student number</label>
        <input id="username" name="username" type="text" maxlength="50" required
               autocomplete="username" autocapitalize="none" spellcheck="false"
               value="<?= e($username) ?>" autofocus>

        <label for="password">Password</label>
        <div class="pw">
          <input id="password" name="password" type="password" maxlength="200" required
                 autocomplete="current-password">
          <button type="button" id="toggle-pw" class="ghost" aria-controls="password" aria-pressed="false">Show</button>
        </div>
        <p class="caps" id="caps-note" hidden>Caps Lock is on.</p>

        <button type="submit" class="btn" id="submit-btn">Sign in</button>
        <p class="foot">Forgot your password? Ask the Admin office to reset it.</p>
      </form>
    </main>
  </div>
</body>
</html>
