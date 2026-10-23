<?php
declare(strict_types=1);

$portalNavBase = isset($portalNavBase) ? (string) $portalNavBase : '';
$portalNavActive = isset($portalNavActive) ? (string) $portalNavActive : '';
$portalNavIsAuthenticated = app_is_authenticated();
$portalNavIsHr = $portalNavIsAuthenticated && app_is_hr();
$portalNavIsManagement = $portalNavIsAuthenticated && app_is_management();
$portalBrandHref = $portalNavIsAuthenticated ? $portalNavBase . 'index.php' : $portalNavBase . 'lobby.php';

$portalNavItems = [];
if ($portalNavIsAuthenticated) {
    $portalNavItems[] = ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => $portalNavBase . 'index.php', 'icon' => 'dashboard'];
}
$portalNavItems[] = ['id' => 'lobby', 'label' => 'Lobby Ujian', 'href' => $portalNavBase . 'lobby.php', 'icon' => 'lobby'];

if ($portalNavIsHr) {
    $portalNavItems[] = ['id' => 'questions', 'label' => 'Bank Soal', 'href' => $portalNavBase . 'admin/question_bank.php', 'icon' => 'questions'];
    $portalNavItems[] = ['id' => 'builder', 'label' => 'Assessment Studio', 'href' => $portalNavBase . 'admin/assessment_builder.php?view=modules', 'icon' => 'builder'];
    $portalNavItems[] = ['id' => 'schedules', 'label' => 'Jadwal & Undangan', 'href' => $portalNavBase . 'admin/schedules.php', 'icon' => 'schedules'];
    $portalNavItems[] = ['id' => 'reviews', 'label' => 'Portal Penilai HR', 'href' => $portalNavBase . 'admin/reviews.php', 'icon' => 'reviews'];
    $portalNavItems[] = ['id' => 'reports', 'label' => 'Laporan & Hasil', 'href' => $portalNavBase . 'reports.php', 'icon' => 'reports'];
    $portalNavItems[] = ['id' => 'leaderboard', 'label' => 'Leaderboard', 'href' => $portalNavBase . 'admin/leaderboard.php', 'icon' => 'leaderboard'];
} elseif ($portalNavIsManagement) {
    $portalNavItems[] = ['id' => 'reports', 'label' => 'Laporan & Hasil', 'href' => $portalNavBase . 'reports.php', 'icon' => 'reports'];
}
?>
<svg class="portal-icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="portal-icon-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></symbol>
    <symbol id="portal-icon-lobby" viewBox="0 0 24 24"><path d="M4 21h16M6 21V4.8a2 2 0 0 1 1.6-2l9-1.8A2 2 0 0 1 19 3v18M13 12h.01"/></symbol>
    <symbol id="portal-icon-questions" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></symbol>
    <symbol id="portal-icon-builder" viewBox="0 0 24 24"><path d="M4 4h7v7H4zM13 4h7v4h-7zM13 10h7v10h-7zM4 13h7v7H4z"/></symbol>
    <symbol id="portal-icon-schedules" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/></symbol>
    <symbol id="portal-icon-reviews" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4.5h6a1.5 1.5 0 0 0-1.5-1.5h-3A1.5 1.5 0 0 0 9 4.5ZM9 10h6M9 14h6M9 18h3"/></symbol>
    <symbol id="portal-icon-reports" viewBox="0 0 24 24"><path d="M3 3v18h18M7 14l4-4 4 3 6-7M17 6h4v4"/></symbol>
    <symbol id="portal-icon-leaderboard" viewBox="0 0 24 24"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4ZM7 6H4v2a4 4 0 0 0 4 4M17 6h3v2a4 4 0 0 1-4 4"/><path d="m12 6 1.1 2.2 2.4.4-1.8 1.7.4 2.4-2.1-1.1-2.1 1.1.4-2.4-1.8-1.7 2.4-.4L12 6Z"/></symbol>
    <symbol id="portal-icon-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
</svg>
<aside class="portal-sidebar" id="portal-sidebar" aria-label="Navigasi utama">
    <a class="portal-brand" href="<?= e($portalBrandHref) ?>">
        <span class="portal-brand-mark"><img src="https://dms.lju.co.id/assets/img/logo.png" alt="" onerror="this.parentElement.classList.add('is-logo-fallback')"><span aria-hidden="true">LJU</span></span>
        <span class="portal-brand-copy"><strong>E-ASSESSMENT</strong><small>PT Lampung Jasa Utama</small></span>
    </a>
    <div class="portal-nav-label">MENU UTAMA</div>
    <nav class="portal-nav" aria-label="Navigasi aplikasi">
        <?php foreach ($portalNavItems as $item): ?>
            <?php
            $isCurrent = $portalNavActive === $item['id'];
            $isLobbyArea = $item['id'] === 'lobby' && $portalNavActive === 'exam';
            ?>
            <a class="portal-nav-link<?= ($isCurrent || $isLobbyArea) ? ' is-active' : '' ?>" href="<?= e($item['href']) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                <svg class="portal-nav-icon" aria-hidden="true"><use href="#portal-icon-<?= e($item['icon']) ?>"></use></svg>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="portal-sidebar-footer"><span class="portal-status-dot"></span><span>Portal Asesmen Terpadu</span></div>
</aside>
<button class="portal-menu-toggle" type="button" aria-label="Buka menu navigasi" aria-expanded="false" aria-controls="portal-sidebar" data-portal-menu-toggle>
    <svg class="portal-nav-icon" aria-hidden="true"><use href="#portal-icon-menu"></use></svg>
</button>
<button class="portal-sidebar-backdrop" type="button" aria-label="Tutup menu navigasi" tabindex="-1" data-portal-menu-close></button>
