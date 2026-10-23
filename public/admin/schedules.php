<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/schedule_service.php';
app_require_hr();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'search_users') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    try {
        $users = exam_schedule_search_users(exam_schedule_scalar($_GET['q'] ?? ''), 30);
        echo json_encode(['users' => $users], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $exception) {
        error_log('E-Assessment schedule directory search failed: ' . $exception->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'Direktori SSO belum dapat diakses.'], JSON_THROW_ON_ERROR);
    }
    exit;
}

$wasPosted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$flash = app_flash('schedule');
$formError = null;
$databaseError = null;
$notice = null;
$editId = 0;
$defaultStart = (new DateTimeImmutable('tomorrow 09:00'))->format('Y-m-d\TH:i');
$defaultEnd = (new DateTimeImmutable('tomorrow 11:00'))->format('Y-m-d\TH:i');
$formSchedule = [
    'id' => 0,
    'package_id' => '',
    'code' => 'EA-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
    'name' => '',
    'access_mode' => 'employee_sso',
    'instructions' => '',
    'starts_at' => $defaultStart,
    'ends_at' => $defaultEnd,
    'allow_retake' => '',
    'max_attempts' => '1',
    'status' => 'draft',
];
$selectedEmployeeIds = [];
$selected360ReviewerId = 0;
$selected360SubjectIds = [];
$oneTimeInvites = [];
if (isset($_SESSION['_eassessment_one_time_candidate_tokens']) && is_array($_SESSION['_eassessment_one_time_candidate_tokens'])) {
    $pendingTokens = $_SESSION['_eassessment_one_time_candidate_tokens'];
    unset($_SESSION['_eassessment_one_time_candidate_tokens']);
    $createdAt = (int) ($pendingTokens['created_at'] ?? 0);
    if ($createdAt > 0 && $createdAt >= time() - 300 && is_array($pendingTokens['items'] ?? null)) {
        $oneTimeInvites = $pendingTokens['items'];
    }
}

function schedule_page_status_label(string $status): string
{
    return match ($status) {
        'scheduled' => 'Terjadwal',
        'open' => 'Dibuka',
        'closed' => 'Ditutup',
        'archived' => 'Arsip',
        default => 'Draft',
    };
}

function schedule_page_status_class(string $status): string
{
    return match ($status) {
        'open' => 'is-open',
        'scheduled' => 'is-scheduled',
        'closed' => 'is-closed',
        'archived' => 'is-archived',
        default => 'is-draft',
    };
}

function schedule_page_360_assignment_status(string $status): string
{
    return match ($status) {
        'in_progress' => 'Berlangsung',
        'submitted' => 'Dikirim',
        'pending_review' => 'Menunggu review',
        'reviewed' => 'Ditinjau',
        'cancelled' => 'Dibatalkan',
        default => 'Ditugaskan',
    };
}

function schedule_page_access_label(string $mode): string
{
    return match ($mode) {
        'external_token' => 'Kandidat bertoken',
        'mixed' => 'SSO + kandidat',
        default => 'Karyawan SSO',
    };
}

function schedule_page_date(?string $value, bool $withTime = true): string
{
    if (!$value) {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable) {
        return '—';
    }
    $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $result = $date->format('d') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
    return $withTime ? $result . ' · ' . $date->format('H:i') . ' WIB' : $result;
}

function schedule_page_employee_unit(array $profile): string
{
    $parts = [];
    $division = trim((string) ($profile['participant_division'] ?? $profile['division'] ?? ''));
    $department = trim((string) ($profile['participant_department'] ?? $profile['department'] ?? ''));
    if ($division !== '') {
        $parts[] = 'Bidang ' . $division; // users.division → nama_bidang
    }
    if ($department !== '') {
        $parts[] = 'Divisi ' . $department; // users.department → nama_divisi
    }

    return implode(' · ', $parts);
}

