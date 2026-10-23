<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function lobby_normalize_token(string $token): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($token)) ?? '');
}

function lobby_assignment_query(): string
{
    return "SELECT sp.id AS participant_id, sp.schedule_id, sp.participant_type,
                   sp.sso_user_id, sp.candidate_id, sp.status AS participant_status,
                   sp.token_expires_at, s.code AS schedule_code, s.name AS schedule_name,
                   s.status AS schedule_status, s.access_mode, s.starts_at, s.ends_at, s.instructions,
                   s.allow_retake, s.max_attempts, p.id AS package_id,
                   p.name AS package_name, p.status AS package_status, p.package_type,
                   ec.name AS candidate_name, ec.email AS candidate_email,
                   (SELECT COUNT(*) FROM exam_attempts ea
                    WHERE ea.schedule_participant_id = sp.id) AS attempt_count,
                   (SELECT ea.status FROM exam_attempts ea
                    WHERE ea.schedule_participant_id = sp.id
                    ORDER BY ea.attempt_number DESC LIMIT 1) AS latest_attempt_status,
                   (SELECT ea.id FROM exam_attempts ea
                    WHERE ea.schedule_participant_id = sp.id
                    ORDER BY ea.attempt_number DESC LIMIT 1) AS latest_attempt_id
            FROM schedule_participants sp
            INNER JOIN exam_schedules s ON s.id = sp.schedule_id
            INNER JOIN test_packages p ON p.id = s.package_id
            LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id";
}

/** Return assigned and recently completed employee schedules for the current SSO ID. */
function lobby_employee_schedules(int $userId): array
{
    if ($userId < 1) {
        return [];
    }

    $statement = db()->prepare(
        lobby_assignment_query() . "
         WHERE sp.participant_type = 'employee'
           AND s.access_mode IN ('employee_sso', 'mixed')
           AND sp.sso_user_id = :user_id
           AND sp.status <> 'cancelled'
           AND s.status <> 'draft'
           AND s.ends_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         ORDER BY s.starts_at DESC
         LIMIT 20"
    );
    $statement->execute(['user_id' => $userId]);

    return lobby_attach_modules($statement->fetchAll());
}

/** Validate a candidate's email + one-time schedule token without exposing which part failed. */
function lobby_verify_candidate_token(string $email, string $token): ?array
{
    $email = strtolower(trim($email));
    $token = lobby_normalize_token($token);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($token) < 8 || strlen($token) > 128) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $statement = db()->prepare(
        lobby_assignment_query() . "
         WHERE sp.participant_type = 'candidate'
           AND p.package_type <> 'review_360'
           AND s.access_mode IN ('external_token', 'mixed')
           AND sp.invite_token_hash = :token_hash
           AND LOWER(TRIM(ec.email)) = :email
           AND sp.status <> 'cancelled'
           AND (sp.token_expires_at IS NULL OR sp.token_expires_at > NOW())
           AND s.status <> 'draft'
           AND s.ends_at >= NOW()
         LIMIT 1"
    );
    $statement->execute([
        'token_hash' => $tokenHash,
        'email' => $email,
    ]);
    $assignment = $statement->fetch();

    if ($assignment === false) {
        return null;
    }

    $assignments = lobby_attach_modules([$assignment]);
    return $assignments[0] ?? null;
}

/** Reload and revalidate an external candidate session against the database. */
function lobby_candidate_assignment_from_session(): ?array
{
    $access = $_SESSION['_eassessment_candidate_access'] ?? null;
    if (!is_array($access)) {
        return null;
    }

    $participantId = (int) ($access['participant_id'] ?? 0);
    $candidateId = (int) ($access['candidate_id'] ?? 0);
    if ($participantId < 1 || $candidateId < 1) {
        unset($_SESSION['_eassessment_candidate_access']);
        return null;
    }

    $statement = db()->prepare(
        lobby_assignment_query() . "
         WHERE sp.id = :participant_id
           AND sp.candidate_id = :candidate_id
           AND sp.participant_type = 'candidate'
           AND p.package_type <> 'review_360'
           AND s.access_mode IN ('external_token', 'mixed')
           AND sp.invite_token_hash IS NOT NULL
           AND sp.status <> 'cancelled'
           AND (sp.token_expires_at IS NULL OR sp.token_expires_at > NOW())
         LIMIT 1"
    );
    $statement->execute([
        'participant_id' => $participantId,
        'candidate_id' => $candidateId,
    ]);
    $assignment = $statement->fetch();

    if ($assignment === false) {
        unset($_SESSION['_eassessment_candidate_access']);
        return null;
    }

    $assignments = lobby_attach_modules([$assignment]);
    return $assignments[0] ?? null;
}

