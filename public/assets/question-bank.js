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

    const toast = document.getElementById('qb-toast');
    let toastTimer;
    const showToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
    };

    document.querySelectorAll('[data-confirm-action]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const prompt = form.dataset.confirmAction || 'Lanjutkan tindakan ini?';
            if (!window.confirm(prompt)) event.preventDefault();
        });
    });

    const editor = document.querySelector('[data-question-editor]');
    if (!editor) return;

    const typeSelect = editor.querySelector('[data-question-type]');
    const optionSection = editor.querySelector('[data-options-section]');
    const optionList = editor.querySelector('[data-option-list]');
    const addButton = editor.querySelector('[data-add-option]');
    const optionTemplate = document.getElementById('qb-option-template');
    const papiField = editor.querySelector('[data-papi-field]');
    const fileFields = editor.querySelector('[data-file-fields]');

    const currentType = () => typeSelect?.value || '';
    const isChoiceType = () => ['single_choice', 'multi_select', 'forced_choice'].includes(currentType());

    const reindexRows = () => {
        const rows = [...(optionList?.querySelectorAll('[data-option-row]') || [])];
        rows.forEach((row, index) => {
            const letter = String.fromCharCode(65 + index);
            const key = row.querySelector('[data-option-key]');
            const text = row.querySelector('textarea');
            const correct = row.querySelector('[data-correct-control] input');
            const aspect = row.querySelector('[data-aspect-control] input');
            if (key) key.textContent = letter;
            if (text) {
                text.name = `options[${index}][text]`;
                const label = row.querySelector('.qb-option-text .visually-hidden');
                if (label) label.textContent = `Teks pilihan ${letter}`;
            }
            if (correct) {
                correct.name = `options[${index}][is_correct]`;
                correct.value = '1';
            }
            if (aspect) aspect.name = `options[${index}][papi_aspect_code]`;
            const removeButton = row.querySelector('[data-remove-option]');
            if (removeButton) removeButton.disabled = rows.length <= 2;
        });
        if (addButton) addButton.disabled = rows.length >= 20;
    };

    const updateTypeFields = () => {
        const type = currentType();
        const isChoice = ['single_choice', 'multi_select', 'forced_choice'].includes(type);
        const isForced = type === 'forced_choice';
        if (optionSection) optionSection.hidden = !isChoice;
        if (papiField) papiField.hidden = !isForced;
        if (fileFields) fileFields.hidden = type !== 'file_upload';
        optionList?.querySelectorAll('[data-option-row]').forEach((row) => {
            const correctControl = row.querySelector('[data-correct-control]');
            const correctInput = correctControl?.querySelector('input');
            const aspectControl = row.querySelector('[data-aspect-control]');
            if (correctControl) correctControl.hidden = isForced;
            if (correctInput) correctInput.disabled = isForced;
            if (aspectControl) aspectControl.hidden = !isForced;
        });
        if (isChoice && (optionList?.querySelectorAll('[data-option-row]').length || 0) < 2) addOption();
        reindexRows();
    };

    function addOption() {
        const rows = optionList?.querySelectorAll('[data-option-row]').length || 0;
        if (!optionList || !optionTemplate || rows >= 20) {
            showToast('Maksimal 20 pilihan jawaban per soal.');
            return;
        }
        const fragment = optionTemplate.content.cloneNode(true);
        optionList.appendChild(fragment);
        const newRow = optionList.lastElementChild;
        const correctInput = newRow?.querySelector('[data-correct-control] input');
        if (correctInput && currentType() === 'forced_choice') correctInput.disabled = true;
        updateTypeFields();
        const newText = newRow?.querySelector('textarea');
        newText?.focus();
    }

    addButton?.addEventListener('click', addOption);
    optionList?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-option]');
        if (!button) return;
        const rows = optionList.querySelectorAll('[data-option-row]');
        if (rows.length <= 2) {
            showToast('Soal pilihan harus memiliki minimal dua pilihan.');
            return;
        }
        button.closest('[data-option-row]')?.remove();
        reindexRows();
    });
    optionList?.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.matches('[data-correct-control] input')) return;
        if (currentType() !== 'single_choice' || !input.checked) return;
        optionList.querySelectorAll('[data-correct-control] input').forEach((other) => {
            if (other !== input) other.checked = false;
        });
    });
    typeSelect?.addEventListener('change', updateTypeFields);

    editor.addEventListener('submit', (event) => {
        if (currentType() === 'forced_choice') {
            const itemCode = editor.querySelector('[name="papi_item_code"]')?.value.trim();
            const category = editor.querySelector('[name="category"]')?.value;
            if (category === 'PAPI_KOSTICK' && !itemCode) {
                event.preventDefault();
                showToast('Isi kode item PAPI Kostick sebelum menyimpan.');
                editor.querySelector('[name="papi_item_code"]')?.focus();
                return;
            }
        }
        const submit = editor.querySelector('button[type="submit"]');
        if (submit) {
            submit.disabled = true;
            submit.dataset.originalText = submit.textContent;
            submit.textContent = 'Menyimpan soal…';
        }
    });

    updateTypeFields();
})();
