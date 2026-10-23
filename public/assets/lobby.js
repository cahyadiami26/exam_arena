(() => {
    const toast = document.getElementById('lobby-toast');
    let toastTimer;
    const showToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3600);
    };

    const tabButtons = [...document.querySelectorAll('[data-tab-target]')];
    const selectTab = (name) => {
        tabButtons.forEach((button) => {
            const selected = button.dataset.tabTarget === name;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        document.querySelectorAll('.access-panel[role="tabpanel"]').forEach((panel) => {
            const selected = panel.id === `${name}-panel`;
            panel.classList.toggle('is-active', selected);
            panel.hidden = !selected;
        });
        const url = new URL(window.location.href);
        url.searchParams.set('mode', name);
        window.history.replaceState({}, '', url);
    };
    tabButtons.forEach((button) => button.addEventListener('click', () => selectTab(button.dataset.tabTarget)));

    document.querySelectorAll('[data-avatar]').forEach((image) => {
        image.addEventListener('error', () => {
            image.remove();
            const fallback = image.parentElement?.querySelector('.avatar-fallback');
            if (fallback) fallback.hidden = false;
        }, { once: true });
    });
    document.querySelectorAll('[data-brand-logo]').forEach((image) => {
        image.addEventListener('error', () => image.parentElement?.classList.add('logo-failed'), { once: true });
    });

    const setCheckState = (element, state, message) => {
        if (!element) return;
        element.dataset.state = state;
        const text = element.querySelector('[data-status-text]');
        if (text) text.textContent = message;
    };

    const withTimeout = async (url, timeoutMs = 5000) => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), timeoutMs);
        try {
            const response = await fetch(url, {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: controller.signal
            });
            if (!response.ok) throw new Error('Server tidak merespons dengan baik.');
            const payload = await response.json();
            if (payload.ok !== true) throw new Error('Pemeriksaan layanan gagal.');
            return response;
        } finally {
            window.clearTimeout(timer);
        }
    };

    const checkNetwork = async () => {
        if (!navigator.onLine) throw new Error('Perangkat sedang offline.');
        const timings = [];
        const endpoint = new URL('lobby.php', window.location.href);
        for (let attempt = 0; attempt < 3; attempt += 1) {
            endpoint.searchParams.set('health', `${Date.now()}-${attempt}`);
            const started = performance.now();
            await withTimeout(endpoint.toString());
            timings.push(performance.now() - started);
        }
        const average = timings.reduce((sum, value) => sum + value, 0) / timings.length;
        const jitter = Math.max(...timings) - Math.min(...timings);
        if (average > 1500 || jitter > 1200) {
            throw new Error(`Respons kurang stabil (rata-rata ${Math.round(average)} ms).`);
        }
        return `Stabil · ${Math.round(average)} ms rata-rata`;
    };

    const checkCamera = async () => {
        if (!window.isSecureContext) throw new Error('Pemeriksaan kamera membutuhkan koneksi HTTPS.');
        if (!navigator.mediaDevices?.getUserMedia) throw new Error('Browser tidak mendukung akses kamera.');
        const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        const videoTracks = stream.getVideoTracks();
        const cameraFound = videoTracks.some((track) => track.readyState === 'live');
        videoTracks.forEach((track) => track.stop());
        stream.getTracks().forEach((track) => track.stop());
        if (!cameraFound) throw new Error('Kamera tidak terdeteksi.');
        return 'Kamera siap digunakan';
    };

    document.querySelectorAll('[data-device-panel]').forEach((panel) => {
        const cameraStatus = panel.querySelector('[data-camera-status]');
        const networkStatus = panel.querySelector('[data-network-status]');
        const checkButton = panel.querySelector('[data-run-device-check]');
        const startButton = panel.querySelector('[data-start-exam]');
        const reason = panel.querySelector('[data-start-reason]');
        const stageNote = panel.querySelector('[data-exam-stage-note]');
        let cameraPassed = false;
        let networkPassed = false;
        let checkInProgress = false;

        const updateStartButton = () => {
            const scheduleAvailable = panel.dataset.startAvailable === '1';
            const ready = cameraPassed && networkPassed && navigator.onLine;
            if (startButton) startButton.disabled = !(ready && scheduleAvailable);
            if (ready && scheduleAvailable) {
                if (reason) reason.textContent = 'Perangkat siap dan jadwal sedang dibuka.';
                if (stageNote) stageNote.hidden = false;
            } else if (stageNote) {
                stageNote.hidden = true;
            }
        };

        checkButton?.addEventListener('click', async () => {
            if (checkInProgress) return;
            checkInProgress = true;
            cameraPassed = false;
            networkPassed = false;
            if (startButton) startButton.disabled = true;
            if (stageNote) stageNote.hidden = true;
            if (reason && panel.dataset.startAvailable !== '1') {
                // Keep the server's schedule-window explanation visible when it is not yet open.
            } else if (reason) {
                reason.textContent = 'Sedang menjalankan pemeriksaan perangkat…';
            }
            if (checkButton) {
                checkButton.disabled = true;
                checkButton.innerHTML = '<svg class="icon"><use href="#l-icon-activity"></use></svg>Memeriksa…';
            }
            setCheckState(cameraStatus, 'checking', 'Meminta izin kamera…');
            setCheckState(networkStatus, 'checking', 'Mengukur kestabilan…');

            const [cameraResult, networkResult] = await Promise.allSettled([checkCamera(), checkNetwork()]);
            if (cameraResult.status === 'fulfilled') {
                cameraPassed = true;
                setCheckState(cameraStatus, 'passed', cameraResult.value);
            } else {
                setCheckState(cameraStatus, 'failed', cameraResult.reason?.message || 'Kamera belum siap');
            }
            if (networkResult.status === 'fulfilled') {
                networkPassed = true;
                setCheckState(networkStatus, 'passed', networkResult.value);
            } else {
                setCheckState(networkStatus, 'failed', networkResult.reason?.message || 'Koneksi belum stabil');
            }

            if (checkButton) {
                checkButton.disabled = false;
                checkButton.innerHTML = '<svg class="icon"><use href="#l-icon-activity"></use></svg>Ulangi Pemeriksaan';
            }
            checkInProgress = false;
            if (!(cameraPassed && networkPassed) && reason) {
                reason.textContent = 'Selesaikan pemeriksaan yang gagal sebelum melanjutkan.';
            }
            updateStartButton();
            if (cameraPassed && networkPassed && panel.dataset.startAvailable !== '1') {
                showToast('Perangkat siap. Tombol ujian akan aktif saat jadwal dibuka oleh HR.');
            } else if (cameraPassed && networkPassed) {
                showToast('Pemeriksaan webcam dan koneksi berhasil.');
            }
        });

        window.addEventListener('offline', () => {
            networkPassed = false;
            setCheckState(networkStatus, 'failed', 'Perangkat sedang offline');
            updateStartButton();
        });
        window.addEventListener('online', () => {
            networkPassed = false;
            setCheckState(networkStatus, 'idle', 'Koneksi berubah; periksa ulang');
            updateStartButton();
        });
    });

    const tokenInput = document.getElementById('candidate-token');
    tokenInput?.addEventListener('input', () => {
        tokenInput.value = tokenInput.value.replace(/\s+/g, '').toUpperCase();
    });
})();
