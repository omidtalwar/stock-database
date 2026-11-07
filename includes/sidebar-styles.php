<?php /* Shared dark sidebar styles — used by includes/header.php and dashboard.php. */ ?>
/* ── Sidebar ── */
.sidebar {
    width: var(--w11-sidebar-w);
    height: 100vh;
    position: fixed;
    top: 0;
    <?= isRTL() ? 'right' : 'left' ?>: 0;
    background: linear-gradient(165deg, #0F0E17 0%, #1B1047 55%, #0D1B3E 100%);
    <?= isRTL() ? 'border-left' : 'border-right' ?>: 1px solid rgba(255,255,255,0.07);
    display: flex;
    flex-direction: column;
    z-index: 300;
    transition: transform .25s ease;
    overflow-y: auto;
    overflow-x: hidden;
}
/* Animated rainbow top bar */
.sidebar::before {
    content: '';
    display: block;
    height: 3px;
    flex-shrink: 0;
    background: linear-gradient(90deg, #818CF8, #C084FC, #22D3EE, #4ADE80, #FB923C, #F87171, #818CF8);
    background-size: 300% 100%;
    animation: sidebarRainbow 5s linear infinite;
}
@keyframes sidebarRainbow {
    0%   { background-position: 0% 50%; }
    100% { background-position: 300% 50%; }
}

.sidebar-brand {
    padding: 14px 18px 14px;
    display: flex;
    align-items: center;
    gap: 11px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
}
.brand-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #818CF8 0%, #C084FC 100%);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 900; font-size: 0.95rem; letter-spacing: 1px;
    box-shadow: 0 2px 12px rgba(129,140,248,0.55);
    flex-shrink: 0;
}
.brand-name { font-weight: 700; font-size: 0.95rem; color: #fff; line-height: 1.2; }
.brand-sub  { font-size: 0.68rem; color: rgba(255,255,255,0.42); }

.sidebar-nav { flex: 1; padding: 8px 10px; }

.nav-section {
    font-size: 0.67rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: rgba(255,255,255,0.28);
    padding: 12px 10px 4px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: var(--w11-radius-sm);
    color: rgba(255,255,255,0.65);
    text-decoration: none;
    font-size: 0.875rem;
    transition: background .15s ease, color .15s ease;
    margin-bottom: 1px;
    position: relative;
}
.nav-item i {
    width: 20px; font-size: 1rem; flex-shrink: 0;
    color: var(--item-clr, rgba(255,255,255,0.38));
    transition: color .15s, transform .15s;
}
.nav-item:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.95); }
.nav-item:hover i { color: var(--item-clr, rgba(255,255,255,0.85)); transform: scale(1.12); }
.nav-item.active {
    background: rgba(255,255,255,0.08);
    color: #fff;
    font-weight: 600;
}
.nav-item.active::before {
    content: '';
    position: absolute;
    left: 0; top: 18%; bottom: 18%;
    width: 3px; border-radius: 0 2px 2px 0;
    background: var(--item-clr, #818CF8);
    box-shadow: 0 0 8px var(--item-clr, #818CF8);
}
[dir="rtl"] .nav-item.active::before { left: auto; right: 0; border-radius: 2px 0 0 2px; }
.nav-item.active i {
    color: var(--item-clr, #818CF8);
    filter: drop-shadow(0 0 5px var(--item-clr));
}

.sidebar-footer {
    padding: 12px 14px;
    border-top: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    background: rgba(0,0,0,0.18);
}
.s-avatar {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.8rem; color: #fff; flex-shrink: 0;
}
.s-avatar.admin     { background: linear-gradient(135deg,#F87171,#C42B1C); box-shadow: 0 2px 8px rgba(248,113,113,0.4); }
.s-avatar.assistant { background: linear-gradient(135deg,#60A5FA,#0067C0); box-shadow: 0 2px 8px rgba(96,165,250,0.4); }
.s-name { font-weight: 600; font-size: 0.8rem; line-height: 1.2; color: rgba(255,255,255,0.88); }
.s-role { font-size: 0.67rem; color: rgba(255,255,255,0.42); }
.s-logout {
    <?= isRTL() ? 'margin-right' : 'margin-left' ?>: auto;
    width: 28px; height: 28px; border-radius: 6px;
    background: transparent; border: none;
    color: rgba(255,255,255,0.35);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; text-decoration: none; font-size: 1rem;
    transition: background .15s, color .15s;
}
.s-logout:hover { background: rgba(248,113,113,0.15); color: #F87171; }
