<?php
declare(strict_types=1);

/** Presentation metadata from the HR-provided PAPI Kostick profile example; no norms or interpretations. */
function assessment_papi_group_catalog(): array
{
    return [
        'Arah Kerja' => ['color' => '#df2b2f', 'codes' => ['N', 'G', 'A']],
        'Kepemimpinan' => ['color' => '#db16c9', 'codes' => ['L', 'P', 'I']],
        'Aktivitas' => ['color' => '#263ce8', 'codes' => ['T', 'V', 'S']],
        'Pergaulan' => ['color' => '#08aed1', 'codes' => ['B', 'O', 'X']],
        'Gaya Kerja' => ['color' => '#05c975', 'codes' => ['C', 'D', 'R']],
        'Sikap' => ['color' => '#dfca00', 'codes' => ['Z', 'E', 'K']],
        'Ketaatan' => ['color' => '#f0a21b', 'codes' => ['F', 'W']],
    ];
}

/** Aspect labels match the user-provided example; they are labels only, not interpretations. */
function assessment_papi_aspect_catalog(): array
{
    $groups = assessment_papi_group_catalog();
    $labels = [
        'N' => 'Penyelesaian pekerjaan',
        'G' => 'Peran pekerja keras',
        'A' => 'Keinginan berprestasi',
        'L' => 'Kepemimpinan',
        'P' => 'Keinginan mengawasi orang lain',
        'I' => 'Mudah mengambil keputusan',
        'T' => 'Tempo kerja',
        'V' => 'Stamina',
        'S' => 'Lingkungan sosial',
        'B' => 'Kebutuhan untuk memiliki kelompok',
        'O' => 'Butuh kedekatan dan kasih sayang',
        'X' => 'Kebutuhan untuk diperhatikan',
        'C' => 'Kebutuhan akan keteraturan',
        'D' => 'Minat bekerja dengan detail',
        'R' => 'Kebutuhan akan teoritis',
        'Z' => 'Kebutuhan akan perubahan',
        'E' => 'Ketahanan emosi',
        'K' => 'Kebutuhan akan ketahanan (konflik)',
        'F' => 'Kebutuhan untuk membantu atasan',
        'W' => 'Kebutuhan akan aturan dan supervisi',
    ];

    $catalog = [];
    foreach ($groups as $groupName => $group) {
        foreach ($group['codes'] as $code) {
            $catalog[$code] = [
                'label' => $labels[$code],
                'group' => $groupName,
                'color' => $group['color'],
            ];
        }
    }

    return $catalog;
}

