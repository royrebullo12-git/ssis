<?php
declare(strict_types=1);

/**
 * Secure PDO connection (SQLi defence layer 1).
 *
 * - Credentials come from environment variables, with local XAMPP defaults.
 * - ATTR_EMULATE_PREPARES = false  -> real server-side prepared statements, so
 *   user input is never concatenated into SQL text.
 * - Errors are logged, never shown to the browser.
 */

if (!defined('SSIS_BOOT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host    = getenv('SSIS_DB_HOST') ?: '127.0.0.1';
    $port    = getenv('SSIS_DB_PORT') ?: '3306';
    $name    = getenv('SSIS_DB_NAME') ?: 'ssis_db';
    $user    = getenv('SSIS_DB_USER') ?: 'root';
    $pass    = getenv('SSIS_DB_PASS') !== false ? getenv('SSIS_DB_PASS') : '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        error_log('[SSIS] DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Service temporarily unavailable. Please try again later.');
    }

    return $pdo;
}
