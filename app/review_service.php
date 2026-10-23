<?php
declare(strict_types=1);

require_once __DIR__ . '/exam_service.php';
require_once __DIR__ . '/papi_profile_service.php';

function assessment_review_require_hr(): void
{
    if (!app_is_hr()) {
        throw new DomainException('Akses penilaian hanya tersedia untuk HR/Admin.');
    }
}

function assessment_review_text(mixed $value, string $label, int $maximumLength): string
{
    $text = is_scalar($value) ? trim((string) $value) : '';
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length > $maximumLength) {
        throw new DomainException($label . ' maksimal ' . number_format($maximumLength, 0, ',', '.') . ' karakter.');
    }

    return $text;
}

function assessment_review_employee_profiles(array $rawIds): array
{
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

function assessment_review_summary(): array
{
    assessment_review_require_hr();
    $normal = db()->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status IN ('pending', 'in_progress')), 0) AS open_count,
                COALESCE(SUM(status = 'completed'), 0) AS completed_count,
                COALESCE(SUM(status IN ('pending', 'in_progress') AND review_type IN ('essay', 'practical')), 0) AS manual_count
         FROM assessment_reviews
         WHERE review_type NOT IN ('papi', 'review_360')"
    )->fetch() ?: [];
    $papi = db()->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(has_open = 1), 0) AS open_count,
                COALESCE(SUM(has_open = 0), 0) AS completed_count
         FROM (
             SELECT attempt_id, MAX(status IN ('pending', 'in_progress')) AS has_open
             FROM assessment_reviews WHERE review_type = 'papi' GROUP BY attempt_id
         ) AS grouped_papi"
    )->fetch() ?: [];
    $review360 = db()->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(has_open = 1), 0) AS open_count,
                COALESCE(SUM(has_open = 0), 0) AS completed_count
         FROM (
             SELECT r.attempt_id, COALESCE(aa.review_assignment_id, 0) AS assignment_key,
                    MAX(r.status IN ('pending', 'in_progress')) AS has_open
             FROM assessment_reviews r
             LEFT JOIN attempt_answers aa ON aa.id = r.answer_id
             WHERE r.review_type = 'review_360'
             GROUP BY r.attempt_id, COALESCE(aa.review_assignment_id, 0)
         ) AS grouped_360"
    )->fetch() ?: [];

    return [
        'total' => (int) ($normal['total'] ?? 0) + (int) ($papi['total'] ?? 0) + (int) ($review360['total'] ?? 0),
        'open' => (int) ($normal['open_count'] ?? 0) + (int) ($papi['open_count'] ?? 0) + (int) ($review360['open_count'] ?? 0),
        'completed' => (int) ($normal['completed_count'] ?? 0) + (int) ($papi['completed_count'] ?? 0) + (int) ($review360['completed_count'] ?? 0),
        'manual' => (int) ($normal['manual_count'] ?? 0),
    ];
}

