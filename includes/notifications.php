<?php
declare(strict_types=1);

if (!defined('SSIS_BOOT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notification_helper.php';

function password_error(string $password): ?string
{
    if (strlen($password) < 12 || strlen($password) > 200) {
        return 'Password must be 12 to 200 characters.';
    }
    if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)
        || !preg_match('/\d/', $password)) {
        return 'Password needs an uppercase letter, a lowercase letter and a digit.';
    }
    return null;
}

function temporary_password(): string
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
    $characters = ['A', 'a', '1', '!'];
    for ($i = 0; $i < 16; $i++) {
        $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    for ($i = count($characters) - 1; $i > 0; $i--) {
        $swap = random_int(0, $i);
        [$characters[$i], $characters[$swap]] = [$characters[$swap], $characters[$i]];
    }
    return implode('', $characters);
}

function credential_delivery(string $email, string $phone, string $username, string $password): array
{
    $publicUrl = rtrim(trim((string)getenv('WLS_PUBLIC_URL')), '/');
    $loginUrl = filter_var($publicUrl, FILTER_VALIDATE_URL)
        && parse_url($publicUrl, PHP_URL_SCHEME) === 'https'
        ? $publicUrl . '/auth/login.php'
        : '';
    $loginLink = $loginUrl !== '' ? ' Sign in at ' . $loginUrl . '.' : '';
    return sendSmsNotification(
        $phone,
        'Wilson University WLS account: ID ' . $username . ', temporary password ' . $password
        . '.' . $loginLink . ' Change it after login.'
    ) + ['email_sent' => false];
}

function otp_delivery(string $email, string $phone, string $otp): array
{
    return sendSmsNotification(
        $phone,
        'Wilson University WLS password reset code: ' . $otp . '. Expires in 10 minutes. Do not share this code.'
    ) + ['email_sent' => false];
}

function create_notification(int $userId, string $title, string $body, ?string $link = null): void
{
    $statement = db()->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
    $statement->execute([$userId, mb_substr($title, 0, 120), mb_substr($body, 0, 500), $link]);
}

function notify_role(string $role, string $title, string $body, ?string $link = null, ?int $departmentId = null): void
{
    $sql = 'SELECT id FROM users WHERE role = ? AND status = "active"';
    $params = [$role];
    if ($departmentId !== null) {
        $sql .= ' AND department_id = ?';
        $params[] = $departmentId;
    }
    $users = db()->prepare($sql);
    $users->execute($params);
    $insert = db()->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
    foreach ($users->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        $insert->execute([(int)$userId, mb_substr($title, 0, 120), mb_substr($body, 0, 500), $link]);
    }
}
