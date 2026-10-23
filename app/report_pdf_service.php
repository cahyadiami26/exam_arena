<?php
declare(strict_types=1);

require_once __DIR__ . '/report_service.php';
require_once __DIR__ . '/dompdf_loader.php';

function assessment_report_pdf_package_type(string $type): string
{
    return match ($type) {
        'internal' => 'Internal',
        'review_360' => 'Evaluasi 360 derajat',
        default => 'Rekrutmen',
    };
}

function assessment_report_pdf_score(mixed $value): string
{
    return $value === null ? '—' : number_format((float) $value, 2, ',', '.');
}

function assessment_report_pdf_result_label(array $row): string
{
    if (($row['attempt_status'] ?? '') === 'pending_review') {
        return 'Menunggu review';
    }
    if (($row['total_score'] ?? null) === null) {
        return ($row['attempt_status'] ?? '') === 'completed' ? 'Selesai, skor tidak tersedia' : 'Belum final';
    }
    if (($row['passing_score'] ?? null) === null) {
        return 'Selesai, tanpa ambang';
    }

    return (float) $row['total_score'] >= (float) $row['passing_score'] ? 'Lulus' : 'Belum lulus';
}

function assessment_report_pdf_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('d/m/Y H:i') . ' WIB';
    } catch (Throwable) {
        return '—';
    }
}

function assessment_report_pdf_review_type_label(string $type): string
{
    return match ($type) {
        'essay' => 'Esai',
        'practical' => 'Praktik',
        'psychometric' => 'Psikometrik',
        'papi' => 'PAPI Kostick',
        'review_360' => 'Review 360°',
        'overall' => 'Review keseluruhan',
        default => 'Review HR',
    };
}

function assessment_report_pdf_review_status_label(string $status): string
{
    return match ($status) {
        'completed' => 'Selesai',
        'in_progress' => 'Dalam proses',
        'pending' => 'Menunggu review',
        'not_created' => 'Belum ada review',
        default => 'Status tidak diketahui',
    };
}

function assessment_report_pdf_recommendation_label(?string $recommendation): string
{
    return match ($recommendation) {
        'recommended' => 'Direkomendasikan',
        'consider' => 'Dipertimbangkan',
        'not_recommended' => 'Tidak direkomendasikan',
        default => '—',
    };
}

