<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/dashboard_data.php';
app_require_auth();

$user = app_user();
$isHr = app_is_hr();
$isManagement = app_is_management();
$isParticipant = !$isHr && !$isManagement;
$data = app_dashboard_data();
$stats = $data['stats'] ?? [];
$avatarUrl = app_avatar_url();
$displayName = (string) ($user['name'] ?? 'Pengguna');
$roleLabel = $isHr ? (app_is_admin() ? 'Mode HR/Admin Aktif' : 'Panel HR Aktif') : ($isManagement ? 'Mode Manajemen' : 'Peserta SSO');
$divisionLabel = trim((string) ($user['nama_bidang'] ?? ''));
$divisionLabel = $divisionLabel !== '' ? 'Divisi ' . $divisionLabel : 'Pusat Asesmen Talenta BUMD';

function dashboard_number(mixed $value): string
{
    return number_format((float) ($value ?? 0), 0, ',', '.');
}

function dashboard_percent(mixed $value): string
{
    return $value === null ? '—' : number_format((float) $value, 1, ',', '.') . '%';
}

function dashboard_date(?string $value, bool $withTime = true): string
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
    $months = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];
    $dayName = $days[(int) $date->format('w')];
    $monthName = $months[(int) $date->format('n')];
    $formatted = $dayName . ', ' . $date->format('d') . ' ' . $monthName . ' ' . $date->format('Y');

    return $withTime ? $formatted . ' · ' . $date->format('H:i') . ' WIB' : $formatted;
}

function dashboard_review_label(string $type): string
{
    return match ($type) {
        'essay' => 'Esai',
        'practical' => 'Praktik',
        'psychometric' => 'Psikotes',
        'papi' => 'PAPI Kostick',
        'review_360' => 'Evaluasi 360°',
        'overall' => 'Konsolidasi akhir',
        default => 'Penilaian HR',
    };
}

function dashboard_module_label(string $category): string
{
    $labels = [
        'TPA' => 'Tes Potensi Akademik',
        'COGNITIVE' => 'Kognitif',
        'PAPI_KOSTICK' => 'PAPI Kostick',
        'DISC' => 'DISC',
        'MOTIVATION' => 'Motivasi & Gaya Kerja',
        'OFFICE' => 'Software Office',
        'TECHNICAL' => 'Kompetensi Teknis',
        'ESSAY' => 'Esai',
        '360' => 'Evaluasi 360°',
        'OTHER' => 'Kategori lainnya',
    ];

    return $labels[$category] ?? ucwords(strtolower(str_replace('_', ' ', $category)));
}

$navItems = [];
if ($isHr) {
    $navItems = [
        ['icon' => 'overview', 'label' => 'Dashboard Utama HR', 'tag' => 'Overview', 'active' => true],
        ['icon' => 'door', 'label' => 'Lobby Ujian', 'tag' => 'Peserta'],
        ['icon' => 'layers', 'label' => 'Bank Soal & Paket', 'href' => 'admin/question_bank.php'],
        ['icon' => 'calendar', 'label' => 'Jadwal & Undangan', 'href' => 'admin/schedules.php'],
        ['icon' => 'clipboard', 'label' => 'Portal Penilai HR', 'tag' => 'Review', 'href' => 'admin/reviews.php'],
        ['icon' => 'chart', 'label' => 'Laporan & Hasil', 'href' => 'reports.php'],
        ['icon' => 'users', 'label' => 'Evaluasi 360°'],
    ];
} elseif ($isManagement) {
    $navItems = [
        ['icon' => 'overview', 'label' => 'Ringkasan Manajemen', 'tag' => 'Overview', 'active' => true],
        ['icon' => 'chart', 'label' => 'Laporan & Hasil', 'href' => 'reports.php'],
        ['icon' => 'users', 'label' => 'Evaluasi 360°'],
    ];
} else {
    $navItems = [
        ['icon' => 'overview', 'label' => 'Beranda Peserta', 'tag' => 'SSO', 'active' => true],
        ['icon' => 'door', 'label' => 'Ruang Ujian', 'tag' => 'Jadwal'],
        ['icon' => 'file', 'label' => 'Riwayat Hasil'],
    ];
}

