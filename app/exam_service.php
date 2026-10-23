<?php
declare(strict_types=1);

require_once __DIR__ . '/lobby_service.php';

/** Create (or resume) an attempt after rechecking identity, schedule window and retry policy. */
function exam_start_attempt(int $participantId, string $identityType): int
{
    if (!in_array($identityType, ['employee', 'candidate'], true) || $participantId < 1) {
        throw new DomainException('Data peserta tidak valid.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            "SELECT sp.id, sp.schedule_id, sp.participant_type, sp.sso_user_id,
                    sp.candidate_id, sp.status AS participant_status,
                    s.access_mode, s.name AS schedule_name, s.status AS schedule_status,
                    s.starts_at, s.ends_at, s.allow_retake, s.max_attempts,
                    p.id AS package_id, p.name AS package_name, p.status AS package_status, p.package_type
             FROM schedule_participants sp
             INNER JOIN exam_schedules s ON s.id = sp.schedule_id
             INNER JOIN test_packages p ON p.id = s.package_id
             WHERE sp.id = :participant_id
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['participant_id' => $participantId]);
        $participant = $statement->fetch();
        if (!$participant || $participant['participant_type'] !== $identityType || $participant['participant_status'] === 'cancelled') {
            throw new DomainException('Penugasan ujian tidak ditemukan atau sudah dibatalkan.');
        }

        if ($identityType === 'employee') {
            $user = app_user();
            if (!app_is_authenticated() || (int) $user['id'] !== (int) $participant['sso_user_id']) {
                throw new DomainException('Silakan masuk dengan akun SSO yang menerima penugasan ujian.');
            }
            if (!in_array($participant['access_mode'], ['employee_sso', 'mixed'], true)) {
                throw new DomainException('Jadwal ini tidak menerima akses karyawan SSO.');
            }
        } else {
            $candidateAccess = $_SESSION['_eassessment_candidate_access'] ?? null;
            if (!is_array($candidateAccess)
                || (int) ($candidateAccess['participant_id'] ?? 0) !== $participantId
                || (int) ($candidateAccess['candidate_id'] ?? 0) !== (int) $participant['candidate_id']) {
                throw new DomainException('Verifikasi email dan token kandidat terlebih dahulu.');
            }
            if (!in_array($participant['access_mode'], ['external_token', 'mixed'], true)) {
                throw new DomainException('Jadwal ini tidak menerima akses token kandidat.');
            }
            if ($participant['package_type'] === 'review_360') {
                throw new DomainException('Evaluasi 360° hanya tersedia bagi karyawan SSO.');
            }
        }

        $now = time();
        $startsAt = strtotime((string) $participant['starts_at']) ?: 0;
        $endsAt = strtotime((string) $participant['ends_at']) ?: 0;
        if ($participant['schedule_status'] !== 'open' || $startsAt > $now || $endsAt < $now) {
            throw new DomainException('Ujian hanya dapat dimulai ketika jadwal berstatus dibuka dan berada dalam rentang waktu pelaksanaan.');
        }
        if ($participant['package_status'] !== 'published') {
            throw new DomainException('Paket ujian belum diterbitkan oleh HR.');
        }

        $modulesStatement = $pdo->prepare(
            "SELECT pm.id AS package_module_id, pm.module_id, pm.sort_order,
                    COALESCE(pm.duration_override_minutes, m.duration_minutes) AS duration_minutes,
                    m.name AS module_name, m.status AS module_status, m.module_kind,
                    m.question_selection_mode, m.random_question_count,
                    m.shuffle_questions, m.shuffle_options
             FROM package_modules pm
             INNER JOIN assessment_modules m ON m.id = pm.module_id
             WHERE pm.package_id = :package_id
             ORDER BY pm.sort_order FOR UPDATE"
        );
        $modulesStatement->execute(['package_id' => (int) $participant['package_id']]);
        $modules = $modulesStatement->fetchAll();
        if ($modules === []) {
            throw new DomainException('Paket ujian belum memiliki modul. Hubungi HR.');
        }
        foreach ($modules as $module) {
            if ($module['module_status'] !== 'published') {
                throw new DomainException('Salah satu modul belum diterbitkan. Hubungi HR.');
            }
        }

        $has360 = count(array_filter($modules, static fn (array $module): bool => $module['module_kind'] === 'review_360')) > 0;
        if ($has360) {
            if ($identityType !== 'employee') {
                throw new DomainException('Evaluasi 360° hanya tersedia bagi karyawan yang ditugaskan.');
            }
            $reviewerId = (int) $participant['sso_user_id'];
            $targetStatement = $pdo->prepare(
                "SELECT COUNT(*) FROM review_assignments
                 WHERE schedule_id = :schedule_id AND reviewer_user_id = :reviewer_id AND status <> 'cancelled'"
            );
            $targetStatement->execute([
                'schedule_id' => (int) $participant['schedule_id'],
                'reviewer_id' => $reviewerId,
            ]);
            if ((int) $targetStatement->fetchColumn() === 0) {
                throw new DomainException('Belum ada karyawan yang ditugaskan untuk Anda nilai dalam evaluasi 360°.');
            }
        }

        $attemptStatement = $pdo->prepare(
            "SELECT id, attempt_number, status FROM exam_attempts
             WHERE schedule_participant_id = :participant_id
             ORDER BY attempt_number DESC LIMIT 1 FOR UPDATE"
        );
        $attemptStatement->execute(['participant_id' => $participantId]);
        $latestAttempt = $attemptStatement->fetch();
        if ($latestAttempt && $latestAttempt['status'] === 'in_progress') {
            $pdo->commit();
            return (int) $latestAttempt['id'];
        }

        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM exam_attempts WHERE schedule_participant_id = :participant_id');
        $countStatement->execute(['participant_id' => $participantId]);
        $attemptCount = (int) $countStatement->fetchColumn();
        $maxAttempts = max(1, (int) $participant['max_attempts']);
        if ($attemptCount > 0 && (!(bool) $participant['allow_retake'] || $attemptCount >= $maxAttempts)) {
            throw new DomainException('Kesempatan ujian Anda telah digunakan.');
        }

        $attemptNumber = $attemptCount + 1;
        $insertAttempt = $pdo->prepare(
            "INSERT INTO exam_attempts (schedule_participant_id, attempt_number, status, started_at)
             VALUES (:participant_id, :attempt_number, 'in_progress', :started_at)"
        );
        $insertAttempt->execute([
            'participant_id' => $participantId,
            'attempt_number' => $attemptNumber,
            'started_at' => date('Y-m-d H:i:s'),
        ]);
        $attemptId = (int) $pdo->lastInsertId();

        $insertProgress = $pdo->prepare(
            "INSERT INTO attempt_module_progress (attempt_id, package_module_id, status, time_remaining_seconds)
             VALUES (:attempt_id, :package_module_id, 'not_started', :seconds)"
        );
        $questionStatement = $pdo->prepare(
            "SELECT mq.question_id, mq.sort_order, q.question_type
             FROM module_questions mq
             INNER JOIN question_bank q ON q.id = mq.question_id
             WHERE mq.module_id = :module_id AND q.is_active = 1
             ORDER BY mq.sort_order FOR UPDATE"
        );
        $optionStatement = $pdo->prepare(
            'SELECT id FROM question_options WHERE question_id = :question_id ORDER BY sort_order, id FOR UPDATE'
        );
        $insertAttemptQuestion = $pdo->prepare(
            'INSERT INTO attempt_module_questions (attempt_module_progress_id, question_id, sort_order) VALUES (:progress_id, :question_id, :sort_order)'
        );
        $insertAttemptOption = $pdo->prepare(
            'INSERT INTO attempt_question_options (attempt_module_question_id, question_option_id, sort_order) VALUES (:attempt_question_id, :option_id, :sort_order)'
        );

        foreach ($modules as $module) {
            $durationSeconds = max(1, (int) $module['duration_minutes']) * 60;
            $insertProgress->execute([
                'attempt_id' => $attemptId,
                'package_module_id' => (int) $module['package_module_id'],
                'seconds' => $durationSeconds,
            ]);
            $progressId = (int) $pdo->lastInsertId();

            $questionStatement->execute(['module_id' => (int) $module['module_id']]);
            $questions = $questionStatement->fetchAll();
            if ($module['question_selection_mode'] === 'random' && $module['random_question_count'] !== null) {
                shuffle($questions);
                $questions = array_slice($questions, 0, max(0, (int) $module['random_question_count']));
            }
            if ((bool) $module['shuffle_questions']) {
                shuffle($questions);
            }

            foreach ($questions as $questionIndex => $question) {
                $insertAttemptQuestion->execute([
                    'progress_id' => $progressId,
                    'question_id' => (int) $question['question_id'],
                    'sort_order' => $questionIndex + 1,
                ]);
                $attemptQuestionId = (int) $pdo->lastInsertId();
                if (!in_array($question['question_type'], ['single_choice', 'multi_select', 'forced_choice'], true)) {
                    continue;
                }

                $optionStatement->execute(['question_id' => (int) $question['question_id']]);
                $options = $optionStatement->fetchAll(PDO::FETCH_COLUMN);
                if ((bool) $module['shuffle_options']) {
                    shuffle($options);
                }
                foreach ($options as $optionIndex => $optionId) {
                    $insertAttemptOption->execute([
                        'attempt_question_id' => $attemptQuestionId,
                        'option_id' => (int) $optionId,
                        'sort_order' => $optionIndex + 1,
                    ]);
                }
            }
        }

        $participantUpdate = $pdo->prepare("UPDATE schedule_participants SET status = 'in_progress' WHERE id = :participant_id");
        $participantUpdate->execute(['participant_id' => $participantId]);
        $pdo->commit();

        return $attemptId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Load an attempt and enforce that the current SSO/token session owns it. */
function exam_get_attempt_context(int $attemptId): array
{
    if ($attemptId < 1) {
        throw new DomainException('Sesi ujian tidak valid.');
    }

    $statement = db()->prepare(
        "SELECT a.*, sp.id AS participant_id, sp.participant_type, sp.sso_user_id,
                sp.candidate_id, sp.status AS participant_status,
                s.id AS schedule_id, s.code AS schedule_code, s.name AS schedule_name,
                s.status AS schedule_status, s.starts_at, s.ends_at, s.package_id,
                p.name AS package_name, p.status AS package_status, p.passing_score,
                ec.name AS candidate_name
         FROM exam_attempts a
         INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
         INNER JOIN exam_schedules s ON s.id = sp.schedule_id
         INNER JOIN test_packages p ON p.id = s.package_id
         LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id
         WHERE a.id = :attempt_id LIMIT 1"
    );
    $statement->execute(['attempt_id' => $attemptId]);
    $attempt = $statement->fetch();
    if (!$attempt) {
        throw new DomainException('Sesi ujian tidak ditemukan.');
    }

    if ($attempt['participant_type'] === 'employee') {
        $user = app_user();
        if (!app_is_authenticated() || (int) $user['id'] !== (int) $attempt['sso_user_id']) {
            throw new DomainException('Sesi ujian ini bukan milik akun SSO Anda.');
        }
    } else {
        $access = $_SESSION['_eassessment_candidate_access'] ?? null;
        if (!is_array($access)
            || (int) ($access['participant_id'] ?? 0) !== (int) $attempt['participant_id']
            || (int) ($access['candidate_id'] ?? 0) !== (int) $attempt['candidate_id']) {
            throw new DomainException('Akses kandidat tidak valid. Masuk kembali menggunakan email dan token undangan.');
        }
    }

    return $attempt;
}

function exam_active_progress(int $attemptId, ?int $progressId = null): ?array
{
    $sql = "SELECT amp.id AS progress_id, amp.attempt_id, amp.package_module_id,
                   amp.status AS progress_status, amp.started_at, amp.completed_at,
                   amp.time_remaining_seconds, pm.module_id, pm.sort_order,
                   COALESCE(pm.duration_override_minutes, m.duration_minutes) AS duration_minutes,
                   m.name AS module_name, m.category, m.module_kind,
                   m.description AS module_description
            FROM attempt_module_progress amp
            INNER JOIN package_modules pm ON pm.id = amp.package_module_id
            INNER JOIN assessment_modules m ON m.id = pm.module_id
            WHERE amp.attempt_id = :attempt_id";
    $parameters = ['attempt_id' => $attemptId];
    if ($progressId !== null) {
        $sql .= ' AND amp.id = :progress_id';
        $parameters['progress_id'] = $progressId;
    } else {
        $sql .= " AND amp.status IN ('in_progress', 'not_started') ORDER BY pm.sort_order ASC LIMIT 1";
    }

    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    $progress = $statement->fetch();

    return $progress === false ? null : $progress;
}

function exam_remaining_seconds(array $progress): int
{
    $duration = max(1, (int) $progress['duration_minutes']) * 60;
    $startedAt = strtotime((string) ($progress['started_at'] ?? '')) ?: time();

    return max(0, $duration - (time() - $startedAt));
}

/** Move to the next module, or finalize the attempt when all modules are complete. */
function exam_prepare_attempt(int $attemptId, ?int $reviewAssignmentId = null): array
{
    $attempt = exam_get_attempt_context($attemptId);
    if ($attempt['status'] !== 'in_progress') {
        return ['attempt' => $attempt, 'progress' => null, 'questions' => [], 'review_targets' => [], 'selected_review_id' => null];
    }

    $endTime = strtotime((string) $attempt['ends_at']) ?: 0;
    if ($attempt['schedule_status'] !== 'open' || $endTime < time()) {
        exam_finalize_attempt($attemptId, true);
        return ['attempt' => exam_get_attempt_context($attemptId), 'progress' => null, 'questions' => [], 'review_targets' => [], 'selected_review_id' => null];
    }

    for ($guard = 0; $guard < 50; $guard++) {
        $progress = exam_active_progress($attemptId);
        if ($progress === null) {
            exam_finalize_attempt($attemptId);
            return ['attempt' => exam_get_attempt_context($attemptId), 'progress' => null, 'questions' => [], 'review_targets' => [], 'selected_review_id' => null];
        }

        if ($progress['progress_status'] === 'not_started') {
            $startedAt = date('Y-m-d H:i:s');
            $seconds = max(1, (int) $progress['duration_minutes']) * 60;
            $update = db()->prepare(
                "UPDATE attempt_module_progress
                 SET status = 'in_progress', started_at = :started_at, time_remaining_seconds = :seconds
                 WHERE id = :progress_id AND status = 'not_started'"
            );
            $update->execute([
                'started_at' => $startedAt,
                'seconds' => $seconds,
                'progress_id' => (int) $progress['progress_id'],
            ]);
            $progress = exam_active_progress($attemptId, (int) $progress['progress_id']) ?? $progress;
            $progress['progress_status'] = 'in_progress';
            $progress['started_at'] = $progress['started_at'] ?: $startedAt;
        }

        $remaining = exam_remaining_seconds($progress);
        if ($remaining < 1) {
            $expire = db()->prepare(
                "UPDATE attempt_module_progress
                 SET status = 'completed', completed_at = :completed_at, time_remaining_seconds = 0
                 WHERE id = :progress_id AND status = 'in_progress'"
            );
            $expire->execute([
                'completed_at' => date('Y-m-d H:i:s'),
                'progress_id' => (int) $progress['progress_id'],
            ]);
            db()->prepare('UPDATE exam_attempts SET auto_submitted = 1 WHERE id = :attempt_id')
                ->execute(['attempt_id' => $attemptId]);
            continue;
        }

        $progress['remaining_seconds'] = $remaining;
        $targets = [];
        $selectedReviewId = null;
        if ($progress['module_kind'] === 'review_360') {
            if ($attempt['participant_type'] !== 'employee') {
                throw new DomainException('Modul evaluasi 360° hanya dapat dikerjakan oleh karyawan.');
            }
            $targets = exam_review_targets((int) $attempt['schedule_id'], (int) $attempt['sso_user_id'], $attemptId);
            if ($targets === []) {
                throw new DomainException('Penugasan evaluasi 360° tidak tersedia. Hubungi HR sebelum melanjutkan.');
            }
            if ($targets !== []) {
                $validIds = array_map(static fn (array $target): int => (int) $target['id'], $targets);
                $selectedReviewId = $reviewAssignmentId !== null && in_array($reviewAssignmentId, $validIds, true)
                    ? $reviewAssignmentId
                    : $validIds[0];
                $markTarget = db()->prepare(
                    "UPDATE review_assignments SET status = 'in_progress'
                     WHERE id = :id AND status = 'assigned'"
                );
                $markTarget->execute(['id' => $selectedReviewId]);
            }
        }

        return [
            'attempt' => $attempt,
            'progress' => $progress,
            'questions' => exam_load_questions($attemptId, $progress, $selectedReviewId),
            'review_targets' => $targets,
            'selected_review_id' => $selectedReviewId,
        ];
    }

    exam_finalize_attempt($attemptId);
    return ['attempt' => exam_get_attempt_context($attemptId), 'progress' => null, 'questions' => [], 'review_targets' => [], 'selected_review_id' => null];
}

function exam_review_targets(int $scheduleId, int $reviewerId, int $attemptId): array
{
    $statement = db()->prepare(
        "SELECT id, subject_user_id, status
         FROM review_assignments
         WHERE schedule_id = :schedule_id AND reviewer_user_id = :reviewer_id AND status <> 'cancelled'
         ORDER BY subject_user_id"
    );
    $statement->execute(['schedule_id' => $scheduleId, 'reviewer_id' => $reviewerId]);
    $targets = $statement->fetchAll();
    if ($targets === []) {
        return [];
    }

    $subjectIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['subject_user_id'], $targets)));
    $names = [];
    try {
        $placeholders = implode(', ', array_fill(0, count($subjectIds), '?'));
        $namesStatement = sso_db()->prepare("SELECT id, name, division FROM users WHERE id IN ($placeholders)");
        $namesStatement->execute($subjectIds);
        foreach ($namesStatement->fetchAll() as $user) {
            $names[(int) $user['id']] = ['name' => $user['name'], 'division' => $user['division']];
        }
    } catch (Throwable $exception) {
        error_log('E-Assessment 360° directory lookup failed: ' . $exception->getMessage());
    }

    $answerStatement = db()->prepare(
        'SELECT COUNT(DISTINCT question_id) FROM attempt_answers WHERE attempt_id = :attempt_id AND review_assignment_id = :review_assignment_id'
    );
    foreach ($targets as &$target) {
        $subjectId = (int) $target['subject_user_id'];
        $target['subject_name'] = $names[$subjectId]['name'] ?? ('Karyawan #' . $subjectId);
        $target['subject_division'] = $names[$subjectId]['division'] ?? null;
        $answerStatement->execute([
            'attempt_id' => $attemptId,
            'review_assignment_id' => (int) $target['id'],
        ]);
        $target['answered_count'] = (int) $answerStatement->fetchColumn();
    }
    unset($target);

    return $targets;
}

