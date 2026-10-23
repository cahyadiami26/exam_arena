<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/exam_service.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Same-origin lightweight endpoint used by the device check to estimate latency.
if (isset($_GET['health'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'time' => time()], JSON_THROW_ON_ERROR);
    exit;
}

$formError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? null)) {
        http_response_code(419);
        $formError = 'Sesi formulir kedaluwarsa. Muat ulang halaman, lalu coba lagi.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'verify_candidate') {
            $recentAttempts = $_SESSION['_eassessment_candidate_login_attempts'] ?? [];
            if (!is_array($recentAttempts)) {
                $recentAttempts = [];
            }
            $recentAttempts = array_values(array_filter(
                $recentAttempts,
                static fn (mixed $timestamp): bool => is_numeric($timestamp) && (int) $timestamp > time() - 900
            ));

            if (count($recentAttempts) >= 8) {
                $formError = 'Terlalu banyak percobaan. Silakan tunggu beberapa menit sebelum mencoba kembali.';
            } else {
                try {
                    $assignment = lobby_verify_candidate_token(
                        (string) ($_POST['email'] ?? ''),
                        (string) ($_POST['access_token'] ?? '')
                    );
                    if ($assignment === null) {
                        $recentAttempts[] = time();
                        $_SESSION['_eassessment_candidate_login_attempts'] = $recentAttempts;
                        $formError = 'Email atau token tidak valid, telah kedaluwarsa, atau jadwal sudah ditutup.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['_eassessment_candidate_access'] = [
                            'participant_id' => (int) $assignment['participant_id'],
                            'candidate_id' => (int) $assignment['candidate_id'],
                            'verified_at' => time(),
                        ];
                        unset($_SESSION['_eassessment_candidate_login_attempts']);
                        app_redirect('lobby.php?mode=candidate');
                    }
                } catch (Throwable $exception) {
                    error_log('E-Assessment candidate token verification failed: ' . $exception->getMessage());
                    $formError = 'Layanan token sedang tidak tersedia. Silakan coba kembali beberapa saat lagi.';
                }
            }
            $_SESSION['_eassessment_candidate_login_attempts'] = $recentAttempts;
        } elseif ($action === 'start_exam') {
            try {
                $attemptId = exam_start_attempt(
                    (int) ($_POST['participant_id'] ?? 0),
                    (string) ($_POST['identity_type'] ?? '')
                );
                app_redirect('exam.php?id=' . $attemptId);
            } catch (DomainException $exception) {
                $formError = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('E-Assessment attempt start failed: ' . $exception->getMessage());
                $formError = 'Sesi ujian belum dapat dimulai. Silakan periksa kembali jadwal atau hubungi HR.';
            }
        } elseif ($action === 'candidate_logout') {
            unset($_SESSION['_eassessment_candidate_access']);
            app_redirect('lobby.php?mode=candidate');
        }
    }
}

$user = app_user();
$isEmployeeAuthenticated = app_is_authenticated();
$isHr = $isEmployeeAuthenticated && app_is_hr();
$employeeSchedules = [];
$candidateAssignment = null;
$loadError = null;

try {
    if ($isEmployeeAuthenticated) {
        $employeeSchedules = lobby_employee_schedules((int) $user['id']);
    }
    if (!empty($_SESSION['_eassessment_candidate_access'])) {
        $candidateAssignment = lobby_candidate_assignment_from_session();
    }
} catch (Throwable $exception) {
    error_log('E-Assessment lobby data load failed: ' . $exception->getMessage());
    $loadError = 'Data jadwal belum dapat dimuat. Silakan coba kembali atau hubungi HR.';
}

$activeTab = (string) ($_GET['mode'] ?? ($candidateAssignment ? 'candidate' : 'employee'));
if (!in_array($activeTab, ['employee', 'candidate'], true)) {
    $activeTab = 'employee';
}
if ($candidateAssignment !== null && !isset($_GET['mode'])) {
    $activeTab = 'candidate';
}
$avatarUrl = $isEmployeeAuthenticated ? app_avatar_url() : null;
$displayName = $isEmployeeAuthenticated ? (string) $user['name'] : '';

function lobby_human_date(?string $value, bool $withTime = true): string
{
    if (!$value) {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable) {
        return '—';
    }
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $output = $days[(int) $date->format('w')] . ', ' . $date->format('d') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
    return $withTime ? $output . ' · ' . $date->format('H:i') . ' WIB' : $output;
}

