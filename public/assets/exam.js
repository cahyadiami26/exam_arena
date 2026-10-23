(() => {
    document.querySelectorAll('[data-brand-logo]').forEach((image) => {
        image.addEventListener('error', () => image.parentElement?.classList.add('logo-failed'), { once: true });
    });

    const root = document.querySelector('[data-exam-root]');
    if (!root) return;

    const attemptId = Number.parseInt(root.dataset.attemptId || '0', 10);
    const progressId = Number.parseInt(root.dataset.progressId || '0', 10);
    const reviewId = root.dataset.reviewId || '';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const questions = [...document.querySelectorAll('[data-question-card]')];
    const navButtons = [...document.querySelectorAll('[data-question-nav]')];
    const prevButton = document.querySelector('[data-question-prev]');
    const nextButton = document.querySelector('[data-question-next]');
    const positionText = document.querySelector('[data-question-position]');
    const timerText = document.querySelector('[data-timer-text]');
    const timerBox = document.querySelector('[data-exam-timer]');
    const toast = document.getElementById('exam-toast');
    const saveStatusElements = [...document.querySelectorAll('[data-save-status]')];
    const pendingSaves = new Map();
    const failedSaves = new Map();
    let saveTimer = null;
    let saving = false;
    let uploadingCount = 0;
    let currentIndex = 0;
    let toastTimer = null;
    let deadline = Date.now() + Math.max(0, Number.parseInt(root.dataset.remainingSeconds || '0', 10)) * 1000;
    let expiredRequestRunning = false;

    const showToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3600);
    };

    const setSaveStatus = (state, message) => {
        saveStatusElements.forEach((element) => { element.textContent = message; });
        document.querySelectorAll('.save-state-dot').forEach((dot) => {
            dot.classList.toggle('is-saving', state === 'saving');
            dot.classList.toggle('is-error', state === 'error');
        });
    };

    const postAction = async (action, fields = {}, file = null) => {
        const body = new FormData();
        body.append('_csrf_token', csrfToken);
        body.append('ajax_action', action);
        body.append('progress_id', String(progressId));
        if (reviewId) body.append('review_assignment_id', reviewId);
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));
        if (file) body.append('answer_file', file);

        const url = new URL(window.location.href);
        url.searchParams.set('id', String(attemptId));
        url.searchParams.delete('asset');
        url.searchParams.delete('record_id');
        const response = await fetch(url.toString(), {
            method: 'POST',
            body,
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        let result;
        try { result = await response.json(); }
        catch { throw new Error('Respons server tidak dapat dibaca.'); }
        if (!response.ok || result.ok !== true) {
            const error = new Error(result.message || 'Jawaban belum dapat disimpan.');
            error.status = response.status;
            throw error;
        }
        return result;
    };

    const getQuestionPayload = (card) => {
        const type = card.dataset.questionType;
        if (type === 'single_choice') {
            const selected = card.querySelector('input[type="radio"]:checked');
            return { selected_option_id: selected?.value || '' };
        }
        if (type === 'multi_select') {
            return { selected_option_ids: [...card.querySelectorAll('input[type="checkbox"]:checked')].map((input) => input.value) };
        }
        if (type === 'forced_choice') {
            const most = card.querySelector('[data-forced-most]')?.value || '';
            const least = card.querySelector('[data-forced-least]')?.value || '';
            if (most && least && most === least) {
                showToast('Pilihan Most dan Least harus berbeda.');
                return null;
            }
            return { most_option_id: most, least_option_id: least };
        }
        if (type === 'essay') {
            return { html: card.querySelector('[data-rich-editor]')?.innerHTML || '' };
        }
        return null;
    };

    const answerLooksFilled = (card) => {
        const type = card.dataset.questionType;
        if (type === 'single_choice') return Boolean(card.querySelector('input[type="radio"]:checked'));
        if (type === 'multi_select') return Boolean(card.querySelector('input[type="checkbox"]:checked'));
        if (type === 'forced_choice') {
            const most = card.querySelector('[data-forced-most]')?.value;
            const least = card.querySelector('[data-forced-least]')?.value;
            return Boolean(most && least && most !== least);
        }
        if (type === 'essay') return Boolean((card.querySelector('[data-rich-editor]')?.innerText || '').trim());
        if (type === 'file_upload') return Boolean(card.querySelector('.uploaded-file'));
        return false;
    };

    const updateAnsweredState = (card) => {
        const index = questions.indexOf(card);
        const button = navButtons[index];
        const filled = answerLooksFilled(card);
        card.dataset.answered = filled ? '1' : '0';
        button?.classList.toggle('is-answered', filled);
        if (button) button.setAttribute('aria-label', `Soal ${String(index + 1)}${filled ? ', sudah dijawab' : ', belum dijawab'}`);
    };

    const saveCard = async (card) => {
        const questionId = card.dataset.questionId;
        const payload = getQuestionPayload(card);
        if (payload === null) throw new Error('Pilihan Most dan Least harus berbeda.');
        await postAction('save_answer', {
            question_id: questionId,
            payload: JSON.stringify(payload)
        });
        updateAnsweredState(card);
    };

    const drainSaves = async () => {
        if (saving) return;
        saving = true;
        while (pendingSaves.size > 0) {
            const first = pendingSaves.entries().next().value;
            if (!first) break;
            const [questionId, card] = first;
            pendingSaves.delete(questionId);
            setSaveStatus('saving', 'Menyimpan jawaban…');
            try {
                await saveCard(card);
                failedSaves.delete(questionId);
                setSaveStatus('saved', `Tersimpan otomatis · ${new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23', timeZone: 'Asia/Jakarta' }).format(new Date())} WIB`);
            } catch (error) {
                failedSaves.set(questionId, card);
                setSaveStatus('error', 'Belum tersimpan · cek koneksi');
                showToast(error.message || 'Jawaban belum tersimpan. Coba kembali.');
                if (error.message?.includes('Waktu modul telah habis') || error.message?.includes('Jadwal ujian sudah ditutup')) {
                    window.location.reload();
                }
            }
        }
        saving = false;
    };

    const queueSave = (card) => {
        if (card.dataset.questionType === 'file_upload') return;
        failedSaves.delete(card.dataset.questionId);
        pendingSaves.set(card.dataset.questionId, card);
        setSaveStatus('saving', 'Perubahan belum tersimpan…');
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(() => { void drainSaves(); }, 500);
    };

    const flushSaves = async () => {
        window.clearTimeout(saveTimer);
        for (let attempt = 0; attempt < 2; attempt += 1) {
            if (failedSaves.size > 0) {
                if (!navigator.onLine) throw new Error('Ada jawaban yang belum terkirim. Sambungkan kembali ke internet sebelum melanjutkan.');
                failedSaves.forEach((card, questionId) => pendingSaves.set(questionId, card));
                failedSaves.clear();
            }
            if (pendingSaves.size > 0 && !navigator.onLine) {
                throw new Error('Ada jawaban yang belum terkirim. Sambungkan kembali ke internet sebelum melanjutkan.');
            }
            while (saving || pendingSaves.size > 0 || uploadingCount > 0) {
                if (!saving && pendingSaves.size > 0) await drainSaves();
                else await new Promise((resolve) => window.setTimeout(resolve, 40));
            }
            if (failedSaves.size === 0) return;
        }
        throw new Error('Sebagian jawaban belum tersimpan. Periksa koneksi, lalu coba lagi.');
    };

    const showQuestion = (index) => {
        if (!questions.length) return;
        currentIndex = Math.max(0, Math.min(questions.length - 1, index));
        questions.forEach((card, cardIndex) => card.classList.toggle('is-current', cardIndex === currentIndex));
        navButtons.forEach((button, buttonIndex) => {
            button.classList.toggle('is-current', buttonIndex === currentIndex);
            button.setAttribute('aria-current', buttonIndex === currentIndex ? 'step' : 'false');
        });
        if (positionText) positionText.textContent = `${currentIndex + 1} / ${questions.length}`;
        if (prevButton) prevButton.disabled = currentIndex === 0;
        if (nextButton) nextButton.disabled = currentIndex >= questions.length - 1;
        const activeCard = questions[currentIndex];
        activeCard?.querySelector('.question-text')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };

    navButtons.forEach((button) => button.addEventListener('click', () => {
        const index = Number.parseInt(button.dataset.questionNav || '0', 10);
        void flushSaves().then(() => showQuestion(index)).catch((error) => showToast(error.message));
    }));
    prevButton?.addEventListener('click', () => showQuestion(currentIndex - 1));
    nextButton?.addEventListener('click', () => showQuestion(currentIndex + 1));

    questions.forEach((card) => {
        card.querySelectorAll('input[type="radio"], input[type="checkbox"], select[data-answer-control]').forEach((control) => {
            control.addEventListener('change', () => {
                card.querySelectorAll('.choice-option').forEach((option) => option.classList.toggle('is-selected', Boolean(option.querySelector('input:checked'))));
                queueSave(card);
            });
        });
        const editor = card.querySelector('[data-rich-editor]');
        editor?.addEventListener('input', () => queueSave(card));
        editor?.addEventListener('paste', (event) => {
            event.preventDefault();
            const text = event.clipboardData?.getData('text/plain') || '';
            document.execCommand('insertText', false, text);
        });
        card.querySelectorAll('[data-command]').forEach((button) => {
            button.addEventListener('click', () => {
                editor?.focus();
                document.execCommand(button.dataset.command, false, null);
                queueSave(card);
            });
        });

        const fileInput = card.querySelector('[data-upload-input]');
        const uploadButton = card.querySelector('[data-upload-button]');
        const uploadStatus = card.querySelector('[data-upload-status]');
        uploadButton?.addEventListener('click', async () => {
            const file = fileInput?.files?.[0];
            if (!file) {
                showToast('Pilih file terlebih dahulu.');
                return;
            }
            uploadButton.disabled = true;
            uploadingCount += 1;
            if (uploadStatus) uploadStatus.textContent = 'Mengunggah file…';
            setSaveStatus('saving', 'Mengunggah berkas…');
            try {
                const result = await postAction('upload_file', { question_id: card.dataset.questionId }, file);
                const list = card.querySelector('[data-uploaded-files]');
                if (list) {
                    const link = document.createElement('a');
                    link.className = 'uploaded-file';
                    link.href = `exam.php?id=${attemptId}&asset=answer_file&record_id=${encodeURIComponent(result.file_id)}`;
                    link.setAttribute('download', '');
                    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    icon.setAttribute('class', 'icon');
                    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
                    use.setAttribute('href', '#x-icon-file');
                    icon.appendChild(use);
                    const filename = document.createTextNode(result.file_name || file.name);
                    link.append(icon, filename);
                    const size = document.createElement('small');
                    size.textContent = `${Math.round((Number(result.file_size || file.size) / 1024))} KB`;
                    link.appendChild(size);
                    list.prepend(link);
                }
                if (uploadStatus) uploadStatus.textContent = 'File tersimpan dengan aman';
                fileInput.value = '';
                updateAnsweredState(card);
                setSaveStatus('saved', 'Berkas tersimpan otomatis');
            } catch (error) {
                if (uploadStatus) uploadStatus.textContent = 'Unggah gagal · coba kembali';
                setSaveStatus('error', 'Unggah berkas gagal');
                showToast(error.message || 'File belum berhasil diunggah.');
            } finally {
                uploadingCount = Math.max(0, uploadingCount - 1);
                uploadButton.disabled = false;
            }
        });
        fileInput?.addEventListener('change', () => {
            if (fileInput.files?.[0] && uploadStatus) uploadStatus.textContent = `Terpilih: ${fileInput.files[0].name}`;
        });
    });

    const completeModule = async () => {
        if (!window.confirm('Selesaikan modul ini dan lanjutkan ke modul berikutnya? Jawaban wajib yang belum lengkap akan diminta kembali.')) return;
        const buttons = [...document.querySelectorAll('[data-finish-module]')];
        try {
            await flushSaves();
            buttons.forEach((button) => { button.disabled = true; button.textContent = 'Menyimpan modul…'; });
            setSaveStatus('saving', 'Mengirim modul…');
            const result = await postAction('complete_module');
            window.location.assign(result.redirect || `exam.php?id=${attemptId}`);
        } catch (error) {
            buttons.forEach((button) => { button.disabled = false; button.textContent = 'Selesaikan Modul'; });
            setSaveStatus('error', 'Modul belum terkirim');
            showToast(error.message || 'Modul belum dapat diselesaikan.');
            const firstUnanswered = questions.findIndex((card) => card.dataset.required === '1' && !answerLooksFilled(card));
            if (firstUnanswered >= 0) showQuestion(firstUnanswered);
        }
    };
    document.querySelectorAll('[data-finish-module]').forEach((button) => button.addEventListener('click', () => { void completeModule(); }));

    const reviewSelect = document.querySelector('[data-review-target]');
    reviewSelect?.addEventListener('change', async () => {
        try {
            await flushSaves();
            const url = new URL(window.location.href);
            url.searchParams.set('review', reviewSelect.value);
            window.location.assign(url.toString());
        } catch (error) {
            showToast(error.message || 'Jawaban tertunda belum tersimpan.');
        }
    });

    const expireModule = async () => {
        if (expiredRequestRunning) return;
        expiredRequestRunning = true;
        timerBox?.classList.add('is-expired');
        setSaveStatus('saving', 'Waktu habis · mengirim modul…');
        try {
            const result = await postAction('expire_module');
            window.location.assign(result.redirect || `exam.php?id=${attemptId}`);
        } catch (error) {
            if (error.message?.includes('Waktu modul belum habis')) {
                expiredRequestRunning = false;
                deadline = Date.now() + 1200;
                window.setTimeout(updateTimer, 1200);
                return;
            }
            showToast(error.message || 'Modul akan dikirim otomatis ketika koneksi kembali.');
            window.setTimeout(() => window.location.reload(), 1800);
        }
    };

    function updateTimer() {
        const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        if (timerText) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const remaining = seconds % 60;
            const pad = (value) => String(value).padStart(2, '0');
            timerText.textContent = hours > 0 ? `${hours}:${pad(minutes)}:${pad(remaining)}` : `${pad(minutes)}:${pad(remaining)}`;
        }
        timerBox?.classList.toggle('is-warning', seconds > 0 && seconds <= 300);
        if (seconds <= 0) {
            void expireModule();
        }
    }

    const targetSelect = document.querySelector('[data-review-target]');
    // If another device/browser has already marked this target complete, do not leave the selector stale.
    targetSelect?.addEventListener('focus', () => {});
    showQuestion(0);
    updateTimer();
    window.setInterval(updateTimer, 1000);
    const retryFailedSaves = () => {
        if (!navigator.onLine || failedSaves.size === 0) return;
        failedSaves.forEach((card, questionId) => pendingSaves.set(questionId, card));
        failedSaves.clear();
        void drainSaves();
    };
    window.setInterval(() => {
        if (navigator.onLine) retryFailedSaves();
        if (pendingSaves.size > 0) void drainSaves();
    }, 10000);
    window.addEventListener('online', () => {
        showToast('Koneksi kembali. Jawaban tertunda akan dikirim.');
        retryFailedSaves();
    });
    window.addEventListener('offline', () => {
        setSaveStatus('error', 'Offline · menunggu koneksi untuk menyimpan');
        showToast('Koneksi terputus. Jangan tutup halaman; jawaban tertunda akan dikirim saat koneksi kembali.');
    });
    window.addEventListener('beforeunload', (event) => {
        if (pendingSaves.size > 0 || failedSaves.size > 0 || saving || uploadingCount > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