/** Load a bounded HR queue; employees are resolved from the separate, read-only SSO directory. */
function assessment_review_list(string $statusFilter = 'open', string $typeFilter = 'all'): array
{
    assessment_review_require_hr();
    if (!in_array($statusFilter, ['open', 'completed', 'all'], true)) {
        $statusFilter = 'open';
    }
    $reviewTypes = ['essay', 'practical', 'psychometric', 'papi', 'review_360', 'overall'];
    if ($typeFilter !== 'all' && !in_array($typeFilter, $reviewTypes, true)) {
        $typeFilter = 'all';
    }

    $where = [];
    $parameters = [];
    if ($statusFilter === 'open') {
        $where[] = "r.status IN ('pending', 'in_progress')";
    } elseif ($statusFilter === 'completed') {
        $where[] = "r.status = 'completed'";
    }
    if ($typeFilter !== 'all') {
        $where[] = 'r.review_type = :review_type';
        $parameters['review_type'] = $typeFilter;
    }
    $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
    $sql = "SELECT r.id AS review_id, r.review_type, r.status AS review_status,
                   r.created_at, r.reviewed_at, a.id AS attempt_id, a.attempt_number,
                   a.status AS attempt_status, sp.participant_type, sp.sso_user_id,
                   ec.name AS candidate_name, s.name AS schedule_name, s.code AS schedule_code,
                   p.name AS package_name, aa.question_id, aa.review_assignment_id,
                   q.category, q.question_type, LEFT(q.question_text, 220) AS question_preview,
                   ra.subject_user_id
            FROM assessment_reviews r
            INNER JOIN exam_attempts a ON a.id = r.attempt_id
            INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
            INNER JOIN exam_schedules s ON s.id = sp.schedule_id
            INNER JOIN test_packages p ON p.id = s.package_id
            LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id
            LEFT JOIN attempt_answers aa ON aa.id = r.answer_id
            LEFT JOIN question_bank q ON q.id = aa.question_id
            LEFT JOIN review_assignments ra ON ra.id = aa.review_assignment_id
            $whereSql
            ORDER BY CASE r.status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END,
                     CASE WHEN r.review_type IN ('papi', 'review_360') AND r.status = 'completed' THEN r.reviewed_at END DESC,
                     r.created_at ASC, r.id ASC
            LIMIT 10000";
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    $rows = $statement->fetchAll();

    $groupedRows = [];
    foreach ($rows as $row) {
        $reviewType = (string) $row['review_type'];
        $attemptId = (int) $row['attempt_id'];
        if ($reviewType === 'papi') {
            $groupKey = 'papi:' . $attemptId;
        } elseif ($reviewType === 'review_360') {
            $groupKey = 'review_360:' . $attemptId . ':' . (int) ($row['review_assignment_id'] ?? 0);
        } else {
            $groupKey = 'review:' . (int) $row['review_id'];
        }
        if (!isset($groupedRows[$groupKey])) {
            $groupedRows[$groupKey] = $row;
            $groupedRows[$groupKey]['group_review_count'] = 0;
            $groupedRows[$groupKey]['review_status'] = 'completed';
        }
        $groupedRows[$groupKey]['group_review_count']++;
        if ($row['review_status'] === 'pending'
            || ($row['review_status'] === 'in_progress' && $groupedRows[$groupKey]['review_status'] !== 'pending')) {
            $groupedRows[$groupKey]['review_status'] = (string) $row['review_status'];
        }
        if ($reviewType === 'papi') {
            $groupedRows[$groupKey]['question_preview'] = 'Profil dan jawaban PAPI peserta';
        } elseif ($reviewType === 'review_360') {
            $groupedRows[$groupKey]['question_preview'] = 'Review jawaban per subjek 360°';
        }
    }
    $rows = array_values($groupedRows);
    usort($rows, static function (array $left, array $right): int {
        $priority = ['pending' => 0, 'in_progress' => 1, 'completed' => 2];
        $statusOrder = ($priority[$left['review_status']] ?? 2) <=> ($priority[$right['review_status']] ?? 2);
        if ($statusOrder !== 0) {
            return $statusOrder;
        }
        $createdOrder = strcmp((string) $left['created_at'], (string) $right['created_at']);
        return $createdOrder !== 0 ? $createdOrder : ((int) $left['review_id'] <=> (int) $right['review_id']);
    });
    $rows = array_slice($rows, 0, 250);

    $employeeIds = [];
    foreach ($rows as $row) {
        if ($row['participant_type'] === 'employee') {
            $employeeIds[] = (int) $row['sso_user_id'];
        }
        if ($row['subject_user_id'] !== null) {
            $employeeIds[] = (int) $row['subject_user_id'];
        }
    }
    $profiles = [];
    if ($employeeIds !== []) {
        try {
            $profiles = assessment_review_employee_profiles($employeeIds);
        } catch (Throwable $exception) {
            error_log('E-Assessment review queue SSO lookup failed: ' . $exception->getMessage());
        }
    }
    foreach ($rows as &$row) {
        $ssoId = (int) ($row['sso_user_id'] ?? 0);
        $participant = $profiles[$ssoId] ?? [];
        $row['participant_name'] = $row['participant_type'] === 'candidate'
            ? ($row['candidate_name'] ?: 'Calon karyawan')
            : ($participant['name'] ?? ('Karyawan #' . $ssoId));
        $row['participant_email'] = $participant['email'] ?? '';
        $row['participant_division'] = $participant['division'] ?? '';
        $subjectId = (int) ($row['subject_user_id'] ?? 0);
        $row['subject_name'] = $profiles[$subjectId]['name'] ?? ($subjectId > 0 ? 'Karyawan #' . $subjectId : '');
        $row['review_id'] = (int) $row['review_id'];
        $row['attempt_id'] = (int) $row['attempt_id'];
        $row['attempt_number'] = (int) $row['attempt_number'];
    }
    unset($row);

    return $rows;
}

