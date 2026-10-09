<?php
declare(strict_types=1);
if (!defined('SSIS_BOOT')) { http_response_code(403); exit('Direct access forbidden.'); }
require_once __DIR__ . '/helpers.php';

const NAV = [
    'student' => [
        'Overview' => '/student/dashboard.php', 'Grades' => '/student/grades.php',
        'Clearance' => '/student/clearance.php', 'Enrollment' => '/student/enrollment.php',
        'Requests' => '/student/requests.php',
    ],
    'registrar' => [
        'Overview' => '/registrar/dashboard.php', 'Admissions' => '/registrar/admissions.php',
        'Students' => '/registrar/students.php', 'Enrollment' => '/registrar/enrollments.php',
        'Clearances' => '/registrar/clearances.php', 'Document requests' => '/registrar/requests.php',
        'Document types & routing' => '/registrar/document_types.php',
        'Curriculum requirements' => '/registrar/curriculum.php',
        'Course drop requests' => '/registrar/drop_requests.php',
    ],
    'cashier' => [
        'Overview' => '/cashier/dashboard.php', 'Payments' => '/cashier/payments.php',
        'Collection reports' => '/cashier/collections.php',
        'Fee clearances' => '/cashier/clearances.php',
    ],
    'department' => [
        'Overview' => '/department/dashboard.php', 'Academic setup' => '/department/academics.php',
        'Faculty profiles & assignments' => '/department/faculty.php',
        'Document requests' => '/department/document_requests.php', 'Course drop requests' => '/department/drop_requests.php',
        'Class lists' => '/department/classes.php', 'Grade review' => '/department/grades.php',
        'Clearances' => '/department/clearances.php',
    ],
    'professor' => [
        'Overview' => '/professor/dashboard.php', 'Class rosters & grades' => '/professor/grades.php',
    ],
    'admin' => [
        'Overview' => '/admin/dashboard.php', 'User management' => '/admin/users.php',
        'Department directory' => '/admin/departments.php', 'Audit log' => '/admin/audit.php',
    ],
];

