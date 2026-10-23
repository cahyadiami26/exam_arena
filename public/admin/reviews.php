<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/review_service.php';
app_require_hr();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function review_page_type_label(string $type): string
{
    return match ($type) {
        'essay' => 'Esai',
        'practical' => 'Praktik / berkas',
        'psychometric' => 'Psikometri',
        'papi' => 'PAPI Kostick',
        'review_360' => 'Evaluasi 360°',
        'overall' => 'Konsolidasi akhir',
        default => 'Review assessor',
    };
}

function review_page_status_label(string $status): string
{
    return match ($status) {
        'in_progress' => 'Sedang ditinjau',
        'completed' => 'Selesai ditinjau',
        default => 'Menunggu penilaian',
    };
}

function review_page_status_class(string $status): string
{
    return match ($status) {
        'completed' => 'is-complete',
        'in_progress' => 'is-progress',
        default => 'is-pending',
    };
}

function review_page_recommendation_label(?string $recommendation): string
{
    return match ($recommendation) {
        'recommended' => 'Direkomendasikan',
        'consider' => 'Dipertimbangkan',
        'not_recommended' => 'Tidak direkomendasikan',
        default => 'Belum ditetapkan',
    };
}

function review_page_date(?string $value): string
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

function review_page_file_size(mixed $bytes): string
{
    $size = max(0, (int) $bytes);
    if ($size >= 1024 * 1024) {
        return number_format($size / (1024 * 1024), 2, ',', '.') . ' MB';
    }
    return number_format(max(1, (int) ceil($size / 1024)), 0, ',', '.') . ' KB';
}

function review_page_unit(array $profile): string
{
    $units = [];
    $division = trim((string) ($profile['participant_division'] ?? ''));
    $department = trim((string) ($profile['participant_department'] ?? ''));
    if ($division !== '') {
        $units[] = 'Bidang ' . $division;
    }
    if ($department !== '') {
        $units[] = 'Divisi ' . $department;
    }
    return implode(' · ', $units);
}

function review_page_safe_query_value(mixed $value, string $default): string
{
    return is_scalar($value) ? (string) $value : $default;
}

if (isset($_GET['asset_review_id'], $_GET['asset_type'], $_GET['asset_record_id'])) {
    $reviewIdValue = filter_var($_GET['asset_review_id'], FILTER_VALIDATE_INT);
    $recordIdValue = filter_var($_GET['asset_record_id'], FILTER_VALIDATE_INT);
    $assetType = review_page_safe_query_value($_GET['asset_type'], '');
    try {
        $asset = assessment_review_private_question_asset(
            is_int($reviewIdValue) ? $reviewIdValue : 0,
            $assetType,
            is_int($recordIdValue) ? $recordIdValue : 0
        );
        if ($asset === null) {
            http_response_code(404);
            exit('Aset soal tidak ditemukan.');
        }
        $mime = strtolower(trim((string) $asset['mime_type']));
        if (!preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D', $mime)) {
            $mime = 'application/octet-stream';
        }
        $filename = preg_replace('/[\r\n"\\]/', '_', (string) $asset['original_file_name']) ?: 'assessment-asset';
        $asciiFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'assessment-asset';
        $inlineTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $inline = in_array($assetType, ['question_image', 'option_image'], true) && in_array($mime, $inlineTypes, true);
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . (string) filesize($asset['path']));
        header('Cache-Control: private, no-store');
        readfile($asset['path']);
        exit;
    } catch (Throwable $exception) {
        error_log('E-Assessment review question asset delivery failed: ' . $exception->getMessage());
        http_response_code(404);
        exit('Aset soal tidak ditemukan.');
    }
}

if (isset($_GET['file_review_id'], $_GET['file_id'])) {
    $reviewIdValue = filter_var($_GET['file_review_id'], FILTER_VALIDATE_INT);
    $fileIdValue = filter_var($_GET['file_id'], FILTER_VALIDATE_INT);
    try {
        $file = assessment_review_private_file(
            is_int($reviewIdValue) ? $reviewIdValue : 0,
            is_int($fileIdValue) ? $fileIdValue : 0
        );
        if ($file === null) {
            http_response_code(404);
            exit('File jawaban tidak ditemukan.');
        }
        $mime = strtolower(trim((string) $file['mime_type']));
        if (!preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D', $mime)) {
            $mime = 'application/octet-stream';
        }
        $filename = preg_replace('/[\r\n"\\]/', '_', (string) $file['original_file_name']) ?: 'assessment-file';
        $asciiFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'assessment-file';
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . (string) filesize($file['path']));
        header('Cache-Control: private, no-store');
        readfile($file['path']);
        exit;
    } catch (Throwable $exception) {
        error_log('E-Assessment review attachment delivery failed: ' . $exception->getMessage());
        http_response_code(404);
        exit('File jawaban tidak ditemukan.');
    }
}

