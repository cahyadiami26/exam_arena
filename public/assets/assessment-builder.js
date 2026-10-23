(() => {
    document.querySelectorAll('[data-brand-logo]').forEach((image) => {
        image.addEventListener('error', () => image.parentElement?.classList.add('logo-failed'), { once: true });
    });
    document.querySelectorAll('[data-avatar]').forEach((image) => {
        image.addEventListener('error', () => {
            image.remove();
            const fallback = image.parentElement?.querySelector('.avatar-fallback');
            if (fallback) fallback.hidden = false;
        }, { once: true });
    });

    const toast = document.getElementById('ab-toast');
    let toastTimer;
    const showToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
    };

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const button = form.querySelector('[data-confirm-action]');
            if (button && !window.confirm(button.dataset.confirmAction || 'Lanjutkan tindakan ini?')) {
                event.preventDefault();
                return;
            }
        });
    });

    const filterList = (container, config) => {
        if (!container) return;
        const search = container.querySelector(config.search);
        const category = container.querySelector(config.category || '___none___');
        const type = container.querySelector(config.type || '___none___');
        const status = container.querySelector(config.status || '___none___');
        const rows = [...container.querySelectorAll(config.row)];
        const checkboxSelector = config.checkbox;
        const count = container.querySelector(config.count);
        const selectAllButton = container.querySelector(config.selectAll);

        const update = () => {
            const term = (search?.value || '').trim().toLocaleLowerCase('id-ID');
            const categoryValue = category?.value || '';
            const typeValue = type?.value || '';
            const statusValue = status?.value || '';
            let visibleCount = 0;
            let selectedCount = 0;
            rows.forEach((row) => {
                const matches = (!term || (row.dataset.searchText || '').toLocaleLowerCase('id-ID').includes(term))
                    && (!categoryValue || row.dataset.category === categoryValue)
                    && (!typeValue || row.dataset.type === typeValue)
                    && (!statusValue || row.dataset.status === statusValue);
                row.hidden = !matches;
                if (matches) visibleCount += 1;
                const checkbox = row.querySelector(checkboxSelector);
                if (checkbox?.checked && !checkbox.disabled) selectedCount += 1;
                row.classList.toggle('is-selected', Boolean(checkbox?.checked));
                row.querySelectorAll('[data-setting-input]').forEach((input) => {
                    input.disabled = Boolean(checkbox && (!checkbox.checked || checkbox.disabled));
                });
            });
            if (count) count.textContent = `${selectedCount} ${config.countLabel} dipilih`;
            if (selectAllButton) {
                const eligibleVisible = rows.filter((row) => !row.hidden && !row.querySelector(checkboxSelector)?.disabled);
                const allSelected = eligibleVisible.length > 0 && eligibleVisible.every((row) => row.querySelector(checkboxSelector)?.checked);
                selectAllButton.textContent = allSelected ? 'Lepas hasil' : config.selectAllLabel;
                selectAllButton.disabled = eligibleVisible.length === 0;
            }
        };

        [search, category, type, status].filter(Boolean).forEach((control) => {
            control.addEventListener(control === search ? 'input' : 'change', update);
        });
        rows.forEach((row) => row.querySelector(checkboxSelector)?.addEventListener('change', update));
        selectAllButton?.addEventListener('click', () => {
            const eligibleVisible = rows.filter((row) => !row.hidden && !row.querySelector(checkboxSelector)?.disabled);
            const select = eligibleVisible.some((row) => !row.querySelector(checkboxSelector)?.checked);
            eligibleVisible.forEach((row) => {
                const checkbox = row.querySelector(checkboxSelector);
                if (checkbox) checkbox.checked = select;
            });
            update();
        });
        update();
    };

    const moduleEditor = document.querySelector('[data-module-editor]');
    if (moduleEditor) {
        const selectionMode = moduleEditor.querySelector('[data-selection-mode]');
        const randomField = moduleEditor.querySelector('[data-random-count-field]');
        const updateRandomField = () => {
            if (randomField) randomField.hidden = selectionMode?.value !== 'random';
        };
        selectionMode?.addEventListener('change', updateRandomField);
        updateRandomField();
        filterList(moduleEditor.querySelector('[data-question-picker]'), {
            search: '[data-question-search]',
            category: '[data-question-category]',
            type: '[data-question-type]',
            row: '[data-question-row]',
            checkbox: '[data-question-select]',
            count: '[data-selected-question-count]',
            countLabel: 'soal',
            selectAll: '[data-select-visible]',
            selectAllLabel: 'Pilih hasil'
        });
        moduleEditor.addEventListener('submit', (event) => {
            if (selectionMode?.value === 'random') {
                const selectedCount = [...moduleEditor.querySelectorAll('[data-question-select]:checked')].filter((checkbox) => !checkbox.disabled).length;
                const randomCount = Number.parseInt(moduleEditor.querySelector('[name="random_question_count"]')?.value || '0', 10);
                if (randomCount < 1 || randomCount > selectedCount) {
                    event.preventDefault();
                    showToast('Jumlah soal acak harus minimal satu dan tidak melebihi soal yang dipilih.');
                    moduleEditor.querySelector('[name="random_question_count"]')?.focus();
                }
            }
            if (!event.defaultPrevented) {
                const saveButton = event.submitter || moduleEditor.querySelector('button[type="submit"]');
                if (saveButton) {
                    saveButton.disabled = true;
                    saveButton.textContent = 'Menyimpan…';
                }
            }
        });
    }

    const packageEditor = document.querySelector('[data-package-editor]');
    if (packageEditor) {
        filterList(packageEditor.querySelector('[data-module-picker]'), {
            search: '[data-module-search]',
            status: '[data-module-status-filter]',
            row: '[data-module-row]',
            checkbox: '[data-module-select]',
            count: '[data-selected-module-count]',
            countLabel: 'modul',
            selectAll: '[data-select-visible-modules]',
            selectAllLabel: 'Pilih hasil'
        });
        packageEditor.addEventListener('submit', (event) => {
            const packageStatus = packageEditor.querySelector('[name="status"]')?.value;
            if (packageStatus === 'published') {
                const selected = [...packageEditor.querySelectorAll('[data-module-select]:checked')].filter((checkbox) => !checkbox.disabled);
                const hasDraft = selected.some((checkbox) => checkbox.closest('[data-module-row]')?.dataset.status !== 'published');
                if (hasDraft) {
                    event.preventDefault();
                    showToast('Terbitkan semua modul yang dipilih terlebih dahulu.');
                }
            }
            if (!event.defaultPrevented) {
                const saveButton = event.submitter || packageEditor.querySelector('button[type="submit"]');
                if (saveButton) {
                    saveButton.disabled = true;
                    saveButton.textContent = 'Menyimpan…';
                }
            }
        });
    }
})();
