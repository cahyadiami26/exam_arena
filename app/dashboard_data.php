<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Load only the dashboard data appropriate to the signed-in user's access.
 * Participant accounts never receive organization-wide aggregates or review queues.
 */
function app_dashboard_data(): array
{
    if (!app_is_hr() && !app_is_management()) {
        return app_participant_dashboard_data();
    }

    try {
        $pdo = db();

        $participants = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(participant_type = 'employee'), 0) AS employees,
                    COALESCE(SUM(participant_type = 'candidate'), 0) AS candidates
             FROM schedule_participants
             WHERE status <> 'cancelled'"
        )->fetch() ?: [];

        $activeSessions = (int) $pdo->query(
            "SELECT COUNT(*) FROM exam_attempts WHERE status = 'in_progress'"
        )->fetchColumn();

        $reviewSummary = $pdo->query(
            "SELECT COUNT(*) AS total
             FROM assessment_reviews
             WHERE status IN ('pending', 'in_progress')"
        )->fetch() ?: [];

        $review360 = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'reviewed'), 0) AS completed
             FROM review_assignments
             WHERE status <> 'cancelled'"
        )->fetch() ?: [];

        $passSummary = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(a.total_score >= p.passing_score), 0) AS passed
             FROM exam_attempts a
             INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
             INNER JOIN exam_schedules s ON s.id = sp.schedule_id
             INNER JOIN test_packages p ON p.id = s.package_id
             WHERE a.status = 'completed'
               AND a.total_score IS NOT NULL
               AND p.passing_score IS NOT NULL"
        )->fetch() ?: [];

        $activeRows = $pdo->query(
            "SELECT a.id AS attempt_id, a.started_at, s.name AS schedule_name,
                    s.code AS schedule_code, p.name AS package_name,
                    sp.participant_type, sp.sso_user_id, ec.name AS candidate_name
             FROM exam_attempts a
             INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
             INNER JOIN exam_schedules s ON s.id = sp.schedule_id
             INNER JOIN test_packages p ON p.id = s.package_id
             LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id
             WHERE a.status = 'in_progress'
             ORDER BY a.started_at DESC
             LIMIT 5"
        )->fetchAll();

        $reviews = $pdo->query(
            "SELECT r.id, r.review_type, r.created_at,
                    a.id AS attempt_id, sp.participant_type, sp.sso_user_id,
                    ec.name AS candidate_name, s.name AS schedule_name,
                    p.name AS package_name
             FROM assessment_reviews r
             INNER JOIN exam_attempts a ON a.id = r.attempt_id
             INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
             INNER JOIN exam_schedules s ON s.id = sp.schedule_id
             INNER JOIN test_packages p ON p.id = s.package_id
             LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id
             WHERE r.status IN ('pending', 'in_progress')
             ORDER BY r.created_at ASC
             LIMIT 5"
        )->fetchAll();

        $scheduleRows = $pdo->query(
            "SELECT s.id, s.name, s.code, s.starts_at, s.ends_at, s.status,
                    p.name AS package_name,
                    (SELECT COUNT(*) FROM schedule_participants sp
                     WHERE sp.schedule_id = s.id AND sp.status <> 'cancelled') AS participant_count
             FROM exam_schedules s
             INNER JOIN test_packages p ON p.id = s.package_id
             WHERE s.status IN ('scheduled', 'open') AND s.ends_at >= NOW()
             ORDER BY s.starts_at ASC
             LIMIT 4"
        )->fetchAll();

        $scoreRows = $pdo->query(
            "SELECT q.category, COUNT(*) AS answer_count,
                    SUM(aa.auto_score) AS earned_score,
                    SUM(q.max_score * q.weight) AS possible_score
             FROM attempt_answers aa
             INNER JOIN exam_attempts a ON a.id = aa.attempt_id
             INNER JOIN question_bank q ON q.id = aa.question_id
             WHERE aa.auto_score IS NOT NULL
               AND a.status IN ('submitted', 'auto_submitted', 'pending_review', 'completed')
               AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY q.category
             ORDER BY answer_count DESC
             LIMIT 5"
        )->fetchAll();

        foreach ($scoreRows as &$scoreRow) {
            $possible = (float) ($scoreRow['possible_score'] ?? 0);
            $scoreRow['percentage'] = $possible > 0
                ? round(((float) $scoreRow['earned_score'] / $possible) * 100, 1)
                : null;
        }
        unset($scoreRow);

        // Resolve a small, bounded number of employee names from the read-only SSO DB.
        // If the directory connection is unavailable, the dashboard still renders safely.
        $employeeIds = [];
        foreach (array_merge($activeRows, $reviews) as $row) {
            if (($row['participant_type'] ?? '') === 'employee' && !empty($row['sso_user_id'])) {
                $employeeIds[] = (int) $row['sso_user_id'];
            }
        }
        $employeeNames = [];
        $employeeIds = array_values(array_unique(array_filter($employeeIds)));
        if ($employeeIds !== []) {
            try {
                $placeholders = implode(', ', array_fill(0, count($employeeIds), '?'));
                $statement = sso_db()->prepare(
                    "SELECT id, name, division FROM users WHERE is_active = 1 AND id IN ($placeholders)"
                );
                $statement->execute($employeeIds);
                foreach ($statement->fetchAll() as $employee) {
                    $employeeNames[(int) $employee['id']] = [
                        'name' => $employee['name'],
                        'division' => $employee['division'],
                    ];
                }
            } catch (Throwable $exception) {
                error_log('E-Assessment SSO directory lookup failed: ' . $exception->getMessage());
            }
        }

        foreach ($activeRows as &$row) {
            $ssoId = (int) ($row['sso_user_id'] ?? 0);
            $row['participant_name'] = $row['participant_type'] === 'candidate'
                ? ($row['candidate_name'] ?: 'Calon karyawan')
                : ($employeeNames[$ssoId]['name'] ?? ('Peserta karyawan #' . $ssoId));
            $row['participant_division'] = $employeeNames[$ssoId]['division'] ?? null;
        }
        unset($row);

        foreach ($reviews as &$row) {
            $ssoId = (int) ($row['sso_user_id'] ?? 0);
            $row['participant_name'] = $row['participant_type'] === 'candidate'
                ? ($row['candidate_name'] ?: 'Calon karyawan')
                : ($employeeNames[$ssoId]['name'] ?? ('Peserta karyawan #' . $ssoId));
        }
        unset($row);

        $passTotal = (int) ($passSummary['total'] ?? 0);
        $passCount = (int) ($passSummary['passed'] ?? 0);

        return [
            'available' => true,
            'error' => null,
            'scope' => 'organization',
            'stats' => [
                'participants' => (int) ($participants['total'] ?? 0),
                'employees' => (int) ($participants['employees'] ?? 0),
                'candidates' => (int) ($participants['candidates'] ?? 0),
                'active_sessions' => $activeSessions,
                'pending_reviews' => (int) ($reviewSummary['total'] ?? 0),
                'review360_total' => (int) ($review360['total'] ?? 0),
                'review360_completed' => (int) ($review360['completed'] ?? 0),
                'pass_rate' => $passTotal > 0 ? round(($passCount / $passTotal) * 100, 1) : null,
                'passed' => $passCount,
                'scored_attempts' => $passTotal,
            ],
            'active_sessions' => $activeRows,
            'reviews' => $reviews,
            'schedules' => $scheduleRows,
            'scores' => $scoreRows,
        ];
    } catch (Throwable $exception) {
        error_log('E-Assessment dashboard query failed: ' . $exception->getMessage());

        return [
            'available' => false,
            'error' => 'Data belum dapat dimuat. Periksa koneksi database dan pastikan database/schema.sql sudah diimpor.',
            'scope' => 'organization',
            'stats' => [],
            'active_sessions' => [],
            'reviews' => [],
            'schedules' => [],
            'scores' => [],
        ];
    }
}