function schedule_page_datetime_local(?string $value): string
{
    if (!$value) {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
}

function schedule_page_json(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
}

if ($wasPosted) {
    $csrf = $_POST['_csrf_token'] ?? null;
    if (!is_string($csrf) || !app_verify_csrf($csrf)) {
        http_response_code(419);
        $formError = 'Sesi formulir kedaluwarsa. Muat ulang halaman sebelum mencoba kembali.';
    } else {
        $action = exam_schedule_scalar($_POST['action'] ?? '');
        $parsedEditId = filter_var($_POST['schedule_id'] ?? ($_POST['id'] ?? null), FILTER_VALIDATE_INT);
        $editId = is_int($parsedEditId) ? max(0, $parsedEditId) : 0;
        try {
            if ($action === 'save_schedule') {
                foreach (array_keys($formSchedule) as $field) {
                    if ($field === 'allow_retake') {
                        continue;
                    }
                    $formSchedule[$field] = exam_schedule_scalar($_POST[$field] ?? $formSchedule[$field]);
                }
                $formSchedule['id'] = max(0, (int) $formSchedule['id']);
                $formSchedule['allow_retake'] = isset($_POST['allow_retake']) ? '1' : '';
                $savedId = exam_schedule_save($_POST, (int) (app_user()['id'] ?? 0));
                app_flash('schedule', $formSchedule['id'] > 0 ? 'Perubahan jadwal berhasil disimpan.' : 'Jadwal baru berhasil dibuat. Jadwal masih berstatus draft sampai diterbitkan.');
                app_redirect('schedules.php?edit=' . $savedId . '#schedule-editor');
            }
            if ($action === 'change_status') {
                $status = exam_schedule_scalar($_POST['status'] ?? '');
                exam_schedule_change_status($editId, $status);
                app_flash('schedule', 'Status jadwal diperbarui menjadi ' . schedule_page_status_label($status) . '.');
                app_redirect('schedules.php?edit=' . $editId . '#schedule-detail');
            }
            if ($action === 'add_employees') {
                $result = exam_schedule_add_employees($editId, $_POST['employee_ids'] ?? null);
                $message = $result['added'] . ' karyawan berhasil ditambahkan.';
                if ($result['already_assigned'] > 0) {
                    $message .= ' ' . $result['already_assigned'] . ' sudah memiliki undangan aktif atau riwayat ujian.';
                }
                app_flash('schedule', $message);
                app_redirect('schedules.php?edit=' . $editId . '#participants');
            }
            if ($action === 'add_360_assignments') {
                $result = exam_schedule_360_add_assignments(
                    $editId,
                    $_POST['reviewer_user_id'] ?? null,
                    $_POST['subject_user_ids'] ?? null
                );
                $message = $result['added'] . ' alokasi reviewer–subjek berhasil dibuat.';
                if ($result['already_assigned'] > 0) {
                    $message .= ' ' . $result['already_assigned'] . ' alokasi sudah ada.';
                }
                app_flash('schedule', $message);
                app_redirect('schedules.php?edit=' . $editId . '#review360');
            }
            if ($action === 'cancel_360_assignment') {
                $assignmentValue = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT);
                exam_schedule_360_cancel_assignment($editId, is_int($assignmentValue) ? $assignmentValue : 0);
                app_flash('schedule', 'Alokasi reviewer–subjek berhasil dibatalkan.');
                app_redirect('schedules.php?edit=' . $editId . '#review360');
            }
            if ($action === 'add_candidate') {
                $invitation = exam_schedule_add_candidate($editId, $_POST);
                $_SESSION['_eassessment_one_time_candidate_tokens'] = ['created_at' => time(), 'items' => [$invitation]];
                app_flash('schedule', 'Undangan kandidat berhasil dibuat. Salin token yang ditampilkan sekarang; token tidak dapat dipulihkan dari database.');
                app_redirect('schedules.php?edit=' . $editId . '#participants');
            }
            if ($action === 'cancel_participant') {
                $participantValue = filter_var($_POST['participant_id'] ?? null, FILTER_VALIDATE_INT);
                exam_schedule_cancel_participant($editId, is_int($participantValue) ? $participantValue : 0);
                app_flash('schedule', 'Undangan peserta berhasil dibatalkan.');
                app_redirect('schedules.php?edit=' . $editId . '#participants');
            }
            throw new DomainException('Aksi jadwal tidak dikenal.');
        } catch (DomainException $exception) {
            $formError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('E-Assessment schedule action failed: ' . $exception->getMessage());
            $formError = 'Perubahan jadwal belum dapat disimpan. Periksa data atau koneksi database.';
        }
        if ($action === 'save_schedule') {
            foreach (array_keys($formSchedule) as $field) {
                if ($field === 'allow_retake') {
                    $formSchedule[$field] = isset($_POST['allow_retake']) ? '1' : '';
                } else {
                    $formSchedule[$field] = exam_schedule_scalar($_POST[$field] ?? $formSchedule[$field]);
                }
            }
            $formSchedule['id'] = max(0, (int) $formSchedule['id']);
            $editId = (int) $formSchedule['id'];
        }
        if ($action === 'add_employees' && is_array($_POST['employee_ids'] ?? null)) {
            $selectedEmployeeIds = $_POST['employee_ids'];
        }
        if ($action === 'add_360_assignments') {
            $selectedReviewer = filter_var($_POST['reviewer_user_id'] ?? null, FILTER_VALIDATE_INT);
            $selected360ReviewerId = is_int($selectedReviewer) ? max(0, $selectedReviewer) : 0;
            if (is_array($_POST['subject_user_ids'] ?? null)) {
                $selected360SubjectIds = $_POST['subject_user_ids'];
            }
        }
    }
} elseif (isset($_GET['edit'])) {
    $parsedEditId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    $editId = is_int($parsedEditId) ? max(0, $parsedEditId) : 0;
}

$schedules = [];
$packages = [];
$selectedSchedule = null;
$participants = [];
$review360Assignments = [];
$selectedEmployeeProfiles = [];
$selected360SubjectProfiles = [];
try {
    $schedules = exam_schedule_list();
    $packages = exam_schedule_package_catalog();
    if ($editId > 0) {
        $selectedSchedule = exam_schedule_get($editId);
        if ($selectedSchedule === null) {
            $formError ??= 'Jadwal yang diminta tidak ditemukan.';
            $editId = 0;
        } else {
            $selectedSchedule['attempt_count'] = (int) $selectedSchedule['attempt_count'];
            if (!$wasPosted || ($_POST['action'] ?? '') !== 'save_schedule') {
                $formSchedule = array_merge($formSchedule, $selectedSchedule);
                $formSchedule['starts_at'] = schedule_page_datetime_local($selectedSchedule['starts_at']);
                $formSchedule['ends_at'] = schedule_page_datetime_local($selectedSchedule['ends_at']);
                $formSchedule['allow_retake'] = (int) $selectedSchedule['allow_retake'] === 1 ? '1' : '';
                $formSchedule['max_attempts'] = (string) $selectedSchedule['max_attempts'];
            }
            $participants = exam_schedule_participants($editId);
            if ($selectedSchedule['package_type'] === 'review_360') {
                $review360Assignments = exam_schedule_360_assignments($editId);
            }
        }
    }
    if ($selectedEmployeeIds !== []) {
        $selectedEmployeeProfiles = exam_schedule_employee_profiles($selectedEmployeeIds);
    }
    if ($selected360SubjectIds !== []) {
        $selected360SubjectProfiles = exam_schedule_employee_profiles($selected360SubjectIds);
    }
} catch (Throwable $exception) {
    error_log('E-Assessment schedule page load failed: ' . $exception->getMessage());
    $databaseError = 'Data jadwal atau direktori belum dapat dimuat. Pastikan database aplikasi dan koneksi SSO tersedia.';
}

$accessMode = (string) ($selectedSchedule['access_mode'] ?? $formSchedule['access_mode'] ?? 'employee_sso');
$isScheduleLocked = $selectedSchedule !== null
    && ((int) ($selectedSchedule['participant_count'] ?? 0) > 0 || (int) ($selectedSchedule['attempt_count'] ?? 0) > 0);
