<?php
declare(strict_types=1);
define('SSIS_BOOT', true);
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/layout.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
post_guard();
$statement = db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
$statement->execute([(int)$user['id']]);
redirect(ROLE_HOME[$user['role']]);
