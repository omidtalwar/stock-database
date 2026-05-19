<?php if (empty($_SESSION['user_id'])) return; ?>
<?php $_fabAdmin = function_exists('isAdmin') && isAdmin(); ?>
<!-- ── Global FAB ── -->
<div id="fzl-fab-root">
    <?php if ($_fabAdmin): ?>
    <a class="fzl-act" href="/dashboard.php"      style="--clr:#4F46E5"><i class="bi bi-grid-1x2-fill"></i><span class="fzl-tip">Dashboard</span></a>
    <?php endif; ?>
    <a class="fzl-act" href="/stock/add.php"       style="--clr:#0E7490"><i class="bi bi-box-seam-fill"></i><span class="fzl-tip">Stock In</span></a>
    <a class="fzl-act" href="/payments/add.php"    style="--clr:#107C10"><i class="bi bi-cash-stack"></i><span class="fzl-tip">Record Payment</span></a>
    <a class="fzl-act fzl-act--star" href="/sales/create.php" style="--clr:#0067C0"><i class="bi bi-plus-circle-fill"></i><span class="fzl-tip">New Invoice</span></a>
    <a class="fzl-act" href="/sales/index.php"     style="--clr:#9D5D00"><i class="bi bi-receipt"></i><span class="fzl-tip">Sales</span></a>
    <a class="fzl-act" href="/customers/index.php" style="--clr:#7719AA"><i class="bi bi-people-fill"></i><span class="fzl-tip">Customers</span></a>
    <button id="fzl-fab-btn" aria-label="Quick actions" aria-expanded="false">
        <i class="bi bi-lightning-charge-fill" id="fzl-fab-ico"></i>
    </button>
</div>

<style>
#fzl-fab-root {
    position: fixed;
    bottom: 20px;
    right: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 1039;
    pointer-events: none;
}
#fzl-fab-btn {
    flex-shrink: 0;
    pointer-events: auto;
    width: 46px; height: 46px;
    border-radius: 50%;
    border: none; cursor: pointer; color: #fff; font-size: 1.2rem;
    background: linear-gradient(135deg, #0067C0 0%, #4338CA 100%);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 18px rgba(0,103,192,0.5);
    outline: none; position: relative; z-index: 2;
    transition: transform .25s cubic-bezier(.34,1.56,.64,1), background .25s, box-shadow .25s;
    animation: fabPulse 4s ease-in-out infinite 3s;
}
#fzl-fab-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 24px rgba(0,103,192,0.65);
    animation: none;
}
#fzl-fab-root.fab-open #fzl-fab-btn {
    background: linear-gradient(135deg, #C42B1C 0%, #7F1D1D 100%);
    box-shadow: 0 4px 18px rgba(196,43,28,0.5);
    animation: none;
    transform: rotate(0deg);
}
@keyframes fabPulse {
    0%,100% { box-shadow: 0 4px 18px rgba(0,103,192,0.5), 0 0 0 0 rgba(0,103,192,0.35); }
    55%     { box-shadow: 0 4px 18px rgba(0,103,192,0.5), 0 0 0 13px rgba(0,103,192,0); }
}
#fzl-fab-ico {
    display: block; line-height: 1;
    transition: transform .2s ease, opacity .15s ease;
}
.fzl-act {
    flex-shrink: 0;
    width: 38px; height: 38px;
    border-radius: 12px;
    background: var(--clr, #0067C0);
    color: #fff; text-decoration: none;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    box-shadow: 0 3px 14px rgba(0,0,0,0.22), inset 0 1px 0 rgba(255,255,255,0.18);
    opacity: 0;
    pointer-events: none;
    position: relative;
    transform: translateX(var(--start-x, 60px)) scale(0.55);
    transition:
        transform .45s cubic-bezier(0.34,1.58,0.64,1),
        opacity   .3s ease;
}
.fzl-act--star {
    width: 44px; height: 44px;
    border-radius: 14px; font-size: 1.1rem;
    box-shadow: 0 4px 18px rgba(0,103,192,0.38), inset 0 1px 0 rgba(255,255,255,0.22);
}
.fzl-act.fab-live {
    pointer-events: auto;
}
.fzl-act:hover {
    filter: brightness(1.15);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.25);
}
/* Open state: slide to natural position */
#fzl-fab-root.fab-open .fzl-act {
    opacity: 1;
    transform: translateX(0) scale(1);
}
/* Stagger via transition-delay — set in JS */
.fzl-tip {
    position: absolute;
    bottom: calc(100% + 7px);
    left: 50%;
    transform: translateX(-50%) translateY(5px);
    background: rgba(10,10,10,0.88);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    color: #fff; font-size: .68rem; font-weight: 600; letter-spacing: .2px;
    padding: 4px 10px; border-radius: 18px;
    white-space: nowrap; pointer-events: none; opacity: 0;
    transition: opacity .15s, transform .15s;
}
.fzl-act:hover .fzl-tip {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}
@media (max-width: 480px) {
    #fzl-fab-root { bottom: 14px; right: 14px; gap: 6px; }
    .fzl-tip { display: none; }
    #fzl-fab-btn { width: 42px; height: 42px; font-size: 1.05rem; }
    .fzl-act { width: 34px; height: 34px; font-size: .9rem; }
    .fzl-act--star { width: 40px; height: 40px; }
}
</style>