function assessment_review_group_answers(array $review): array
{
    $attemptId = (int) ($review['attempt_id'] ?? 0);
    $reviewType = (string) ($review['review_type'] ?? '');
    if ($attemptId < 1) {
        return [];
    }
    if ($reviewType === 'papi') {
        $scopeSql = "q.question_type = 'forced_choice'
                     AND (q.papi_item_code IS NOT NULL OR q.category = 'PAPI_KOSTICK')";
        $parameters = ['attempt_id' => $attemptId];
    } elseif ($reviewType === 'review_360') {
        $assignmentId = (int) ($review['review_assignment_id'] ?? 0);
        if ($assignmentId < 1) {
            return [];
        }
        $scopeSql = "m.module_kind = 'review_360' AND aa.review_assignment_id = :review_assignment_id";
        $parameters = ['attempt_id' => $attemptId, 'review_assignment_id' => $assignmentId];
    } else {
        return [];
    }

    $statement = db()->prepare(
        "SELECT aa.id AS answer_id, aa.question_id, aa.answer_text, aa.answer_payload,
                q.question_type, q.category, q.question_text, q.instructions, q.rubric,
                q.question_image_path, q.question_image_alt,
                q.reference_file_path, q.reference_file_name, q.reference_mime_type,
                q.papi_item_code, q.papi_aspect_code, m.name AS module_name
         FROM attempt_answers aa
         INNER JOIN question_bank q ON q.id = aa.question_id
         INNER JOIN assessment_modules m ON m.id = aa.module_id
         LEFT JOIN module_questions mq ON mq.module_id = aa.module_id AND mq.question_id = aa.question_id
         WHERE aa.attempt_id = :attempt_id AND $scopeSql
         ORDER BY aa.module_id, COALESCE(mq.sort_order, 65535), aa.question_id"
    );
    $statement->execute($parameters);
    $answers = $statement->fetchAll();
    if ($answers === []) {
        return [];
    }

    $questionIds = array_values(array_unique(array_map('intval', array_column($answers, 'question_id'))));
    $questionOptions = [];
    if ($questionIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($questionIds), '?'));
        $optionsStatement = db()->prepare(
            "SELECT question_id, id AS option_id, option_key, option_text,
                    papi_aspect_code, option_image_path
             FROM question_options WHERE question_id IN ($placeholders)
             ORDER BY question_id, sort_order, id"
        );
        $optionsStatement->execute($questionIds);
        foreach ($optionsStatement->fetchAll() as $option) {
            $questionOptions[(int) $option['question_id']][(int) $option['option_id']] = $option;
        }
    }

    $answerIds = array_values(array_unique(array_map('intval', array_column($answers, 'answer_id'))));
    $filesByAnswer = [];
    if ($answerIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($answerIds), '?'));
        $filesStatement = db()->prepare(
            "SELECT answer_id, id AS file_id, original_file_name, mime_type,
                    file_size_bytes, uploaded_at
             FROM attempt_answer_files WHERE answer_id IN ($placeholders)
             ORDER BY answer_id, uploaded_at, id"
        );
        $filesStatement->execute($answerIds);
        foreach ($filesStatement->fetchAll() as $file) {
            $filesByAnswer[(int) $file['answer_id']][] = $file;
        }
    }

    foreach ($answers as &$answer) {
        $payload = $answer['answer_payload'] !== null
            ? json_decode((string) $answer['answer_payload'], true)
            : [];
        $payload = is_array($payload) ? $payload : [];
        $roles = [];
        $mostId = (int) ($payload['most_option_id'] ?? 0);
        $leastId = (int) ($payload['least_option_id'] ?? 0);
        if ($mostId > 0) {
            $roles[$mostId] = 'Paling menggambarkan (Most)';
        }
        if ($leastId > 0) {
            $roles[$leastId] = 'Paling tidak menggambarkan (Least)';
        }
        foreach (is_array($payload['selected_option_ids'] ?? null) ? $payload['selected_option_ids'] : [] as $optionId) {
            $optionId = (int) $optionId;
            if ($optionId > 0) {
                $roles[$optionId] = 'Dipilih';
            }
        }
        $singleId = (int) ($payload['selected_option_id'] ?? 0);
        if ($singleId > 0) {
            $roles[$singleId] = 'Dipilih';
        }
        $answer['selected_options'] = [];
        foreach ($roles as $optionId => $role) {
            $option = $questionOptions[(int) $answer['question_id']][(int) $optionId] ?? null;
            if ($option === null) {
                continue;
            }
            $answer['selected_options'][] = [
                'id' => (int) $option['option_id'],
                'label' => $role,
                'option_key' => (string) $option['option_key'],
                'option_text' => (string) $option['option_text'],
                'aspect_code' => (string) ($option['papi_aspect_code'] ?? ''),
                'option_image_path' => (string) ($option['option_image_path'] ?? ''),
            ];
        }
        $answer['answer_text'] = (string) ($answer['answer_text'] ?? '');
        $answer['files'] = $filesByAnswer[(int) $answer['answer_id']] ?? [];
    }
    unset($answer);

    return $answers;
}