/** Order known aspects by the supplied wheel; preserve and append any custom codes. */
function assessment_papi_profile_scores(array $rawScores): array
{
    $scoreByCode = [];
    foreach ($rawScores as $row) {
        if (!is_array($row) || !is_scalar($row['aspect_code'] ?? null) || !is_numeric($row['raw_score'] ?? null)) {
            continue;
        }
        $code = strtoupper(trim((string) $row['aspect_code']));
        if ($code === '') {
            continue;
        }
        $scoreByCode[$code] = (float) $row['raw_score'];
    }

    $catalog = assessment_papi_aspect_catalog();
    $ordered = [];
    foreach ($catalog as $code => $metadata) {
        if (array_key_exists($code, $scoreByCode)) {
            $ordered[] = $metadata + ['aspect_code' => $code, 'raw_score' => $scoreByCode[$code]];
        }
    }

    $customCodes = array_diff(array_keys($scoreByCode), array_keys($catalog));
    sort($customCodes, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($customCodes as $code) {
        $ordered[] = [
            'aspect_code' => $code,
            'label' => 'Aspek tambahan',
            'group' => 'Lainnya',
            'color' => '#8a95a7',
            'raw_score' => $scoreByCode[$code],
        ];
    }

    return $ordered;
}

function assessment_papi_format_raw_score(mixed $value): string
{
    if (!is_numeric($value)) {
        return '—';
    }
    $score = (float) $value;
    if (abs($score - round($score)) < 0.00001) {
        return number_format($score, 0, ',', '.');
    }

    return number_format($score, 2, ',', '.');
}

/** Build a signed, dynamically scaled SVG radar chart; values remain raw and are never normed. */
function assessment_papi_profile_svg(array $rawScores): string
{
    $scores = assessment_papi_profile_scores($rawScores);
    $catalog = assessment_papi_aspect_catalog();
    $knownCount = count(array_filter($scores, static fn (array $score): bool => isset($catalog[$score['aspect_code']])));
    if (count($scores) !== count($catalog) || $knownCount !== count($catalog)) {
        return '<div class="rv-papi-chart-unavailable">Grafik roda ditampilkan saat seluruh 20 aspek standar tersedia. Tabel skor mentah tetap menampilkan aspek yang tersimpan.</div>';
    }

    $absoluteValues = array_map(static fn (array $score): float => abs((float) $score['raw_score']), $scores);
    $actualMaximum = max($absoluteValues ?: [0.0]);
    $scaleMaximum = $actualMaximum > 0.0 ? $actualMaximum : 1.0;
    $centerX = 260.0;
    $centerY = 260.0;
    $zeroRadius = 116.0;
    $rangeRadius = 88.0;
    $outerRadius = $zeroRadius + $rangeRadius;
    $groupRadius = $outerRadius + 9.0;
    $labelRadius = $groupRadius + 23.0;
    $count = count($scores);
    $angleStep = 360.0 / $count;
    $points = [];
    $svg = '<svg class="rv-papi-radar" viewBox="0 0 520 520" role="img" aria-label="Profil radar skor mentah PAPI dengan skala simetris dinamis">'
        . '<title>Profil skor mentah PAPI Kostick</title>'
        . '<desc>Grafik radial memakai skor mentah bertanda, dengan nol pada lingkar tengah dan rentang dinamis berdasarkan nilai terbesar pada attempt ini. Grafik bukan norma atau interpretasi.</desc>';

    foreach ([-1.0, -0.5, 0.0, 0.5, 1.0] as $fraction) {
        $radius = $zeroRadius + ($fraction * $rangeRadius);
        $svg .= '<circle cx="260" cy="260" r="' . number_format($radius, 2, '.', '') . '" fill="none" stroke="'
            . ($fraction === 0.0 ? '#9ca9ba' : '#dfe5ed') . '" stroke-width="' . ($fraction === 0.0 ? '1.4' : '1') . '"'
            . ($fraction === 0.0 ? ' stroke-dasharray="3 3"' : '') . '/>';
    }

    foreach ($scores as $index => $score) {
        $angle = -90.0 + ($index * $angleStep);
        $radians = deg2rad($angle);
        $xOuter = $centerX + ($outerRadius * cos($radians));
        $yOuter = $centerY + ($outerRadius * sin($radians));
        $svg .= '<line x1="260" y1="260" x2="' . number_format($xOuter, 2, '.', '') . '" y2="' . number_format($yOuter, 2, '.', '') . '" stroke="#e5eaf1" stroke-width="1"/>';

        $scoreRadius = $zeroRadius + (((float) $score['raw_score'] / $scaleMaximum) * $rangeRadius);
        $pointX = $centerX + ($scoreRadius * cos($radians));
        $pointY = $centerY + ($scoreRadius * sin($radians));
        $points[] = number_format($pointX, 2, '.', '') . ',' . number_format($pointY, 2, '.', '');

        $halfGap = min(1.4, $angleStep * 0.12);
        $startAngle = $angle - ($angleStep / 2) + $halfGap;
        $endAngle = $angle + ($angleStep / 2) - $halfGap;
        $arcStartX = $centerX + ($groupRadius * cos(deg2rad($startAngle)));
        $arcStartY = $centerY + ($groupRadius * sin(deg2rad($startAngle)));
        $arcEndX = $centerX + ($groupRadius * cos(deg2rad($endAngle)));
        $arcEndY = $centerY + ($groupRadius * sin(deg2rad($endAngle)));
        $svg .= '<path d="M ' . number_format($arcStartX, 2, '.', '') . ' ' . number_format($arcStartY, 2, '.', '')
            . ' A ' . number_format($groupRadius, 2, '.', '') . ' ' . number_format($groupRadius, 2, '.', '')
            . ' 0 0 1 ' . number_format($arcEndX, 2, '.', '') . ' ' . number_format($arcEndY, 2, '.', '')
            . '" fill="none" stroke="' . htmlspecialchars($score['color'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" stroke-width="9"/>';

        $labelX = $centerX + ($labelRadius * cos($radians));
        $labelY = $centerY + ($labelRadius * sin($radians));
        $code = htmlspecialchars($score['aspect_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $svg .= '<text x="' . number_format($labelX, 2, '.', '') . '" y="' . number_format($labelY, 2, '.', '')
            . '" text-anchor="middle" dominant-baseline="middle" fill="#34445f" font-size="10" font-weight="700">' . $code . '</text>';
    }

    $svg .= '<polygon points="' . implode(' ', $points) . '" fill="#4285d033" stroke="#3479c7" stroke-width="2" stroke-linejoin="round"/>';
    foreach ($scores as $index => $score) {
        $angle = deg2rad(-90.0 + ($index * $angleStep));
        $scoreRadius = $zeroRadius + (((float) $score['raw_score'] / $scaleMaximum) * $rangeRadius);
        $pointX = $centerX + ($scoreRadius * cos($angle));
        $pointY = $centerY + ($scoreRadius * sin($angle));
        $description = htmlspecialchars(
            $score['aspect_code'] . ' · ' . $score['label'] . ' · skor mentah ' . assessment_papi_format_raw_score($score['raw_score']),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $svg .= '<circle cx="' . number_format($pointX, 2, '.', '') . '" cy="' . number_format($pointY, 2, '.', '') . '" r="3.2" fill="#fff" stroke="#3479c7" stroke-width="1.8"><title>' . $description . '</title></circle>';
    }
    $svg .= '</svg>';

    return $svg;
}
