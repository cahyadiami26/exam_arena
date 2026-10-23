<?php
declare(strict_types=1);

/**
 * Central configuration. Values are provided by the PHP/web-server environment;
 * this project intentionally does not need Composer or a .env loader.
 */
$env = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};

$appUrl = rtrim((string) $env('APP_URL', 'https://assessment.lju.co.id'), '/');
$dbHost = (string) $env('DB_HOST', '127.0.0.1');
$dbPort = (int) $env('DB_PORT', '3306');
$dbUser = (string) $env('DB_USERNAME', 'assessment_app');
$dbPassword = (string) $env('DB_PASSWORD', '');
$ssoDbHost = (string) $env('SSO_DB_HOST', $dbHost);
$ssoDbPort = (int) $env('SSO_DB_PORT', (string) $dbPort);
$ssoDbUser = (string) $env('SSO_DB_USERNAME', $dbUser);
$ssoDbPassword = (string) $env('SSO_DB_PASSWORD', $dbPassword);

// Unlike most variables, an explicitly empty cookie domain is useful on localhost.
$cookieDomainValue = getenv('SESSION_COOKIE_DOMAIN');
$cookieDomain = $cookieDomainValue === false ? '.lju.co.id' : trim($cookieDomainValue);

return [
    'app' => [
        'name' => (string) $env('APP_NAME', 'E-Assessment PT Lampung Jasa Utama'),
        'environment' => (string) $env('APP_ENV', 'production'),
        'debug' => strtolower((string) $env('APP_DEBUG', 'false')) === 'true',
        'url' => $appUrl,
        'timezone' => (string) $env('APP_TIMEZONE', 'Asia/Jakarta'),
    ],
    'sso' => [
        'login_url' => (string) $env('SSO_LOGIN_URL', 'https://lju.co.id/dashboard/'),
        'target_system' => (string) $env('SSO_TARGET_SYSTEM', $appUrl),
    ],
    'session' => [
        'name' => 'LJU_APPS',
        'cookie_domain' => $cookieDomain,
        'lifetime' => 86400,
    ],
    'db' => [
        'host' => $dbHost,
        'port' => $dbPort,
        'database' => (string) $env('DB_DATABASE', 'lju_eassessment'),
        'username' => $dbUser,
        'password' => $dbPassword,
        'charset' => 'utf8mb4',
    ],
    'sso_db' => [
        'host' => $ssoDbHost,
        'port' => $ssoDbPort,
        'database' => (string) $env('SSO_DB_DATABASE', 'lju_sso'),
        'username' => $ssoDbUser,
        'password' => $ssoDbPassword,
        'charset' => 'utf8mb4',
    ],
    'storage' => [
        // Keep this outside public/ and serve files only through authorized endpoints.
        'root' => dirname(__DIR__) . '/storage',
        'max_upload_bytes' => 20 * 1024 * 1024,
    ],
];