function assessment_review_get(int $reviewId): ?array
{
    assessment_review_require_hr();
    if ($reviewId < 1) {
        return null;
    }
    $statement = db()->prepare(
        "SELECT r.id AS review_id, r.attempt_id, r.answer_id, r.review_type,
                r.status AS review_status, r.reviewer_user_id, r.score AS review_score,
                r.recommendation, r.strengths, r.development_areas, r.notes,
                r.created_at, r.reviewed_at,
                a.attempt_number, a.status AS attempt_status, a.objective_score,
                a.manual_score AS attempt_manual_score, a.total_score,
                sp.id AS participant_id, sp.participant_type, sp.sso_user_id,
                ec.name AS candidate_name, ec.email AS candidate_email,
                s.id AS schedule_id, s.name AS schedule_name, s.code AS schedule_code,
                p.name AS package_name, p.passing_score,
                aa.module_id, aa.question_id, aa.review_assignment_id,
                aa.answer_text, aa.answer_payload, aa.auto_score, aa.manual_score,
                aa.review_status AS answer_review_status,
                q.category, q.question_type, q.question_text, q.instructions,
                q.rubric, q.max_score, q.weight, q.papi_item_code, q.papi_aspect_code,
                q.question_image_path, q.question_image_alt,
                q.reference_file_path, q.reference_file_name, q.reference_mime_type,
                ROUND(COALESCE(mq.points_override, q.max_score * q.weight), 2) AS points,
                m.name AS module_name, m.module_kind,
                ra.subject_user_id
         FROM assessment_reviews r
         INNER JOIN exam_attempts a ON a.id = r.attempt_id
         INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
         INNER JOIN exam_schedules s ON s.id = sp.schedule_id
         INNER JOIN test_packages p ON p.id = s.package_id
         LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id
         LEFT JOIN attempt_answers aa ON aa.id = r.answer_id
         LEFT JOIN question_bank q ON q.id = aa.question_id
         LEFT JOIN assessment_modules m ON m.id = aa.module_id
         LEFT JOIN module_questions mq ON mq.module_id = aa.module_id AND mq.question_id = aa.question_id
         LEFT JOIN review_assignments ra ON ra.id = aa.review_assignment_id
         WHERE r.id = :review_id LIMIT 1"
    );
    $statement->execute(['review_id' => $reviewId]);
    $review = $statement->fetch();
    if (!$review) {
        return null;
    }

    $employeeIds = [];
    if ($review['participant_type'] === 'employee') {
        $employeeIds[] = (int) $review['sso_user_id'];
    }
    if ($review['reviewer_user_id'] !== null) {
        $employeeIds[] = (int) $review['reviewer_user_id'];
    }
    if ($review['subject_user_id'] !== null) {
        $employeeIds[] = (int) $review['subject_user_id'];
    }
    $profiles = [];
    if ($employeeIds !== []) {
        try {
            $profiles = assessment_review_employee_profiles($employeeIds);
        } catch (Throwable $exception) {
            error_log('E-Assessment review detail SSO lookup failed: ' . $exception->getMessage());
        }
    }
    $ssoId = (int) ($review['sso_user_id'] ?? 0);
    $participant = $profiles[$ssoId] ?? [];
    $review['participant_name'] = $review['participant_type'] === 'candidate'
        ? ($review['candidate_name'] ?: 'Calon karyawan')
        : ($participant['name'] ?? ('Karyawan #' . $ssoId));
    $review['participant_email'] = $review['candidate_email'] ?: ($participant['email'] ?? '');
    $review['participant_position'] = $participant['position'] ?? '';
    $review['participant_division'] = $participant['division'] ?? '';
    $review['participant_department'] = $participant['department'] ?? '';
    $subjectId = (int) ($review['subject_user_id'] ?? 0);
    $review['subject_name'] = $profiles[$subjectId]['name'] ?? ($subjectId > 0 ? 'Karyawan #' . $subjectId : '');
    $reviewerId = (int) ($review['reviewer_user_id'] ?? 0);
    $review['reviewer_name'] = $profiles[$reviewerId]['name'] ?? ($reviewerId > 0 ? 'Karyawan #' . $reviewerId : '');
    $review['review_id'] = (int) $review['review_id'];
    $review['attempt_id'] = (int) $review['attempt_id'];
    $review['attempt_number'] = (int) $review['attempt_number'];
    $review['answer_payload_decoded'] = [];
    if ($review['answer_payload'] !== null) {
        $decoded = json_decode((string) $review['answer_payload'], true);
        $review['answer_payload_decoded'] = is_array($decoded) ? $decoded : [];
    }
    $review['selected_options'] = [];

    if ($review['question_id'] !== null) {
        $payload = $review['answer_payload_decoded'];
        $optionRoles = [];
        $mostId = (int) ($payload['most_option_id'] ?? 0);
        $leastId = (int) ($payload['least_option_id'] ?? 0);
        if ($mostId > 0) {
            $optionRoles[$mostId] = 'Paling menggambarkan (Most)';
        }
        if ($leastId > 0) {
            $optionRoles[$leastId] = 'Paling tidak menggambarkan (Least)';
        }
        foreach (is_array($payload['selected_option_ids'] ?? null) ? $payload['selected_option_ids'] : [] as $optionId) {
            $optionId = (int) $optionId;
            if ($optionId > 0) {
                $optionRoles[$optionId] = 'Dipilih';
            }
        }
        $singleId = (int) ($payload['selected_option_id'] ?? 0);
        if ($singleId > 0) {
            $optionRoles[$singleId] = 'Dipilih';
        }
        if ($optionRoles !== []) {
            $placeholders = implode(', ', array_fill(0, count($optionRoles), '?'));
            $options = db()->prepare(
                "SELECT id, option_key, option_text, papi_aspect_code, option_image_path
                 FROM question_options WHERE question_id = ? AND id IN ($placeholders)
                 ORDER BY sort_order, id"
            );
            $options->execute(array_merge([(int) $review['question_id']], array_keys($optionRoles)));
            foreach ($options->fetchAll() as $option) {
                $optionId = (int) $option['id'];
                $review['selected_options'][] = [
                    'id' => $optionId,
                    'label' => $optionRoles[$optionId] ?? 'Dipilih',
                    'option_key' => (string) $option['option_key'],
                    'option_text' => (string) $option['option_text'],
                    'aspect_code' => (string) ($option['papi_aspect_code'] ?? ''),
                    'option_image_path' => (string) ($option['option_image_path'] ?? ''),
                ];
            }
        }
    }

    $review['files'] = [];
    if ($review['answer_id'] !== null) {
        $files = db()->prepare(
            'SELECT id AS file_id, original_file_name, mime_type, file_size_bytes, uploaded_at
             FROM attempt_answer_files WHERE answer_id = :answer_id ORDER BY uploaded_at, id'
        );
        $files->execute(['answer_id' => (int) $review['answer_id']]);
        $review['files'] = $files->fetchAll();
    }
    $review['papi_scores'] = [];
    if ($review['review_type'] === 'papi') {
        $scores = db()->prepare(
            'SELECT aspect_code, raw_score FROM attempt_papi_scores WHERE attempt_id = :attempt_id ORDER BY aspect_code'
        );
        $scores->execute(['attempt_id' => $review['attempt_id']]);
        $review['papi_scores'] = $scores->fetchAll();
    }
    $review['review_answers'] = in_array($review['review_type'], ['papi', 'review_360'], true)
        ? assessment_review_group_answers($review)
        : [];

    return $review;
}

