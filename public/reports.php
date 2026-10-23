<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/report_service.php';
app_require_auth();
$isHr = app_is_hr();
$isManagementView = !$isHr && app_is_management();
if (!$isHr && !$isManagementView) {
    http_response_code(403);
    exit('Akses ditolak. Laporan hanya tersedia untuk HR/Admin dan manajemen.');
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function report_page_status_label(array $row): string
{
    if ($row['attempt_status'] === 'pending_review') {
        return 'Menunggu review';
    }
    if ($row['total_score'] === null) {
        return $row['attempt_status'] === 'completed' ? 'Selesai · skor tidak tersedia' : 'Belum final';
    }
    if ($row['passing_score'] === null) {
        return 'Selesai · tanpa ambang';
    }

    return (float) $row['total_score'] >= (float) $row['passing_score'] ? 'Lulus' : 'Belum lulus';
}

function report_page_attempt_status_label(string $status): string
{
    return match ($status) {
        'pending_review' => 'Menunggu review',
        'completed' => 'Selesai',
        'submitted' => 'Terkirim',
        'auto_submitted' => 'Terkirim otomatis',
        default => 'Belum final',
    };
}

function report_page_status_class(array $row): string
{
    if ($row['attempt_status'] === 'pending_review' || ($row['total_score'] === null && $row['attempt_status'] !== 'completed')) {
        return 'is-pending';
    }
    if ($row['total_score'] === null) {
        return 'is-neutral';
    }
    if ($row['passing_score'] === null) {
        return 'is-neutral';
    }

    return (float) $row['total_score'] >= (float) $row['passing_score'] ? 'is-pass' : 'is-fail';
}

function report_page_score(mixed $value): string
{
    return $value === null ? '—' : number_format((float) $value, 2, ',', '.');
}

function report_page_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('d M Y · H:i') . ' WIB';
    } catch (Throwable) {
        return '—';
    }
}

function report_page_package_type(string $type): string
{
    return match ($type) {
        'internal' => 'Internal',
        'review_360' => 'Evaluasi 360°',
        default => 'Rekrutmen',
    };
}

function report_page_csv_number(mixed $value): string
{
    return $value === null ? '' : number_format((float) $value, 2, '.', '');
}