/** Attach published module summaries to the limited set of schedule rows. */
function lobby_attach_modules(array $assignments): array
{
    if ($assignments === []) {
        return [];
    }

    $packageIds = array_values(array_unique(array_map(
        static fn (array $assignment): int => (int) $assignment['package_id'],
        $assignments
    )));
    $placeholders = implode(', ', array_fill(0, count($packageIds), '?'));
    $statement = db()->prepare(
        "SELECT pm.package_id, m.id AS module_id, m.name AS module_name,
                m.category, m.module_kind, m.duration_minutes,
                COALESCE(pm.duration_override_minutes, m.duration_minutes) AS effective_duration,
                m.question_selection_mode, m.random_question_count,
                (SELECT COUNT(*) FROM module_questions mq WHERE mq.module_id = m.id) AS question_pool_size
         FROM package_modules pm
         INNER JOIN assessment_modules m ON m.id = pm.module_id
         WHERE pm.package_id IN ($placeholders)
         ORDER BY pm.package_id, pm.sort_order"
    );
    $statement->execute($packageIds);

    $modulesByPackage = [];
    foreach ($statement->fetchAll() as $module) {
        $poolSize = (int) $module['question_pool_size'];
        $questionCount = $poolSize;
        if ($module['question_selection_mode'] === 'random' && $module['random_question_count'] !== null) {
            $questionCount = min($poolSize, (int) $module['random_question_count']);
        }
        $module['question_count'] = $questionCount;
        $modulesByPackage[(int) $module['package_id']][] = $module;
    }

    foreach ($assignments as &$assignment) {
        $modules = $modulesByPackage[(int) $assignment['package_id']] ?? [];
        $assignment['modules'] = $modules;
        $assignment['total_duration_minutes'] = array_sum(array_map(
            static fn (array $module): int => (int) $module['effective_duration'],
            $modules
        ));
        $assignment['total_questions'] = array_sum(array_map(
            static fn (array $module): int => (int) $module['question_count'],
            $modules
        ));
        $assignment['attempt_count'] = (int) ($assignment['attempt_count'] ?? 0);
        $assignment['max_attempts'] = max(1, (int) ($assignment['max_attempts'] ?? 1));
        $assignment['allow_retake'] = (bool) ($assignment['allow_retake'] ?? false);
    }
    unset($assignment);

    return $assignments;
}

/**
 * Evaluate the schedule window and retry policy. This controls the lobby CTA;
 * the execution endpoint must repeat the same checks server-side when it is added.
 */
function lobby_start_state(array $assignment): array
{
    $now = time();
    $startsAt = strtotime((string) ($assignment['starts_at'] ?? '')) ?: 0;
    $endsAt = strtotime((string) ($assignment['ends_at'] ?? '')) ?: 0;
    $inWindow = $assignment['schedule_status'] === 'open' && $startsAt <= $now && $endsAt >= $now;
    $attemptCount = (int) ($assignment['attempt_count'] ?? 0);
    $latestStatus = (string) ($assignment['latest_attempt_status'] ?? '');
    $maxAttempts = max(1, (int) ($assignment['max_attempts'] ?? 1));
    $allowRetake = (bool) ($assignment['allow_retake'] ?? false);

    if ($assignment['package_status'] !== 'published') {
        return ['available' => false, 'in_window' => $inWindow, 'reason' => 'Paket ujian belum diterbitkan.'];
    }
    if (!$inWindow) {
        if ($assignment['schedule_status'] === 'scheduled' || $startsAt > $now) {
            $reason = 'Tombol mulai akan aktif saat jadwal dibuka.';
        } elseif ($endsAt > 0 && $endsAt < $now) {
            $reason = 'Waktu pelaksanaan ujian telah berakhir.';
        } else {
            $reason = 'Jadwal ujian belum dibuka oleh HR.';
        }
        return ['available' => false, 'in_window' => false, 'reason' => $reason];
    }
    if ($latestStatus === 'in_progress') {
        return ['available' => true, 'in_window' => true, 'reason' => 'Sesi ujian sedang berlangsung.'];
    }
    if ($attemptCount > 0 && (!$allowRetake || $attemptCount >= $maxAttempts)) {
        return ['available' => false, 'in_window' => true, 'reason' => 'Kesempatan ujian telah digunakan.'];
    }

    return ['available' => true, 'in_window' => true, 'reason' => 'Jadwal sedang dibuka. Periksa perangkat sebelum memulai.'];
}