function exam_load_questions(int $attemptId, array $progress, ?int $reviewAssignmentId): array
{
    $statement = db()->prepare(
        "SELECT amq.id AS attempt_question_id, amq.question_id, amq.sort_order,
                q.category, q.question_type, q.question_text, q.question_image_path,
                q.question_image_alt, q.instructions, q.reference_file_path,
                q.reference_file_name, q.reference_mime_type, q.allowed_extensions,
                q.max_file_size_mb, q.max_score, q.weight,
                COALESCE(mq.points_override, q.max_score * q.weight) AS points,
                COALESCE(mq.is_required, 1) AS is_required
         FROM attempt_module_questions amq
         INNER JOIN question_bank q ON q.id = amq.question_id
         INNER JOIN attempt_module_progress amp ON amp.id = amq.attempt_module_progress_id
         INNER JOIN package_modules pm ON pm.id = amp.package_module_id
         LEFT JOIN module_questions mq ON mq.module_id = pm.module_id AND mq.question_id = q.id
         WHERE amp.attempt_id = :attempt_id AND amp.id = :progress_id
         ORDER BY amq.sort_order"
    );
    $statement->execute([
        'attempt_id' => $attemptId,
        'progress_id' => (int) $progress['progress_id'],
    ]);
    $questions = $statement->fetchAll();
    if ($questions === []) {
        return [];
    }

    $optionStatement = db()->prepare(
        "SELECT aqo.id AS attempt_option_id, aqo.attempt_module_question_id,
                qo.id AS option_id, qo.option_key, qo.option_text, qo.option_image_path
         FROM attempt_question_options aqo
         INNER JOIN question_options qo ON qo.id = aqo.question_option_id
         WHERE aqo.attempt_module_question_id = :attempt_question_id
         ORDER BY aqo.sort_order"
    );
    $answerStatement = db()->prepare(
        "SELECT id, answer_text, answer_payload
         FROM attempt_answers
         WHERE attempt_id = :attempt_id AND question_id = :question_id AND review_assignment_id "
        . ($reviewAssignmentId === null ? 'IS NULL' : '= :review_assignment_id') . ' LIMIT 1'
    );
    $fileStatement = db()->prepare(
        'SELECT id, original_file_name, file_size_bytes FROM attempt_answer_files WHERE answer_id = :answer_id ORDER BY uploaded_at DESC'
    );

    foreach ($questions as &$question) {
        $optionStatement->execute(['attempt_question_id' => (int) $question['attempt_question_id']]);
        $question['options'] = $optionStatement->fetchAll();

        $answerParameters = [
            'attempt_id' => $attemptId,
            'question_id' => (int) $question['question_id'],
        ];
        if ($reviewAssignmentId !== null) {
            $answerParameters['review_assignment_id'] = $reviewAssignmentId;
        }
        $answerStatement->execute($answerParameters);
        $answer = $answerStatement->fetch();
        if ($answer) {
            $payload = $answer['answer_payload'] !== null ? json_decode((string) $answer['answer_payload'], true) : null;
            $question['answer'] = [
                'id' => (int) $answer['id'],
                'text' => (string) ($answer['answer_text'] ?? ''),
                'payload' => is_array($payload) ? $payload : [],
            ];
            $fileStatement->execute(['answer_id' => (int) $answer['id']]);
            $question['files'] = $fileStatement->fetchAll();
        } else {
            $question['answer'] = ['id' => null, 'text' => '', 'payload' => []];
            $question['files'] = [];
        }
    }
    unset($question);

    return $questions;
}

