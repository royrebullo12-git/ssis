<?php
declare(strict_types=1);

define('SSIS_BOOT', true);
require_once __DIR__ . '/auth_check.php';

// Sign-out only works as a POST with a valid CSRF token, so another site cannot
// log users out with an <img> tag. The Sign out button in includes/layout.php
// sends that POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? null)) {
    logout_user();
    start_secure_session();
    redirect('/auth/login.php?reason=loggedout');
}

$user = auth_user();
redirect($user ? ROLE_HOME[$user['role']] : '/auth/login.php');
