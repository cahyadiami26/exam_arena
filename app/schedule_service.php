<?php
declare(strict_types=1);

require_once __DIR__ . '/assessment_builder_service.php';

function exam_schedule_scalar(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

function exam_schedule_checked(mixed $value): bool
{
    return $value === true || in_array(exam_schedule_scalar($value), ['1', 'on', 'true'], true);
}

/** Search the read-only SSO directory. Every users-table record is eligible; the schedule enforces no role filter. */
function exam_schedule_search_users(string $term, int $limit = 30): array
{
    $term = trim($term);
    $length = function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
    if ($length < 2) {
        return [];
    }
    $term = function_exists('mb_substr') ? mb_substr($term, 0, 100, 'UTF-8') : substr($term, 0, 100);
    $limit = max(1, min(50, $limit));
    $statement = sso_db()->prepare(
        'SELECT id, name, email, position, division, department
         FROM users
         WHERE name LIKE :name OR email LIKE :email OR username LIKE :username OR nik LIKE :nik
         ORDER BY name ASC LIMIT :row_limit'
    );
    $like = '%' . $term . '%';
    $statement->bindValue(':name', $like, PDO::PARAM_STR);
    $statement->bindValue(':email', $like, PDO::PARAM_STR);
    $statement->bindValue(':username', $like, PDO::PARAM_STR);
    $statement->bindValue(':nik', $like, PDO::PARAM_STR);
    $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function exam_schedule_employee_profiles(array $rawUserIds): array
{
    $ids = [];
    foreach ($rawUserIds as $rawId) {
        if (!is_scalar($rawId)) {
            continue;
        }
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if (is_int($id) && $id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $statement = sso_db()->prepare(
        "SELECT id, name, email, position, division, department
         FROM users WHERE id IN ($placeholders)"
    );
    $statement->execute($ids);

    return $statement->fetchAll();
}

function exam_schedule_package_catalog(): array
{
    return db()->query(
        "SELECT p.id, p.code, p.name, p.package_type, p.passing_score,
                (SELECT COUNT(*) FROM package_modules pm WHERE pm.package_id = p.id) AS module_count,
                (SELECT COALESCE(SUM(COALESCE(pm.duration_override_minutes, m.duration_minutes)), 0)
                 FROM package_modules pm INNER JOIN assessment_modules m ON m.id = pm.module_id
                 WHERE pm.package_id = p.id) AS duration_minutes,
                EXISTS (SELECT 1 FROM package_modules pm INNER JOIN assessment_modules m ON m.id = pm.module_id
                        WHERE pm.package_id = p.id AND m.module_kind = 'review_360') AS has_review_360
         FROM test_packages p
         WHERE p.status = 'published'
         ORDER BY p.name, p.id DESC"
    )->fetchAll();
}

function exam_schedule_list(): array
{
    return db()->query(
        "SELECT s.*, p.name AS package_name, p.package_type,
                (SELECT COUNT(*) FROM schedule_participants sp WHERE sp.schedule_id = s.id AND sp.status <> 'cancelled') AS participant_count,
                (SELECT COUNT(*) FROM schedule_participants sp WHERE sp.schedule_id = s.id AND sp.participant_type = 'employee' AND sp.status <> 'cancelled') AS employee_count,
                (SELECT COUNT(*) FROM schedule_participants sp WHERE sp.schedule_id = s.id AND sp.participant_type = 'candidate' AND sp.status <> 'cancelled') AS candidate_count,
                (SELECT COUNT(*) FROM exam_attempts a
                 INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
                 WHERE sp.schedule_id = s.id) AS attempt_count
         FROM exam_schedules s INNER JOIN test_packages p ON p.id = s.package_id
         ORDER BY s.starts_at DESC, s.id DESC
         LIMIT 150"
    )->fetchAll();
}

function exam_schedule_get(int $scheduleId): ?array
{
    if ($scheduleId < 1) {
        return null;
    }
    $statement = db()->prepare(
        "SELECT s.*, p.name AS package_name, p.code AS package_code,
                p.package_type, p.status AS package_status,
                (SELECT COUNT(*) FROM schedule_participants sp WHERE sp.schedule_id = s.id AND sp.status <> 'cancelled') AS participant_count,
                (SELECT COUNT(*) FROM exam_attempts a
                 INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
                 WHERE sp.schedule_id = s.id) AS attempt_count
         FROM exam_schedules s INNER JOIN test_packages p ON p.id = s.package_id
         WHERE s.id = :id LIMIT 1"
    );
    $statement->execute(['id' => $scheduleId]);
    $schedule = $statement->fetch();

    return $schedule === false ? null : $schedule;
}

function exam_schedule_participants(int $scheduleId): array
{
    $statement = db()->prepare(
        "SELECT sp.id AS participant_id, sp.participant_type, sp.sso_user_id, sp.candidate_id,
                sp.status, sp.invite_token_hash, sp.token_expires_at, sp.invited_at,
                ec.name AS candidate_name, ec.email AS candidate_email,
                (SELECT COUNT(*) FROM exam_attempts a WHERE a.schedule_participant_id = sp.id) AS attempt_count,
                (SELECT a.status FROM exam_attempts a WHERE a.schedule_participant_id = sp.id
                 ORDER BY a.attempt_number DESC LIMIT 1) AS latest_attempt_status
         FROM schedule_participants sp
         LEFT JOIN external_candidates ec ON ec.id = sp.candidate_id
         WHERE sp.schedule_id = :schedule_id
         ORDER BY sp.created_at DESC, sp.id DESC"
    );
    $statement->execute(['schedule_id' => $scheduleId]);
    $rows = $statement->fetchAll();

    $employeeIds = array_values(array_unique(array_map(
        static fn (array $row): int => $row['participant_type'] === 'employee' ? (int) $row['sso_user_id'] : 0,
        $rows
    )));
    $employeeIds = array_values(array_filter($employeeIds, static fn (int $id): bool => $id > 0));
    $employees = [];
    if ($employeeIds !== []) {
        try {
            $placeholders = implode(', ', array_fill(0, count($employeeIds), '?'));
            $profile = sso_db()->prepare(
                "SELECT id, name, email, position, division, department
                 FROM users WHERE id IN ($placeholders)"
            );
            $profile->execute($employeeIds);
            foreach ($profile->fetchAll() as $user) {
                $employees[(int) $user['id']] = $user;
            }
        } catch (Throwable $exception) {
            error_log('E-Assessment participant directory lookup failed: ' . $exception->getMessage());
        }
    }

    foreach ($rows as &$row) {
        if ($row['participant_type'] === 'employee') {
            $profile = $employees[(int) $row['sso_user_id']] ?? null;
            $row['participant_name'] = $profile['name'] ?? ('Karyawan #' . (int) $row['sso_user_id']);
            $row['participant_email'] = $profile['email'] ?? '';
            $row['participant_position'] = $profile['position'] ?? '';
            $row['participant_division'] = $profile['division'] ?? '';
            $row['participant_department'] = $profile['department'] ?? '';
        } else {
            $row['participant_name'] = $row['candidate_name'] ?: 'Calon karyawan';
            $row['participant_email'] = $row['candidate_email'] ?: '';
            $row['participant_position'] = '';
            $row['participant_division'] = '';
            $row['participant_department'] = '';
        }
        $row['attempt_count'] = (int) $row['attempt_count'];
        $row['has_token'] = $row['participant_type'] === 'candidate' && !empty($row['invite_token_hash']);
    }
    unset($row);

    return $rows;
}

function exam_schedule_parse_datetime(string $value, string $label): DateTimeImmutable
{
    $value = trim($value);
    $timezone = new DateTimeZone((string) app_config()['app']['timezone']);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new DomainException('Isi tanggal dan waktu ' . $label . ' dengan format yang benar.');
    }

    return $date;
}

function exam_schedule_save(array $input, int $actorUserId): int
{
    $id = max(0, (int) exam_schedule_scalar($input['id'] ?? 0));
    $packageId = max(0, (int) exam_schedule_scalar($input['package_id'] ?? 0));
    $code = strtoupper(trim(exam_schedule_scalar($input['code'] ?? '')));
    $name = trim(exam_schedule_scalar($input['name'] ?? ''));
    $accessMode = exam_schedule_scalar($input['access_mode'] ?? 'employee_sso');
    $instructions = trim(exam_schedule_scalar($input['instructions'] ?? ''));
    $status = exam_schedule_scalar($input['status'] ?? 'scheduled');
    $start = exam_schedule_parse_datetime(exam_schedule_scalar($input['starts_at'] ?? ''), 'mulai');
    $end = exam_schedule_parse_datetime(exam_schedule_scalar($input['ends_at'] ?? ''), 'selesai');
    $allowRetake = exam_schedule_checked($input['allow_retake'] ?? false);
    $attemptRaw = trim(exam_schedule_scalar($input['max_attempts'] ?? '1'));

    if ($code === '' || !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $code)) {
        throw new DomainException('Kode jadwal wajib diisi dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
    }
    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 180) {
        throw new DomainException('Nama jadwal wajib diisi dan maksimal 180 karakter.');
    }
    if ($packageId < 1) {
        throw new DomainException('Pilih paket tes yang sudah diterbitkan.');
    }
    if (!in_array($accessMode, ['employee_sso', 'external_token', 'mixed'], true)) {
        throw new DomainException('Mode akses jadwal tidak valid.');
    }
    if (!in_array($status, ['draft', 'scheduled', 'open', 'closed', 'archived'], true)) {
        throw new DomainException('Status jadwal tidak valid.');
    }
    if ($end <= $start) {
        throw new DomainException('Waktu selesai harus lebih akhir daripada waktu mulai.');
    }
    if (in_array($status, ['scheduled', 'open'], true)
        && $end <= new DateTimeImmutable('now', new DateTimeZone((string) app_config()['app']['timezone']))) {
        throw new DomainException('Jadwal berstatus Terjadwal atau Dibuka harus memiliki waktu selesai yang belum lewat.');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($instructions, 'UTF-8') : strlen($instructions)) > 60000) {
        throw new DomainException('Instruksi jadwal maksimal 60.000 karakter.');
    }
    if (!preg_match('/^[0-9]+$/D', $attemptRaw) || (int) $attemptRaw < 1 || (int) $attemptRaw > 10) {
        throw new DomainException('Jumlah kesempatan harus antara 1 dan 10.');
    }
    $maxAttempts = $allowRetake ? (int) $attemptRaw : 1;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $lock = $pdo->prepare(
                "SELECT s.id,
                        (SELECT COUNT(*) FROM schedule_participants sp WHERE sp.schedule_id = s.id AND sp.status <> 'cancelled') AS participant_count,
                        (SELECT COUNT(*) FROM exam_attempts a INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id WHERE sp.schedule_id = s.id) AS attempt_count
                 FROM exam_schedules s WHERE s.id = :id LIMIT 1 FOR UPDATE"
            );
            $lock->execute(['id' => $id]);
            $lockedSchedule = $lock->fetch();
            if (!$lockedSchedule) {
                throw new DomainException('Jadwal yang akan diperbarui tidak ditemukan.');
            }
            if ((int) $lockedSchedule['participant_count'] > 0 || (int) $lockedSchedule['attempt_count'] > 0) {
                throw new DomainException('Jadwal sudah memiliki peserta terundang atau sesi ujian. Pengaturan pokoknya dikunci; ubah status dari panel status jadwal.');
            }
        }

        $package = $pdo->prepare('SELECT id, package_type, status FROM test_packages WHERE id = :id LIMIT 1 FOR UPDATE');
        $package->execute(['id' => $packageId]);
        $packageRow = $package->fetch();
        if (!$packageRow || $packageRow['status'] !== 'published') {
            throw new DomainException('Jadwal hanya dapat menggunakan paket yang sudah diterbitkan.');
        }
        if ($packageRow['package_type'] === 'review_360' && $accessMode !== 'employee_sso') {
            throw new DomainException('Paket evaluasi 360° hanya dapat diakses melalui SSO karyawan.');
        }
        if ($packageRow['package_type'] === 'review_360' && $allowRetake) {
            throw new DomainException('Jadwal evaluasi 360° menggunakan satu percobaan per reviewer untuk menjaga integritas alokasi.');
        }
        if ($packageRow['package_type'] === 'review_360' && $status === 'open') {
            throw new DomainException('Untuk membuka jadwal 360°, buat jadwal terjadwal, undang reviewer, lalu alokasikan subjek melalui panel Evaluasi 360°.');
        }

        if ($id > 0) {
            $update = $pdo->prepare(
                'UPDATE exam_schedules
                 SET package_id = :package_id, code = :code, name = :name,
                     access_mode = :access_mode, instructions = :instructions,
                     starts_at = :starts_at, ends_at = :ends_at,
                     allow_retake = :allow_retake, max_attempts = :max_attempts,
                     status = :status
                 WHERE id = :id'
            );
            $update->execute([
                'package_id' => $packageId,
                'code' => $code,
                'name' => $name,
                'access_mode' => $accessMode,
                'instructions' => $instructions !== '' ? $instructions : null,
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
                'allow_retake' => $allowRetake ? 1 : 0,
                'max_attempts' => $maxAttempts,
                'status' => $status,
                'id' => $id,
            ]);
            $scheduleId = $id;
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO exam_schedules
                    (package_id, code, name, access_mode, instructions, starts_at, ends_at,
                     allow_retake, max_attempts, status, created_by_user_id)
                 VALUES
                    (:package_id, :code, :name, :access_mode, :instructions, :starts_at, :ends_at,
                     :allow_retake, :max_attempts, :status, :created_by_user_id)'
            );
            $insert->execute([
                'package_id' => $packageId,
                'code' => $code,
                'name' => $name,
                'access_mode' => $accessMode,
                'instructions' => $instructions !== '' ? $instructions : null,
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
                'allow_retake' => $allowRetake ? 1 : 0,
                'max_attempts' => $maxAttempts,
                'status' => $status,
                'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ]);
            $scheduleId = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        return $scheduleId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException
            && (string) $exception->getCode() === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('Kode jadwal tersebut sudah digunakan. Pilih kode yang berbeda.', 0, $exception);
        }
        throw $exception;
    }
}