$statusFilter = review_page_safe_query_value($_GET['status'] ?? 'open', 'open');
if (!in_array($statusFilter, ['open', 'completed', 'all'], true)) {
    $statusFilter = 'open';
}
$typeFilter = review_page_safe_query_value($_GET['type'] ?? 'all', 'all');
$validTypes = ['all', 'essay', 'practical', 'psychometric', 'papi', 'review_360', 'overall'];
if (!in_array($typeFilter, $validTypes, true)) {
    $typeFilter = 'all';
}
$reviewId = 0;
$formError = null;
$databaseError = null;
$flash = app_flash('assessment_review');
$formInput = [];
$wasPosted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($wasPosted) {
    $parsedId = filter_var($_POST['review_id'] ?? null, FILTER_VALIDATE_INT);
    $reviewId = is_int($parsedId) ? max(0, $parsedId) : 0;
    $formInput = [
        'score' => review_page_safe_query_value($_POST['score'] ?? '', ''),
        'recommendation' => review_page_safe_query_value($_POST['recommendation'] ?? '', ''),
        'strengths' => review_page_safe_query_value($_POST['strengths'] ?? '', ''),
        'development_areas' => review_page_safe_query_value($_POST['development_areas'] ?? '', ''),
        'notes' => review_page_safe_query_value($_POST['notes'] ?? '', ''),
    ];
    if (!app_verify_csrf($_POST['_csrf_token'] ?? null)) {
        http_response_code(419);
        $formError = 'Sesi formulir kedaluwarsa. Muat ulang halaman sebelum menyimpan penilaian.';
    } else {
        try {
            if (review_page_safe_query_value($_POST['action'] ?? '', '') !== 'save_review') {
                throw new DomainException('Aksi penilaian tidak dikenal.');
            }
            $result = assessment_review_save($reviewId, (int) (app_user()['id'] ?? 0), $_POST);
            $message = 'Penilaian tersimpan.';
            if ($result['attempt_status'] === 'completed') {
                $message .= ' Semua item review untuk sesi ini selesai; hasil akhir sudah dikonsolidasikan.';
            } else {
                $message .= ' Masih ada ' . $result['pending_reviews'] . ' item review pada sesi ini.';
            }
            app_flash('assessment_review', $message);
            app_redirect('reviews.php?status=all&type=all&id=' . $reviewId . '#review-detail');
        } catch (DomainException $exception) {
            $formError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('E-Assessment manual review save failed: ' . $exception->getMessage());
            $formError = 'Penilaian belum dapat disimpan. Periksa data atau koneksi database.';
        }
    }
} else {
    $parsedId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $reviewId = is_int($parsedId) ? max(0, $parsedId) : 0;
}

$queue = [];
$summary = ['total' => 0, 'open' => 0, 'completed' => 0, 'manual' => 0];
$selectedReview = null;
try {
    $summary = assessment_review_summary();
    $queue = assessment_review_list($statusFilter, $typeFilter);
    if ($reviewId > 0) {
        $selectedReview = assessment_review_get($reviewId);
        if ($selectedReview === null) {
            $formError ??= 'Item penilaian tidak ditemukan.';
        }
    }
} catch (Throwable $exception) {
    error_log('E-Assessment review page load failed: ' . $exception->getMessage());
    $databaseError = 'Antrean penilaian belum dapat dimuat. Pastikan database aplikasi, skema, dan koneksi SSO tersedia.';
}

$user = app_user();
$displayName = (string) ($user['name'] ?? 'Pengguna');
$avatarUrl = app_avatar_url();
$isManualScore = $selectedReview !== null && in_array($selectedReview['review_type'], ['essay', 'practical'], true);
$isPapiReview = $selectedReview !== null && $selectedReview['review_type'] === 'papi';
$isReview360 = $selectedReview !== null && $selectedReview['review_type'] === 'review_360';
$isParticipantReview = $isPapiReview || $isReview360;
$detailParticipantName = $isReview360 && trim((string) ($selectedReview['subject_name'] ?? '')) !== ''
    ? (string) $selectedReview['subject_name']
    : (string) ($selectedReview['participant_name'] ?? '');
$selectedStatus = (string) ($selectedReview['review_status'] ?? '');
$papiProfileScores = $selectedReview !== null && $selectedReview['review_type'] === 'papi'
    ? assessment_papi_profile_scores($selectedReview['papi_scores'] ?? [])
    : [];
$filteredReviewTotal = $statusFilter === 'open'
    ? $summary['open']
    : ($statusFilter === 'completed' ? $summary['completed'] : $summary['total']);
