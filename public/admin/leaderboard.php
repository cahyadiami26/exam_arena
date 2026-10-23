<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/report_service.php';
app_require_auth();
if (!app_is_hr()) {
    http_response_code(403);
    exit('Leaderboard hanya tersedia untuk HR/Admin.');
}

function leaderboard_page_score(mixed $value): string
{
    return $value === null ? '—' : number_format((float) $value, 2, ',', '.');
}

function leaderboard_page_date(?string $value): string
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

$rawScheduleId = $_GET['schedule_id'] ?? null;
$parsedScheduleId = is_scalar($rawScheduleId) ? filter_var($rawScheduleId, FILTER_VALIDATE_INT) : false;
$scheduleId = is_int($parsedScheduleId) && $parsedScheduleId > 0 ? $parsedScheduleId : 0;
$schedules = [];
$selectedSchedule = null;
$leaderboardRows = [];
$pageError = null;
$databaseError = null;
$hasMoreRows = false;

try {
    $schedules = assessment_report_leaderboard_schedule_options();
    if ($scheduleId > 0) {
        foreach ($schedules as $schedule) {
            if ((int) $schedule['id'] === $scheduleId) {
                $selectedSchedule = $schedule;
                break;
            }
        }
        if ($selectedSchedule === null) {
            $pageError = 'Jadwal tidak tersedia untuk leaderboard. Paket PAPI dan 360° tidak dirangking.';
        } else {
            $leaderboardRows = assessment_report_leaderboard($scheduleId, 501);
            $hasMoreRows = count($leaderboardRows) > 500;
            if ($hasMoreRows) {
                $leaderboardRows = array_slice($leaderboardRows, 0, 500);
            }
        }
    }
} catch (DomainException $exception) {
    $pageError = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('E-Assessment leaderboard page load failed: ' . $exception->getMessage());
    $databaseError = 'Leaderboard belum dapat dimuat. Pastikan database dan skema aplikasi tersedia.';
}

$displayName = (string) (app_user()['name'] ?? 'Pengguna');
$avatarUrl = app_avatar_url();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7fb">
    <title>Leaderboard · E-Assessment LJU</title>
    <link rel="stylesheet" href="../assets/assessment-builder.css?v=1">
    <link rel="stylesheet" href="../assets/leaderboard.css?v=1">
    <link rel="stylesheet" href="../assets/portal-ui.css?v=1">
    <script src="../assets/portal-ui.js?v=1" defer></script>
</head>
<body class="assessment-builder-page leaderboard-page has-portal-sidebar">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="lb-chart" viewBox="0 0 24 24"><path d="M3 3v18h18M7 14l4-4 4 3 6-7M17 6h4v4"/></symbol>
    <symbol id="lb-award" viewBox="0 0 24 24"><circle cx="12" cy="8" r="6"/><path d="m8.2 13-1.1 9 4.9-2.7 4.9 2.7-1.1-9"/></symbol>
    <symbol id="lb-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8a3 3 0 0 1-2 2.83M20 21v-2a4 4 0 0 0-3-3.87"/></symbol>
    <symbol id="lb-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="lb-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
    <symbol id="lb-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
</svg>
<?php $portalNavBase = '../'; $portalNavActive = 'leaderboard'; require dirname(__DIR__, 2) . '/app/portal_sidebar.php'; ?>
<header class="ab-topbar">
    <a class="ab-brand" href="../index.php" aria-label="Kembali ke dashboard HR">
        <span class="ab-brand-mark"><img src="../assets/logo-lju.svg" alt="" onerror="this.parentElement.classList.add('logo-failed')"><b class="ab-brand-fallback">LJU</b></span>
        <span><strong>PT LAMPUNG JASA UTAMA</strong><small>E-Assessment · Panel HR</small></span>
    </a>
    <nav class="ab-top-nav" aria-label="Navigasi utama">
        <a href="../index.php">Dashboard</a>
        <a href="question_bank.php">Bank Soal &amp; Paket</a>
        <a href="schedules.php">Jadwal &amp; Undangan</a>
        <a href="reviews.php">Portal Penilai HR</a>
        <a href="../reports.php">Laporan &amp; Hasil</a>
        <span>Leaderboard</span>
    </nav>
    <div class="ab-user">
        <span class="ab-avatar"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="" onerror="this.nextElementSibling.hidden=false;this.remove()"><?php endif; ?><b <?= $avatarUrl ? 'hidden' : '' ?>><?= e(app_user_initials($displayName)) ?></b></span>
        <span><strong><?= e($displayName) ?></strong><small>HR / Administrator</small></span>
    </div>