$isReview360Schedule = $selectedSchedule !== null && $selectedSchedule['package_type'] === 'review_360';
$activeParticipants = array_filter($participants, static fn (array $participant): bool => $participant['status'] !== 'cancelled');
$activeReviewers = array_values(array_filter(
    $activeParticipants,
    static fn (array $participant): bool => $participant['participant_type'] === 'employee'
));
$activeReviewerIds = array_map(static fn (array $participant): int => (int) $participant['sso_user_id'], $activeReviewers);
$review360CountsByReviewer = [];
foreach ($review360Assignments as $assignment) {
    if ($assignment['status'] !== 'cancelled') {
        $review360CountsByReviewer[(int) $assignment['reviewer_user_id']] = ($review360CountsByReviewer[(int) $assignment['reviewer_user_id']] ?? 0) + 1;
    }
}
$review360MissingReviewerCount = count(array_filter(
    $activeReviewers,
    static fn (array $participant): bool => empty($review360CountsByReviewer[(int) $participant['sso_user_id']])
));
$review360CandidateCount = count(array_filter(
    $activeParticipants,
    static fn (array $participant): bool => $participant['participant_type'] === 'candidate'
));
$review360OrphanAssignmentCount = count(array_filter(
    $review360Assignments,
    static fn (array $assignment): bool => $assignment['status'] !== 'cancelled'
        && !in_array((int) $assignment['reviewer_user_id'], $activeReviewerIds, true)
));
if ($selected360ReviewerId < 1 && $activeReviewers !== []) {
    $selected360ReviewerId = (int) $activeReviewers[0]['sso_user_id'];
}
$totalParticipants = count($activeParticipants);
$openSchedules = count(array_filter($schedules, static fn (array $schedule): bool => in_array($schedule['status'], ['scheduled', 'open'], true)));
$totalInvited = array_sum(array_map(static fn (array $schedule): int => (int) $schedule['participant_count'], $schedules));
$candidateLoginUrl = rtrim((string) app_config()['app']['url'], '/') . '/lobby.php?mode=candidate';
$user = app_user();
$avatarUrl = app_avatar_url();
$displayName = (string) ($user['name'] ?? 'Pengguna');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7fb">
    <title>Jadwal & Undangan · E-Assessment LJU</title>
    <link rel="stylesheet" href="../assets/assessment-builder.css?v=1">
    <link rel="stylesheet" href="../assets/schedules.css?v=1">
    <link rel="stylesheet" href="../assets/portal-ui.css?v=1">
    <script src="../assets/schedules.js?v=1" defer></script>
    <script src="../assets/portal-ui.js?v=1" defer></script>
</head>
<body class="assessment-builder-page schedule-page has-portal-sidebar">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="sch-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/></symbol>
    <symbol id="sch-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8a3 3 0 0 1-2 2.83M20 21v-2a4 4 0 0 0-3-3.87"/></symbol>
    <symbol id="sch-key" viewBox="0 0 24 24"><circle cx="8" cy="15" r="5"/><path d="m11.5 11.5 9-9 2 2-2 2 1 1-3 3-1-1-2 2"/></symbol>
    <symbol id="sch-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="sch-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="sch-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></symbol>
    <symbol id="sch-copy" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></symbol>
    <symbol id="sch-arrow" viewBox="0 0 24 24"><path d="M7 17 17 7M7 7h10v10"/></symbol>
    <symbol id="sch-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
    <symbol id="sch-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
</svg>
<?php $portalNavBase = '../'; $portalNavActive = 'schedules'; require dirname(__DIR__, 2) . '/app/portal_sidebar.php'; ?>
<header class="ab-topbar">
    <a class="ab-brand" href="../index.php" aria-label="Kembali ke dashboard HR">
        <span class="ab-brand-mark"><img src="../assets/logo-lju.svg" alt="" onerror="this.parentElement.classList.add('logo-failed')"><b class="ab-brand-fallback">LJU</b></span>
        <span><strong>PT LAMPUNG JASA UTAMA</strong><small>E-Assessment · Panel HR</small></span>
    </a>
    <nav class="ab-top-nav" aria-label="Navigasi utama">
        <a href="../index.php">Dashboard</a>
        <a href="question_bank.php">Bank Soal & Paket</a>
        <span>Jadwal & Undangan</span>
        <a href="reviews.php">Portal Penilai HR</a>
        <a href="leaderboard.php">Leaderboard</a>
        <a href="../reports.php">Laporan &amp; Hasil</a>
        <a href="../lobby.php">Lobby Ujian</a>
    </nav>
    <div class="ab-user">
        <span class="ab-avatar"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="" onerror="this.remove()"><?php else: ?><b><?= e(app_user_initials($displayName)) ?></b><?php endif; ?></span>
        <span><strong><?= e($displayName) ?></strong><small>HR / Administrator</small></span>
    </div>
