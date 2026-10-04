<?php
declare(strict_types=1);

define('SSIS_BOOT', true);
require_once __DIR__ . '/auth_check.php';

// POST + CSRF token only, so another site cannot sign users out with an <img> tag.
// In any dashboard header use:
//   <form method="post" action="<?= e(url('/auth/logout.php')) ?>"><?= csrf_field() ?><button>Sign out</button></form>
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? null)) {
    logout_user();
    start_secure_session();
    redirect('/auth/login.php?reason=loggedout');
}

$user = auth_user();
redirect($user ? ROLE_HOME[$user['role']] : '/auth/login.php');