$filterError = null;
try {
    $filters = assessment_report_filters($_GET, $isManagementView);
} catch (DomainException $exception) {
    $filterError = $exception->getMessage();
    $filters = assessment_report_filters([], $isManagementView);
}
$exportType = is_scalar($_GET['export'] ?? null) ? (string) $_GET['export'] : '';
$exportRequested = in_array($exportType, ['csv', 'papi_csv'], true);
if ($exportRequested) {
    if (!$isHr) {
        http_response_code(403);
        exit('Ekspor rincian hasil hanya tersedia untuk HR/Admin.');
    }
    if ($filterError !== null) {
        http_response_code(422);
        exit(e($filterError));
    }
    try {
        if ($exportType === 'papi_csv') {
            $papiProfiles = assessment_report_hr_papi_profiles($filters, 1000);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="eassessment-skor-mentah-papi-' . date('Ymd-His') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new RuntimeException('Tidak dapat membuat ekspor skor mentah PAPI.');
            }
            fputcsv($output, [
                'Kode jadwal', 'Nama jadwal', 'Paket', 'Tipe peserta', 'Nama peserta',
                'Bidang', 'Divisi', 'Percobaan ke', 'Status attempt', 'Mulai', 'Dikirim',
                'Kode aspek', 'Nama aspek', 'Area kerja', 'Skor mentah per aspek',
            ], ',', '"', '\\');
            foreach ($papiProfiles as $profile) {
                foreach ($profile['scores'] as $score) {
                    fputcsv($output, [
                        assessment_report_csv_safe_text($profile['schedule_code']),
                        assessment_report_csv_safe_text($profile['schedule_name']),
                        assessment_report_csv_safe_text($profile['package_name']),
                        $profile['participant_type'] === 'employee' ? 'Karyawan SSO' : 'Kandidat',
                        assessment_report_csv_safe_text($profile['participant_name']),
                        assessment_report_csv_safe_text($profile['participant_division']),
                        assessment_report_csv_safe_text($profile['participant_department']),
                        (string) $profile['attempt_number'],
                        assessment_report_csv_safe_text($profile['attempt_status']),
                        assessment_report_csv_safe_text($profile['started_at']),
                        assessment_report_csv_safe_text($profile['submitted_at']),
                        assessment_report_csv_safe_text($score['aspect_code']),
                        assessment_report_csv_safe_text($score['label']),
                        assessment_report_csv_safe_text($score['group']),
                        report_page_csv_number($score['raw_score']),
                    ], ',', '"', '\\');
                }
            }
            fclose($output);
            exit;
        }

        $rows = assessment_report_hr_attempts($filters, 5000);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="eassessment-hasil-' . date('Ymd-His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Tidak dapat membuat ekspor CSV.');
        }
        fputcsv($output, [
            'Kode jadwal', 'Nama jadwal', 'Paket', 'Jenis paket', 'Tipe peserta',
            'Nama peserta', 'Email', 'Bidang', 'Divisi', 'Percobaan ke', 'Mulai', 'Dikirim',
            'Status attempt', 'Skor objektif', 'Skor manual', 'Skor akhir',
            'Nilai ambang', 'Hasil', 'Item review tertunda',
        ], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($output, [
                assessment_report_csv_safe_text($row['schedule_code']),
                assessment_report_csv_safe_text($row['schedule_name']),
                assessment_report_csv_safe_text($row['package_name']),
                assessment_report_csv_safe_text(report_page_package_type((string) $row['package_type'])),
                $row['participant_type'] === 'employee' ? 'Karyawan SSO' : 'Kandidat',
                assessment_report_csv_safe_text($row['participant_name']),
                assessment_report_csv_safe_text($row['participant_email']),
                assessment_report_csv_safe_text($row['participant_division']),
                assessment_report_csv_safe_text($row['participant_department']),
                (string) $row['attempt_number'],
                assessment_report_csv_safe_text($row['started_at']),
                assessment_report_csv_safe_text($row['submitted_at']),
                assessment_report_csv_safe_text($row['attempt_status']),
                report_page_csv_number($row['objective_score']),
                report_page_csv_number($row['manual_score']),
                report_page_csv_number($row['total_score']),
                report_page_csv_number($row['passing_score']),
                assessment_report_csv_safe_text(report_page_status_label($row)),
                (string) $row['pending_review_count'],
            ], ',', '"', '\\');
        }
        fclose($output);
        exit;
    } catch (Throwable $exception) {
        error_log('E-Assessment CSV report export failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('Ekspor laporan belum dapat dibuat. Coba kembali atau hubungi administrator.');
    }
}

$summary = [];
$attemptRows = [];
$papiProfiles = [];
$managementPackages = [];
$schedules = [];
$packages = [];
$databaseError = null;
try {
    $packages = assessment_report_package_options();
    if ($isHr) {
        $schedules = assessment_report_schedule_options();
        $summary = assessment_report_aggregate($filters, true);
        $attemptRows = assessment_report_hr_attempts($filters, 500);
        $papiProfiles = assessment_report_hr_papi_profiles($filters, 50);
    } else {
        $summary = assessment_report_aggregate($filters, false);
        $managementPackages = assessment_report_management_packages($filters);
    }
} catch (Throwable $exception) {
    error_log('E-Assessment report page load failed: ' . $exception->getMessage());
    $databaseError = 'Laporan belum dapat dimuat. Pastikan database aplikasi, skema, dan koneksi SSO tersedia.';
    $summary = [
        'attempt_count' => 0,
        'participant_count' => 0,
        'completed_participant_count' => 0,
        'scored_participant_count' => 0,
        'threshold_participant_count' => 0,
        'completed_count' => 0,
        'pending_review_count' => 0,
        'scored_count' => 0,
        'threshold_count' => 0,
        'passed_count' => 0,
        'pass_rate' => null,
    ];
}

