<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Create a PDO connection using only the supplied database configuration. */
function app_create_pdo(array $settings): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $settings['host'],
        $settings['port'],
        $settings['database'],
        $settings['charset'] ?? 'utf8mb4'
    );

    $connection = new PDO($dsn, $settings['username'], $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    // Align NOW(), CURRENT_TIMESTAMP, and DATETIME comparisons with the app's WIB schedule inputs.
    $timezone = new DateTimeZone((string) app_config()['app']['timezone']);
    $mysqlOffset = (new DateTimeImmutable('now', $timezone))->format('P');
    $connection->exec('SET time_zone = ' . $connection->quote($mysqlOffset));

    return $connection;
}

/** Connection to the application's own E-Assessment database. */
function db(): PDO
{
    static $connection = null;
    if (!$connection instanceof PDO) {
        $connection = app_create_pdo(app_config()['db']);
    }

    return $connection;
}

/** Read-only directory connection to the separate SSO database. */
function sso_db(): PDO
{
    static $connection = null;
    if (!$connection instanceof PDO) {
        $connection = app_create_pdo(app_config()['sso_db']);
    }

    return $connection;
}

/** Fetch the small, non-sensitive employee profile used by HR assignment screens. */
function sso_find_user(int $userId): ?array
{
    if ($userId < 1) {
        return null;
    }

    $statement = sso_db()->prepare(
        'SELECT id, nik, name, email, position, division, department, username, avatar
         FROM users
         WHERE id = :id AND is_active = 1
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    return $user === false ? null : $user;
}

/**
 * Search active SSO users for participant/reviewer pickers.
 * No password, signature, RFID, or webmail columns are read.
 */
function sso_search_users(string $term = '', int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $term = trim($term);
    $sql = 'SELECT id, nik, name, email, position, division, department, username, avatar
            FROM users
            WHERE is_active = 1';
    $parameters = [];

    if ($term !== '') {
        $sql .= ' AND (name LIKE :term_name OR nik LIKE :term_nik OR email LIKE :term_email OR username LIKE :term_username)';
        $like = '%' . $term . '%';
        $parameters = [
            'term_name' => $like,
            'term_nik' => $like,
            'term_email' => $like,
            'term_username' => $like,
        ];
    }

    $sql .= ' ORDER BY name ASC LIMIT :row_limit';
    $statement = sso_db()->prepare($sql);
    foreach ($parameters as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}
