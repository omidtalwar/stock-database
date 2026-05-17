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

<?php if (!empty($_SESSION['user_id'])): ?>
<?php $_fabAdmin = function_exists('isAdmin') && isAdmin(); ?>
<!-- ── Global Floating Action Button ── -->
<div id="fzl-fab-root" aria-label="Quick actions">
    <div id="fzl-fab-scrim"></div>
    <div id="fzl-fab-wrap">
        <div id="fzl-fab-actions">
            <?php if ($_fabAdmin): ?>
            <a class="fzl-act" href="/dashboard.php"        style="--clr:#4F46E5;--i:0"><i class="bi bi-grid-1x2-fill"></i><span class="fzl-tip">Dashboard</span></a>
            <?php endif; ?>
            <a class="fzl-act" href="/customers/index.php"  style="--clr:#7719AA;--i:<?= $_fabAdmin?1:0 ?>"><i class="bi bi-people-fill"></i><span class="fzl-tip">Customers</span></a>
            <a class="fzl-act" href="/sales/index.php"      style="--clr:#9D5D00;--i:<?= $_fabAdmin?2:1 ?>"><i class="bi bi-receipt"></i><span class="fzl-tip">Sales</span></a>
            <a class="fzl-act fzl-act--star" href="/sales/create.php"   style="--clr:#0067C0;--i:<?= $_fabAdmin?3:2 ?>"><i class="bi bi-plus-circle-fill"></i><span class="fzl-tip">New Invoice</span></a>
            <a class="fzl-act" href="/payments/add.php"     style="--clr:#107C10;--i:<?= $_fabAdmin?4:3 ?>"><i class="bi bi-cash-stack"></i><span class="fzl-tip">Record Payment</span></a>
            <a class="fzl-act" href="/stock/add.php"        style="--clr:#0E7490;--i:<?= $_fabAdmin?5:4 ?>"><i class="bi bi-box-seam-fill"></i><span class="fzl-tip">Stock In</span></a>
        </div>
        <button id="fzl-fab-btn" aria-expanded="false">
            <i class="bi bi-lightning-charge-fill" id="fzl-fab-ico"></i>
        </button>
    </div>
</div>

<style>
/* ── FAB Root ── */
#fzl-fab-root {
    position: fixed; inset: 0; z-index: 1039;
    pointer-events: none;
}
#fzl-fab-scrim {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.42);
    backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
    opacity: 0; pointer-events: none;
    transition: opacity .35s ease;
}
#fzl-fab-root.fab-open #fzl-fab-scrim {
    opacity: 1; pointer-events: auto;
}

/* ── Wrap (magnetic target) ── */
#fzl-fab-wrap {
    position: absolute; bottom: 28px; left: 50%;
    transform: translateX(-50%);
    pointer-events: auto;
    will-change: transform;
}

/* ── Main trigger button ── */
#fzl-fab-btn {
    position: relative; z-index: 2;
    width: 62px; height: 62px; border-radius: 50%;
    border: none; cursor: pointer; color: #fff; font-size: 1.4rem;
    background: linear-gradient(135deg, #0067C0 0%, #4338CA 100%);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 22px rgba(0,103,192,0.52), 0 0 0 0 rgba(0,103,192,0.28);
    transition: transform .3s cubic-bezier(.34,1.56,.64,1), background .35s, box-shadow .3s;
    outline: none;
    animation: fabPulse 4s ease-in-out infinite 4s;
}
#fzl-fab-btn:hover {
    transform: scale(1.12) !important;
    box-shadow: 0 8px 32px rgba(0,103,192,0.6), 0 0 0 10px rgba(0,103,192,0.12);
    animation: none;
}
#fzl-fab-root.fab-open #fzl-fab-btn {
    background: linear-gradient(135deg, #C42B1C 0%, #7F1D1D 100%);
    box-shadow: 0 4px 22px rgba(196,43,28,0.5);
    animation: none;
}
@keyframes fabPulse {
    0%,100% { box-shadow: 0 4px 22px rgba(0,103,192,0.52), 0 0 0 0   rgba(0,103,192,0.3); }
    55%     { box-shadow: 0 4px 22px rgba(0,103,192,0.52), 0 0 0 16px rgba(0,103,192,0);   }
}
#fzl-fab-ico {
    display: block; line-height: 1;
    transition: transform .22s ease, opacity .16s ease;
}

/* ── Actions container ── */
#fzl-fab-actions {
    position: absolute; bottom: 0; left: 0;
    width: 62px;
    pointer-events: none;
}

/* ── Individual action buttons ── */
.fzl-act {
    position: absolute;
    width: 50px; height: 50px;
    bottom: 6px; left: 6px;
    border-radius: 15px;
    background: var(--clr, #0067C0);
    color: #fff; text-decoration: none;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    box-shadow: 0 4px 18px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.2);
    transform: translate(0px, 0px) scale(0);
    opacity: 0;
    pointer-events: none;
    will-change: transform, opacity;
    transition:
        transform   .48s cubic-bezier(0.34, 1.6, 0.64, 1),
        opacity     .3s  ease,
        box-shadow  .2s  ease,
        filter      .2s  ease;
    transition-delay: calc(var(--i, 0) * 0.055s);
}
.fzl-act--star {
    width: 56px; height: 56px;
    bottom: 3px; left: 3px;
    border-radius: 18px;
    font-size: 1.25rem;
    box-shadow: 0 6px 24px rgba(0,103,192,0.45), inset 0 1px 0 rgba(255,255,255,0.25);
}
.fzl-act:hover {
    box-shadow: 0 8px 28px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.25);
    filter: brightness(1.15);
}
#fzl-fab-root.fab-open .fzl-act {
    transform: translate(var(--tx, 0px), var(--ty, 0px)) scale(1);
    opacity: 1;
    pointer-events: auto;
}