</header>
<main class="ab-main">
    <div class="ab-breadcrumb"><a href="../index.php">Dashboard</a><span>›</span><strong>Leaderboard</strong></div>
    <section class="ab-hero lb-hero">
        <span class="ab-hero-icon"><svg class="icon"><use href="#lb-award"></use></svg></span>
        <div class="ab-hero-copy"><span class="ab-eyebrow">PERINGKAT INTERNAL · HR/ADMIN</span><h1>Leaderboard hasil asesmen</h1><p>Peringkat final per jadwal. Hanya HR/Admin yang dapat melihat nama peserta dan hasil ini.</p></div>
        <span class="ab-hero-orbit" aria-hidden="true"></span>
    </section>
    <div class="ab-tabs">
        <a href="question_bank.php"><svg class="icon"><use href="#lb-chart"></use></svg>Bank Soal</a>
        <a href="schedules.php"><svg class="icon"><use href="#lb-clock"></use></svg>Jadwal</a>
        <a href="reviews.php"><svg class="icon"><use href="#lb-check"></use></svg>Penilaian</a>
        <a href="../reports.php"><svg class="icon"><use href="#lb-chart"></use></svg>Laporan</a>
        <a class="is-active" href="leaderboard.php"><svg class="icon"><use href="#lb-award"></use></svg>Leaderboard</a>
    </div>

    <?php if ($databaseError): ?><div class="ab-alert ab-alert-error"><svg class="icon"><use href="#lb-info"></use></svg><span><?= e($databaseError) ?></span></div><?php endif; ?>
    <?php if ($pageError): ?><div class="ab-alert ab-alert-error"><svg class="icon"><use href="#lb-info"></use></svg><span><?= e($pageError) ?></span></div><?php endif; ?>

    <form class="lb-filter-panel" method="get" action="leaderboard.php">
        <label class="ab-field"><span>Pilih jadwal</span>
            <select name="schedule_id" required>
                <option value="">Pilih satu jadwal…</option>
                <?php foreach ($schedules as $schedule): ?>
                    <option value="<?= (int) $schedule['id'] ?>" <?= $scheduleId === (int) $schedule['id'] ? 'selected' : '' ?>><?= e($schedule['code']) ?> · <?= e($schedule['name']) ?> — <?= e($schedule['package_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="ab-primary-button"><svg class="icon"><use href="#lb-award"></use></svg>Tampilkan peringkat</button>
    </form>

    <?php if ($selectedSchedule !== null && !$pageError): ?>
        <section class="lb-context-grid" aria-label="Cakupan leaderboard">
            <article><small>JADWAL</small><strong><?= e($selectedSchedule['code']) ?> · <?= e($selectedSchedule['name']) ?></strong></article>
            <article><small>PAKET</small><strong><?= e($selectedSchedule['package_name']) ?></strong></article>
            <article><small>PESERTA BERSKOR FINAL</small><strong><?= number_format((int) $selectedSchedule['scored_participant_count'], 0, ',', '.') ?></strong></article>
            <article><small>BASIS RANKING</small><strong>Skor final tertinggi per peserta</strong></article>
        </section>
        <section class="ab-panel lb-results-panel" aria-labelledby="lb-results-heading">
            <div class="ab-panel-heading"><div><span class="ab-kicker">SKOR FINAL · ATTEMPT SELESAI</span><h2 id="lb-results-heading">Peringkat peserta</h2><p>Retake memakai skor tertinggi; jika skornya sama, attempt terbaru yang dipilih. Skor sama berbagi peringkat dan nomor berikutnya mengikuti urutan kompetisi.</p></div><span class="lb-count-badge"><?= number_format(count($leaderboardRows), 0, ',', '.') ?> peserta</span></div>
            <?php if ($leaderboardRows === []): ?>
                <div class="ab-empty lb-empty"><span><svg class="icon"><use href="#lb-users"></use></svg></span><strong>Belum ada skor final untuk jadwal ini</strong><p>Attempt yang masih menunggu review tidak masuk peringkat.</p></div>
            <?php else: ?>
                <div class="ab-table-scroll"><table class="ab-table lb-table"><thead><tr><th>PERINGKAT</th><th>PESERTA</th><th>SKOR FINAL</th><th>ATTEMPT TERPILIH</th><th>WAKTU KIRIM</th><th>AMBANG / HASIL</th></tr></thead><tbody>
                    <?php foreach ($leaderboardRows as $row): ?>
                        <?php
                        $rank = (int) $row['leaderboard_rank'];
                        $resultLabel = $row['passing_score'] === null
                            ? 'Tanpa ambang'
                            : ($row['total_score'] >= $row['passing_score'] ? 'Lulus' : 'Belum lulus');
                        $resultClass = $row['passing_score'] === null
                            ? 'is-neutral'
                            : ($row['total_score'] >= $row['passing_score'] ? 'is-pass' : 'is-fail');
                        $typeLabel = $row['participant_type'] === 'employee' ? 'Karyawan SSO' : 'Kandidat';
                        ?>
                        <tr>
                            <td><span class="lb-rank <?= $rank <= 3 ? 'is-top-' . $rank : '' ?>"><?= number_format($rank, 0, ',', '.') ?></span></td>
                            <td><div class="lb-person"><strong><?= e($row['participant_name']) ?></strong><small><?= e($typeLabel) ?><?= $row['participant_division'] !== '' ? ' · Bidang ' . e($row['participant_division']) : '' ?><?= $row['participant_department'] !== '' ? ' · Divisi ' . e($row['participant_department']) : '' ?></small></div></td>
                            <td><strong class="lb-score"><?= e(leaderboard_page_score($row['total_score'])) ?></strong></td>
                            <td><span class="lb-attempt-badge">#<?= (int) $row['attempt_number'] ?></span></td>
                            <td><span class="lb-date"><?= e(leaderboard_page_date($row['submitted_at'])) ?></span></td>
                            <td><span class="lb-result-pill <?= e($resultClass) ?>"><i></i><?= e($resultLabel) ?></span><?php if ($row['passing_score'] !== null): ?><small class="lb-threshold">Ambang <?= e(leaderboard_page_score($row['passing_score'])) ?></small><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <?php if ($hasMoreRows): ?><p class="lb-cap-note">Tampilan dibatasi 500 peserta teratas; peringkat tetap dihitung terhadap seluruh peserta berskor final pada jadwal ini.</p><?php endif; ?>
            <?php endif; ?>
        </section>
        <div class="lb-policy-note"><svg class="icon"><use href="#lb-info"></use></svg><span>Leaderboard ini hanya untuk HR/Admin. Paket yang memuat PAPI atau evaluasi 360° tidak ditawarkan karena skor finalnya tidak digunakan sebagai dasar peringkat. Hasil PAPI tetap tersedia sebagai skor mentah per aspek tanpa norma.</span></div>
    <?php elseif ($schedules === [] && !$databaseError): ?>
        <div class="ab-panel lb-no-schedules"><div class="ab-empty"><span><svg class="icon"><use href="#lb-info"></use></svg></span><strong>Belum ada jadwal yang dapat dirangking</strong><p>Leaderboard hanya memakai paket non-PAPI/non-360° dengan skor final per attempt.</p></div></div>
    <?php endif; ?>
    <footer class="ab-footer"><span>PT Lampung Jasa Utama · E-Assessment</span><span>Hasil bernama hanya untuk HR/Admin</span></footer>
</main>
</body>
</html>
