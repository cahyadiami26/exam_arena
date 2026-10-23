<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/exam_service.php';
require_once dirname(__DIR__) . '/app/papi_profile_service.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$attemptId = (int) ($_GET['id'] ?? 0);

if (isset($_GET['asset'])) {
    try {
        $assetType = (string) $_GET['asset'];
        $recordId = (int) ($_GET['record_id'] ?? 0);
        $asset = exam_private_asset($attemptId, $assetType, $recordId);
        if ($asset === null) {
            http_response_code(404);
            exit('File tidak ditemukan.');
        }

        $mime = strtolower(trim((string) $asset['mime_type']));
        if (!preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D', $mime)) {
            $mime = 'application/octet-stream';
        }
        $safeInlineMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $isQuestionPreview = in_array($assetType, ['question_image', 'option_image'], true);
        $disposition = $assetType === 'answer_file'
            ? 'attachment'
            : (($isQuestionPreview || in_array($mime, $safeInlineMimes, true)) ? 'inline' : 'attachment');
        $filename = preg_replace('/[\r\n"\\]/', '_', (string) $asset['original_file_name']) ?: 'assessment-file';
        $asciiFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'assessment-file';

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . $disposition . '; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . (string) filesize($asset['path']));
        header('Cache-Control: private, no-store');
        readfile($asset['path']);
        exit;
    } catch (DomainException $exception) {
        http_response_code(403);
        exit('Akses file ditolak.');
    } catch (Throwable $exception) {
        error_log('E-Assessment private asset delivery failed: ' . $exception->getMessage());
        http_response_code(404);
        exit('File tidak ditemukan.');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!app_verify_csrf($_POST['_csrf_token'] ?? null)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'Sesi formulir kedaluwarsa. Muat ulang halaman.']);
        exit;
    }

    try {
        $action = (string) $_POST['ajax_action'];
        $progressId = (int) ($_POST['progress_id'] ?? 0);
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $reviewValue = $_POST['review_assignment_id'] ?? '';
        $reviewAssignmentId = $reviewValue !== '' ? (int) $reviewValue : null;

        if ($action === 'save_answer') {
            $payload = json_decode((string) ($_POST['payload'] ?? '{}'), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new DomainException('Format jawaban tidak valid.');
            }
            $result = exam_save_answer($attemptId, $progressId, $questionId, $payload, $reviewAssignmentId);
            echo json_encode(['ok' => true, 'saved_at' => $result['saved_at']], JSON_THROW_ON_ERROR);
            exit;
        }
        if ($action === 'upload_file') {
            $result = exam_save_uploaded_file(
                $attemptId,
                $progressId,
                $questionId,
                $_FILES['answer_file'] ?? [],
                $reviewAssignmentId
            );
            echo json_encode([
                'ok' => true,
                'file_name' => $result['file_name'],
                'file_id' => $result['file_id'],
                'file_size' => $result['file_size'],
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        if ($action === 'complete_module') {
            $result = exam_submit_module($attemptId, $progressId);
            echo json_encode(['ok' => true, 'redirect' => $result['redirect']], JSON_THROW_ON_ERROR);
            exit;
        }
        if ($action === 'expire_module') {
            $result = exam_expire_module($attemptId, $progressId);
            echo json_encode(['ok' => true, 'redirect' => $result['redirect']], JSON_THROW_ON_ERROR);
            exit;
        }

        throw new DomainException('Aksi ujian tidak dikenal.');
    } catch (DomainException $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
        exit;
    } catch (JsonException $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Jawaban tidak dapat dibaca. Muat ulang halaman.'], JSON_THROW_ON_ERROR);
        exit;
    } catch (Throwable $exception) {
        error_log('E-Assessment exam action failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Terjadi gangguan saat menyimpan data. Coba kembali.'], JSON_THROW_ON_ERROR);
        exit;
    }
}

if ($attemptId < 1) {
    app_redirect('lobby.php');
}

$view = null;
$pageError = null;
try {
    $requestedReviewId = isset($_GET['review']) ? (int) $_GET['review'] : null;
    $view = exam_prepare_attempt($attemptId, $requestedReviewId);
} catch (DomainException $exception) {
    http_response_code(403);
    $pageError = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('E-Assessment exam page load failed: ' . $exception->getMessage());
    http_response_code(500);
    $pageError = 'Ruang ujian belum dapat dimuat. Silakan coba kembali atau hubungi pengawas.';
}

$attempt = $view['attempt'] ?? null;
$progress = $view['progress'] ?? null;
$questions = $view['questions'] ?? [];
$reviewTargets = $view['review_targets'] ?? [];
$selectedReviewId = $view['selected_review_id'] ?? null;
$isFinished = $attempt !== null && $attempt['status'] !== 'in_progress';
$employeeUser = app_user();
$participantName = $attempt
    ? ($attempt['participant_type'] === 'candidate' ? (string) ($attempt['candidate_name'] ?? 'Calon karyawan') : (string) $employeeUser['name'])
    : '';
$progressSummary = ['total' => 0, 'completed' => 0];
if ($attempt && $attempt['status'] === 'in_progress') {
    try {
        $summaryStatement = db()->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'completed'), 0) AS completed
             FROM attempt_module_progress WHERE attempt_id = :attempt_id"
        );
        $summaryStatement->execute(['attempt_id' => $attemptId]);
        $progressSummary = $summaryStatement->fetch() ?: $progressSummary;
    } catch (Throwable) {
        // Progress decoration is non-essential; the active test still renders.
    }
}

