(() => {
    const body = document.body;
    const toggle = document.querySelector('[data-portal-menu-toggle]');
    const backdrop = document.querySelector('[data-portal-menu-close]');
    const sidebar = document.getElementById('portal-sidebar');

    if (!toggle || !sidebar) {
        return;
    }

    const closeMenu = () => {
        body.classList.remove('portal-sidebar-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Buka menu navigasi');
    };

    toggle.addEventListener('click', () => {
        const isOpen = body.classList.toggle('portal-sidebar-open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggle.setAttribute('aria-label', isOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi');
    });

    backdrop?.addEventListener('click', closeMenu);
    sidebar.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });
})();
