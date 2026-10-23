<?php
declare(strict_types=1);

/** Shared application bootstrap: configuration, secure SSO session and helpers. */

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config/app.php';
    }

    return $config;
}

function app_request_is_secure(): bool
{
    return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

function app_text_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
}

$config = app_config();
date_default_timezone_set($config['app']['timezone']);

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name($config['session']['name']);

    $cookieOptions = [
        'lifetime' => $config['session']['lifetime'],
        'path' => '/',
        'secure' => app_request_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($config['session']['cookie_domain'] !== '') {
        $cookieOptions['domain'] = $config['session']['cookie_domain'];
    }

    session_set_cookie_params($cookieOptions);
    session_start();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
}

/** Escape text for safe HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Return the normalized identity exposed by the shared LJU SSO session. */
function app_user(): array
{
    $division = $_SESSION['division'] ?? $_SESSION['nama_bidang'] ?? null;
    $department = $_SESSION['department'] ?? $_SESSION['nama_divisi'] ?? null;

    return [
        'id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'nik' => $_SESSION['user_nik'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'name' => $_SESSION['user_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'Pengguna',
        'email' => $_SESSION['user_email'] ?? $_SESSION['email'] ?? null,
        'role' => $_SESSION['role'] ?? 'user',
        'position' => $_SESSION['position'] ?? null,
        // In the application vocabulary, users.division is nama_bidang.
        'nama_bidang' => $division,
        // In the application vocabulary, users.department is nama_divisi.
        'nama_divisi' => $department,
        'avatar' => $_SESSION['avatar'] ?? null,
        'photo' => $_SESSION['photo'] ?? null,
    ];
}

function app_is_authenticated(): bool
{
    return !empty($_SESSION['user_id']);
}

function app_is_admin(): bool
{
    $user = app_user();
    return app_text_lower((string) $user['role']) === 'admin'
        || app_text_lower((string) $user['position']) === 'system administrator';
}

function app_is_hr(): bool
{
    $user = app_user();
    return app_is_admin()
        || in_array(app_text_lower((string) $user['role']), ['hr', 'hrd', 'assessor'], true)
        || app_text_lower((string) $user['nama_bidang']) === 'sdm';
}

function app_is_management(): bool
{
    $user = app_user();
    $position = app_text_lower((string) $user['position']);
    $leadershipPositions = [
        'direktur utama',
        'direktur operasional',
        'kepala bidang',
        'kepala divisi',
        'manager',
        'corporate secretary',
        'sekretaris direksi',
        'sekretaris komisaris',
    ];

    return app_is_admin()
        || app_text_lower((string) $user['role']) === 'management'
        || in_array($position, $leadershipPositions, true)
        || app_text_lower((string) $user['nama_bidang']) === 'direksi'
        || app_text_lower((string) $user['nama_divisi']) === 'direksi';
}

function app_avatar_url(): ?string
{
    $avatar = trim((string) (app_user()['avatar'] ?? ''));
    if ($avatar === '') {
        return null;
    }

    // The SSO stores a filename. Strip path components before constructing its public URL.
    $filename = basename(str_replace('\\', '/', $avatar));
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return null;
    }

    return 'https://dms.lju.co.id/uploads/avatars/' . rawurlencode($filename);
}

function app_user_initials(?string $name = null): string
{
    $name = trim($name ?? (string) (app_user()['name'] ?? ''));
    if ($name === '') {
        return 'LU';
    }

    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $first = $parts[0] ?? 'LJU';
    $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';
    $firstInitial = function_exists('mb_substr') ? mb_substr($first, 0, 1, 'UTF-8') : substr($first, 0, 1);
    $lastInitial = $last !== ''
        ? (function_exists('mb_substr') ? mb_substr($last, 0, 1, 'UTF-8') : substr($last, 0, 1))
        : '';
    $initials = $firstInitial . $lastInitial;

    return function_exists('mb_strtoupper') ? mb_strtoupper($initials, 'UTF-8') : strtoupper($initials);
}

function app_redirect(string $location, int $status = 302): never
{
    if (!headers_sent()) {
        header('Location: ' . $location, true, $status);
    }
    exit;
}

/** Build the shared Portal SSO URL for a safe, local post-login path. */
function app_sso_login_url(?string $redirectAfterLogin = null): string
{
    $config = app_config();
    $requestUri = $redirectAfterLogin ?? (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($requestUri === '' || $requestUri[0] !== '/' || str_starts_with($requestUri, '//') || preg_match('/[\r\n]/', $requestUri)) {
        $requestUri = '/';
    }

    $query = http_build_query([
        'target_system' => $config['sso']['target_system'],
        'redirect_after_login' => $requestUri,
    ], '', '&', PHP_QUERY_RFC3986);
    $separator = str_contains($config['sso']['login_url'], '?') ? '&' : '?';

    return $config['sso']['login_url'] . $separator . $query;
}

/** Redirect unauthenticated employees to the shared Portal SSO. */
function app_require_auth(): void
{
    if (app_is_authenticated()) {
        return;
    }

    app_redirect(app_sso_login_url());
}

function app_require_hr(): void
{
    app_require_auth();
    if (!app_is_hr()) {
        http_response_code(403);
        exit('Akses ditolak. Halaman ini hanya tersedia untuk HR/Admin.');
    }
}

function app_require_management(): void
{
    app_require_auth();
    if (!app_is_management()) {
        http_response_code(403);
        exit('Akses ditolak. Halaman ini hanya tersedia untuk manajemen.');
    }
}

function app_csrf_token(): string
{
    // Keep application keys namespaced because the LJU_APPS session is shared across subdomains.
    if (empty($_SESSION['_eassessment_csrf_token']) || !is_string($_SESSION['_eassessment_csrf_token'])) {
        $_SESSION['_eassessment_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_eassessment_csrf_token'];
}

function app_csrf_input(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e(app_csrf_token()) . '">';
}

function app_verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['_eassessment_csrf_token'])
        && is_string($_SESSION['_eassessment_csrf_token'])
        && hash_equals($_SESSION['_eassessment_csrf_token'], $token);
}

function app_flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_eassessment_flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['_eassessment_flash'][$key] ?? null;
    unset($_SESSION['_eassessment_flash'][$key]);
    return is_string($value) ? $value : null;
}