function exam_question_kind_label(string $type): string
{
    return match ($type) {
        'single_choice' => 'Pilihan ganda',
        'multi_select' => 'Multiple select',
        'forced_choice' => 'Forced choice · Most / Least',
        'essay' => 'Jawaban uraian',
        'file_upload' => 'Unggah hasil praktik',
        default => 'Pertanyaan asesmen',
    };
}

function exam_extension_accept(?string $extensions): string
{
    $parts = array_filter(array_map(static function (string $extension): string {
        $extension = strtolower(ltrim(trim($extension), '.'));
        return preg_match('/^[a-z0-9]{1,10}$/', $extension) ? '.' . $extension : '';
    }, explode(',', (string) $extensions)));
    if ($parts === []) {
        $parts = ['.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.jpg', '.jpeg', '.png'];
    }

    return implode(',', $parts);
}

function exam_asset_url(int $attemptId, string $assetType, int $recordId): string
{
    return 'exam.php?id=' . $attemptId . '&asset=' . rawurlencode($assetType) . '&record_id=' . $recordId;
}

function exam_date_display(?string $value): string
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
    return $date->format('d') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y') . ' · ' . $date->format('H:i') . ' WIB';
}

function exam_render_option_image(int $attemptId, array $option): void
{
    if (empty($option['option_image_path'])) {
        return;
    }
    ?><img class="option-image" src="<?= e(exam_asset_url($attemptId, 'option_image', (int) $option['option_id'])) ?>" alt="" loading="lazy"><?php
}

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f6fa">
    <meta name="csrf-token" content="<?= e(app_csrf_token()) ?>">
    <title><?= $attempt ? e($attempt['schedule_name']) : 'Ruang Ujian' ?> · E-Assessment LJU</title>
    <link rel="stylesheet" href="assets/app.css?v=1">
    <link rel="stylesheet" href="assets/exam.css?v=1">
    <?php if ($attempt && !$pageError): ?><script src="assets/exam.js?v=1" defer></script><?php endif; ?>
</head>
<body class="exam-page">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="x-icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="x-icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
    <symbol id="x-icon-chevron" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
    <symbol id="x-icon-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></symbol>
    <symbol id="x-icon-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="x-icon-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.6 9a2.5 2.5 0 1 1 4.1 1.9c-1 .8-1.7 1.1-1.7 2.6M12 17h.01"/></symbol>
    <symbol id="x-icon-book" viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></symbol>
    <symbol id="x-icon-warning" viewBox="0 0 24 24"><path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0ZM12 9v4M12 17h.01"/></symbol>
    <symbol id="x-icon-send" viewBox="0 0 24 24"><path d="m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13"/></symbol>
    <symbol id="x-icon-camera" viewBox="0 0 24 24"><path d="M14 5h-4L8 8H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-3l-2-3Z"/><circle cx="12" cy="13" r="3"/></symbol>
    <symbol id="x-icon-list" viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></symbol>
    <symbol id="x-icon-save" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></symbol>
    <symbol id="x-icon-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></symbol>
    <symbol id="x-icon-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="x-icon-bold" viewBox="0 0 24 24"><path d="M6 12h8a4 4 0 0 0 0-8H6v16h9a4 4 0 0 0 0-8H6Z"/></symbol>
    <symbol id="x-icon-italic" viewBox="0 0 24 24"><path d="M19 4h-9M14 20H5M15 4 9 20"/></symbol>
    <symbol id="x-icon-list-ul" viewBox="0 0 24 24"><path d="M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01"/></symbol>
