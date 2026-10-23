<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/question_bank_service.php';
app_require_hr();

$categories = question_bank_categories();
$types = question_bank_types();
$flash = app_flash('question_bank');
$formError = null;
$databaseError = null;
$summary = ['total' => 0, 'active' => 0, 'archived' => 0];
$list = ['items' => [], 'filters' => ['q' => '', 'category' => '', 'type' => '', 'state' => 'active']];
$formQuestion = [
    'id' => 0,
    'code' => '',
    'category' => 'OTHER',
    'question_type' => 'single_choice',
    'question_text' => '',
    'instructions' => '',
    'papi_item_code' => '',
    'rubric' => '',
    'allowed_extensions' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,csv,txt,zip',
    'max_file_size_mb' => '',
    'max_score' => '1.00',
    'weight' => '1.00',
    'has_attempts' => false,
];
$formOptions = [
    ['text' => '', 'is_correct' => '1', 'papi_aspect_code' => ''],
    ['text' => '', 'is_correct' => '', 'papi_aspect_code' => ''],
];
$wasPosted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

function question_bank_category_name(string $category, array $categories): string
{
    return $categories[$category] ?? $category;
}

function question_bank_type_name(string $type, array $types): string
{
    return $types[$type] ?? 'Jenis lainnya';
}

function question_bank_preview_text(string $text, int $limit = 112): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }

    return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
}

function question_bank_row_options(array $options): array
{
    $rows = [];
    foreach (array_values($options) as $option) {
        if (!is_array($option)) {
            continue;
        }
        $rawCorrect = $option['is_correct'] ?? false;
        $isCorrect = $rawCorrect === true || in_array(question_bank_scalar_text($rawCorrect), ['1', 'on', 'true'], true);
        $rows[] = [
            'text' => question_bank_scalar_text($option['text'] ?? $option['option_text'] ?? ''),
            'is_correct' => $isCorrect ? '1' : '',
            'papi_aspect_code' => question_bank_scalar_text($option['papi_aspect_code'] ?? ''),
        ];
    }

    return $rows;
}

if ($wasPosted) {
    foreach (['id', 'code', 'category', 'question_type', 'question_text', 'instructions', 'papi_item_code', 'rubric', 'allowed_extensions', 'max_file_size_mb', 'max_score', 'weight'] as $field) {
        $formQuestion[$field] = question_bank_scalar_text($_POST[$field] ?? ($formQuestion[$field] ?? ''));
    }
    $formQuestion['id'] = max(0, (int) $formQuestion['id']);
    $formOptions = question_bank_row_options(is_array($_POST['options'] ?? null) ? $_POST['options'] : []);
    if ($formOptions === []) {
        $formOptions = [['text' => '', 'is_correct' => '', 'papi_aspect_code' => '']];
    }

    $csrfCandidate = $_POST['_csrf_token'] ?? null;
    if (!is_string($csrfCandidate) || !app_verify_csrf($csrfCandidate)) {
        http_response_code(419);
        $formError = 'Sesi formulir kedaluwarsa. Muat ulang halaman sebelum menyimpan.';
    } else {
        $action = question_bank_scalar_text($_POST['action'] ?? 'save');
        try {
            if ($action === 'archive' || $action === 'restore') {
                $postedQuestionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
                question_bank_set_active(is_int($postedQuestionId) ? $postedQuestionId : 0, $action === 'restore');
                app_flash('question_bank', $action === 'restore' ? 'Soal diaktifkan kembali.' : 'Soal diarsipkan; soal tidak akan masuk ke sesi baru.');
                app_redirect('question_bank.php?state=' . ($action === 'restore' ? 'archived' : 'active'));
            }
            if ($action !== 'save') {
                throw new DomainException('Aksi tidak dikenal.');
            }

            $savedId = question_bank_save($_POST, (int) (app_user()['id'] ?? 0));
            app_flash('question_bank', $formQuestion['id'] > 0 ? 'Perubahan soal berhasil disimpan.' : 'Soal baru berhasil ditambahkan ke bank soal.');
            app_redirect('question_bank.php?edit=' . $savedId . '#question-editor');
        } catch (DomainException $exception) {
            $formError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('E-Assessment question bank action failed: ' . $exception->getMessage());
            $formError = 'Soal belum dapat disimpan. Periksa koneksi database atau hubungi administrator.';
        }
    }
} elseif (isset($_GET['edit'])) {
    try {
        $editValue = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
        $loadedQuestion = question_bank_get(is_int($editValue) ? $editValue : 0);
        if ($loadedQuestion === null) {
            $formError = 'Soal yang diminta tidak ditemukan.';
        } else {
            $formQuestion = array_merge($formQuestion, $loadedQuestion);
            $formOptions = question_bank_row_options($loadedQuestion['options'] ?? []);
            if ($formOptions === []) {
                $formOptions = [['text' => '', 'is_correct' => '', 'papi_aspect_code' => '']];
            }
            if (!empty($loadedQuestion['has_attempts'])) {
                $formQuestion['has_attempts'] = true;
            }
        }
    } catch (Throwable $exception) {
        error_log('E-Assessment question bank edit load failed: ' . $exception->getMessage());
        $formError = 'Data soal belum dapat dimuat. Periksa koneksi database.';
    }
}