$databaseOnline = (bool) ($data['available'] ?? false);
$participantSchedules = $data['schedules'] ?? [];
$review360Total = (int) ($stats['review360_total'] ?? 0);
$review360Completed = (int) ($stats['review360_completed'] ?? 0);
$review360Percent = $review360Total > 0 ? ($review360Completed / $review360Total) * 100 : null;
$passRate = $stats['pass_rate'] ?? null;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7fb">
    <title><?= e($isHr ? 'Dashboard HR' : ($isManagement ? 'Ringkasan Manajemen' : 'Beranda Peserta')) ?> · E-Assessment LJU</title>
    <link rel="stylesheet" href="assets/app.css?v=1">
    <script src="assets/app.js?v=1" defer></script>
</head>
<body class="<?= $isParticipant ? 'participant-mode' : 'dashboard-mode' ?>">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="i-overview" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></symbol>
    <symbol id="i-door" viewBox="0 0 24 24"><path d="M4 21h16M6 21V4.8a2 2 0 0 1 1.6-2l9-1.8A2 2 0 0 1 19 3v18M13 12h.01"/></symbol>
    <symbol id="i-layers" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></symbol>
    <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/></symbol>
    <symbol id="i-clipboard" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4.5h6a1.5 1.5 0 0 0-1.5-1.5h-3A1.5 1.5 0 0 0 9 4.5ZM9 10h6M9 14h6M9 18h3"/></symbol>
    <symbol id="i-chart" viewBox="0 0 24 24"><path d="M3 3v18h18M7 14l4-4 4 3 6-7"/><path d="M17 6h4v4"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8a3 3 0 0 1-2 2.83M20 21v-2a4 4 0 0 0-3-3.87"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></symbol>
    <symbol id="i-key" viewBox="0 0 24 24"><circle cx="8" cy="15" r="5"/><path d="m11.5 11.5 9-9 2 2-2 2 1 1-3 3-1-1-2 2"/></symbol>
    <symbol id="i-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></symbol>
    <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
    <symbol id="i-chevron" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
    <symbol id="i-building" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 10h.01M15 10h.01M9 14h.01M15 14h.01M10 21v-4h4v4"/></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></symbol>
    <symbol id="i-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.6 9a2.5 2.5 0 1 1 4.1 1.9c-1 .8-1.7 1.1-1.7 2.6M12 17h.01"/></symbol>
    <symbol id="i-activity" viewBox="0 0 24 24"><path d="M3 12h4l3-8 4 16 3-8h4"/></symbol>
    <symbol id="i-camera" viewBox="0 0 24 24"><path d="M14 5h-4l-2 3H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-3l-2-3Z"/><circle cx="12" cy="13" r="3"/></symbol>
    <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M7 17 17 7M7 7h10v10"/></symbol>
    <symbol id="i-book" viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="i-warning" viewBox="0 0 24 24"><path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0ZM12 9v4M12 17h.01"/></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><path d="m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13"/></symbol>
</svg>

