    </div><!-- /.content-area -->
</div><!-- /.main-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    // ── PWA: Service Worker + Install prompt ──
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    }

    (function () {
        let deferredPrompt = null;
        const btn = document.getElementById('fzl-install-btn');

        window.addEventListener('beforeinstallprompt', e => {
            e.preventDefault();
            deferredPrompt = e;
            if (btn) btn.style.display = 'flex';
        });

        btn?.addEventListener('click', async () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') btn.style.display = 'none';
            deferredPrompt = null;
        });

        window.addEventListener('appinstalled', () => {
            if (btn) btn.style.display = 'none';
            deferredPrompt = null;
        });
    })();

    // ── Sidebar toggle ──
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    const ham     = document.getElementById('hamburger');

    function openSidebar()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }

    ham?.addEventListener('click', () =>
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar()
    );
    overlay?.addEventListener('click', closeSidebar);

    // ── Page transition progress bar ──
    const bar     = document.getElementById('fzl-bar');
    let   resetTimer = null;
    let   active  = false;

    function startBar() {
        if (active) return;
        active = true;
        clearTimeout(resetTimer);
        bar.style.transition = 'none';
        bar.style.width      = '0%';
        bar.style.opacity    = '1';
        bar.classList.add('indeterminate');
        requestAnimationFrame(() => requestAnimationFrame(() => {
            bar.style.transition = 'width 1.4s cubic-bezier(0.08, 0.82, 0.17, 1)';
            bar.style.width      = '82%';
        }));
        // Safety reset if navigation was cancelled (e.g. confirm() rejected)
        resetTimer = setTimeout(abortBar, 10000);
    }

    function completeBar() {
        clearTimeout(resetTimer);
        bar.classList.remove('indeterminate');
        bar.style.transition = 'width 0.12s ease';
        bar.style.width      = '100%';
        bar.style.opacity    = '1';
        setTimeout(() => {
            bar.style.transition = 'opacity 0.3s ease';
            bar.style.opacity    = '0';
            setTimeout(() => { bar.style.width = '0%'; active = false; }, 320);
        }, 130);
    }

    function abortBar() {
        bar.classList.remove('indeterminate');
        bar.style.transition = 'opacity 0.25s ease';
        bar.style.opacity    = '0';
        setTimeout(() => { bar.style.width = '0%'; active = false; }, 260);
    }

    // Complete bar on this page finishing load
    completeBar();

    // Start bar when navigating away via a link
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href
            || href.startsWith('#')
            || href.startsWith('javascript:')
            || href.startsWith('mailto:')
            || link.target === '_blank'
            || e.ctrlKey || e.metaKey || e.shiftKey) return;
        // Skip external URLs not on this host
        if (/^https?:\/\//i.test(href) && !href.includes(location.hostname)) return;
        startBar();
    });

    // Start bar on form submit too
    document.addEventListener('submit', function () { startBar(); });

    // Flash to 100% the instant the browser starts unloading
    window.addEventListener('beforeunload', function () {
        clearTimeout(resetTimer);
        bar.classList.remove('indeterminate');
        bar.style.transition = 'width 0.08s ease';
        bar.style.width      = '100%';
        bar.style.opacity    = '1';
    });
})();
</script>
<?php if (!empty($extraScript)) echo $extraScript; ?>
</body>
</html>
