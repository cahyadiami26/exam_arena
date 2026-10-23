<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function question_bank_categories(): array
{
    return [
        'TPA' => 'Tes Potensi Akademik',
        'COGNITIVE' => 'Kognitif',
        'PAPI_KOSTICK' => 'PAPI Kostick',
        'DISC' => 'DISC',
        'MOTIVATION' => 'Motivasi & Gaya Kerja',
        'OFFICE' => 'Software Office',
        'TECHNICAL' => 'Kompetensi Teknis',
        'ESSAY' => 'Esai',
        '360' => 'Evaluasi 360°',
        'OTHER' => 'Lainnya',
    ];
}

function question_bank_types(): array
{
    return [
        'single_choice' => 'Pilihan tunggal',
        'multi_select' => 'Pilihan ganda / multi-select',
        'forced_choice' => 'Forced choice · Most / Least',
        'essay' => 'Esai / uraian',
        'file_upload' => 'Unggah file praktik',
    ];
}

function question_bank_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function question_bank_scalar_text(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

function question_bank_summary(): array
{
    $row = db()->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(is_active = 1), 0) AS active,
                COALESCE(SUM(is_active = 0), 0) AS archived
         FROM question_bank"
    )->fetch() ?: [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'active' => (int) ($row['active'] ?? 0),
        'archived' => (int) ($row['archived'] ?? 0),
    ];
}

function question_bank_list(array $filters = []): array
{
    $categories = question_bank_categories();
    $types = question_bank_types();
    $term = trim(question_bank_scalar_text($filters['q'] ?? ''));
    $term = function_exists('mb_substr') ? mb_substr($term, 0, 160, 'UTF-8') : substr($term, 0, 160);
    $category = question_bank_scalar_text($filters['category'] ?? '');
    $type = question_bank_scalar_text($filters['type'] ?? '');
    $state = question_bank_scalar_text($filters['state'] ?? 'active');

    $where = [];
    $parameters = [];
    if ($term !== '') {
        $where[] = '(q.code LIKE :code OR q.question_text LIKE :question_text)';
        $like = '%' . $term . '%';
        $parameters['code'] = $like;
        $parameters['question_text'] = $like;
    }
    if (isset($categories[$category])) {
        $where[] = 'q.category = :category';
        $parameters['category'] = $category;
    } else {
        $category = '';
    }
    if (isset($types[$type])) {
        $where[] = 'q.question_type = :question_type';
        $parameters['question_type'] = $type;
    } else {
        $type = '';
    }
    if ($state === 'active') {
        $where[] = 'q.is_active = 1';
    } elseif ($state === 'archived') {
        $where[] = 'q.is_active = 0';
    } else {
        $state = 'all';
    }

    $sql = "SELECT q.id, q.code, q.category, q.question_type, q.question_text,
                   q.max_score, q.weight, q.is_active, q.updated_at,
                   (SELECT COUNT(*) FROM question_options qo WHERE qo.question_id = q.id) AS option_count,
                   (SELECT COUNT(*) FROM module_questions mq WHERE mq.question_id = q.id) AS module_count,
                   (SELECT COUNT(*) FROM attempt_module_questions amq WHERE amq.question_id = q.id) AS attempt_count
            FROM question_bank q";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY q.updated_at DESC, q.id DESC LIMIT 200';

    $statement = db()->prepare($sql);
    $statement->execute($parameters);

    return [
        'items' => $statement->fetchAll(),
        'filters' => ['q' => $term, 'category' => $category, 'type' => $type, 'state' => $state],
    ];
}

