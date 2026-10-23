<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/assessment_builder_service.php';
app_require_hr();

$view = assessment_builder_scalar($_POST['view'] ?? $_GET['view'] ?? 'modules');
if (!in_array($view, ['modules', 'packages'], true)) {
    $view = 'modules';
}
$flash = app_flash('assessment_builder');
$formError = null;
$databaseError = null;
$wasPosted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$moduleDefaults = [
    'id' => 0,
    'code' => '',
    'name' => '',
    'category' => 'OTHER',
    'module_kind' => 'standard',
    'description' => '',
    'duration_minutes' => '30',
    'question_selection_mode' => 'selected',
    'random_question_count' => '10',
    'shuffle_questions' => '',
    'shuffle_options' => '',
    'status' => 'draft',
];
$packageDefaults = [
    'id' => 0,
    'code' => '',
    'name' => '',
    'package_type' => 'recruitment',
    'description' => '',
    'target_position' => '',
    'passing_score' => '',
    'status' => 'draft',
];
$formModule = $moduleDefaults;
$formPackage = $packageDefaults;
$selectedQuestionIds = [];
$questionSettings = [];
$selectedModuleIds = [];
$moduleSettings = [];
$moduleLocked = false;
$packageLocked = false;
$moduleRows = [];
$packageRows = [];
$questionRows = [];
$moduleCatalog = [];
$moduleSummary = ['total' => 0, 'published' => 0, 'draft' => 0];
$packageSummary = ['total' => 0, 'published' => 0, 'draft' => 0];

function builder_status_label(string $status): string
{
    return match ($status) {
        'published' => 'Terbit',
        'archived' => 'Arsip',
        default => 'Draft',
    };
}

function builder_status_class(string $status): string
{
    return match ($status) {
        'published' => 'is-published',
        'archived' => 'is-archived',
        default => 'is-draft',
    };
}

function builder_question_preview(string $text, int $limit = 82): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
}

function builder_required_value(mixed $value): string
{
    return $value === true || in_array(assessment_builder_scalar($value), ['1', 'on', 'true'], true) ? '1' : '';
}

if ($wasPosted) {
    $csrf = $_POST['_csrf_token'] ?? null;
    if (!is_string($csrf) || !app_verify_csrf($csrf)) {
        http_response_code(419);
        $formError = 'Sesi formulir kedaluwarsa. Muat ulang halaman sebelum menyimpan.';
    } else {
        $action = assessment_builder_scalar($_POST['action'] ?? '');
        try {
            if ($action === 'save_module') {
                foreach (array_keys($moduleDefaults) as $field) {
                    $formModule[$field] = assessment_builder_scalar($_POST[$field] ?? $moduleDefaults[$field]);
                }
                $formModule['id'] = max(0, (int) $formModule['id']);
                $formModule['shuffle_questions'] = isset($_POST['shuffle_questions']) ? '1' : '';
                $formModule['shuffle_options'] = isset($_POST['shuffle_options']) ? '1' : '';
                $selectedQuestionIds = is_array($_POST['question_ids'] ?? null) ? array_values($_POST['question_ids']) : [];
                $questionSettings = is_array($_POST['question_settings'] ?? null) ? $_POST['question_settings'] : [];
                $savedId = assessment_builder_save_module($_POST, (int) (app_user()['id'] ?? 0));
                app_flash('assessment_builder', $formModule['id'] > 0 ? 'Perubahan modul berhasil disimpan.' : 'Modul baru berhasil dibuat.');
                app_redirect('assessment_builder.php?view=modules&edit_module=' . $savedId . '#builder-editor');
            }
            if ($action === 'save_package') {
                foreach (array_keys($packageDefaults) as $field) {
                    $formPackage[$field] = assessment_builder_scalar($_POST[$field] ?? $packageDefaults[$field]);
                }
                $formPackage['id'] = max(0, (int) $formPackage['id']);
                $selectedModuleIds = is_array($_POST['module_ids'] ?? null) ? array_values($_POST['module_ids']) : [];
                $moduleSettings = is_array($_POST['module_settings'] ?? null) ? $_POST['module_settings'] : [];
                $savedId = assessment_builder_save_package($_POST, (int) (app_user()['id'] ?? 0));
                app_flash('assessment_builder', $formPackage['id'] > 0 ? 'Perubahan paket tes berhasil disimpan.' : 'Paket tes baru berhasil dibuat.');
                app_redirect('assessment_builder.php?view=packages&edit_package=' . $savedId . '#builder-editor');
            }
            if ($action === 'module_status' || $action === 'package_status') {
                $status = assessment_builder_scalar($_POST['status'] ?? 'draft');
                $parsedId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
                $itemId = is_int($parsedId) ? $parsedId : 0;
                if ($action === 'module_status') {
                    assessment_builder_set_module_status($itemId, $status);
                    app_flash('assessment_builder', $status === 'archived' ? 'Modul berhasil diarsipkan.' : 'Modul dikembalikan ke status draft.');
                    app_redirect('assessment_builder.php?view=modules');
                }
                assessment_builder_set_package_status($itemId, $status);
                app_flash('assessment_builder', $status === 'archived' ? 'Paket berhasil diarsipkan.' : 'Paket dikembalikan ke status draft.');
                app_redirect('assessment_builder.php?view=packages');
            }
            throw new DomainException('Aksi pembuat asesmen tidak dikenal.');
        } catch (DomainException $exception) {
            $formError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('E-Assessment builder action failed: ' . $exception->getMessage());
            $formError = 'Perubahan belum dapat disimpan. Periksa data atau koneksi database.';
        }
    }
}