function assessment_review_save(int $reviewId, int $reviewerUserId, array $input): array
{
    assessment_review_require_hr();
    if ($reviewId < 1 || $reviewerUserId < 1) {
        throw new DomainException('Data penilaian atau reviewer tidak valid.');
    }
    $recommendation = trim(is_scalar($input['recommendation'] ?? null) ? (string) $input['recommendation'] : '');
    if ($recommendation !== '' && !in_array($recommendation, ['recommended', 'consider', 'not_recommended'], true)) {
        throw new DomainException('Rekomendasi hasil tidak valid.');
    }
    $strengths = assessment_review_text($input['strengths'] ?? '', 'Kekuatan', 10000);
    $development = assessment_review_text($input['development_areas'] ?? '', 'Area pengembangan', 10000);
    $notes = assessment_review_text($input['notes'] ?? '', 'Catatan assessor', 20000);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            "SELECT r.id, r.attempt_id, r.answer_id, r.review_type, r.status,
                    a.status AS attempt_status, sp.id AS participant_id,
                    aa.review_assignment_id, q.question_type,
                    ROUND(COALESCE(mq.points_override, q.max_score * q.weight), 2) AS points
             FROM assessment_reviews r
             INNER JOIN exam_attempts a ON a.id = r.attempt_id
             INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
             LEFT JOIN attempt_answers aa ON aa.id = r.answer_id
             LEFT JOIN question_bank q ON q.id = aa.question_id
             LEFT JOIN module_questions mq ON mq.module_id = aa.module_id AND mq.question_id = aa.question_id
             WHERE r.id = :review_id LIMIT 1 FOR UPDATE"
        );
        $lock->execute(['review_id' => $reviewId]);
        $review = $lock->fetch();
        if (!$review) {
            throw new DomainException('Item penilaian tidak ditemukan.');
        }
        $attemptLock = $pdo->prepare('SELECT id FROM exam_attempts WHERE id = :attempt_id LIMIT 1 FOR UPDATE');
        $attemptLock->execute(['attempt_id' => (int) $review['attempt_id']]);
        if ($attemptLock->fetchColumn() === false) {
            throw new DomainException('Sesi peserta untuk item ini tidak ditemukan.');
        }
        if (!in_array($review['status'], ['pending', 'in_progress'], true)) {
            throw new DomainException('Item ini sudah ditutup dan tidak dapat diubah kembali.');
        }
        if (!in_array($review['attempt_status'], ['submitted', 'auto_submitted', 'pending_review', 'completed'], true)) {
            throw new DomainException('Penilaian hanya dapat dilakukan setelah sesi peserta dikirim.');
        }

        $manualScoreTypes = ['essay', 'practical'];
        $manualScore = null;
        if (in_array($review['review_type'], $manualScoreTypes, true)) {
            if ($review['answer_id'] === null || $review['points'] === null || !is_numeric($review['points'])) {
                throw new DomainException('Bobot maksimum untuk jawaban ini belum tersedia.');
            }
            $scoreRaw = trim(is_scalar($input['score'] ?? null) ? (string) $input['score'] : '');
            if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $scoreRaw)) {
                throw new DomainException('Masukkan skor manual berupa angka dengan maksimal dua angka desimal.');
            }
            $manualScore = (float) $scoreRaw;
            $maximumScore = (float) $review['points'];
            if ($manualScore < 0 || $manualScore > $maximumScore) {
                throw new DomainException('Skor harus berada di antara 0 dan ' . number_format($maximumScore, 2, ',', '.') . '.');
            }
        } elseif (in_array($review['review_type'], ['papi', 'review_360'], true)) {
            if ($recommendation === '') {
                throw new DomainException('Pilih rekomendasi sebelum menutup review peserta.');
            }
            if ($notes === '') {
                throw new DomainException('Isi catatan review HR sebelum menutup review peserta.');
            }
        } elseif ($recommendation === '' && $strengths === '' && $development === '' && $notes === '') {
            throw new DomainException('Tambahkan rekomendasi atau catatan assessor sebelum menutup item review ini.');
        }

        $now = date('Y-m-d H:i:s');
        $updateReview = $pdo->prepare(
            "UPDATE assessment_reviews
             SET status = 'completed', reviewer_user_id = :reviewer_user_id,
                 score = :score, recommendation = :recommendation,
                 strengths = :strengths, development_areas = :development_areas,
                 notes = :notes, reviewed_at = :reviewed_at
             WHERE id = :review_id AND status IN ('pending', 'in_progress')"
        );
        $updateReview->execute([
            'reviewer_user_id' => $reviewerUserId,
            'score' => $manualScore,
            'recommendation' => $recommendation !== '' ? $recommendation : null,
            'strengths' => $strengths !== '' ? $strengths : null,
            'development_areas' => $development !== '' ? $development : null,
            'notes' => $notes !== '' ? $notes : null,
            'reviewed_at' => $now,
            'review_id' => $reviewId,
        ]);
        $reviewType = (string) $review['review_type'];
        $assignmentId = (int) ($review['review_assignment_id'] ?? 0);
        if ($reviewType === 'papi') {
            $pdo->prepare(
                "UPDATE assessment_reviews
                 SET status = 'completed',
                     reviewer_user_id = COALESCE(reviewer_user_id, :reviewer_user_id),
                     reviewed_at = COALESCE(reviewed_at, :reviewed_at)
                 WHERE attempt_id = :attempt_id AND review_type = 'papi'
                   AND id <> :review_id AND status IN ('pending', 'in_progress')"
            )->execute([
                'reviewer_user_id' => $reviewerUserId,
                'reviewed_at' => $now,
                'attempt_id' => (int) $review['attempt_id'],
                'review_id' => $reviewId,
            ]);
            $pdo->prepare(
                "UPDATE attempt_answers aa
                 INNER JOIN question_bank q ON q.id = aa.question_id
                 SET aa.review_status = 'reviewed'
                 WHERE aa.attempt_id = :attempt_id
                   AND q.question_type = 'forced_choice'
                   AND (q.papi_item_code IS NOT NULL OR q.category = 'PAPI_KOSTICK')"
            )->execute(['attempt_id' => (int) $review['attempt_id']]);
        } elseif ($reviewType === 'review_360') {
            if ($assignmentId < 1) {
                throw new DomainException('Penugasan subjek untuk review 360° tidak ditemukan.');
            }
            $pdo->prepare(
                "UPDATE assessment_reviews r
                 INNER JOIN attempt_answers aa ON aa.id = r.answer_id
                 SET r.status = 'completed',
                     r.reviewer_user_id = COALESCE(r.reviewer_user_id, :reviewer_user_id),
                     r.reviewed_at = COALESCE(r.reviewed_at, :reviewed_at)
                 WHERE r.attempt_id = :attempt_id AND r.review_type = 'review_360'
                   AND aa.review_assignment_id = :assignment_id
                   AND r.id <> :review_id AND r.status IN ('pending', 'in_progress')"
            )->execute([
                'reviewer_user_id' => $reviewerUserId,
                'reviewed_at' => $now,
                'attempt_id' => (int) $review['attempt_id'],
                'assignment_id' => $assignmentId,
                'review_id' => $reviewId,
            ]);
            $pdo->prepare(
                "UPDATE attempt_answers aa
                 INNER JOIN assessment_modules m ON m.id = aa.module_id
                 SET aa.review_status = 'reviewed'
                 WHERE aa.attempt_id = :attempt_id
                   AND aa.review_assignment_id = :assignment_id
                   AND m.module_kind = 'review_360'"
            )->execute([
                'attempt_id' => (int) $review['attempt_id'],
                'assignment_id' => $assignmentId,
            ]);
        } elseif ($review['answer_id'] !== null) {
            $pdo->prepare(
                "UPDATE attempt_answers SET manual_score = :manual_score, review_status = 'reviewed'
                 WHERE id = :answer_id AND attempt_id = :attempt_id"
            )->execute([
                'manual_score' => $manualScore,
                'answer_id' => (int) $review['answer_id'],
                'attempt_id' => (int) $review['attempt_id'],
            ]);
        }

        $scoreSummary = $pdo->prepare(
            'SELECT COALESCE(SUM(auto_score), 0) AS objective_score,
                    SUM(manual_score) AS manual_score, COUNT(manual_score) AS manual_count
             FROM attempt_answers WHERE attempt_id = :attempt_id'
        );
        $scoreSummary->execute(['attempt_id' => (int) $review['attempt_id']]);
        $scores = $scoreSummary->fetch() ?: [];
        $objectiveScore = (float) ($scores['objective_score'] ?? 0);
        $manualTotal = (int) ($scores['manual_count'] ?? 0) > 0 ? (float) $scores['manual_score'] : null;
        $pendingReviews = $pdo->prepare(
            "SELECT COUNT(*) FROM assessment_reviews
             WHERE attempt_id = :attempt_id AND status IN ('pending', 'in_progress')"
        );
        $pendingReviews->execute(['attempt_id' => (int) $review['attempt_id']]);
        $pendingReviewCount = (int) $pendingReviews->fetchColumn();
        $pendingReviewGroups = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM assessment_reviews
                 WHERE attempt_id = :normal_attempt_id AND status IN ('pending', 'in_progress')
                   AND review_type NOT IN ('papi', 'review_360'))
                + (SELECT COUNT(*) FROM (
                    SELECT attempt_id FROM assessment_reviews
                    WHERE attempt_id = :papi_attempt_id AND review_type = 'papi'
                      AND status IN ('pending', 'in_progress')
                    GROUP BY attempt_id
                ) AS pending_papi)
                + (SELECT COUNT(*) FROM (
                    SELECT r.attempt_id, COALESCE(aa.review_assignment_id, 0) AS assignment_key
                    FROM assessment_reviews r
                    LEFT JOIN attempt_answers aa ON aa.id = r.answer_id
                    WHERE r.attempt_id = :review360_attempt_id AND r.review_type = 'review_360'
                      AND r.status IN ('pending', 'in_progress')
                    GROUP BY r.attempt_id, COALESCE(aa.review_assignment_id, 0)
                ) AS pending_360)"
        );
        $pendingReviewGroups->execute([
            'normal_attempt_id' => (int) $review['attempt_id'],
            'papi_attempt_id' => (int) $review['attempt_id'],
            'review360_attempt_id' => (int) $review['attempt_id'],
        ]);
        $pendingReviewGroupCount = (int) $pendingReviewGroups->fetchColumn();
        $hasPendingReviews = $pendingReviewCount > 0;
        $attemptStatus = $hasPendingReviews ? 'pending_review' : 'completed';
        $totalScore = $hasPendingReviews ? null : $objectiveScore + ($manualTotal ?? 0.0);
        $pdo->prepare(
            'UPDATE exam_attempts
             SET status = :status, objective_score = :objective_score,
                 manual_score = :manual_score, total_score = :total_score
             WHERE id = :attempt_id'
        )->execute([
            'status' => $attemptStatus,
            'objective_score' => $objectiveScore,
            'manual_score' => $manualTotal,
            'total_score' => $totalScore,
            'attempt_id' => (int) $review['attempt_id'],
        ]);
        $participantAttempts = $pdo->prepare(
            "SELECT COALESCE(SUM(status = 'in_progress'), 0) AS active_count,
                    COALESCE(SUM(status = 'pending_review'), 0) AS review_count
             FROM exam_attempts WHERE schedule_participant_id = :participant_id"
        );
        $participantAttempts->execute(['participant_id' => (int) $review['participant_id']]);
        $participantState = $participantAttempts->fetch() ?: [];
        $participantStatus = (int) ($participantState['active_count'] ?? 0) > 0
            ? 'in_progress'
            : ((int) ($participantState['review_count'] ?? 0) > 0 ? 'pending_review' : 'completed');
        $pdo->prepare('UPDATE schedule_participants SET status = :status WHERE id = :participant_id')
            ->execute([
                'status' => $participantStatus,
                'participant_id' => (int) $review['participant_id'],
            ]);

        $assignmentId = (int) ($review['review_assignment_id'] ?? 0);
        if ($assignmentId > 0) {
            $assignmentPending = $pdo->prepare(
                "SELECT COUNT(*) FROM assessment_reviews r
                 INNER JOIN attempt_answers aa ON aa.id = r.answer_id
                 WHERE aa.review_assignment_id = :assignment_id
                   AND r.status IN ('pending', 'in_progress')"
            );
            $assignmentPending->execute(['assignment_id' => $assignmentId]);
            if ((int) $assignmentPending->fetchColumn() === 0) {
                $pdo->prepare(
                    "UPDATE review_assignments
                     SET status = 'reviewed', completed_at = COALESCE(completed_at, :completed_at)
                     WHERE id = :assignment_id AND status IN ('in_progress', 'submitted', 'pending_review')"
                )->execute(['completed_at' => $now, 'assignment_id' => $assignmentId]);
            }
        }

        $pdo->commit();
        return [
            'review_status' => 'completed',
            'attempt_status' => $attemptStatus,
            'pending_reviews' => $pendingReviewGroupCount,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Build the authorization predicate for a question shown in a per-answer or grouped review. */
function assessment_review_question_scope_sql(): string
{
    return "(
        (r.review_type = 'papi' AND EXISTS (
            SELECT 1 FROM attempt_answers group_answer
            INNER JOIN question_bank group_question ON group_question.id = group_answer.question_id
            WHERE group_answer.attempt_id = r.attempt_id AND group_question.id = q.id
              AND (group_question.papi_item_code IS NOT NULL OR group_question.category = 'PAPI_KOSTICK')
        ))
        OR (r.review_type = 'review_360' AND EXISTS (
            SELECT 1 FROM attempt_answers review_anchor
            INNER JOIN attempt_answers group_answer
                ON group_answer.attempt_id = r.attempt_id
               AND group_answer.review_assignment_id = review_anchor.review_assignment_id
            INNER JOIN assessment_modules group_module ON group_module.id = group_answer.module_id
            WHERE review_anchor.id = r.answer_id AND review_anchor.review_assignment_id IS NOT NULL
              AND group_answer.question_id = q.id AND group_module.module_kind = 'review_360'
        ))
        OR (r.review_type NOT IN ('papi', 'review_360') AND EXISTS (
            SELECT 1 FROM attempt_answers direct_answer
            WHERE direct_answer.id = r.answer_id AND direct_answer.question_id = q.id
        ))
    )";
}