function lobby_module_count(array $module): int
{
    return (int) ($module['question_count'] ?? 0);
}

function lobby_render_assignment(array $assignment, string $identityType): void
{
    $now = time();
    $state = lobby_start_state($assignment, $now);
    $isContinuing = ($assignment['latest_attempt_status'] ?? '') === 'in_progress';
    $status = (string) ($assignment['schedule_status'] ?? 'scheduled');
    $startsAtTimestamp = strtotime((string) ($assignment['starts_at'] ?? '')) ?: 0;
    $endsAtTimestamp = strtotime((string) ($assignment['ends_at'] ?? '')) ?: 0;
    $displayStatus = $status;
    if ($status === 'open' && $startsAtTimestamp > $now) {
        $displayStatus = 'upcoming';
    } elseif ($status === 'open' && $endsAtTimestamp > 0 && $endsAtTimestamp < $now) {
        $displayStatus = 'ended';
    }
    $statusClass = match ($displayStatus) {
        'open' => 'is-open',
        'closed', 'ended', 'archived' => 'is-closed',
        default => 'is-scheduled',
    };
    $statusLabel = match ($displayStatus) {
        'open' => 'Jadwal dibuka',
        'upcoming' => 'Belum dimulai',
        'closed' => 'Ditutup',
        'ended' => 'Berakhir',
        'archived' => 'Diarsipkan',
        default => 'Terjadwal',
    };
    $startReason = $state['reason'];
    if ($state['reason_code'] === 'not_started') {
        $startReason = 'Jadwal dibuka oleh HR, tetapi ujian baru dimulai ' . lobby_human_date((string) $assignment['starts_at']) . '. Muat ulang Lobby setelah waktu mulai.';
    } elseif ($state['reason_code'] === 'scheduled_upcoming') {
        $startReason = 'Jadwal dimulai ' . lobby_human_date((string) $assignment['starts_at']) . ' dan masih menunggu dibuka oleh HR. Muat ulang Lobby setelah jadwal dibuka.';
    }
    $modeName = $identityType === 'candidate' ? 'Token Kandidat' : 'Akun SSO';
    ?>
    <article class="exam-card">
        <div class="exam-card-top">
            <div class="exam-card-mark"><svg class="icon"><use href="#l-icon-file"></use></svg></div>
            <div class="exam-title-wrap">
                <span class="exam-code"><?= e($assignment['schedule_code']) ?> <i>·</i> <?= e($modeName) ?></span>
                <h3><?= e($assignment['schedule_name']) ?></h3>
                <p><?= e($assignment['package_name']) ?></p>
            </div>
            <span class="schedule-status <?= e($statusClass) ?>"><i></i><?= e($statusLabel) ?></span>
        </div>
        <div class="exam-summary-grid">
            <div class="exam-summary-cell"><span class="summary-label"><svg class="icon"><use href="#l-icon-calendar"></use></svg>WAKTU PELAKSANAAN</span><strong><?= e(lobby_human_date((string) $assignment['starts_at'])) ?></strong><small>Sampai <?= e(lobby_human_date((string) $assignment['ends_at'])) ?></small></div>
            <div class="exam-summary-cell"><span class="summary-label"><svg class="icon"><use href="#l-icon-clock"></use></svg>TOTAL ASESMEN</span><strong><?= (int) $assignment['total_duration_minutes'] ?> menit</strong><small><?= (int) $assignment['total_questions'] ?> butir soal · <?= count($assignment['modules']) ?> modul</small></div>
            <div class="exam-summary-cell"><span class="summary-label"><svg class="icon"><use href="#l-icon-shield"></use></svg>STATUS PESERTA</span><strong><?= e(ucwords(str_replace('_', ' ', (string) $assignment['participant_status']))) ?></strong><small><?= (int) $assignment['attempt_count'] ?> dari <?= (int) $assignment['max_attempts'] ?> kesempatan digunakan</small></div>
        </div>

        <div class="module-list-wrap">
            <div class="module-list-heading"><strong>Rangkaian modul tes</strong><span><?= count($assignment['modules']) ?> modul</span></div>
            <?php if (empty($assignment['modules'])): ?>
                <div class="module-empty">Modul tes belum tersedia. Hubungi HR untuk informasi lebih lanjut.</div>
            <?php else: ?>
                <ol class="module-list">
                    <?php foreach ($assignment['modules'] as $index => $module): ?>
                        <li>
                            <span class="module-index"><?= (int) $index + 1 ?></span>
                            <span class="module-info"><strong><?= e($module['module_name']) ?></strong><small><?= e($module['category']) ?><?= $module['module_kind'] === 'review_360' ? ' · Penilaian rekan kerja' : '' ?></small></span>
                            <span class="module-meta"><b><?= lobby_module_count($module) ?></b> soal <i>·</i> <?= (int) $module['effective_duration'] ?> mnt</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>

        <?php if (!empty($assignment['instructions'])): ?>
            <details class="instruction-details"><summary><svg class="icon"><use href="#l-icon-book"></use></svg>Baca instruksi pelaksanaan</summary><div class="instruction-body"><?= nl2br(e($assignment['instructions'])) ?></div></details>
        <?php endif; ?>

        <div class="device-check-panel" data-device-panel data-start-available="<?= $state['available'] ? '1' : '0' ?>">
            <div class="device-check-heading">
                <div class="device-heading-icon"><svg class="icon"><use href="#l-icon-monitor"></use></svg></div>
                <div><strong>Pemeriksaan sistem &amp; perangkat</strong><p>Cek webcam dan kestabilan koneksi sebelum masuk ruang ujian.</p></div>
            </div>
            <div class="device-status-grid">
                <div class="device-status-item" data-camera-status data-state="idle"><span class="device-status-icon"><svg class="icon"><use href="#l-icon-camera"></use></svg></span><span><strong>Kamera</strong><small data-status-text>Belum diperiksa</small></span><span class="device-state-mark"></span></div>
                <div class="device-status-item" data-network-status data-state="idle"><span class="device-status-icon"><svg class="icon"><use href="#l-icon-wifi"></use></svg></span><span><strong>Koneksi</strong><small data-status-text>Belum diperiksa</small></span><span class="device-state-mark"></span></div>
            </div>
            <div class="device-actions-row">
                <button type="button" class="button button-device-check" data-run-device-check><svg class="icon"><use href="#l-icon-activity"></use></svg>Periksa Perangkat</button>
                <form class="start-exam-form" method="post" action="lobby.php?mode=<?= e($identityType === 'candidate' ? 'candidate' : 'employee') ?>">
                    <?= app_csrf_input() ?>
                    <input type="hidden" name="action" value="start_exam">
                    <input type="hidden" name="participant_id" value="<?= (int) $assignment['participant_id'] ?>">
                    <input type="hidden" name="identity_type" value="<?= e($identityType) ?>">
                    <button type="submit" class="button button-start-exam" data-start-exam disabled><?= $isContinuing ? 'Lanjutkan Ujian' : 'Mulai Ujian' ?><svg class="icon"><use href="#l-icon-arrow"></use></svg></button>
                </form>
            </div>
            <p class="start-reason" data-start-reason><?= e($startReason) ?></p>
            <p class="exam-stage-note" data-exam-stage-note hidden>Pemeriksaan berhasil. Anda dapat melanjutkan ke ruang pengerjaan ujian.</p>
        </div>
    </article>
    <?php
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7fb">
    <title>Lobby Ujian · E-Assessment LJU</title>
    <link rel="stylesheet" href="assets/app.css?v=1">
    <link rel="stylesheet" href="assets/lobby.css?v=1">
    <link rel="stylesheet" href="assets/portal-ui.css?v=1">
    <script src="assets/lobby.js?v=1" defer></script>
    <script src="assets/portal-ui.js?v=1" defer></script>
</head>
<body class="lobby-page has-portal-sidebar">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="l-icon-building" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 10h.01M15 10h.01M9 14h.01M15 14h.01M10 21v-4h4v4"/></symbol>
    <symbol id="l-icon-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></symbol>
    <symbol id="l-icon-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01"/></symbol>
    <symbol id="l-icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="l-icon-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="l-icon-book" viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></symbol>
    <symbol id="l-icon-monitor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4M8 10l2 2 5-5"/></symbol>
    <symbol id="l-icon-camera" viewBox="0 0 24 24"><path d="M14 5h-4L8 8H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-3l-2-3Z"/><circle cx="12" cy="13" r="3"/></symbol>
    <symbol id="l-icon-wifi" viewBox="0 0 24 24"><path d="M5 12.5a11 11 0 0 1 14 0M8.5 16a5.5 5.5 0 0 1 7 0M12 20h.01M2 9a16 16 0 0 1 20 0"/></symbol>
    <symbol id="l-icon-activity" viewBox="0 0 24 24"><path d="M3 12h4l3-8 4 16 3-8h4"/></symbol>
    <symbol id="l-icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
    <symbol id="l-icon-arrow-up" viewBox="0 0 24 24"><path d="M7 17 17 7M7 7h10v10"/></symbol>
    <symbol id="l-icon-key" viewBox="0 0 24 24"><circle cx="8" cy="15" r="5"/><path d="m11.5 11.5 9-9 2 2-2 2 1 1-3 3-1-1-2 2"/></symbol>
    <symbol id="l-icon-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></symbol>
    <symbol id="l-icon-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="l-icon-warning" viewBox="0 0 24 24"><path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0ZM12 9v4M12 17h.01"/></symbol>
    <symbol id="l-icon-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.6 9a2.5 2.5 0 1 1 4.1 1.9c-1 .8-1.7 1.1-1.7 2.6M12 17h.01"/></symbol>
    <symbol id="l-icon-link" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.1 0l3-3A5 5 0 0 0 13 2.9l-1.7 1.7M14 11a5 5 0 0 0-7.1 0l-3 3A5 5 0 0 0 11 21.1l1.7-1.7"/></symbol>
</svg>
<?php $portalNavBase = ''; $portalNavActive = 'lobby'; require dirname(__DIR__) . '/app/portal_sidebar.php'; ?>

<header class="lobby-topbar">
    <a class="lobby-brand" href="lobby.php" aria-label="E-Assessment LJU">
        <span class="lobby-logo-box"><img src="https://dms.lju.co.id/assets/img/logo.png" alt="Logo LJU" data-brand-logo><span class="brand-logo-fallback">LJU</span></span>
        <span><strong>E-ASSESSMENT</strong><small>PT Lampung Jasa Utama</small></span>
    </a>
    <div class="lobby-top-actions">
        <a class="lobby-home-link" href="lobby.php"><svg class="icon"><use href="#l-icon-building"></use></svg> Lobby Ujian</a>
        <?php if ($isHr): ?><a class="lobby-hr-link" href="index.php"><svg class="icon"><use href="#l-icon-arrow-up"></use></svg> Dashboard HR</a><?php endif; ?>
        <?php if ($isEmployeeAuthenticated): ?>
            <span class="lobby-profile"><span class="lobby-avatar">
                <?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="" data-avatar><?php endif; ?>
                <span class="avatar-fallback" <?= $avatarUrl ? 'hidden' : '' ?>><?= e(app_user_initials($displayName)) ?></span>
            </span><span><?= e($displayName) ?></span></span>
        <?php else: ?>
            <span class="lobby-secure"><svg class="icon"><use href="#l-icon-shield"></use></svg> Akses aman</span>
        <?php endif; ?>
    </div>
</header>

<main class="lobby-main">
    <div class="lobby-breadcrumb"><a href="lobby.php">Beranda</a><span>/</span><strong>Ruang Ujian</strong></div>
    <section class="lobby-hero">
        <div class="lobby-hero-copy">
            <span class="lobby-eyebrow"><span></span> EXAM LOBBY</span>
            <h1>Selamat datang di<br><em>Ruang Asesmen</em></h1>
            <p>Pastikan Anda menggunakan akun atau token yang benar, memahami aturan ujian, dan memeriksa perangkat sebelum memulai asesmen.</p>
            <div class="lobby-hero-meta"><span><svg class="icon"><use href="#l-icon-shield"></use></svg> Sesi terenkripsi</span><span><svg class="icon"><use href="#l-icon-clock"></use></svg> Waktu Asia/Jakarta</span></div>
        </div>
        <div class="lobby-hero-art" aria-hidden="true"><span class="lobby-orbit orbit-a"></span><span class="lobby-orbit orbit-b"></span><div class="lobby-art-card"><svg class="icon"><use href="#l-icon-file"></use></svg><span>ASSESSMENT</span><strong>Mulai dengan<br>persiapan tepat</strong><i></i></div><b class="lobby-spark">✦</b></div>
    </section>

    <?php if ($loadError): ?>
        <div class="lobby-alert" role="status"><svg class="icon"><use href="#l-icon-warning"></use></svg><span><?= e($loadError) ?></span></div>
    <?php endif; ?>

    <div class="lobby-content-grid">
        <section class="entry-panel">
            <div class="entry-panel-heading"><span class="section-kicker">AKSES PESERTA</span><h2>Masuk ke asesmen</h2><p>Pilih metode masuk sesuai status Anda.</p></div>
            <div class="access-tabs" role="tablist" aria-label="Metode masuk">
                <button class="access-tab <?= $activeTab === 'employee' ? 'is-active' : '' ?>" type="button" role="tab" aria-selected="<?= $activeTab === 'employee' ? 'true' : 'false' ?>" aria-controls="employee-panel" id="employee-tab" data-tab-target="employee"><svg class="icon"><use href="#l-icon-user"></use></svg><span>Karyawan LJU</span><small>SSO</small></button>
                <button class="access-tab <?= $activeTab === 'candidate' ? 'is-active' : '' ?>" type="button" role="tab" aria-selected="<?= $activeTab === 'candidate' ? 'true' : 'false' ?>" aria-controls="candidate-panel" id="candidate-tab" data-tab-target="candidate"><svg class="icon"><use href="#l-icon-key"></use></svg><span>Calon Karyawan</span><small>Token</small></button>
            </div>

            <div class="access-panel <?= $activeTab === 'employee' ? 'is-active' : '' ?>" id="employee-panel" role="tabpanel" aria-labelledby="employee-tab" <?= $activeTab === 'employee' ? '' : 'hidden' ?>>
                <?php if ($formError && $activeTab === 'employee'): ?><div class="form-alert" role="alert"><?= e($formError) ?></div><?php endif; ?>
                <?php if (!$isEmployeeAuthenticated): ?>
                    <div class="sso-entry-card">
                        <span class="sso-mark"><svg class="icon"><use href="#l-icon-shield"></use></svg></span>
                        <h3>Masuk dengan akun SSO LJU</h3>
                        <p>Karyawan PT Lampung Jasa Utama dapat menggunakan sesi Portal SSO yang sama. Anda akan diarahkan kembali ke Lobby Ujian setelah berhasil masuk.</p>
                        <a class="button lobby-primary-button" href="<?= e(app_sso_login_url('/lobby.php?mode=employee')) ?>"><svg class="icon"><use href="#l-icon-arrow-up"></use></svg>Lanjutkan melalui SSO</a>
                        <div class="sso-entry-foot"><svg class="icon"><use href="#l-icon-check"></use></svg> Tidak perlu membuat kata sandi baru</div>
                    </div>
                <?php else: ?>
                    <div class="employee-greeting"><div class="employee-greeting-avatar"><svg class="icon"><use href="#l-icon-user"></use></svg></div><div><span>TERAUTENTIKASI MELALUI SSO</span><h3><?= e($displayName) ?></h3><p><?= e($user['position'] ?: 'Karyawan LJU') ?><?= $user['nama_bidang'] ? ' · ' . e($user['nama_bidang']) : '' ?></p></div></div>
                    <div class="assignment-section-heading"><div><h3>Jadwal ujian Anda</h3><p>Paket yang ditugaskan melalui akun karyawan.</p></div><span class="assignment-count"><?= count($employeeSchedules) ?> jadwal</span></div>
                    <?php if (!$employeeSchedules): ?>
                        <div class="lobby-empty"><div><svg class="icon"><use href="#l-icon-calendar"></use></svg></div><strong>Belum ada jadwal yang ditugaskan</strong><p>Jika Anda seharusnya mengikuti asesmen, konfirmasikan jadwal dengan tim HR.</p></div>
                    <?php else: ?>
                        <div class="exam-list">
                            <?php foreach ($employeeSchedules as $assignment): lobby_render_assignment($assignment, 'employee'); endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="access-panel <?= $activeTab === 'candidate' ? 'is-active' : '' ?>" id="candidate-panel" role="tabpanel" aria-labelledby="candidate-tab" <?= $activeTab === 'candidate' ? '' : 'hidden' ?>>
                <?php if ($formError && $activeTab === 'candidate'): ?><div class="form-alert" role="alert"><?= e($formError) ?></div><?php endif; ?>
                <?php if ($candidateAssignment): ?>
                    <div class="candidate-session-bar"><div><span>AKSES TOKEN TERVERIFIKASI</span><strong><?= e($candidateAssignment['candidate_name']) ?></strong><small><?= e($candidateAssignment['candidate_email']) ?></small></div><form method="post" action="lobby.php?mode=candidate"><?= app_csrf_input() ?><input type="hidden" name="action" value="candidate_logout"><button class="text-button" type="submit">Keluar dari token</button></form></div>
                    <div class="exam-list candidate-exam-list"><?php lobby_render_assignment($candidateAssignment, 'candidate'); ?></div>
                <?php else: ?>
                    <div class="candidate-intro"><span class="candidate-intro-icon"><svg class="icon"><use href="#l-icon-key"></use></svg></span><div><h3>Gunakan token undangan</h3><p>Masukkan email dan token akses yang dikirimkan oleh tim rekrutmen.</p></div></div>
                    <form class="token-form" method="post" action="lobby.php?mode=candidate" autocomplete="on">
                        <?= app_csrf_input() ?><input type="hidden" name="action" value="verify_candidate">
                        <label for="candidate-email">Alamat email</label>
                        <input id="candidate-email" name="email" type="email" inputmode="email" autocomplete="email" maxlength="191" placeholder="nama@email.com" required>
                        <label for="candidate-token">Token akses</label>
                        <div class="token-input-wrap"><svg class="icon"><use href="#l-icon-key"></use></svg><input id="candidate-token" name="access_token" type="text" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="128" placeholder="Masukkan token undangan" required></div>
                        <p class="field-help">Token bersifat pribadi. Jangan bagikan kepada orang lain.</p>
                        <button class="button lobby-primary-button token-submit" type="submit"><svg class="icon"><use href="#l-icon-arrow-up"></use></svg>Verifikasi &amp; Lanjutkan</button>
                    </form>
                    <div class="token-help"><svg class="icon"><use href="#l-icon-help"></use></svg><span>Belum menerima token atau mengalami kendala? Hubungi PIC rekrutmen yang mengirimkan undangan.</span></div>
                <?php endif; ?>
            </div>
        </section>

        <aside class="lobby-side-column">
            <section class="lobby-side-card preparation-card">
                <div class="side-card-heading"><span class="side-heading-icon"><svg class="icon"><use href="#l-icon-monitor"></use></svg></span><div><h2>Siapkan diri sebelum ujian</h2><p>Ikuti langkah berikut agar proses berjalan lancar.</p></div></div>
                <ol class="preparation-steps">
                    <li><span>01</span><div><strong>Gunakan perangkat yang sesuai</strong><p>Disarankan menggunakan laptop/komputer dan browser versi terbaru.</p></div></li>
                    <li><span>02</span><div><strong>Pastikan koneksi stabil</strong><p>Hindari berpindah jaringan atau menutup halaman saat ujian berjalan.</p></div></li>
                    <li><span>03</span><div><strong>Baca instruksi setiap modul</strong><p>Durasi dan jenis jawaban dapat berbeda untuk setiap rangkaian tes.</p></div></li>
                </ol>
            </section>
            <section class="lobby-side-card rules-card">
                <div class="side-card-heading"><span class="side-heading-icon side-heading-gold"><svg class="icon"><use href="#l-icon-shield"></use></svg></span><div><h2>Aturan singkat</h2><p>Ketentuan umum pengerjaan asesmen.</p></div></div>
                <ul class="rules-list"><li><svg class="icon"><use href="#l-icon-check"></use></svg><span>Waktu dihitung setelah Anda memulai sesi ujian.</span></li><li><svg class="icon"><use href="#l-icon-check"></use></svg><span>Jawaban tersimpan berkala selama koneksi tersedia.</span></li><li><svg class="icon"><use href="#l-icon-check"></use></svg><span>Jangan menutup tab atau membagikan token kepada orang lain.</span></li><li><svg class="icon"><use href="#l-icon-check"></use></svg><span>Jawaban objektif dapat ditampilkan setelah sesi berakhir sesuai pengaturan HR.</span></li></ul>
                <div class="rules-confidential"><svg class="icon"><use href="#l-icon-shield"></use></svg><span>Data asesmen Anda dikelola secara rahasia oleh PT Lampung Jasa Utama.</span></div>
            </section>
        </aside>
    </div>

    <footer class="lobby-footer"><span>© <?= e(date('Y')) ?> PT Lampung Jasa Utama</span><span>Talent &amp; Competency Assessment Suite</span><span><svg class="icon"><use href="#l-icon-link"></use></svg> SSO &amp; Token Terverifikasi</span></footer>
</main>
<div class="toast" id="lobby-toast" role="status" aria-live="polite"></div>
</body>
</html>