$editingModuleId = 0;
$editingPackageId = 0;
if (!$wasPosted && $view === 'modules') {
    $parsed = filter_var($_GET['edit_module'] ?? null, FILTER_VALIDATE_INT);
    $editingModuleId = is_int($parsed) ? max(0, $parsed) : 0;
}
if (!$wasPosted && $view === 'packages') {
    $parsed = filter_var($_GET['edit_package'] ?? null, FILTER_VALIDATE_INT);
    $editingPackageId = is_int($parsed) ? max(0, $parsed) : 0;
}
if ($wasPosted && $view === 'modules') {
    $editingModuleId = (int) $formModule['id'];
}
if ($wasPosted && $view === 'packages') {
    $editingPackageId = (int) $formPackage['id'];
}

try {
    if ($view === 'modules') {
        if ($editingModuleId > 0) {
            $storedModule = assessment_builder_get_module($editingModuleId);
            if ($storedModule === null) {
                $formError = $formError ?? 'Modul yang diminta tidak ditemukan.';
            } else {
                $moduleLocked = !empty($storedModule['has_attempts']);
                if (!$wasPosted) {
                    foreach (array_keys($moduleDefaults) as $field) {
                        $formModule[$field] = assessment_builder_scalar($storedModule[$field] ?? $moduleDefaults[$field]);
                    }
                    $formModule['id'] = (int) $storedModule['id'];
                    $questionSettings = [];
                    $selectedQuestionIds = [];
                    foreach ($storedModule['questions'] as $question) {
                        if ($question['selected_sort_order'] !== null) {
                            $qid = (int) $question['id'];
                            $selectedQuestionIds[] = $qid;
                            $questionSettings[$qid] = [
                                'sort_order' => (string) $question['selected_sort_order'],
                                'points_override' => (string) ($question['points_override'] ?? ''),
                                'is_required' => builder_required_value($question['is_required'] ?? ''),
                            ];
                        }
                    }
                }
            }
        }
        $questionRows = assessment_builder_question_catalog($editingModuleId > 0 ? $editingModuleId : null);
        $moduleRows = assessment_builder_module_list();
        $moduleSummary['total'] = count($moduleRows);
        foreach ($moduleRows as $row) {
            if ($row['status'] === 'published') $moduleSummary['published']++;
            if ($row['status'] === 'draft') $moduleSummary['draft']++;
        }
    } else {
        if ($editingPackageId > 0) {
            $storedPackage = assessment_builder_get_package($editingPackageId);
            if ($storedPackage === null) {
                $formError = $formError ?? 'Paket yang diminta tidak ditemukan.';
            } else {
                $packageLocked = !empty($storedPackage['has_attempts']);
                if (!$wasPosted) {
                    foreach (array_keys($packageDefaults) as $field) {
                        $formPackage[$field] = assessment_builder_scalar($storedPackage[$field] ?? $packageDefaults[$field]);
                    }
                    $formPackage['id'] = (int) $storedPackage['id'];
                    $moduleSettings = [];
                    $selectedModuleIds = [];
                    foreach ($storedPackage['modules'] as $module) {
                        if ($module['selected_sort_order'] !== null) {
                            $mid = (int) $module['id'];
                            $selectedModuleIds[] = $mid;
                            $moduleSettings[$mid] = [
                                'sort_order' => (string) $module['selected_sort_order'],
                                'duration_override_minutes' => (string) ($module['duration_override_minutes'] ?? ''),
                                'is_required' => builder_required_value($module['is_required'] ?? ''),
                            ];
                        }
                    }
                }
            }
        }
        $moduleCatalog = assessment_builder_module_catalog($editingPackageId > 0 ? $editingPackageId : null);
        $packageRows = assessment_builder_package_list();
        $packageSummary['total'] = count($packageRows);
        foreach ($packageRows as $row) {
            if ($row['status'] === 'published') $packageSummary['published']++;
            if ($row['status'] === 'draft') $packageSummary['draft']++;
        }
    }
} catch (Throwable $exception) {
    error_log('E-Assessment builder page load failed: ' . $exception->getMessage());
    $databaseError = 'Data pembuat asesmen belum dapat dimuat. Pastikan database/schema.sql sudah diimpor.';
}