function exam_validate_attempt_progress(int $attemptId, int $progressId): array
{
    $attempt = exam_get_attempt_context($attemptId);
    if ($attempt['status'] !== 'in_progress') {
        throw new DomainException('Sesi ujian sudah dikirim atau tidak aktif.');
    }
    $scheduleEnd = strtotime((string) $attempt['ends_at']) ?: 0;
    if ($attempt['schedule_status'] !== 'open' || $scheduleEnd < time()) {
        exam_finalize_attempt($attemptId, true);
        throw new DomainException('Jadwal ujian sudah ditutup. Jawaban yang tersimpan telah dikirim.');
    }
    $progress = exam_active_progress($attemptId, $progressId);
    if (!$progress || $progress['progress_status'] !== 'in_progress') {
        throw new DomainException('Modul ini tidak sedang aktif. Muat ulang ruang ujian.');
    }
    if (exam_remaining_seconds($progress) < 1) {
        $expire = db()->prepare(
            "UPDATE attempt_module_progress SET status = 'completed', completed_at = :completed_at, time_remaining_seconds = 0
             WHERE id = :progress_id AND status = 'in_progress'"
        );
        $expire->execute(['completed_at' => date('Y-m-d H:i:s'), 'progress_id' => $progressId]);
        db()->prepare('UPDATE exam_attempts SET auto_submitted = 1 WHERE id = :attempt_id')
            ->execute(['attempt_id' => $attemptId]);
        throw new DomainException('Waktu modul telah habis. Peralihan ke modul berikutnya sedang diproses.');
    }

    return [$attempt, $progress];
}