function render_header(array $user, string $title): void
{
    $self = $_SERVER['SCRIPT_NAME'] ?? '';
    $role = $user['role'];
    $notifications = db()->prepare(
        'SELECT id, title, body, link, created_at, read_at FROM notifications
          WHERE user_id = ? AND read_at IS NULL ORDER BY created_at DESC LIMIT 50'
    );
    $notifications->execute([(int)$user['id']]);
    $recentNotifications = $notifications->fetchAll();
    $unreadQuery = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $unreadQuery->execute([(int)$user['id']]);
    $unreadNotifications = (int)$unreadQuery->fetchColumn();
    $notificationMarkup = notification_items_markup($recentNotifications);
    $GLOBALS['wls_notification_markup'] = $notificationMarkup;
    $GLOBALS['wls_unread_notification_count'] = $unreadNotifications;
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="theme-color" content="#0F2942">'
       . '<title>' . e($title) . ' - Wilson University</title>'
       . '<link rel="icon" type="image/svg+xml" href="' . e(url('/assets/wls-logo.svg')) . '">'
       . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">'
       . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">'
       . '<link rel="stylesheet" href="' . e(url('/assets/css/style.css')) . '?v=' . (string)filemtime(__DIR__ . '/../assets/css/style.css') . '">'
       . '<link rel="stylesheet" href="' . e(url('/assets/css/wls.css')) . '?v=' . (string)filemtime(__DIR__ . '/../assets/css/wls.css') . '">'
       . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>'
       . '<script src="' . e(url('/assets/js/app.js')) . '?v=' . (string)filemtime(__DIR__ . '/../assets/js/app.js') . '" defer></script></head><body>';
    echo '<div class="app-shell" data-app-shell data-sidebar-key="' . e('wls-sidebar-pinned-' . (int)$user['id']) . '"><aside class="sidebar offcanvas-lg offcanvas-start" id="portal-sidebar" tabindex="-1" aria-label="Main navigation">'
       . '<div class="offcanvas-header sidebar-mobile-header"><a class="side-brand" href="' . e(url(ROLE_HOME[$role])) . '"><img src="' . e(url('/assets/wls-logo.svg')) . '" alt=""><span>WLSU</span></a>'
       . '<button class="btn-close" type="button" data-bs-dismiss="offcanvas" data-bs-target="#portal-sidebar" aria-label="Close navigation"></button></div>'
       . '<div class="sidebar-brand-row sidebar-desktop-brand"><a class="side-brand" href="' . e(url(ROLE_HOME[$role])) . '"><img src="' . e(url('/assets/wls-logo.svg')) . '" alt=""><span>WLSU</span></a>'
       . '</div>'
       . '<div class="side-section"><small>' . e(label($role)) . ' portal</small><nav aria-label="Main">';
    foreach (NAV[$role] as $text => $path) {
        $cur = str_ends_with($self, $path) ? ' class="active" aria-current="page"' : '';
        $icon = match ($path) {
            '/student/dashboard.php', '/registrar/dashboard.php', '/cashier/dashboard.php', '/department/dashboard.php', '/professor/dashboard.php', '/admin/dashboard.php' => 'bi-grid-1x2',
            '/student/grades.php', '/professor/grades.php' => 'bi-journal-check',
            '/student/clearance.php', '/registrar/clearances.php', '/cashier/clearances.php', '/department/clearances.php' => 'bi-clipboard-check',
            '/student/enrollment.php', '/registrar/enrollments.php' => 'bi-mortarboard',
            '/student/requests.php', '/registrar/requests.php', '/department/document_requests.php' => 'bi-file-earmark-text',
            '/registrar/admissions.php' => 'bi-person-plus',
            '/registrar/students.php' => 'bi-people',
            '/registrar/document_types.php' => 'bi-signpost-split',
            '/registrar/curriculum.php' => 'bi-book',
            '/registrar/drop_requests.php', '/department/drop_requests.php' => 'bi-box-arrow-down',
            '/cashier/payments.php' => 'bi-cash-coin',
            '/cashier/collections.php' => 'bi-bar-chart',
            '/department/academics.php' => 'bi-book-half',
            '/department/faculty.php' => 'bi-person-workspace',
            '/department/classes.php' => 'bi-easel',
            '/department/grades.php' => 'bi-check2-square',
            '/admin/users.php' => 'bi-person-gear',
            '/admin/departments.php' => 'bi-building',
            '/admin/audit.php' => 'bi-shield-check',
            default => 'bi-circle',
        };
        echo '<a href="' . e(url($path)) . '"' . $cur . ' title="' . e($text) . '"><i class="bi ' . e($icon) . '" aria-hidden="true"></i><span class="nav-label">' . e($text) . '</span></a>';
    }
    echo '</nav></div><div class="side-bottom">'
       . '<form method="post" action="' . e(url('/auth/logout.php')) . '">' . csrf_field()
       . '<button class="side-signout" type="submit" title="Sign out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="nav-label">Sign out</span></button></form></div></aside>'
       . '<div class="app-main"><header class="app-top"><div class="app-top-title">'
       . '<button class="sidebar-toggle sidebar-mobile-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#portal-sidebar" aria-controls="portal-sidebar" aria-expanded="false" aria-label="Open navigation"><i class="bi bi-list" aria-hidden="true"></i></button>'
       . '<button class="sidebar-toggle sidebar-desktop-toggle" type="button" data-sidebar-toggle aria-controls="portal-sidebar" aria-expanded="false" aria-label="Expand navigation" title="Expand navigation"><i class="bi bi-layout-sidebar" aria-hidden="true"></i></button>'
       . '<div class="app-top-brand"><img src="' . e(url('/assets/wls-logo.svg')) . '" alt=""><span>Wilson University</span></div></div>'
       . '<div class="top-actions"><details class="top-menu profile-menu"><summary aria-label="Open profile and notifications">'
       . '<span class="user-avatar user-avatar-trigger">' . e(strtoupper(substr($user['username'], 0, 1)))
       . ($unreadNotifications > 0 ? '<span class="avatar-notification-dot" aria-label="Unread notifications"></span>' : '')
       . '</span></summary><div class="top-dropdown profile-dropdown">'
       . '<div class="profile-dropdown-heading"><span class="user-avatar user-avatar-large">'
       . e(strtoupper(substr($user['username'], 0, 1))) . '</span><span><strong>' . e($user['username'])
       . '</strong><small>' . e(label($role)) . ' account</small></span></div>'
       . '<div class="profile-dropdown-links"><a href="' . e(url('/auth/account.php')) . '"><i class="bi bi-person-circle" aria-hidden="true"></i>My Profile &amp; Settings</a></div>'
       . '<section class="profile-notifications" aria-label="Recent notifications"><div class="notification-heading"><strong>Notifications</strong>'
       . ($unreadNotifications > 0 ? '<span class="notification-pill">' . $unreadNotifications . ' new</span>' : '')
       . '</div>' . $notificationMarkup
       . ($unreadNotifications > 0
           ? '<form method="post" action="' . e(url('/auth/notifications.php')) . '">' . csrf_field()
             . '<button class="notification-mark-read" type="submit"><i class="bi bi-check2-all" aria-hidden="true"></i>Mark all as read</button></form>'
           : '')
       . '</section>'
       . '<div class="profile-dropdown-footer"><form method="post" action="' . e(url('/auth/logout.php')) . '">' . csrf_field()
       . '<button class="dropdown-action" type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Sign out</button></form>'
       . '</div></div></details></div></header><main class="page container-fluid py-4 px-3 px-md-4">';
    if (!empty($user['must_change_password']) && empty($_SESSION['temporary_password_banner_dismissed'])) {
        echo '<div class="temporary-password-banner" role="status" data-dismissible="temporary-password">'
           . '<div><strong>Temporary password in use.</strong> Change it to secure your account.</div>'
           . '<a class="btn" href="' . e(url('/auth/account.php')) . '">Change password</a>'
           . '<button class="banner-dismiss" type="button" aria-label="Dismiss temporary password reminder">×</button></div>';
    }
    foreach (flash_take() as [$type, $msg]) {
        echo '<div class="msg ' . e($type) . '" role="status">' . e($msg) . '</div>';
    }
    $headingClass = basename($self) === 'dashboard.php' ? ' dashboard-heading' : '';
    echo '<div class="page-heading' . $headingClass . '"><div><h1>' . e($title) . '</h1><p class="breadcrumb">Wilson University · WLS Student Services</p></div>'
       . '</div><div class="workspace-grid' . ($headingClass !== '' ? ' dashboard-workspace' : '') . '"><div class="workspace-primary">';
}

