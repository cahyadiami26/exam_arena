<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/report_pdf_service.php';
app_require_auth();
$isHr = app_is_hr();
$isManagementView = !$isHr && app_is_management();
if (!$isHr && !$isManagementView) {
    http_response_code(403);
    exit('Ekspor PDF hanya tersedia untuk HR/Admin dan manajemen.');
}
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    $filters = assessment_report_filters($_GET, $isManagementView);
} catch (DomainException $exception) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($exception->getMessage());
}

if (!app_load_dompdf()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Ekspor PDF belum siap. Jalankan scripts/install_dompdf.sh dan pastikan ekstensi PHP dom, mbstring, serta iconv aktif.');
}

try {
    $packages = assessment_report_package_options();
    $packageLabel = 'Semua paket';
    if ($filters['package_id'] > 0) {
        $packageLabel = 'Paket #' . $filters['package_id'];
        foreach ($packages as $package) {
            if ((int) $package['id'] === $filters['package_id']) {
                $packageLabel = (string) $package['name'] . ' · ' . (string) $package['code'];
                break;
            }
        }
    }

    $scheduleLabel = 'Semua jadwal';
    if ($isHr && $filters['schedule_id'] > 0) {
        $scheduleLabel = 'Jadwal #' . $filters['schedule_id'];
        foreach (assessment_report_schedule_options() as $schedule) {
            if ((int) $schedule['id'] === $filters['schedule_id']) {
                $scheduleLabel = (string) $schedule['code'] . ' · ' . (string) $schedule['name'];
                break;
            }
        }
    }

    if ($isHr) {
        $summary = assessment_report_aggregate($filters, true);
        $rows = assessment_report_hr_attempts($filters, 500);
        $rowLimit = 500;
    } else {
        $summary = assessment_report_aggregate($filters, false);
        $rows = assessment_report_management_packages($filters);
        $rowLimit = 0;
    }

    $html = assessment_report_pdf_html(
        $rows,
        $summary,
        $filters,
        $isManagementView,
        $packageLabel,
        $scheduleLabel,
        $rowLimit
    );
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
    $dompdf->setPaper('A4', $isHr ? 'landscape' : 'portrait');
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

    $prefix = $isHr ? 'eassessment-hasil-hr' : 'eassessment-agregat-manajemen';
    $filename = $prefix . '-' . $filters['from'] . '-sampai-' . $filters['to'] . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
} catch (Throwable $exception) {
    error_log('E-Assessment PDF report export failed: ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('PDF laporan belum dapat dibuat. Pastikan instalasi DOMPDF, ekstensi PHP, dan storage privat tersedia.');
}