function question_bank_get(int $questionId): ?array
{
    if ($questionId < 1) {
        return null;
    }

    $statement = db()->prepare('SELECT * FROM question_bank WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $questionId]);
    $question = $statement->fetch();
    if ($question === false) {
        return null;
    }

    $options = db()->prepare(
        'SELECT option_key, option_text, is_correct, score_value, papi_aspect_code
         FROM question_options WHERE question_id = :question_id ORDER BY sort_order, id'
    );
    $options->execute(['question_id' => $questionId]);
    $question['options'] = $options->fetchAll();

    $attempts = db()->prepare('SELECT id FROM attempt_module_questions WHERE question_id = :question_id LIMIT 1');
    $attempts->execute(['question_id' => $questionId]);
    $question['has_attempts'] = $attempts->fetchColumn() !== false;

    return $question;
}

/** Validate and save a bank question. Questions already present in an attempt are immutable. */
function question_bank_save(array $input, int $actorUserId): int
{
    $categories = question_bank_categories();
    $types = question_bank_types();
    $id = max(0, (int) question_bank_scalar_text($input['id'] ?? 0));
    $code = strtoupper(trim(question_bank_scalar_text($input['code'] ?? '')));
    $category = question_bank_scalar_text($input['category'] ?? 'OTHER');
    $type = question_bank_scalar_text($input['question_type'] ?? '');
    $questionText = trim(question_bank_scalar_text($input['question_text'] ?? ''));
    $instructions = trim(question_bank_scalar_text($input['instructions'] ?? ''));
    $rubric = trim(question_bank_scalar_text($input['rubric'] ?? ''));
    $papiItemCode = strtoupper(trim(question_bank_scalar_text($input['papi_item_code'] ?? '')));
    $maxScoreRaw = trim(question_bank_scalar_text($input['max_score'] ?? '1'));
    $weightRaw = trim(question_bank_scalar_text($input['weight'] ?? '1'));

    if ($code !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,49}$/', $code)) {
        throw new DomainException('Kode soal hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.');
    }
    if (!isset($categories[$category])) {
        throw new DomainException('Pilih kategori soal yang tersedia.');
    }
    if (!isset($types[$type])) {
        throw new DomainException('Pilih jenis soal yang tersedia.');
    }
    if ($questionText === '' || question_bank_text_length($questionText) > 60000) {
        throw new DomainException('Teks soal wajib diisi dan maksimal 60.000 karakter.');
    }
    if (question_bank_text_length($instructions) > 12000 || question_bank_text_length($rubric) > 60000) {
        throw new DomainException('Instruksi maksimal 12.000 karakter dan rubrik maksimal 60.000 karakter.');
    }
    if (!is_numeric($maxScoreRaw) || !is_numeric($weightRaw)) {
        throw new DomainException('Skor maksimum dan bobot harus berupa angka.');
    }
    $maxScore = (float) $maxScoreRaw;
    $weight = (float) $weightRaw;
    if (!is_finite($maxScore) || $maxScore <= 0 || $maxScore > 999999.99
        || !is_finite($weight) || $weight <= 0 || $weight > 999999.99) {
        throw new DomainException('Skor maksimum dan bobot harus lebih besar dari 0 dan tidak melebihi batas sistem.');
    }
    if ($papiItemCode !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $papiItemCode)) {
        throw new DomainException('Kode item PAPI tidak valid.');
    }

    $rawOptions = $input['options'] ?? [];
    if (!is_array($rawOptions)) {
        $rawOptions = [];
    }
    $options = [];
    foreach (array_values($rawOptions) as $rawOption) {
        if (!is_array($rawOption)) {
            continue;
        }
        $optionText = trim(question_bank_scalar_text($rawOption['text'] ?? ''));
        if ($optionText === '') {
            continue;
        }
        if (question_bank_text_length($optionText) > 5000) {
            throw new DomainException('Teks setiap pilihan maksimal 5.000 karakter.');
        }
        $aspectCode = strtoupper(trim(question_bank_scalar_text($rawOption['papi_aspect_code'] ?? '')));
        if ($aspectCode !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $aspectCode)) {
            throw new DomainException('Kode aspek PAPI pada pilihan tidak valid.');
        }
        $rawCorrect = $rawOption['is_correct'] ?? false;
        $isCorrect = $rawCorrect === true || in_array(question_bank_scalar_text($rawCorrect), ['1', 'on', 'true'], true);
        $options[] = [
            'text' => $optionText,
            'is_correct' => $isCorrect,
            'papi_aspect_code' => $aspectCode !== '' ? $aspectCode : null,
        ];
    }

    if (count($options) > 20) {
        throw new DomainException('Satu soal dapat memiliki paling banyak 20 pilihan.');
    }
    if (in_array($type, ['single_choice', 'multi_select', 'forced_choice'], true) && count($options) < 2) {
        throw new DomainException('Tambahkan sedikitnya dua pilihan jawaban.');
    }
    if ($type === 'forced_choice' && count($options) > 4) {
        throw new DomainException('Forced choice hanya mendukung dua sampai empat pilihan.');
    }
    if ($type === 'single_choice' && count(array_filter($options, static fn (array $option): bool => $option['is_correct'])) !== 1) {
        throw new DomainException('Soal pilihan tunggal harus memiliki tepat satu jawaban benar.');
    }
    if ($type === 'multi_select' && count(array_filter($options, static fn (array $option): bool => $option['is_correct'])) < 1) {
        throw new DomainException('Pilih minimal satu jawaban benar untuk soal multi-select.');
    }
    if ($type === 'forced_choice' && array_filter($options, static fn (array $option): bool => $option['is_correct'])) {
        throw new DomainException('Forced choice tidak menggunakan penanda jawaban benar.');
    }
    if (($papiItemCode !== '' || $category === 'PAPI_KOSTICK') && $type !== 'forced_choice') {
        throw new DomainException('Item PAPI Kostick harus menggunakan jenis Forced choice.');
    }
    if ($category === 'PAPI_KOSTICK' && $papiItemCode === '') {
        throw new DomainException('Isi kode item PAPI Kostick untuk soal pada kategori PAPI.');
    }
    if ($papiItemCode !== '') {
        foreach ($options as $option) {
            if (empty($option['papi_aspect_code'])) {
                throw new DomainException('Setiap pilihan pada item PAPI harus memiliki kode aspek.');
            }
        }
    }

    $allowedExtensions = null;
    $maxFileSizeMb = null;
    if ($type === 'file_upload') {
        $supported = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'csv', 'txt', 'zip'];
        $requested = array_values(array_unique(array_filter(array_map(
            static fn (string $extension): string => strtolower(ltrim(trim($extension), '.')),
            explode(',', question_bank_scalar_text($input['allowed_extensions'] ?? ''))
        ))));
        if ($requested === []) {
            $requested = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'csv', 'txt', 'zip'];
        }
        foreach ($requested as $extension) {
            if (!in_array($extension, $supported, true)) {
                throw new DomainException('Daftar ekstensi file berisi format yang tidak didukung.');
            }
        }
        $allowedExtensions = implode(',', $requested);
        $sizeRaw = trim(question_bank_scalar_text($input['max_file_size_mb'] ?? ''));
        if ($sizeRaw !== '') {
            if (!preg_match('/^[0-9]+$/D', $sizeRaw) || (int) $sizeRaw < 1 || (int) $sizeRaw > 20) {
                throw new DomainException('Batas ukuran file harus antara 1 dan 20 MB.');
            }
            $maxFileSizeMb = (int) $sizeRaw;
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $lock = $pdo->prepare('SELECT id FROM question_bank WHERE id = :id LIMIT 1 FOR UPDATE');
            $lock->execute(['id' => $id]);
            if ($lock->fetchColumn() === false) {
                throw new DomainException('Soal yang akan diperbarui tidak ditemukan.');
            }
            $attemptLock = $pdo->prepare('SELECT id FROM attempt_module_questions WHERE question_id = :question_id LIMIT 1 FOR UPDATE');
            $attemptLock->execute(['question_id' => $id]);
            if ($attemptLock->fetchColumn() !== false) {
                throw new DomainException('Soal sudah digunakan dalam sesi ujian. Untuk menjaga integritas hasil, kontennya dikunci; arsipkan soal dan buat salinan baru bila perlu perubahan.');
            }

            $update = $pdo->prepare(
                'UPDATE question_bank
                 SET code = :code, category = :category, question_type = :question_type,
                     question_text = :question_text, instructions = :instructions,
                     papi_item_code = :papi_item_code, rubric = :rubric,
                     allowed_extensions = :allowed_extensions, max_file_size_mb = :max_file_size_mb,
                     max_score = :max_score, weight = :weight
                 WHERE id = :id'
            );
            $update->execute([
                'code' => $code !== '' ? $code : null,
                'category' => $category,
                'question_type' => $type,
                'question_text' => $questionText,
                'instructions' => $instructions !== '' ? $instructions : null,
                'papi_item_code' => $papiItemCode !== '' ? $papiItemCode : null,
                'rubric' => $rubric !== '' ? $rubric : null,
                'allowed_extensions' => $allowedExtensions,
                'max_file_size_mb' => $maxFileSizeMb,
                'max_score' => $maxScore,
                'weight' => $weight,
                'id' => $id,
            ]);
            $questionId = $id;
            $pdo->prepare('DELETE FROM question_options WHERE question_id = :question_id')
                ->execute(['question_id' => $questionId]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO question_bank
                    (code, category, question_type, question_text, instructions, papi_item_code,
                     rubric, allowed_extensions, max_file_size_mb, max_score, weight, is_active, created_by_user_id)
                 VALUES
                    (:code, :category, :question_type, :question_text, :instructions, :papi_item_code,
                     :rubric, :allowed_extensions, :max_file_size_mb, :max_score, :weight, 1, :created_by_user_id)'
            );
            $insert->execute([
                'code' => $code !== '' ? $code : null,
                'category' => $category,
                'question_type' => $type,
                'question_text' => $questionText,
                'instructions' => $instructions !== '' ? $instructions : null,
                'papi_item_code' => $papiItemCode !== '' ? $papiItemCode : null,
                'rubric' => $rubric !== '' ? $rubric : null,
                'allowed_extensions' => $allowedExtensions,
                'max_file_size_mb' => $maxFileSizeMb,
                'max_score' => $maxScore,
                'weight' => $weight,
                'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ]);
            $questionId = (int) $pdo->lastInsertId();
        }

        if (in_array($type, ['single_choice', 'multi_select', 'forced_choice'], true)) {
            $insertOption = $pdo->prepare(
                'INSERT INTO question_options
                    (question_id, option_key, option_text, is_correct, score_value, papi_aspect_code, sort_order)
                 VALUES (:question_id, :option_key, :option_text, :is_correct, :score_value, :papi_aspect_code, :sort_order)'
            );
            foreach ($options as $index => $option) {
                $insertOption->execute([
                    'question_id' => $questionId,
                    'option_key' => chr(65 + $index),
                    'option_text' => $option['text'],
                    'is_correct' => $option['is_correct'] ? 1 : 0,
                    'score_value' => $option['is_correct'] ? 1 : 0,
                    'papi_aspect_code' => $option['papi_aspect_code'],
                    'sort_order' => $index + 1,
                ]);
            }
        }

        $pdo->commit();
        return $questionId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException
            && (string) $exception->getCode() === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('Kode soal tersebut sudah digunakan. Pilih kode yang berbeda.', 0, $exception);
        }
        throw $exception;
    }
}

