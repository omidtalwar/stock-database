<?php if (empty($_SESSION['user_id'])) return; ?>
<?php $_fabAdmin = function_exists('isAdmin') && isAdmin(); ?>
<!-- ── Global FAB ── -->
<div id="fzl-fab-root">
    <div id="fzl-fab-wrap">
        <div id="fzl-fab-actions">
            <?php if ($_fabAdmin): ?>
            <a class="fzl-act" href="/dashboard.php"       style="--clr:#4F46E5;--i:0"><i class="bi bi-grid-1x2-fill"></i><span class="fzl-tip">Dashboard</span></a>
            <?php endif; ?>
            <a class="fzl-act" href="/customers/index.php" style="--clr:#7719AA;--i:<?= $_fabAdmin?1:0 ?>"><i class="bi bi-people-fill"></i><span class="fzl-tip">Customers</span></a>
            <a class="fzl-act" href="/sales/index.php"     style="--clr:#9D5D00;--i:<?= $_fabAdmin?2:1 ?>"><i class="bi bi-receipt"></i><span class="fzl-tip">Sales</span></a>
            <a class="fzl-act fzl-act--star" href="/sales/create.php"  style="--clr:#0067C0;--i:<?= $_fabAdmin?3:2 ?>"><i class="bi bi-plus-circle-fill"></i><span class="fzl-tip">New Invoice</span></a>
            <a class="fzl-act" href="/payments/add.php"    style="--clr:#107C10;--i:<?= $_fabAdmin?4:3 ?>"><i class="bi bi-cash-stack"></i><span class="fzl-tip">Record Payment</span></a>
            <a class="fzl-act" href="/stock/add.php"       style="--clr:#0E7490;--i:<?= $_fabAdmin?5:4 ?>"><i class="bi bi-box-seam-fill"></i><span class="fzl-tip">Stock In</span></a>
        </div>
        <button id="fzl-fab-btn" aria-expanded="false" aria-label="Quick actions">
            <i class="bi bi-lightning-charge-fill" id="fzl-fab-ico"></i>
        </button>
    </div>
</div>