function assessment_report_pdf_answer_text(array $answer): string
{
    $parts = [];
    $plainText = trim(html_entity_decode(strip_tags((string) ($answer['answer_text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($plainText !== '') {
        $parts[] = $plainText;
    }
    foreach (($answer['selected_options'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }
        $optionText = trim((string) ($option['option_text'] ?? ''));
        if ($optionText !== '') {
            $parts[] = trim((string) ($option['label'] ?? 'Dipilih')) . ': ' . $optionText;
        }
    }
    foreach (($answer['files'] ?? []) as $file) {
        if (is_array($file) && trim((string) ($file['original_file_name'] ?? '')) !== '') {
            $parts[] = 'File: ' . (string) $file['original_file_name'];
        }
    }

    return $parts === [] ? '—' : implode("\n", $parts);
}

/** Render a detailed per-attempt card; called only on the HR/Admin PDF branch. */
function assessment_report_pdf_hr_attempt_section(array $row): string
{
    $escape = static fn (mixed $value): string => e($value);
    $participantType = ($row['participant_type'] ?? '') === 'employee' ? 'Karyawan SSO' : 'Kandidat';
    $identity = array_filter([
        $participantType,
        (string) ($row['participant_email'] ?? ''),
        ($row['participant_division'] ?? '') !== '' ? 'Bidang ' . $row['participant_division'] : '',
        ($row['participant_department'] ?? '') !== '' ? 'Divisi ' . $row['participant_department'] : '',
    ], static fn (string $value): bool => $value !== '');
    $resultLabel = assessment_report_pdf_result_label($row);
    $submittedAt = (string) (($row['submitted_at'] ?? '') ?: ($row['started_at'] ?? ''));
    $html = '<section class="attempt-card"><div class="attempt-heading"><div><h2>' . $escape($row['participant_name'] ?? 'Peserta') . '</h2>'
        . '<p>' . $escape(implode(' · ', $identity)) . '</p></div><span class="attempt-number">Attempt #' . (int) ($row['attempt_number'] ?? 1) . '</span></div>'
        . '<div class="attempt-meta"><span><b>Jadwal</b>' . $escape((string) ($row['schedule_code'] ?? '') . ' · ' . (string) ($row['schedule_name'] ?? '')) . '</span>'
        . '<span><b>Paket</b>' . $escape((string) ($row['package_name'] ?? '') . ' · ' . assessment_report_pdf_package_type((string) ($row['package_type'] ?? ''))) . '</span>'
        . '<span><b>Dikirim</b>' . $escape(assessment_report_pdf_date($submittedAt !== '' ? $submittedAt : null)) . '</span>'
        . '<span><b>Hasil</b>' . $escape($resultLabel) . '</span></div>'
        . '<table class="attempt-scores"><tr><td><small>SKOR OBJEKTIF</small><strong>' . $escape(assessment_report_pdf_score($row['objective_score'] ?? null)) . '</strong></td>'
        . '<td><small>SKOR MANUAL</small><strong>' . $escape(assessment_report_pdf_score($row['manual_score'] ?? null)) . '</strong></td>'
        . '<td><small>SKOR AKHIR</small><strong>' . $escape(assessment_report_pdf_score($row['total_score'] ?? null)) . '</strong></td>';
    if (($row['passing_score'] ?? null) !== null) {
        $html .= '<td><small>NILAI AMBANG</small><strong>' . $escape(assessment_report_pdf_score($row['passing_score'])) . '</strong></td>';
    }
    $html .= '</tr></table><h3 class="section-heading">Skor autograde per modul</h3>';
    if (($row['autograde_modules'] ?? []) === []) {
        $html .= '<p class="muted">Tidak ada skor autograde tersimpan untuk attempt ini.</p>';
    } else {
        $html .= '<table class="detail-table"><thead><tr><th>Modul</th><th>Skor mentah</th><th>Item dinilai</th><th>Persentase</th></tr></thead><tbody>';
        foreach ($row['autograde_modules'] as $moduleScore) {
            $percentage = $moduleScore['percentage'] === null
                ? '—'
                : number_format((float) $moduleScore['percentage'], 1, ',', '.') . '%';
            $html .= '<tr><td><strong>' . $escape($moduleScore['module_name']) . '</strong><span class="small">' . $escape($moduleScore['module_category']) . '</span></td>'
                . '<td class="score">' . $escape(assessment_report_pdf_score($moduleScore['earned_score'])) . ' / ' . $escape(assessment_report_pdf_score($moduleScore['possible_score'])) . '</td>'
                . '<td>' . (int) $moduleScore['scored_item_count'] . '</td><td>' . $escape($percentage) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }

    $html .= '<h3 class="section-heading">Review HR dan hasil penilaian <span class="internal-label">Informasi internal HR/Admin</span></h3>';
    if (($row['hr_reviews'] ?? []) === []) {
        $html .= '<p class="muted">Belum ada rekaman review HR untuk attempt ini.</p>';
    } else {
        foreach ($row['hr_reviews'] as $review) {
            $status = (string) ($review['review_status'] ?? '');
            $reviewType = (string) ($review['review_type'] ?? '');
            $html .= '<article class="review-card"><div class="review-heading"><div><strong>' . $escape(assessment_report_pdf_review_type_label($reviewType)) . '</strong>'
                . (!empty($review['subject_name']) ? '<span>Subjek: ' . $escape($review['subject_name']) . '</span>' : '')
                . '</div><span class="review-status status-' . $escape($status) . '">' . $escape(assessment_report_pdf_review_status_label($status)) . '</span></div>'
                . '<p class="review-meta">Reviewer: ' . $escape($review['reviewer_name'] ?? '—') . ' · ' . $escape(assessment_report_pdf_date($review['reviewed_at'] ?? null)) . '</p>';
            if (($review['score'] ?? null) !== null || ($review['recommendation'] ?? null) !== null) {
                $html .= '<div class="review-result">';
                if (($review['score'] ?? null) !== null) {
                    $html .= '<span>Skor review <strong>' . $escape(assessment_report_pdf_score($review['score'])) . '</strong></span>';
                }
                if (($review['recommendation'] ?? null) !== null) {
                    $html .= '<span>Rekomendasi <strong>' . $escape(assessment_report_pdf_recommendation_label((string) $review['recommendation'])) . '</strong></span>';
                }
                $html .= '</div>';
            }
            foreach ([['Kekuatan', $review['strengths'] ?? ''], ['Area pengembangan', $review['development_areas'] ?? ''], ['Catatan internal HR', $review['notes'] ?? '']] as [$label, $value]) {
                if (trim((string) $value) !== '') {
                    $html .= '<div class="review-note"><b>' . $escape($label) . '</b><p>' . nl2br($escape($value)) . '</p></div>';
                }
            }
            foreach (($review['records'] ?? []) as $record) {
                if ($reviewType === 'review_360' && !empty($review['responses'])) {
                    continue;
                }
                $question = trim(strip_tags((string) ($record['question_text'] ?? '')));
                $answerText = assessment_report_pdf_answer_text($record);
                if ($question === '' && $answerText === '—') {
                    continue;
                }
                $html .= '<div class="review-answer"><b>' . $escape($question !== '' ? $question : 'Jawaban peserta') . '</b><p>' . nl2br($escape($answerText)) . '</p>';
                if ((int) ($review['record_count'] ?? 0) > 1 && (($record['score'] ?? null) !== null || trim((string) ($record['notes'] ?? '')) !== '')) {
                    $html .= '<small>' . (($record['score'] ?? null) !== null ? 'Skor ' . $escape(assessment_report_pdf_score($record['score'])) . ' · ' : '') . $escape((string) ($record['notes'] ?? '')) . '</small>';
                }
                $html .= '</div>';
            }
            if ($reviewType === 'review_360' && !empty($review['responses'])) {
                $html .= '<div class="review-response-list"><strong>Jawaban evaluasi 360°</strong><table class="detail-table"><thead><tr><th>Pertanyaan</th><th>Jawaban</th></tr></thead><tbody>';
                foreach ($review['responses'] as $response) {
                    $html .= '<tr><td>' . $escape(trim(strip_tags((string) ($response['question_text'] ?? '')))) . '</td><td>'
                        . nl2br($escape(assessment_report_pdf_answer_text($response))) . '</td></tr>';
                }
                $html .= '</tbody></table></div>';
            }
            $html .= '</article>';
        }
    }

    if (($row['papi_scores'] ?? []) !== []) {
        $profileScores = assessment_papi_profile_scores($row['papi_scores']);
        $profileSvg = (string) ($row['papi_chart_svg'] ?? '');
        if (str_starts_with($profileSvg, '<svg ')) {
            $profileSvg = preg_replace('/^<svg /', '<svg width="240" height="240" ', $profileSvg, 1) ?? $profileSvg;
        }
        $html .= '<h3 class="section-heading">Profil PAPI Kostick · skor mentah</h3><p class="muted">Grafik memakai skala dinamis bertanda dengan nol pada lingkar tengah; data merupakan skor mentah, bukan norma atau interpretasi.</p>'
            . '<div class="papi-layout"><div class="papi-chart">' . $profileSvg . '</div><table class="papi-table"><thead><tr><th>Area</th><th>Aspek</th><th>Skor mentah</th></tr></thead><tbody>';
        foreach ($profileScores as $score) {
            $html .= '<tr><td>' . $escape($score['group']) . '</td><td><strong>' . $escape($score['aspect_code']) . '</strong> · ' . $escape($score['label']) . '</td><td>' . $escape(assessment_papi_format_raw_score($score['raw_score'])) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }

    return $html . '</section>';
}

function assessment_report_pdf_html(
    array $rows,
    array $summary,
    array $filters,
    bool $managementView,
    string $packageLabel = 'Semua paket',
    string $scheduleLabel = 'Semua jadwal',
    int $rowLimit = 500
): string {
    $fromLabel = (new DateTimeImmutable((string) $filters['from']))->format('d/m/Y');
    $toLabel = (new DateTimeImmutable((string) $filters['to']))->format('d/m/Y');
    $rangeLabel = $fromLabel . ' sampai ' . $toLabel . ' WIB';
    $escape = static fn (mixed $value): string => e($value);
    $count = static fn (mixed $value): string => number_format((int) ($value ?? 0), 0, ',', '.');
    $passRate = $summary['pass_rate'] === null
        ? '—'
        : number_format((float) $summary['pass_rate'], 1, ',', '.') . '%';

    $html = '<!doctype html><html lang="id"><head><meta charset="UTF-8"><title>Laporan E-Assessment LJU</title><style>'
        . '@page{margin:22px 23px 38px}*{box-sizing:border-box}body{font-family:"DejaVu Sans",sans-serif;color:#26354d;font-size:8px;line-height:1.4;margin:0}'
        . '.brand{color:#15345a;font-size:8px;font-weight:bold;letter-spacing:.5px}.brand small{color:#75849b;font-size:7px;font-weight:normal;letter-spacing:0}'
        . 'h1{color:#132b4c;font-size:18px;margin:7px 0 3px}p{margin:3px 0;color:#6e7b90}.meta{margin:12px 0 10px;padding:8px 10px;background:#f2f5fa;border:1px solid #e0e6ef;border-radius:4px}'
        . '.meta td{padding:1px 4px;vertical-align:top}.meta-label{color:#7a879a;font-size:7px;font-weight:bold;text-transform:uppercase;width:90px}.summary{width:100%;border-collapse:separate;border-spacing:5px;margin:5px -5px 12px}.summary td{width:20%;padding:8px;background:#f7f9fc;border:1px solid #e8edf3;vertical-align:top}.summary small{display:block;color:#75839a;font-size:6px;font-weight:bold;text-transform:uppercase}.summary strong{display:block;margin-top:3px;color:#18375d;font-size:15px}'
        . '.privacy{margin:9px 0;padding:7px 9px;background:#f1f6fb;border-left:3px solid #5378a3;color:#516882;font-size:7px}'
        . 'table.results{width:100%;border-collapse:collapse;table-layout:fixed}table.results thead{display:table-header-group}table.results th{padding:6px 4px;background:#eaf0f7;color:#354c6d;border:1px solid #dbe3ed;font-size:6px;text-align:left;text-transform:uppercase}table.results td{padding:5px 4px;border:1px solid #e6eaf0;vertical-align:top;overflow-wrap:anywhere}table.results tr{page-break-inside:avoid}.small{display:block;margin-top:2px;color:#7f8ca0;font-size:6px}.score{font-weight:bold;color:#223c60;white-space:nowrap}.pill{font-weight:bold;color:#425d7e}.subheading{margin:10px 0 5px;color:#18375d;font-size:10px}.note{margin-top:7px;color:#7b8798;font-size:7px}.empty{margin:28px 0;padding:22px;border:1px solid #e5eaf1;color:#77859a;text-align:center}'
        . '.attempt-card{margin:10px 0 12px;padding:10px;border:1px solid #dfe6ef;background:#fff}.attempt-heading{width:100%;border-bottom:1px solid #e8edf3;padding-bottom:7px}.attempt-heading>div{display:inline-block;width:82%;vertical-align:top}.attempt-heading h2{margin:0;color:#1c385e;font-size:12px}.attempt-heading p{margin:3px 0 0;color:#748198;font-size:7px}.attempt-number{display:inline-block;border:1px solid #e3e9f0;border-radius:10px;padding:4px 7px;color:#61738c;font-size:7px;font-weight:bold}.attempt-meta{margin:7px 0 5px}.attempt-meta span{display:inline-block;width:48%;padding:2px 3px;vertical-align:top;color:#53647d;font-size:7px}.attempt-meta b{display:block;margin-bottom:1px;color:#8490a2;font-size:6px;text-transform:uppercase}.attempt-scores{width:100%;border-collapse:separate;border-spacing:4px;margin:4px -4px 8px}.attempt-scores td{width:25%;padding:6px 8px;border:1px solid #e6ebf1;background:#f8fafd;vertical-align:top}.attempt-scores small{display:block;color:#7c899d;font-size:6px;font-weight:bold}.attempt-scores strong{display:block;margin-top:3px;color:#234267;font-size:13px}.section-heading{margin:9px 0 5px;padding-bottom:3px;border-bottom:1px solid #e9edf3;color:#274568;font-size:9px}.internal-label{margin-left:5px;color:#a35e4b;font-size:6px;font-weight:normal}.detail-table{width:100%;border-collapse:collapse;margin:4px 0 8px}.detail-table th{padding:5px;background:#f0f4f8;color:#5b6e87;font-size:6px;text-align:left}.detail-table td{padding:5px;border-bottom:1px solid #e9edf3;color:#4c5d74;font-size:7px;vertical-align:top}.detail-table td strong{color:#354b6a}.review-card{margin:6px 0;padding:7px;border:1px solid #e7ebf2;background:#fbfcfe;page-break-inside:auto}.review-heading{width:100%}.review-heading>div{display:inline-block;width:78%;vertical-align:top}.review-heading>div strong,.review-heading>div span{display:block}.review-heading>div strong{color:#354b6a;font-size:8px}.review-heading>div span{margin-top:2px;color:#8490a2;font-size:6px}.review-status{display:inline-block;border-radius:8px;background:#f1f3f6;padding:4px 6px;color:#6d7b90;font-size:6px;font-weight:bold}.review-status.status-completed{background:#eaf7f1;color:#287858}.review-status.status-pending{background:#fff6e9;color:#9b681e}.review-status.status-in_progress{background:#edf3fc;color:#4b6d98}.review-status.status-not_created{background:#f1f2f4;color:#7c8797}.review-meta{margin:3px 0;color:#8490a2;font-size:6px}.review-result{margin:4px 0}.review-result span{display:inline-block;margin-right:5px;border:1px solid #e5eaf1;background:#f6f8fb;padding:4px 6px;color:#77849a;font-size:6px}.review-result strong{color:#354b6a}.review-note{margin-top:4px;border-left:2px solid #dce5f0;padding-left:5px}.review-note b{color:#718096;font-size:6px}.review-note p{margin:2px 0;color:#4f5f77;font-size:7px;overflow-wrap:anywhere}.review-answer{margin-top:5px;border-top:1px solid #eceff3;padding-top:5px}.review-answer b{color:#576981;font-size:7px}.review-answer p{margin:2px 0;color:#68778e;font-size:7px;overflow-wrap:anywhere}.review-answer small{display:block;color:#8994a5;font-size:6px}.review-response-list{margin-top:6px}.review-response-list>strong{color:#526681;font-size:7px}.papi-layout{margin:4px 0}.papi-chart{text-align:center;margin:5px auto 8px;page-break-inside:avoid}.papi-chart svg{display:block;width:240px;height:240px;margin:0 auto}.papi-chart .rv-papi-chart-unavailable{padding:8px;border:1px solid #ece8f1;color:#768399;font-size:7px;text-align:center}.papi-table{width:100%;border-collapse:collapse;margin:5px 0 8px}.papi-table th{padding:4px;background:#f1edf7;color:#756b84;font-size:6px;text-align:left}.papi-table td{padding:4px 5px;border-bottom:1px solid #ece8f1;color:#4f5c70;font-size:6px}.papi-table td:last-child{text-align:right}.muted{color:#7d899b;font-size:7px}'
        . '</style></head><body>'
        . '<div class="brand">PT LAMPUNG JASA UTAMA <small>· E-Assessment</small></div>'
        . '<h1>' . ($managementView ? 'Laporan Agregat Manajemen' : 'Laporan Hasil Asesmen') . '</h1>'
        . '<p>Ringkasan hasil asesmen · zona waktu Asia/Jakarta (WIB)</p>'
        . '<table class="meta"><tr><td class="meta-label">Periode</td><td>' . $escape($rangeLabel) . '</td><td class="meta-label">Paket</td><td>' . $escape($packageLabel) . '</td></tr>';
    if (!$managementView) {
        $statusLabel = match ($filters['status']) {
            'completed' => 'Selesai',
            'pending_review' => 'Menunggu review',
            default => 'Semua hasil',
        };
        $html .= '<tr><td class="meta-label">Jadwal</td><td>' . $escape($scheduleLabel) . '</td><td class="meta-label">Status</td><td>' . $escape($statusLabel) . '</td></tr>';
    }
    $html .= '</table>';

    if ($managementView) {
        $html .= '<table class="summary"><tr>'
            . '<td><small>Peserta dengan attempt</small><strong>' . $count($summary['participant_count'] ?? 0) . '</strong></td>'
            . '<td><small>Total attempt</small><strong>' . $count($summary['attempt_count'] ?? 0) . '</strong></td>'
            . '<td><small>Attempt selesai</small><strong>' . $count($summary['completed_count'] ?? 0) . '</strong></td>'
            . '<td><small>Menunggu review</small><strong>' . $count($summary['pending_review_count'] ?? 0) . '</strong></td>'
            . '<td><small>Tingkat lulus per attempt</small><strong>' . ((int) ($summary['threshold_participant_count'] ?? 0) < 5 ? 'Disembunyikan' : $escape($passRate)) . '</strong></td>'
            . '</tr></table>'
            . '<div class="privacy">Ringkasan agregat per paket. Identitas peserta, alamat email, ID SSO, dan rincian attempt individual tidak dicantumkan. Tingkat lulus per attempt memerlukan sedikitnya 5 peserta berbeda dengan nilai ambang.</div>'
            . '<div class="subheading">Capaian per paket</div>';
        if ($rows === []) {
            $html .= '<div class="empty">Belum ada data agregat pada filter yang dipilih.</div>';
        } else {
            $html .= '<table class="results"><thead><tr><th style="width:25%">Paket</th><th style="width:10%">Peserta</th><th style="width:14%">Selesai</th><th style="width:10%">Attempt</th><th style="width:13%">Menunggu review</th><th style="width:14%">Rata-rata skor</th><th style="width:14%">Tingkat lulus</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $average = $row['average_suppressed']
                    ? 'Disembunyikan (<5)'
                    : assessment_report_pdf_score($row['average_total_score']);
                $rate = $row['pass_rate_suppressed']
                    ? 'Disembunyikan (<5)'
                    : ($row['pass_rate'] === null ? '—' : number_format((float) $row['pass_rate'], 1, ',', '.') . '%');
                $html .= '<tr><td><strong>' . $escape($row['package_name']) . '</strong><span class="small">' . $escape($row['package_code']) . ' · ' . $escape(assessment_report_pdf_package_type((string) $row['package_type'])) . '</span></td>'
                    . '<td>' . $count($row['participant_count']) . '</td>'
                    . '<td>' . $count($row['completed_count']) . ' attempt<span class="small">' . $count($row['completed_participant_count']) . ' peserta</span></td>'
                    . '<td>' . $count($row['attempt_count']) . '</td>'
                    . '<td>' . $count($row['pending_review_count']) . '</td>'
                    . '<td>' . $escape($average) . '</td><td>' . $escape($rate) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '<p class="note">Rata-rata hanya ditampilkan untuk kohor dengan sedikitnya 5 peserta berskor. Tingkat lulus dihitung per attempt dengan nilai ambang; retake dihitung terpisah.</p>';
    } else {
        $html .= '<table class="summary"><tr>'
            . '<td><small>Peserta dengan attempt</small><strong>' . $count($summary['participant_count'] ?? 0) . '</strong></td>'
            . '<td><small>Total attempt</small><strong>' . $count($summary['attempt_count'] ?? 0) . '</strong></td>'
            . '<td><small>Attempt selesai</small><strong>' . $count($summary['completed_count'] ?? 0) . '</strong></td>'
            . '<td><small>Menunggu review</small><strong>' . $count($summary['pending_review_count'] ?? 0) . '</strong></td>'
            . '<td><small>Tingkat lulus per attempt</small><strong>' . $escape($passRate) . '</strong></td>'
            . '</tr></table>'
            . '<div class="privacy">Laporan rinci per attempt. Catatan dan rekomendasi internal dalam bagian review hanya diperuntukkan bagi HR/Admin.</div>'
            . '<div class="subheading">Laporan lengkap per peserta</div>';
        if ($rows === []) {
            $html .= '<div class="empty">Belum ada hasil pada filter yang dipilih.</div>';
        } else {
            foreach ($rows as $row) {
                $html .= assessment_report_pdf_hr_attempt_section($row);
            }
        }
        if ((int) ($summary['attempt_count'] ?? 0) > count($rows)) {
            $html .= '<p class="note">PDF dibatasi hingga ' . $count($rowLimit) . ' attempt terbaru. Persempit filter untuk mencakup data lain.</p>';
        }
        $html .= '<p class="note">Identitas, skor individual, hasil review, dan catatan internal dalam dokumen ini hanya untuk HR/Admin. Tingkat lulus dihitung per attempt selesai yang memiliki nilai ambang.</p>';
    }

    $html .= '<p class="note">Dicetak pada ' . e((new DateTimeImmutable('now'))->format('d/m/Y H:i')) . ' WIB · Data mengikuti filter laporan yang dipilih.</p>'
        . '</body></html>';

    return $html;
}

/** Build a self-only PDF result sheet; it never includes another participant or SSO identifier. */
function assessment_participant_result_pdf_html(array $attempt, string $participantName, array $papiRawScores = []): string
{
    $escape = static fn (mixed $value): string => e($value);
    $isFinal = ($attempt['status'] ?? '') === 'completed';
    $objectiveScore = $attempt['objective_score'] ?? null;
    $manualScore = $attempt['manual_score'] ?? null;
    $totalScore = $attempt['total_score'] ?? null;
    $passingScore = $attempt['passing_score'] ?? null;

    if (!$isFinal) {
        $resultLabel = 'Menunggu review HR';
    } elseif ($totalScore === null) {
        $resultLabel = 'Selesai · skor akhir tidak tersedia';
    } elseif ($passingScore === null) {
        $resultLabel = 'Selesai · paket tanpa ambang';
    } else {
        $resultLabel = (float) $totalScore >= (float) $passingScore ? 'Lulus' : 'Belum lulus';
    }

    $html = '<!doctype html><html lang="id"><head><meta charset="UTF-8"><title>Ringkasan Hasil Asesmen</title><style>'
        . '@page{margin:34px 38px 42px}*{box-sizing:border-box}body{font-family:"DejaVu Sans",sans-serif;color:#293750;font-size:9px;line-height:1.5;margin:0}'
        . '.brand{color:#15345a;font-size:9px;font-weight:bold;letter-spacing:.5px}.brand small{color:#7a879a;font-size:7px;font-weight:normal;letter-spacing:0}'
        . 'h1{margin:18px 0 4px;color:#132b4c;font-size:20px}p{margin:4px 0;color:#6d7b90}.intro{margin-bottom:17px}.identity{margin:14px 0;padding:12px 14px;border:1px solid #e2e8f0;background:#f5f8fc}.identity strong{display:block;color:#253c5e;font-size:13px}.identity span{display:block;margin-top:3px;color:#77859a;font-size:8px}'
        . 'table.meta{width:100%;border-collapse:collapse;margin:12px 0 16px}table.meta td{border-bottom:1px solid #e8edf3;padding:8px 5px;vertical-align:top}table.meta td:first-child{width:31%;color:#78859a;font-size:8px}table.meta td:last-child{color:#344863;font-weight:bold}'
        . '.scores{width:100%;border-collapse:separate;border-spacing:6px;margin:8px -6px}.scores td{width:33.333%;padding:12px 10px;border:1px solid #e5eaf1;border-radius:5px;background:#f8fafd;text-align:center}.scores small{display:block;color:#7d899b;font-size:7px;font-weight:bold;letter-spacing:.35px}.scores strong{display:block;margin-top:5px;color:#1b3a62;font-size:20px}.scores .total{background:#eef5fb;border-color:#d7e4f1}.scores .total strong{color:#12385f}'
        . '.status{margin:12px 0;padding:10px 12px;border-left:3px solid #5480a8;background:#f2f7fb;color:#37516e;font-weight:bold}.status.pass{border-color:#358468;background:#eff8f4;color:#276b55}.status.fail{border-color:#b86455;background:#fff4f1;color:#925045}.status.pending{border-color:#c68a3d;background:#fff8ed;color:#8e642e}'
        . '.notice{margin:14px 0;padding:10px 12px;border:1px solid #e8edf3;background:#fafbfd;color:#768399;font-size:8px}.papi-heading{margin:16px 0 3px;color:#3f4a60;font-size:12px}.papi-copy{margin:0 0 8px;color:#778399;font-size:7px}.papi-chart{text-align:center;margin:8px auto 12px;page-break-inside:avoid}.papi-chart svg{display:block;width:250px;height:250px;margin:0 auto}.papi-chart .rv-papi-chart-unavailable{padding:12px;border:1px solid #ece8f1;color:#768399;font-size:8px;text-align:center}.papi-scores{width:100%;border-collapse:collapse;margin:7px 0 12px}.papi-scores th{padding:6px;background:#f1edf7;color:#756b84;font-size:7px;text-align:left}.papi-scores td{padding:5px 6px;border-bottom:1px solid #ece8f1;color:#4f5c70;font-size:7px;page-break-inside:avoid}.papi-scores tr:nth-child(even){background:#f8fafd}.papi-scores td:last-child,.papi-scores th:last-child{text-align:right;width:70px}.papi-group{color:#776e84;font-size:6px}.papi-dot{display:inline-block;width:6px;height:6px;margin-right:4px;border-radius:1px}.papi-code{display:inline-block;min-width:20px;color:#354a69;font-weight:bold}.footer-note{margin-top:20px;border-top:1px solid #e8edf3;padding-top:9px;color:#8994a5;font-size:7px}'
        . '</style></head><body><div class="brand">PT LAMPUNG JASA UTAMA <small>· E-Assessment</small></div>'
        . '<h1>Ringkasan Hasil Asesmen</h1><p class="intro">Dokumen pribadi untuk peserta yang terautentikasi pada sesi asesmen ini.</p>'
        . '<div class="identity"><strong>' . $escape($participantName) . '</strong><span>' . $escape($attempt['package_name'] ?? 'Paket asesmen') . '</span></div>'
        . '<table class="meta"><tr><td>Jadwal</td><td>' . $escape((string) ($attempt['schedule_code'] ?? '') . ' · ' . (string) ($attempt['schedule_name'] ?? '')) . '</td></tr>'
        . '<tr><td>Nomor attempt</td><td>' . (int) ($attempt['attempt_number'] ?? 1) . '</td></tr>'
        . '<tr><td>Mulai</td><td>' . $escape(assessment_report_pdf_date($attempt['started_at'] ?? null)) . '</td></tr>'
        . '<tr><td>Dikirim</td><td>' . $escape(assessment_report_pdf_date($attempt['submitted_at'] ?? null)) . '</td></tr></table>'
        . '<table class="scores"><tr>'
        . '<td><small>SKOR OBJEKTIF' . (!$isFinal ? ' · SEMENTARA' : '') . '</small><strong>' . $escape(assessment_report_pdf_score($objectiveScore)) . '</strong></td>'
        . '<td><small>SKOR MANUAL</small><strong>' . $escape($isFinal ? assessment_report_pdf_score($manualScore) : '—') . '</strong></td>'
        . '<td class="total"><small>SKOR AKHIR</small><strong>' . $escape($isFinal ? assessment_report_pdf_score($totalScore) : '—') . '</strong></td>'
        . '</tr></table>';
    $statusClass = !$isFinal ? 'pending' : (($totalScore !== null && $passingScore !== null)
        ? ((float) $totalScore >= (float) $passingScore ? 'pass' : 'fail')
        : '');
    $html .= '<div class="status ' . $statusClass . '">Hasil: ' . $escape($resultLabel);
    if ($passingScore !== null) {
        $html .= ' · Nilai ambang paket: ' . $escape(assessment_report_pdf_score($passingScore));
    }
    $html .= '</div>';

    if (!$isFinal) {
        $html .= '<div class="notice">Skor objektif di atas bersifat sementara. Nilai manual dan skor akhir belum diterbitkan karena proses review HR masih berjalan.</div>';
    } else {
        $html .= '<div class="notice">Skor merupakan hasil pada attempt ini. Dokumen ini tidak memuat interpretasi atau norma PAPI/psikometri; gunakan pedoman resmi yang berlaku untuk interpretasi instrumen.</div>';
    }
    if ($isFinal && $papiRawScores !== []) {
        $profileScores = assessment_papi_profile_scores($papiRawScores);
        if ($profileScores !== []) {
            $profileSvg = assessment_papi_profile_svg($papiRawScores);
            if (str_starts_with($profileSvg, '<svg ')) {
                $profileSvg = preg_replace('/^<svg /', '<svg width="250" height="250" ', $profileSvg, 1) ?? $profileSvg;
            }
            $html .= '<h2 class="papi-heading">Profil PAPI Kostick · skor mentah</h2>'
                . '<p class="papi-copy">Review PAPI HR telah selesai. Grafik memakai rentang dinamis bertanda dengan 0 pada ring tengah. Grafik dan tabel memuat skor mentah saja, bukan norma atau interpretasi.</p>'
                . '<div class="papi-chart">' . $profileSvg . '</div>'
                . '<table class="papi-scores"><thead><tr><th>Area</th><th>Aspek</th><th>Skor mentah</th></tr></thead><tbody>';
            foreach ($profileScores as $score) {
                $html .= '<tr><td><span class="papi-group"><i class="papi-dot" style="background-color:' . $escape($score['color']) . '"></i>' . $escape($score['group']) . '</span></td>'
                    . '<td><span class="papi-code">' . $escape($score['aspect_code']) . '</span>' . $escape($score['label']) . '</td>'
                    . '<td>' . $escape(assessment_papi_format_raw_score($score['raw_score'])) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
    }
    $html .= '<div class="footer-note">Dokumen ini hanya menampilkan hasil milik peserta yang sedang mengakses. Jangan membagikan dokumen ini tanpa izin.</div>'
        . '<p class="footer-note">Dicetak pada ' . e((new DateTimeImmutable('now'))->format('d/m/Y H:i')) . ' WIB.</p></body></html>';

    return $html;
}

/** Create a private, writable cache directory for Dompdf's temporary files and font cache. */
function assessment_report_pdf_cache_directory(): string
{
    $storageRoot = rtrim((string) app_config()['storage']['root'], '/\\');
    $cacheDirectory = $storageRoot . '/dompdf';
    if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0700, true) && !is_dir($cacheDirectory)) {
        throw new RuntimeException('Direktori cache PDF tidak dapat dibuat.');
    }
    if (!is_writable($cacheDirectory)) {
        throw new RuntimeException('Direktori cache PDF tidak dapat ditulis.');
    }

    $fontCacheDirectory = $cacheDirectory . '/fonts';
    if (!is_dir($fontCacheDirectory) && !@mkdir($fontCacheDirectory, 0700, true) && !is_dir($fontCacheDirectory)) {
        throw new RuntimeException('Direktori font cache PDF tidak dapat dibuat.');
    }
    if (!is_writable($fontCacheDirectory)) {
        throw new RuntimeException('Direktori font cache PDF tidak dapat ditulis.');
    }

    return $cacheDirectory;
}