</header>
<main class="ab-main">
    <div class="ab-breadcrumb"><a href="../index.php">Dashboard</a><span>›</span><strong>Jadwal & Undangan</strong></div>
    <section class="ab-hero">
        <span class="ab-hero-icon"><svg class="icon"><use href="#sch-calendar"></use></svg></span>
        <div class="ab-hero-copy"><span class="ab-eyebrow">PELAKSANAAN ASESMEN</span><h1>Jadwal & Undangan</h1><p>Atur periode tes dari paket terbit, pilih peserta karyawan melalui direktori SSO, atau buat undangan kandidat bertoken.</p></div>
        <span class="ab-hero-orbit" aria-hidden="true"></span>
        <a class="ab-primary-button sch-hero-button" href="schedules.php#schedule-editor"><svg class="icon"><use href="#sch-plus"></use></svg> Jadwal Baru</a>
    </section>
    <div class="ab-tabs">
        <a class="is-active" href="schedules.php"><svg class="icon"><use href="#sch-calendar"></use></svg>Jadwal</a>
        <a href="assessment_builder.php?view=packages"><svg class="icon"><use href="#sch-users"></use></svg>Bank Soal & Paket</a>
        <a href="../lobby.php"><svg class="icon"><use href="#sch-key"></use></svg>Lobby Peserta</a>
    </div>
    <?php if ($flash): ?><div class="ab-alert ab-alert-success"><svg class="icon"><use href="#sch-check"></use></svg><span><?= e($flash) ?></span></div><?php endif; ?>
    <?php if ($formError): ?><div class="ab-alert ab-alert-error"><svg class="icon"><use href="#sch-info"></use></svg><span><?= e($formError) ?></span></div><?php endif; ?>
    <?php if ($databaseError): ?><div class="ab-alert ab-alert-error"><svg class="icon"><use href="#sch-info"></use></svg><span><?= e($databaseError) ?></span></div><?php endif; ?>

    <?php if ($oneTimeInvites !== []): ?>
        <section class="sch-token-panel" aria-labelledby="token-heading">
            <div class="sch-token-heading"><span class="sch-token-icon"><svg class="icon"><use href="#sch-key"></use></svg></span><div><span class="ab-kicker">TOKEN DITAMPILKAN SEKALI</span><h2 id="token-heading">Salin detail undangan kandidat</h2><p>Token bearer hanya ditampilkan sekali selama 5 menit setelah dibuat. Bagikan melalui kanal resmi yang disetujui HR; jika hilang, batalkan undangan yang belum dipakai lalu buat ulang.</p></div></div>
            <?php foreach ($oneTimeInvites as $invitation): ?>
                <?php if (!is_array($invitation) || empty($invitation['token'])) { continue; } ?>
                <div class="sch-token-card">
                    <div class="sch-token-meta"><strong><?= e($invitation['name'] ?? 'Kandidat') ?></strong><span><?= e($invitation['email'] ?? '') ?> · Jadwal <?= e($invitation['schedule_code'] ?? '') ?></span></div>
                    <div class="sch-token-value"><code><?= e($invitation['token']) ?></code><button type="button" class="sch-copy-button" data-copy-value="<?= e($invitation['token']) ?>"><svg class="icon"><use href="#sch-copy"></use></svg><span>Salin token</span></button></div>
                    <div class="sch-token-link"><span>Tautan lobby kandidat</span><a href="<?= e($candidateLoginUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($candidateLoginUrl) ?></a><button type="button" class="sch-copy-button sch-copy-link" data-copy-value="<?= e($candidateLoginUrl) ?>">Salin tautan</button></div>
                    <small>Kedaluwarsa: <?= e(schedule_page_date((string) ($invitation['expires_at'] ?? ''))) ?>. Email dan token diperlukan untuk masuk.</small>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="ab-stat-grid sch-stat-grid" aria-label="Ringkasan jadwal">
        <article><span class="ab-stat-icon"><svg class="icon"><use href="#sch-calendar"></use></svg></span><div><small>TOTAL JADWAL</small><strong><?= number_format(count($schedules), 0, ',', '.') ?></strong></div></article>
        <article><span class="ab-stat-icon is-green"><svg class="icon"><use href="#sch-check"></use></svg></span><div><small>TERJADWAL / DIBUKA</small><strong><?= number_format($openSchedules, 0, ',', '.') ?></strong></div></article>
        <article><span class="ab-stat-icon is-muted"><svg class="icon"><use href="#sch-users"></use></svg></span><div><small>PESERTA TERUNDANG</small><strong><?= number_format($totalInvited, 0, ',', '.') ?></strong></div></article>
    </section>

    <div class="ab-columns sch-main-columns">
        <section class="ab-panel ab-list-panel" aria-labelledby="schedule-list-heading">
            <div class="ab-panel-heading"><div><span class="ab-kicker">KALENDER ASESMEN</span><h2 id="schedule-list-heading">Daftar jadwal</h2><p>Jadwal terbaru ditampilkan hingga 150 entri.</p></div><a class="ab-small-button" href="schedules.php#schedule-editor"><svg class="icon"><use href="#sch-plus"></use></svg> Buat jadwal</a></div>
            <?php if ($schedules === []): ?>
                <div class="ab-empty"><span><svg class="icon"><use href="#sch-calendar"></use></svg></span><strong>Belum ada jadwal</strong><p>Mulai dengan memilih paket tes yang sudah diterbitkan.</p><a href="#schedule-editor"><svg class="icon"><use href="#sch-plus"></use></svg> Susun jadwal pertama</a></div>
            <?php else: ?>
                <div class="ab-table-scroll"><table class="ab-table sch-schedule-table"><thead><tr><th>JADWAL / PAKET</th><th>PERIODE</th><th>AKSES</th><th>PESERTA</th><th>STATUS</th><th></th></tr></thead><tbody>
                <?php foreach ($schedules as $schedule): ?>
                    <tr class="<?= (int) $schedule['id'] === $editId ? 'is-selected' : '' ?>">
                        <td><div class="ab-item-title"><strong><?= e($schedule['name']) ?></strong><span><?= e($schedule['code']) ?> · <?= e($schedule['package_name']) ?></span></div></td>
                        <td><div class="sch-date-cell"><strong><?= e(schedule_page_date($schedule['starts_at'], false)) ?></strong><span><?= e((new DateTimeImmutable($schedule['starts_at']))->format('H:i')) ?>–<?= e((new DateTimeImmutable($schedule['ends_at']))->format('H:i')) ?> WIB</span></div></td>
                        <td><span class="sch-access-pill"><?= e(schedule_page_access_label($schedule['access_mode'])) ?></span></td>
                        <td><div class="ab-item-metrics"><b><?= number_format((int) $schedule['participant_count'], 0, ',', '.') ?> peserta</b><span><?= (int) $schedule['employee_count'] ?> karyawan · <?= (int) $schedule['candidate_count'] ?> kandidat</span></div></td>
                        <td><span class="sch-status <?= e(schedule_page_status_class($schedule['status'])) ?>"><i></i><?= e(schedule_page_status_label($schedule['status'])) ?></span></td>
                        <td><a class="ab-icon-button sch-edit-button" href="schedules.php?edit=<?= (int) $schedule['id'] ?>#schedule-editor" aria-label="Kelola <?= e($schedule['name']) ?>"><svg class="icon"><use href="#sch-arrow"></use></svg></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

        <section class="ab-panel ab-editor-panel" id="schedule-editor" aria-labelledby="schedule-editor-heading">
            <div class="ab-panel-heading"><div><span class="ab-kicker">PENGATURAN PERIODE</span><h2 id="schedule-editor-heading"><?= $editId > 0 ? 'Pengaturan jadwal' : 'Jadwal baru' ?></h2><p>Pilih paket terbit, jendela waktu, mode akses, dan kesempatan ujian.</p></div><?php if ($editId > 0): ?><a class="ab-reset-link" href="schedules.php#schedule-editor">Jadwal baru</a><?php endif; ?></div>
            <?php if ($isScheduleLocked): ?><div class="ab-lock-notice"><svg class="icon"><use href="#sch-info"></use></svg><div><strong>Pengaturan pokok terkunci</strong><p>Jadwal telah memiliki peserta terundang atau sesi ujian. Periode, paket, mode akses, dan kesempatan tidak dapat diubah. Status masih dapat dikelola terpisah.</p></div></div><?php endif; ?>
            <?php if ($packages === []): ?><div class="ab-lock-notice"><svg class="icon"><use href="#sch-info"></use></svg><div><strong>Belum ada paket tes terbit</strong><p>Terbitkan paket terlebih dahulu sebelum membuat jadwal.</p><a href="assessment_builder.php?view=packages#builder-editor">Buka editor paket →</a></div></div><?php endif; ?>
            <form method="post" action="schedules.php<?= $editId > 0 ? '?edit=' . $editId : '' ?>#schedule-editor" class="sch-form">
                <?= app_csrf_input() ?><input type="hidden" name="action" value="save_schedule"><input type="hidden" name="id" value="<?= (int) ($formSchedule['id'] ?? 0) ?>">
                <fieldset <?= ($isScheduleLocked || $packages === []) ? 'disabled' : '' ?>>
                    <div class="ab-form-grid">
                        <label class="ab-field"><span>Paket tes <small>wajib · hanya paket terbit</small></span><select name="package_id" required><option value="">Pilih paket</option><?php foreach ($packages as $package): ?><option value="<?= (int) $package['id'] ?>" data-package-type="<?= e($package['package_type']) ?>" <?= (int) ($formSchedule['package_id'] ?? 0) === (int) $package['id'] ? 'selected' : '' ?>><?= e($package['name']) ?> · <?= e($package['code']) ?> (<?= (int) $package['module_count'] ?> modul / <?= (int) $package['duration_minutes'] ?> menit)</option><?php endforeach; ?></select></label>
                        <label class="ab-field"><span>Status awal</span><select name="status"><?php foreach (['draft' => 'Draft', 'scheduled' => 'Terjadwal', 'open' => 'Dibuka', 'closed' => 'Ditutup', 'archived' => 'Arsip'] as $value => $label): ?><option value="<?= e($value) ?>" <?= ($formSchedule['status'] ?? 'draft') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    </div>
                    <div class="ab-form-grid">
                        <label class="ab-field"><span>Kode jadwal <small>unik</small></span><input type="text" name="code" maxlength="40" required value="<?= e($formSchedule['code']) ?>" placeholder="EA-2026-001"></label>
                        <label class="ab-field"><span>Nama jadwal</span><input type="text" name="name" maxlength="180" required value="<?= e($formSchedule['name']) ?>" placeholder="Asesmen rekrutmen batch 1"></label>
                    </div>
                    <div class="ab-form-grid">
                        <label class="ab-field"><span>Mulai (WIB)</span><input type="datetime-local" name="starts_at" required value="<?= e($formSchedule['starts_at']) ?>"></label>
                        <label class="ab-field"><span>Selesai (WIB)</span><input type="datetime-local" name="ends_at" required value="<?= e($formSchedule['ends_at']) ?>"></label>
                    </div>
                    <div class="ab-form-grid sch-settings-grid">
                        <label class="ab-field"><span>Mode akses</span><select name="access_mode" data-access-mode><option value="employee_sso" <?= $formSchedule['access_mode'] === 'employee_sso' ? 'selected' : '' ?>>Karyawan melalui SSO</option><option value="external_token" <?= $formSchedule['access_mode'] === 'external_token' ? 'selected' : '' ?>>Kandidat melalui token undangan</option><option value="mixed" <?= $formSchedule['access_mode'] === 'mixed' ? 'selected' : '' ?>>Karyawan SSO + kandidat bertoken</option></select></label>
                        <label class="ab-field"><span>Maksimum percobaan</span><input type="number" name="max_attempts" min="1" max="10" required value="<?= e($formSchedule['max_attempts']) ?>" data-max-attempts></label>
                    </div>
                    <div class="ab-check-row"><label><input type="checkbox" name="allow_retake" value="1" data-retake-toggle <?= $formSchedule['allow_retake'] !== '' ? 'checked' : '' ?>> Izinkan peserta mengulang ujian</label><span class="sch-inline-note" data-retake-note>Jika tidak dicentang, maksimum percobaan otomatis menjadi satu.</span></div>
                    <label class="ab-field"><span>Instruksi peserta <small>opsional</small></span><textarea name="instructions" maxlength="60000" rows="4" placeholder="Ketentuan pelaksanaan, dokumen yang perlu disiapkan, dan kontak bantuan."><?= e($formSchedule['instructions']) ?></textarea></label>
                </fieldset>
                <div class="ab-form-actions"><button class="ab-primary-button" type="submit" <?= ($isScheduleLocked || $packages === []) ? 'disabled' : '' ?>><svg class="icon"><use href="#sch-check"></use></svg><?= $editId > 0 ? 'Simpan perubahan' : 'Buat jadwal' ?></button><?php if ($editId > 0): ?><a class="ab-secondary-button" href="#schedule-detail">Kelola status & undangan</a><?php endif; ?></div>
                <p class="sch-form-footnote">Jadwal draft belum tampil di lobby peserta. Undangan dapat ditambahkan setelah status menjadi terjadwal atau dibuka.</p>
            </form>
            <?php if ($selectedSchedule !== null): ?>
                <div class="sch-status-control" id="schedule-detail"><div><span class="ab-kicker">KONTROL JADWAL</span><strong><?= e(schedule_page_status_label($selectedSchedule['status'])) ?></strong><small><?= (int) $selectedSchedule['attempt_count'] ?> sesi ujian tercatat</small></div><form method="post" action="schedules.php?edit=<?= (int) $editId ?>#schedule-detail"><?= app_csrf_input() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="schedule_id" value="<?= (int) $editId ?>"><select name="status" aria-label="Status jadwal baru"><?php foreach (['draft' => 'Draft', 'scheduled' => 'Terjadwal', 'open' => 'Dibuka', 'closed' => 'Ditutup', 'archived' => 'Arsip'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $selectedSchedule['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><button class="ab-secondary-button" type="submit">Ubah status</button></form></div>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($selectedSchedule !== null): ?>
        <section class="ab-panel sch-participants-panel" id="participants" aria-labelledby="participants-heading">
            <div class="ab-panel-heading"><div><span class="ab-kicker">PESERTA & AKSES</span><h2 id="participants-heading">Kelola undangan · <?= e($selectedSchedule['name']) ?></h2><p><?= e($selectedSchedule['code']) ?> · <?= e($selectedSchedule['package_name']) ?> · <?= e(schedule_page_access_label($selectedSchedule['access_mode'])) ?> · <?= e(schedule_page_date($selectedSchedule['starts_at'])) ?> – <?= e(schedule_page_date($selectedSchedule['ends_at'])) ?></p></div><span class="sch-participant-count"><svg class="icon"><use href="#sch-users"></use></svg><?= number_format($totalParticipants, 0, ',', '.') ?> peserta</span></div>
            <?php if (!in_array($selectedSchedule['status'], ['scheduled', 'open'], true)): ?><div class="ab-lock-notice"><svg class="icon"><use href="#sch-info"></use></svg><div><strong>Jadwal belum menerima undangan</strong><p>Ubah status jadwal menjadi Terjadwal atau Dibuka sebelum menambahkan peserta.</p></div></div><?php endif; ?>
            <div class="sch-invite-grid">
                <section class="sch-invite-card <?= !in_array($accessMode, ['employee_sso', 'mixed'], true) || !in_array($selectedSchedule['status'], ['scheduled', 'open'], true) || ($isReview360Schedule && ($selectedSchedule['status'] === 'open' || (int) $selectedSchedule['attempt_count'] > 0)) ? 'is-disabled' : '' ?>" aria-labelledby="employee-invite-heading">
                    <div class="sch-invite-card-heading"><span class="sch-invite-icon is-blue"><svg class="icon"><use href="#sch-users"></use></svg></span><div><h3 id="employee-invite-heading">Undang karyawan SSO</h3><p>Cari berdasarkan nama, email, username, atau NIK.</p></div></div>
                    <?php if (!in_array($accessMode, ['employee_sso', 'mixed'], true)): ?><div class="sch-mode-hint">Mode jadwal ini tidak menerima peserta karyawan.</div><?php elseif ($isReview360Schedule && (int) $selectedSchedule['attempt_count'] > 0): ?><div class="sch-mode-hint">Daftar reviewer terkunci setelah sesi ujian 360° pertama dimulai.</div><?php elseif ($isReview360Schedule && $selectedSchedule['status'] === 'open'): ?><div class="sch-mode-hint">Kembalikan jadwal 360° ke status Terjadwal sebelum menambahkan reviewer baru.</div><?php elseif (!in_array($selectedSchedule['status'], ['scheduled', 'open'], true)): ?><div class="sch-mode-hint">Terbitkan jadwal untuk mengaktifkan undangan.</div><?php else: ?>
                        <form method="post" action="schedules.php?edit=<?= (int) $editId ?>#participants" data-employee-invite-form>
                            <?= app_csrf_input() ?><input type="hidden" name="action" value="add_employees"><input type="hidden" name="schedule_id" value="<?= (int) $editId ?>">
                            <label class="ab-field"><span>Cari direktori SSO</span><div class="sch-search-box"><svg class="icon"><use href="#sch-search"></use></svg><input type="search" autocomplete="off" maxlength="100" placeholder="Ketik minimal 2 karakter…" data-employee-search data-search-url="schedules.php?ajax=search_users"><span data-search-state aria-live="polite"></span></div></label>
                            <div class="sch-search-results" data-search-results aria-live="polite"><p>Ketik nama, email, username, atau NIK untuk mencari karyawan.</p></div>
                            <div class="sch-selected-users" data-selected-users data-initial-selected="<?= e(schedule_page_json(array_map(static fn (array $profile): array => ['id' => (int) $profile['id'], 'name' => $profile['name'], 'email' => $profile['email'], 'position' => $profile['position'], 'division' => $profile['division'], 'department' => $profile['department']], $selectedEmployeeProfiles))) ?>">
                                <div class="sch-selected-heading"><strong>Dipilih untuk diundang</strong><span data-selected-count>0 dipilih</span></div>
                                <div data-selected-list><p class="sch-selected-empty">Belum ada karyawan dipilih.</p></div>
                            </div>
                            <button class="ab-primary-button" type="submit" data-invite-submit disabled><svg class="icon"><use href="#sch-plus"></use></svg> Tambahkan peserta</button>
                            <p class="sch-invite-footnote">Semua akun di direktori SSO dapat dipilih. Peserta masuk menggunakan sesi Portal SSO yang sudah ada.</p>
                        </form>
                    <?php endif; ?>
                </section>
                <section class="sch-invite-card <?= !in_array($accessMode, ['external_token', 'mixed'], true) || !in_array($selectedSchedule['status'], ['scheduled', 'open'], true) ? 'is-disabled' : '' ?>" aria-labelledby="candidate-invite-heading">
                    <div class="sch-invite-card-heading"><span class="sch-invite-icon is-amber"><svg class="icon"><use href="#sch-key"></use></svg></span><div><h3 id="candidate-invite-heading">Undang kandidat eksternal</h3><p>Kandidat masuk menggunakan email dan token unik.</p></div></div>
                    <?php if (!in_array($accessMode, ['external_token', 'mixed'], true)): ?><div class="sch-mode-hint">Mode jadwal ini tidak menerima kandidat eksternal.</div><?php elseif (!in_array($selectedSchedule['status'], ['scheduled', 'open'], true)): ?><div class="sch-mode-hint">Terbitkan jadwal untuk mengaktifkan undangan.</div><?php else: ?>
                        <form method="post" action="schedules.php?edit=<?= (int) $editId ?>#participants" class="sch-candidate-form">
                            <?= app_csrf_input() ?><input type="hidden" name="action" value="add_candidate"><input type="hidden" name="schedule_id" value="<?= (int) $editId ?>">
                            <label class="ab-field"><span>Nama kandidat</span><input type="text" name="name" maxlength="150" autocomplete="name" required placeholder="Nama sesuai identitas"></label>
                            <label class="ab-field"><span>Email kandidat</span><input type="email" name="email" maxlength="191" autocomplete="email" required placeholder="kandidat@email.com"></label>
                            <div class="ab-form-grid"><label class="ab-field"><span>Nomor telepon <small>opsional</small></span><input type="tel" name="phone" maxlength="40" autocomplete="tel"></label><label class="ab-field"><span>NIK <small>opsional</small></span><input type="text" name="nik" maxlength="50"></label></div>
                            <button class="ab-primary-button" type="submit"><svg class="icon"><use href="#sch-key"></use></svg> Buat undangan kandidat</button>
                            <p class="sch-invite-footnote">Token ditampilkan satu kali setelah disimpan. Belum ada mailer/outbox sender yang aktif; HR perlu mengirimkan tautan dan token melalui kanal resmi.</p>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
            <?php if ($isReview360Schedule): ?>
                <section class="sch360-panel" id="review360" aria-labelledby="review360-heading">
                    <div class="sch360-heading"><span class="sch-invite-icon is-blue"><svg class="icon"><use href="#sch-users"></use></svg></span><div><span class="ab-kicker">MATRIX EVALUASI</span><h3 id="review360-heading">Alokasi reviewer → subjek</h3><p>Setiap reviewer karyawan harus mendapat minimal satu subjek sebelum jadwal 360° dapat dibuka. Reviewer tidak boleh menilai dirinya sendiri.</p></div></div>
                    <?php if ($review360MissingReviewerCount > 0): ?><div class="sch360-readiness is-warning"><strong><?= $review360MissingReviewerCount ?> reviewer belum memiliki alokasi.</strong><span>Lengkapi alokasi sebelum membuka jadwal.</span></div><?php elseif ($review360OrphanAssignmentCount > 0): ?><div class="sch360-readiness is-warning"><strong><?= $review360OrphanAssignmentCount ?> alokasi memakai reviewer yang tidak aktif pada jadwal.</strong><span>Tinjau kembali peserta dan alokasi sebelum membuka.</span></div><?php elseif ($activeReviewers !== [] && $review360CandidateCount === 0): ?><div class="sch360-readiness is-ready"><strong>Semua reviewer aktif sudah memiliki subjek.</strong><span>Jadwal siap dibuka setelah rentang waktu dan status diverifikasi.</span></div><?php endif; ?>
                    <?php if ($review360CandidateCount > 0): ?><div class="ab-lock-notice"><svg class="icon"><use href="#sch-info"></use></svg><div><strong>Peserta kandidat tidak didukung</strong><p>Hapus undangan kandidat sebelum membuka jadwal evaluasi 360°.</p></div></div><?php endif; ?>
                    <?php if ((int) $selectedSchedule['attempt_count'] > 0): ?>
                        <div class="ab-lock-notice"><svg class="icon"><use href="#sch-info"></use></svg><div><strong>Matrix alokasi terkunci</strong><p>Alokasi tidak dapat diubah setelah sesi ujian pertama dimulai agar daftar subjek tetap konsisten untuk semua reviewer.</p></div></div>
                    <?php elseif ($selectedSchedule['status'] !== 'scheduled'): ?>
                        <div class="ab-lock-notice"><svg class="icon"><use href="#sch-info"></use></svg><div><strong>Jadwal belum dalam tahap pengaturan</strong><p>Matrix hanya dapat diubah saat jadwal berstatus Terjadwal. Jika belum ada sesi ujian, kembalikan status jadwal ke Terjadwal terlebih dahulu.</p></div></div>
                    <?php elseif ($activeReviewers === []): ?>
                        <div class="ab-lock-notice"><svg class="icon"><use href="#sch-info"></use></svg><div><strong>Undang reviewer terlebih dahulu</strong><p>Tambahkan karyawan SSO ke jadwal, lalu pilih mereka sebagai reviewer di sini.</p></div></div>
                    <?php else: ?>
                        <form method="post" action="schedules.php?edit=<?= (int) $editId ?>#review360" class="sch360-form" data-360-assignment-form>
                            <?= app_csrf_input() ?><input type="hidden" name="action" value="add_360_assignments"><input type="hidden" name="schedule_id" value="<?= (int) $editId ?>">
                            <div class="sch360-form-top">
                                <label class="ab-field"><span>Pilih reviewer <small>karyawan aktif pada jadwal</small></span><select name="reviewer_user_id" data-360-reviewer required><?php foreach ($activeReviewers as $reviewer): ?><option value="<?= (int) $reviewer['sso_user_id'] ?>" <?= (int) $reviewer['sso_user_id'] === $selected360ReviewerId ? 'selected' : '' ?>><?= e($reviewer['participant_name']) ?><?= $reviewer['participant_email'] ? ' · ' . e($reviewer['participant_email']) : '' ?></option><?php endforeach; ?></select></label>
                                <label class="ab-field"><span>Cari subjek dari direktori SSO</span><div class="sch-search-box"><svg class="icon"><use href="#sch-search"></use></svg><input type="search" autocomplete="off" maxlength="100" placeholder="Ketik nama, email, username, atau NIK…" data-360-subject-search data-search-url="schedules.php?ajax=search_users"><span data-360-search-state aria-live="polite"></span></div></label>
                            </div>
                            <div class="sch360-picker-grid">
                                <div class="sch-search-results" data-360-search-results aria-live="polite"><p>Ketik minimal 2 karakter untuk mencari subjek.</p></div>
                                <div class="sch-selected-users" data-360-selected-users data-initial-selected="<?= e(schedule_page_json(array_map(static fn (array $profile): array => ['id' => (int) $profile['id'], 'name' => $profile['name'], 'email' => $profile['email'], 'position' => $profile['position'], 'division' => $profile['division'], 'department' => $profile['department']], $selected360SubjectProfiles))) ?>">
                                    <div class="sch-selected-heading"><strong>Subjek dipilih</strong><span data-360-selected-count>0 dipilih</span></div><div data-360-selected-list><p class="sch-selected-empty">Belum ada subjek dipilih.</p></div>
                                </div>
                            </div>
                            <button class="ab-primary-button" type="submit" data-360-submit disabled><svg class="icon"><use href="#sch-plus"></use></svg> Simpan alokasi</button>
                            <p class="sch-invite-footnote">Pilih maksimal 100 subjek per penyimpanan. Subjek dapat berupa akun SSO mana pun; reviewer harus sudah diundang pada jadwal ini.</p>
                        </form>
                    <?php endif; ?>
                    <div class="sch-participant-list-heading"><div><span class="ab-kicker">DAFTAR ALOKASI</span><h3>Reviewer dan subjek yang ditugaskan</h3></div><span><?= count(array_filter($review360Assignments, static fn (array $assignment): bool => $assignment['status'] !== 'cancelled')) ?> alokasi aktif</span></div>
                    <?php if ($review360Assignments === []): ?><div class="ab-empty sch-empty"><span><svg class="icon"><use href="#sch-users"></use></svg></span><strong>Belum ada alokasi 360°</strong><p>Tambahkan satu atau beberapa subjek untuk setiap reviewer.</p></div><?php else: ?>
                        <div class="ab-table-scroll"><table class="ab-table sch360-table"><thead><tr><th>REVIEWER</th><th>SUBJEK YANG DINILAI</th><th>STATUS</th><th>DITUGASKAN</th><th></th></tr></thead><tbody>
                        <?php foreach ($review360Assignments as $assignment): ?>
                            <tr class="<?= $assignment['status'] === 'cancelled' ? 'is-cancelled' : '' ?>">
                                <td><div class="ab-item-title"><strong><?= e($assignment['reviewer_name']) ?></strong><span><?= e(schedule_page_employee_unit(['division' => $assignment['reviewer_division'], 'department' => $assignment['reviewer_department']]) ?: 'Unit tidak tersedia') ?></span></div></td>
                                <td><div class="ab-item-title"><strong><?= e($assignment['subject_name']) ?></strong><span><?= e(schedule_page_employee_unit(['division' => $assignment['subject_division'], 'department' => $assignment['subject_department']]) ?: 'Unit tidak tersedia') ?></span></div></td>
                                <td><span class="sch-status <?= $assignment['status'] === 'assigned' ? 'is-scheduled' : ($assignment['status'] === 'cancelled' ? 'is-closed' : 'is-open') ?>"><i></i><?= e(schedule_page_360_assignment_status($assignment['status'])) ?></span></td>
                                <td><span class="ab-item-metrics"><?= e(schedule_page_date($assignment['assigned_at'])) ?></span></td>
                                <td><?php if ($assignment['status'] === 'assigned' && $selectedSchedule['status'] === 'scheduled' && (int) $selectedSchedule['attempt_count'] === 0): ?><form method="post" action="schedules.php?edit=<?= (int) $editId ?>#review360" data-confirm="Batalkan alokasi reviewer–subjek ini?"><?= app_csrf_input() ?><input type="hidden" name="action" value="cancel_360_assignment"><input type="hidden" name="schedule_id" value="<?= (int) $editId ?>"><input type="hidden" name="assignment_id" value="<?= (int) $assignment['assignment_id'] ?>"><button class="sch-cancel-button" type="submit">Batalkan</button></form><?php else: ?><span class="sch-readonly-mark">—</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <div class="sch-participant-list-heading"><div><span class="ab-kicker">DAFTAR PESERTA</span><h3>Undangan pada jadwal ini</h3></div><span><?= count($participants) ?> baris · status & jumlah sesi</span></div>
            <?php if ($participants === []): ?><div class="ab-empty sch-empty"><span><svg class="icon"><use href="#sch-users"></use></svg></span><strong>Belum ada peserta</strong><p>Gunakan pencarian SSO atau form kandidat di atas.</p></div><?php else: ?>
                <div class="ab-table-scroll"><table class="ab-table sch-participants-table"><thead><tr><th>PESERTA</th><th>TIPE / UNIT</th><th>STATUS</th><th>UNDANGAN / AKSES</th><th>SESI</th><th></th></tr></thead><tbody>
                <?php foreach ($participants as $participant): ?>
                    <tr class="<?= $participant['status'] === 'cancelled' ? 'is-cancelled' : '' ?>">
                        <td><div class="ab-item-title"><strong><?= e($participant['participant_name']) ?></strong><span><?= e($participant['participant_email'] ?: 'Email tidak tersedia di direktori') ?></span></div></td>
                        <td><div class="ab-item-metrics"><b><?= $participant['participant_type'] === 'employee' ? 'Karyawan SSO' : 'Kandidat' ?></b><span><?= e($participant['participant_type'] === 'employee' ? (schedule_page_employee_unit($participant) ?: 'Unit tidak tersedia') : 'Eksternal') ?></span></div></td>
                        <td><span class="sch-status <?= $participant['status'] === 'cancelled' ? 'is-closed' : ($participant['status'] === 'completed' ? 'is-open' : 'is-scheduled') ?>"><i></i><?= e(match ($participant['status']) { 'invited' => 'Diundang', 'ready' => 'Siap', 'in_progress' => 'Berlangsung', 'submitted' => 'Dikirim', 'pending_review' => 'Menunggu review', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan', default => $participant['status'] }) ?></span></td>
                        <td><div class="ab-item-metrics"><b><?= $participant['participant_type'] === 'employee' ? 'Akses melalui SSO' : ($participant['has_token'] ? 'Token tersimpan sebagai hash' : 'Token tidak aktif') ?></b><span><?= $participant['participant_type'] === 'candidate' && $participant['token_expires_at'] ? 'Berlaku s.d. ' . schedule_page_date($participant['token_expires_at']) : 'Diundang ' . schedule_page_date($participant['invited_at']) ?></span></div></td>
                        <td><span class="sch-attempt-count"><?= (int) $participant['attempt_count'] ?> sesi</span></td>
                        <td><?php if ($participant['status'] !== 'cancelled' && (int) $participant['attempt_count'] === 0 && (!$isReview360Schedule || (int) $selectedSchedule['attempt_count'] === 0)): ?><form method="post" action="schedules.php?edit=<?= (int) $editId ?>#participants" data-confirm="Batalkan undangan peserta ini?"><?= app_csrf_input() ?><input type="hidden" name="action" value="cancel_participant"><input type="hidden" name="schedule_id" value="<?= (int) $editId ?>"><input type="hidden" name="participant_id" value="<?= (int) $participant['participant_id'] ?>"><button class="sch-cancel-button" type="submit">Batalkan</button></form><?php else: ?><span class="sch-readonly-mark">—</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <footer class="ab-footer"><span>PT Lampung Jasa Utama · E-Assessment</span><span>Zona waktu Asia/Jakarta (WIB)</span></footer>
</main>
<div class="ab-toast" role="status" aria-live="polite" data-schedule-toast></div>
</body>
</html>
