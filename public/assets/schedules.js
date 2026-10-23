(() => {
    'use strict';

    const toast = document.querySelector('[data-schedule-toast]');
    let toastTimer = 0;
    const showToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2600);
    };

    document.querySelectorAll('[data-copy-value]').forEach((button) => {
        button.addEventListener('click', async () => {
            const value = button.getAttribute('data-copy-value') || '';
            if (!value) return;
            try {
                await navigator.clipboard.writeText(value);
                showToast('Berhasil disalin ke clipboard.');
            } catch (_) {
                const field = document.createElement('textarea');
                field.value = value;
                field.setAttribute('readonly', '');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                const copied = document.execCommand('copy');
                field.remove();
                showToast(copied ? 'Berhasil disalin ke clipboard.' : 'Clipboard tidak tersedia. Silakan salin secara manual.');
            }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.getAttribute('data-confirm') || 'Lanjutkan tindakan ini?')) {
                event.preventDefault();
            }
        });
    });

    const retakeToggle = document.querySelector('[data-retake-toggle]');
    const maxAttempts = document.querySelector('[data-max-attempts]');
    if (retakeToggle && maxAttempts) {
        const syncAttempts = () => {
            maxAttempts.disabled = !retakeToggle.checked;
            if (!retakeToggle.checked) maxAttempts.value = '1';
        };
        retakeToggle.addEventListener('change', syncAttempts);
        syncAttempts();
    }

    const employeeForm = document.querySelector('[data-employee-invite-form]');
    if (employeeForm) {
        const searchInput = employeeForm.querySelector('[data-employee-search]');
        const searchResults = employeeForm.querySelector('[data-search-results]');
        const searchState = employeeForm.querySelector('[data-search-state]');
        const selectedUsers = employeeForm.querySelector('[data-selected-users]');
        const selectedList = employeeForm.querySelector('[data-selected-list]');
        const selectedCount = employeeForm.querySelector('[data-selected-count]');
        const submitButton = employeeForm.querySelector('[data-invite-submit]');
        const selected = new Map();
        let searchTimer = 0;
        let activeController = null;
        let requestNumber = 0;

        const profileLabel = (user) => {
            const parts = [];
            const position = String(user.position || '').trim();
            const division = String(user.division || '').trim();
            const department = String(user.department || '').trim();
            if (position) parts.push(position);
            if (division) parts.push(`Bidang ${division}`); // users.division → nama_bidang
            if (department) parts.push(`Divisi ${department}`); // users.department → nama_divisi
            return parts.join(' · ');
        };

        const syncSelection = () => {
            selectedList.replaceChildren();
            employeeForm.querySelectorAll('input[data-selected-id]').forEach((input) => input.remove());
            if (selected.size === 0) {
                const empty = document.createElement('p');
                empty.className = 'sch-selected-empty';
                empty.textContent = 'Belum ada karyawan dipilih.';
                selectedList.appendChild(empty);
            } else {
                selected.forEach((user, id) => {
                    const row = document.createElement('div');
                    row.className = 'sch-selected-row';
                    const copy = document.createElement('span');
                    const name = document.createElement('strong');
                    name.textContent = user.name || `Karyawan #${id}`;
                    const details = document.createElement('small');
                    details.textContent = user.email || profileLabel(user) || `ID SSO ${id}`;
                    copy.append(name, details);
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.textContent = '×';
                    remove.setAttribute('aria-label', `Hapus ${user.name || 'karyawan'} dari pilihan`);
                    remove.addEventListener('click', () => {
                        selected.delete(String(id));
                        syncSelection();
                    });
                    row.append(copy, remove);
                    selectedList.appendChild(row);

                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'employee_ids[]';
                    hidden.value = String(id);
                    hidden.dataset.selectedId = String(id);
                    employeeForm.appendChild(hidden);
                });
            }
            selectedCount.textContent = `${selected.size} dipilih`;
            submitButton.disabled = selected.size === 0;
        };

        const showMessage = (message) => {
            searchResults.replaceChildren();
            const note = document.createElement('p');
            note.textContent = message;
            searchResults.appendChild(note);
        };

        const addSearchResult = (user) => {
            const id = String(user.id || '');
            if (!id || selected.has(id)) return null;
            const row = document.createElement('div');
            row.className = 'sch-search-result';
            const copy = document.createElement('span');
            copy.className = 'sch-search-result-copy';
            const name = document.createElement('strong');
            name.textContent = user.name || `Karyawan #${id}`;
            const details = document.createElement('small');
            details.textContent = [user.email, profileLabel(user)].filter(Boolean).join(' · ');
            copy.append(name, details);
            const add = document.createElement('button');
            add.type = 'button';
            add.textContent = 'Pilih';
            add.addEventListener('click', () => {
                selected.set(id, {
                    id,
                    name: String(user.name || `Karyawan #${id}`),
                    email: String(user.email || ''),
                    position: String(user.position || ''),
                    division: String(user.division || ''),
                    department: String(user.department || ''),
                });
                row.remove();
                if (!searchResults.querySelector('.sch-search-result')) showMessage('Semua hasil yang tampil sudah dipilih.');
                syncSelection();
            });
            row.append(copy, add);
            return row;
        };

        const runSearch = async () => {
            const term = searchInput.value.trim();
            if (term.length < 2) {
                searchState.textContent = '';
                showMessage('Ketik minimal 2 karakter untuk mencari direktori SSO.');
                return;
            }
            if (activeController) activeController.abort();
            activeController = new AbortController();
            const thisRequest = ++requestNumber;
            searchState.textContent = 'Mencari…';
            showMessage('Menghubungi direktori SSO…');
            const endpoint = new URL(searchInput.dataset.searchUrl || 'schedules.php?ajax=search_users', window.location.href);
            endpoint.searchParams.set('q', term);
            try {
                const response = await fetch(endpoint.toString(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    signal: activeController.signal,
                });
                const payload = await response.json();
                if (thisRequest !== requestNumber) return;
                if (!response.ok) throw new Error(payload.error || 'Pencarian SSO belum tersedia.');
                searchResults.replaceChildren();
                const results = Array.isArray(payload.users) ? payload.users : [];
                const visible = results.map(addSearchResult).filter(Boolean);
                if (visible.length === 0) {
                    showMessage(results.length ? 'Semua hasil yang ditemukan sudah dipilih.' : 'Tidak ada akun SSO yang cocok.');
                } else {
                    visible.forEach((element) => searchResults.appendChild(element));
                }
                searchState.textContent = `${results.length} hasil`;
            } catch (error) {
                if (error.name === 'AbortError' || thisRequest !== requestNumber) return;
                searchState.textContent = 'Tidak tersedia';
                showMessage(error.message || 'Direktori SSO belum dapat diakses.');
            }
        };

        searchInput.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(runSearch, 280);
        });
        employeeForm.addEventListener('submit', (event) => {
            if (selected.size === 0) {
                event.preventDefault();
                showToast('Pilih minimal satu karyawan dari hasil pencarian SSO.');
            }
        });

        if (selectedUsers) {
            try {
                const initial = JSON.parse(selectedUsers.dataset.initialSelected || '[]');
                if (Array.isArray(initial)) {
                    initial.forEach((user) => {
                        const id = String(user.id || '');
                        if (id) selected.set(id, user);
                    });
                }
            } catch (_) {
                // Ignore invalid server-rendered picker state and keep the form usable.
            }
        }
        syncSelection();
    }

    const assignmentForm = document.querySelector('[data-360-assignment-form]');
    if (assignmentForm) {
        const reviewerSelect = assignmentForm.querySelector('[data-360-reviewer]');
        const searchInput = assignmentForm.querySelector('[data-360-subject-search]');
        const searchResults = assignmentForm.querySelector('[data-360-search-results]');
        const searchState = assignmentForm.querySelector('[data-360-search-state]');
        const selectedUsers = assignmentForm.querySelector('[data-360-selected-users]');
        const selectedList = assignmentForm.querySelector('[data-360-selected-list]');
        const selectedCount = assignmentForm.querySelector('[data-360-selected-count]');
        const submitButton = assignmentForm.querySelector('[data-360-submit]');
        const selected = new Map();
        let timer = 0;
        let activeController = null;
        let requestNumber = 0;

        const profileLabel = (user) => {
            const parts = [];
            const position = String(user.position || '').trim();
            const division = String(user.division || '').trim();
            const department = String(user.department || '').trim();
            if (position) parts.push(position);
            if (division) parts.push(`Bidang ${division}`);
            if (department) parts.push(`Divisi ${department}`);
            return parts.join(' · ');
        };

        const render = () => {
            const reviewerId = String(reviewerSelect.value || '');
            if (reviewerId && selected.has(reviewerId)) {
                selected.delete(reviewerId);
                showToast('Reviewer tidak dapat dipilih sebagai subjek dirinya sendiri.');
            }
            selectedList.replaceChildren();
            assignmentForm.querySelectorAll('input[data-360-subject-id]').forEach((input) => input.remove());
            if (selected.size === 0) {
                const empty = document.createElement('p');
                empty.className = 'sch-selected-empty';
                empty.textContent = 'Belum ada subjek dipilih.';
                selectedList.appendChild(empty);
            } else {
                selected.forEach((user, id) => {
                    const row = document.createElement('div');
                    row.className = 'sch-selected-row';
                    const copy = document.createElement('span');
                    const name = document.createElement('strong');
                    name.textContent = user.name || `Karyawan #${id}`;
                    const details = document.createElement('small');
                    details.textContent = user.email || profileLabel(user) || `ID SSO ${id}`;
                    copy.append(name, details);
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.textContent = '×';
                    remove.setAttribute('aria-label', `Hapus ${user.name || 'subjek'} dari alokasi`);
                    remove.addEventListener('click', () => {
                        selected.delete(String(id));
                        render();
                    });
                    row.append(copy, remove);
                    selectedList.appendChild(row);

                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'subject_user_ids[]';
                    hidden.value = String(id);
                    hidden.setAttribute('data-360-subject-id', String(id));
                    assignmentForm.appendChild(hidden);
                });
            }
            selectedCount.textContent = `${selected.size} dipilih`;
            submitButton.disabled = selected.size === 0 || reviewerId === '';
        };

        const showMessage = (message) => {
            searchResults.replaceChildren();
            const note = document.createElement('p');
            note.textContent = message;
            searchResults.appendChild(note);
        };

        const makeResult = (user) => {
            const id = String(user.id || '');
            if (!id || selected.has(id)) return null;
            const row = document.createElement('div');
            row.className = 'sch-search-result';
            const copy = document.createElement('span');
            copy.className = 'sch-search-result-copy';
            const name = document.createElement('strong');
            name.textContent = user.name || `Karyawan #${id}`;
            const details = document.createElement('small');
            details.textContent = [user.email, profileLabel(user)].filter(Boolean).join(' · ');
            copy.append(name, details);
            const add = document.createElement('button');
            add.type = 'button';
            const isReviewer = id === String(reviewerSelect.value || '');
            add.textContent = isReviewer ? 'Reviewer' : 'Pilih';
            add.disabled = isReviewer;
            add.title = isReviewer ? 'Reviewer tidak dapat menilai dirinya sendiri' : 'Pilih subjek';
            add.addEventListener('click', () => {
                if (id === String(reviewerSelect.value || '')) {
                    showToast('Reviewer tidak dapat menilai dirinya sendiri.');
                    return;
                }
                selected.set(id, {
                    id,
                    name: String(user.name || `Karyawan #${id}`),
                    email: String(user.email || ''),
                    position: String(user.position || ''),
                    division: String(user.division || ''),
                    department: String(user.department || ''),
                });
                row.remove();
                if (!searchResults.querySelector('.sch-search-result')) showMessage('Semua hasil yang tampil sudah dipilih.');
                render();
            });
            row.append(copy, add);
            return row;
        };

        const runSearch = async () => {
            const term = searchInput.value.trim();
            if (term.length < 2) {
                searchState.textContent = '';
                showMessage('Ketik minimal 2 karakter untuk mencari direktori SSO.');
                return;
            }
            if (activeController) activeController.abort();
            activeController = new AbortController();
            const currentRequest = ++requestNumber;
            searchState.textContent = 'Mencari…';
            showMessage('Menghubungi direktori SSO…');
            const endpoint = new URL(searchInput.dataset.searchUrl || 'schedules.php?ajax=search_users', window.location.href);
            endpoint.searchParams.set('q', term);
            try {
                const response = await fetch(endpoint.toString(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    signal: activeController.signal,
                });
                const payload = await response.json();
                if (currentRequest !== requestNumber) return;
                if (!response.ok) throw new Error(payload.error || 'Pencarian SSO belum tersedia.');
                searchResults.replaceChildren();
                const results = Array.isArray(payload.users) ? payload.users : [];
                const visible = results.map(makeResult).filter(Boolean);
                if (visible.length === 0) {
                    showMessage(results.length ? 'Semua hasil yang ditemukan sudah dipilih.' : 'Tidak ada akun SSO yang cocok.');
                } else {
                    visible.forEach((element) => searchResults.appendChild(element));
                }
                searchState.textContent = `${results.length} hasil`;
            } catch (error) {
                if (error.name === 'AbortError' || currentRequest !== requestNumber) return;
                searchState.textContent = 'Tidak tersedia';
                showMessage(error.message || 'Direktori SSO belum dapat diakses.');
            }
        };

        searchInput.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(runSearch, 280);
        });
        reviewerSelect.addEventListener('change', render);
        assignmentForm.addEventListener('submit', (event) => {
            if (selected.size === 0 || !reviewerSelect.value) {
                event.preventDefault();
                showToast('Pilih reviewer dan minimal satu subjek.');
            }
        });
        try {
            const initial = JSON.parse(selectedUsers.dataset.initialSelected || '[]');
            if (Array.isArray(initial)) {
                initial.forEach((user) => {
                    const id = String(user.id || '');
                    if (id) selected.set(id, user);
                });
            }
        } catch (_) {
            // Ignore malformed, server-rendered form state.
        }
        render();
    }

    const packageSelect = document.querySelector('[name="package_id"]');
    const accessMode = document.querySelector('[data-access-mode]');
    if (packageSelect && accessMode) {
        const syncReview360 = () => {
            const selectedOption = packageSelect.selectedOptions[0];
            const isReview360 = selectedOption && selectedOption.dataset.packageType === 'review_360';
            Array.from(accessMode.options).forEach((option) => {
                if (isReview360) option.disabled = option.value !== 'employee_sso';
                else option.disabled = false;
            });
            if (isReview360) accessMode.value = 'employee_sso';
            const retakeNote = document.querySelector('[data-retake-note]');
            if (retakeNote) {
                retakeNote.textContent = isReview360
                    ? 'Paket evaluasi 360° menggunakan satu percobaan per reviewer.'
                    : 'Jika tidak dicentang, maksimum percobaan otomatis menjadi satu.';
            }
            if (retakeToggle && maxAttempts) {
                retakeToggle.disabled = Boolean(isReview360);
                if (isReview360) {
                    retakeToggle.checked = false;
                    maxAttempts.value = '1';
                    maxAttempts.disabled = true;
                } else {
                    maxAttempts.disabled = !retakeToggle.checked;
                    if (!retakeToggle.checked) maxAttempts.value = '1';
                }
            }
        };
        packageSelect.addEventListener('change', syncReview360);
        syncReview360();
    }
})();