<style>
#fzl-fab-root {
    position: fixed; inset: 0; z-index: 1039;
    pointer-events: none;
}
#fzl-fab-wrap {
    position: absolute; bottom: 28px; left: 50%;
    transform: translateX(-50%);
    pointer-events: auto;
    will-change: transform;
    transition: none;
}
#fzl-fab-btn {
    position: relative; z-index: 2;
    width: 62px; height: 62px; border-radius: 50%;
    border: none; cursor: pointer; color: #fff; font-size: 1.4rem;
    background: linear-gradient(135deg, #0067C0 0%, #4338CA 100%);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 22px rgba(0,103,192,0.52);
    transition: transform .3s cubic-bezier(.34,1.56,.64,1), background .3s, box-shadow .3s;
    outline: none;
    animation: fabPulse 4s ease-in-out infinite 5s;
}
#fzl-fab-btn:hover {
    transform: scale(1.1) !important;
    box-shadow: 0 8px 30px rgba(0,103,192,0.65), 0 0 0 10px rgba(0,103,192,0.1);
    animation: none;
}
#fzl-fab-root.fab-open #fzl-fab-btn {
    background: linear-gradient(135deg, #C42B1C 0%, #7F1D1D 100%);
    box-shadow: 0 4px 22px rgba(196,43,28,0.5);
    animation: none;
}
@keyframes fabPulse {
    0%,100% { box-shadow: 0 4px 22px rgba(0,103,192,0.52), 0 0 0 0   rgba(0,103,192,0.3); }
    55%     { box-shadow: 0 4px 22px rgba(0,103,192,0.52), 0 0 0 18px rgba(0,103,192,0);  }
}
#fzl-fab-ico {
    display: block; line-height: 1;
    transition: transform .22s ease, opacity .16s ease;
}
#fzl-fab-actions {
    position: absolute; bottom: 0; left: 0;
    width: 62px; pointer-events: none;
}
.fzl-act {
    position: absolute;
    width: 50px; height: 50px;
    bottom: 6px; left: 6px;
    border-radius: 15px;
    background: var(--clr, #0067C0);
    color: #fff; text-decoration: none;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    box-shadow: 0 4px 18px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.18);
    transform: translate(0px, 0px) scale(0);
    opacity: 0; pointer-events: none;
    will-change: transform, opacity;
    transition:
        transform  .5s  cubic-bezier(0.34, 1.65, 0.64, 1),
        opacity    .3s  ease,
        box-shadow .2s  ease,
        filter     .2s  ease;
    transition-delay: calc(var(--i, 0) * 0.055s);
}
.fzl-act--star {
    width: 56px; height: 56px;
    bottom: 3px; left: 3px;
    border-radius: 18px; font-size: 1.25rem;
    box-shadow: 0 6px 24px rgba(0,103,192,0.45), inset 0 1px 0 rgba(255,255,255,0.22);
}
.fzl-act:hover {
    box-shadow: 0 8px 28px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.25);
    filter: brightness(1.14);
}
#fzl-fab-root.fab-open .fzl-act {
    transform: translate(var(--tx,0px), var(--ty,0px)) scale(1);
    opacity: 1; pointer-events: auto;
}
#fzl-fab-root.fab-closing .fzl-act {
    transition-delay: calc((5 - var(--i,0)) * 0.04s);
}
.fzl-tip {
    position: absolute; top: 50%;
    background: rgba(10,10,10,0.9);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    color: #fff; font-size: .72rem; font-weight: 600; letter-spacing: .2px;
    padding: 5px 12px; border-radius: 22px;
    white-space: nowrap; pointer-events: none; opacity: 0;
    transition: opacity .18s, transform .18s;
}
.fzl-act.tip-left  .fzl-tip { right: calc(100% + 10px); transform: translateY(-50%) translateX(5px); }
.fzl-act.tip-right .fzl-tip { left:  calc(100% + 10px); transform: translateY(-50%) translateX(-5px); }
.fzl-act:hover      .fzl-tip { opacity: 1; }
.fzl-act.tip-left:hover  .fzl-tip { transform: translateY(-50%) translateX(0); }
.fzl-act.tip-right:hover .fzl-tip { transform: translateY(-50%) translateX(0); }
@media (max-width: 480px) {
    #fzl-fab-wrap { bottom: 14px; }
    .fzl-tip { display: none; }
}
</style>