/** Resolve an HR-authorized answer attachment without exposing its storage path. */
function assessment_review_private_file(int $reviewId, int $fileId): ?array
{
    assessment_review_require_hr();
    if ($reviewId < 1 || $fileId < 1) {
        return null;
    }
    $statement = db()->prepare(
        "SELECT f.stored_file_path, f.original_file_name, f.mime_type, f.file_size_bytes
         FROM assessment_reviews r
         INNER JOIN attempt_answer_files f ON f.id = :file_id
         INNER JOIN attempt_answers aa ON aa.id = f.answer_id
         INNER JOIN question_bank q ON q.id = aa.question_id
         INNER JOIN assessment_modules m ON m.id = aa.module_id
         WHERE r.id = :review_id AND (
             (r.review_type = 'papi' AND aa.attempt_id = r.attempt_id
              AND q.question_type = 'forced_choice'
              AND (q.papi_item_code IS NOT NULL OR q.category = 'PAPI_KOSTICK'))
             OR (r.review_type = 'review_360' AND m.module_kind = 'review_360'
                 AND EXISTS (
                     SELECT 1 FROM attempt_answers review_anchor
                     WHERE review_anchor.id = r.answer_id
                       AND review_anchor.review_assignment_id = aa.review_assignment_id
                 ))
             OR (r.review_type NOT IN ('papi', 'review_360') AND r.answer_id = aa.id)
         ) LIMIT 1"
    );
    $statement->execute(['review_id' => $reviewId, 'file_id' => $fileId]);
    $file = $statement->fetch();
    if (!$file) {
        return null;
    }

    $root = realpath(app_config()['storage']['root']);
    if ($root === false) {
        return null;
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . ltrim((string) $file['stored_file_path'], '/\\'));
    if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return null;
    }
    $file['path'] = $path;
    $file['mime_type'] = (string) ($file['mime_type'] ?: 'application/octet-stream');

    return $file;
}

