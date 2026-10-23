<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/papi_profile_service.php';

function assessment_report_parse_date(mixed $value, string $label): DateTimeImmutable
{
    $raw = is_scalar($value) ? trim((string) $value) : '';
    $timezone = new DateTimeZone((string) app_config()['app']['timezone']);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || $date->format('Y-m-d') !== $raw
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new DomainException('Tanggal ' . $label . ' tidak valid.');
    }

    return $date;
}

/** Normalize and bound the report filters shared by HR and management. */
function assessment_report_filters(array $input, bool $managementView = false): array
{
    $timezone = new DateTimeZone((string) app_config()['app']['timezone']);
    $today = new DateTimeImmutable('today', $timezone);
    $defaultFrom = $today->modify('first day of this month');
    $fromRaw = is_scalar($input['from'] ?? null) ? trim((string) $input['from']) : '';
    $toRaw = is_scalar($input['to'] ?? null) ? trim((string) $input['to']) : '';
    $from = $fromRaw !== '' ? assessment_report_parse_date($fromRaw, 'mulai') : $defaultFrom;
    $to = $toRaw !== '' ? assessment_report_parse_date($toRaw, 'selesai') : $today;
    if ($from > $to) {
        throw new DomainException('Tanggal mulai harus sama dengan atau sebelum tanggal selesai.');
    }
    if ($from->diff($to)->days > 3660) {
        throw new DomainException('Rentang laporan maksimal 10 tahun.');
    }

    $packageId = is_scalar($input['package_id'] ?? null)
        ? filter_var($input['package_id'], FILTER_VALIDATE_INT)
        : false;
    $scheduleId = $managementView || !is_scalar($input['schedule_id'] ?? null)
        ? false
        : filter_var($input['schedule_id'], FILTER_VALIDATE_INT);
    $status = $managementView ? 'all' : (is_scalar($input['status'] ?? null) ? (string) $input['status'] : 'all');
    if (!in_array($status, ['all', 'completed', 'pending_review'], true)) {
        $status = 'all';
    }

    return [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'from_at' => $from->format('Y-m-d 00:00:00'),
        'until_at' => $to->modify('+1 day')->format('Y-m-d 00:00:00'),
        'package_id' => is_int($packageId) && $packageId > 0 ? $packageId : 0,
        'schedule_id' => is_int($scheduleId) && $scheduleId > 0 ? $scheduleId : 0,
        'status' => $status,
    ];
}

function assessment_report_schedule_options(): array
{
    if (!app_is_hr()) {
        throw new DomainException('Daftar jadwal laporan hanya tersedia untuk HR/Admin.');
    }

    return db()->query(
        "SELECT s.id, s.code, s.name, s.status, p.name AS package_name,
                (SELECT COUNT(*) FROM exam_attempts a
                 INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
                 WHERE sp.schedule_id = s.id AND a.status <> 'voided') AS attempt_count
         FROM exam_schedules s
         INNER JOIN test_packages p ON p.id = s.package_id
         ORDER BY s.starts_at DESC, s.id DESC LIMIT 250"
    )->fetchAll();
}

function assessment_report_package_options(): array
{
    if (!app_is_hr() && !app_is_management()) {
        throw new DomainException('Daftar paket laporan tidak tersedia untuk akun ini.');
    }

    return db()->query(
        'SELECT id, code, name, package_type, status FROM test_packages ORDER BY name, id DESC'
    )->fetchAll();
}

/** Build an allow-listed SQL filter for an attempt report. */
function assessment_report_attempt_where(array $filters, bool $includeSchedule = true): array
{
    $where = [
        "a.status IN ('submitted', 'auto_submitted', 'pending_review', 'completed')",
        'COALESCE(a.submitted_at, a.started_at) >= :from_at',
        'COALESCE(a.submitted_at, a.started_at) < :until_at',
    ];
    $parameters = [
        'from_at' => $filters['from_at'],
        'until_at' => $filters['until_at'],
    ];
    if ((int) ($filters['package_id'] ?? 0) > 0) {
        $where[] = 'p.id = :package_id';
        $parameters['package_id'] = (int) $filters['package_id'];
    }
    if ($includeSchedule && (int) ($filters['schedule_id'] ?? 0) > 0) {
        $where[] = 's.id = :schedule_id';
        $parameters['schedule_id'] = (int) $filters['schedule_id'];
    }
    if (($filters['status'] ?? 'all') === 'completed') {
        $where[] = "a.status = 'completed'";
    } elseif (($filters['status'] ?? 'all') === 'pending_review') {
        $where[] = "a.status = 'pending_review'";
    }

    return ['sql' => 'WHERE ' . implode(' AND ', $where), 'parameters' => $parameters];
}