/* ── Closing: reverse stagger ── */
#fzl-fab-root.fab-closing .fzl-act {
    transition-delay: calc((5 - var(--i, 0)) * 0.04s);
}

/* ── Labels ── */
.fzl-tip {
    position: absolute; top: 50%;
    background: rgba(10,10,10,0.88);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    color: #fff; font-size: .72rem; font-weight: 600; letter-spacing: .2px;
    padding: 5px 12px; border-radius: 22px;
    white-space: nowrap; pointer-events: none;
    opacity: 0;
    transition: opacity .18s, transform .18s;
}
.fzl-act.tip-left  .fzl-tip { right: calc(100% + 10px); transform: translateY(-50%) translateX(5px); }
.fzl-act.tip-right .fzl-tip { left:  calc(100% + 10px); transform: translateY(-50%) translateX(-5px); }
.fzl-act:hover .fzl-tip { opacity: 1; }
.fzl-act.tip-left:hover  .fzl-tip { transform: translateY(-50%) translateX(0); }
.fzl-act.tip-right:hover .fzl-tip { transform: translateY(-50%) translateX(0); }

@media (max-width: 480px) {
    #fzl-fab-wrap { bottom: 18px; }
    .fzl-tip { display: none; }
}
</style>

<script>
(function () {
    const root  = document.getElementById('fzl-fab-root');
    const wrap  = document.getElementById('fzl-fab-wrap');
    const btn   = document.getElementById('fzl-fab-btn');
    const ico   = document.getElementById('fzl-fab-ico');
    const scrim = document.getElementById('fzl-fab-scrim');
    const acts  = Array.from(document.querySelectorAll('.fzl-act'));
    if (!root) return;

    // ── Compute arc positions ──────────────────────────────────────
    const N = acts.length;
    const RADIUS  = 105;
    const ANG_START = -150, ANG_END = -30;
    acts.forEach((el, i) => {
        const deg = N > 1 ? ANG_START + (ANG_END - ANG_START) * i / (N - 1) : -90;
        const rad = deg * Math.PI / 180;
        const tx  = Math.cos(rad) * RADIUS;
        const ty  = Math.sin(rad) * RADIUS;
        el.style.setProperty('--tx', tx.toFixed(1) + 'px');
        el.style.setProperty('--ty', ty.toFixed(1) + 'px');
        el.classList.add(tx <= 0 ? 'tip-left' : 'tip-right');
    });

    // ── Open / Close ──────────────────────────────────────────────
    let isOpen = false;

    function morphIcon(toClose) {
        ico.style.opacity = '0';
        ico.style.transform = 'scale(0.4) rotate(' + (toClose ? 90 : -90) + 'deg)';
        setTimeout(() => {
            ico.className = toClose ? 'bi bi-x-lg' : 'bi bi-lightning-charge-fill';
            ico.style.transform = 'scale(0.4) rotate(' + (toClose ? -90 : 90) + 'deg)';
            requestAnimationFrame(() => requestAnimationFrame(() => {
                ico.style.opacity = '1';
                ico.style.transform = 'scale(1) rotate(0deg)';
            }));
        }, 140);
    }

    function openFab() {
        isOpen = true;
        root.classList.remove('fab-closing');
        root.classList.add('fab-open');
        btn.setAttribute('aria-expanded', 'true');
        morphIcon(true);
        // Reset magnetic
        targetX = 0; targetY = 0;
    }

    function closeFab() {
        isOpen = false;
        root.classList.add('fab-closing');
        root.classList.remove('fab-open');
        btn.setAttribute('aria-expanded', 'false');
        morphIcon(false);
        setTimeout(() => root.classList.remove('fab-closing'), 500);
    }

    btn.addEventListener('click', () => isOpen ? closeFab() : openFab());
    scrim.addEventListener('click', closeFab);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && isOpen) closeFab(); });

    // Close on action click (navigate away)
    acts.forEach(a => a.addEventListener('click', () => closeFab()));

    // ── Magnetic gravity ──────────────────────────────────────────
    const BTN_H      = 62;
    const WRAP_BOT   = 28;
    const MAG_RADIUS = 240;    // px — activation distance
    const MAG_PULL   = 0.24;   // 0–1 — max pull ratio

    let targetX = 0, targetY = 0, curX = 0, curY = 0;
    let rafId   = null;

    function fabCenter() {
        return {
            x: window.innerWidth  / 2 + curX,
            y: window.innerHeight - WRAP_BOT - BTN_H / 2 + curY
        };
    }

    function animMag() {
        curX += (targetX - curX) * 0.13;
        curY += (targetY - curY) * 0.13;
        wrap.style.transform =
            'translate(calc(-50% + ' + curX.toFixed(2) + 'px), ' + curY.toFixed(2) + 'px)';
        const d = Math.abs(targetX - curX) + Math.abs(targetY - curY);
        rafId = d > 0.04 ? requestAnimationFrame(animMag) : null;
    }

    function kickMag() { if (!rafId) rafId = requestAnimationFrame(animMag); }

    document.addEventListener('mousemove', function (e) {
        if (isOpen) return;
        const c    = fabCenter();
        const dx   = e.clientX - c.x;
        const dy   = e.clientY - c.y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < MAG_RADIUS) {
            const t  = Math.pow(1 - dist / MAG_RADIUS, 2);
            targetX = dx * t * MAG_PULL;
            targetY = dy * t * MAG_PULL;
        } else {
            targetX = 0; targetY = 0;
        }
        kickMag();
    });

    document.addEventListener('mouseleave', function () {
        targetX = 0; targetY = 0; kickMag();
    });
})();
</script>
<?php endif; ?>

</body>
</html>