/** Resolve only question assets belonging to the selected review item's answered question. */
function assessment_review_private_question_asset(int $reviewId, string $assetType, int $recordId): ?array
{
    assessment_review_require_hr();
    if ($reviewId < 1 || $recordId < 1) {
        return null;
    }
    $questionScope = assessment_review_question_scope_sql();
    $statement = match ($assetType) {
        'question_image' => db()->prepare(
            "SELECT q.question_image_path AS stored_path,
                    COALESCE(NULLIF(q.question_image_alt, ''), 'question-image') AS original_file_name,
                    NULL AS mime_type
             FROM assessment_reviews r
             INNER JOIN question_bank q ON q.id = :record_id
             WHERE r.id = :review_id AND q.question_image_path IS NOT NULL AND $questionScope LIMIT 1"
        ),
        'option_image' => db()->prepare(
            "SELECT qo.option_image_path AS stored_path,
                    CONCAT('option-', qo.option_key) AS original_file_name,
                    NULL AS mime_type
             FROM assessment_reviews r
             INNER JOIN question_options qo ON qo.id = :record_id
             INNER JOIN question_bank q ON q.id = qo.question_id
             WHERE r.id = :review_id AND qo.option_image_path IS NOT NULL AND $questionScope LIMIT 1"
        ),
        'reference' => db()->prepare(
            "SELECT q.reference_file_path AS stored_path,
                    COALESCE(NULLIF(q.reference_file_name, ''), 'reference-file') AS original_file_name,
                    q.reference_mime_type AS mime_type
             FROM assessment_reviews r
             INNER JOIN question_bank q ON q.id = :record_id
             WHERE r.id = :review_id AND q.reference_file_path IS NOT NULL AND $questionScope LIMIT 1"
        ),
        default => null,
    };
    if (!($statement instanceof PDOStatement)) {
        return null;
    }
    $statement->execute(['review_id' => $reviewId, 'record_id' => $recordId]);
    $asset = $statement->fetch();
    if (!$asset || empty($asset['stored_path'])) {
        return null;
    }

    $root = realpath(app_config()['storage']['root']);
    if ($root === false) {
        return null;
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . ltrim((string) $asset['stored_path'], '/\\'));
    if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return null;
    }
    $mime = trim((string) ($asset['mime_type'] ?? ''));
    if ($mime === '') {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);
    }
    $asset['path'] = $path;
    $asset['mime_type'] = $mime !== '' ? $mime : 'application/octet-stream';

    return $asset;
}