$filterInput = [
    'q' => question_bank_scalar_text($_GET['q'] ?? ''),
    'category' => question_bank_scalar_text($_GET['category'] ?? ''),
    'type' => question_bank_scalar_text($_GET['type'] ?? ''),
    'state' => question_bank_scalar_text($_GET['state'] ?? 'active'),
];
try {
    $summary = question_bank_summary();
    $list = question_bank_list($filterInput);
} catch (Throwable $exception) {
    error_log('E-Assessment question bank list load failed: ' . $exception->getMessage());
    $databaseError = 'Bank soal belum dapat dimuat. Pastikan database/schema.sql telah diimpor dan koneksi database aktif.';
}

$questionId = (int) ($formQuestion['id'] ?? 0);
$questionLocked = !empty($formQuestion['has_attempts']);
$questionType = (string) ($formQuestion['question_type'] ?? 'single_choice');
$displayName = (string) (app_user()['name'] ?? 'Pengguna');
$avatarUrl = app_avatar_url();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7fb">
    <title>Bank Soal · E-Assessment LJU</title>
    <link rel="stylesheet" href="../assets/app.css?v=1">
    <link rel="stylesheet" href="../assets/question-bank.css?v=1">
    <link rel="stylesheet" href="../assets/portal-ui.css?v=1">
    <script src="../assets/question-bank.js?v=1" defer></script>
    <script src="../assets/portal-ui.js?v=1" defer></script>
</head>
<body class="question-bank-page has-portal-sidebar">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="qb-layers" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></symbol>
    <symbol id="qb-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></symbol>
    <symbol id="qb-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="qb-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></symbol>
    <symbol id="qb-edit" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></symbol>
    <symbol id="qb-archive" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9M10 13h4"/></symbol>
    <symbol id="qb-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="qb-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
    <symbol id="qb-warning" viewBox="0 0 24 24"><path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0ZM12 9v4M12 17h.01"/></symbol>
    <symbol id="qb-x" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
</svg>
<?php $portalNavBase = '../'; $portalNavActive = 'questions'; require dirname(__DIR__, 2) . '/app/portal_sidebar.php'; ?>

<header class="qb-topbar">
    <a class="qb-brand" href="../index.php" aria-label="Kembali ke Dashboard E-Assessment">
        <span class="qb-brand-mark"><img src="https://dms.lju.co.id/assets/img/logo.png" alt="" data-brand-logo><span class="qb-brand-fallback">LJU</span></span>
        <span><strong>E-ASSESSMENT</strong><small>PT Lampung Jasa Utama</small></span>
    </a>
    <nav class="qb-top-nav" aria-label="Navigasi HR">
        <a href="../index.php">Dashboard</a>
        <a href="../lobby.php">Lobby ujian</a>
        <span class="qb-top-nav-current">Bank soal</span>
    </nav>
    <div class="qb-user">
        <span class="qb-avatar"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="" data-avatar><?php endif; ?><span class="avatar-fallback" <?= $avatarUrl ? 'hidden' : '' ?>><?= e(app_user_initials($displayName)) ?></span></span>
        <span><strong><?= e($displayName) ?></strong><small><?= e((string) (app_user()['position'] ?? 'HR/Admin')) ?></small></span>
    </div>
