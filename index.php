<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/auth/auth_check.php';
$u = auth_user();
redirect($u ? ROLE_HOME[$u['role']] : '/auth/login.php');
