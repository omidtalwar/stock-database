<?php if (empty($_SESSION['user_id'])) return; ?>
<?php $_fabAdmin = function_exists('isAdmin') && isAdmin(); ?>
<!-- ── Global FAB (right-to-left parade) ── -->
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
/* ── Root: flex row, FAB at far right ── */
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

/* ── FAB button ── */
#fzl-fab-btn {
    flex-shrink: 0;
    pointer-events: auto;
    width: 46px; height: 46px;
    border-radius: 50%;
    border: none; cursor: pointer; color: #fff;
    font-size: 1.2rem;
    background: linear-gradient(135deg, #0067C0 0%, #4338CA 100%);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 18px rgba(0,103,192,0.5);
    outline: none;
    position: relative; z-index: 2;
    transition: background .25s, box-shadow .25s;
    animation: fabPulse 4s ease-in-out infinite 6s;
}
#fzl-fab-btn:hover {
    box-shadow: 0 6px 24px rgba(0,103,192,0.65);
    animation: none;
}
#fzl-fab-root.fab-pinned #fzl-fab-btn {
    background: linear-gradient(135deg, #C42B1C 0%, #7F1D1D 100%);
    box-shadow: 0 4px 18px rgba(196,43,28,0.5);
    animation: none;
}
@keyframes fabPulse {
    0%,100% { box-shadow: 0 4px 18px rgba(0,103,192,0.5), 0 0 0 0   rgba(0,103,192,0.35); }
    55%     { box-shadow: 0 4px 18px rgba(0,103,192,0.5), 0 0 0 13px rgba(0,103,192,0); }
}
#fzl-fab-ico {
    display: block; line-height: 1;
    transition: transform .2s ease, opacity .15s ease;
}

/* ── Action buttons ── */
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

/* ── Tooltip (above each button) ── */
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
    // acts[0] = leftmost (Dashboard), acts[last] = rightmost closest to FAB (Customers)
    const acts = Array.from(document.querySelectorAll('.fzl-act'));
    if (!root || !btn || !acts.length) return;

    // ── Config ────────────────────────────────────────────────────
    const ENTER_MS  = 460;   // enter transition duration
    const EXIT_MS   = 280;   // exit transition duration
    const ENTER_GAP = 150;   // ms stagger between buttons (right→left entry)
    const EXIT_GAP  = 70;    // ms stagger between buttons (left→right exit)
    const STAY_MS   = 2600;  // ms all buttons stay visible
    const PAUSE_MS  = 700;   // ms gap before next parade cycle
    const BTN_STEP  = 46;    // px per button slot (38px + 8px gap)

    let paradeTimer = null;
    let isPinned    = false;

    // Pre-compute start position for each act (all buttons initially appear to come from FAB)
    // acts[i] is i slots from the right end (FAB). Distance = (acts.length - i) * BTN_STEP
    acts.forEach((act, i) => {
        const startX = (acts.length - i) * BTN_STEP + 16;
        act._startX  = startX;
        // Initial state: at FAB position, scaled down, invisible
        act.style.transition = 'none';
        act.style.transform  = `translateX(${startX}px) scale(0.55)`;
        act.style.opacity    = '0';
    });

    // ── Show / Hide helpers ───────────────────────────────────────
    function showAct(act) {
        act.style.transition = `transform ${ENTER_MS}ms cubic-bezier(0.34,1.58,0.64,1), opacity ${Math.round(ENTER_MS*0.55)}ms ease`;
        act.style.transform  = 'translateX(0) scale(1)';
        act.style.opacity    = '1';
        act.classList.add('fab-live');
    }

    function hideAct(act) {
        act.style.transition = `transform ${EXIT_MS}ms cubic-bezier(0.6,0,0.8,0.4), opacity ${Math.round(EXIT_MS*0.65)}ms ease`;
        act.style.transform  = `translateX(${act._startX}px) scale(0.55)`;
        act.style.opacity    = '0';
        act.classList.remove('fab-live');
    }

    // ── FAB launch bounce ─────────────────────────────────────────
    // Called when a button is dispatched; FAB squishes then springs
    function fabBounce() {
        btn.style.transition = 'transform 0.11s ease-in';
        btn.style.transform  = 'scale(0.82)';
        setTimeout(() => {
            btn.style.transition = 'transform 0.38s cubic-bezier(0.34,1.65,0.64,1)';
            btn.style.transform  = 'scale(1)';
        }, 110);
    }

    // ── Parade ────────────────────────────────────────────────────
    // Entry order: rightmost first (closest to FAB) → leftmost last
    // Exit  order: leftmost first → rightmost last
    function runParade() {
        if (isPinned) return;
        clearTimeout(paradeTimer);

        const reversed = acts.slice().reverse(); // reversed[0] = Customers (rightmost)

        // Entry: right → left
        reversed.forEach((act, ri) => {
            paradeTimer = setTimeout(() => {
                if (isPinned) return;
                showAct(act);
                fabBounce();
            }, ri * ENTER_GAP);
        });

        // Schedule exit after all entered + stay time
        const enterDone = reversed.length * ENTER_GAP + ENTER_MS + 60;
        paradeTimer = setTimeout(() => {
            if (isPinned) return;

            // Exit: left → right
            acts.forEach((act, i) => {
                setTimeout(() => {
                    if (!isPinned) hideAct(act);
                }, i * EXIT_GAP);
            });

            // Schedule next cycle
            const exitDone = acts.length * EXIT_GAP + EXIT_MS + 60;
            paradeTimer = setTimeout(() => {
                if (!isPinned) runParade();
            }, exitDone + PAUSE_MS);

        }, enterDone + STAY_MS);
    }

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

    // ── Pin open (user click: show all instantly, stop parade) ───
    function pinOpen() {
        isPinned = true;
        clearTimeout(paradeTimer);
        root.classList.add('fab-pinned');
        btn.setAttribute('aria-expanded', 'true');
        morphIcon(true);
        // Cascade in right→left
        acts.slice().reverse().forEach((act, ri) => {
            setTimeout(() => showAct(act), ri * 65);
        });
    }

    // ── Unpin (user click again: hide all, resume parade) ────────
    function unpin() {
        isPinned = false;
        root.classList.remove('fab-pinned');
        btn.setAttribute('aria-expanded', 'false');
        morphIcon(false);
        acts.forEach((act, i) => {
            setTimeout(() => hideAct(act), i * 45);
        });
        const done = acts.length * 45 + EXIT_MS + 60;
        paradeTimer = setTimeout(runParade, done + PAUSE_MS);
    }

    btn.addEventListener('click', () => isPinned ? unpin() : pinOpen());
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && isPinned) unpin(); });

    // Kick off parade on load
    setTimeout(runParade, 1000);
})();
</script>