/** A participant sees their own schedule assignments only. */
function app_participant_dashboard_data(): array
{
    $user = app_user();
    if (empty($user['id'])) {
        return [
            'available' => false,
            'error' => 'Sesi SSO tidak memuat ID karyawan. Silakan masuk kembali melalui Portal SSO.',
            'scope' => 'participant',
            'schedules' => [],
        ];
    }

    try {
        $statement = db()->prepare(
            "SELECT sp.id AS participant_id, sp.status AS participant_status,
                    s.name AS schedule_name, s.code AS schedule_code,
                    s.starts_at, s.ends_at, s.status AS schedule_status,
                    s.instructions, p.name AS package_name,
                    (SELECT COUNT(*) FROM package_modules pm WHERE pm.package_id = p.id) AS module_count
             FROM schedule_participants sp
             INNER JOIN exam_schedules s ON s.id = sp.schedule_id
             INNER JOIN test_packages p ON p.id = s.package_id
             WHERE sp.participant_type = 'employee'
               AND sp.sso_user_id = :user_id
               AND sp.status <> 'cancelled'
               AND s.ends_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY s.starts_at DESC
             LIMIT 10"
        );
        $statement->execute(['user_id' => (int) $user['id']]);

        return [
            'available' => true,
            'error' => null,
            'scope' => 'participant',
            'schedules' => $statement->fetchAll(),
        ];
    } catch (Throwable $exception) {
        error_log('E-Assessment participant dashboard query failed: ' . $exception->getMessage());

        return [
            'available' => false,
            'error' => 'Daftar ujian belum dapat dimuat. Silakan coba lagi atau hubungi HR.',
            'scope' => 'participant',
            'schedules' => [],
        ];
    }
}
