<?php
declare(strict_types=1);
if (!defined('SSIS_BOOT')) { http_response_code(403); exit('Direct access forbidden.'); }
require_once __DIR__ . '/helpers.php';

const NAV = [
    'student' => [
        'Overview' => '/student/dashboard.php', 'Grades' => '/student/grades.php',
        'Clearance' => '/student/clearance.php', 'Enrollment' => '/student/enrollment.php',
        'Document requests' => '/student/requests.php',
    ],
    'registrar' => [
        'Overview' => '/registrar/dashboard.php', 'Admissions' => '/registrar/admissions.php',
        'Students' => '/registrar/students.php', 'Enrollment' => '/registrar/enrollments.php',
        'Clearances' => '/registrar/clearances.php', 'Document requests' => '/registrar/requests.php',
    ],
    'cashier' => [
        'Overview' => '/cashier/dashboard.php', 'Payments' => '/cashier/payments.php',
        'Fee clearances' => '/cashier/clearances.php',
    ],
    'department' => [
        'Overview' => '/department/dashboard.php', 'Academic setup' => '/department/academics.php',
        'Class lists' => '/department/classes.php', 'Grade review' => '/department/grades.php',
        'Clearances' => '/department/clearances.php',
    ],
    'professor' => [
        'Overview' => '/professor/dashboard.php', 'Class rosters & grades' => '/professor/grades.php',
    ],
    'admin' => [
        'Overview' => '/admin/dashboard.php', 'User management' => '/admin/users.php', 'Audit log' => '/admin/audit.php',
    ],
];

function render_header(array $user, string $title): void
{
    $self = $_SERVER['SCRIPT_NAME'] ?? '';
    $role = $user['role'];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($title) . ' - SSIS</title>'
       . '<link rel="stylesheet" href="' . e(url('/assets/css/style.css')) . '">'
       . '<script src="' . e(url('/assets/js/app.js')) . '" defer></script></head><body>';
    echo '<div class="app-shell"><aside class="sidebar">'
       . '<a class="side-brand" href="' . e(url(ROLE_HOME[$role])) . '"><span>SSIS</span><small>Student Services</small></a>'
       . '<div class="side-section"><small>' . e(label($role)) . ' portal</small><nav aria-label="Main">';
    foreach (NAV[$role] as $text => $path) {
        $cur = str_ends_with($self, $path) ? ' aria-current="page"' : '';
        echo '<a href="' . e(url($path)) . '"' . $cur . '>' . e($text) . '</a>';
    }
    echo '</nav></div><div class="side-bottom">'
       . '<a href="' . e(url('/')) . '">Public home</a>'
       . '<form method="post" action="' . e(url('/auth/logout.php')) . '">' . csrf_field()
       . '<button class="side-signout" type="submit">Sign out</button></form></div></aside>'
       . '<div class="app-main"><header class="app-top"><div><span class="top-kicker">UNIVERSITY SERVICES</span><strong>' . e($title) . '</strong></div>'
       . '<div class="who"><span class="user-avatar">' . e(strtoupper(substr($user['username'],0,1))) . '</span>'
       . '<span>' . e($user['username']) . '<small>' . e(label($role)) . '</small></span></div></header><main class="page">';
    foreach (flash_take() as [$type, $msg]) {
        echo '<div class="msg ' . e($type) . '" role="status">' . e($msg) . '</div>';
    }
    echo '<div class="page-heading"><div><h1>' . e($title) . '</h1><p class="breadcrumb">Student Services Information System</p></div></div>';
}

function render_footer(): void
{
    echo '</main><footer class="app-footer">SSIS · Secure university service portal</footer></div></div></body></html>';
}

function form_open(string $action = '', string $extra = ''): string
{
    return '<form method="post" action="" ' . $extra . '>' . csrf_field()
         . ($action !== '' ? '<input type="hidden" name="action" value="' . e($action) . '">' : '');
}