<script>
(function () {
    const root = document.getElementById('fzl-fab-root');
    const btn  = document.getElementById('fzl-fab-btn');
    const ico  = document.getElementById('fzl-fab-ico');
    const acts = Array.from(document.querySelectorAll('.fzl-act'));
    if (!root || !btn || !acts.length) return;

    const BTN_STEP = 46; // 38px button + 8px gap
    let isOpen = false;

    // Set each button's collapsed start position (distance from its natural spot back to FAB)
    acts.forEach((act, i) => {
        const dist = (acts.length - i) * BTN_STEP + 14;
        act.style.setProperty('--start-x', dist + 'px');
    });

    // ── Icon morph ────────────────────────────────────────────────
    function morphIcon(toX) {
        ico.style.opacity   = '0';
        ico.style.transform = `scale(0.3) rotate(${toX ? 100 : -100}deg)`;
        setTimeout(() => {
            ico.className       = toX ? 'bi bi-x-lg' : 'bi bi-lightning-charge-fill';
            ico.style.transform = `scale(0.3) rotate(${toX ? -100 : 100}deg)`;
            requestAnimationFrame(() => requestAnimationFrame(() => {
                ico.style.opacity   = '1';
                ico.style.transform = 'scale(1) rotate(0deg)';
            }));
        }, 110);
    }

    // ── Open ──────────────────────────────────────────────────────
    function openFab() {
        isOpen = true;
        // Stagger: rightmost button (closest to FAB) enters first
        acts.slice().reverse().forEach((act, ri) => {
            act.style.transitionDelay = `${ri * 55}ms`;
            act.classList.add('fab-live');
        });
        root.classList.add('fab-open');
        btn.setAttribute('aria-expanded', 'true');
        morphIcon(true);
    }

    // ── Close ─────────────────────────────────────────────────────
    function closeFab() {
        isOpen = false;
        // Stagger: leftmost exits first
        acts.forEach((act, i) => {
            act.style.transitionDelay = `${i * 40}ms`;
        });
        root.classList.remove('fab-open');
        btn.setAttribute('aria-expanded', 'false');
        morphIcon(false);
        // Remove pointer-events after animation
        const cleanup = acts.length * 40 + 480;
        setTimeout(() => {
            if (!isOpen) acts.forEach(a => a.classList.remove('fab-live'));
        }, cleanup);
    }

    btn.addEventListener('click', () => isOpen ? closeFab() : openFab());
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && isOpen) closeFab(); });

    // Close when clicking a page link inside FAB (navigation away)
    acts.forEach(act => act.addEventListener('click', () => closeFab()));
})();
</script>