function exam_validate_review_assignment(array $attempt, array $progress, ?int $reviewAssignmentId): ?int
{
    if ($progress['module_kind'] !== 'review_360') {
        if ($reviewAssignmentId !== null) {
            throw new DomainException('Konteks penilaian tidak sesuai dengan modul ini.');
        }
        return null;
    }
    if ($reviewAssignmentId === null || $attempt['participant_type'] !== 'employee') {
        throw new DomainException('Pilih karyawan yang akan dinilai pada evaluasi 360°.');
    }

    $statement = db()->prepare(
        "SELECT id FROM review_assignments
         WHERE id = :id AND schedule_id = :schedule_id AND reviewer_user_id = :reviewer_id AND status <> 'cancelled'
         LIMIT 1"
    );
    $statement->execute([
        'id' => $reviewAssignmentId,
        'schedule_id' => (int) $attempt['schedule_id'],
        'reviewer_id' => (int) $attempt['sso_user_id'],
    ]);
    if ($statement->fetchColumn() === false) {
        throw new DomainException('Penugasan evaluasi 360° tidak valid untuk akun Anda.');
    }

    return $reviewAssignmentId;
}

function exam_question_for_progress(int $progressId, int $questionId): ?array
{
    $statement = db()->prepare(
        "SELECT amq.id AS attempt_question_id, q.id, q.question_type, q.category,
                q.max_score, q.weight, q.papi_item_code,
                COALESCE(mq.points_override, q.max_score * q.weight) AS points
         FROM attempt_module_questions amq
         INNER JOIN question_bank q ON q.id = amq.question_id
         INNER JOIN attempt_module_progress amp ON amp.id = amq.attempt_module_progress_id
         INNER JOIN package_modules pm ON pm.id = amp.package_module_id
         LEFT JOIN module_questions mq ON mq.module_id = pm.module_id AND mq.question_id = q.id
         WHERE amp.id = :progress_id AND q.id = :question_id
         LIMIT 1"
    );
    $statement->execute(['progress_id' => $progressId, 'question_id' => $questionId]);
    $question = $statement->fetch();

    return $question === false ? null : $question;
}

function exam_valid_option_ids(int $attemptQuestionId, array $optionIds): array
{
    $optionIds = array_values(array_unique(array_filter(array_map('intval', $optionIds), static fn (int $id): bool => $id > 0)));
    if ($optionIds === []) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($optionIds), '?'));
    $statement = db()->prepare(
        "SELECT question_option_id FROM attempt_question_options
         WHERE attempt_module_question_id = ? AND question_option_id IN ($placeholders)"
    );
    $statement->execute(array_merge([$attemptQuestionId], $optionIds));
    $valid = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    if (count($valid) !== count($optionIds)) {
        throw new DomainException('Pilihan jawaban tidak valid. Muat ulang soal dan coba kembali.');
    }

    return $valid;
}

function exam_is_same_id_set(array $selected, array $correct): bool
{
    sort($selected);
    sort($correct);
    return $selected === $correct;
}

/** Basic allow-list rich-text sanitizer; no attributes, links, images or embedded content are retained. */
function exam_sanitize_rich_html(string $html): string
{
    $html = mb_substr($html, 0, 60000, 'UTF-8');
    $safe = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li>');
    $safe = preg_replace_callback(
        '/<\/?(p|br|strong|b|em|i|u|ul|ol|li)\b[^>]*>/i',
        static function (array $match): string {
            $closing = str_starts_with($match[0], '</');
            $tag = strtolower($match[1]);
            if ($tag === 'br') {
                return '<br>';
            }
            return $closing ? '</' . $tag . '>' : '<' . $tag . '>';
        },
        $safe
    ) ?? '';

    return $safe;
}

