<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/exam_service.php';
require_once dirname(__DIR__) . '/app/report_pdf_service.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$rawAttemptId = $_GET['id'] ?? null;
$attemptId = is_scalar($rawAttemptId) ? filter_var($rawAttemptId, FILTER_VALIDATE_INT) : false;
if ($attemptId === false || $attemptId < 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Sesi asesmen tidak valid.');
}

if (!app_is_authenticated() && !is_array($_SESSION['_eassessment_candidate_access'] ?? null)) {
    app_redirect(app_sso_login_url('/participant_result_pdf.php?id=' . $attemptId));
}

try {
    $attempt = exam_get_attempt_context((int) $attemptId);
} catch (DomainException $exception) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Hasil ini hanya dapat diakses oleh peserta pemilik sesi.');
} catch (Throwable $exception) {
    error_log('E-Assessment participant PDF authorization failed: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Hasil asesmen belum dapat dimuat.');
}

if (!in_array(($attempt['status'] ?? ''), ['pending_review', 'completed'], true)) {
    http_response_code(409);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('PDF hanya tersedia untuk hasil yang telah dikirim dan tercatat.');
}

$papiRawScores = [];
if (($attempt['status'] ?? '') === 'completed') {
    try {
        $papiRawScores = exam_participant_papi_scores((int) $attemptId);
    } catch (Throwable $exception) {
        error_log('E-Assessment participant PAPI PDF data load failed: ' . $exception->getMessage());
    }
}

if (!app_load_dompdf()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Ekspor PDF belum siap. Hubungi administrator aplikasi.');
}

try {
    $participantName = $attempt['participant_type'] === 'candidate'
        ? (string) ($attempt['candidate_name'] ?? 'Kandidat')
        : (string) (app_user()['name'] ?? 'Karyawan');
    $html = assessment_participant_result_pdf_html($attempt, $participantName, $papiRawScores);
    $cacheDirectory = assessment_report_pdf_cache_directory();
    $libraryRoot = dirname(__DIR__) . '/lib/dompdf';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->set('isJavascriptEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('tempDir', $cacheDirectory);
    $options->set('fontCache', $cacheDirectory . '/fonts');
    $options->set('fontDir', $libraryRoot . '/lib/fonts');
    $options->set('chroot', [$libraryRoot]);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $canvas->page_text(
        $canvas->get_width() - 126,
        $canvas->get_height() - 24,
        'Halaman {PAGE_NUM} dari {PAGE_COUNT}',
        $font,
        7,
        [0.35, 0.40, 0.49]
    );

    $dompdf->stream('eassessment-hasil-attempt-' . (int) $attemptId . '.pdf', ['Attachment' => true]);
    exit;
} catch (Throwable $exception) {
    error_log('E-Assessment participant PDF export failed: ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('PDF hasil asesmen belum dapat dibuat. Pastikan instalasi DOMPDF dan storage privat tersedia.');
}