</svg>

<?php if ($pageError): ?>
    <main class="exam-error-page"><section class="exam-error-card"><span class="exam-error-icon"><svg class="icon"><use href="#x-icon-warning"></use></svg></span><h1>Ruang ujian tidak tersedia</h1><p><?= e($pageError) ?></p><a class="button exam-primary-button" href="lobby.php">Kembali ke Lobby Ujian</a></section></main>
<?php elseif (!$attempt): ?>
    <main class="exam-error-page"><section class="exam-error-card"><h1>Sesi ujian tidak ditemukan</h1><p>Silakan kembali ke Lobby Ujian untuk melihat penugasan Anda.</p><a class="button exam-primary-button" href="lobby.php">Kembali ke Lobby Ujian</a></section></main>
<?php elseif ($isFinished): ?>
    <?php
    $reviewCount = 0;
    try {
        $reviewCountStatement = db()->prepare("SELECT COUNT(*) FROM assessment_reviews WHERE attempt_id = :attempt_id AND status IN ('pending', 'in_progress')");
        $reviewCountStatement->execute(['attempt_id' => $attemptId]);
        $reviewCount = (int) $reviewCountStatement->fetchColumn();
    } catch (Throwable) {
        $reviewCount = 0;
    }
    $papiRawScores = [];
    $papiProfileScores = [];
    if ($attempt['status'] === 'completed') {
        try {
            $papiRawScores = exam_participant_papi_scores($attemptId);
            $papiProfileScores = assessment_papi_profile_scores($papiRawScores);
        } catch (Throwable $exception) {
            error_log('E-Assessment participant PAPI result load failed: ' . $exception->getMessage());
        }
    }
    ?>
    <header class="exam-topbar result-topbar"><a class="exam-brand" href="lobby.php"><span class="exam-logo-box"><img src="https://dms.lju.co.id/assets/img/logo.png" alt="LJU" data-brand-logo><span class="brand-logo-fallback">LJU</span></span><span><strong>E-ASSESSMENT</strong><small>PT Lampung Jasa Utama</small></span></a><a class="exam-exit-link" href="lobby.php">Keluar ke Lobby <svg class="icon"><use href="#x-icon-arrow"></use></svg></a></header>
    <main class="exam-result-page">
        <section class="result-card<?= $papiProfileScores !== [] ? ' has-papi-profile' : '' ?>">
            <div class="result-success-icon"><svg class="icon"><use href="#x-icon-check"></use></svg></div>
            <span class="result-kicker">SESI ASESMEN SELESAI</span>
            <h1>Terima kasih, <?= e($participantName) ?>.</h1>
            <p class="result-subtitle">Jawaban Anda pada paket <strong><?= e($attempt['package_name']) ?></strong> telah tersimpan.</p>
            <div class="result-score-grid <?= $attempt['status'] === 'completed' ? 'is-final' : '' ?>">
                <div><span>SKOR OBJEKTIF</span><strong><?= number_format((float) ($attempt['objective_score'] ?? 0), 2, ',', '.') ?></strong></div>
                <?php if ($attempt['status'] === 'completed'): ?>
                    <div><span>SKOR MANUAL</span><strong><?= $attempt['manual_score'] !== null ? number_format((float) $attempt['manual_score'], 2, ',', '.') : '—' ?></strong></div>
                    <div><span>SKOR AKHIR</span><strong><?= number_format((float) ($attempt['total_score'] ?? $attempt['objective_score'] ?? 0), 2, ',', '.') ?></strong></div>
                <?php else: ?>
                    <div><span>STATUS HASIL</span><strong class="result-status is-review">Menunggu review HR</strong></div>
                <?php endif; ?>
            </div>
            <?php if ($attempt['status'] === 'pending_review'): ?>
                <div class="result-review-note"><svg class="icon"><use href="#x-icon-shield"></use></svg><span><?= $reviewCount > 0 ? $reviewCount . ' item esai, praktik, PAPI, psikometri, atau 360° sedang menunggu review HR.' : 'Hasil akhir akan tersedia setelah proses review HR selesai.' ?> Skor objektif ditampilkan sebagai ringkasan sementara.</span></div>
            <?php else: ?>
                <div class="result-review-note result-complete-note"><svg class="icon"><use href="#x-icon-check"></use></svg><span>Proses review HR selesai. Skor akhir mencakup penilaian objektif dan manual bila ada, serta dibandingkan dengan nilai ambang paket apabila passing grade ditetapkan.</span></div>
            <?php endif; ?>
            <?php if ($attempt['status'] === 'completed' && $papiProfileScores !== []): ?>
                <section class="participant-papi-profile" aria-labelledby="participant-papi-heading">
                    <div class="participant-papi-heading">
                        <span>HASIL PAPI KOSTICK</span>
                        <h2 id="participant-papi-heading">Profil skor mentah per aspek</h2>
                        <p>Review PAPI HR telah selesai. Tampilan ini memuat skor mentah saja; catatan dan rekomendasi internal HR tidak ditampilkan.</p>
                    </div>
                    <div class="participant-papi-layout">
                        <div class="participant-papi-chart">
                            <?= assessment_papi_profile_svg($papiRawScores) ?>
                            <div class="participant-papi-groups" aria-label="Kelompok aspek PAPI">
                                <?php foreach (assessment_papi_group_catalog() as $groupName => $group): ?>
                                    <span><i style="background-color:<?= e($group['color']) ?>"></i><?= e($groupName) ?></span>
                                <?php endforeach; ?>
                                <?php if (in_array('Lainnya', array_column($papiProfileScores, 'group'), true)): ?>
                                    <span><i style="background-color:#8a95a7"></i>Lainnya</span>
                                <?php endif; ?>
                            </div>
                            <small>Rentang grafik dinamis dan bertanda; 0 berada pada ring tengah. Grafik bukan skala norma atau interpretasi.</small>
                        </div>
                        <div class="participant-papi-table-wrap">
                            <table class="participant-papi-table">
                                <thead><tr><th>AREA</th><th>ASPEK</th><th>SKOR MENTAH</th></tr></thead>
                                <tbody>
                                    <?php foreach ($papiProfileScores as $papiScore): ?>
                                        <tr>
                                            <td><span><i style="background-color:<?= e($papiScore['color']) ?>"></i><?= e($papiScore['group']) ?></span></td>
                                            <td><strong><?= e($papiScore['aspect_code']) ?></strong><small><?= e($papiScore['label']) ?></small></td>
                                            <td><?= e(assessment_papi_format_raw_score($papiScore['raw_score'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
            <div class="result-actions">
                <?php if (in_array($attempt['status'], ['pending_review', 'completed'], true)): ?>
                    <a class="button exam-primary-button result-pdf-button" href="participant_result_pdf.php?id=<?= $attemptId ?>"><svg class="icon"><use href="#x-icon-download"></use></svg>Unduh ringkasan PDF</a>
                <?php endif; ?>
                <a class="button exam-primary-button result-lobby-button" href="lobby.php">Kembali ke Lobby Ujian <svg class="icon"><use href="#x-icon-arrow"></use></svg></a>
            </div>
            <small class="result-confidential">PDF hanya memuat hasil attempt Anda; bagikan secara bijak.</small>
        </section>
    </main>
<?php else: ?>
    <?php
    $moduleOrdinal = 1;
    $allProgressStatement = db()->prepare(
        "SELECT amp.id, amp.status, pm.sort_order, m.name
         FROM attempt_module_progress amp
         INNER JOIN package_modules pm ON pm.id = amp.package_module_id
         INNER JOIN assessment_modules m ON m.id = pm.module_id
         WHERE amp.attempt_id = :attempt_id ORDER BY pm.sort_order"
    );
    $allProgressStatement->execute(['attempt_id' => $attemptId]);
    $allProgressRows = $allProgressStatement->fetchAll();
    foreach ($allProgressRows as $index => $moduleRow) {
        if ((int) $moduleRow['id'] === (int) $progress['progress_id']) {
            $moduleOrdinal = $index + 1;
            break;
        }
    }
    $questionCount = count($questions);
    ?>
    <header class="exam-topbar">
        <a class="exam-brand" href="lobby.php" aria-label="E-Assessment LJU"><span class="exam-logo-box"><img src="https://dms.lju.co.id/assets/img/logo.png" alt="LJU" data-brand-logo><span class="brand-logo-fallback">LJU</span></span><span><strong>E-ASSESSMENT</strong><small>PT Lampung Jasa Utama</small></span></a>
        <div class="exam-session-heading"><span>PAKET UJIAN</span><strong><?= e($attempt['schedule_name']) ?></strong></div>
        <div class="exam-top-user"><span class="exam-top-avatar"><?= e(app_user_initials($participantName)) ?></span><span><?= e($participantName) ?></span></div>
    </header>

    <main class="exam-layout" data-exam-root
          data-attempt-id="<?= $attemptId ?>"
          data-progress-id="<?= (int) $progress['progress_id'] ?>"
          data-module-kind="<?= e($progress['module_kind']) ?>"
          data-review-id="<?= $selectedReviewId ? (int) $selectedReviewId : '' ?>"
          data-remaining-seconds="<?= (int) $progress['remaining_seconds'] ?>"
          data-question-count="<?= $questionCount ?>">
        <aside class="exam-sidebar">
            <div class="sidebar-attempt-meta"><span class="exam-code-label"><?= e($attempt['schedule_code']) ?></span><h2><?= e($attempt['package_name']) ?></h2><p>Peserta: <?= e($participantName) ?></p></div>
            <div class="module-current-card"><span>MODUL <?= $moduleOrdinal ?> DARI <?= (int) $progressSummary['total'] ?></span><strong><?= e($progress['module_name']) ?></strong><small><?= e(exam_date_display((string) $progress['started_at'])) ?></small></div>
            <?php if ($progress['module_kind'] === 'review_360'): ?>
                <div class="review-target-box">
                    <label for="review-target-select">Karyawan yang Anda nilai</label>
                    <?php if (!$reviewTargets): ?><p class="review-target-empty">Belum ada rekan yang ditugaskan untuk dinilai.</p><?php else: ?>
                        <select id="review-target-select" data-review-target>
                            <?php foreach ($reviewTargets as $target): ?>
                                <option value="<?= (int) $target['id'] ?>" <?= (int) $target['id'] === (int) $selectedReviewId ? 'selected' : '' ?>><?= e($target['subject_name']) ?><?= $target['subject_division'] ? ' · ' . e($target['subject_division']) : '' ?> · <?= (int) $target['answered_count'] ?>/<?= $questionCount ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Jawaban setiap rekan disimpan terpisah.</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="question-nav-heading"><strong>Navigasi Soal</strong><span><?= $questionCount ?> butir</span></div>
            <nav class="question-nav" aria-label="Navigasi soal">
                <?php foreach ($questions as $index => $question): ?>
                    <?php
                    $payload = $question['answer']['payload'] ?? [];
                    $filled = !empty($payload['selected_option_id']) || !empty($payload['selected_option_ids'])
                        || (!empty($payload['most_option_id']) && !empty($payload['least_option_id']))
                        || trim(strip_tags((string) ($question['answer']['text'] ?? ''))) !== ''
                        || !empty($question['files']);
                    ?>
                    <button type="button" class="question-nav-button <?= $index === 0 ? 'is-current' : '' ?> <?= $filled ? 'is-answered' : '' ?>" data-question-nav="<?= (int) $index ?>" aria-label="Soal <?= (int) $index + 1 ?><?= $filled ? ', sudah dijawab' : ', belum dijawab' ?>"><span><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><i></i></button>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-save-state"><span class="save-state-dot"></span><span data-save-status>Tersimpan otomatis</span></div>
            <div class="sidebar-help"><svg class="icon"><use href="#x-icon-help"></use></svg><span>Kendala teknis? Hubungi pengawas ujian.</span></div>
        </aside>

        <section class="exam-main-column">
            <div class="exam-main-topline"><div class="exam-breadcrumb"><span><?= e($attempt['schedule_code']) ?></span><svg class="icon"><use href="#x-icon-chevron"></use></svg><strong><?= e($progress['module_name']) ?></strong></div><div class="exam-timer" data-exam-timer><svg class="icon"><use href="#x-icon-clock"></use></svg><span>SISA WAKTU</span><strong data-timer-text>--:--</strong></div></div>
            <?php if ($progress['module_description']): ?><p class="module-introduction"><?= nl2br(e($progress['module_description'])) ?></p><?php endif; ?>
            <?php if ($progress['module_kind'] === 'review_360' && $reviewTargets): ?>
                <?php $selectedTargetName = ''; foreach ($reviewTargets as $target) { if ((int) $target['id'] === (int) $selectedReviewId) { $selectedTargetName = (string) $target['subject_name']; break; } } ?>
                <div class="review-target-banner"><svg class="icon"><use href="#x-icon-shield"></use></svg><span>Anda sedang menilai <strong><?= e($selectedTargetName) ?></strong>. Berikan penilaian profesional dan konstruktif.</span></div>
            <?php endif; ?>

            <div class="question-stack">
                <?php if (!$questions): ?>
                    <div class="question-empty"><svg class="icon"><use href="#x-icon-warning"></use></svg><h2>Modul belum memiliki soal</h2><p>Hubungi HR sebelum melanjutkan. Jangan menutup sesi ujian tanpa instruksi pengawas.</p></div>
                <?php endif; ?>
                <?php foreach ($questions as $index => $question): ?>
                    <?php
                    $questionPayload = $question['answer']['payload'] ?? [];
                    $selectedSingle = (int) ($questionPayload['selected_option_id'] ?? 0);
                    $selectedMultiple = array_map('intval', is_array($questionPayload['selected_option_ids'] ?? null) ? $questionPayload['selected_option_ids'] : []);
                    $selectedMost = (int) ($questionPayload['most_option_id'] ?? 0);
                    $selectedLeast = (int) ($questionPayload['least_option_id'] ?? 0);
                    $questionImage = !empty($question['question_image_path']);
                    $referenceMime = strtolower((string) ($question['reference_mime_type'] ?? ''));
                    $referenceInline = in_array($referenceMime, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
                    ?>
                    <article class="question-card <?= $index === 0 ? 'is-current' : '' ?>" data-question-card data-question-id="<?= (int) $question['question_id'] ?>" data-question-type="<?= e($question['question_type']) ?>" data-required="<?= (int) $question['is_required'] ?>">
                        <div class="question-card-heading"><span class="question-number">SOAL <?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><span class="question-kind"><?= e(exam_question_kind_label((string) $question['question_type'])) ?></span><?php if (!$question['is_required']): ?><span class="optional-label">Opsional</span><?php endif; ?></div>
                        <div class="question-prompt"><div class="question-text"><?= nl2br(e($question['question_text'])) ?></div>
                            <?php if ($questionImage): ?><figure class="question-image-wrap"><img src="<?= e(exam_asset_url($attemptId, 'question_image', (int) $question['question_id'])) ?>" alt="<?= e($question['question_image_alt'] ?: 'Ilustrasi soal') ?>" loading="lazy"></figure><?php endif; ?>
                        </div>
                        <?php if ($question['instructions']): ?><div class="question-instruction"><svg class="icon"><use href="#x-icon-book"></use></svg><span><?= nl2br(e($question['instructions'])) ?></span></div><?php endif; ?>

                        <?php if (in_array($question['question_type'], ['single_choice', 'multi_select'], true)): ?>
                            <div class="choice-list <?= $question['question_type'] === 'multi_select' ? 'is-multiple' : '' ?>">
                                <?php foreach ($question['options'] as $optionIndex => $option): ?>
                                    <?php $optionId = (int) $option['option_id']; $checked = $question['question_type'] === 'single_choice' ? $selectedSingle === $optionId : in_array($optionId, $selectedMultiple, true); ?>
                                    <label class="choice-option <?= $checked ? 'is-selected' : '' ?>">
                                        <input type="<?= $question['question_type'] === 'single_choice' ? 'radio' : 'checkbox' ?>" name="answer-<?= (int) $question['question_id'] ?><?= $question['question_type'] === 'multi_select' ? '[]' : '' ?>" value="<?= $optionId ?>" data-answer-control <?= $checked ? 'checked' : '' ?>>
                                        <span class="choice-marker"><?= $question['question_type'] === 'single_choice' ? '' : '<svg class="icon"><use href="#x-icon-check"></use></svg>' ?></span>
                                        <span class="choice-letter"><?= e($option['option_key'] ?: chr(65 + $optionIndex)) ?></span>
                                        <span class="choice-copy"><span><?= nl2br(e($option['option_text'])) ?></span><?php exam_render_option_image($attemptId, $option); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($question['question_type'] === 'forced_choice'): ?>
                            <div class="forced-choice-grid">
                                <label><span><b class="forced-most-mark">M</b><span><strong>Paling menggambarkan saya</strong><small>Most</small></span></span><select data-forced-most data-answer-control><option value="">Pilih pernyataan…</option><?php foreach ($question['options'] as $option): ?><option value="<?= (int) $option['option_id'] ?>" <?= $selectedMost === (int) $option['option_id'] ? 'selected' : '' ?>><?= e($option['option_key'] . '. ' . $option['option_text']) ?></option><?php endforeach; ?></select></label>
                                <label><span><b class="forced-least-mark">L</b><span><strong>Paling tidak menggambarkan saya</strong><small>Least</small></span></span><select data-forced-least data-answer-control><option value="">Pilih pernyataan…</option><?php foreach ($question['options'] as $option): ?><option value="<?= (int) $option['option_id'] ?>" <?= $selectedLeast === (int) $option['option_id'] ? 'selected' : '' ?>><?= e($option['option_key'] . '. ' . $option['option_text']) ?></option><?php endforeach; ?></select></label>
                            </div>
                            <p class="forced-help">Pilihan Most dan Least harus berbeda. Tidak ada jawaban benar atau salah.</p>
                        <?php elseif ($question['question_type'] === 'essay'): ?>
                            <div class="rich-editor-wrap"><div class="rich-toolbar" role="toolbar" aria-label="Format jawaban"><button type="button" data-command="bold" aria-label="Tebal"><svg class="icon"><use href="#x-icon-bold"></use></svg></button><button type="button" data-command="italic" aria-label="Miring"><svg class="icon"><use href="#x-icon-italic"></use></svg></button><button type="button" data-command="insertUnorderedList" aria-label="Daftar"><svg class="icon"><use href="#x-icon-list-ul"></use></svg></button></div><div class="rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-rich-editor data-answer-control data-placeholder="Tuliskan jawaban Anda di sini…"><?= exam_sanitize_rich_html((string) $question['answer']['text']) ?></div></div>
                        <?php elseif ($question['question_type'] === 'file_upload'): ?>
                            <div class="upload-instructions"><svg class="icon"><use href="#x-icon-file"></use></svg><span><?= e($question['instructions'] ?: 'Unggah file hasil pengerjaan Anda sesuai instruksi.') ?></span></div>
                            <?php if ($question['reference_file_path']): ?>
                                <div class="reference-file-card"><div class="reference-file-icon"><svg class="icon"><use href="#x-icon-file"></use></svg></div><div><strong><?= e($question['reference_file_name'] ?: 'Contoh hasil / berkas acuan') ?></strong><small>File contoh dari HR</small></div><a href="<?= e(exam_asset_url($attemptId, 'reference', (int) $question['question_id'])) ?>" target="_blank" rel="noopener" aria-label="Buka file contoh"><svg class="icon"><use href="#x-icon-arrow"></use></svg></a></div>
                                <?php if ($referenceInline && $referenceMime === 'application/pdf'): ?><iframe class="reference-preview" title="Pratinjau contoh PDF" src="<?= e(exam_asset_url($attemptId, 'reference', (int) $question['question_id'])) ?>"></iframe><?php elseif (in_array($referenceMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)): ?><img class="reference-image-preview" src="<?= e(exam_asset_url($attemptId, 'reference', (int) $question['question_id'])) ?>" alt="Pratinjau file contoh" loading="lazy"><?php endif; ?>
                            <?php endif; ?>
                            <div class="upload-control"><input type="file" data-upload-input data-accept="<?= e(exam_extension_accept($question['allowed_extensions'])) ?>" accept="<?= e(exam_extension_accept($question['allowed_extensions'])) ?>"><button type="button" class="button upload-button" data-upload-button><svg class="icon"><use href="#x-icon-send"></use></svg>Unggah Berkas</button><span data-upload-status><?= $question['files'] ? 'File tersimpan' : 'PDF, Office, atau gambar sesuai instruksi' ?></span></div>
                            <div class="uploaded-file-list" data-uploaded-files><?php foreach ($question['files'] as $uploadedFile): ?><a href="exam.php?id=<?= $attemptId ?>&amp;asset=answer_file&amp;record_id=<?= (int) $uploadedFile['id'] ?>" class="uploaded-file" download><svg class="icon"><use href="#x-icon-file"></use></svg><?= e($uploadedFile['original_file_name']) ?><small><?= number_format(((int) $uploadedFile['file_size_bytes']) / 1024, 0, ',', '.') ?> KB</small></a><?php endforeach; ?></div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="exam-navigation-actions"><button type="button" class="button button-nav-secondary" data-question-prev disabled>Kembali</button><span class="question-position" data-question-position>1 / <?= max(1, $questionCount) ?></span><?php if ($questionCount > 1): ?><button type="button" class="button button-nav-primary" data-question-next>Soal berikutnya <svg class="icon"><use href="#x-icon-arrow"></use></svg></button><?php else: ?><button type="button" class="button button-nav-primary" data-finish-module>Selesaikan Modul <svg class="icon"><use href="#x-icon-check"></use></svg></button><?php endif; ?></div>
            <?php if ($questionCount > 1): ?><button type="button" class="button button-finish-module" data-finish-module>Selesaikan Modul &amp; Lanjut <svg class="icon"><use href="#x-icon-arrow"></use></svg></button><?php endif; ?>
        </section>

        <aside class="exam-rightbar">
            <div class="rightbar-card progress-card"><div class="rightbar-title"><strong>Progres modul</strong><span><?= (int) $progressSummary['completed'] + 1 ?>/<?= (int) $progressSummary['total'] ?></span></div><div class="module-progress-track"><i style="width: <?= (int) $progressSummary['total'] > 0 ? e((string) round(((int) $progressSummary['completed'] / (int) $progressSummary['total']) * 100)) : '0' ?>%"></i></div><p><?= (int) $progressSummary['completed'] ?> modul selesai · <?= (int) $progress['duration_minutes'] ?> menit dialokasikan untuk modul ini</p></div>
            <div class="rightbar-card saved-card"><span class="saved-icon"><svg class="icon"><use href="#x-icon-save"></use></svg></span><div><strong data-save-status>Jawaban tersimpan otomatis</strong><small>Pastikan koneksi Anda tetap aktif.</small></div></div>
            <div class="rightbar-card integrity-card"><div class="rightbar-title"><span><svg class="icon"><use href="#x-icon-shield"></use></svg>Integritas asesmen</span></div><ul><li>Jangan menutup tab saat waktu berjalan.</li><li>Setiap modul memiliki timer masing-masing.</li><li>Ketika waktu habis, modul akan dikirim otomatis.</li></ul></div>
            <div class="rightbar-support"><svg class="icon"><use href="#x-icon-help"></use></svg><span>Jika koneksi terputus, buka kembali tautan ujian yang sama. Jawaban tersimpan berkala.</span></div>
        </aside>
    </main>
    <div class="exam-toast" id="exam-toast" role="status" aria-live="polite"></div>
<?php endif; ?>
</body>
</html>