function assessment_report_person_key_sql(): string
{
    // Prefix IDs by participant source so an SSO ID can never collide with a candidate ID.
    return "CASE
        WHEN sp.participant_type = 'employee' AND sp.sso_user_id IS NOT NULL THEN CONCAT('employee:', sp.sso_user_id)
        WHEN sp.participant_type = 'candidate' AND sp.candidate_id IS NOT NULL THEN CONCAT('candidate:', sp.candidate_id)
        ELSE NULL
    END";
}

function assessment_report_aggregate(array $filters, bool $includeSchedule = true): array
{
    if ($includeSchedule && !app_is_hr() && !app_is_management()) {
        throw new DomainException('Ringkasan laporan tidak tersedia untuk akun ini.');
    }
    if (!$includeSchedule && (!app_is_management() || app_is_hr())) {
        throw new DomainException('Ringkasan agregat ini hanya tersedia untuk akun manajemen non-HR.');
    }
    $where = assessment_report_attempt_where($filters, $includeSchedule);
    $personKey = assessment_report_person_key_sql();
    $statement = db()->prepare(
        "SELECT COUNT(*) AS attempt_count,
                COUNT(DISTINCT {$personKey}) AS participant_count,
                COUNT(DISTINCT CASE WHEN a.status = 'completed' THEN {$personKey} END) AS completed_participant_count,
                COUNT(DISTINCT CASE WHEN a.status = 'completed' AND a.total_score IS NOT NULL THEN {$personKey} END) AS scored_participant_count,
                COUNT(DISTINCT CASE WHEN a.status = 'completed' AND a.total_score IS NOT NULL AND p.passing_score IS NOT NULL THEN {$personKey} END) AS threshold_participant_count,
                COALESCE(SUM(a.status = 'completed'), 0) AS completed_count,
                COALESCE(SUM(a.status = 'pending_review'), 0) AS pending_review_count,
                COALESCE(SUM(a.status = 'completed' AND a.total_score IS NOT NULL), 0) AS scored_count,
                COALESCE(SUM(a.status = 'completed' AND a.total_score IS NOT NULL AND p.passing_score IS NOT NULL), 0) AS threshold_count,
                COALESCE(SUM(a.status = 'completed' AND a.total_score IS NOT NULL AND p.passing_score IS NOT NULL
                             AND a.total_score >= p.passing_score), 0) AS passed_count
         FROM exam_attempts a
         INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
         INNER JOIN exam_schedules s ON s.id = sp.schedule_id
         INNER JOIN test_packages p ON p.id = s.package_id
         {$where['sql']}"
    );
    $statement->execute($where['parameters']);
    $row = $statement->fetch() ?: [];
    foreach (['attempt_count', 'participant_count', 'completed_participant_count', 'scored_participant_count', 'threshold_participant_count', 'completed_count', 'pending_review_count', 'scored_count', 'threshold_count', 'passed_count'] as $key) {
        $row[$key] = (int) ($row[$key] ?? 0);
    }
    $row['pass_rate'] = $row['threshold_count'] > 0
        ? round(($row['passed_count'] / $row['threshold_count']) * 100, 1)
        : null;

    return $row;
}