function question_bank_set_active(int $questionId, bool $active): void
{
    if ($questionId < 1) {
        throw new DomainException('ID soal tidak valid.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM question_bank WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute(['id' => $questionId]);
        if ($lock->fetchColumn() === false) {
            throw new DomainException('Soal tidak ditemukan.');
        }

        if (!$active) {
            $lastQuestion = $pdo->prepare(
                "SELECT m.id
                 FROM module_questions current_question
                 INNER JOIN assessment_modules m ON m.id = current_question.module_id
                 INNER JOIN package_modules pm ON pm.module_id = m.id
                 INNER JOIN test_packages p ON p.id = pm.package_id
                 WHERE current_question.question_id = :question_id
                   AND m.status = 'published' AND p.status = 'published'
                   AND (
                       NOT EXISTS (
                           SELECT 1 FROM module_questions remaining_question
                           INNER JOIN question_bank remaining_bank ON remaining_bank.id = remaining_question.question_id
                           WHERE remaining_question.module_id = m.id
                             AND remaining_bank.is_active = 1
                             AND remaining_bank.id <> :excluded_question_id
                       )
                       OR (m.question_selection_mode = 'random'
                           AND m.random_question_count IS NOT NULL
                           AND m.random_question_count > (
                               SELECT COUNT(*) FROM module_questions random_question
                               INNER JOIN question_bank random_bank ON random_bank.id = random_question.question_id
                               WHERE random_question.module_id = m.id
                                 AND random_bank.is_active = 1
                                 AND random_bank.id <> :random_excluded_id
                           ))
                   )
                 LIMIT 1"
            );
            $lastQuestion->execute([
                'question_id' => $questionId,
                'excluded_question_id' => $questionId,
                'random_excluded_id' => $questionId,
            ]);
            if ($lastQuestion->fetchColumn() !== false) {
                throw new DomainException('Mengarsipkan soal ini akan membuat modul pada paket terbit kekurangan soal aktif, termasuk jumlah sampel acak. Tambahkan soal pengganti atau arsipkan paket terlebih dahulu.');
            }
        }

        $pdo->prepare('UPDATE question_bank SET is_active = :is_active WHERE id = :id')
            ->execute(['is_active' => $active ? 1 : 0, 'id' => $questionId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