</header>

<main class="qb-main">
    <div class="qb-breadcrumb"><a href="../index.php">Dashboard HR</a><span>/</span><strong>Bank soal</strong></div>
    <section class="qb-hero">
        <div class="qb-hero-icon"><svg class="icon"><use href="#qb-layers"></use></svg></div>
        <div class="qb-hero-copy"><span class="qb-eyebrow">ASSESSMENT STUDIO · HR/ADMIN</span><h1>Bank Soal</h1><p>Susun dan kelola soal asesmen secara terstruktur. Soal yang sudah pernah dipakai dalam sesi peserta akan dikunci untuk menjaga integritas hasil.</p></div>
        <a class="qb-primary-button" href="#question-editor"><svg class="icon"><use href="#qb-plus"></use></svg>Tambah soal</a>
        <div class="qb-hero-orbit" aria-hidden="true"></div>
    </section>

    <nav class="qb-module-tabs" aria-label="Modul penyusunan asesmen">
        <a class="is-active" href="question_bank.php"><svg class="icon"><use href="#qb-layers"></use></svg>Bank soal</a>
        <a href="assessment_builder.php?view=modules"><svg class="icon"><use href="#qb-file"></use></svg>Modul &amp; paket tes</a>
    </nav>

    <?php if ($flash): ?><div class="qb-alert qb-alert-success" role="status"><svg class="icon"><use href="#qb-check"></use></svg><span><?= e($flash) ?></span></div><?php endif; ?>
    <?php if ($formError): ?><div class="qb-alert qb-alert-error" role="alert"><svg class="icon"><use href="#qb-warning"></use></svg><span><?= e($formError) ?></span></div><?php endif; ?>
    <?php if ($databaseError): ?><div class="qb-alert qb-alert-error" role="alert"><svg class="icon"><use href="#qb-warning"></use></svg><span><?= e($databaseError) ?></span></div><?php endif; ?>

    <section class="qb-stat-grid" aria-label="Ringkasan bank soal">
        <article class="qb-stat-card"><span class="qb-stat-icon qb-stat-blue"><svg class="icon"><use href="#qb-layers"></use></svg></span><div><small>SELURUH SOAL</small><strong><?= number_format($summary['total'], 0, ',', '.') ?></strong></div></article>
        <article class="qb-stat-card"><span class="qb-stat-icon qb-stat-green"><svg class="icon"><use href="#qb-check"></use></svg></span><div><small>AKTIF</small><strong><?= number_format($summary['active'], 0, ',', '.') ?></strong></div></article>
        <article class="qb-stat-card"><span class="qb-stat-icon qb-stat-muted"><svg class="icon"><use href="#qb-archive"></use></svg></span><div><small>DIARSIPKAN</small><strong><?= number_format($summary['archived'], 0, ',', '.') ?></strong></div></article>
    </section>

    <div class="qb-workspace-grid">
        <section class="qb-panel qb-list-panel">
            <div class="qb-panel-heading"><div><span class="qb-section-kicker">KATALOG PERTANYAAN</span><h2>Daftar soal</h2><p><?= count($list['items']) ?> soal tampil · maksimal 200 hasil per pencarian</p></div><span class="qb-list-count"><?= number_format($summary['active'], 0, ',', '.') ?> aktif</span></div>
            <form class="qb-filter-form" method="get" action="question_bank.php">
                <label class="qb-search-field"><svg class="icon"><use href="#qb-search"></use></svg><input type="search" name="q" value="<?= e($list['filters']['q']) ?>" placeholder="Cari kode atau teks soal" aria-label="Cari soal"></label>
                <select name="category" aria-label="Filter kategori"><option value="">Semua kategori</option><?php foreach ($categories as $value => $label): ?><option value="<?= e($value) ?>" <?= $list['filters']['category'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <select name="type" aria-label="Filter jenis"><option value="">Semua jenis</option><?php foreach ($types as $value => $label): ?><option value="<?= e($value) ?>" <?= $list['filters']['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <select name="state" aria-label="Filter status"><option value="active" <?= $list['filters']['state'] === 'active' ? 'selected' : '' ?>>Soal aktif</option><option value="archived" <?= $list['filters']['state'] === 'archived' ? 'selected' : '' ?>>Diarsipkan</option><option value="all" <?= $list['filters']['state'] === 'all' ? 'selected' : '' ?>>Semua status</option></select>
                <button type="submit" class="qb-filter-button">Terapkan</button>
            </form>
            <div class="qb-table-scroll">
                <table class="qb-table">
                    <thead><tr><th>SOAL / KODE</th><th>KATEGORI &amp; JENIS</th><th>STRUKTUR</th><th>STATUS</th><th><span class="visually-hidden">Aksi</span></th></tr></thead>
                    <tbody>
                    <?php if ($list['items'] === []): ?>
                        <tr><td colspan="5"><div class="qb-empty"><span><svg class="icon"><use href="#qb-file"></use></svg></span><strong>Belum ada soal yang cocok</strong><p>Ubah filter pencarian atau tambahkan soal pertama ke bank.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($list['items'] as $item): ?>
                            <?php $active = (bool) $item['is_active']; ?>
                            <tr>
                                <td><div class="qb-question-cell"><strong><?= e($item['code'] ?: 'Tanpa kode') ?></strong><p><?= e(question_bank_preview_text((string) $item['question_text'])) ?></p><small><?= e(number_format((float) $item['max_score'], 2, ',', '.')) ?> poin · bobot <?= e(number_format((float) $item['weight'], 2, ',', '.')) ?></small></div></td>
                                <td><span class="qb-category-pill"><?= e(question_bank_category_name((string) $item['category'], $categories)) ?></span><small class="qb-type-label"><?= e(question_bank_type_name((string) $item['question_type'], $types)) ?></small></td>
                                <td><div class="qb-structure"><span><?= (int) $item['option_count'] ?> pilihan</span><span><?= (int) $item['module_count'] ?> modul</span><span><?= (int) $item['attempt_count'] ?> sesi</span></div></td>
                                <td><span class="qb-status <?= $active ? 'is-active' : 'is-archived' ?>"><i></i><?= $active ? 'Aktif' : 'Arsip' ?></span></td>
                                <td><div class="qb-row-actions">
                                    <a class="qb-icon-action" href="question_bank.php?edit=<?= (int) $item['id'] ?>#question-editor" aria-label="Edit <?= e($item['code'] ?: 'soal') ?>" title="Lihat atau edit"><svg class="icon"><use href="#qb-edit"></use></svg></a>
                                    <form method="post" action="question_bank.php" data-confirm-action="<?= $active ? 'Arsipkan soal ini? Soal tidak akan digunakan pada sesi baru.' : 'Aktifkan kembali soal ini?' ?>">
                                        <?= app_csrf_input() ?><input type="hidden" name="question_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="<?= $active ? 'archive' : 'restore' ?>">
                                        <button class="qb-icon-action <?= $active ? 'is-danger' : 'is-restore' ?>" type="submit" aria-label="<?= $active ? 'Arsipkan soal' : 'Aktifkan soal' ?>" title="<?= $active ? 'Arsipkan' : 'Aktifkan' ?>"><svg class="icon"><use href="#qb-<?= $active ? 'archive' : 'check' ?>"></use></svg></button>
                                    </form>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="qb-panel qb-editor-panel" id="question-editor">
            <div class="qb-panel-heading"><div><span class="qb-section-kicker"><?= $questionId > 0 ? 'KELOLA PERTANYAAN' : 'BUAT PERTANYAAN' ?></span><h2><?= $questionId > 0 ? 'Edit soal' : 'Soal baru' ?></h2><p><?= $questionId > 0 ? 'Perbarui informasi soal dan pilihan jawaban.' : 'Isi detail soal dan tentukan bentuk respons peserta.' ?></p></div><?php if ($questionId > 0 && !$wasPosted): ?><a class="qb-reset-link" href="question_bank.php#question-editor">Batal edit</a><?php endif; ?></div>
            <?php if ($questionLocked): ?>
                <div class="qb-lock-notice"><svg class="icon"><use href="#qb-warning"></use></svg><div><strong>Konten soal dikunci</strong><p>Soal ini sudah masuk snapshot sesi ujian. Untuk menjaga validitas hasil, kontennya tidak dapat diubah. Arsipkan lalu buat soal baru bila perlu revisi.</p></div></div>
            <?php endif; ?>
            <form method="post" action="question_bank.php#question-editor" class="qb-editor-form" data-question-editor>
                <?= app_csrf_input() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= $questionId ?>">
                <fieldset <?= $questionLocked ? 'disabled' : '' ?>>
                    <div class="qb-form-grid qb-form-grid-top">
                        <label class="qb-field"><span>Kode soal <small>opsional</small></span><input name="code" type="text" maxlength="50" value="<?= e($formQuestion['code'] ?? '') ?>" placeholder="Contoh: TPA-LOG-001"></label>
                        <label class="qb-field"><span>Kategori</span><select name="category" required><?php foreach ($categories as $value => $label): ?><option value="<?= e($value) ?>" <?= ($formQuestion['category'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    </div>
                    <label class="qb-field"><span>Jenis soal</span><select name="question_type" data-question-type required><?php foreach ($types as $value => $label): ?><option value="<?= e($value) ?>" <?= $questionType === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    <label class="qb-field"><span>Teks soal <b>*</b></span><textarea name="question_text" rows="5" maxlength="60000" required placeholder="Tuliskan pertanyaan secara jelas. Baris baru akan dipertahankan saat ditampilkan."><?= e($formQuestion['question_text'] ?? '') ?></textarea><small class="qb-field-help">Teks ditampilkan sebagai teks biasa; jangan masukkan kata sandi atau data pribadi peserta.</small></label>
                    <div class="qb-form-grid qb-form-grid-scores">
                        <label class="qb-field"><span>Skor maksimum</span><input name="max_score" type="number" min="0.01" max="999999.99" step="0.01" value="<?= e((string) ($formQuestion['max_score'] ?? '1.00')) ?>" required></label>
                        <label class="qb-field"><span>Bobot</span><input name="weight" type="number" min="0.01" max="999999.99" step="0.01" value="<?= e((string) ($formQuestion['weight'] ?? '1.00')) ?>" required></label>
                    </div>

                    <section class="qb-options-section" data-options-section <?= in_array($questionType, ['single_choice', 'multi_select', 'forced_choice'], true) ? '' : 'hidden' ?>>
                        <div class="qb-options-heading"><div><strong>Pilihan jawaban</strong><small data-option-help>Tambahkan minimal dua pilihan.</small></div><button class="qb-add-option" type="button" data-add-option><svg class="icon"><use href="#qb-plus"></use></svg>Tambah pilihan</button></div>
                        <div class="qb-option-list" data-option-list>
                            <?php foreach ($formOptions as $index => $option): ?>
                                <div class="qb-option-row" data-option-row>
                                    <span class="qb-option-key" data-option-key><?= e(chr(65 + (int) $index)) ?></span>
                                    <label class="qb-option-text"><span class="visually-hidden">Teks pilihan <?= e(chr(65 + (int) $index)) ?></span><textarea name="options[<?= (int) $index ?>][text]" maxlength="5000" rows="2" placeholder="Teks pilihan jawaban"><?= e($option['text']) ?></textarea></label>
                                    <label class="qb-correct-control" data-correct-control><input type="checkbox" name="options[<?= (int) $index ?>][is_correct]" value="1" <?= $option['is_correct'] === '1' ? 'checked' : '' ?>><span>Benar</span></label>
                                    <label class="qb-aspect-control" data-aspect-control><span class="visually-hidden">Kode aspek PAPI</span><input type="text" name="options[<?= (int) $index ?>][papi_aspect_code]" maxlength="40" value="<?= e($option['papi_aspect_code']) ?>" placeholder="Aspek PAPI"></label>
                                    <button class="qb-remove-option" type="button" data-remove-option aria-label="Hapus pilihan"><svg class="icon"><use href="#qb-x"></use></svg></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <template id="qb-option-template"><div class="qb-option-row" data-option-row><span class="qb-option-key" data-option-key></span><label class="qb-option-text"><span class="visually-hidden">Teks pilihan</span><textarea maxlength="5000" rows="2" placeholder="Teks pilihan jawaban"></textarea></label><label class="qb-correct-control" data-correct-control><input type="checkbox" value="1"><span>Benar</span></label><label class="qb-aspect-control" data-aspect-control><span class="visually-hidden">Kode aspek PAPI</span><input type="text" maxlength="40" placeholder="Aspek PAPI"></label><button class="qb-remove-option" type="button" data-remove-option aria-label="Hapus pilihan"><svg class="icon"><use href="#qb-x"></use></svg></button></div></template>
                    </section>

                    <div class="qb-form-grid qb-form-grid-papi" data-papi-field <?= $questionType === 'forced_choice' ? '' : 'hidden' ?>>
                        <label class="qb-field"><span>Kode item PAPI <small>opsional</small></span><input name="papi_item_code" type="text" maxlength="40" value="<?= e($formQuestion['papi_item_code'] ?? '') ?>" placeholder="Contoh: PAPI-01"><small class="qb-field-help">Wajib untuk kategori PAPI Kostick. Setiap pilihan PAPI juga perlu kode aspek.</small></label>
                        <div class="qb-field qb-field-note"><span>Forced choice</span><p>Peserta memilih Most dan Least. Jawaban tidak memiliki kunci benar/salah.</p></div>
                    </div>

                    <label class="qb-field" data-instructions-field><span>Instruksi peserta <small>opsional</small></span><textarea name="instructions" rows="2" maxlength="12000" placeholder="Petunjuk khusus sebelum peserta menjawab."><?= e($formQuestion['instructions'] ?? '') ?></textarea></label>
                    <label class="qb-field" data-rubric-field><span>Rubrik penilaian / catatan asesor <small>opsional</small></span><textarea name="rubric" rows="3" maxlength="60000" placeholder="Kriteria penilaian untuk esai, praktik, psikometri, atau review manual."><?= e($formQuestion['rubric'] ?? '') ?></textarea></label>

                    <section class="qb-file-fields" data-file-fields <?= $questionType === 'file_upload' ? '' : 'hidden' ?>>
                        <div class="qb-file-heading"><svg class="icon"><use href="#qb-file"></use></svg><div><strong>Pengaturan unggah file</strong><small>File peserta disimpan di area privat, di luar web root.</small></div></div>
                        <div class="qb-form-grid qb-form-grid-file">
                            <label class="qb-field"><span>Ekstensi yang diizinkan</span><input name="allowed_extensions" type="text" maxlength="255" value="<?= e($formQuestion['allowed_extensions'] ?? '') ?>" placeholder="pdf,docx,jpg"><small class="qb-field-help">Pisahkan dengan koma. Kosongkan untuk memakai daftar bawaan.</small></label>
                            <label class="qb-field"><span>Maksimal ukuran (MB) <small>opsional</small></span><input name="max_file_size_mb" type="number" min="1" max="20" step="1" value="<?= e((string) ($formQuestion['max_file_size_mb'] ?? '')) ?>" placeholder="20"></label>
                        </div>
                    </section>
                </fieldset>
                <div class="qb-form-actions">
                    <?php if ($questionLocked): ?><a class="qb-secondary-button" href="question_bank.php">Kembali ke bank soal</a><?php else: ?><button class="qb-primary-button" type="submit"><svg class="icon"><use href="#qb-check"></use></svg><?= $questionId > 0 ? 'Simpan perubahan' : 'Simpan soal' ?></button><?php if ($questionId > 0): ?><a class="qb-secondary-button" href="question_bank.php#question-editor">Batalkan</a><?php endif; ?><?php endif; ?>
                </div>
            </form>
        </section>
    </div>
    <footer class="qb-footer"><span>© <?= e(date('Y')) ?> PT Lampung Jasa Utama</span><span>Assessment Studio · HR/Admin</span></footer>
</main>
<div class="qb-toast" id="qb-toast" role="status" aria-live="polite"></div>
</body>
</html>
