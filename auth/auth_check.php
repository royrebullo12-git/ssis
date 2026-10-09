<?php
declare(strict_types=1);

/**
 * auth/auth_check.php - authentication service + RBAC middleware.
 *
 * USAGE on every protected page (first lines, before any output):
 *
 *     define('SSIS_BOOT', true);
 *     require_once __DIR__ . '/../auth/auth_check.php';
 *     $user = require_role('registrar');              // one role
 *     $user = require_role(['registrar', 'admin']);   // several roles
 *     $user = require_login();                        // any signed-in role
 *
 * Security controls implemented here
 *   [SQLi]   Every query uses PDO prepared statements (no string-built input).
 *   [BF]     Per-IP and per-username throttling, per-account lockout, uniform
 *            error messages, dummy hash check for unknown usernames.
 *   [BAC]    Server-side role check on every request; role/status re-read from
 *            the database each time; denied attempts are audit-logged (403).
 *   Also     Session fixation protection, idle + absolute timeouts, UA binding,
 *            CSRF tokens, security headers, no-store caching.
 */

if (!defined('SSIS_BOOT')) {
    define('SSIS_BOOT', true);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

/* ------------------------------------------------------------------ */
/* Small helpers                                                       */
/* ------------------------------------------------------------------ */

/** HTML-escape for output (XSS defence). */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path): string
{
    return APP_BASE . $path;
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/**
 * Client IP. Uses REMOTE_ADDR only: X-Forwarded-For is attacker-controlled
 * unless you sit behind a proxy you trust (then adapt this one function).
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; "
         . "style-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; form-action 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

/* ------------------------------------------------------------------ */
/* Session                                                             */
/* ------------------------------------------------------------------ */

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');   // reject attacker-supplied session IDs
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name('SSIS_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => APP_BASE === '' ? '/' : APP_BASE,
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Remembers why the current request was rejected (used for the login notice). */
function auth_reason(?string $set = null): ?string
{
    static $reason = null;
    if ($set !== null) {
        $reason = $set;
    }
    return $reason;
}

/**
 * Returns the validated signed-in user or null.
 * Re-reads role/status from the DB so disabled accounts and role changes
 * take effect immediately (no stale privileges in the session).
 */
function auth_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;

    if (empty($_SESSION['uid']) || empty($_SESSION['created'])) {
        return null;
    }

    $now = time();
    if ($now - (int)$_SESSION['created'] > SESSION_ABSOLUTE_SECONDS) {
        destroy_session();
        auth_reason('expired');
        return null;
    }
    if ($now - (int)($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_SECONDS) {
        destroy_session();
        auth_reason('timeout');
        return null;
    }
    $fingerprint = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!hash_equals((string)($_SESSION['fp'] ?? ''), $fingerprint)) {
        audit_log('SESSION_HIJACK_SUSPECTED', (int)$_SESSION['uid'], $_SESSION['username'] ?? null);
        destroy_session();
        auth_reason('invalid');
        return null;
    }

    $st = db()->prepare(
        'SELECT id, username, email, role, department_id, status, must_change_password FROM users WHERE id = ? LIMIT 1'
    );
    $st->execute([(int)$_SESSION['uid']]);
    $row = $st->fetch();

    if (!$row || $row['status'] !== 'active' || !in_array($row['role'], ALL_ROLES, true)) {
        destroy_session();
        auth_reason('disabled');
        return null;
    }

    $_SESSION['role']          = $row['role'];   // DB is the source of truth
    $_SESSION['last_activity'] = $now;
    $cache = $row;
    return $cache;
}

/** Gate: any authenticated user. Redirects to login otherwise. */
function require_login(): array
{
    send_security_headers();
    $user = auth_user();
    if ($user === null) {
        $reason = auth_reason();
        redirect('/auth/login.php' . ($reason ? '?reason=' . urlencode($reason) : ''));
        exit;
    }
    return $user;
}

/** Gate: authenticated AND holding one of the allowed roles. Otherwise 403. */
function require_role(string|array $roles): array
{
    $roles = (array)$roles;
    foreach ($roles as $r) {
        if (!in_array($r, ALL_ROLES, true)) {
            throw new InvalidArgumentException("Unknown role: {$r}");
        }
    }

    $user = require_login();

    if (!in_array($user['role'], $roles, true)) {
        audit_log(
            'ACCESS_DENIED',
            (int)$user['id'],
            $user['username'],
            null,
            null,
            'Role ' . $user['role'] . ' requested ' . substr($_SERVER['REQUEST_URI'] ?? '', 0, 200)
        );
        http_response_code(403);
        render_forbidden($user);
        exit;
    }
    return $user;
}

function render_forbidden(array $user): void
{
    $home = url(ROLE_HOME[$user['role']]);
    $css  = e(url('/assets/css/auth.css'));
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Access denied - SSIS</title><link rel="stylesheet" href="' . $css . '"></head>'
       . '<body class="plain"><main class="denied"><h1>You don\'t have access to this page</h1>'
       . '<p>Your account (' . e($user['role']) . ') is not permitted to open it. '
       . 'This attempt has been recorded.</p>'
       . '<a class="btn" href="' . e($home) . '">Back to my dashboard</a></main></body></html>';
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/* ------------------------------------------------------------------ */
/* Audit log                                                           */
/* ------------------------------------------------------------------ */

function audit_log(
    string $action,
    ?int $userId = null,
    ?string $username = null,
    ?string $entity = null,
    ?int $entityId = null,
    ?string $details = null
): void {
    try {
        $st = db()->prepare(
            'INSERT INTO audit_logs (user_id, username, action, entity, entity_id, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $userId,
            $username !== null ? substr($username, 0, 50) : null,
            $action,
            $entity,
            $entityId,
            $details !== null ? substr($details, 0, 500) : null,
            client_ip(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[SSIS] audit_log failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Login service (brute-force protected)                               */
/* ------------------------------------------------------------------ */

// Valid bcrypt hash of a throwaway string. Verified against when the username
// does not exist so response time does not reveal which usernames are real.
const DUMMY_HASH = '$2b$10$sodcD7jMKoXuJDcqJx6WM.689JO9RxJnT2/S37xIUT/VVg9nkizS2';

function record_attempt(string $username, bool $success): void
{
    $st = db()->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)');
    $st->execute([substr($username, 0, 50), client_ip(), $success ? 1 : 0]);

    if (random_int(1, 100) === 1) {  // opportunistic cleanup keeps the table small
        db()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    }
}

function recent_failures(string $column, string $value): int
{
    $col = $column === 'ip_address' ? 'ip_address' : 'username';   // whitelist, never user input
    $st  = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
          WHERE {$col} = ? AND success = 0
            AND attempted_at > (NOW() - INTERVAL " . (int)IP_WINDOW_MINUTES . ' MINUTE)'
    );
    $st->execute([$value]);
    return (int)$st->fetchColumn();
}

/**
 * @return array{ok:bool, message:?string, blocked:bool, user:?array}
 */
function attempt_login(string $username, string $password): array
{
    $username = trim($username);
    $invalid  = ['ok' => false, 'blocked' => false, 'user' => null,
                 'message' => 'The username or password is incorrect.'];
    $blocked  = ['ok' => false, 'blocked' => true, 'user' => null,
                 'message' => 'Too many failed sign-in attempts. Try again in '
                              . LOCKOUT_MINUTES . ' minutes.'];

    // Length caps stop oversized-input abuse (bcrypt/DoS) before touching the DB.
    if ($username === '' || $password === '' || strlen($username) > 50 || strlen($password) > 200) {
        return $invalid;
    }

    $pdo = db();

    // 1) Throttle by IP and by username (covers unknown usernames too, so the
    //    lockout response is identical for real and fake accounts).
    if (recent_failures('ip_address', client_ip()) >= IP_MAX_FAILURES
        || recent_failures('username', $username) >= MAX_FAILED_ATTEMPTS) {
        audit_log('LOGIN_BLOCKED', null, $username, 'user', null, 'Rate limit reached');
        return $blocked;
    }

    // 2) Look up the account (prepared statement).
    $st = $pdo->prepare(
        'SELECT id, username, password_hash, role, status, failed_attempts,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
           FROM users WHERE username = ? LIMIT 1'
    );
    $st->execute([$username]);
    $user = $st->fetch() ?: null;

    if ($user && (int)$user['is_locked'] === 1) {
        audit_log('LOGIN_BLOCKED', (int)$user['id'], $user['username'], 'user', (int)$user['id'], 'Account locked');
        return $blocked;
    }

    // 3) Always run one password_verify so timing is similar either way.
    $hash  = $user['password_hash'] ?? DUMMY_HASH;
    $valid = password_verify($password, $hash) && $user !== null;

    if (!$valid) {
        record_attempt($username, false);
        if ($user) {
            $newCount = (int)$user['failed_attempts'] + 1;
            $up = $pdo->prepare(
                'UPDATE users
                    SET failed_attempts = ?,
                        locked_until = IF(? >= ?, NOW() + INTERVAL ' . (int)LOCKOUT_MINUTES . ' MINUTE, locked_until)
                  WHERE id = ?'
            );
            $up->execute([min($newCount, 255), $newCount, MAX_FAILED_ATTEMPTS, (int)$user['id']]);

            audit_log('LOGIN_FAILED', (int)$user['id'], $user['username'], 'user', (int)$user['id'],
                      "Attempt {$newCount} of " . MAX_FAILED_ATTEMPTS);
            if ($newCount >= MAX_FAILED_ATTEMPTS) {
                audit_log('ACCOUNT_LOCKED', (int)$user['id'], $user['username'], 'user', (int)$user['id'],
                          'Locked for ' . LOCKOUT_MINUTES . ' minutes');
            }
        } else {
            audit_log('LOGIN_FAILED', null, $username, 'user', null, 'Unknown username');
        }
        return $invalid;
    }

    // 4) Correct password but account disabled: same generic message.
    if ($user['status'] !== 'active' || !in_array($user['role'], ALL_ROLES, true)) {
        record_attempt($username, false);
        audit_log('LOGIN_DISABLED', (int)$user['id'], $user['username'], 'user', (int)$user['id'], 'Disabled account');
        return $invalid;
    }

    // 5) Success: reset counters, upgrade hash if needed, rotate session ID.
    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')
        ->execute([(int)$user['id']]);
    $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND success = 0')->execute([$username]);
    record_attempt($username, true);

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
    }

    session_regenerate_id(true);   // session fixation defence
    $_SESSION = [
        'uid'           => (int)$user['id'],
        'username'      => $user['username'],
        'role'          => $user['role'],
        'created'       => time(),
        'last_activity' => time(),
        'fp'            => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''),
        'csrf'          => bin2hex(random_bytes(32)),
    ];

    audit_log('LOGIN_SUCCESS', (int)$user['id'], $user['username'], 'user', (int)$user['id'], 'Role ' . $user['role']);

    return ['ok' => true, 'blocked' => false, 'message' => null,
            'user' => ['id' => (int)$user['id'], 'username' => $user['username'], 'role' => $user['role']]];
}

function logout_user(): void
{
    if (!empty($_SESSION['uid'])) {
        audit_log('LOGOUT', (int)$_SESSION['uid'], $_SESSION['username'] ?? null);
    }
    destroy_session();
}

/* ------------------------------------------------------------------ */
/* Bootstrap: every page that includes this file gets a hardened session */
/* ------------------------------------------------------------------ */
send_security_headers();
start_secure_session();
