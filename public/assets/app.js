(() => {
    const body = document.body;
    const menuButton = document.querySelector('[data-sidebar-toggle]');
    const closeBackdrop = document.querySelector('[data-sidebar-close]');
    const sidebar = document.getElementById('sidebar');
    const toast = document.getElementById('app-toast');
    let toastTimer;

    const openSidebar = () => {
        body.classList.add('sidebar-open');
        menuButton?.setAttribute('aria-expanded', 'true');
        sidebar?.querySelector('a, button')?.focus({ preventScroll: true });
    };

    const closeSidebar = () => {
        body.classList.remove('sidebar-open');
        menuButton?.setAttribute('aria-expanded', 'false');
        menuButton?.focus({ preventScroll: true });
    };

    menuButton?.addEventListener('click', () => {
        if (body.classList.contains('sidebar-open')) closeSidebar();
        else openSidebar();
    });
    closeBackdrop?.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && body.classList.contains('sidebar-open')) closeSidebar();
    });

    const showToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
    };

    document.querySelectorAll('[data-coming-soon]').forEach((control) => {
        control.addEventListener('click', () => {
            showToast('Modul ini disiapkan pada tahap pengembangan berikutnya.');
        });
    });

    document.querySelectorAll('[data-avatar]').forEach((image) => {
        image.addEventListener('error', () => {
            image.remove();
            const fallback = image.parentElement?.querySelector('.avatar-fallback');
            if (fallback) fallback.hidden = false;
        }, { once: true });
    });

    document.querySelectorAll('[data-brand-logo]').forEach((image) => {
        image.addEventListener('error', () => {
            image.parentElement?.classList.add('logo-failed');
        }, { once: true });
    });

    const clock = document.getElementById('local-clock');
    if (clock && 'Intl' in window) {
        const formatter = new Intl.DateTimeFormat('id-ID', {
            timeZone: 'Asia/Jakarta',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23'
        });
        const updateClock = () => { clock.textContent = `${formatter.format(new Date())} WIB`; };
        updateClock();
        window.setInterval(updateClock, 1000);
    }

    const formatElapsed = (totalSeconds) => {
        const seconds = Math.max(0, totalSeconds);
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remaining = seconds % 60;
        const pad = (value) => String(value).padStart(2, '0');
        return hours > 0 ? `${hours}:${pad(minutes)}:${pad(remaining)}` : `${pad(minutes)}:${pad(remaining)}`;
    };

    document.querySelectorAll('[data-elapsed]').forEach((element) => {
        let elapsed = Number.parseInt(element.dataset.elapsed || '0', 10);
        if (!Number.isFinite(elapsed)) elapsed = 0;
        const update = () => {
            element.textContent = `Berjalan ${formatElapsed(elapsed)}`;
            elapsed += 1;
        };
        update();
        window.setInterval(update, 1000);
    });
})();