$moduleFormId = (int) ($formModule['id'] ?? 0);
$packageFormId = (int) ($formPackage['id'] ?? 0);
$moduleStatus = assessment_builder_scalar($formModule['status'] ?? 'draft');
$packageStatus = assessment_builder_scalar($formPackage['status'] ?? 'draft');
$moduleLocked = $moduleLocked || ($wasPosted && $moduleFormId > 0 && !empty($storedModule['has_attempts']));
$packageLocked = $packageLocked || ($wasPosted && $packageFormId > 0 && !empty($storedPackage['has_attempts']));
$questionIdLookup = array_fill_keys(array_map('intval', $selectedQuestionIds), true);
$moduleIdLookup = array_fill_keys(array_map('intval', $selectedModuleIds), true);
$questionCategories = question_bank_categories();
$questionTypes = question_bank_types();
$packageTypeLabels = ['recruitment' => 'Rekrutmen', 'internal' => 'Karyawan internal', 'review_360' => 'Evaluasi 360°'];
$displayName = (string) (app_user()['name'] ?? 'Pengguna');
$avatarUrl = app_avatar_url();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7fb">
    <title><?= $view === 'modules' ? 'Modul Asesmen' : 'Paket Tes' ?> · Assessment Studio LJU</title>
    <link rel="stylesheet" href="../assets/app.css?v=1">
    <link rel="stylesheet" href="../assets/assessment-builder.css?v=1">
    <script src="../assets/assessment-builder.js?v=1" defer></script>
</head>
<body class="assessment-builder-page">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="ab-cube" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/></symbol>
    <symbol id="ab-stack" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></symbol>
    <symbol id="ab-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></symbol>
    <symbol id="ab-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="ab-edit" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></symbol>
    <symbol id="ab-archive" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9M10 13h4"/></symbol>
    <symbol id="ab-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="ab-warning" viewBox="0 0 24 24"><path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0ZM12 9v4M12 17h.01"/></symbol>
    <symbol id="ab-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></symbol>
    <symbol id="ab-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
    <symbol id="ab-shuffle" viewBox="0 0 24 24"><path d="m18 14 4 4-4 4M18 2l4 4-4 4M2 18h2.5a6 6 0 0 0 4.2-1.7l6.6-6.6A6 6 0 0 1 19.5 8H22M2 6h2.5a6 6 0 0 1 4.2 1.7l1.2 1.2M14 14l1.3 1.3a6 6 0 0 0 4.2 1.7H22"/></symbol>
    <symbol id="ab-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
</svg>
<header class="ab-topbar">
    <a class="ab-brand" href="../index.php"><span class="ab-brand-mark"><img src="https://dms.lju.co.id/assets/img/logo.png" alt="" data-brand-logo><span class="ab-brand-fallback">LJU</span></span><span><strong>E-ASSESSMENT</strong><small>PT Lampung Jasa Utama</small></span></a>
    <nav class="ab-top-nav"><a href="../index.php">Dashboard</a><a href="question_bank.php">Bank soal</a><span>Assessment Studio</span></nav>
    <div class="ab-user"><span class="ab-avatar"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="" data-avatar><?php endif; ?><span class="avatar-fallback" <?= $avatarUrl ? 'hidden' : '' ?>><?= e(app_user_initials($displayName)) ?></span></span><span><strong><?= e($displayName) ?></strong><small>HR / Admin</small></span></div>