$reviewActionUrl = 'reviews.php?status=' . rawurlencode($statusFilter)
    . '&type=' . rawurlencode($typeFilter) . '&id=' . $reviewId . '#review-detail';
$inputValue = static function (string $field, mixed $stored = null) use ($formInput, $wasPosted): string {
    if ($wasPosted && array_key_exists($field, $formInput)) {
        return $formInput[$field];
    }
    return is_scalar($stored) ? (string) $stored : '';
};
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7fb">
    <title>Portal Penilai HR · E-Assessment LJU</title>
    <link rel="stylesheet" href="../assets/assessment-builder.css?v=1">
    <link rel="stylesheet" href="../assets/reviews.css?v=1">
    <link rel="stylesheet" href="../assets/portal-ui.css?v=1">
    <script src="../assets/portal-ui.js?v=1" defer></script>
</head>
<body class="assessment-builder-page review-page has-portal-sidebar">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="rv-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="rv-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></symbol>
    <symbol id="rv-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></symbol>
    <symbol id="rv-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8a3 3 0 0 1-2 2.83M20 21v-2a4 4 0 0 0-3-3.87"/></symbol>
    <symbol id="rv-star" viewBox="0 0 24 24"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/></symbol>
    <symbol id="rv-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="rv-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></symbol>
    <symbol id="rv-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
</svg>
<?php $portalNavBase = '../'; $portalNavActive = 'reviews'; require dirname(__DIR__, 2) . '/app/portal_sidebar.php'; ?>
<header class="ab-topbar">
    <a class="ab-brand" href="../index.php" aria-label="Kembali ke dashboard HR">
        <span class="ab-brand-mark"><img src="../assets/logo-lju.svg" alt="" onerror="this.parentElement.classList.add('logo-failed')"><b class="ab-brand-fallback">LJU</b></span>
        <span><strong>PT LAMPUNG JASA UTAMA</strong><small>E-Assessment · Panel HR</small></span>
    </a>
    <nav class="ab-top-nav" aria-label="Navigasi utama">
        <a href="../index.php">Dashboard</a>
        <a href="question_bank.php">Bank Soal &amp; Paket</a>
        <a href="schedules.php">Jadwal &amp; Undangan</a>
        <a href="leaderboard.php">Leaderboard</a>
        <a href="../reports.php">Laporan &amp; Hasil</a>
        <span>Portal Penilai HR</span>
    </nav>
    <div class="ab-user">
        <span class="ab-avatar"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="" onerror="this.remove()"><?php else: ?><b><?= e(app_user_initials($displayName)) ?></b><?php endif; ?></span>
        <span><strong><?= e($displayName) ?></strong><small>HR / Administrator</small></span>
    </div>
