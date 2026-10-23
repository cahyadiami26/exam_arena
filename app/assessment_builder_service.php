<?php
declare(strict_types=1);

require_once __DIR__ . '/question_bank_service.php';

function assessment_builder_scalar(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

function assessment_builder_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function assessment_builder_checked(mixed $value): bool
{
    return $value === true || in_array(assessment_builder_scalar($value), ['1', 'on', 'true'], true);
}

function assessment_builder_module_list(): array
{
    return db()->query(
        "SELECT m.*,
                (SELECT COUNT(*) FROM module_questions mq WHERE mq.module_id = m.id) AS question_count,
                (SELECT COUNT(*) FROM module_questions mq INNER JOIN question_bank q ON q.id = mq.question_id
                 WHERE mq.module_id = m.id AND q.is_active = 1) AS active_question_count,
                (SELECT COUNT(*) FROM package_modules pm WHERE pm.module_id = m.id) AS package_count,
                (SELECT COUNT(DISTINCT amp.attempt_id)
                 FROM attempt_module_progress amp INNER JOIN package_modules pm ON pm.id = amp.package_module_id
                 WHERE pm.module_id = m.id) AS attempt_count
         FROM assessment_modules m
         ORDER BY FIELD(m.status, 'published', 'draft', 'archived'), m.updated_at DESC, m.id DESC"
    )->fetchAll();
}

function assessment_builder_question_catalog(?int $moduleId = null): array
{
    if ($moduleId !== null && $moduleId > 0) {
        $statement = db()->prepare(
            "SELECT q.id, q.code, q.category, q.question_type, LEFT(q.question_text, 800) AS question_text_preview,
                    q.max_score, q.weight, q.is_active,
                    mq.sort_order AS selected_sort_order, mq.points_override, mq.is_required
             FROM question_bank q
             LEFT JOIN module_questions mq ON mq.question_id = q.id AND mq.module_id = :module_id
             WHERE q.is_active = 1 OR mq.id IS NOT NULL
             ORDER BY (mq.id IS NOT NULL) DESC, COALESCE(mq.sort_order, 65535), q.category, q.id DESC
             LIMIT 600"
        );
        $statement->execute(['module_id' => $moduleId]);
        return $statement->fetchAll();
    }

    return db()->query(
        "SELECT q.id, q.code, q.category, q.question_type, LEFT(q.question_text, 800) AS question_text_preview,
                q.max_score, q.weight, q.is_active,
                NULL AS selected_sort_order, NULL AS points_override, 1 AS is_required
         FROM question_bank q
         WHERE q.is_active = 1
         ORDER BY q.category, q.id DESC
         LIMIT 600"
    )->fetchAll();
}

function assessment_builder_get_module(int $moduleId): ?array
{
    if ($moduleId < 1) {
        return null;
    }

    $statement = db()->prepare('SELECT * FROM assessment_modules WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $moduleId]);
    $module = $statement->fetch();
    if ($module === false) {
        return null;
    }

    $attempt = db()->prepare(
        'SELECT amp.id FROM attempt_module_progress amp
         INNER JOIN package_modules pm ON pm.id = amp.package_module_id
         WHERE pm.module_id = :module_id LIMIT 1'
    );
    $attempt->execute(['module_id' => $moduleId]);
    $module['has_attempts'] = $attempt->fetchColumn() !== false;
    $module['questions'] = assessment_builder_question_catalog($moduleId);

    return $module;
}

function assessment_builder_package_list(): array
{
    return db()->query(
        "SELECT p.*,
                (SELECT COUNT(*) FROM package_modules pm WHERE pm.package_id = p.id) AS module_count,
                (SELECT COUNT(DISTINCT a.id)
                 FROM exam_attempts a
                 INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
                 INNER JOIN exam_schedules s ON s.id = sp.schedule_id
                 WHERE s.package_id = p.id) AS attempt_count,
                (SELECT COUNT(*) FROM exam_schedules s WHERE s.package_id = p.id) AS schedule_count
         FROM test_packages p
         ORDER BY FIELD(p.status, 'published', 'draft', 'archived'), p.updated_at DESC, p.id DESC"
    )->fetchAll();
}

function assessment_builder_module_catalog(?int $packageId = null): array
{
    if ($packageId !== null && $packageId > 0) {
        $statement = db()->prepare(
            "SELECT m.id, m.code, m.name, m.category, m.module_kind, m.duration_minutes,
                    m.status, m.description,
                    pm.sort_order AS selected_sort_order, pm.duration_override_minutes,
                    pm.is_required,
                    (SELECT COUNT(*) FROM module_questions mq INNER JOIN question_bank q ON q.id = mq.question_id
                     WHERE mq.module_id = m.id AND q.is_active = 1) AS question_count
             FROM assessment_modules m
             LEFT JOIN package_modules pm ON pm.module_id = m.id AND pm.package_id = :package_id
             WHERE m.status <> 'archived' OR pm.id IS NOT NULL
             ORDER BY (pm.id IS NOT NULL) DESC, COALESCE(pm.sort_order, 65535), m.status, m.name
             LIMIT 300"
        );
        $statement->execute(['package_id' => $packageId]);
        return $statement->fetchAll();
    }

    return db()->query(
        "SELECT m.id, m.code, m.name, m.category, m.module_kind, m.duration_minutes,
                m.status, m.description,
                NULL AS selected_sort_order, NULL AS duration_override_minutes,
                1 AS is_required,
                (SELECT COUNT(*) FROM module_questions mq INNER JOIN question_bank q ON q.id = mq.question_id
                 WHERE mq.module_id = m.id AND q.is_active = 1) AS question_count
         FROM assessment_modules m
         WHERE m.status <> 'archived'
         ORDER BY FIELD(m.status, 'published', 'draft'), m.category, m.name
         LIMIT 300"
    )->fetchAll();
}

function assessment_builder_get_package(int $packageId): ?array
{
    if ($packageId < 1) {
        return null;
    }

    $statement = db()->prepare('SELECT * FROM test_packages WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $packageId]);
    $package = $statement->fetch();
    if ($package === false) {
        return null;
    }

    $attempt = db()->prepare(
        'SELECT a.id FROM exam_attempts a
         INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
         INNER JOIN exam_schedules s ON s.id = sp.schedule_id
         WHERE s.package_id = :package_id LIMIT 1'
    );
    $attempt->execute(['package_id' => $packageId]);
    $package['has_attempts'] = $attempt->fetchColumn() !== false;
    $package['modules'] = assessment_builder_module_catalog($packageId);

    return $package;
}

function assessment_builder_normalize_ids(mixed $rawIds, string $label): array
{
    if (!is_array($rawIds)) {
        throw new DomainException('Pilih minimal satu ' . $label . '.');
    }
    $ids = [];
    foreach ($rawIds as $rawId) {
        if (!is_scalar($rawId)) {
            throw new DomainException('Daftar ' . $label . ' tidak valid. Muat ulang halaman lalu coba lagi.');
        }
        $parsed = filter_var($rawId, FILTER_VALIDATE_INT);
        if (!is_int($parsed) || $parsed < 1) {
            throw new DomainException('Daftar ' . $label . ' tidak valid. Muat ulang halaman lalu coba lagi.');
        }
        $ids[] = $parsed;
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        throw new DomainException('Pilih minimal satu ' . $label . '.');
    }
    if (count($ids) > 600) {
        throw new DomainException('Pilihan melebihi batas 600 item per ' . $label . '.');
    }

    return $ids;
}

function assessment_builder_sorted_entries(array $ids, mixed $rawSettings, string $kind): array
{
    $settings = is_array($rawSettings) ? $rawSettings : [];
    $entries = [];
    foreach ($ids as $index => $id) {
        $raw = $settings[$id] ?? $settings[(string) $id] ?? [];
        $raw = is_array($raw) ? $raw : [];
        $orderRaw = trim(assessment_builder_scalar($raw['sort_order'] ?? ''));
        if ($orderRaw === '') {
            $order = $index + 1;
        } elseif (preg_match('/^[0-9]+$/D', $orderRaw) && (int) $orderRaw >= 1 && (int) $orderRaw <= 65535) {
            $order = (int) $orderRaw;
        } else {
            throw new DomainException('Nomor urutan setiap ' . $kind . ' harus berupa angka positif.');
        }
        $entries[] = ['id' => $id, 'requested_order' => $order, 'index' => $index, 'settings' => $raw];
    }
    usort($entries, static fn (array $a, array $b): int => ($a['requested_order'] <=> $b['requested_order']) ?: ($a['index'] <=> $b['index']));

    return $entries;
}

function assessment_builder_module_has_attempts(PDO $pdo, int $moduleId): bool
{
    $statement = $pdo->prepare(
        'SELECT amp.id FROM attempt_module_progress amp
         INNER JOIN package_modules pm ON pm.id = amp.package_module_id
         WHERE pm.module_id = :module_id LIMIT 1 FOR UPDATE'
    );
    $statement->execute(['module_id' => $moduleId]);

    return $statement->fetchColumn() !== false;
}

function assessment_builder_package_has_attempts(PDO $pdo, int $packageId): bool
{
    $statement = $pdo->prepare(
        'SELECT a.id FROM exam_attempts a
         INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
         INNER JOIN exam_schedules s ON s.id = sp.schedule_id
         WHERE s.package_id = :package_id LIMIT 1 FOR UPDATE'
    );
    $statement->execute(['package_id' => $packageId]);

    return $statement->fetchColumn() !== false;
}

/** Do not let an active schedule's package definition drift after participant invitations exist. */
function assessment_builder_package_has_active_schedule_participants(PDO $pdo, int $packageId): bool
{
    $statement = $pdo->prepare(
        "SELECT sp.id FROM schedule_participants sp
         INNER JOIN exam_schedules s ON s.id = sp.schedule_id
         WHERE s.package_id = :package_id AND sp.status <> 'cancelled'
         LIMIT 1"
    );
    $statement->execute(['package_id' => $packageId]);

    return $statement->fetchColumn() !== false;
}

function assessment_builder_save_module(array $input, int $actorUserId): int
{
    $categories = question_bank_categories();
    $id = max(0, (int) assessment_builder_scalar($input['id'] ?? 0));
    $code = strtoupper(trim(assessment_builder_scalar($input['code'] ?? '')));
    $name = trim(assessment_builder_scalar($input['name'] ?? ''));
    $category = assessment_builder_scalar($input['category'] ?? 'OTHER');
    $kind = assessment_builder_scalar($input['module_kind'] ?? 'standard');
    $description = trim(assessment_builder_scalar($input['description'] ?? ''));
    $durationRaw = trim(assessment_builder_scalar($input['duration_minutes'] ?? '30'));
    $selectionMode = assessment_builder_scalar($input['question_selection_mode'] ?? 'selected');
    $randomCountRaw = trim(assessment_builder_scalar($input['random_question_count'] ?? ''));
    $status = assessment_builder_scalar($input['status'] ?? 'draft');

    if ($code === '' || !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $code)) {
        throw new DomainException('Kode modul wajib diisi dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
    }
    if ($name === '' || assessment_builder_length($name) > 180) {
        throw new DomainException('Nama modul wajib diisi dan maksimal 180 karakter.');
    }
    if (!isset($categories[$category])) {
        throw new DomainException('Pilih kategori modul yang tersedia.');
    }
    if (!in_array($kind, ['standard', 'review_360'], true)) {
        throw new DomainException('Jenis modul tidak valid.');
    }
    if (assessment_builder_length($description) > 12000) {
        throw new DomainException('Deskripsi modul maksimal 12.000 karakter.');
    }
    if (!preg_match('/^[0-9]+$/D', $durationRaw) || (int) $durationRaw < 1 || (int) $durationRaw > 1440) {
        throw new DomainException('Durasi modul harus antara 1 dan 1.440 menit.');
    }
    if (!in_array($selectionMode, ['selected', 'random'], true)) {
        throw new DomainException('Pilih metode pemilihan soal yang tersedia.');
    }
    if (!in_array($status, ['draft', 'published', 'archived'], true)) {
        throw new DomainException('Status modul tidak valid.');
    }

    $questionIds = assessment_builder_normalize_ids($input['question_ids'] ?? [], 'soal');
    $entries = assessment_builder_sorted_entries($questionIds, $input['question_settings'] ?? [], 'soal');
    if ($selectionMode === 'random') {
        if (!preg_match('/^[0-9]+$/D', $randomCountRaw) || (int) $randomCountRaw < 1 || (int) $randomCountRaw > count($questionIds)) {
            throw new DomainException('Jumlah soal acak harus minimal 1 dan tidak melebihi jumlah soal terpilih.');
        }
        $randomCount = (int) $randomCountRaw;
    } else {
        $randomCount = null;
    }
    if ($status === 'published' && $questionIds === []) {
        throw new DomainException('Modul harus memiliki soal sebelum dapat diterbitkan.');
    }

    foreach ($entries as &$entry) {
        $settings = $entry['settings'];
        $overrideRaw = trim(assessment_builder_scalar($settings['points_override'] ?? ''));
        $entry['points_override'] = null;
        if ($overrideRaw !== '') {
            if (!is_numeric($overrideRaw) || (float) $overrideRaw <= 0 || (float) $overrideRaw > 999999.99) {
                throw new DomainException('Bobot poin setiap soal harus lebih besar dari 0 dan tidak melebihi 999.999,99.');
            }
            $entry['points_override'] = (float) $overrideRaw;
        }
        $requiredRaw = $settings['is_required'] ?? '';
        $entry['is_required'] = assessment_builder_checked($requiredRaw) ? 1 : 0;
    }
    unset($entry);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $lock = $pdo->prepare('SELECT id FROM assessment_modules WHERE id = :id LIMIT 1 FOR UPDATE');
            $lock->execute(['id' => $id]);
            if ($lock->fetchColumn() === false) {
                throw new DomainException('Modul yang akan diperbarui tidak ditemukan.');
            }
            if (assessment_builder_module_has_attempts($pdo, $id)) {
                throw new DomainException('Modul sudah dipakai dalam sesi peserta. Kontennya dikunci untuk menjaga validitas hasil. Buat modul baru untuk perubahan.');
            }

            if ($status !== 'published') {
                $publishedPackage = $pdo->prepare(
                    "SELECT pm.id FROM package_modules pm
                     INNER JOIN test_packages p ON p.id = pm.package_id
                     WHERE pm.module_id = :module_id AND p.status = 'published' LIMIT 1"
                );
                $publishedPackage->execute(['module_id' => $id]);
                if ($publishedPackage->fetchColumn() !== false) {
                    throw new DomainException('Modul ini masih berada di paket yang diterbitkan. Ubah status paket terkait sebelum menurunkan status atau mengarsipkan modul.');
                }
            }

            $update = $pdo->prepare(
                'UPDATE assessment_modules
                 SET code = :code, name = :name, category = :category, module_kind = :module_kind,
                     description = :description, duration_minutes = :duration_minutes,
                     question_selection_mode = :selection_mode, random_question_count = :random_count,
                     shuffle_questions = :shuffle_questions, shuffle_options = :shuffle_options,
                     status = :status
                 WHERE id = :id'
            );
            $update->execute([
                'code' => $code,
                'name' => $name,
                'category' => $category,
                'module_kind' => $kind,
                'description' => $description !== '' ? $description : null,
                'duration_minutes' => (int) $durationRaw,
                'selection_mode' => $selectionMode,
                'random_count' => $randomCount,
                'shuffle_questions' => assessment_builder_checked($input['shuffle_questions'] ?? false) ? 1 : 0,
                'shuffle_options' => assessment_builder_checked($input['shuffle_options'] ?? false) ? 1 : 0,
                'status' => $status,
                'id' => $id,
            ]);
            $moduleId = $id;
            $pdo->prepare('DELETE FROM module_questions WHERE module_id = :module_id')
                ->execute(['module_id' => $moduleId]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO assessment_modules
                    (code, name, category, module_kind, description, duration_minutes,
                     question_selection_mode, random_question_count, shuffle_questions,
                     shuffle_options, status, created_by_user_id)
                 VALUES
                    (:code, :name, :category, :module_kind, :description, :duration_minutes,
                     :selection_mode, :random_count, :shuffle_questions,
                     :shuffle_options, :status, :created_by_user_id)'
            );
            $insert->execute([
                'code' => $code,
                'name' => $name,
                'category' => $category,
                'module_kind' => $kind,
                'description' => $description !== '' ? $description : null,
                'duration_minutes' => (int) $durationRaw,
                'selection_mode' => $selectionMode,
                'random_count' => $randomCount,
                'shuffle_questions' => assessment_builder_checked($input['shuffle_questions'] ?? false) ? 1 : 0,
                'shuffle_options' => assessment_builder_checked($input['shuffle_options'] ?? false) ? 1 : 0,
                'status' => $status,
                'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ]);
            $moduleId = (int) $pdo->lastInsertId();
        }

        $questionPlaceholders = implode(', ', array_fill(0, count($questionIds), '?'));
        $activeQuestions = $pdo->prepare("SELECT id FROM question_bank WHERE is_active = 1 AND id IN ($questionPlaceholders) FOR UPDATE");
        $activeQuestions->execute($questionIds);
        $activeIds = array_map('intval', $activeQuestions->fetchAll(PDO::FETCH_COLUMN));
        if (count($activeIds) !== count($questionIds)) {
            throw new DomainException('Satu atau beberapa soal telah diarsipkan. Hapus soal tidak aktif dari pilihan modul lalu simpan kembali.');
        }

        $insertQuestion = $pdo->prepare(
            'INSERT INTO module_questions (module_id, question_id, sort_order, points_override, is_required)
             VALUES (:module_id, :question_id, :sort_order, :points_override, :is_required)'
        );
        foreach ($entries as $index => $entry) {
            $insertQuestion->execute([
                'module_id' => $moduleId,
                'question_id' => $entry['id'],
                'sort_order' => $index + 1,
                'points_override' => $entry['points_override'],
                'is_required' => $entry['is_required'],
            ]);
        }

        $pdo->commit();
        return $moduleId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException
            && (string) $exception->getCode() === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('Kode modul tersebut sudah digunakan. Pilih kode yang berbeda.', 0, $exception);
        }
        throw $exception;
    }
}

function assessment_builder_set_module_status(int $moduleId, string $status): void
{
    if ($moduleId < 1 || !in_array($status, ['draft', 'published', 'archived'], true)) {
        throw new DomainException('Modul atau status tidak valid.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM assessment_modules WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute(['id' => $moduleId]);
        if ($lock->fetchColumn() === false) {
            throw new DomainException('Modul tidak ditemukan.');
        }
        if (assessment_builder_module_has_attempts($pdo, $moduleId)) {
            throw new DomainException('Modul sudah digunakan dalam sesi peserta dan statusnya tidak dapat diubah.');
        }
        if ($status === 'published') {
            $count = $pdo->prepare(
                "SELECT m.question_selection_mode, m.random_question_count,
                        (SELECT COUNT(*) FROM module_questions mq
                         INNER JOIN question_bank q ON q.id = mq.question_id
                         WHERE mq.module_id = m.id AND q.is_active = 1) AS active_question_count
                 FROM assessment_modules m WHERE m.id = :module_id LIMIT 1"
            );
            $count->execute(['module_id' => $moduleId]);
            $module = $count->fetch();
            if (!$module || (int) $module['active_question_count'] < 1) {
                throw new DomainException('Tambahkan minimal satu soal aktif sebelum menerbitkan modul.');
            }
            if ($module['question_selection_mode'] === 'random'
                && $module['random_question_count'] !== null
                && (int) $module['random_question_count'] > (int) $module['active_question_count']) {
                throw new DomainException('Jumlah soal acak melebihi soal aktif. Perbarui jumlah acak atau pilih soal pengganti.');
            }
        }
        if ($status !== 'published') {
            $publishedPackage = $pdo->prepare(
                "SELECT pm.id FROM package_modules pm
                 INNER JOIN test_packages p ON p.id = pm.package_id
                 WHERE pm.module_id = :module_id AND p.status = 'published' LIMIT 1"
            );
            $publishedPackage->execute(['module_id' => $moduleId]);
            if ($publishedPackage->fetchColumn() !== false) {
                throw new DomainException('Modul ini masih berada di paket yang diterbitkan. Ubah status paket terkait sebelum menurunkan status atau mengarsipkan modul.');
            }
        }
        $pdo->prepare('UPDATE assessment_modules SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $moduleId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function assessment_builder_save_package(array $input, int $actorUserId): int
{
    $id = max(0, (int) assessment_builder_scalar($input['id'] ?? 0));
    $code = strtoupper(trim(assessment_builder_scalar($input['code'] ?? '')));
    $name = trim(assessment_builder_scalar($input['name'] ?? ''));
    $type = assessment_builder_scalar($input['package_type'] ?? 'recruitment');
    $description = trim(assessment_builder_scalar($input['description'] ?? ''));
    $targetPosition = trim(assessment_builder_scalar($input['target_position'] ?? ''));
    $passingRaw = trim(assessment_builder_scalar($input['passing_score'] ?? ''));
    $status = assessment_builder_scalar($input['status'] ?? 'draft');
    $moduleIds = assessment_builder_normalize_ids($input['module_ids'] ?? [], 'modul');
    $entries = assessment_builder_sorted_entries($moduleIds, $input['module_settings'] ?? [], 'modul');

    if ($code === '' || !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $code)) {
        throw new DomainException('Kode paket wajib diisi dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
    }
    if ($name === '' || assessment_builder_length($name) > 180) {
        throw new DomainException('Nama paket wajib diisi dan maksimal 180 karakter.');
    }
    if (!in_array($type, ['recruitment', 'internal', 'review_360'], true)) {
        throw new DomainException('Jenis paket tidak valid.');
    }
    if (assessment_builder_length($description) > 12000 || assessment_builder_length($targetPosition) > 150) {
        throw new DomainException('Deskripsi maksimal 12.000 karakter dan posisi sasaran maksimal 150 karakter.');
    }
    if ($passingRaw !== '' && (!is_numeric($passingRaw) || (float) $passingRaw < 0 || (float) $passingRaw > 999999.99)) {
        throw new DomainException('Passing grade harus angka antara 0 dan 999.999,99.');
    }
    if (!in_array($status, ['draft', 'published', 'archived'], true)) {
        throw new DomainException('Status paket tidak valid.');
    }

    foreach ($entries as &$entry) {
        $overrideRaw = trim(assessment_builder_scalar($entry['settings']['duration_override_minutes'] ?? ''));
        $entry['duration_override_minutes'] = null;
        if ($overrideRaw !== '') {
            if (!preg_match('/^[0-9]+$/D', $overrideRaw) || (int) $overrideRaw < 1 || (int) $overrideRaw > 1440) {
                throw new DomainException('Durasi pengganti setiap modul harus antara 1 dan 1.440 menit.');
            }
            $entry['duration_override_minutes'] = (int) $overrideRaw;
        }
        // Package modules are all required until the runner supports participant-driven skips.
        $entry['is_required'] = 1;
    }
    unset($entry);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $lock = $pdo->prepare('SELECT id FROM test_packages WHERE id = :id LIMIT 1 FOR UPDATE');
            $lock->execute(['id' => $id]);
            if ($lock->fetchColumn() === false) {
                throw new DomainException('Paket yang akan diperbarui tidak ditemukan.');
            }
            if (assessment_builder_package_has_attempts($pdo, $id)) {
                throw new DomainException('Paket sudah digunakan oleh peserta. Kontennya dikunci untuk menjaga validitas hasil. Buat paket baru untuk perubahan.');
            }
            if (assessment_builder_package_has_active_schedule_participants($pdo, $id)) {
                throw new DomainException('Paket memiliki peserta terundang pada jadwal aktif. Batalkan undangan terlebih dahulu sebelum mengubah isi atau jenis paket.');
            }
        }

        $modulePlaceholders = implode(', ', array_fill(0, count($moduleIds), '?'));
        $moduleStatement = $pdo->prepare(
            "SELECT m.id, m.status, m.module_kind,
                    (SELECT COUNT(*) FROM module_questions mq
                     INNER JOIN question_bank q ON q.id = mq.question_id
                     WHERE mq.module_id = m.id AND q.is_active = 1) AS active_question_count
             FROM assessment_modules m WHERE m.id IN ($modulePlaceholders) FOR UPDATE"
        );
        $moduleStatement->execute($moduleIds);
        $moduleRows = $moduleStatement->fetchAll();
        if (count($moduleRows) !== count($moduleIds)) {
            throw new DomainException('Satu atau beberapa modul yang dipilih tidak ditemukan.');
        }
        $containsReview360 = false;
        foreach ($moduleRows as $module) {
            if ($module['status'] === 'archived') {
                throw new DomainException('Paket tidak dapat menggunakan modul yang diarsipkan.');
            }
            if ($status === 'published' && $module['status'] !== 'published') {
                throw new DomainException('Terbitkan semua modul yang dipilih sebelum menerbitkan paket.');
            }
            if ($status === 'published' && (int) $module['active_question_count'] < 1) {
                throw new DomainException('Paket terbit hanya dapat memuat modul yang memiliki soal aktif.');
            }
            if ($module['module_kind'] === 'review_360') {
                $containsReview360 = true;
            }
        }
        if ($containsReview360 !== ($type === 'review_360')) {
            throw new DomainException('Paket evaluasi 360° harus memuat modul 360°, dan modul 360° hanya dapat dimasukkan ke paket berjenis evaluasi 360°.');
        }

        if ($id > 0) {
            $update = $pdo->prepare(
                'UPDATE test_packages
                 SET code = :code, name = :name, package_type = :package_type,
                     description = :description, target_position = :target_position,
                     passing_score = :passing_score, status = :status
                 WHERE id = :id'
            );
            $update->execute([
                'code' => $code,
                'name' => $name,
                'package_type' => $type,
                'description' => $description !== '' ? $description : null,
                'target_position' => $targetPosition !== '' ? $targetPosition : null,
                'passing_score' => $passingRaw !== '' ? (float) $passingRaw : null,
                'status' => $status,
                'id' => $id,
            ]);
            $packageId = $id;
            $pdo->prepare('DELETE FROM package_modules WHERE package_id = :package_id')
                ->execute(['package_id' => $packageId]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO test_packages
                    (code, name, package_type, description, target_position, passing_score, status, created_by_user_id)
                 VALUES (:code, :name, :package_type, :description, :target_position, :passing_score, :status, :created_by_user_id)'
            );
            $insert->execute([
                'code' => $code,
                'name' => $name,
                'package_type' => $type,
                'description' => $description !== '' ? $description : null,
                'target_position' => $targetPosition !== '' ? $targetPosition : null,
                'passing_score' => $passingRaw !== '' ? (float) $passingRaw : null,
                'status' => $status,
                'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ]);
            $packageId = (int) $pdo->lastInsertId();
        }

        $insertModule = $pdo->prepare(
            'INSERT INTO package_modules (package_id, module_id, sort_order, duration_override_minutes, is_required)
             VALUES (:package_id, :module_id, :sort_order, :duration_override, :is_required)'
        );
        foreach ($entries as $index => $entry) {
            $insertModule->execute([
                'package_id' => $packageId,
                'module_id' => $entry['id'],
                'sort_order' => $index + 1,
                'duration_override' => $entry['duration_override_minutes'],
                'is_required' => $entry['is_required'],
            ]);
        }

        $pdo->commit();
        return $packageId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException
            && (string) $exception->getCode() === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('Kode paket tersebut sudah digunakan. Pilih kode yang berbeda.', 0, $exception);
        }
        throw $exception;
    }
}

function assessment_builder_set_package_status(int $packageId, string $status): void
{
    if ($packageId < 1 || !in_array($status, ['draft', 'published', 'archived'], true)) {
        throw new DomainException('Paket atau status tidak valid.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM test_packages WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute(['id' => $packageId]);
        if ($lock->fetchColumn() === false) {
            throw new DomainException('Paket tidak ditemukan.');
        }
        if (assessment_builder_package_has_attempts($pdo, $packageId)) {
            throw new DomainException('Paket sudah digunakan oleh peserta dan statusnya tidak dapat diubah.');
        }
        if (assessment_builder_package_has_active_schedule_participants($pdo, $packageId)) {
            throw new DomainException('Paket memiliki peserta terundang pada jadwal aktif. Tutup jadwal atau batalkan semua undangan terlebih dahulu.');
        }
        if ($status === 'published') {
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM package_modules pm
                 INNER JOIN assessment_modules m ON m.id = pm.module_id
                 INNER JOIN module_questions mq ON mq.module_id = m.id
                 INNER JOIN question_bank q ON q.id = mq.question_id AND q.is_active = 1
                 WHERE pm.package_id = :package_id AND m.status = 'published'"
            );
            $check->execute(['package_id' => $packageId]);
            if ((int) $check->fetchColumn() < 1) {
                throw new DomainException('Paket harus memiliki minimal satu modul terbit dengan soal aktif.');
            }
            $invalid = $pdo->prepare(
                "SELECT pm.id FROM package_modules pm
                 INNER JOIN assessment_modules m ON m.id = pm.module_id
                 WHERE pm.package_id = :package_id AND m.status <> 'published' LIMIT 1"
            );
            $invalid->execute(['package_id' => $packageId]);
            if ($invalid->fetchColumn() !== false) {
                throw new DomainException('Terbitkan semua modul di paket sebelum menerbitkan paket.');
            }
            $invalidRandomCount = $pdo->prepare(
                "SELECT m.id FROM package_modules pm
                 INNER JOIN assessment_modules m ON m.id = pm.module_id
                 WHERE pm.package_id = :package_id AND m.question_selection_mode = 'random'
                   AND m.random_question_count IS NOT NULL
                   AND m.random_question_count > (
                       SELECT COUNT(*) FROM module_questions mq
                       INNER JOIN question_bank q ON q.id = mq.question_id
                       WHERE mq.module_id = m.id AND q.is_active = 1
                   )
                 LIMIT 1"
            );
            $invalidRandomCount->execute(['package_id' => $packageId]);
            if ($invalidRandomCount->fetchColumn() !== false) {
                throw new DomainException('Jumlah soal acak pada salah satu modul melebihi soal aktif. Perbaiki modul tersebut sebelum menerbitkan paket.');
            }
        }
        $pdo->prepare('UPDATE test_packages SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $packageId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
