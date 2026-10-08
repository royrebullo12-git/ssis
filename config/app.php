<?php
declare(strict_types=1);

if (!defined('SSIS_BOOT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

// URL prefix of the project folder, no trailing slash.
// XAMPP at http://localhost/ssis/  ->  '/ssis'.  Docroot deployment -> ''.
define('APP_BASE', rtrim(getenv('SSIS_BASE') !== false ? (string)getenv('SSIS_BASE') : '/ssis', '/'));

// Brute-force controls
define('MAX_FAILED_ATTEMPTS', 5);       // per account before lockout
define('LOCKOUT_MINUTES', 15);          // account lock duration
define('IP_MAX_FAILURES', 15);          // failed attempts per IP inside the window
define('IP_WINDOW_MINUTES', 15);

// Session controls
define('SESSION_IDLE_SECONDS', 1800);   // 30 min idle timeout
define('SESSION_ABSOLUTE_SECONDS', 28800); // 8 h hard limit

// Current academic term (change each semester)
define('CURRENT_SY', '2025-2026');
define('CURRENT_SEM', '1st');

// Role => landing page (relative to APP_BASE)
const ROLE_HOME = [
    'student'    => '/student/dashboard.php',
    'registrar'  => '/registrar/dashboard.php',
    'cashier'    => '/cashier/dashboard.php',
    'department' => '/department/dashboard.php',
    'professor'  => '/professor/dashboard.php',
    'admin'      => '/admin/dashboard.php',
];

const ALL_ROLES = ['admin', 'registrar', 'cashier', 'department', 'professor', 'student'];

error_reporting(E_ALL);
ini_set('display_errors', '0');   // never leak stack traces
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Manila');