function render_footer(): void
{
    $isDashboard = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'dashboard.php';
    $weatherWidget = '';
    if ($isDashboard) {
        $weatherWidget = '<section class="overview-weather-widget card shadow-sm border-0 rounded-3" data-overview-context aria-label="Manila weather forecast and local time">'
            . '<div class="overview-weather-heading"><span class="overview-weather-icon" aria-hidden="true"><i class="bi bi-cloud-sun"></i></span>'
            . '<span><strong>Weather forecast</strong><small data-weather-location>Manila, Philippines</small></span></div>'
            . '<div class="weather-forecast" aria-live="polite">'
            . '<div class="weather-forecast-row" data-weather-day="yesterday"><span class="weather-day-label">Yesterday</span><span class="weather-condition"><i class="bi bi-cloud-sun" data-weather-icon="yesterday" aria-hidden="true"></i><span data-weather-condition="yesterday">Loading...</span></span><strong data-weather-temperature="yesterday">--° / --°</strong></div>'
            . '<div class="weather-forecast-row" data-weather-day="today"><span class="weather-day-label">Today</span><span class="weather-condition"><i class="bi bi-cloud-sun" data-weather-icon="today" aria-hidden="true"></i><span data-weather-condition="today">Loading...</span></span><strong data-weather-temperature="today">--° / --°</strong></div>'
            . '<div class="weather-forecast-row" data-weather-day="tomorrow"><span class="weather-day-label">Tomorrow</span><span class="weather-condition"><i class="bi bi-cloud-sun" data-weather-icon="tomorrow" aria-hidden="true"></i><span data-weather-condition="tomorrow">Loading...</span></span><strong data-weather-temperature="tomorrow">--° / --°</strong></div>'
            . '</div><div class="overview-local-time"><span><i class="bi bi-clock" aria-hidden="true"></i> Local time</span><strong data-live-clock></strong></div></section>';
    }
    echo '</div><aside class="notification-rail' . ($isDashboard ? '' : ' d-none d-lg-block') . '" aria-label="Notifications">'
       . '<section class="notification-widget card shadow-sm border-0 rounded-3"><div class="notification-widget-heading">'
       . '<div><span class="card-kicker">UPDATES</span><h2>Notifications</h2></div>'
       . ((int)($GLOBALS['wls_unread_notification_count'] ?? 0) > 0
           ? '<span class="notification-pill">' . (int)$GLOBALS['wls_unread_notification_count'] . ' new</span>'
           : '')
       . '</div>' . ($GLOBALS['wls_notification_markup'] ?? '<p class="dropdown-empty">Notifications are unavailable.</p>')
       . (isset($GLOBALS['wls_unread_notification_count']) && (int)$GLOBALS['wls_unread_notification_count'] > 0
           ? '<form method="post" action="' . e(url('/auth/notifications.php')) . '">' . csrf_field()
             . '<button class="notification-mark-read" type="submit"><i class="bi bi-check2-all" aria-hidden="true"></i>Mark all as read</button></form>'
           : '')
       . '</section>' . $weatherWidget . '</aside></div></main><footer class="app-footer container-fluid px-3 px-md-4">Wilson University · Secure student services portal</footer></div></div></body></html>';
}

function notification_items_markup(array $notifications): string
{
    if (!$notifications) {
        return '<p class="dropdown-empty"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>You are all caught up.</span></p>';
    }

    $markup = '<div class="notification-list">';
    foreach ($notifications as $notification) {
        $target = is_string($notification['link']) && str_starts_with($notification['link'], '/')
            ? url($notification['link'])
            : '#';
        $markup .= '<a class="notification-item unread" href="' . e($target) . '">'
            . '<span class="notification-dot" aria-hidden="true"></span><span class="notification-copy"><b>'
            . e($notification['title']) . '</b><span>' . e($notification['body']) . '</span><small>'
            . e(fmt_date($notification['created_at'])) . '</small></span></a>';
    }
    $markup .= '</div>';
    return $markup;
}

function form_open(string $action = '', string $extra = ''): string
{
    return '<form method="post" action="" ' . $extra . '>' . csrf_field()
         . ($action !== '' ? '<input type="hidden" name="action" value="' . e($action) . '">' : '');
}