$displayName = (string) (app_user()['name'] ?? 'Pengguna');
$avatarUrl = app_avatar_url();
$passRateSuppressed = (int) ($summary['threshold_participant_count'] ?? 0) < 5;
$papiReportExportUrl = 'reports.php?' . http_build_query([
    'from' => $filters['from'],
    'to' => $filters['to'],
    'package_id' => $filters['package_id'] > 0 ? $filters['package_id'] : '',
    'schedule_id' => $filters['schedule_id'] > 0 ? $filters['schedule_id'] : '',
    'status' => $filters['status'],
    'export' => 'papi_csv',
], '', '&', PHP_QUERY_RFC3986);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7fb">
    <title>Laporan &amp; Hasil · E-Assessment LJU</title>
    <link rel="stylesheet" href="assets/assessment-builder.css?v=1">
    <link rel="stylesheet" href="assets/reports.css?v=1">
    <link rel="stylesheet" href="assets/portal-ui.css?v=1">
    <script src="assets/portal-ui.js?v=1" defer></script>
</head>
<body class="assessment-builder-page report-page has-portal-sidebar">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="rp-chart" viewBox="0 0 24 24"><path d="M3 3v18h18M7 14l4-4 4 3 6-7M17 6h4v4"/></symbol>
    <symbol id="rp-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8a3 3 0 0 1-2 2.83M20 21v-2a4 4 0 0 0-3-3.87"/></symbol>
    <symbol id="rp-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="rp-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="rp-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></symbol>
    <symbol id="rp-pdf" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h2a1.5 1.5 0 0 1 0 3H8v-3ZM13 16v-3h1.5a1.5 1.5 0 0 1 0 3H13ZM18 13h-2v3M16 14.5h1.5"/></symbol>
    <symbol id="rp-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
</svg>
<?php $portalNavBase = ''; $portalNavActive = 'reports'; require dirname(__DIR__) . '/app/portal_sidebar.php'; ?>
<header class="ab-topbar">
    <a class="ab-brand" href="index.php" aria-label="Kembali ke dashboard">
        <span class="ab-brand-mark"><img src="assets/logo-lju.svg" alt="" onerror="this.parentElement.classList.add('logo-failed')"><b class="ab-brand-fallback">LJU</b></span>
        <span><strong>PT LAMPUNG JASA UTAMA</strong><small>E-Assessment · <?= $isHr ? 'Panel HR' : 'Ringkasan Manajemen' ?></small></span>
    </a>
    <nav class="ab-top-nav" aria-label="Navigasi utama">
        <a href="index.php">Dashboard</a>
        <?php if ($isHr): ?><a href="admin/question_bank.php">Bank Soal &amp; Paket</a><a href="admin/schedules.php">Jadwal &amp; Undangan</a><a href="admin/reviews.php">Portal Penilai HR</a><a href="admin/leaderboard.php">Leaderboard</a><?php endif; ?>
        <span>Laporan &amp; Hasil</span>
    </nav>
    <div class="ab-user">
        <span class="ab-avatar"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="" onerror="this.nextElementSibling.hidden=false;this.remove()"><?php endif; ?><b <?= $avatarUrl ? 'hidden' : '' ?>><?= e(app_user_initials($displayName)) ?></b></span>
        <span><strong><?= e($displayName) ?></strong><small><?= $isHr ? 'HR / Administrator' : 'Manajemen' ?></small></span>
    </div>
