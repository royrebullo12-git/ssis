<?php
declare(strict_types=1);
if (!defined('SSIS_BOOT')) { http_response_code(403); exit('Direct access forbidden.'); }
require_once __DIR__ . '/helpers.php';

const NAV = [
    'student' => [
        'Dashboard' => '/student/dashboard.php', 'Grades' => '/student/grades.php',
        'Clearance' => '/student/clearance.php', 'Enrollment' => '/student/enrollment.php',
        'Document requests' => '/student/requests.php',
    ],
    'registrar' => [
        'Dashboard' => '/registrar/dashboard.php', 'Students' => '/registrar/students.php',
        'Grades' => '/registrar/grades.php', 'Clearances' => '/registrar/clearances.php',
        'Document requests' => '/registrar/requests.php',
    ],
    'cashier' => [
        'Dashboard' => '/cashier/dashboard.php', 'Payments' => '/cashier/payments.php',
        'Fee clearances' => '/cashier/clearances.php',
    ],
    'department' => [
        'Dashboard' => '/department/dashboard.php', 'Clearances' => '/department/clearances.php',
    ],
    'admin' => [
        'Dashboard' => '/admin/dashboard.php', 'Users' => '/admin/users.php', 'Audit log' => '/admin/audit.php',
    ],
];

function render_header(array $user, string $title): void
{
    $self = $_SERVER['SCRIPT_NAME'] ?? '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($title) . ' - SSIS</title>'
       . '<link rel="stylesheet" href="' . e(url('/assets/css/style.css')) . '">'
       . '<script src="' . e(url('/assets/js/app.js')) . '" defer></script></head><body>';
    echo '<header class="top"><a class="logo" href="' . e(url(ROLE_HOME[$user['role']])) . '">SSIS</a>'
       . '<nav aria-label="Main">';
    foreach (NAV[$user['role']] as $text => $path) {
        $cur = str_ends_with($self, $path) ? ' aria-current="page"' : '';
        echo '<a href="' . e(url($path)) . '"' . $cur . '>' . e($text) . '</a>';
    }
    echo '</nav><div class="who"><span>' . e($user['username']) . ' (' . e(label($user['role'])) . ')</span>'
       . '<form method="post" action="' . e(url('/auth/logout.php')) . '">' . csrf_field()
       . '<button class="link" type="submit">Sign out</button></form></div></header><main class="page">';
    foreach (flash_take() as [$type, $msg]) {
        echo '<div class="msg ' . e($type) . '" role="status">' . e($msg) . '</div>';
    }
    echo '<h1>' . e($title) . '</h1>';
}

function render_footer(): void
{
    echo '</main></body></html>';
}

/** Hidden CSRF + action inputs for small inline forms. */
function form_open(string $action = '', string $extra = ''): string
{
    return '<form method="post" action="" ' . $extra . '>' . csrf_field()
         . ($action !== '' ? '<input type="hidden" name="action" value="' . e($action) . '">' : '');
}