<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
        <a class="brand" href="index.php" aria-label="E-Assessment LJU, beranda">
            <span class="brand-logo-wrap">
                <img class="brand-logo" src="https://dms.lju.co.id/assets/img/logo.png" alt="Logo PT Lampung Jasa Utama" data-brand-logo>
                <span class="brand-logo-fallback" aria-hidden="true">LJU</span>
            </span>
            <span class="brand-copy">
                <strong>E-ASSESSMENT</strong>
                <small>PT Lampung Jasa Utama</small>
            </span>
        </a>

        <div class="sidebar-section-label">NAVIGASI UTAMA</div>
        <nav class="side-nav">
            <?php foreach ($navItems as $item): ?>
                <?php if (!empty($item['active'])): ?>
                    <a class="nav-link is-active" href="index.php" aria-current="page">
                        <svg class="icon"><use href="#i-<?= e($item['icon']) ?>"></use></svg>
                        <span class="nav-label"><?= e($item['label']) ?></span>
                        <?php if (!empty($item['tag'])): ?><span class="nav-tag"><?= e($item['tag']) ?></span><?php endif; ?>
                    </a>
                <?php elseif (!empty($item['href'])): ?>
                    <a class="nav-link" href="<?= e($item['href']) ?>">
                        <svg class="icon"><use href="#i-<?= e($item['icon']) ?>"></use></svg>
                        <span class="nav-label"><?= e($item['label']) ?></span>
                        <?php if (!empty($item['tag'])): ?><span class="nav-tag nav-tag-muted"><?= e($item['tag']) ?></span><?php endif; ?>
                    </a>
                <?php else: ?>
                    <button class="nav-link nav-coming-soon" type="button" data-coming-soon>
                        <svg class="icon"><use href="#i-<?= e($item['icon']) ?>"></use></svg>
                        <span class="nav-label"><?= e($item['label']) ?></span>
                        <?php if (!empty($item['tag'])): ?><span class="nav-tag nav-tag-muted"><?= e($item['tag']) ?></span><?php endif; ?>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <div class="support-card">
                <div class="support-icon"><svg class="icon"><use href="#i-help"></use></svg></div>
                <strong>Bantuan Pengawas</strong>
                <p>Butuh bantuan teknis atau asistensi pengawasan CAT?</p>
                <button type="button" class="text-action" data-coming-soon>Hubungi Tim CAT <span aria-hidden="true">→</span></button>
            </div>
            <div class="sidebar-footnote"><span class="status-dot"></span> Sistem Asesmen Terpadu</div>
        </div>
    </aside>

    <div class="sidebar-backdrop" data-sidebar-close></div>

    <div class="workspace">
        <header class="topbar">
            <div class="topbar-left">
                <button class="icon-button mobile-menu-button" type="button" aria-label="Buka navigasi" aria-expanded="false" data-sidebar-toggle>
                    <svg class="icon"><use href="#i-menu"></use></svg>
                </button>
                <div class="topbar-context">
                    <span class="topbar-kicker">ASSESSMENT SUITE</span>
                    <strong>Talent &amp; Competency Center</strong>
                </div>
            </div>
            <div class="topbar-right">
                <div class="server-clock"><svg class="icon"><use href="#i-clock"></use></svg><span id="local-clock">WIB</span></div>
                <div class="system-status <?= $databaseOnline ? 'is-online' : 'is-offline' ?>">
                    <span class="status-dot"></span><span><?= $databaseOnline ? 'Online' : 'Data belum terhubung' ?></span>
                </div>
                <span class="role-pill <?= $isHr ? 'role-hr' : ($isManagement ? 'role-management' : 'role-participant') ?>">
                    <svg class="icon"><use href="#i-shield"></use></svg><?= e($roleLabel) ?>
                </span>
                <div class="profile-menu" aria-label="Profil pengguna">
                    <span class="avatar" aria-hidden="true">
                        <?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="" data-avatar><?php endif; ?>
                        <span class="avatar-fallback" <?= $avatarUrl ? 'hidden' : '' ?>><?= e(app_user_initials($displayName)) ?></span>
                    </span>
                    <span class="profile-copy"><strong><?= e($displayName) ?></strong><small><?= e($user['position'] ?: 'Pengguna SSO') ?></small></span>
                    <svg class="icon profile-chevron"><use href="#i-chevron"></use></svg>
                </div>
            </div>
        </header>

        <main class="main-content">
            <div class="page-context">
                <div class="breadcrumb"><svg class="icon"><use href="#i-building"></use></svg><span><?= e($divisionLabel) ?></span><span class="breadcrumb-slash">/</span><span>Pusat Asesmen Talenta</span></div>
                <div class="context-meta">
                    <span class="context-chip"><svg class="icon"><use href="#i-calendar"></use></svg>Tahun <?= e(date('Y')) ?> · Siklus Assessment</span>
                    <span class="context-chip context-chip-live"><span class="status-dot"></span><?= $databaseOnline ? 'Sinkronisasi sistem aktif' : 'Menunggu koneksi database' ?></span>
                </div>
            </div>

            <?php if ($data['error'] ?? null): ?>
                <div class="connection-alert" role="status">
                    <svg class="icon"><use href="#i-warning"></use></svg>
                    <div><strong>Koneksi data belum siap</strong><p><?= e($data['error']) ?></p></div>
                </div>
            <?php endif; ?>

            <?php if ($isParticipant): ?>
                <section class="participant-hero">
                    <div class="hero-copy">
                        <span class="eyebrow"><span class="eyebrow-dot"></span> PORTAL PESERTA</span>
                        <h1>Selamat datang,<br><span><?= e($displayName) ?></span></h1>
                        <p>Semua jadwal asesmen Anda tersaji di sini. Pastikan perangkat dan koneksi internet siap sebelum memasuki ruang ujian.</p>
                        <div class="hero-actions">
                            <button class="button button-primary" type="button" data-coming-soon><svg class="icon"><use href="#i-door"></use></svg>Buka Ruang Ujian</button>
                            <button class="button button-ghost" type="button" data-coming-soon><svg class="icon"><use href="#i-help"></use></svg>Panduan Peserta</button>
                        </div>
                    </div>
                    <div class="hero-art" aria-hidden="true">
                        <div class="hero-orbit orbit-one"></div><div class="hero-orbit orbit-two"></div>
                        <div class="hero-art-card"><svg class="icon"><use href="#i-clipboard"></use></svg><span>ASSESSMENT</span><strong>Kompetensi<br>&amp; Talenta</strong><i></i></div>
                        <span class="hero-art-spark spark-one">✦</span><span class="hero-art-spark spark-two">✧</span>
                    </div>
                </section>

                <section class="section-heading participant-heading">
                    <div><span class="section-kicker">AKTIVITAS ANDA</span><h2>Jadwal asesmen</h2><p>Lihat informasi dan status ujian yang ditugaskan kepada Anda.</p></div>
                </section>
                <?php if (!$participantSchedules): ?>
                    <div class="empty-state large-empty">
                        <div class="empty-icon"><svg class="icon"><use href="#i-calendar"></use></svg></div>
                        <h3>Belum ada jadwal asesmen</h3>
                        <p>Jika Anda merasa seharusnya menerima undangan, silakan hubungi tim HR atau pengawas CAT.</p>
                    </div>
                <?php else: ?>
                    <div class="participant-schedule-list">
                        <?php foreach ($participantSchedules as $schedule): ?>
                            <?php $scheduleStatus = (string) $schedule['schedule_status']; ?>
                            <article class="participant-schedule-card">
                                <div class="participant-schedule-symbol"><svg class="icon"><use href="#i-file"></use></svg></div>
                                <div class="participant-schedule-main">
                                    <span class="schedule-code"><?= e($schedule['schedule_code']) ?></span>
                                    <h3><?= e($schedule['schedule_name']) ?></h3>
                                    <p><?= e($schedule['package_name']) ?> <span>·</span> <?= dashboard_number($schedule['module_count']) ?> modul</p>
                                    <div class="participant-schedule-date"><svg class="icon"><use href="#i-clock"></use></svg><?= e(dashboard_date($schedule['starts_at'])) ?> — <?= e(dashboard_date($schedule['ends_at'])) ?></div>
                                </div>
                                <div class="participant-schedule-action">
                                    <span class="status-pill <?= $scheduleStatus === 'open' ? 'status-live' : 'status-neutral' ?>"><?= $scheduleStatus === 'open' ? 'Sedang dibuka' : ($scheduleStatus === 'closed' ? 'Ditutup' : 'Terjadwal') ?></span>
                                    <button class="button button-small button-primary" type="button" data-coming-soon><?= $scheduleStatus === 'open' ? 'Masuk ujian' : 'Lihat detail' ?><svg class="icon"><use href="#i-arrow"></use></svg></button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <section class="participant-help-grid">
                    <article class="panel help-panel"><div class="help-panel-icon"><svg class="icon"><use href="#i-camera"></use></svg></div><div><h3>Persiapan perangkat</h3><p>Gunakan laptop/komputer dengan browser terbaru. Pastikan webcam dan mikrofon siap jika diperlukan.</p></div><button type="button" class="text-action" data-coming-soon>Cek perangkat <span>→</span></button></article>
                    <article class="panel help-panel"><div class="help-panel-icon help-panel-icon-gold"><svg class="icon"><use href="#i-book"></use></svg></div><div><h3>Aturan pengerjaan</h3><p>Baca petunjuk pada setiap modul. Jawaban disimpan otomatis selama koneksi Anda stabil.</p></div><button type="button" class="text-action" data-coming-soon>Baca panduan <span>→</span></button></article>
                </section>
            <?php else: ?>
                <section class="welcome-hero">
                    <div class="welcome-copy">
                        <span class="eyebrow"><svg class="icon"><use href="#i-shield"></use></svg><?= $isHr ? 'PANEL KENDALI UTAMA SDM' : 'RINGKASAN MANAJEMEN' ?></span>
                        <h1><?= $isHr ? 'Selamat Datang, Tim SDM &amp; Asesmen Talenta PT Lampung Jasa Utama' : 'Ringkasan Asesmen Talenta PT Lampung Jasa Utama' ?></h1>
                        <p><?= $isHr ? 'Pusat kendali operasional evaluasi kompetensi BUMD Lampung secara transparan, akuntabel, dan terukur. Pantau pelaksanaan CAT, validasi integritas, dan percepat verifikasi portofolio kandidat.' : 'Pantau ringkasan pelaksanaan asesmen, capaian kompetensi, dan evaluasi 360° secara ringkas untuk mendukung keputusan pengembangan talenta.' ?></p>
                    </div>
                    <div class="welcome-actions">
                        <?php if ($isHr): ?>
                            <a class="button button-primary" href="admin/assessment_builder.php?view=packages#builder-editor"><svg class="icon"><use href="#i-plus"></use></svg>Buat Paket Ujian</a>
                            <a class="button button-light" href="admin/schedules.php"><svg class="icon"><use href="#i-calendar"></use></svg>Jadwal &amp; Undangan</a>
                            <a class="button button-dark-ghost" href="reports.php"><svg class="icon"><use href="#i-download"></use></svg>Laporan &amp; Unduh Rekap</a>
                        <?php else: ?>
                            <a class="button button-light" href="reports.php"><svg class="icon"><use href="#i-chart"></use></svg>Lihat Laporan</a>
                        <?php endif; ?>
                    </div>
                    <div class="welcome-decoration" aria-hidden="true"><span></span><span></span><span></span><i></i></div>
                </section>

                <section class="metric-grid" aria-label="Ringkasan metrik asesmen">
                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-label">TOTAL PESERTA<br>TERDAFTAR</span><span class="metric-icon metric-blue"><svg class="icon"><use href="#i-users"></use></svg></span></div>
                        <div class="metric-value-row"><strong><?= $databaseOnline ? dashboard_number($stats['participants'] ?? 0) : '—' ?></strong><span class="metric-change"><svg class="icon"><use href="#i-check"></use></svg>Terdata</span></div>
                        <div class="metric-foot"><span><?= $databaseOnline ? dashboard_number($stats['employees'] ?? 0) . ' SSO Internal' : 'Karyawan SSO' ?></span><span><?= $databaseOnline ? dashboard_number($stats['candidates'] ?? 0) . ' Eksternal' : 'Calon karyawan' ?></span></div>
                    </article>
                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-label">SESI UJIAN AKTIF</span><span class="metric-icon metric-blue"><svg class="icon"><use href="#i-clock"></use></svg></span></div>
                        <div class="metric-value-row"><strong><?= $databaseOnline ? dashboard_number($stats['active_sessions'] ?? 0) : '—' ?></strong><span class="live-chip"><i></i>Live</span></div>
                        <div class="metric-foot"><span>Sesi yang sedang berlangsung</span></div>
                    </article>
                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-label">MENUNGGU REVIEW<br>ASESOR</span><span class="metric-icon metric-amber"><svg class="icon"><use href="#i-clipboard"></use></svg></span></div>
                        <div class="metric-value-row"><strong><?= $databaseOnline ? dashboard_number($stats['pending_reviews'] ?? 0) : '—' ?></strong><span class="attention-chip">Perlu telaah</span></div>
                        <div class="metric-foot"><span>Esai, praktik, PAPI, psikometri, dan 360°</span></div>
                    </article>
                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-label">EVALUASI 360°<br>TERISI</span><span class="metric-icon metric-lilac"><svg class="icon"><use href="#i-activity"></use></svg></span></div>
                        <div class="metric-value-row"><strong><?= $databaseOnline ? dashboard_percent($review360Percent) : '—' ?></strong><span class="metric-caption"><?= $databaseOnline ? dashboard_number($review360Completed) . '/' . dashboard_number($review360Total) . ' selesai' : 'penugasan' ?></span></div>
                        <div class="metric-progress"><span style="width: <?= $databaseOnline && $review360Percent !== null ? e((string) min(100, $review360Percent)) : '0' ?>%"></span></div>
                    </article>
                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-label">TINGKAT LULUS<br>STANDAR</span><span class="metric-icon metric-green"><svg class="icon"><use href="#i-check"></use></svg></span></div>
                        <div class="metric-value-row"><strong class="metric-pass"><?= $databaseOnline ? dashboard_percent($passRate) : '—' ?></strong><?php if ($passRate !== null): ?><span class="pass-chip">Terukur</span><?php endif; ?></div>
                        <div class="metric-foot"><span><?= $databaseOnline ? dashboard_number($stats['scored_attempts'] ?? 0) . ' hasil final' : 'Passing grade sesuai paket' ?></span></div>
                    </article>
                </section>

                <div class="dashboard-columns">
                    <div class="dashboard-primary-column">
                        <?php if ($isHr): ?>
                            <section class="panel monitoring-panel">
                                <div class="panel-heading monitoring-heading">
                                    <div><div class="panel-title-row"><span class="live-dot"></span><h2>Monitoring Sesi &amp; Live Proctoring Hari Ini</h2></div><p>Pengawasan sesi ujian berbasis CAT dan keterlaksanaan asesmen.</p></div>
                                    <div class="panel-actions"><button type="button" class="button button-soft button-compact" data-coming-soon><svg class="icon"><use href="#i-send"></use></svg>Broadcast Pesan</button><button type="button" class="button button-navy button-compact" data-coming-soon><svg class="icon"><use href="#i-camera"></use></svg>Buka Matrix Proctor</button></div>
                                </div>
                                <div class="monitoring-list">
                                    <?php if (!$databaseOnline || empty($data['active_sessions'])): ?>
                                        <div class="empty-state compact-empty"><div class="empty-icon"><svg class="icon"><use href="#i-activity"></use></svg></div><div><strong><?= $databaseOnline ? 'Belum ada sesi yang berlangsung' : 'Monitoring belum tersedia' ?></strong><p><?= $databaseOnline ? 'Sesi ujian aktif akan muncul otomatis di bagian ini.' : 'Hubungkan database untuk memuat data sesi ujian.' ?></p></div></div>
                                    <?php else: ?>
                                        <?php foreach ($data['active_sessions'] as $session): ?>
                                            <?php $elapsed = max(0, time() - (strtotime((string) $session['started_at']) ?: time())); ?>
                                            <article class="monitor-card">
                                                <div class="monitor-main-icon"><svg class="icon"><use href="#i-building"></use></svg></div>
                                                <div class="monitor-content">
                                                    <div class="monitor-title-line"><div><h3><?= e($session['package_name']) ?></h3><p><?= e($session['schedule_name']) ?> <span>·</span> <?= e($session['participant_name']) ?></p></div><span class="live-chip live-chip-amber"><i></i>Sedang Berlangsung</span></div>
                                                    <div class="monitor-meta-row"><span>Batch Ref: <b><?= e($session['schedule_code']) ?></b></span><span>Mulai <?= e(dashboard_date($session['started_at'])) ?></span><span class="elapsed-label" data-elapsed="<?= e((string) $elapsed) ?>">Berjalan</span></div>
                                                    <?php if ($isHr): ?><div class="monitor-action-line"><span><svg class="icon"><use href="#i-shield"></use></svg><?= e($session['participant_division'] ?: ($session['participant_type'] === 'candidate' ? 'Calon karyawan' : 'Karyawan internal')) ?></span><button type="button" class="text-action" data-coming-soon>Lihat detail <span>→</span></button></div><?php endif; ?>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="monitor-footer"><span><span class="status-dot"></span> Koneksi peserta dipantau selama sesi ujian</span><button type="button" class="text-action" data-coming-soon>Lihat audit trail <span>→</span></button></div>
                            </section>
                        <?php else: ?>
                            <section class="panel overview-panel">
                                <div class="panel-heading"><div><span class="section-kicker">TREN KOMPETENSI</span><h2>Analisis Distribusi Skor</h2><p>Rangkuman skor otomatis dari asesmen yang telah diselesaikan.</p></div><button type="button" class="button button-soft button-compact" data-coming-soon>Semua siklus <svg class="icon"><use href="#i-chevron"></use></svg></button></div>
                                <?php if (!$databaseOnline || empty($data['scores'])): ?>
                                    <div class="empty-state compact-empty"><div class="empty-icon"><svg class="icon"><use href="#i-chart"></use></svg></div><div><strong>Belum ada data skor terakumulasi</strong><p>Grafik akan terisi setelah peserta menyelesaikan asesmen dan hasilnya diterbitkan.</p></div></div>
                                <?php else: ?>
                                    <div class="competency-list">
                                        <?php foreach ($data['scores'] as $score): ?>
                                            <?php $scorePercent = max(0, min(100, (float) ($score['percentage'] ?? 0))); ?>
                                            <div class="competency-item"><div class="competency-label"><strong><?= e(dashboard_module_label((string) $score['category'])) ?></strong><span><?= e(dashboard_percent($score['percentage'])) ?></span></div><div class="competency-track"><span style="width: <?= e((string) $scorePercent) ?>%"></span></div><small><?= dashboard_number($score['answer_count']) ?> jawaban dinilai otomatis</small></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <section class="panel schedule-panel">
                            <div class="panel-heading"><div><span class="section-kicker">KALENDER ASESMEN</span><h2>Jadwal Ujian Mendatang</h2><p>Paket ujian yang telah dijadwalkan dan siap dikelola.</p></div><?php if ($isHr): ?><a class="text-action" href="admin/schedules.php">Lihat semua <span>→</span></a><?php else: ?><button type="button" class="text-action" data-coming-soon>Lihat semua <span>→</span></button><?php endif; ?></div>
                            <?php if (!$databaseOnline || empty($data['schedules'])): ?>
                                <div class="empty-state compact-empty schedule-empty"><div class="empty-icon"><svg class="icon"><use href="#i-calendar"></use></svg></div><div><strong><?= $databaseOnline ? 'Belum ada jadwal mendatang' : 'Jadwal belum tersedia' ?></strong><p>Jadwal aktif akan ditampilkan di sini.</p></div></div>
                            <?php else: ?>
                                <div class="upcoming-list">
                                    <?php foreach ($data['schedules'] as $schedule): ?>
                                        <article class="upcoming-item"><div class="upcoming-date"><strong><?= e((new DateTimeImmutable((string) $schedule['starts_at']))->format('d')) ?></strong><span><?= e(strtoupper((new DateTimeImmutable((string) $schedule['starts_at']))->format('M'))) ?></span></div><div class="upcoming-info"><strong><?= e($schedule['name']) ?></strong><span><?= e($schedule['package_name']) ?> · <?= dashboard_number($schedule['participant_count']) ?> peserta</span><small><?= e(dashboard_date($schedule['starts_at'])) ?></small></div><span class="status-pill <?= $schedule['status'] === 'open' ? 'status-live' : 'status-neutral' ?>"><?= $schedule['status'] === 'open' ? 'Berlangsung' : 'Terjadwal' ?></span></article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>

                    <aside class="dashboard-side-column">
                        <?php if ($isHr): ?>
                            <section class="panel action-panel">
                                <div class="panel-heading side-panel-heading"><div><h2>Tindakan HR</h2><p>Prioritas yang membutuhkan tindak lanjut</p></div><span class="attention-count"><?= $databaseOnline ? dashboard_number($stats['pending_reviews'] ?? 0) : '—' ?><small> review</small></span></div>
                                <div class="action-list">
                                    <?php if (!$databaseOnline || empty($data['reviews'])): ?>
                                        <div class="empty-state compact-empty action-empty"><div class="empty-icon"><svg class="icon"><use href="#i-check"></use></svg></div><div><strong><?= $databaseOnline ? 'Antrean review bersih' : 'Antrean belum tersedia' ?></strong><p><?= $databaseOnline ? 'Tidak ada penilaian manual yang menunggu.' : 'Hubungkan database untuk memuat antrean.' ?></p></div></div>
                                    <?php else: ?>
                                        <?php foreach (array_slice($data['reviews'], 0, 3) as $review): ?>
                                            <a class="action-card" href="admin/reviews.php?id=<?= (int) $review['id'] ?>#review-detail">
                                                <span class="action-icon <?= $review['review_type'] === 'papi' || $review['review_type'] === 'psychometric' ? 'action-icon-amber' : 'action-icon-rose' ?>"><svg class="icon"><use href="#i-<?= in_array($review['review_type'], ['papi', 'psychometric'], true) ? 'activity' : 'file' ?>"></use></svg></span>
                                                <span class="action-card-copy"><strong><?= e(dashboard_review_label((string) $review['review_type'])) ?> · <?= e($review['participant_name']) ?></strong><span><?= e($review['package_name']) ?></span><small><?= e($review['schedule_name']) ?></small><em>Buka item penilaian <span>→</span></em></span>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <a class="button button-outline button-full" href="admin/reviews.php">Buka antrean penilaian <svg class="icon"><use href="#i-arrow"></use></svg></a>
                            </section>
                        <?php else: ?>
                            <section class="panel action-panel">
                                <div class="panel-heading side-panel-heading"><div><h2>Status Evaluasi 360°</h2><p>Penilaian rekan kerja yang terkonsolidasi</p></div><span class="metric-icon metric-lilac"><svg class="icon"><use href="#i-users"></use></svg></span></div>
                                <div class="management-stat"><strong><?= $databaseOnline ? dashboard_percent($review360Percent) : '—' ?></strong><span><?= dashboard_number($review360Completed) ?> dari <?= dashboard_number($review360Total) ?> penugasan selesai</span></div>
                                <div class="metric-progress wide-progress"><span style="width: <?= $databaseOnline && $review360Percent !== null ? e((string) min(100, $review360Percent)) : '0' ?>%"></span></div>
                                <div class="privacy-note"><svg class="icon"><use href="#i-shield"></use></svg><span>Ringkasan agregat ditampilkan sesuai hak akses manajemen.</span></div>
                            </section>
                        <?php endif; ?>

                        <section class="panel score-panel">
                            <div class="panel-heading side-panel-heading"><div><h2><?= $isHr ? 'Evaluasi Kompetensi' : 'Capaian Kompetensi' ?></h2><p><?= $isHr ? 'Skor objektif per kategori' : 'Ringkasan hasil terukur' ?></p></div><span class="score-count"><?= $databaseOnline ? dashboard_number(count($data['scores'] ?? [])) : '—' ?><small>kategori</small></span></div>
                            <?php if (!$databaseOnline || empty($data['scores'])): ?>
                                <div class="muted-empty">Data kompetensi akan tampil setelah hasil asesmen tersedia.</div>
                            <?php else: ?>
                                <div class="side-score-list">
                                    <?php foreach (array_slice($data['scores'], 0, 4) as $score): ?>
                                        <?php $scorePercent = max(0, min(100, (float) ($score['percentage'] ?? 0))); ?>
                                        <div class="side-score-item"><div><span><?= e(dashboard_module_label((string) $score['category'])) ?></span><strong><?= e(dashboard_percent($score['percentage'])) ?></strong></div><div class="score-track"><i style="width: <?= e((string) $scorePercent) ?>%"></i></div></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <a href="reports.php" class="button button-soft button-full"><svg class="icon"><use href="#i-chart"></use></svg>Lihat analisis lengkap</a>
                        </section>

                        <section class="panel resources-panel">
                            <div class="panel-heading side-panel-heading"><div><h2>Pintasan &amp; Dokumen</h2><p>Sumber daya tim asesmen</p></div></div>
                            <button type="button" class="resource-link" data-coming-soon><span class="resource-icon resource-blue"><svg class="icon"><use href="#i-layers"></use></svg></span><span><strong>Template Import Soal CAT</strong><small>Format standar Excel (.xlsx)</small></span><svg class="icon resource-arrow"><use href="#i-download"></use></svg></button>
                            <button type="button" class="resource-link" data-coming-soon><span class="resource-icon resource-gold"><svg class="icon"><use href="#i-book"></use></svg></span><span><strong>Panduan Penilaian Asesor BUMD</strong><small>Standar kompetensi &amp; rubrik (.pdf)</small></span><svg class="icon resource-arrow"><use href="#i-download"></use></svg></button>
                            <button type="button" class="resource-link" data-coming-soon><span class="resource-icon resource-green"><svg class="icon"><use href="#i-file"></use></svg></span><span><strong>Generator Token Cepat</strong><small>Pembuatan kunci sesi darurat</small></span><svg class="icon resource-arrow"><use href="#i-arrow"></use></svg></button>
                            <div class="compliance-note"><svg class="icon"><use href="#i-shield"></use></svg><span>Data asesmen dikelola sesuai prinsip kerahasiaan dan tata kelola perusahaan.</span></div>
                        </section>
                    </aside>
                </div>
            <?php endif; ?>

            <footer class="page-footer"><span>© <?= e(date('Y')) ?> PT Lampung Jasa Utama · E-Assessment Suite</span><span><svg class="icon"><use href="#i-shield"></use></svg> Akses terproteksi melalui SSO LJU</span></footer>
        </main>
    </div>
</div>
<div class="toast" id="app-toast" role="status" aria-live="polite"></div>
</body>
</html>