</header>
<main class="ab-main">
    <div class="ab-breadcrumb"><a href="index.php">Dashboard</a><span>›</span><strong>Laporan &amp; Hasil</strong></div>
    <section class="ab-hero rp-hero">
        <span class="ab-hero-icon"><svg class="icon"><use href="#rp-chart"></use></svg></span>
        <div class="ab-hero-copy"><span class="ab-eyebrow"><?= $isHr ? 'ANALISIS HASIL ASESMEN' : 'RINGKASAN AGREGAT' ?></span><h1>Laporan &amp; Hasil</h1><p><?= $isHr ? 'Telusuri hasil attempt, status penilaian, dan skor akhir peserta sesuai jadwal serta paket.' : 'Pantau capaian asesmen secara agregat per paket. Identitas peserta dan hasil individual tidak ditampilkan.' ?></p></div>
        <span class="ab-hero-orbit" aria-hidden="true"></span>
    </section>
    <div class="ab-tabs">
        <a href="index.php"><svg class="icon"><use href="#rp-chart"></use></svg>Dashboard</a>
        <?php if ($isHr): ?><a href="admin/schedules.php"><svg class="icon"><use href="#rp-clock"></use></svg>Jadwal</a><a href="admin/reviews.php"><svg class="icon"><use href="#rp-check"></use></svg>Penilaian</a><a href="admin/leaderboard.php"><svg class="icon"><use href="#rp-chart"></use></svg>Leaderboard</a><?php endif; ?>
        <a class="is-active" href="reports.php"><svg class="icon"><use href="#rp-chart"></use></svg>Laporan</a>
    </div>
    <?php if ($filterError): ?><div class="ab-alert ab-alert-error"><svg class="icon"><use href="#rp-info"></use></svg><span><?= e($filterError) ?></span></div><?php endif; ?>
    <?php if ($databaseError): ?><div class="ab-alert ab-alert-error"><svg class="icon"><use href="#rp-info"></use></svg><span><?= e($databaseError) ?></span></div><?php endif; ?>

    <form class="rp-filter-panel" method="get" action="reports.php">
        <div class="rp-filter-heading"><div><span class="ab-kicker">FILTER LAPORAN</span><h2><?= $isHr ? 'Periode &amp; cakupan hasil' : 'Periode &amp; paket asesmen' ?></h2></div><span>Zona waktu Asia/Jakarta (WIB)</span></div>
        <div class="rp-filter-grid <?= $isHr ? '' : 'is-management' ?>">
            <label class="ab-field"><span>Dari tanggal</span><input type="date" name="from" value="<?= e($filters['from']) ?>" required></label>
            <label class="ab-field"><span>Sampai tanggal</span><input type="date" name="to" value="<?= e($filters['to']) ?>" required></label>
            <label class="ab-field"><span>Paket</span><select name="package_id"><option value="">Semua paket</option><?php foreach ($packages as $package): ?><option value="<?= (int) $package['id'] ?>" <?= $filters['package_id'] === (int) $package['id'] ? 'selected' : '' ?>><?= e($package['name']) ?> · <?= e($package['code']) ?></option><?php endforeach; ?></select></label>
            <?php if ($isHr): ?>
                <label class="ab-field"><span>Jadwal</span><select name="schedule_id"><option value="">Semua jadwal</option><?php foreach ($schedules as $schedule): ?><option value="<?= (int) $schedule['id'] ?>" <?= $filters['schedule_id'] === (int) $schedule['id'] ? 'selected' : '' ?>><?= e($schedule['code']) ?> · <?= e($schedule['name']) ?></option><?php endforeach; ?></select></label>
                <label class="ab-field"><span>Status hasil</span><select name="status"><option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>Semua hasil</option><option value="completed" <?= $filters['status'] === 'completed' ? 'selected' : '' ?>>Selesai</option><option value="pending_review" <?= $filters['status'] === 'pending_review' ? 'selected' : '' ?>>Menunggu review</option></select></label>
            <?php endif; ?>
        </div>
        <div class="rp-filter-actions"><span>Rentang tanggal memakai waktu pengiriman attempt; untuk attempt tanpa waktu kirim dipakai waktu mulai.</span><div><button class="ab-secondary-button" type="submit"><svg class="icon"><use href="#rp-chart"></use></svg>Terapkan filter</button><?php if ($isHr): ?><button class="ab-primary-button" type="submit" name="export" value="csv"><svg class="icon"><use href="#rp-download"></use></svg>Unduh CSV</button><?php endif; ?><button class="ab-primary-button rp-pdf-button" type="submit" formaction="report_pdf.php" formmethod="get"><svg class="icon"><use href="#rp-pdf"></use></svg>Unduh PDF</button></div></div>
    </form>

    <?php if ($isHr): ?>
        <section class="ab-stat-grid rp-stat-grid" aria-label="Ringkasan hasil terfilter">
            <article><span class="ab-stat-icon"><svg class="icon"><use href="#rp-users"></use></svg></span><div><small>PESERTA DENGAN ATTEMPT</small><strong><?= number_format($summary['participant_count'] ?? 0, 0, ',', '.') ?></strong></div></article>
            <article><span class="ab-stat-icon"><svg class="icon"><use href="#rp-chart"></use></svg></span><div><small>ATTEMPT</small><strong><?= number_format($summary['attempt_count'] ?? 0, 0, ',', '.') ?></strong></div></article>
            <article><span class="ab-stat-icon is-green"><svg class="icon"><use href="#rp-check"></use></svg></span><div><small>ATTEMPT SELESAI</small><strong><?= number_format($summary['completed_count'] ?? 0, 0, ',', '.') ?></strong></div></article>
            <article><span class="ab-stat-icon is-muted"><svg class="icon"><use href="#rp-clock"></use></svg></span><div><small>ATTEMPT MENUNGGU REVIEW</small><strong><?= number_format($summary['pending_review_count'] ?? 0, 0, ',', '.') ?></strong></div></article>
            <article><span class="ab-stat-icon is-green"><svg class="icon"><use href="#rp-chart"></use></svg></span><div><small>TINGKAT LULUS · PER ATTEMPT</small><strong><?= $summary['pass_rate'] === null ? '—' : number_format((float) $summary['pass_rate'], 1, ',', '.') . '%' ?></strong></div></article>
        </section>
        <section class="ab-panel rp-results-panel" aria-labelledby="rp-results-heading">
            <div class="ab-panel-heading"><div><span class="ab-kicker">RINCIAN INDIVIDU · HR/ADMIN</span><h2 id="rp-results-heading">Hasil per attempt</h2><p>Daftar dan PDF memuat maksimal 500 attempt terbaru. Ekspor CSV memuat hingga 5.000 baris. Tingkat lulus dihitung per attempt selesai yang memiliki nilai ambang; retake dihitung terpisah.</p></div><span class="rp-result-count"><?= number_format(count($attemptRows), 0, ',', '.') ?> baris</span></div>
            <?php if ($attemptRows === []): ?>
                <div class="ab-empty rp-empty"><span><svg class="icon"><use href="#rp-chart"></use></svg></span><strong>Belum ada hasil pada filter ini</strong><p>Perluas rentang tanggal atau pilih jadwal/paket lain.</p></div>
            <?php else: ?>
                <div class="ab-table-scroll"><table class="ab-table rp-results-table"><thead><tr><th>PESERTA</th><th>JADWAL / PAKET</th><th>ATTEMPT</th><th>WAKTU KIRIM</th><th>OBJEKTIF</th><th>MANUAL</th><th>AKHIR</th><th>HASIL</th><th>REVIEW</th></tr></thead><tbody>
                <?php foreach ($attemptRows as $row): ?>
                    <tr>
                        <td><div class="ab-item-title"><strong><?= e($row['participant_name']) ?></strong><span><?= e($row['participant_email'] ?: ($row['participant_type'] === 'employee' ? 'Karyawan SSO' : 'Email tidak tersedia')) ?><?php if ($row['participant_division'] !== ''): ?> · Bidang <?= e($row['participant_division']) ?><?php endif; ?><?php if ($row['participant_department'] !== ''): ?> · Divisi <?= e($row['participant_department']) ?><?php endif; ?></span></div></td>
                        <td><div class="ab-item-title"><strong><?= e($row['schedule_code']) ?> · <?= e($row['schedule_name']) ?></strong><span><?= e($row['package_name']) ?> · <?= e(report_page_package_type((string) $row['package_type'])) ?></span></div></td>
                        <td><span class="rp-attempt-badge">#<?= (int) $row['attempt_number'] ?></span></td>
                        <td><span class="rp-date-cell"><?= e(report_page_date($row['submitted_at'] ?: $row['started_at'])) ?></span></td>
                        <td><span class="rp-score-cell"><?= e(report_page_score($row['objective_score'])) ?></span></td>
                        <td><span class="rp-score-cell"><?= e(report_page_score($row['manual_score'])) ?></span></td>
                        <td><span class="rp-score-cell is-total"><?= e(report_page_score($row['total_score'])) ?></span><?php if ($row['passing_score'] !== null): ?><small class="rp-passing-note">Ambang <?= e(report_page_score($row['passing_score'])) ?></small><?php endif; ?></td>
                        <td><span class="rp-result-pill <?= e(report_page_status_class($row)) ?>"><i></i><?= e(report_page_status_label($row)) ?></span></td>
                        <td><?php if ($row['pending_review_count'] > 0): ?><a class="rp-review-link" href="admin/reviews.php?status=open"> <?= (int) $row['pending_review_count'] ?> item · tinjau</a><?php else: ?><span class="rp-readonly">—</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php if (($summary['attempt_count'] ?? 0) > count($attemptRows)): ?><p class="rp-cap-note">Daftar di layar dibatasi 500 baris. Gunakan ekspor CSV untuk maksimal 5.000 baris atau persempit filter.</p><?php endif; ?>
            <?php endif; ?>
        </section>
        <section class="ab-panel rp-papi-report-panel" aria-labelledby="rp-papi-heading">
            <div class="ab-panel-heading rp-papi-panel-heading">
                <div><span class="ab-kicker">SKOR INSTRUMEN · HR/ADMIN</span><h2 id="rp-papi-heading">Skor mentah PAPI per attempt</h2><p>Skor aspek diambil apa adanya dari jawaban tersimpan (Most +1, Least −1). Laporan tidak menggabungkan skor antar peserta/attempt, menormalisasi, menafsirkan, atau memberi klasifikasi normatif.</p></div>
                <a class="ab-secondary-button rp-papi-export-button" href="<?= e($papiReportExportUrl) ?>"><svg class="icon"><use href="#rp-download"></use></svg>Unduh CSV PAPI</a>
            </div>
            <?php if ($papiProfiles === []): ?>
                <div class="ab-empty rp-empty"><span><svg class="icon"><use href="#rp-info"></use></svg></span><strong>Tidak ada skor mentah PAPI pada filter ini</strong><p>Perluas rentang tanggal atau pilih paket/jadwal lain.</p></div>
            <?php else: ?>
                <div class="rp-papi-profile-list">
                    <?php foreach ($papiProfiles as $profile): ?>
                        <article class="rp-papi-profile">
                            <div class="rp-papi-profile-heading"><div><strong><?= e($profile['participant_name']) ?></strong><small><?= e($profile['participant_type'] === 'employee' ? 'Karyawan SSO' : 'Kandidat') ?><?= $profile['participant_division'] !== '' ? ' · Bidang ' . e($profile['participant_division']) : '' ?><?= $profile['participant_department'] !== '' ? ' · Divisi ' . e($profile['participant_department']) : '' ?></small><small><?= e($profile['schedule_code']) ?> · <?= e($profile['schedule_name']) ?> · <?= e($profile['package_name']) ?></small></div><span class="rp-attempt-badge">#<?= (int) $profile['attempt_number'] ?> · <?= e(report_page_attempt_status_label($profile['attempt_status'])) ?></span></div>
                            <div class="rp-papi-aspect-grid">
                                <?php foreach ($profile['scores'] as $score): ?>
                                    <div><span><?= e($score['aspect_code']) ?></span><strong><?= e(report_page_score($score['raw_score'])) ?></strong><small><?= e($score['label']) ?> · <?= e($score['group']) ?></small></div>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="rp-cap-note">Tampilan menampilkan maksimal 50 attempt PAPI terbaru sesuai filter. Ekspor CSV memuat hingga 1.000 attempt dan satu baris per aspek; persempit filter untuk mengambil periode yang lebih lama.</p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="ab-stat-grid rp-stat-grid rp-management-stats" aria-label="Ringkasan agregat manajemen">
            <article><span class="ab-stat-icon"><svg class="icon"><use href="#rp-users"></use></svg></span><div><small>PESERTA DENGAN ATTEMPT</small><strong><?= number_format($summary['participant_count'] ?? 0, 0, ',', '.') ?></strong></div></article>
            <article><span class="ab-stat-icon"><svg class="icon"><use href="#rp-chart"></use></svg></span><div><small>TOTAL ATTEMPT</small><strong><?= number_format($summary['attempt_count'] ?? 0, 0, ',', '.') ?></strong></div></article>
            <article><span class="ab-stat-icon is-green"><svg class="icon"><use href="#rp-check"></use></svg></span><div><small>ATTEMPT SELESAI</small><strong><?= number_format($summary['completed_count'] ?? 0, 0, ',', '.') ?></strong></div></article>
            <article><span class="ab-stat-icon is-muted"><svg class="icon"><use href="#rp-clock"></use></svg></span><div><small>ATTEMPT MENUNGGU REVIEW</small><strong><?= number_format($summary['pending_review_count'] ?? 0, 0, ',', '.') ?></strong></div></article>
        </section>
        <?php if ($passRateSuppressed): ?>
            <div class="rp-privacy-note"><svg class="icon"><use href="#rp-info"></use></svg><span>Tingkat lulus keseluruhan hanya ditampilkan bila sedikitnya 5 peserta berbeda memiliki hasil final pada paket dengan nilai ambang.</span></div>
        <?php else: ?>
            <section class="ab-stat-grid rp-manager-score-stats"><article><span class="ab-stat-icon is-green"><svg class="icon"><use href="#rp-check"></use></svg></span><div><small>TINGKAT LULUS · PER ATTEMPT</small><strong><?= $summary['pass_rate'] === null ? '—' : number_format((float) $summary['pass_rate'], 1, ',', '.') . '%' ?></strong></div></article></section>
        <?php endif; ?>
        <section class="ab-panel rp-results-panel" aria-labelledby="rp-aggregate-heading">
            <div class="ab-panel-heading"><div><span class="ab-kicker">AGREGAT PER PAKET · TANPA IDENTITAS PESERTA</span><h2 id="rp-aggregate-heading">Capaian asesmen</h2><p>Rata-rata dan tingkat lulus per paket hanya ditampilkan bila ada sedikitnya 5 peserta dengan hasil final; retake dihitung sebagai attempt terpisah.</p></div><span class="rp-result-count"><?= number_format(count($managementPackages), 0, ',', '.') ?> paket</span></div>
            <?php if ($managementPackages === []): ?>
                <div class="ab-empty rp-empty"><span><svg class="icon"><use href="#rp-chart"></use></svg></span><strong>Belum ada data agregat pada filter ini</strong><p>Pilih periode atau paket lain untuk melihat ringkasan.</p></div>
            <?php else: ?>
                <div class="ab-table-scroll"><table class="ab-table rp-management-table"><thead><tr><th>PAKET</th><th>PESERTA</th><th>SELESAI</th><th>ATTEMPT</th><th>ATTEMPT MENUNGGU REVIEW</th><th>RATA-RATA SKOR</th><th>TINGKAT LULUS · PER ATTEMPT</th></tr></thead><tbody>
                <?php foreach ($managementPackages as $row): ?>
                    <tr><td><div class="ab-item-title"><strong><?= e($row['package_name']) ?></strong><span><?= e($row['package_code']) ?> · <?= e(report_page_package_type((string) $row['package_type'])) ?></span></div></td><td><span class="rp-score-cell"><?= number_format($row['participant_count'], 0, ',', '.') ?></span></td><td><div class="ab-item-metrics"><b><?= number_format($row['completed_count'], 0, ',', '.') ?> attempt</b><span><?= number_format($row['completed_participant_count'], 0, ',', '.') ?> peserta</span></div></td><td><span class="rp-score-cell"><?= number_format($row['attempt_count'], 0, ',', '.') ?></span></td><td><span class="rp-score-cell"><?= number_format($row['pending_review_count'], 0, ',', '.') ?></span></td>
                        <td><?php if ($row['average_suppressed']): ?><span class="rp-suppressed">Disembunyikan · kurang dari 5 peserta berskor</span><?php else: ?><span class="rp-score-cell"><?= $row['average_total_score'] === null ? '—' : e(number_format((float) $row['average_total_score'], 2, ',', '.')) ?></span><?php endif; ?></td><td><?php if ($row['pass_rate_suppressed']): ?><span class="rp-suppressed">Disembunyikan · kurang dari 5 peserta berambang</span><?php else: ?><span class="rp-score-cell"><?= $row['pass_rate'] === null ? '—' : e(number_format((float) $row['pass_rate'], 1, ',', '.') . '%') ?></span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
        <p class="rp-management-disclaimer">Tampilan manajemen hanya memuat ringkasan agregat per paket. Tidak ada nama, email, nomor SSO, baris attempt individual, atau ekspor rincian.</p>
    <?php endif; ?>
    <footer class="ab-footer"><span>PT Lampung Jasa Utama · E-Assessment</span><span><?= $isHr ? 'Rincian hasil hanya untuk HR/Admin' : 'Ringkasan agregat · hasil individual dirahasiakan' ?></span></footer>
</main>
</body>
</html>