<script>
(function () {
    const root = document.getElementById('fzl-fab-root');
    const wrap = document.getElementById('fzl-fab-wrap');
    const btn  = document.getElementById('fzl-fab-btn');
    const ico  = document.getElementById('fzl-fab-ico');
    const acts = Array.from(document.querySelectorAll('.fzl-act'));
    if (!root) return;

    const N = acts.length;

    // ── Arc configurations (rotate every 4 s) ─────────────────────
    const ARC_PHASES = [
        [-150, -30],   // default: top spread
        [-175, -55],   // tilted left
        [-125,  -5],   // tilted right
    ];
    let arcPhase = 0;

    function applyArc(startDeg, endDeg) {
        acts.forEach((el, i) => {
            const deg = N > 1 ? startDeg + (endDeg - startDeg) * i / (N - 1) : -90;
            const rad = deg * Math.PI / 180;
            const tx  = +(Math.cos(rad) * 108).toFixed(1);
            const ty  = +(Math.sin(rad) * 108).toFixed(1);
            el.style.setProperty('--tx', tx + 'px');
            el.style.setProperty('--ty', ty + 'px');
            el.classList.toggle('tip-left',  tx <= 0);
            el.classList.toggle('tip-right', tx >  0);
        });
    }
    applyArc(...ARC_PHASES[0]);

    setInterval(() => {
        if (!isOpen) return;
        arcPhase = (arcPhase + 1) % ARC_PHASES.length;
        applyArc(...ARC_PHASES[arcPhase]);
    }, 4000);

    // ── Open / Close ──────────────────────────────────────────────
    let isOpen = false;

    function morphIcon(toX) {
        ico.style.opacity   = '0';
        ico.style.transform = 'scale(0.3) rotate(' + (toX ? 110 : -110) + 'deg)';
        setTimeout(() => {
            ico.className       = toX ? 'bi bi-x-lg' : 'bi bi-lightning-charge-fill';
            ico.style.transform = 'scale(0.3) rotate(' + (toX ? -110 : 110) + 'deg)';
            requestAnimationFrame(() => requestAnimationFrame(() => {
                ico.style.opacity   = '1';
                ico.style.transform = 'scale(1) rotate(0deg)';
            }));
        }, 125);
    }

    function openFab(silent) {
        isOpen = true;
        root.classList.remove('fab-closing');
        root.classList.add('fab-open');
        btn.setAttribute('aria-expanded', 'true');
        if (!silent) morphIcon(true);
    }

    function closeFab() {
        isOpen = false;
        root.classList.add('fab-closing');
        root.classList.remove('fab-open');
        btn.setAttribute('aria-expanded', 'false');
        morphIcon(false);
        setTimeout(() => root.classList.remove('fab-closing'), 520);
    }

    btn.addEventListener('click', () => isOpen ? closeFab() : openFab());
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && isOpen) closeFab(); });

    // Auto-open on load
    setTimeout(() => openFab(true), 500);

    // ── Rise + Magnetic ───────────────────────────────────────────
    const WRAP_BOT  = 28;
    const BTN_HALF  = 31;         // half of 62px button
    const RISE_PCT  = 0.30;       // rise 30% of viewport height
    const MAG_R     = 380;        // magnetic activation radius (px)
    const MAG_MAX   = 28;         // max magnetic displacement (px)
    const LERP      = 0.20;       // interpolation speed (higher = snappier)
    const RETURN_MS = 1600;       // ms before auto-return after cursor leaves

    let targetX = 0, targetY = 0;
    let curX    = 0, curY    = 0;
    let rafId   = null;
    let riseOn  = false;
    let riseTimer = null;

    function animFrame() {
        curX += (targetX - curX) * LERP;
        curY += (targetY - curY) * LERP;
        wrap.style.transform =
            'translate(calc(-50% + ' + curX.toFixed(2) + 'px),' + curY.toFixed(2) + 'px)';
        rafId = (Math.abs(targetX - curX) + Math.abs(targetY - curY)) > 0.04
            ? requestAnimationFrame(animFrame) : null;
    }
    function kick() { if (!rafId) rafId = requestAnimationFrame(animFrame); }

    function startReturn() {
        clearTimeout(riseTimer);
        riseTimer = setTimeout(() => {
            riseOn  = false;
            targetY = 0;
            kick();
        }, RETURN_MS);
    }

    document.addEventListener('mousemove', function (e) {
        const vh  = window.innerHeight;
        const vw  = window.innerWidth;

        // Current FAB centre (accounting for already-applied offset)
        const cx  = vw / 2 + curX;
        const cy  = vh - WRAP_BOT - BTN_HALF + curY;
        const dx  = e.clientX - cx;
        const dy  = e.clientY - cy;
        const dist = Math.sqrt(dx * dx + dy * dy);

        // Magnetic: pull toward cursor within MAG_R
        let magX = 0, magY = 0;
        if (dist < MAG_R && dist > 0) {
            const t = Math.pow(1 - dist / MAG_R, 1.8);
            magX = (dx / dist) * t * MAG_MAX;
            magY = (dy / dist) * t * MAG_MAX;
        }

        // Rise: cursor in bottom 35% of screen → float up 30%
        if (e.clientY > vh * 0.65) {
            clearTimeout(riseTimer);
            riseOn  = true;
            targetX = magX;
            targetY = -(vh * RISE_PCT) + magY;
        } else {
            targetX = magX;
            if (riseOn) {
                // Cursor left the rise zone — wait before returning
                startReturn();
                targetY = -(vh * RISE_PCT) + magY; // hold position until timer
            } else {
                targetY = magY;
            }
        }
        kick();
    });

    document.addEventListener('mouseleave', function () {
        targetX = 0;
        if (riseOn) startReturn();
        else { targetY = 0; kick(); }
    });
})();
</script>