function assessment_report_employee_profiles(array $rawIds): array
{
    if (!app_is_hr()) {
        throw new DomainException('Lookup profil peserta hanya tersedia untuk HR/Admin.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $statement = sso_db()->prepare(
        "SELECT id, name, email, position, division, department
         FROM users WHERE id IN ($placeholders)"
    );
    $statement->execute($ids);
    $profiles = [];
    foreach ($statement->fetchAll() as $profile) {
        $profiles[(int) $profile['id']] = $profile;
    }

    return $profiles;
}

/** HR-only attempt details; no SSO role or is_active filter is applied to participant identities. */
function assessment_report_hr_attempts(array $filters, int $limit = 500): array
{
    if (!app_is_hr()) {
        throw new DomainException('Rincian hasil peserta hanya tersedia untuk HR/Admin.');
    }
    $limit = max(1, min(5000, $limit));
    $where = assessment_report_attempt_where($filters, true);
    $statement = db()->prepare(
        "SELECT a.id AS attempt_id, a.attempt_number, a.status AS attempt_status,
                a.started_at, a.submitted_at, a.objective_score, a.manual_score, a.total_score,
                sp.id AS participant_id, sp.participant_type, sp.sso_user_id,
                ec.name AS candidate_name, ec.email AS candidate_email,
                s.id AS schedule_id, s.code AS schedule_code, s.name AS schedule_name,
                p.name AS package_name, p.package_type, p.passing_score,
                (SELECT COUNT(*) FROM assessment_reviews r
                 WHERE r.attempt_id = a.id AND r.status IN ('pending', 'in_progress')) AS pending_review_count
         FROM exam_attempts a
         INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
         INNER JOIN exam_schedules s ON s.id = sp.schedule_id
         INNER JOIN test_packages p ON p.id = s.package_id
         LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id
         {$where['sql']}
         ORDER BY COALESCE(a.submitted_at, a.started_at) DESC, a.id DESC
         LIMIT :row_limit"
    );
    foreach ($where['parameters'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll();

    $employeeIds = [];
    foreach ($rows as $row) {
        if ($row['participant_type'] === 'employee') {
            $employeeIds[] = (int) $row['sso_user_id'];
        }
    }
    $profiles = [];
    if ($employeeIds !== []) {
        try {
            $profiles = assessment_report_employee_profiles($employeeIds);
        } catch (Throwable $exception) {
            error_log('E-Assessment HR report SSO lookup failed: ' . $exception->getMessage());
        }
    }
    foreach ($rows as &$row) {
        $ssoId = (int) ($row['sso_user_id'] ?? 0);
        $profile = $profiles[$ssoId] ?? [];
        $row['participant_name'] = $row['participant_type'] === 'candidate'
            ? ($row['candidate_name'] ?: 'Calon karyawan')
            : ($profile['name'] ?? ('Karyawan #' . $ssoId));
        $row['participant_email'] = $row['participant_type'] === 'candidate'
            ? (string) ($row['candidate_email'] ?? '')
            : (string) ($profile['email'] ?? '');
        $row['participant_division'] = (string) ($profile['division'] ?? '');
        $row['participant_department'] = (string) ($profile['department'] ?? '');
        $row['attempt_id'] = (int) $row['attempt_id'];
        $row['attempt_number'] = (int) $row['attempt_number'];
        $row['pending_review_count'] = (int) $row['pending_review_count'];
    }
    unset($row);

    return $rows;
}

/** Return bounded, per-attempt PAPI raw profiles for HR/Admin without computing norms or interpretation. */
function assessment_report_hr_papi_profiles(array $filters, int $attemptLimit = 50): array
{
    if (!app_is_hr()) {
        throw new DomainException('Skor mentah PAPI hanya tersedia untuk HR/Admin.');
    }
    $attemptLimit = max(1, min(1000, $attemptLimit));
    $where = assessment_report_attempt_where($filters, true);
    $statement = db()->prepare(
        "SELECT selected_attempts.*, papi.aspect_code, papi.raw_score
         FROM (
             SELECT a.id AS attempt_id, a.attempt_number, a.status AS attempt_status,
                    a.started_at, a.submitted_at, sp.participant_type, sp.sso_user_id,
                    ec.name AS candidate_name, s.id AS schedule_id,
                    s.code AS schedule_code, s.name AS schedule_name, p.name AS package_name
             FROM exam_attempts a
             INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
             INNER JOIN exam_schedules s ON s.id = sp.schedule_id
             INNER JOIN test_packages p ON p.id = s.package_id
             LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id
             {$where['sql']}
               AND EXISTS (
                    SELECT 1 FROM attempt_papi_scores available
                    WHERE available.attempt_id = a.id
               )
             ORDER BY COALESCE(a.submitted_at, a.started_at) DESC, a.id DESC
             LIMIT :attempt_limit
         ) AS selected_attempts
         INNER JOIN attempt_papi_scores papi ON papi.attempt_id = selected_attempts.attempt_id
         ORDER BY COALESCE(selected_attempts.submitted_at, selected_attempts.started_at) DESC,
                  selected_attempts.attempt_id DESC, papi.aspect_code"
    );
    foreach ($where['parameters'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':attempt_limit', $attemptLimit, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll();

    $employeeIds = [];
    foreach ($rows as $row) {
        if ($row['participant_type'] === 'employee') {
            $employeeIds[] = (int) $row['sso_user_id'];
        }
    }
    $profiles = [];
    if ($employeeIds !== []) {
        try {
            $profiles = assessment_report_employee_profiles($employeeIds);
        } catch (Throwable $exception) {
            error_log('E-Assessment PAPI report SSO lookup failed: ' . $exception->getMessage());
        }
    }

    $attemptProfiles = [];
    foreach ($rows as $row) {
        $attemptId = (int) $row['attempt_id'];
        if (!isset($attemptProfiles[$attemptId])) {
            $ssoId = (int) ($row['sso_user_id'] ?? 0);
            $employee = $profiles[$ssoId] ?? [];
            $participantType = (string) $row['participant_type'];
            $attemptProfiles[$attemptId] = [
                'attempt_id' => $attemptId,
                'attempt_number' => (int) $row['attempt_number'],
                'attempt_status' => (string) $row['attempt_status'],
                'started_at' => $row['started_at'],
                'submitted_at' => $row['submitted_at'],
                'participant_type' => $participantType,
                'participant_name' => $participantType === 'candidate'
                    ? ($row['candidate_name'] ?: 'Calon karyawan')
                    : ($employee['name'] ?? ('Karyawan #' . $ssoId)),
                'participant_division' => (string) ($employee['division'] ?? ''),
                'participant_department' => (string) ($employee['department'] ?? ''),
                'schedule_id' => (int) $row['schedule_id'],
                'schedule_code' => (string) $row['schedule_code'],
                'schedule_name' => (string) $row['schedule_name'],
                'package_name' => (string) $row['package_name'],
                'scores' => [],
            ];
        }
        $attemptProfiles[$attemptId]['scores'][] = [
            'aspect_code' => (string) $row['aspect_code'],
            'raw_score' => (string) $row['raw_score'],
        ];
    }

    foreach ($attemptProfiles as &$profile) {
        $profile['scores'] = assessment_papi_profile_scores($profile['scores']);
    }
    unset($profile);

    return array_values($attemptProfiles);
}

/** Score-only leaderboard eligibility; PAPI and 360° packages are not rank-comparable. */
function assessment_report_leaderboard_exclusions_sql(): string
{
    return "p.package_type <> 'review_360'
        AND NOT EXISTS (
            SELECT 1 FROM package_modules excluded_360_pm
            INNER JOIN assessment_modules excluded_360_m ON excluded_360_m.id = excluded_360_pm.module_id
            WHERE excluded_360_pm.package_id = p.id AND excluded_360_m.module_kind = 'review_360'
        )
        AND NOT EXISTS (
            SELECT 1 FROM package_modules excluded_papi_pm
            INNER JOIN module_questions excluded_papi_mq ON excluded_papi_mq.module_id = excluded_papi_pm.module_id
            INNER JOIN question_bank excluded_papi_q ON excluded_papi_q.id = excluded_papi_mq.question_id
            WHERE excluded_papi_pm.package_id = p.id
              AND (excluded_papi_q.category = 'PAPI_KOSTICK' OR excluded_papi_q.papi_item_code IS NOT NULL)
        )";
}

/** HR/Admin schedule choices limited to packages with numeric scores that are safe to rank. */
function assessment_report_leaderboard_schedule_options(): array
{
    if (!app_is_hr()) {
        throw new DomainException('Leaderboard hanya tersedia untuk HR/Admin.');
    }
    $eligibility = assessment_report_leaderboard_exclusions_sql();
    $statement = db()->query(
        "SELECT s.id, s.code, s.name, s.status, s.starts_at, s.ends_at,
                p.name AS package_name, p.passing_score,
                (SELECT COUNT(DISTINCT CASE
                    WHEN sp.participant_type = 'employee' AND sp.sso_user_id IS NOT NULL THEN CONCAT('employee:', sp.sso_user_id)
                    WHEN sp.participant_type = 'candidate' AND sp.candidate_id IS NOT NULL THEN CONCAT('candidate:', sp.candidate_id)
                    ELSE NULL END)
                 FROM exam_attempts a
                 INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
                 WHERE sp.schedule_id = s.id AND a.status = 'completed' AND a.total_score IS NOT NULL) AS scored_participant_count
         FROM exam_schedules s
         INNER JOIN test_packages p ON p.id = s.package_id
         WHERE {$eligibility}
         ORDER BY s.starts_at DESC, s.id DESC
         LIMIT 500"
    );
    $schedules = $statement->fetchAll();
    foreach ($schedules as &$schedule) {
        $schedule['id'] = (int) $schedule['id'];
        $schedule['scored_participant_count'] = (int) ($schedule['scored_participant_count'] ?? 0);
        $schedule['passing_score'] = $schedule['passing_score'] !== null ? (float) $schedule['passing_score'] : null;
    }
    unset($schedule);

    return $schedules;
}

/** Rank each participant by their highest completed final score within one eligible schedule. */
function assessment_report_leaderboard(int $scheduleId, int $limit = 500): array
{
    if (!app_is_hr()) {
        throw new DomainException('Leaderboard hanya tersedia untuk HR/Admin.');
    }
    if ($scheduleId < 1) {
        throw new DomainException('Pilih jadwal terlebih dahulu.');
    }
    $limit = max(1, min(1000, $limit));
    $eligibility = assessment_report_leaderboard_exclusions_sql();
    $scheduleCheck = db()->prepare(
        "SELECT s.id FROM exam_schedules s
         INNER JOIN test_packages p ON p.id = s.package_id
         WHERE s.id = :schedule_id AND {$eligibility}
         LIMIT 1"
    );
    $scheduleCheck->execute(['schedule_id' => $scheduleId]);
    if ($scheduleCheck->fetchColumn() === false) {
        throw new DomainException('Jadwal tidak tersedia untuk leaderboard. Paket PAPI dan 360° tidak dirangking.');
    }

    $statement = db()->prepare(
        "WITH attempt_choices AS (
             SELECT a.id AS attempt_id, a.attempt_number, a.total_score, a.submitted_at,
                    sp.id AS participant_id, sp.participant_type, sp.sso_user_id, sp.candidate_id,
                    ec.name AS candidate_name, p.passing_score,
                    ROW_NUMBER() OVER (
                        PARTITION BY CASE
                            WHEN sp.participant_type = 'employee' AND sp.sso_user_id IS NOT NULL THEN CONCAT('employee:', sp.sso_user_id)
                            WHEN sp.participant_type = 'candidate' AND sp.candidate_id IS NOT NULL THEN CONCAT('candidate:', sp.candidate_id)
                            ELSE CONCAT('participant:', sp.id)
                        END
                        ORDER BY a.total_score DESC, COALESCE(a.submitted_at, a.started_at) DESC, a.id DESC
                    ) AS participant_attempt_order
             FROM exam_attempts a
             INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
             INNER JOIN exam_schedules s ON s.id = sp.schedule_id
             INNER JOIN test_packages p ON p.id = s.package_id
             LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id
             WHERE s.id = :schedule_id AND a.status = 'completed' AND a.total_score IS NOT NULL
         ), best_attempts AS (
             SELECT attempt_id, attempt_number, total_score, submitted_at,
                    participant_type, sso_user_id, candidate_id, candidate_name, passing_score
             FROM attempt_choices WHERE participant_attempt_order = 1
         ), ranked_attempts AS (
             SELECT RANK() OVER (ORDER BY total_score DESC) AS leaderboard_rank,
                    best_attempts.*
             FROM best_attempts
         )
         SELECT leaderboard_rank, attempt_id, attempt_number, total_score, submitted_at,
                participant_type, sso_user_id, candidate_id, candidate_name, passing_score
         FROM ranked_attempts
         ORDER BY leaderboard_rank, total_score DESC, attempt_id ASC
         LIMIT :row_limit"
    );
    $statement->bindValue(':schedule_id', $scheduleId, PDO::PARAM_INT);
    $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll();

    $employeeIds = [];
    foreach ($rows as $row) {
        if ($row['participant_type'] === 'employee') {
            $employeeIds[] = (int) $row['sso_user_id'];
        }
    }
    $profiles = [];
    if ($employeeIds !== []) {
        try {
            $profiles = assessment_report_employee_profiles($employeeIds);
        } catch (Throwable $exception) {
            error_log('E-Assessment leaderboard SSO lookup failed: ' . $exception->getMessage());
        }
    }

    foreach ($rows as &$row) {
        $ssoId = (int) ($row['sso_user_id'] ?? 0);
        $employee = $profiles[$ssoId] ?? [];
        $row['participant_name'] = $row['participant_type'] === 'candidate'
            ? ($row['candidate_name'] ?: 'Calon karyawan')
            : ($employee['name'] ?? ('Karyawan #' . $ssoId));
        $row['participant_division'] = (string) ($employee['division'] ?? '');
        $row['participant_department'] = (string) ($employee['department'] ?? '');
        $row['leaderboard_rank'] = (int) $row['leaderboard_rank'];
        $row['attempt_id'] = (int) $row['attempt_id'];
        $row['attempt_number'] = (int) $row['attempt_number'];
        $row['total_score'] = (float) $row['total_score'];
        $row['passing_score'] = $row['passing_score'] !== null ? (float) $row['passing_score'] : null;
        unset($row['sso_user_id'], $row['candidate_id'], $row['candidate_name']);
    }
    unset($row);

    return $rows;
}

/** Aggregate management view groups by package and suppresses score statistics for small cohorts. */
function assessment_report_management_packages(array $filters): array
{
    if (!app_is_management() || app_is_hr()) {
        throw new DomainException('Ringkasan agregat ini hanya tersedia untuk akun manajemen non-HR.');
    }
    $where = assessment_report_attempt_where($filters, false);
    $personKey = assessment_report_person_key_sql();
    $statement = db()->prepare(
        "SELECT p.id AS package_id, p.code AS package_code, p.name AS package_name,
                p.package_type, p.passing_score,
                COUNT(DISTINCT {$personKey}) AS participant_count,
                COUNT(DISTINCT CASE WHEN a.status = 'completed' THEN {$personKey} END) AS completed_participant_count,
                COUNT(DISTINCT CASE WHEN a.status = 'completed' AND a.total_score IS NOT NULL THEN {$personKey} END) AS scored_participant_count,
                COUNT(DISTINCT CASE WHEN a.status = 'completed' AND a.total_score IS NOT NULL AND p.passing_score IS NOT NULL THEN {$personKey} END) AS threshold_participant_count,
                COUNT(*) AS attempt_count,
                COALESCE(SUM(a.status = 'completed'), 0) AS completed_count,
                COALESCE(SUM(a.status = 'pending_review'), 0) AS pending_review_count,
                COALESCE(SUM(a.status = 'completed' AND a.total_score IS NOT NULL AND p.passing_score IS NOT NULL), 0) AS threshold_count,
                COALESCE(SUM(a.status = 'completed' AND a.total_score IS NOT NULL AND p.passing_score IS NOT NULL
                             AND a.total_score >= p.passing_score), 0) AS passed_count,
                AVG(CASE WHEN a.status = 'completed' AND a.total_score IS NOT NULL THEN a.total_score END) AS average_total_score,
                AVG(CASE WHEN a.status = 'completed' AND a.objective_score IS NOT NULL THEN a.objective_score END) AS average_objective_score
         FROM exam_attempts a
         INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
         INNER JOIN exam_schedules s ON s.id = sp.schedule_id
         INNER JOIN test_packages p ON p.id = s.package_id
         {$where['sql']}
         GROUP BY p.id, p.code, p.name, p.package_type, p.passing_score
         ORDER BY p.name, p.id"
    );
    $statement->execute($where['parameters']);
    $rows = $statement->fetchAll();
    foreach ($rows as &$row) {
        foreach (['participant_count', 'completed_participant_count', 'scored_participant_count', 'threshold_participant_count', 'attempt_count', 'completed_count', 'pending_review_count', 'threshold_count', 'passed_count'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        $row['average_total_score'] = $row['average_total_score'] !== null ? (float) $row['average_total_score'] : null;
        $row['average_objective_score'] = $row['average_objective_score'] !== null ? (float) $row['average_objective_score'] : null;
        $row['pass_rate'] = $row['threshold_count'] > 0
            ? round(($row['passed_count'] / $row['threshold_count']) * 100, 1)
            : null;
        $row['average_suppressed'] = $row['scored_participant_count'] < 5;
        $row['pass_rate_suppressed'] = $row['threshold_participant_count'] < 5;
    }
    unset($row);

    return $rows;
}

function assessment_report_csv_safe_text(mixed $value): string
{
    $text = (string) ($value ?? '');
    if (preg_match('/^[\t\r\n ]*[=+@-]/u', $text)) {
        return "'" . $text;
    }

    return $text;
}