function exam_schedule_change_status(int $scheduleId, string $status): void
{
    if ($scheduleId < 1 || !in_array($status, ['draft', 'scheduled', 'open', 'closed', 'archived'], true)) {
        throw new DomainException('Jadwal atau status tidak valid.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            "SELECT s.id, s.package_id, s.access_mode, s.ends_at, s.allow_retake,
                    (SELECT COUNT(*) FROM schedule_participants sp WHERE sp.schedule_id = s.id AND sp.status <> 'cancelled') AS participant_count,
                    (SELECT COUNT(*) FROM exam_attempts a INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id WHERE sp.schedule_id = s.id) AS attempt_count
             FROM exam_schedules s WHERE s.id = :id LIMIT 1 FOR UPDATE"
        );
        $lock->execute(['id' => $scheduleId]);
        $schedule = $lock->fetch();
        if (!$schedule) {
            throw new DomainException('Jadwal tidak ditemukan.');
        }
        $package = $pdo->prepare('SELECT status, package_type FROM test_packages WHERE id = :id LIMIT 1 FOR UPDATE');
        $package->execute(['id' => (int) $schedule['package_id']]);
        $packageData = $package->fetch();
        $packageStatus = $packageData['status'] ?? null;
        $packageType = $packageData['package_type'] ?? null;
        if (in_array($status, ['scheduled', 'open'], true)
            && (strtotime((string) $schedule['ends_at']) ?: 0) <= time()) {
            throw new DomainException('Jadwal yang telah berakhir tidak dapat diaktifkan kembali.');
        }
        if (in_array($status, ['scheduled', 'open'], true) && $packageStatus !== 'published') {
            throw new DomainException('Terbitkan paket tes sebelum menjadwalkan atau membuka sesi.');
        }
        if (in_array($status, ['scheduled', 'open'], true)
            && $packageType === 'review_360'
            && $schedule['access_mode'] !== 'employee_sso') {
            throw new DomainException('Paket 360° harus menggunakan akses karyawan SSO sebelum jadwal diaktifkan.');
        }
        if ($status === 'open' && $packageType === 'review_360') {
            if ((int) $schedule['allow_retake'] === 1) {
                throw new DomainException('Jadwal evaluasi 360° harus menggunakan satu percobaan per reviewer.');
            }
            $employeeCount = $pdo->prepare(
                "SELECT COUNT(*) FROM schedule_participants
                 WHERE schedule_id = :schedule_id AND participant_type = 'employee' AND status <> 'cancelled'"
            );
            $employeeCount->execute(['schedule_id' => $scheduleId]);
            $candidateCount = $pdo->prepare(
                "SELECT COUNT(*) FROM schedule_participants
                 WHERE schedule_id = :schedule_id AND participant_type = 'candidate' AND status <> 'cancelled'"
            );
            $candidateCount->execute(['schedule_id' => $scheduleId]);
            $missingReviewers = $pdo->prepare(
                "SELECT COUNT(*) FROM schedule_participants sp
                 WHERE sp.schedule_id = :schedule_id AND sp.participant_type = 'employee' AND sp.status <> 'cancelled'
                   AND NOT EXISTS (
                       SELECT 1 FROM review_assignments ra
                       WHERE ra.schedule_id = sp.schedule_id AND ra.reviewer_user_id = sp.sso_user_id
                         AND ra.status <> 'cancelled'
                   )"
            );
            $missingReviewers->execute(['schedule_id' => $scheduleId]);
            $orphanAssignments = $pdo->prepare(
                "SELECT COUNT(*) FROM review_assignments ra
                 WHERE ra.schedule_id = :schedule_id AND ra.status <> 'cancelled'
                   AND NOT EXISTS (
                       SELECT 1 FROM schedule_participants sp
                       WHERE sp.schedule_id = ra.schedule_id AND sp.participant_type = 'employee'
                         AND sp.sso_user_id = ra.reviewer_user_id AND sp.status <> 'cancelled'
                   )"
            );
            $orphanAssignments->execute(['schedule_id' => $scheduleId]);
            if ((int) $employeeCount->fetchColumn() < 1
                || (int) $candidateCount->fetchColumn() > 0
                || (int) $missingReviewers->fetchColumn() > 0
                || (int) $orphanAssignments->fetchColumn() > 0) {
                throw new DomainException('Jadwal 360° baru dapat dibuka setelah minimal satu reviewer karyawan aktif memiliki alokasi subjek dan seluruh undangan reviewer telah dialokasikan.');
            }
        }
        if ($status === 'draft' && ((int) $schedule['participant_count'] > 0 || (int) $schedule['attempt_count'] > 0)) {
            throw new DomainException('Jadwal dengan peserta terundang atau sesi ujian tidak dapat dikembalikan ke draft. Tutup atau arsipkan jadwal.');
        }
        $pdo->prepare('UPDATE exam_schedules SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $scheduleId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function exam_schedule_assert_invitable(PDO $pdo, int $scheduleId, string $identityType): array
{
    $lock = $pdo->prepare(
        'SELECT id, code, status, access_mode, ends_at, package_id
         FROM exam_schedules WHERE id = :id LIMIT 1 FOR UPDATE'
    );
    $lock->execute(['id' => $scheduleId]);
    $schedule = $lock->fetch();
    if (!$schedule) {
        throw new DomainException('Jadwal tidak ditemukan.');
    }
    $package = $pdo->prepare('SELECT status, package_type FROM test_packages WHERE id = :id LIMIT 1 FOR UPDATE');
    $package->execute(['id' => (int) $schedule['package_id']]);
    $packageData = $package->fetch();
    if (!$packageData) {
        throw new DomainException('Paket tes untuk jadwal ini tidak ditemukan.');
    }
    $schedule['package_status'] = $packageData['status'];
    $schedule['package_type'] = $packageData['package_type'];
    if (!in_array($schedule['status'], ['scheduled', 'open'], true)
        || (strtotime((string) $schedule['ends_at']) ?: 0) < time()) {
        throw new DomainException('Undangan hanya dapat ditambahkan ke jadwal terjadwal atau terbuka yang belum berakhir.');
    }
    if ($schedule['package_status'] !== 'published') {
        throw new DomainException('Paket tes belum diterbitkan.');
    }
    if ($identityType === 'employee' && !in_array($schedule['access_mode'], ['employee_sso', 'mixed'], true)) {
        throw new DomainException('Jadwal ini tidak menerima peserta karyawan SSO.');
    }
    if ($identityType === 'candidate' && !in_array($schedule['access_mode'], ['external_token', 'mixed'], true)) {
        throw new DomainException('Jadwal ini tidak menerima peserta kandidat bertoken.');
    }
    if ($schedule['package_type'] === 'review_360' && $schedule['access_mode'] !== 'employee_sso') {
        throw new DomainException('Jadwal paket evaluasi 360° harus diatur ke akses karyawan SSO saja.');
    }
    if ($schedule['package_type'] === 'review_360' && $identityType !== 'employee') {
        throw new DomainException('Paket evaluasi 360° hanya menerima karyawan SSO.');
    }

    return $schedule;
}

function exam_schedule_add_employees(int $scheduleId, mixed $rawUserIds): array
{
    if (!is_array($rawUserIds)) {
        throw new DomainException('Pilih minimal satu karyawan dari direktori SSO.');
    }
    $userIds = [];
    foreach ($rawUserIds as $rawId) {
        if (!is_scalar($rawId)) {
            continue;
        }
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if (is_int($id) && $id > 0) {
            $userIds[] = $id;
        }
    }
    $userIds = array_values(array_unique($userIds));
    if ($userIds === [] || count($userIds) > 100) {
        throw new DomainException('Pilih antara 1 sampai 100 karyawan dalam satu kali undangan.');
    }

    $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
    $directory = sso_db()->prepare(
        "SELECT id, name, email FROM users WHERE id IN ($placeholders)"
    );
    $directory->execute($userIds);
    $users = $directory->fetchAll();
    if (count($users) !== count($userIds)) {
        throw new DomainException('Satu atau beberapa akun SSO tidak ditemukan lagi. Muat ulang pencarian direktori.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $schedule = exam_schedule_assert_invitable($pdo, $scheduleId, 'employee');
        if ($schedule['package_type'] === 'review_360' && $schedule['status'] === 'open') {
            throw new DomainException('Jadwal 360° sudah dibuka. Kembalikan ke status Terjadwal sebelum menambahkan reviewer baru dan melengkapi alokasinya.');
        }
        if ($schedule['package_type'] === 'review_360') {
            $scheduleAttempt = $pdo->prepare(
                'SELECT a.id FROM exam_attempts a
                 INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
                 WHERE sp.schedule_id = :schedule_id LIMIT 1 FOR UPDATE'
            );
            $scheduleAttempt->execute(['schedule_id' => $scheduleId]);
            if ($scheduleAttempt->fetchColumn() !== false) {
                throw new DomainException('Daftar reviewer jadwal 360° terkunci setelah sesi ujian pertama dimulai.');
            }
        }
        $existingQuery = $pdo->prepare(
            'SELECT id, status FROM schedule_participants
             WHERE schedule_id = :schedule_id AND sso_user_id = :user_id LIMIT 1 FOR UPDATE'
        );
        $attemptQuery = $pdo->prepare('SELECT id FROM exam_attempts WHERE schedule_participant_id = :participant_id LIMIT 1 FOR UPDATE');
        $insert = $pdo->prepare(
            "INSERT INTO schedule_participants
                (schedule_id, participant_type, sso_user_id, status, invited_at)
             VALUES (:schedule_id, 'employee', :user_id, 'invited', :invited_at)"
        );
        $reactivate = $pdo->prepare(
            "UPDATE schedule_participants SET status = 'invited', invited_at = :invited_at
             WHERE id = :participant_id"
        );
        $addedNames = [];
        $alreadyAssigned = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $existingQuery->execute(['schedule_id' => $scheduleId, 'user_id' => $userId]);
            $existing = $existingQuery->fetch();
            if (!$existing) {
                $insert->execute(['schedule_id' => $scheduleId, 'user_id' => $userId, 'invited_at' => $now]);
                $addedNames[] = (string) $user['name'];
                continue;
            }
            if ($existing['status'] === 'cancelled') {
                $attemptQuery->execute(['participant_id' => (int) $existing['id']]);
                if ($attemptQuery->fetchColumn() === false) {
                    $reactivate->execute(['invited_at' => $now, 'participant_id' => (int) $existing['id']]);
                    $addedNames[] = (string) $user['name'];
                    continue;
                }
            }
            $alreadyAssigned++;
        }
        $pdo->commit();

        return ['added' => count($addedNames), 'already_assigned' => $alreadyAssigned, 'names' => $addedNames];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function exam_schedule_add_candidate(int $scheduleId, array $input): array
{
    $name = trim(exam_schedule_scalar($input['name'] ?? ''));
    $email = strtolower(trim(exam_schedule_scalar($input['email'] ?? '')));
    $phone = trim(exam_schedule_scalar($input['phone'] ?? ''));
    $nik = trim(exam_schedule_scalar($input['nik'] ?? ''));
    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 150) {
        throw new DomainException('Nama kandidat wajib diisi dan maksimal 150 karakter.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
        throw new DomainException('Alamat email kandidat tidak valid.');
    }
    if (strlen($phone) > 40 || strlen($nik) > 50) {
        throw new DomainException('Nomor telepon maksimal 40 karakter dan NIK maksimal 50 karakter.');
    }

    $token = strtoupper(bin2hex(random_bytes(16)));
    $tokenHash = hash('sha256', $token);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $schedule = exam_schedule_assert_invitable($pdo, $scheduleId, 'candidate');
        $candidateQuery = $pdo->prepare(
            'SELECT id FROM external_candidates WHERE LOWER(TRIM(email)) = :email ORDER BY id LIMIT 1 FOR UPDATE'
        );
        $candidateQuery->execute(['email' => $email]);
        $candidateId = $candidateQuery->fetchColumn();
        if ($candidateId === false) {
            $insertCandidate = $pdo->prepare(
                'INSERT INTO external_candidates (name, email, phone, nik) VALUES (:name, :email, :phone, :nik)'
            );
            $insertCandidate->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'nik' => $nik !== '' ? $nik : null,
            ]);
            $candidateId = (int) $pdo->lastInsertId();
        } else {
            $candidateId = (int) $candidateId;
            $pdo->prepare(
                'UPDATE external_candidates SET name = :name, email = :email, phone = :phone, nik = :nik WHERE id = :id'
            )->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'nik' => $nik !== '' ? $nik : null,
                'id' => $candidateId,
            ]);
        }

        $existingQuery = $pdo->prepare(
            'SELECT id, status FROM schedule_participants
             WHERE schedule_id = :schedule_id AND candidate_id = :candidate_id LIMIT 1 FOR UPDATE'
        );
        $existingQuery->execute(['schedule_id' => $scheduleId, 'candidate_id' => $candidateId]);
        $existing = $existingQuery->fetch();
        $expiresAt = (string) $schedule['ends_at'];
        $now = date('Y-m-d H:i:s');
        if ($existing) {
            if ($existing['status'] !== 'cancelled') {
                throw new DomainException('Kandidat ini sudah terdaftar pada jadwal tersebut.');
            }
            $attempt = $pdo->prepare('SELECT id FROM exam_attempts WHERE schedule_participant_id = :participant_id LIMIT 1 FOR UPDATE');
            $attempt->execute(['participant_id' => (int) $existing['id']]);
            if ($attempt->fetchColumn() !== false) {
                throw new DomainException('Undangan kandidat yang sudah memiliki sesi ujian tidak dapat diterbitkan ulang.');
            }
            $pdo->prepare(
                "UPDATE schedule_participants
                 SET status = 'invited', invite_token_hash = :token_hash,
                     token_expires_at = :expires_at, invited_at = :invited_at
                 WHERE id = :id"
            )->execute([
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'invited_at' => $now,
                'id' => (int) $existing['id'],
            ]);
        } else {
            $insertParticipant = $pdo->prepare(
                "INSERT INTO schedule_participants
                    (schedule_id, participant_type, candidate_id, invite_token_hash, token_expires_at, status, invited_at)
                 VALUES (:schedule_id, 'candidate', :candidate_id, :token_hash, :expires_at, 'invited', :invited_at)"
            );
            $insertParticipant->execute([
                'schedule_id' => $scheduleId,
                'candidate_id' => $candidateId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'invited_at' => $now,
            ]);
        }
        $pdo->commit();

        return [
            'name' => $name,
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt,
            'schedule_code' => (string) $schedule['code'],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function exam_schedule_360_assignments(int $scheduleId): array
{
    if ($scheduleId < 1) {
        return [];
    }
    $statement = db()->prepare(
        'SELECT id AS assignment_id, reviewer_user_id, subject_user_id, status, assigned_at, completed_at
         FROM review_assignments WHERE schedule_id = :schedule_id
         ORDER BY reviewer_user_id, subject_user_id, id'
    );
    $statement->execute(['schedule_id' => $scheduleId]);
    $rows = $statement->fetchAll();
    if ($rows === []) {
        return [];
    }

    $userIds = [];
    foreach ($rows as $row) {
        $userIds[] = (int) $row['reviewer_user_id'];
        $userIds[] = (int) $row['subject_user_id'];
    }
    $profiles = [];
    try {
        foreach (exam_schedule_employee_profiles($userIds) as $profile) {
            $profiles[(int) $profile['id']] = $profile;
        }
    } catch (Throwable $exception) {
        error_log('E-Assessment 360° assignment directory lookup failed: ' . $exception->getMessage());
    }

    foreach ($rows as &$row) {
        $reviewerId = (int) $row['reviewer_user_id'];
        $subjectId = (int) $row['subject_user_id'];
        $reviewer = $profiles[$reviewerId] ?? [];
        $subject = $profiles[$subjectId] ?? [];
        $row['reviewer_name'] = $reviewer['name'] ?? ('Karyawan #' . $reviewerId);
        $row['reviewer_division'] = $reviewer['division'] ?? '';
        $row['reviewer_department'] = $reviewer['department'] ?? '';
        $row['subject_name'] = $subject['name'] ?? ('Karyawan #' . $subjectId);
        $row['subject_division'] = $subject['division'] ?? '';
        $row['subject_department'] = $subject['department'] ?? '';
        $row['assignment_id'] = (int) $row['assignment_id'];
    }
    unset($row);

    return $rows;
}

function exam_schedule_360_add_assignments(int $scheduleId, mixed $rawReviewerId, mixed $rawSubjectIds): array
{
    $reviewerId = is_scalar($rawReviewerId) ? filter_var($rawReviewerId, FILTER_VALIDATE_INT) : false;
    if (!is_int($reviewerId) || $reviewerId < 1 || !is_array($rawSubjectIds)) {
        throw new DomainException('Pilih reviewer dan minimal satu subjek dari direktori SSO.');
    }
    $subjectIds = [];
    foreach ($rawSubjectIds as $rawSubjectId) {
        if (!is_scalar($rawSubjectId)) {
            continue;
        }
        $subjectId = filter_var($rawSubjectId, FILTER_VALIDATE_INT);
        if (is_int($subjectId) && $subjectId > 0) {
            $subjectIds[] = $subjectId;
        }
    }
    $subjectIds = array_values(array_unique($subjectIds));
    if ($subjectIds === [] || count($subjectIds) > 100) {
        throw new DomainException('Pilih antara 1 sampai 100 subjek dalam satu kali alokasi.');
    }
    if (in_array($reviewerId, $subjectIds, true)) {
        throw new DomainException('Reviewer tidak dapat menilai dirinya sendiri. Hapus reviewer dari daftar subjek.');
    }

    $directoryIds = array_values(array_unique(array_merge([$reviewerId], $subjectIds)));
    $profiles = exam_schedule_employee_profiles($directoryIds);
    if (count($profiles) !== count($directoryIds)) {
        throw new DomainException('Reviewer atau subjek tidak ditemukan lagi di direktori SSO. Muat ulang peserta dan pencarian.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $schedule = exam_schedule_assert_invitable($pdo, $scheduleId, 'employee');
        if ($schedule['package_type'] !== 'review_360') {
            throw new DomainException('Alokasi reviewer–subjek hanya tersedia untuk paket evaluasi 360°.');
        }
        if ($schedule['status'] !== 'scheduled') {
            throw new DomainException('Alokasi 360° hanya dapat diubah saat jadwal berstatus Terjadwal. Kembalikan jadwal yang belum berjalan ke status Terjadwal terlebih dahulu.');
        }
        $attempt = $pdo->prepare(
            'SELECT a.id FROM exam_attempts a
             INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
             WHERE sp.schedule_id = :schedule_id LIMIT 1 FOR UPDATE'
        );
        $attempt->execute(['schedule_id' => $scheduleId]);
        if ($attempt->fetchColumn() !== false) {
            throw new DomainException('Alokasi 360° terkunci setelah sesi ujian pertama dimulai.');
        }
        $reviewer = $pdo->prepare(
            "SELECT id FROM schedule_participants
             WHERE schedule_id = :schedule_id AND participant_type = 'employee'
               AND sso_user_id = :reviewer_id AND status <> 'cancelled'
             LIMIT 1 FOR UPDATE"
        );
        $reviewer->execute(['schedule_id' => $scheduleId, 'reviewer_id' => $reviewerId]);
        if ($reviewer->fetchColumn() === false) {
            throw new DomainException('Reviewer harus merupakan karyawan aktif yang sudah diundang pada jadwal ini.');
        }

        $find = $pdo->prepare(
            'SELECT id, status FROM review_assignments
             WHERE schedule_id = :schedule_id AND reviewer_user_id = :reviewer_id AND subject_user_id = :subject_id
             LIMIT 1 FOR UPDATE'
        );
        $insert = $pdo->prepare(
            "INSERT INTO review_assignments (schedule_id, reviewer_user_id, subject_user_id, status, assigned_at)
             VALUES (:schedule_id, :reviewer_id, :subject_id, 'assigned', :assigned_at)"
        );
        $reactivate = $pdo->prepare(
            "UPDATE review_assignments SET status = 'assigned', assigned_at = :assigned_at, completed_at = NULL
             WHERE id = :id"
        );
        $added = 0;
        $alreadyAssigned = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($subjectIds as $subjectId) {
            $find->execute(['schedule_id' => $scheduleId, 'reviewer_id' => $reviewerId, 'subject_id' => $subjectId]);
            $existing = $find->fetch();
            if (!$existing) {
                $insert->execute([
                    'schedule_id' => $scheduleId,
                    'reviewer_id' => $reviewerId,
                    'subject_id' => $subjectId,
                    'assigned_at' => $now,
                ]);
                $added++;
            } elseif ($existing['status'] === 'cancelled') {
                $reactivate->execute(['assigned_at' => $now, 'id' => (int) $existing['id']]);
                $added++;
            } else {
                $alreadyAssigned++;
            }
        }
        $pdo->commit();

        return ['added' => $added, 'already_assigned' => $alreadyAssigned];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function exam_schedule_360_cancel_assignment(int $scheduleId, int $assignmentId): void
{
    if ($scheduleId < 1 || $assignmentId < 1) {
        throw new DomainException('Jadwal atau alokasi 360° tidak valid.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $schedule = exam_schedule_assert_invitable($pdo, $scheduleId, 'employee');
        if ($schedule['package_type'] !== 'review_360') {
            throw new DomainException('Alokasi reviewer–subjek hanya tersedia untuk paket evaluasi 360°.');
        }
        if ($schedule['status'] !== 'scheduled') {
            throw new DomainException('Alokasi hanya dapat dibatalkan saat jadwal berstatus Terjadwal.');
        }
        $attempt = $pdo->prepare(
            'SELECT a.id FROM exam_attempts a
             INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
             WHERE sp.schedule_id = :schedule_id LIMIT 1 FOR UPDATE'
        );
        $attempt->execute(['schedule_id' => $scheduleId]);
        if ($attempt->fetchColumn() !== false) {
            throw new DomainException('Alokasi 360° terkunci setelah sesi ujian pertama dimulai.');
        }
        $assignment = $pdo->prepare(
            'SELECT id, status FROM review_assignments
             WHERE id = :assignment_id AND schedule_id = :schedule_id LIMIT 1 FOR UPDATE'
        );
        $assignment->execute(['assignment_id' => $assignmentId, 'schedule_id' => $scheduleId]);
        $row = $assignment->fetch();
        if (!$row) {
            throw new DomainException('Alokasi reviewer–subjek tidak ditemukan pada jadwal ini.');
        }
        if ($row['status'] !== 'assigned') {
            throw new DomainException('Alokasi yang sudah dimulai atau dikirim tidak dapat dibatalkan.');
        }
        $pdo->prepare("UPDATE review_assignments SET status = 'cancelled' WHERE id = :id")
            ->execute(['id' => $assignmentId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function exam_schedule_cancel_participant(int $scheduleId, int $participantId): void
{
    if ($scheduleId < 1 || $participantId < 1) {
        throw new DomainException('Jadwal atau peserta tidak valid.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $scheduleQuery = $pdo->prepare('SELECT id, package_id, status FROM exam_schedules WHERE id = :id LIMIT 1 FOR UPDATE');
        $scheduleQuery->execute(['id' => $scheduleId]);
        $schedule = $scheduleQuery->fetch();
        if (!$schedule) {
            throw new DomainException('Jadwal tidak ditemukan.');
        }
        $package = $pdo->prepare('SELECT package_type FROM test_packages WHERE id = :id LIMIT 1 FOR UPDATE');
        $package->execute(['id' => (int) $schedule['package_id']]);
        $packageType = $package->fetchColumn();
        $participant = $pdo->prepare(
            'SELECT id, participant_type, sso_user_id FROM schedule_participants
             WHERE id = :participant_id AND schedule_id = :schedule_id LIMIT 1 FOR UPDATE'
        );
        $participant->execute(['participant_id' => $participantId, 'schedule_id' => $scheduleId]);
        $participantRow = $participant->fetch();
        if (!$participantRow) {
            throw new DomainException('Peserta tidak ditemukan pada jadwal ini.');
        }
        $attempt = $pdo->prepare('SELECT id FROM exam_attempts WHERE schedule_participant_id = :participant_id LIMIT 1 FOR UPDATE');
        $attempt->execute(['participant_id' => $participantId]);
        if ($attempt->fetchColumn() !== false) {
            throw new DomainException('Peserta yang sudah memulai ujian tidak dapat dibatalkan dari daftar undangan.');
        }
        if ($packageType === 'review_360') {
            if ($schedule['status'] === 'open') {
                throw new DomainException('Jadwal 360° harus dikembalikan ke Terjadwal sebelum undangan reviewer diubah.');
            }
            $scheduleAttempt = $pdo->prepare(
                'SELECT a.id FROM exam_attempts a
                 INNER JOIN schedule_participants sp ON sp.id = a.schedule_participant_id
                 WHERE sp.schedule_id = :schedule_id LIMIT 1 FOR UPDATE'
            );
            $scheduleAttempt->execute(['schedule_id' => $scheduleId]);
            if ($scheduleAttempt->fetchColumn() !== false) {
                throw new DomainException('Daftar reviewer jadwal 360° terkunci setelah sesi ujian pertama dimulai.');
            }
            if ($participantRow['participant_type'] === 'employee') {
                $reviewAssignments = $pdo->prepare(
                    "SELECT id, status FROM review_assignments
                     WHERE schedule_id = :schedule_id AND reviewer_user_id = :reviewer_id AND status <> 'cancelled'
                     FOR UPDATE"
                );
                $reviewAssignments->execute([
                    'schedule_id' => $scheduleId,
                    'reviewer_id' => (int) $participantRow['sso_user_id'],
                ]);
                foreach ($reviewAssignments->fetchAll() as $assignment) {
                    if ($assignment['status'] !== 'assigned') {
                        throw new DomainException('Reviewer memiliki alokasi yang sudah dimulai. Batalkan sesi terlebih dahulu sebelum mengubah undangan.');
                    }
                }
                $pdo->prepare(
                    "UPDATE review_assignments SET status = 'cancelled'
                     WHERE schedule_id = :schedule_id AND reviewer_user_id = :reviewer_id AND status = 'assigned'"
                )->execute([
                    'schedule_id' => $scheduleId,
                    'reviewer_id' => (int) $participantRow['sso_user_id'],
                ]);
            }
        }
        $pdo->prepare(
            "UPDATE schedule_participants
             SET status = 'cancelled', invite_token_hash = NULL, token_expires_at = NULL
             WHERE id = :participant_id"
        )->execute(['participant_id' => $participantId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
