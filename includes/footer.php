    </div><!-- /.content-area -->
</div><!-- /.main-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Move Bootstrap modals to <body> so they escape the stacking context
// created by .content-area's CSS animation (transform creates a stacking
// context that traps modal z-indices below the backdrop).
document.querySelectorAll('.modal').forEach(function(m){ document.body.appendChild(m); });

(function () {
    // ── PWA: Service Worker + Install prompt ──
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    }

    (function () {
        let deferredPrompt = null;

        const btn        = document.getElementById('fzl-install-btn');
        const modal      = document.getElementById('fzl-install-modal');
        const closeBtn   = document.getElementById('fzl-modal-close');
        const nativeBtn  = document.getElementById('fzl-native-btn');
        const stepsEl    = document.getElementById('fzl-install-steps');

        // Detect platform
        const ua       = navigator.userAgent;
        const isIOS    = /iphone|ipad|ipod/i.test(ua);
        const isSafari = /safari/i.test(ua) && !/chrome/i.test(ua);
        const isAndroid = /android/i.test(ua);

        function getSteps() {
            if (deferredPrompt) return null; // will use native button
            if (isIOS && isSafari) return `
                <b>On iPhone / iPad (Safari):</b><br>
                1. Tap the <b>Share</b> button <span style="font-size:1.1em;">⎦↑</span> at the bottom of Safari<br>
                2. Scroll down and tap <b>"Add to Home Screen"</b><br>
                3. Tap <b>Add</b> — the app icon will appear on your home screen`;
            if (isAndroid) return `
                <b>On Android (Chrome):</b><br>
                1. Tap the <b>⋮ menu</b> in the top-right corner<br>
                2. Tap <b>"Add to Home screen"</b> or <b>"Install app"</b><br>
                3. Tap <b>Install</b>`;
            return `
                <b>On Chrome / Edge (Desktop):</b><br>
                1. Look for the <b>install icon</b> <span style="font-size:1.1em;">⊕</span> in the address bar (right side)<br>
                2. Click it and then click <b>Install</b><br><br>
                <b>Or:</b> Open the browser menu → <b>"Install FZL…"</b>`;
        }

        function openModal() {
            const steps = getSteps();
            if (deferredPrompt) {
                stepsEl.innerHTML = '<b>Click below to install FZL on your device:</b>';
                nativeBtn.style.display = 'block';
            } else {
                stepsEl.innerHTML = steps;
                nativeBtn.style.display = 'none';
            }
            modal.style.display = 'flex';
        }

        function closeModal() { modal.style.display = 'none'; }

        btn?.addEventListener('click', openModal);
        closeBtn?.addEventListener('click', closeModal);
        modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });

        nativeBtn?.addEventListener('click', async () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                closeModal();
                btn.style.display = 'none';
            }
            deferredPrompt = null;
            nativeBtn.style.display = 'none';
        });

        // Capture native install prompt when browser offers it
        window.addEventListener('beforeinstallprompt', e => {
            e.preventDefault();
            deferredPrompt = e;
        });

        // Hide button once app is installed
        window.addEventListener('appinstalled', () => {
            if (btn) btn.style.display = 'none';
            closeModal();
            deferredPrompt = null;
        });

        // Also hide if already running in standalone (already installed)
        if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) {
            if (btn) btn.style.display = 'none';
        }
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

// ── Auto-convert Arabic/Persian digits to Western digits ──
// Fires on beforeinput (before the browser validates the character),
// so it works even on type="number" inputs that would otherwise reject ۱٢٣.
(function () {
    var AR = [/[٠-٩]/g, /[۰-۹]/g];

    function toWestern(str) {
        return str
            .replace(AR[0], function(c){ return c.charCodeAt(0) - 0x0660; })
            .replace(AR[1], function(c){ return c.charCodeAt(0) - 0x06F0; });
    }

    document.addEventListener('beforeinput', function(e) {
        if (!e.data) return;
        var converted = toWestern(e.data);
        if (converted === e.data) return;
        e.preventDefault();
        // execCommand works across all input types including type="number"
        // and correctly handles cursor/selection position
        document.execCommand('insertText', false, converted);
    }, true);

    // Also handle paste (clipboard may contain Arabic numerals)
    document.addEventListener('paste', function(e) {
        var el = e.target;
        if (el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA') return;
        var text = (e.clipboardData || window.clipboardData).getData('text');
        var converted = toWestern(text);
        if (converted === text) return;
        e.preventDefault();
        document.execCommand('insertText', false, converted);
    }, true);
})();
</script>
<?php if (!empty($extraScript)) echo $extraScript; ?>

<?php require_once __DIR__ . '/fab.php'; ?>

</body>
</html>