function exam_upsert_answer(
    PDO $pdo,
    int $attemptId,
    int $moduleId,
    int $questionId,
    ?int $reviewAssignmentId,
    ?string $answerText,
    ?array $payload,
    ?float $autoScore,
    string $reviewStatus
): int {
    $reviewCondition = $reviewAssignmentId === null ? 'review_assignment_id IS NULL' : 'review_assignment_id = :review_id';
    $findSql = "SELECT id FROM attempt_answers
                WHERE attempt_id = :attempt_id AND question_id = :question_id AND $reviewCondition LIMIT 1";
    $find = $pdo->prepare($findSql);
    $parameters = ['attempt_id' => $attemptId, 'question_id' => $questionId];
    if ($reviewAssignmentId !== null) {
        $parameters['review_id'] = $reviewAssignmentId;
    }
    $find->execute($parameters);
    $answerId = $find->fetchColumn();
    $payloadJson = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    if ($answerId !== false) {
        $update = $pdo->prepare(
            "UPDATE attempt_answers
             SET module_id = :module_id, answer_text = :answer_text, answer_payload = :answer_payload,
                 auto_score = :auto_score, review_status = :review_status, saved_at = :saved_at
             WHERE id = :answer_id"
        );
        $update->execute([
            'module_id' => $moduleId,
            'answer_text' => $answerText,
            'answer_payload' => $payloadJson,
            'auto_score' => $autoScore,
            'review_status' => $reviewStatus,
            'saved_at' => date('Y-m-d H:i:s'),
            'answer_id' => (int) $answerId,
        ]);
        return (int) $answerId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO attempt_answers (attempt_id, module_id, question_id, review_assignment_id, answer_text, answer_payload, auto_score, review_status, saved_at)
         VALUES (:attempt_id, :module_id, :question_id, :review_id, :answer_text, :answer_payload, :auto_score, :review_status, :saved_at)'
    );
    $insert->execute([
        'attempt_id' => $attemptId,
        'module_id' => $moduleId,
        'question_id' => $questionId,
        'review_id' => $reviewAssignmentId,
        'answer_text' => $answerText,
        'answer_payload' => $payloadJson,
        'auto_score' => $autoScore,
        'review_status' => $reviewStatus,
        'saved_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) $pdo->lastInsertId();
}

function exam_save_answer(int $attemptId, int $progressId, int $questionId, array $input, ?int $reviewAssignmentId = null): array
{
    [$attempt, $progress] = exam_validate_attempt_progress($attemptId, $progressId);
    $reviewAssignmentId = exam_validate_review_assignment($attempt, $progress, $reviewAssignmentId);
    $question = exam_question_for_progress($progressId, $questionId);
    if (!$question) {
        throw new DomainException('Soal tidak termasuk dalam modul yang sedang dikerjakan.');
    }

    $type = (string) $question['question_type'];
    $payload = [];
    $answerText = null;
    $autoScore = null;
    $reviewStatus = ($progress['module_kind'] === 'review_360' || in_array($type, ['essay', 'file_upload', 'forced_choice'], true))
        ? 'pending'
        : 'not_required';

    if ($type === 'single_choice') {
        $selectedId = isset($input['selected_option_id']) && $input['selected_option_id'] !== ''
            ? (int) $input['selected_option_id']
            : 0;
        $selected = exam_valid_option_ids((int) $question['attempt_question_id'], $selectedId > 0 ? [$selectedId] : []);
        $payload = ['selected_option_id' => $selected[0] ?? null];
        if ($progress['module_kind'] !== 'review_360') {
            $correctStatement = db()->prepare('SELECT id FROM question_options WHERE question_id = :question_id AND is_correct = 1');
            $correctStatement->execute(['question_id' => $questionId]);
            $correctIds = array_map('intval', $correctStatement->fetchAll(PDO::FETCH_COLUMN));
            $autoScore = $selected !== [] && exam_is_same_id_set($selected, $correctIds) ? (float) $question['points'] : 0.0;
        }
    } elseif ($type === 'multi_select') {
        $rawIds = is_array($input['selected_option_ids'] ?? null) ? $input['selected_option_ids'] : [];
        $selected = exam_valid_option_ids((int) $question['attempt_question_id'], $rawIds);
        $payload = ['selected_option_ids' => $selected];
        if ($progress['module_kind'] !== 'review_360') {
            $correctStatement = db()->prepare('SELECT id FROM question_options WHERE question_id = :question_id AND is_correct = 1');
            $correctStatement->execute(['question_id' => $questionId]);
            $correctIds = array_map('intval', $correctStatement->fetchAll(PDO::FETCH_COLUMN));
            $autoScore = $selected !== [] && exam_is_same_id_set($selected, $correctIds) ? (float) $question['points'] : 0.0;
        }
    } elseif ($type === 'forced_choice') {
        $mostId = isset($input['most_option_id']) && $input['most_option_id'] !== '' ? (int) $input['most_option_id'] : 0;
        $leastId = isset($input['least_option_id']) && $input['least_option_id'] !== '' ? (int) $input['least_option_id'] : 0;
        if ($mostId > 0 && $mostId === $leastId) {
            throw new DomainException('Pilihan Most dan Least harus berbeda.');
        }
        $selected = exam_valid_option_ids((int) $question['attempt_question_id'], array_filter([$mostId, $leastId]));
        $payload = [
            'most_option_id' => $mostId > 0 ? $mostId : null,
            'least_option_id' => $leastId > 0 ? $leastId : null,
        ];
    } elseif ($type === 'essay') {
        $answerText = exam_sanitize_rich_html((string) ($input['html'] ?? ''));
        $payload = ['format' => 'restricted_html'];
    } elseif ($type === 'file_upload') {
        throw new DomainException('Gunakan kontrol unggah file untuk menyimpan jawaban praktik.');
    } else {
        throw new DomainException('Jenis soal belum didukung.');
    }

    $pdo = db();
    $answerId = exam_upsert_answer(
        $pdo,
        $attemptId,
        (int) $progress['module_id'],
        $questionId,
        $reviewAssignmentId,
        $answerText,
        $payload,
        $autoScore,
        $reviewStatus
    );

    return ['answer_id' => $answerId, 'saved_at' => date('H:i:s')];
}

function exam_allowed_mime_types(string $extension): array
{
    $map = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/octet-stream'],
        'pptx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    ];

    return $map[$extension] ?? [];
}

function exam_save_uploaded_file(int $attemptId, int $progressId, int $questionId, array $file, ?int $reviewAssignmentId = null): array
{
    [$attempt, $progress] = exam_validate_attempt_progress($attemptId, $progressId);
    $reviewAssignmentId = exam_validate_review_assignment($attempt, $progress, $reviewAssignmentId);
    $question = exam_question_for_progress($progressId, $questionId);
    if (!$question || $question['question_type'] !== 'file_upload') {
        throw new DomainException('Soal ini tidak menerima unggahan file.');
    }
    if (!isset($file['error']) || is_array($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new DomainException('File belum berhasil diunggah. Silakan pilih file dan coba lagi.');
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new DomainException('Unggahan tidak valid. Silakan coba kembali.');
    }

    $originalName = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file')));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? 'file';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = array_filter(array_map(
        static fn (string $value): string => strtolower(ltrim(trim($value), '.')),
        explode(',', (string) ($question['allowed_extensions'] ?? ''))
    ));
    if ($allowed === []) {
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'csv', 'txt', 'zip'];
    }
    if ($extension === '' || !in_array($extension, $allowed, true)) {
        throw new DomainException('Format file tidak diizinkan untuk soal ini.');
    }

    $size = (int) ($file['size'] ?? 0);
    $maximumBytes = (int) app_config()['storage']['max_upload_bytes'];
    if (!empty($question['max_file_size_mb'])) {
        $maximumBytes = min($maximumBytes, (int) $question['max_file_size_mb'] * 1024 * 1024);
    }
    if ($size < 1 || $size > $maximumBytes) {
        throw new DomainException('Ukuran file kosong atau melebihi batas yang diizinkan.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file((string) $file['tmp_name']);
    if (!in_array($mime, exam_allowed_mime_types($extension), true)) {
        throw new DomainException('Isi file tidak cocok dengan ekstensi yang dipilih.');
    }

    $storageRoot = app_config()['storage']['root'];
    $relativeDirectory = 'uploads/answers/' . $attemptId;
    $absoluteDirectory = $storageRoot . '/' . $relativeDirectory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('Tidak dapat menyiapkan penyimpanan unggahan.');
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $relativePath = $relativeDirectory . '/' . $storedName;
    $absolutePath = $storageRoot . '/' . $relativePath;
    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Gagal memindahkan file ke penyimpanan privat.');
    }
    @chmod($absolutePath, 0640);

    try {
        $pdo = db();
        $answerId = exam_upsert_answer(
            $pdo,
            $attemptId,
            (int) $progress['module_id'],
            $questionId,
            $reviewAssignmentId,
            null,
            ['file_uploaded' => true],
            null,
            'pending'
        );
        $insert = $pdo->prepare(
            'INSERT INTO attempt_answer_files (answer_id, original_file_name, stored_file_path, mime_type, file_size_bytes)
             VALUES (:answer_id, :original_name, :stored_path, :mime_type, :size_bytes)'
        );
        $insert->execute([
            'answer_id' => $answerId,
            'original_name' => mb_substr($originalName, 0, 255, 'UTF-8'),
            'stored_path' => $relativePath,
            'mime_type' => $mime,
            'size_bytes' => $size,
        ]);
        $fileId = (int) $pdo->lastInsertId();
    } catch (Throwable $exception) {
        @unlink($absolutePath);
        throw $exception;
    }

    return ['answer_id' => $answerId, 'file_id' => $fileId, 'file_name' => $originalName, 'file_size' => $size];
}

function exam_answer_is_complete(int $attemptId, int $questionId, string $type, ?int $reviewAssignmentId): bool
{
    $condition = $reviewAssignmentId === null ? 'review_assignment_id IS NULL' : 'review_assignment_id = :review_id';
    $statement = db()->prepare(
        "SELECT id, answer_text, answer_payload FROM attempt_answers
         WHERE attempt_id = :attempt_id AND question_id = :question_id AND $condition LIMIT 1"
    );
    $parameters = ['attempt_id' => $attemptId, 'question_id' => $questionId];
    if ($reviewAssignmentId !== null) {
        $parameters['review_id'] = $reviewAssignmentId;
    }
    $statement->execute($parameters);
    $answer = $statement->fetch();
    if (!$answer) {
        return false;
    }

    $payload = $answer['answer_payload'] !== null ? json_decode((string) $answer['answer_payload'], true) : [];
    $payload = is_array($payload) ? $payload : [];
    if ($type === 'single_choice') {
        return !empty($payload['selected_option_id']);
    }
    if ($type === 'multi_select') {
        return !empty($payload['selected_option_ids']);
    }
    if ($type === 'forced_choice') {
        return !empty($payload['most_option_id']) && !empty($payload['least_option_id']);
    }
    if ($type === 'essay') {
        return trim(strip_tags((string) $answer['answer_text'])) !== '';
    }
    if ($type === 'file_upload') {
        $files = db()->prepare('SELECT COUNT(*) FROM attempt_answer_files WHERE answer_id = :answer_id');
        $files->execute(['answer_id' => (int) $answer['id']]);
        return (int) $files->fetchColumn() > 0;
    }

    return false;
}

function exam_expire_module(int $attemptId, int $progressId): array
{
    $attempt = exam_get_attempt_context($attemptId);
    if ($attempt['status'] !== 'in_progress') {
        return ['state' => $attempt['status'], 'redirect' => 'exam.php?id=' . $attemptId];
    }
    $progress = exam_active_progress($attemptId, $progressId);
    if (!$progress || $progress['progress_status'] !== 'in_progress') {
        throw new DomainException('Modul ujian tidak aktif. Muat ulang halaman.');
    }
    if (exam_remaining_seconds($progress) > 0) {
        throw new DomainException('Waktu modul belum habis.');
    }

    $update = db()->prepare(
        "UPDATE attempt_module_progress
         SET status = 'completed', completed_at = :completed_at, time_remaining_seconds = 0
         WHERE id = :progress_id AND attempt_id = :attempt_id AND status = 'in_progress'"
    );
    $update->execute([
        'completed_at' => date('Y-m-d H:i:s'),
        'progress_id' => $progressId,
        'attempt_id' => $attemptId,
    ]);
    db()->prepare('UPDATE exam_attempts SET auto_submitted = 1 WHERE id = :attempt_id')
        ->execute(['attempt_id' => $attemptId]);

    $next = exam_prepare_attempt($attemptId);
    return ['state' => $next['attempt']['status'], 'redirect' => 'exam.php?id=' . $attemptId];
}

function exam_submit_module(int $attemptId, int $progressId): array
{
    [$attempt, $progress] = exam_validate_attempt_progress($attemptId, $progressId);
    $requiredStatement = db()->prepare(
        "SELECT q.id AS question_id, q.question_type
         FROM attempt_module_questions amq
         INNER JOIN question_bank q ON q.id = amq.question_id
         INNER JOIN attempt_module_progress amp ON amp.id = amq.attempt_module_progress_id
         INNER JOIN package_modules pm ON pm.id = amp.package_module_id
         LEFT JOIN module_questions mq ON mq.module_id = pm.module_id AND mq.question_id = q.id
         WHERE amp.id = :progress_id AND COALESCE(mq.is_required, 1) = 1
         ORDER BY amq.sort_order"
    );
    $requiredStatement->execute(['progress_id' => $progressId]);
    $requiredQuestions = $requiredStatement->fetchAll();
    $targetIds = [null];
    if ($progress['module_kind'] === 'review_360') {
        $targets = exam_review_targets((int) $attempt['schedule_id'], (int) $attempt['sso_user_id'], $attemptId);
        $targetIds = array_map(static fn (array $target): int => (int) $target['id'], $targets);
        if ($targetIds === []) {
            throw new DomainException('Belum ada penugasan karyawan untuk evaluasi 360°.');
        }
    }

    $missing = 0;
    foreach ($targetIds as $targetId) {
        foreach ($requiredQuestions as $question) {
            if (!exam_answer_is_complete(
                $attemptId,
                (int) $question['question_id'],
                (string) $question['question_type'],
                $targetId
            )) {
                $missing++;
            }
        }
    }
    if ($missing > 0) {
        throw new DomainException('Masih ada ' . $missing . ' jawaban wajib yang belum lengkap.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            "UPDATE attempt_module_progress
             SET status = 'completed', completed_at = :completed_at, time_remaining_seconds = :seconds
             WHERE id = :progress_id AND attempt_id = :attempt_id AND status = 'in_progress'"
        );
        $update->execute([
            'completed_at' => date('Y-m-d H:i:s'),
            'seconds' => exam_remaining_seconds($progress),
            'progress_id' => $progressId,
            'attempt_id' => $attemptId,
        ]);
        if ($progress['module_kind'] === 'review_360') {
            $assignmentUpdate = $pdo->prepare(
                "UPDATE review_assignments SET status = 'submitted', completed_at = :completed_at
                 WHERE schedule_id = :schedule_id AND reviewer_user_id = :reviewer_id AND status IN ('assigned', 'in_progress')"
            );
            $assignmentUpdate->execute([
                'completed_at' => date('Y-m-d H:i:s'),
                'schedule_id' => (int) $attempt['schedule_id'],
                'reviewer_id' => (int) $attempt['sso_user_id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $next = exam_prepare_attempt($attemptId);
    return [
        'state' => $next['attempt']['status'],
        'redirect' => 'exam.php?id=' . $attemptId,
    ];
}

function exam_finalize_attempt(int $attemptId, bool $autoSubmitted = false): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id, status, schedule_participant_id FROM exam_attempts WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute(['id' => $attemptId]);
        $attempt = $lock->fetch();
        if (!$attempt || $attempt['status'] !== 'in_progress') {
            $pdo->commit();
            return;
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            "UPDATE attempt_module_progress
             SET status = 'completed', completed_at = COALESCE(completed_at, :completed_at), time_remaining_seconds = 0
             WHERE attempt_id = :attempt_id AND status = 'in_progress'"
        )->execute(['completed_at' => $now, 'attempt_id' => $attemptId]);

        $queue = $pdo->prepare(
            "INSERT INTO assessment_reviews (attempt_id, answer_id, review_type, status)
             SELECT aa.attempt_id, aa.id,
                    CASE
                        WHEN q.question_type = 'essay' THEN 'essay'
                        WHEN q.question_type = 'file_upload' THEN 'practical'
                        ELSE 'psychometric'
                    END,
                    'pending'
             FROM attempt_answers aa
             INNER JOIN question_bank q ON q.id = aa.question_id
             INNER JOIN assessment_modules m ON m.id = aa.module_id
             WHERE aa.attempt_id = :attempt_id
               AND aa.review_status = 'pending'
               AND m.module_kind <> 'review_360'
               AND (
                    (q.question_type = 'essay' AND TRIM(COALESCE(aa.answer_text, '')) <> '')
                    OR (q.question_type = 'file_upload' AND EXISTS (
                        SELECT 1 FROM attempt_answer_files f WHERE f.answer_id = aa.id
                    ))
                    OR (q.question_type = 'forced_choice'
                        AND q.papi_item_code IS NULL AND COALESCE(q.category, '') <> 'PAPI_KOSTICK')
               )
               AND NOT EXISTS (
                    SELECT 1 FROM assessment_reviews existing
                    WHERE existing.attempt_id = aa.attempt_id AND existing.answer_id = aa.id
               )"
        );
        $queue->execute(['attempt_id' => $attemptId]);

        // PAPI is reviewed once per attempt; the report contains all selected statements and raw aspect scores.
        $papiReview = $pdo->prepare(
            "INSERT INTO assessment_reviews (attempt_id, answer_id, review_type, status)
             SELECT :attempt_id_insert, NULL, 'papi', 'pending'
             WHERE EXISTS (
                 SELECT 1 FROM attempt_answers aa
                 INNER JOIN question_bank q ON q.id = aa.question_id
                 WHERE aa.attempt_id = :attempt_id_answers
                   AND q.question_type = 'forced_choice'
                   AND (q.papi_item_code IS NOT NULL OR q.category = 'PAPI_KOSTICK')
             )
               AND NOT EXISTS (
                 SELECT 1 FROM assessment_reviews existing
                 WHERE existing.attempt_id = :attempt_id_existing AND existing.review_type = 'papi'
             )"
        );
        $papiReview->execute([
            'attempt_id_insert' => $attemptId,
            'attempt_id_answers' => $attemptId,
            'attempt_id_existing' => $attemptId,
        ]);

        // A 360 review is one HR review per assigned subject, not one review per question.
        $review360Queue = $pdo->prepare(
            "INSERT INTO assessment_reviews (attempt_id, answer_id, review_type, status)
             SELECT :attempt_id_insert, grouped_answers.first_answer_id, 'review_360', 'pending'
             FROM (
                 SELECT aa.review_assignment_id, MIN(aa.id) AS first_answer_id
                 FROM attempt_answers aa
                 INNER JOIN assessment_modules m ON m.id = aa.module_id
                 WHERE aa.attempt_id = :attempt_id_answers
                   AND m.module_kind = 'review_360'
                   AND aa.review_assignment_id IS NOT NULL
                 GROUP BY aa.review_assignment_id
             ) AS grouped_answers
             WHERE NOT EXISTS (
                 SELECT 1 FROM assessment_reviews existing
                 LEFT JOIN attempt_answers existing_answer ON existing_answer.id = existing.answer_id
                 WHERE existing.attempt_id = :attempt_id_existing
                   AND existing.review_type = 'review_360'
                   AND existing_answer.review_assignment_id = grouped_answers.review_assignment_id
             )"
        );
        $review360Queue->execute([
            'attempt_id_insert' => $attemptId,
            'attempt_id_answers' => $attemptId,
            'attempt_id_existing' => $attemptId,
        ]);

        // PAPI raw aspect totals: Most contributes +1 and Least contributes -1.
        $papiAnswers = $pdo->prepare(
            "SELECT aa.answer_payload
             FROM attempt_answers aa
             INNER JOIN question_bank q ON q.id = aa.question_id
             WHERE aa.attempt_id = :attempt_id AND q.question_type = 'forced_choice'
               AND (q.papi_item_code IS NOT NULL OR q.category = 'PAPI_KOSTICK')"
        );
        $papiAnswers->execute(['attempt_id' => $attemptId]);
        $optionTotals = [];
        $optionAspectStatement = $pdo->prepare('SELECT id, papi_aspect_code FROM question_options WHERE id IN (?, ?)');
        foreach ($papiAnswers->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $payload = json_decode((string) $json, true);
            if (!is_array($payload)) {
                continue;
            }
            $most = (int) ($payload['most_option_id'] ?? 0);
            $least = (int) ($payload['least_option_id'] ?? 0);
            $ids = array_values(array_filter([$most, $least]));
            if ($ids === []) {
                continue;
            }
            $queryIds = count($ids) === 1 ? [$ids[0], $ids[0]] : $ids;
            $optionAspectStatement->execute($queryIds);
            foreach ($optionAspectStatement->fetchAll() as $option) {
                $aspect = trim((string) ($option['papi_aspect_code'] ?? ''));
                if ($aspect === '') {
                    continue;
                }
                $delta = (int) $option['id'] === $most ? 1 : -1;
                $optionTotals[$aspect] = ($optionTotals[$aspect] ?? 0) + $delta;
            }
        }
        $papiUpsert = $pdo->prepare(
            'INSERT INTO attempt_papi_scores (attempt_id, aspect_code, raw_score) VALUES (:attempt_id, :aspect_code, :raw_score)
             ON DUPLICATE KEY UPDATE raw_score = VALUES(raw_score)'
        );
        foreach ($optionTotals as $aspect => $rawScore) {
            $papiUpsert->execute([
                'attempt_id' => $attemptId,
                'aspect_code' => $aspect,
                'raw_score' => $rawScore,
            ]);
        }

        $scoreStatement = $pdo->prepare('SELECT COALESCE(SUM(auto_score), 0) FROM attempt_answers WHERE attempt_id = :attempt_id');
        $scoreStatement->execute(['attempt_id' => $attemptId]);
        $objectiveScore = (float) $scoreStatement->fetchColumn();
        $reviewCount = $pdo->prepare("SELECT COUNT(*) FROM assessment_reviews WHERE attempt_id = :attempt_id AND status IN ('pending', 'in_progress')");
        $reviewCount->execute(['attempt_id' => $attemptId]);
        $hasPendingReview = (int) $reviewCount->fetchColumn() > 0;
        $newStatus = $hasPendingReview ? 'pending_review' : 'completed';

        $updateAttempt = $pdo->prepare(
            'UPDATE exam_attempts
             SET status = :status, submitted_at = :submitted_at,
                 auto_submitted = GREATEST(auto_submitted, :auto_submitted),
                 objective_score = :objective_score, total_score = :total_score
             WHERE id = :attempt_id'
        );
        $updateAttempt->execute([
            'status' => $newStatus,
            'submitted_at' => $now,
            'auto_submitted' => $autoSubmitted ? 1 : 0,
            'objective_score' => $objectiveScore,
            'total_score' => $hasPendingReview ? null : $objectiveScore,
            'attempt_id' => $attemptId,
        ]);
        $participantStatus = $hasPendingReview ? 'pending_review' : 'completed';
        $participantUpdate = $pdo->prepare('UPDATE schedule_participants SET status = :status WHERE id = :participant_id');
        $participantUpdate->execute(['status' => $participantStatus, 'participant_id' => (int) $attempt['schedule_participant_id']]);

        $identityStatement = $pdo->prepare(
            "SELECT sp.schedule_id, sp.sso_user_id, sp.participant_type
             FROM schedule_participants sp WHERE sp.id = :participant_id"
        );
        $identityStatement->execute(['participant_id' => (int) $attempt['schedule_participant_id']]);
        $participant = $identityStatement->fetch();
        if ($participant && $participant['participant_type'] === 'employee') {
            $pdo->prepare(
                "UPDATE review_assignments SET status = 'pending_review'
                 WHERE schedule_id = :schedule_id AND reviewer_user_id = :reviewer_id
                   AND status IN ('assigned', 'in_progress', 'submitted')
                   AND EXISTS (
                       SELECT 1 FROM attempt_answers aa
                       INNER JOIN assessment_modules m ON m.id = aa.module_id
                       WHERE aa.attempt_id = :attempt_id AND aa.review_assignment_id IS NOT NULL
                         AND m.module_kind = 'review_360'
                   )"
            )->execute([
                'schedule_id' => (int) $participant['schedule_id'],
                'reviewer_id' => (int) $participant['sso_user_id'],
                'attempt_id' => $attemptId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Return a participant's PAPI raw profile only after the attempt and its PAPI review are complete. */
function exam_participant_papi_scores(int $attemptId): array
{
    $attempt = exam_get_attempt_context($attemptId);
    if (app_is_management() && !app_is_hr()) {
        return [];
    }
    if (($attempt['status'] ?? '') !== 'completed') {
        return [];
    }

    $reviewStatus = db()->prepare(
        "SELECT COALESCE(SUM(status = 'completed'), 0) AS completed_count,
                COALESCE(SUM(status IN ('pending', 'in_progress')), 0) AS open_count
         FROM assessment_reviews
         WHERE attempt_id = :attempt_id AND review_type = 'papi'"
    );
    $reviewStatus->execute(['attempt_id' => $attemptId]);
    $status = $reviewStatus->fetch() ?: [];
    if ((int) ($status['completed_count'] ?? 0) < 1 || (int) ($status['open_count'] ?? 0) > 0) {
        return [];
    }

    $scores = db()->prepare(
        'SELECT aspect_code, raw_score FROM attempt_papi_scores
         WHERE attempt_id = :attempt_id ORDER BY aspect_code'
    );
    $scores->execute(['attempt_id' => $attemptId]);

    return $scores->fetchAll();
}

/** Resolve an authorized, private file referenced by this attempt. */
function exam_private_asset(int $attemptId, string $assetType, int $recordId): ?array
{
    $attempt = exam_get_attempt_context($attemptId);
    $statement = match ($assetType) {
        'question_image' => db()->prepare(
            "SELECT q.question_image_path AS stored_path, 'image' AS asset_kind, NULL AS original_file_name
             FROM attempt_module_questions amq
             INNER JOIN question_bank q ON q.id = amq.question_id
             INNER JOIN attempt_module_progress amp ON amp.id = amq.attempt_module_progress_id
             WHERE amp.attempt_id = :attempt_id AND q.id = :record_id LIMIT 1"
        ),
        'option_image' => db()->prepare(
            "SELECT qo.option_image_path AS stored_path, 'image' AS asset_kind, NULL AS original_file_name
             FROM attempt_question_options aqo
             INNER JOIN question_options qo ON qo.id = aqo.question_option_id
             INNER JOIN attempt_module_questions amq ON amq.id = aqo.attempt_module_question_id
             INNER JOIN attempt_module_progress amp ON amp.id = amq.attempt_module_progress_id
             WHERE amp.attempt_id = :attempt_id AND qo.id = :record_id LIMIT 1"
        ),
        'reference' => db()->prepare(
            "SELECT q.reference_file_path AS stored_path, q.reference_mime_type AS mime_type,
                    q.reference_file_name AS original_file_name
             FROM attempt_module_questions amq
             INNER JOIN question_bank q ON q.id = amq.question_id
             INNER JOIN attempt_module_progress amp ON amp.id = amq.attempt_module_progress_id
             WHERE amp.attempt_id = :attempt_id AND q.id = :record_id LIMIT 1"
        ),
        'answer_file' => db()->prepare(
            "SELECT f.stored_file_path AS stored_path, f.mime_type,
                    f.original_file_name, 'download' AS asset_kind
             FROM attempt_answer_files f
             INNER JOIN attempt_answers aa ON aa.id = f.answer_id
             WHERE aa.attempt_id = :attempt_id AND f.id = :record_id LIMIT 1"
        ),
        default => null,
    };
    if (!($statement instanceof PDOStatement)) {
        return null;
    }
    $statement->execute(['attempt_id' => $attemptId, 'record_id' => $recordId]);
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

    $mime = (string) ($asset['mime_type'] ?? '');
    if ($mime === '') {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);
    }
    $asset['path'] = $path;
    $asset['mime_type'] = $mime !== '' ? $mime : 'application/octet-stream';
    $asset['original_file_name'] = $asset['original_file_name'] ?? basename($path);

    return $asset;
}