</header>
<main class="ab-main">
    <div class="ab-breadcrumb"><a href="../index.php">Dashboard HR</a><span>/</span><a href="question_bank.php">Assessment Studio</a><span>/</span><strong><?= $view === 'modules' ? 'Modul asesmen' : 'Paket tes' ?></strong></div>
    <section class="ab-hero"><span class="ab-hero-icon"><svg class="icon"><use href="#ab-cube"></use></svg></span><div class="ab-hero-copy"><span class="ab-eyebrow">ASSESSMENT STUDIO · HR/ADMIN</span><h1>Rancang asesmen secara terstruktur</h1><p>Atur bank soal menjadi modul berwaktu, lalu rangkai modul menjadi paket tes yang siap diterbitkan dan dijadwalkan.</p></div><div class="ab-hero-orbit" aria-hidden="true"></div></section>
    <nav class="ab-tabs" aria-label="Builder asesmen"><a class="<?= $view === 'modules' ? 'is-active' : '' ?>" href="assessment_builder.php?view=modules"><svg class="icon"><use href="#ab-cube"></use></svg>Modul asesmen</a><a class="<?= $view === 'packages' ? 'is-active' : '' ?>" href="assessment_builder.php?view=packages"><svg class="icon"><use href="#ab-stack"></use></svg>Paket tes</a><a href="question_bank.php"><svg class="icon"><use href="#ab-file"></use></svg>Bank soal</a></nav>
    <?php if ($flash): ?><div class="ab-alert ab-alert-success" role="status"><svg class="icon"><use href="#ab-check"></use></svg><span><?= e($flash) ?></span></div><?php endif; ?>
    <?php if ($formError): ?><div class="ab-alert ab-alert-error" role="alert"><svg class="icon"><use href="#ab-warning"></use></svg><span><?= e($formError) ?></span></div><?php endif; ?>
    <?php if ($databaseError): ?><div class="ab-alert ab-alert-error" role="alert"><svg class="icon"><use href="#ab-warning"></use></svg><span><?= e($databaseError) ?></span></div><?php endif; ?>

    <?php if ($view === 'modules'): ?>
        <section class="ab-stat-grid"><article><span class="ab-stat-icon"><svg class="icon"><use href="#ab-cube"></use></svg></span><div><small>MODUL TOTAL</small><strong><?= number_format($moduleSummary['total'], 0, ',', '.') ?></strong></div></article><article><span class="ab-stat-icon is-green"><svg class="icon"><use href="#ab-check"></use></svg></span><div><small>DITERBITKAN</small><strong><?= number_format($moduleSummary['published'], 0, ',', '.') ?></strong></div></article><article><span class="ab-stat-icon is-muted"><svg class="icon"><use href="#ab-file"></use></svg></span><div><small>DALAM DRAFT</small><strong><?= number_format($moduleSummary['draft'], 0, ',', '.') ?></strong></div></article></section>
        <div class="ab-columns">
            <section class="ab-panel ab-list-panel"><div class="ab-panel-heading"><div><span class="ab-kicker">KATALOG MODUL</span><h2>Modul asesmen</h2><p>Susun soal, durasi, dan aturan pengerjaan per bagian.</p></div><a class="ab-small-button" href="assessment_builder.php?view=modules#builder-editor"><svg class="icon"><use href="#ab-plus"></use></svg>Modul baru</a></div>
                <div class="ab-table-scroll"><table class="ab-table"><thead><tr><th>MODUL</th><th>SOAL / DURASI</th><th>PAKET / SESI</th><th>STATUS</th><th><span class="visually-hidden">Aksi</span></th></tr></thead><tbody>
                <?php if ($moduleRows === []): ?><tr><td colspan="5"><div class="ab-empty"><span><svg class="icon"><use href="#ab-cube"></use></svg></span><strong>Belum ada modul asesmen</strong><p>Buat modul pertama setelah bank soal memiliki pertanyaan aktif.</p><a href="question_bank.php">Buka bank soal <svg class="icon"><use href="#ab-arrow"></use></svg></a></div></td></tr><?php else: ?>
                    <?php foreach ($moduleRows as $row): ?><tr><td><div class="ab-item-title"><strong><?= e($row['name']) ?></strong><span><?= e($row['code']) ?> · <?= e($questionCategories[$row['category']] ?? $row['category']) ?><?= $row['module_kind'] === 'review_360' ? ' · 360°' : '' ?></span></div></td><td><div class="ab-item-metrics"><b><?= (int) $row['active_question_count'] ?> soal aktif</b><span><?= (int) $row['duration_minutes'] ?> menit<?= $row['question_selection_mode'] === 'random' && $row['random_question_count'] ? ' · acak ' . (int) $row['random_question_count'] : '' ?></span></div></td><td><div class="ab-item-metrics"><b><?= (int) $row['package_count'] ?> paket</b><span><?= (int) $row['attempt_count'] ?> sesi ujian</span></div></td><td><span class="ab-status <?= e(builder_status_class((string) $row['status'])) ?>"><i></i><?= e(builder_status_label((string) $row['status'])) ?></span></td><td><div class="ab-row-actions"><a class="ab-icon-button" href="assessment_builder.php?view=modules&amp;edit_module=<?= (int) $row['id'] ?>#builder-editor" aria-label="Edit <?= e($row['name']) ?>" title="Lihat atau edit modul"><svg class="icon"><use href="#ab-edit"></use></svg></a><form method="post" action="assessment_builder.php"><input type="hidden" name="_csrf_token" value="<?= e(app_csrf_token()) ?>"><input type="hidden" name="view" value="modules"><input type="hidden" name="action" value="module_status"><input type="hidden" name="item_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="status" value="<?= $row['status'] === 'archived' ? 'draft' : 'archived' ?>"><button class="ab-icon-button <?= $row['status'] === 'archived' ? 'is-restore' : 'is-archive' ?>" type="submit" data-confirm-action="<?= $row['status'] === 'archived' ? 'Kembalikan modul ke draft?' : 'Arsipkan modul ini? Modul dalam paket terbit tidak dapat diarsipkan.' ?>" aria-label="<?= $row['status'] === 'archived' ? 'Pulihkan modul' : 'Arsipkan modul' ?>"><svg class="icon"><use href="#ab-<?= $row['status'] === 'archived' ? 'check' : 'archive' ?>"></use></svg></button></form></div></td></tr><?php endforeach; ?>
                <?php endif; ?></tbody></table></div>
            </section>
            <section class="ab-panel ab-editor-panel" id="builder-editor"><div class="ab-panel-heading"><div><span class="ab-kicker"><?= $moduleFormId > 0 ? 'KELOLA MODUL' : 'BUAT MODUL' ?></span><h2><?= $moduleFormId > 0 ? 'Detail modul' : 'Modul baru' ?></h2><p>Pilih soal aktif, atur urutan, durasi, dan status.</p></div><?php if ($moduleFormId > 0): ?><a class="ab-reset-link" href="assessment_builder.php?view=modules#builder-editor">Batal</a><?php endif; ?></div>
                <?php if ($moduleLocked): ?><div class="ab-lock-notice"><svg class="icon"><use href="#ab-warning"></use></svg><div><strong>Modul terkunci oleh sesi ujian</strong><p>Modul ini pernah digunakan peserta. Konten dan statusnya tidak dapat diubah.</p></div></div><?php endif; ?>
                <form method="post" action="assessment_builder.php?view=modules#builder-editor" data-module-editor><input type="hidden" name="_csrf_token" value="<?= e(app_csrf_token()) ?>"><input type="hidden" name="action" value="save_module"><input type="hidden" name="view" value="modules"><input type="hidden" name="id" value="<?= $moduleFormId ?>"><fieldset <?= $moduleLocked ? 'disabled' : '' ?>>
                    <div class="ab-form-grid"><label class="ab-field"><span>Kode modul *</span><input type="text" name="code" maxlength="40" value="<?= e($formModule['code']) ?>" placeholder="Contoh: TPA-DASAR" required></label><label class="ab-field"><span>Nama modul *</span><input type="text" name="name" maxlength="180" value="<?= e($formModule['name']) ?>" placeholder="Contoh: Tes Potensi Akademik" required></label></div>
                    <div class="ab-form-grid ab-form-grid-three"><label class="ab-field"><span>Kategori</span><select name="category"><?php foreach ($questionCategories as $value => $label): ?><option value="<?= e($value) ?>" <?= $formModule['category'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label class="ab-field"><span>Jenis modul</span><select name="module_kind"><option value="standard" <?= $formModule['module_kind'] === 'standard' ? 'selected' : '' ?>>Asesmen standar</option><option value="review_360" <?= $formModule['module_kind'] === 'review_360' ? 'selected' : '' ?>>Evaluasi 360°</option></select></label><label class="ab-field"><span>Durasi (menit)</span><input type="number" name="duration_minutes" min="1" max="1440" step="1" value="<?= e($formModule['duration_minutes']) ?>" required></label></div>
                    <div class="ab-form-grid ab-form-grid-selection"><label class="ab-field"><span>Pemilihan soal</span><select name="question_selection_mode" data-selection-mode><option value="selected" <?= $formModule['question_selection_mode'] === 'selected' ? 'selected' : '' ?>>Gunakan semua soal terpilih</option><option value="random" <?= $formModule['question_selection_mode'] === 'random' ? 'selected' : '' ?>>Ambil acak dari soal terpilih</option></select></label><label class="ab-field" data-random-count-field <?= $formModule['question_selection_mode'] === 'random' ? '' : 'hidden' ?>><span>Jumlah soal acak</span><input type="number" name="random_question_count" min="1" max="600" step="1" value="<?= e($formModule['random_question_count']) ?>"></label><label class="ab-field"><span>Status</span><select name="status"><option value="draft" <?= $moduleStatus === 'draft' ? 'selected' : '' ?>>Draft</option><option value="published" <?= $moduleStatus === 'published' ? 'selected' : '' ?>>Terbit</option><option value="archived" <?= $moduleStatus === 'archived' ? 'selected' : '' ?>>Arsip</option></select></label></div>
                    <div class="ab-check-row"><label><input type="checkbox" name="shuffle_questions" value="1" <?= !empty($formModule['shuffle_questions']) ? 'checked' : '' ?>><span>Acak urutan soal</span></label><label><input type="checkbox" name="shuffle_options" value="1" <?= !empty($formModule['shuffle_options']) ? 'checked' : '' ?>><span>Acak pilihan jawaban</span></label></div>
                    <label class="ab-field"><span>Deskripsi / instruksi modul <small>opsional</small></span><textarea name="description" rows="2" maxlength="12000" placeholder="Catatan untuk HR atau petunjuk ringkas."><?= e($formModule['description']) ?></textarea></label>
                    <section class="ab-picker" data-question-picker><div class="ab-picker-heading"><div><strong>Pilih soal dari bank</strong><small><?= count($questionRows) ?> soal tampil · soal harus aktif untuk dimasukkan</small></div><button type="button" class="ab-select-visible" data-select-visible>Pilih hasil</button></div><div class="ab-picker-filters"><label class="ab-search"><svg class="icon"><use href="#ab-search"></use></svg><input type="search" placeholder="Cari kode atau teks soal" data-question-search></label><select data-question-category><option value="">Semua kategori</option><?php foreach ($questionCategories as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select><select data-question-type><option value="">Semua jenis</option><?php foreach ($questionTypes as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div class="ab-question-list" data-question-list>
                        <?php if ($questionRows === []): ?><div class="ab-picker-empty"><strong>Belum ada soal aktif</strong><p>Tambahkan soal di bank soal sebelum membangun modul.</p><a href="question_bank.php">Buka bank soal <svg class="icon"><use href="#ab-arrow"></use></svg></a></div><?php else: ?>
                            <?php foreach ($questionRows as $question): ?>
                                <?php $qid = (int) $question['id']; $selected = isset($questionIdLookup[$qid]); $setting = $questionSettings[$qid] ?? $questionSettings[(string) $qid] ?? []; $setting = is_array($setting) ? $setting : []; $isActiveQuestion = (bool) $question['is_active']; ?>
                                <article class="ab-question-row <?= $selected ? 'is-selected' : '' ?> <?= !$isActiveQuestion ? 'is-inactive' : '' ?>" data-question-row data-search-text="<?= e(app_text_lower((string) ($question['code'] . ' ' . $question['question_text_preview']))) ?>" data-category="<?= e($question['category']) ?>" data-type="<?= e($question['question_type']) ?>">
                                    <label class="ab-question-select"><input type="checkbox" name="question_ids[]" value="<?= $qid ?>" data-question-select <?= $selected ? 'checked' : '' ?> <?= !$isActiveQuestion ? 'disabled' : '' ?>><span class="ab-check-mark"><svg class="icon"><use href="#ab-check"></use></svg></span></label>
                                    <div class="ab-question-copy"><div class="ab-question-meta"><strong><?= e($question['code'] ?: 'Soal #' . $qid) ?></strong><span><?= e($questionCategories[$question['category']] ?? $question['category']) ?> · <?= e($questionTypes[$question['question_type']] ?? $question['question_type']) ?></span><?php if (!$isActiveQuestion): ?><em><?= $selected ? 'Arsip · akan dikeluarkan saat disimpan' : 'Diarsipkan' ?></em><?php endif; ?></div><p><?= e(builder_question_preview((string) $question['question_text_preview'])) ?></p></div>
                                    <div class="ab-question-settings"><label><span>Urutan</span><input type="number" min="1" max="65535" name="question_settings[<?= $qid ?>][sort_order]" value="<?= e((string) ($setting['sort_order'] ?? $question['selected_sort_order'] ?? (count($selectedQuestionIds) + 1))) ?>" data-setting-input></label><label><span>Poin khusus</span><input type="number" min="0.01" max="999999.99" step="0.01" name="question_settings[<?= $qid ?>][points_override]" value="<?= e((string) ($setting['points_override'] ?? $question['points_override'] ?? '')) ?>" placeholder="—" data-setting-input></label><label class="ab-required-setting"><input type="checkbox" name="question_settings[<?= $qid ?>][is_required]" value="1" <?= builder_required_value($setting['is_required'] ?? $question['is_required'] ?? '1') === '1' ? 'checked' : '' ?> data-setting-input><span>Wajib</span></label></div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div><div class="ab-picker-footer"><span data-selected-question-count><?= count($selectedQuestionIds) ?> soal dipilih</span><small>Untuk mengacak, pilih jumlah soal tidak melebihi daftar terpilih.</small></div>
                    </section>
                </fieldset><div class="ab-form-actions"><?php if ($moduleLocked): ?><a href="assessment_builder.php?view=modules" class="ab-secondary-button">Kembali</a><?php else: ?><button class="ab-primary-button" type="submit"><svg class="icon"><use href="#ab-check"></use></svg><?= $moduleFormId > 0 ? 'Simpan modul' : 'Buat modul' ?></button><?php if ($moduleFormId > 0): ?><a href="assessment_builder.php?view=modules#builder-editor" class="ab-secondary-button">Batal</a><?php endif; ?><?php endif; ?></div></form>
            </section>
        </div>
    <?php else: ?>
        <section class="ab-stat-grid"><article><span class="ab-stat-icon"><svg class="icon"><use href="#ab-stack"></use></svg></span><div><small>PAKET TOTAL</small><strong><?= number_format($packageSummary['total'], 0, ',', '.') ?></strong></div></article><article><span class="ab-stat-icon is-green"><svg class="icon"><use href="#ab-check"></use></svg></span><div><small>DITERBITKAN</small><strong><?= number_format($packageSummary['published'], 0, ',', '.') ?></strong></div></article><article><span class="ab-stat-icon is-muted"><svg class="icon"><use href="#ab-file"></use></svg></span><div><small>DALAM DRAFT</small><strong><?= number_format($packageSummary['draft'], 0, ',', '.') ?></strong></div></article></section>
        <div class="ab-columns">
            <section class="ab-panel ab-list-panel"><div class="ab-panel-heading"><div><span class="ab-kicker">KATALOG PAKET</span><h2>Paket tes</h2><p>Rangkai modul terbit menjadi sebuah asesmen.</p></div><a class="ab-small-button" href="assessment_builder.php?view=packages#builder-editor"><svg class="icon"><use href="#ab-plus"></use></svg>Paket baru</a></div>
                <div class="ab-table-scroll"><table class="ab-table"><thead><tr><th>PAKET</th><th>MODUL / JENIS</th><th>JADWAL / SESI</th><th>STATUS</th><th><span class="visually-hidden">Aksi</span></th></tr></thead><tbody>
                <?php if ($packageRows === []): ?><tr><td colspan="5"><div class="ab-empty"><span><svg class="icon"><use href="#ab-stack"></use></svg></span><strong>Belum ada paket tes</strong><p>Buat dan terbitkan beberapa modul terlebih dahulu.</p><a href="assessment_builder.php?view=modules">Kelola modul <svg class="icon"><use href="#ab-arrow"></use></svg></a></div></td></tr><?php else: ?>
                    <?php foreach ($packageRows as $row): ?><tr><td><div class="ab-item-title"><strong><?= e($row['name']) ?></strong><span><?= e($row['code']) ?> · <?= e($packageTypeLabels[$row['package_type']] ?? $row['package_type']) ?><?= $row['target_position'] ? ' · ' . e($row['target_position']) : '' ?></span></div><div class="ab-passing-score">Passing grade: <?= $row['passing_score'] === null ? '—' : e(number_format((float) $row['passing_score'], 2, ',', '.')) ?></div></td><td><div class="ab-item-metrics"><b><?= (int) $row['module_count'] ?> modul</b><span>Urutan dan durasi dapat diatur</span></div></td><td><div class="ab-item-metrics"><b><?= (int) $row['schedule_count'] ?> jadwal</b><span><?= (int) $row['attempt_count'] ?> sesi peserta</span></div></td><td><span class="ab-status <?= e(builder_status_class((string) $row['status'])) ?>"><i></i><?= e(builder_status_label((string) $row['status'])) ?></span></td><td><div class="ab-row-actions"><a class="ab-icon-button" href="assessment_builder.php?view=packages&amp;edit_package=<?= (int) $row['id'] ?>#builder-editor" aria-label="Edit <?= e($row['name']) ?>" title="Lihat atau edit paket"><svg class="icon"><use href="#ab-edit"></use></svg></a><form method="post" action="assessment_builder.php"><input type="hidden" name="_csrf_token" value="<?= e(app_csrf_token()) ?>"><input type="hidden" name="view" value="packages"><input type="hidden" name="action" value="package_status"><input type="hidden" name="item_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="status" value="<?= $row['status'] === 'archived' ? 'draft' : 'archived' ?>"><button class="ab-icon-button <?= $row['status'] === 'archived' ? 'is-restore' : 'is-archive' ?>" type="submit" data-confirm-action="<?= $row['status'] === 'archived' ? 'Kembalikan paket ke draft?' : 'Arsipkan paket ini? Sesi peserta yang sudah ada tetap tersimpan.' ?>" aria-label="<?= $row['status'] === 'archived' ? 'Pulihkan paket' : 'Arsipkan paket' ?>"><svg class="icon"><use href="#ab-<?= $row['status'] === 'archived' ? 'check' : 'archive' ?>"></use></svg></button></form></div></td></tr><?php endforeach; ?>
                <?php endif; ?></tbody></table></div>
            </section>
            <section class="ab-panel ab-editor-panel" id="builder-editor"><div class="ab-panel-heading"><div><span class="ab-kicker"><?= $packageFormId > 0 ? 'KELOLA PAKET' : 'BUAT PAKET' ?></span><h2><?= $packageFormId > 0 ? 'Detail paket tes' : 'Paket baru' ?></h2><p>Pilih modul, atur susunan dan durasi pelaksanaan.</p></div><?php if ($packageFormId > 0): ?><a class="ab-reset-link" href="assessment_builder.php?view=packages#builder-editor">Batal</a><?php endif; ?></div>
                <?php if ($packageLocked): ?><div class="ab-lock-notice"><svg class="icon"><use href="#ab-warning"></use></svg><div><strong>Paket terkunci oleh sesi peserta</strong><p>Paket ini sudah digunakan peserta. Susunan modul, passing grade, dan kontennya tidak dapat diubah.</p></div></div><?php endif; ?>
                <form method="post" action="assessment_builder.php?view=packages#builder-editor" data-package-editor><input type="hidden" name="_csrf_token" value="<?= e(app_csrf_token()) ?>"><input type="hidden" name="action" value="save_package"><input type="hidden" name="view" value="packages"><input type="hidden" name="id" value="<?= $packageFormId ?>"><fieldset <?= $packageLocked ? 'disabled' : '' ?>>
                    <div class="ab-form-grid"><label class="ab-field"><span>Kode paket *</span><input type="text" name="code" maxlength="40" value="<?= e($formPackage['code']) ?>" placeholder="Contoh: REKRUT-STAFF-2026" required></label><label class="ab-field"><span>Nama paket *</span><input type="text" name="name" maxlength="180" value="<?= e($formPackage['name']) ?>" placeholder="Contoh: Seleksi Staf Administrasi" required></label></div>
                    <div class="ab-form-grid ab-form-grid-three"><label class="ab-field"><span>Jenis paket</span><select name="package_type"><?php foreach ($packageTypeLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $formPackage['package_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label class="ab-field"><span>Posisi sasaran <small>opsional</small></span><input type="text" name="target_position" maxlength="150" value="<?= e($formPackage['target_position']) ?>" placeholder="Contoh: Staf Keuangan"></label><label class="ab-field"><span>Passing grade <small>opsional</small></span><input type="number" name="passing_score" min="0" max="999999.99" step="0.01" value="<?= e($formPackage['passing_score']) ?>" placeholder="Belum ditentukan"></label></div>
                    <div class="ab-form-grid ab-form-grid-status"><label class="ab-field"><span>Status paket</span><select name="status"><option value="draft" <?= $packageStatus === 'draft' ? 'selected' : '' ?>>Draft</option><option value="published" <?= $packageStatus === 'published' ? 'selected' : '' ?>>Terbit</option><option value="archived" <?= $packageStatus === 'archived' ? 'selected' : '' ?>>Arsip</option></select></label><label class="ab-field"><span>Deskripsi <small>opsional</small></span><textarea name="description" rows="2" maxlength="12000" placeholder="Ringkasan paket dan tujuan asesmen."><?= e($formPackage['description']) ?></textarea></label></div>
                    <section class="ab-picker" data-module-picker><div class="ab-picker-heading"><div><strong>Rangkaian modul paket</strong><small><?= count($moduleCatalog) ?> modul tersedia · hanya modul terbit dapat digunakan untuk paket terbit</small></div><button type="button" class="ab-select-visible" data-select-visible-modules>Pilih hasil</button></div><div class="ab-picker-filters"><label class="ab-search"><svg class="icon"><use href="#ab-search"></use></svg><input type="search" placeholder="Cari kode atau nama modul" data-module-search></label><select data-module-status-filter><option value="">Semua status</option><option value="published">Terbit</option><option value="draft">Draft</option></select></div>
                        <div class="ab-module-list" data-module-list>
                        <?php if ($moduleCatalog === []): ?><div class="ab-picker-empty"><strong>Belum ada modul yang dapat dipilih</strong><p>Bangun dan terbitkan modul terlebih dahulu.</p><a href="assessment_builder.php?view=modules">Buka pembuat modul <svg class="icon"><use href="#ab-arrow"></use></svg></a></div><?php else: ?>
                            <?php foreach ($moduleCatalog as $module): ?>
                                <?php $mid = (int) $module['id']; $selected = isset($moduleIdLookup[$mid]); $setting = $moduleSettings[$mid] ?? $moduleSettings[(string) $mid] ?? []; $setting = is_array($setting) ? $setting : []; ?>
                                <article class="ab-module-row <?= $selected ? 'is-selected' : '' ?> <?= $module['status'] === 'archived' ? 'is-inactive' : '' ?>" data-module-row data-search-text="<?= e(strtolower($module['code'] . ' ' . $module['name'])) ?>" data-status="<?= e($module['status']) ?>">
                                    <label class="ab-question-select"><input type="checkbox" name="module_ids[]" value="<?= $mid ?>" data-module-select <?= $selected ? 'checked' : '' ?> <?= $module['status'] === 'archived' ? 'disabled' : '' ?>><span class="ab-check-mark"><svg class="icon"><use href="#ab-check"></use></svg></span></label>
                                    <div class="ab-module-copy"><div class="ab-question-meta"><strong><?= e($module['name']) ?></strong><span><?= e($module['code']) ?> · <?= e($questionCategories[$module['category']] ?? $module['category']) ?><?= $module['module_kind'] === 'review_360' ? ' · Review 360°' : '' ?></span></div><p><?= (int) $module['question_count'] ?> soal aktif · <?= (int) $module['duration_minutes'] ?> menit · <em class="<?= $module['status'] === 'published' ? 'is-published' : 'is-draft' ?>"><?= e(builder_status_label((string) $module['status'])) ?></em><?= $module['status'] === 'archived' ? ' · modul akan dikeluarkan saat disimpan' : '' ?></p></div>
                                    <div class="ab-module-settings"><label><span>Urutan</span><input type="number" min="1" max="65535" name="module_settings[<?= $mid ?>][sort_order]" value="<?= e((string) ($setting['sort_order'] ?? $module['selected_sort_order'] ?? (count($selectedModuleIds) + 1))) ?>" data-setting-input></label><label><span>Durasi khusus</span><input type="number" min="1" max="1440" name="module_settings[<?= $mid ?>][duration_override_minutes]" value="<?= e((string) ($setting['duration_override_minutes'] ?? $module['duration_override_minutes'] ?? '')) ?>" placeholder="Default" data-setting-input></label><span class="ab-required-badge">Wajib</span></div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div><div class="ab-picker-footer"><span data-selected-module-count><?= count($selectedModuleIds) ?> modul dipilih</span><small>Urutan ditetapkan melalui nomor di setiap baris. Semua modul paket wajib dikerjakan; modul draft hanya untuk paket draft.</small></div>
                    </section>
                </fieldset><div class="ab-form-actions"><?php if ($packageLocked): ?><a href="assessment_builder.php?view=packages" class="ab-secondary-button">Kembali</a><?php else: ?><button class="ab-primary-button" type="submit"><svg class="icon"><use href="#ab-check"></use></svg><?= $packageFormId > 0 ? 'Simpan paket' : 'Buat paket' ?></button><?php if ($packageFormId > 0): ?><a href="assessment_builder.php?view=packages#builder-editor" class="ab-secondary-button">Batal</a><?php endif; ?><?php endif; ?></div></form>
            </section>
        </div>
    <?php endif; ?>
    <footer class="ab-footer"><span>© <?= e(date('Y')) ?> PT Lampung Jasa Utama</span><span>Assessment Studio · HR/Admin</span></footer>
</main>
<div class="ab-toast" id="ab-toast" role="status" aria-live="polite"></div>
</body>
</html>