</header>
<main class="ab-main">
    <div class="ab-breadcrumb"><a href="../index.php">Dashboard</a><span>›</span><strong>Portal Penilai HR</strong></div>
    <section class="ab-hero rv-hero">
        <span class="ab-hero-icon"><svg class="icon"><use href="#rv-check"></use></svg></span>
        <div class="ab-hero-copy"><span class="ab-eyebrow">PENILAIAN &amp; VERIFIKASI</span><h1>Portal Penilai HR</h1><p>Tinjau jawaban esai dan praktik; nilai profil PAPI atau respons 360° secara terkumpul per peserta/subjek, lalu simpan review dan rekomendasi internal HR.</p></div>
        <span class="ab-hero-orbit" aria-hidden="true"></span>
    </section>
    <div class="ab-tabs">
        <a href="question_bank.php"><svg class="icon"><use href="#rv-file"></use></svg>Bank Soal &amp; Paket</a>
        <a href="schedules.php"><svg class="icon"><use href="#rv-clock"></use></svg>Jadwal</a>
        <a href="leaderboard.php"><svg class="icon"><use href="#rv-star"></use></svg>Leaderboard</a>
        <a class="is-active" href="reviews.php"><svg class="icon"><use href="#rv-check"></use></svg>Penilaian</a>
    </div>
    <?php if ($flash): ?><div class="ab-alert ab-alert-success"><svg class="icon"><use href="#rv-check"></use></svg><span><?= e($flash) ?></span></div><?php endif; ?>
    <?php if ($formError): ?><div class="ab-alert ab-alert-error"><svg class="icon"><use href="#rv-info"></use></svg><span><?= e($formError) ?></span></div><?php endif; ?>
    <?php if ($databaseError): ?><div class="ab-alert ab-alert-error"><svg class="icon"><use href="#rv-info"></use></svg><span><?= e($databaseError) ?></span></div><?php endif; ?>

    <section class="ab-stat-grid rv-stat-grid" aria-label="Ringkasan antrean penilaian">
        <article><span class="ab-stat-icon"><svg class="icon"><use href="#rv-clock"></use></svg></span><div><small>MENUNGGU / BERLANGSUNG</small><strong><?= number_format($summary['open'], 0, ',', '.') ?></strong></div></article>
        <article><span class="ab-stat-icon is-green"><svg class="icon"><use href="#rv-check"></use></svg></span><div><small>SELESAI DITINJAU</small><strong><?= number_format($summary['completed'], 0, ',', '.') ?></strong></div></article>
        <article><span class="ab-stat-icon is-muted"><svg class="icon"><use href="#rv-file"></use></svg></span><div><small>SKOR ESAI / PRAKTIK</small><strong><?= number_format($summary['manual'], 0, ',', '.') ?></strong></div></article>
    </section>

    <div class="rv-layout">
        <section class="ab-panel rv-queue-panel" aria-labelledby="review-queue-heading">
            <div class="ab-panel-heading"><div><span class="ab-kicker">ANTREAN REVIEW</span><h2 id="review-queue-heading">Review peserta</h2><p>PAPI dan Review360 dikelompokkan per peserta atau subjek; jenis lain tetap mengikuti alur per item. Maksimal 250 review sesuai filter.</p></div><span class="rv-queue-count"><?= number_format(count($queue), 0, ',', '.') ?> review</span></div>
            <form class="rv-filter-form" method="get" action="reviews.php">
                <label class="ab-field"><span>Status</span><select name="status"><option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Belum selesai</option><option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Selesai</option><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua status</option></select></label>
                <label class="ab-field"><span>Jenis review</span><select name="type"><option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>Semua jenis</option><?php foreach (array_slice($validTypes, 1) as $reviewType): ?><option value="<?= e($reviewType) ?>" <?= $typeFilter === $reviewType ? 'selected' : '' ?>><?= e(review_page_type_label($reviewType)) ?></option><?php endforeach; ?></select></label>
                <button class="ab-secondary-button" type="submit"><svg class="icon"><use href="#rv-search"></use></svg>Filter</button>
            </form>
            <?php if ($queue === []): ?>
                <div class="ab-empty rv-empty"><span><svg class="icon"><use href="#rv-check"></use></svg></span><strong>Tidak ada item pada filter ini</strong><p>Item baru akan muncul setelah peserta mengirimkan sesi asesmen.</p></div>
            <?php else: ?>
                <div class="rv-queue-list">
                    <?php foreach ($queue as $item): ?>
                        <?php
                        $itemUrl = 'reviews.php?status=' . rawurlencode($statusFilter) . '&type=' . rawurlencode($typeFilter) . '&id=' . (int) $item['review_id'] . '#review-detail';
                        $isSelected = $selectedReview !== null && (int) $selectedReview['review_id'] === (int) $item['review_id'];
                        ?>
                        <a class="rv-queue-item <?= $isSelected ? 'is-selected' : '' ?>" href="<?= e($itemUrl) ?>">
                            <span class="rv-queue-icon"><svg class="icon"><use href="#<?= $item['review_type'] === 'practical' ? 'rv-download' : ($item['review_type'] === 'essay' ? 'rv-file' : 'rv-star') ?>"></use></svg></span>
                            <span class="rv-queue-copy"><span class="rv-queue-topline"><strong><?= e(review_page_type_label((string) $item['review_type'])) ?></strong><span class="rv-status <?= e(review_page_status_class((string) $item['review_status'])) ?>"><?= e(review_page_status_label((string) $item['review_status'])) ?></span></span>
                                <b><?= e($item['participant_name']) ?><?= $item['subject_name'] !== '' ? ' → ' . e($item['subject_name']) : '' ?></b>
                                <small><?= e($item['schedule_name']) ?> · <?= e($item['package_name']) ?></small>
                                <small><?= e($item['question_preview'] ?: 'Item konsolidasi sesi') ?></small>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($typeFilter === 'all' && $filteredReviewTotal > count($queue)): ?><p class="rv-list-cap">Antrean dibatasi hingga 250 item. Gunakan filter jenis review untuk mempersempit daftar.</p><?php endif; ?>
        </section>

        <section class="ab-panel rv-detail-panel" id="review-detail" aria-labelledby="review-detail-heading">
            <?php if ($selectedReview === null): ?>
                <div class="ab-empty rv-detail-empty"><span><svg class="icon"><use href="#rv-file"></use></svg></span><strong>Pilih review peserta</strong><p>Pilih entri pada antrean untuk melihat profil atau seluruh jawaban pada grup, lalu isi review dan rekomendasi HR.</p></div>
            <?php else: ?>
                <div class="ab-panel-heading rv-detail-heading"><div><span class="ab-kicker"><?= e(review_page_type_label((string) $selectedReview['review_type'])) ?></span><h2 id="review-detail-heading"><?= e($detailParticipantName) ?></h2><p><?= $isReview360 ? 'Penilai: ' . e($selectedReview['participant_name']) . ' · ' : '' ?><?= e($selectedReview['schedule_name']) ?> · <?= e($selectedReview['package_name']) ?> · Percobaan <?= (int) $selectedReview['attempt_number'] ?></p></div><span class="rv-status <?= e(review_page_status_class($selectedStatus)) ?>"><?= e(review_page_status_label($selectedStatus)) ?></span></div>
                <div class="rv-context-grid">
                    <div><small><?= $isReview360 ? 'PENILAI / PENGISI' : 'PESERTA' ?></small><strong><?= e($selectedReview['participant_name']) ?></strong><span><?= e($selectedReview['participant_email'] ?: ($selectedReview['participant_type'] === 'employee' ? review_page_unit($selectedReview) : 'Kandidat eksternal')) ?></span></div>
                    <div><small>JADWAL</small><strong><?= e($selectedReview['schedule_code']) ?></strong><span><?= e($selectedReview['schedule_name']) ?></span></div>
                    <?php if ($selectedReview['subject_name'] !== ''): ?><div><small>SUBJEK 360°</small><strong><?= e($selectedReview['subject_name']) ?></strong><span>Respons reviewer untuk subjek ini</span></div><?php endif; ?>
                    <div><small>DIAJUKAN</small><strong><?= e(review_page_date((string) $selectedReview['created_at'])) ?></strong><span><?= e($selectedReview['module_name'] ?: 'Review sesi') ?></span></div>
                </div>

                <?php if ($isParticipantReview): ?>
                    <article class="rv-answer-card rv-group-answer-card">
                        <div class="rv-section-kicker"><?= $isPapiReview ? 'JAWABAN PAPI PER PESERTA' : 'JAWABAN 360° PER SUBJEK' ?></div>
                        <?php if ($selectedReview['review_answers'] === []): ?>
                            <p class="rv-answer-empty">Belum ada jawaban tersimpan untuk review ini.</p>
                        <?php else: ?>
                            <div class="rv-group-answer-list">
                                <?php foreach ($selectedReview['review_answers'] as $answerIndex => $answer): ?>
                                    <section class="rv-group-answer-item">
                                        <div class="rv-group-answer-heading"><strong><?= $isPapiReview ? 'ITEM PAPI ' . e($answer['papi_item_code'] ?: str_pad((string) ($answerIndex + 1), 2, '0', STR_PAD_LEFT)) : 'BUTIR ' . str_pad((string) ($answerIndex + 1), 2, '0', STR_PAD_LEFT) ?></strong><span><?= e($answer['module_name']) ?></span></div>
                                        <h3><?= nl2br(e((string) $answer['question_text'])) ?></h3>
                                        <?php if ($answer['instructions']): ?><div class="rv-question-note"><strong>Instruksi</strong><p><?= nl2br(e((string) $answer['instructions'])) ?></p></div><?php endif; ?>
                                        <?php if ($answer['question_image_path']): ?><img class="rv-question-image" src="reviews.php?asset_review_id=<?= (int) $selectedReview['review_id'] ?>&amp;asset_type=question_image&amp;asset_record_id=<?= (int) $answer['question_id'] ?>" alt="<?= e($answer['question_image_alt'] ?: 'Ilustrasi soal') ?>" loading="lazy"><?php endif; ?>
                                        <?php if ($answer['rubric']): ?><div class="rv-rubric"><strong>Rubrik penilaian</strong><p><?= nl2br(e((string) $answer['rubric'])) ?></p></div><?php endif; ?>
                                        <?php if ($answer['reference_file_path']): ?><a class="rv-reference-link" href="reviews.php?asset_review_id=<?= (int) $selectedReview['review_id'] ?>&amp;asset_type=reference&amp;asset_record_id=<?= (int) $answer['question_id'] ?>"><svg class="icon"><use href="#rv-download"></use></svg><span><?= e($answer['reference_file_name'] ?: 'Buka file referensi soal') ?><small><?= e($answer['reference_mime_type'] ?: 'File referensi') ?></small></span></a><?php endif; ?>
                                        <?php if ($answer['question_type'] === 'essay' && trim((string) $answer['answer_text']) !== ''): ?>
                                            <div class="rv-answer-rich"><?= exam_sanitize_rich_html((string) $answer['answer_text']) ?></div>
                                        <?php elseif (trim((string) $answer['answer_text']) !== ''): ?>
                                            <div class="rv-answer-plain"><?= nl2br(e((string) $answer['answer_text'])) ?></div>
                                        <?php endif; ?>
                                        <?php if ($answer['selected_options'] !== []): ?>
                                            <ul class="rv-selected-options"><?php foreach ($answer['selected_options'] as $option): ?><li><span><?= e($option['label']) ?></span><strong><?= e($option['option_key']) ?>.</strong><div><?= nl2br(e($option['option_text'])) ?><?php if ($option['option_image_path'] !== ''): ?><img class="rv-option-image" src="reviews.php?asset_review_id=<?= (int) $selectedReview['review_id'] ?>&amp;asset_type=option_image&amp;asset_record_id=<?= (int) $option['id'] ?>" alt="" loading="lazy"><?php endif; ?><?php if ($option['aspect_code'] !== ''): ?><small>Aspek <?= e($option['aspect_code']) ?></small><?php endif; ?></div></li><?php endforeach; ?></ul>
                                        <?php endif; ?>
                                        <?php if ($answer['files'] !== []): ?>
                                            <div class="rv-files"><strong>Berkas jawaban</strong><?php foreach ($answer['files'] as $file): ?><a href="reviews.php?file_review_id=<?= (int) $selectedReview['review_id'] ?>&amp;file_id=<?= (int) $file['file_id'] ?>"><svg class="icon"><use href="#rv-download"></use></svg><span><?= e($file['original_file_name']) ?><small><?= e(strtoupper((string) $file['mime_type'])) ?> · <?= e(review_page_file_size($file['file_size_bytes'])) ?></small></span></a><?php endforeach; ?></div>
                                        <?php endif; ?>
                                        <?php if (trim((string) $answer['answer_text']) === '' && $answer['selected_options'] === [] && $answer['files'] === []): ?><p class="rv-answer-empty">Tidak ada respons tersimpan pada butir ini.</p><?php endif; ?>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php elseif ($selectedReview['question_id'] !== null): ?>
                    <article class="rv-question-card">
                        <div class="rv-section-kicker">KONTEKS SOAL</div>
                        <h3><?= nl2br(e((string) $selectedReview['question_text'])) ?></h3>
                        <?php if ($selectedReview['instructions']): ?><div class="rv-question-note"><strong>Instruksi</strong><p><?= nl2br(e((string) $selectedReview['instructions'])) ?></p></div><?php endif; ?>
                        <?php if ($selectedReview['rubric']): ?><div class="rv-rubric"><strong>Rubrik penilaian</strong><p><?= nl2br(e((string) $selectedReview['rubric'])) ?></p></div><?php endif; ?>
                        <?php if ($selectedReview['question_image_path']): ?><img class="rv-question-image" src="reviews.php?asset_review_id=<?= (int) $selectedReview['review_id'] ?>&amp;asset_type=question_image&amp;asset_record_id=<?= (int) $selectedReview['question_id'] ?>" alt="<?= e($selectedReview['question_image_alt'] ?: 'Ilustrasi soal') ?>" loading="lazy"><?php endif; ?>
                        <?php if ($selectedReview['reference_file_path']): ?><a class="rv-reference-link" href="reviews.php?asset_review_id=<?= (int) $selectedReview['review_id'] ?>&amp;asset_type=reference&amp;asset_record_id=<?= (int) $selectedReview['question_id'] ?>"><svg class="icon"><use href="#rv-download"></use></svg><span><?= e($selectedReview['reference_file_name'] ?: 'Buka file referensi soal') ?><small><?= e($selectedReview['reference_mime_type'] ?: 'File referensi') ?></small></span></a><?php endif; ?>
                    </article>
                    <article class="rv-answer-card">
                        <div class="rv-section-kicker">JAWABAN PESERTA</div>
                        <?php if ($selectedReview['question_type'] === 'essay'): ?>
                            <div class="rv-answer-rich"><?= exam_sanitize_rich_html((string) ($selectedReview['answer_text'] ?? '')) ?: '<p class="rv-answer-empty">Tidak ada jawaban tertulis.</p>' ?></div>
                        <?php elseif ($selectedReview['answer_text'] !== null && trim((string) $selectedReview['answer_text']) !== ''): ?>
                            <div class="rv-answer-plain"><?= nl2br(e((string) $selectedReview['answer_text'])) ?></div>
                        <?php endif; ?>
                        <?php if ($selectedReview['selected_options'] !== []): ?>
                            <ul class="rv-selected-options"><?php foreach ($selectedReview['selected_options'] as $option): ?><li><span><?= e($option['label']) ?></span><strong><?= e($option['option_key']) ?>.</strong><div><?= nl2br(e($option['option_text'])) ?><?php if ($option['option_image_path'] !== ''): ?><img class="rv-option-image" src="reviews.php?asset_review_id=<?= (int) $selectedReview['review_id'] ?>&amp;asset_type=option_image&amp;asset_record_id=<?= (int) $option['id'] ?>" alt="" loading="lazy"><?php endif; ?><?php if ($option['aspect_code'] !== ''): ?><small>Aspek <?= e($option['aspect_code']) ?></small><?php endif; ?></div></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                        <?php if ($selectedReview['files'] !== []): ?>
                            <div class="rv-files"><strong>Berkas jawaban</strong><?php foreach ($selectedReview['files'] as $file): ?><a href="reviews.php?file_review_id=<?= (int) $selectedReview['review_id'] ?>&amp;file_id=<?= (int) $file['file_id'] ?>"><svg class="icon"><use href="#rv-download"></use></svg><span><?= e($file['original_file_name']) ?><small><?= e(strtoupper((string) $file['mime_type'])) ?> · <?= e(review_page_file_size($file['file_size_bytes'])) ?></small></span></a><?php endforeach; ?></div>
                        <?php elseif ($selectedReview['question_type'] === 'file_upload'): ?>
                            <p class="rv-answer-empty">Tidak ada berkas yang tersimpan pada jawaban ini.</p>
                        <?php endif; ?>
                        <?php if ($selectedReview['answer_text'] === null && $selectedReview['selected_options'] === [] && $selectedReview['files'] === []): ?><p class="rv-answer-empty">Tidak ada respons tersimpan untuk butir ini.</p><?php endif; ?>
                    </article>
                <?php else: ?>
                    <article class="rv-answer-card"><div class="rv-section-kicker">KONSOLIDASI SESI</div><p>Item ini tidak terhubung ke satu jawaban tertentu. Tinjau ringkasan sesi dan simpan rekomendasi akhir assessor.</p></article>
                <?php endif; ?>

                <?php if ($selectedReview['review_type'] === 'papi' && $papiProfileScores !== []): ?>
                    <section class="rv-papi-panel">
                        <div class="rv-section-kicker">PROFIL PAPI KOSTICK · SKOR MENTAH</div>
                        <p>Nilai per aspek dibaca dari skor tersimpan (Most +1, Least −1). Grafik hanya memvisualisasikan nilai mentah dengan rentang simetris dinamis; bukan norma atau interpretasi. Lengkapi review dan interpretasi HR melalui formulir di bawah.</p>
                        <div class="rv-papi-results-layout">
                            <div class="rv-papi-visual">
                                <?= assessment_papi_profile_svg($selectedReview['papi_scores']) ?>
                                <div class="rv-papi-group-legend" aria-label="Kelompok aspek PAPI">
                                    <?php foreach (assessment_papi_group_catalog() as $groupName => $group): ?>
                                        <span><i style="background-color:<?= e($group['color']) ?>"></i><?= e($groupName) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <small class="rv-papi-scale-note">0 berada pada ring tengah; rentang menyesuaikan skor terbesar pada attempt ini. Bukan skala norma.</small>
                            </div>
                            <div class="rv-papi-table-wrap">
                                <table class="rv-papi-table">
                                    <thead><tr><th>AREA</th><th>ASPEK</th><th>SKOR MENTAH</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($papiProfileScores as $papiScore): ?>
                                            <tr>
                                                <td><span class="rv-papi-area"><i style="background-color:<?= e($papiScore['color']) ?>"></i><?= e($papiScore['group']) ?></span></td>
                                                <td><strong><?= e($papiScore['aspect_code']) ?></strong><span><?= e($papiScore['label']) ?></span></td>
                                                <td class="rv-papi-raw-score"><?= e(assessment_papi_format_raw_score($papiScore['raw_score'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($selectedStatus === 'completed'): ?>
                    <section class="rv-saved-review"><div class="rv-section-kicker">HASIL REVIEW TERSIMPAN</div>
                        <?php if ($selectedReview['review_score'] !== null): ?><div class="rv-saved-score"><span>Skor manual</span><strong><?= e(number_format((float) $selectedReview['review_score'], 2, ',', '.')) ?> / <?= e(number_format((float) ($selectedReview['points'] ?? 0), 2, ',', '.')) ?></strong></div><?php endif; ?>
                        <dl><?php if ($selectedReview['recommendation']): ?><dt>Rekomendasi</dt><dd><?= e(review_page_recommendation_label((string) $selectedReview['recommendation'])) ?></dd><?php endif; ?><?php if ($selectedReview['strengths']): ?><dt>Kekuatan</dt><dd><?= nl2br(e($selectedReview['strengths'])) ?></dd><?php endif; ?><?php if ($selectedReview['development_areas']): ?><dt>Area pengembangan</dt><dd><?= nl2br(e($selectedReview['development_areas'])) ?></dd><?php endif; ?><?php if ($selectedReview['notes']): ?><dt>Catatan assessor</dt><dd><?= nl2br(e($selectedReview['notes'])) ?></dd><?php endif; ?></dl>
                        <small>Ditinjau oleh <?= e($selectedReview['reviewer_name'] ?: 'Reviewer #' . (int) $selectedReview['reviewer_user_id']) ?> pada <?= e(review_page_date((string) $selectedReview['reviewed_at'])) ?>. Penilaian tersimpan dan terkunci.</small>
                    </section>
                <?php else: ?>
                    <form class="rv-review-form" method="post" action="<?= e($reviewActionUrl) ?>">
                        <?= app_csrf_input() ?><input type="hidden" name="action" value="save_review"><input type="hidden" name="review_id" value="<?= (int) $selectedReview['review_id'] ?>">
                        <div class="rv-form-heading"><div><span class="rv-section-kicker">FORM PENILAIAN</span><h3><?= $isPapiReview ? 'Review profil peserta' : ($isReview360 ? 'Review hasil 360° per subjek' : ($isManualScore ? 'Skor manual' : 'Review kualitatif')) ?></h3></div><span><?= $isManualScore ? 'Rentang 0–' . number_format((float) $selectedReview['points'], 2, ',', '.') : 'Skor numerik tidak diterapkan' ?></span></div>
                        <?php if ($isManualScore): ?>
                            <label class="ab-field rv-score-field"><span>Skor untuk jawaban <small>wajib · bobot maksimum <?= e(number_format((float) $selectedReview['points'], 2, ',', '.')) ?></small></span><input type="number" name="score" min="0" max="<?= e((string) $selectedReview['points']) ?>" step="0.01" required value="<?= e($inputValue('score', $selectedReview['review_score'])) ?>" placeholder="0,00"></label>
                        <?php else: ?>
                            <div class="rv-score-hint"><svg class="icon"><use href="#rv-info"></use></svg><span><?= $isPapiReview ? 'Review diberikan untuk profil peserta secara utuh, bukan per item. Skor aspek PAPI di bawah adalah skor mentah saja; interpretasi/norma tidak diterapkan otomatis.' : ($isReview360 ? 'Review diberikan sekali untuk seluruh jawaban pada subjek ini, bukan per soal. Berikan catatan internal HR dan rekomendasi.' : 'Item ini ditutup dengan rekomendasi/catatan assessor, bukan skor angka.') ?></span></div>
                        <?php endif; ?>
                        <label class="ab-field"><span>Rekomendasi <small><?= $isParticipantReview ? 'wajib' : 'opsional' ?></small></span><select name="recommendation" <?= $isParticipantReview ? 'required' : '' ?>><option value="">Pilih rekomendasi</option><?php foreach (['recommended' => 'Direkomendasikan', 'consider' => 'Dipertimbangkan', 'not_recommended' => 'Tidak direkomendasikan'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $inputValue('recommendation', $selectedReview['recommendation']) === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <div class="ab-form-grid rv-textarea-grid">
                            <label class="ab-field"><span>Kekuatan <small>opsional</small></span><textarea name="strengths" rows="3" maxlength="10000" placeholder="Catat hal yang dilakukan dengan baik."><?= e($inputValue('strengths', $selectedReview['strengths'])) ?></textarea></label>
                            <label class="ab-field"><span>Area pengembangan <small>opsional</small></span><textarea name="development_areas" rows="3" maxlength="10000" placeholder="Catat area yang perlu ditingkatkan."><?= e($inputValue('development_areas', $selectedReview['development_areas'])) ?></textarea></label>
                        </div>
                        <label class="ab-field"><span><?= $isParticipantReview ? 'Catatan review HR' : 'Catatan assessor' ?> <small><?= $isParticipantReview ? 'wajib · internal HR' : ($isManualScore ? 'opsional' : 'isi catatan atau rekomendasi untuk menutup item') ?></small></span><textarea name="notes" rows="4" maxlength="20000" <?= $isParticipantReview ? 'required' : '' ?> placeholder="Tambahkan ringkasan review dan konteks rekomendasi. Catatan ini hanya untuk HR."><?= e($inputValue('notes', $selectedReview['notes'])) ?></textarea></label>
                        <div class="rv-form-footer"><span><?= $isParticipantReview ? 'Review dan rekomendasi ini disimpan untuk peserta/subjek dan tidak ditampilkan kepada peserta.' : 'Setelah disimpan, item ini terkunci. Skor total sesi dikonsolidasikan setelah seluruh item review peserta selesai.' ?></span><button type="submit" class="ab-primary-button"><svg class="icon"><use href="#rv-check"></use></svg>Simpan &amp; selesaikan review</button></div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
    <footer class="ab-footer"><span>PT Lampung Jasa Utama · E-Assessment</span><span>Catatan penilaian bersifat rahasia dan hanya untuk reviewer berwenang.</span></footer>
</main>
</body>
</html>
