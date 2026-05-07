<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FZL — Business Management System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════
   BASE & RESET
═══════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --blue:      #0067C0;
    --blue-d:    #003E92;
    --blue-l:    #EFF6FC;
    --blue-g:    linear-gradient(135deg, #0067C0 0%, #003E92 100%);
    --accent:    #7719AA;
    --green:     #107C10;
    --amber:     #9D5D00;
    --red:       #C42B1C;
    --bg:        #F3F3F3;
    --card:      #FFFFFF;
    --text:      #1C1C1C;
    --muted:     #605E5C;
    --border:    rgba(0,0,0,0.08);
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.07);
    --shadow-md: 0 8px 32px rgba(0,0,0,0.10);
    --shadow-lg: 0 20px 60px rgba(0,0,0,0.13);
    --r-sm: 8px;
    --r:    12px;
    --r-lg: 18px;
    --r-xl: 24px;
}
html { scroll-behavior: smooth; }
body {
    font-family: 'Segoe UI Variable', 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 15px;
    overflow-x: hidden;
}
a { text-decoration: none; }

/* ═══════════════════════════════════════════════
   NAVBAR
═══════════════════════════════════════════════ */
.navbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
    height: 56px;
    background: rgba(243,243,243,0.82);
    backdrop-filter: blur(24px) saturate(180%);
    -webkit-backdrop-filter: blur(24px) saturate(180%);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center;
    padding: 0 32px; gap: 14px;
    transition: box-shadow .3s;
}
.navbar.scrolled { box-shadow: var(--shadow-md); }
.nav-brand {
    display: flex; align-items: center; gap: 10px;
    font-weight: 800; font-size: 1.05rem; color: var(--text);
}
.nav-logo {
    width: 34px; height: 34px;
    background: var(--blue-g);
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 900; font-size: 0.82rem;
    box-shadow: 0 2px 10px rgba(0,103,192,0.4);
}
.nav-links { display: flex; gap: 6px; margin-left: auto; }
.nav-link {
    padding: 6px 14px; border-radius: var(--r-sm);
    font-size: 0.85rem; font-weight: 500; color: var(--muted);
    transition: background .15s, color .15s;
}
.nav-link:hover { background: rgba(0,0,0,0.05); color: var(--text); }
.nav-cta {
    padding: 7px 20px; border-radius: var(--r-sm);
    background: var(--blue); color: #fff !important;
    font-size: 0.85rem; font-weight: 600;
    box-shadow: 0 2px 8px rgba(0,103,192,0.3);
    transition: background .15s, transform .15s, box-shadow .15s;
}
.nav-cta:hover {
    background: var(--blue-d) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(0,103,192,0.35);
}

/* ═══════════════════════════════════════════════
   HERO
═══════════════════════════════════════════════ */
.hero {
    min-height: 100vh;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 120px 32px 80px;
    position: relative;
    overflow: hidden;
    text-align: center;
}

/* Animated mesh gradient background */
.hero-bg {
    position: absolute; inset: 0; z-index: 0;
    background:
        radial-gradient(ellipse 80% 60% at 15% 10%, rgba(0,103,192,0.12) 0%, transparent 60%),
        radial-gradient(ellipse 60% 70% at 85% 15%, rgba(119,25,170,0.09) 0%, transparent 60%),
        radial-gradient(ellipse 70% 50% at 50% 90%, rgba(16,124,16,0.06) 0%, transparent 60%),
        #F3F3F3;
    animation: meshShift 12s ease-in-out infinite alternate;
}
@keyframes meshShift {
    0%   { filter: hue-rotate(0deg) brightness(1); }
    100% { filter: hue-rotate(8deg) brightness(1.02); }
}

/* Floating orbs */
.orb {
    position: absolute; border-radius: 50%; pointer-events: none; z-index: 0;
    animation: float linear infinite;
}
.orb-1 {
    width: 500px; height: 500px;
    background: radial-gradient(circle, rgba(0,103,192,0.07) 0%, transparent 70%);
    top: -150px; left: -100px;
    animation-duration: 18s; animation-delay: 0s;
}
.orb-2 {
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(119,25,170,0.06) 0%, transparent 70%);
    top: 20%; right: -80px;
    animation-duration: 14s; animation-delay: -5s;
}
.orb-3 {
    width: 320px; height: 320px;
    background: radial-gradient(circle, rgba(16,124,16,0.05) 0%, transparent 70%);
    bottom: 10%; left: 20%;
    animation-duration: 20s; animation-delay: -9s;
}
@keyframes float {
    0%, 100% { transform: translateY(0px) scale(1); }
    33%       { transform: translateY(-30px) scale(1.04); }
    66%       { transform: translateY(20px) scale(0.97); }
}

.hero-content { position: relative; z-index: 1; max-width: 780px; }

.hero-badge {
    display: inline-flex; align-items: center; gap: 7px;
    background: rgba(0,103,192,0.09);
    border: 1px solid rgba(0,103,192,0.2);
    border-radius: 100px; padding: 5px 14px;
    font-size: 0.78rem; font-weight: 600; color: var(--blue);
    margin-bottom: 24px;
    animation: badgePop .6s cubic-bezier(.34,1.56,.64,1) .2s both;
}
@keyframes badgePop {
    from { opacity:0; transform: scale(0.7) translateY(10px); }
    to   { opacity:1; transform: scale(1) translateY(0); }
}

.hero-title {
    font-size: clamp(2.4rem, 5vw, 3.8rem);
    font-weight: 800;
    line-height: 1.12;
    letter-spacing: -0.02em;
    color: var(--text);
    margin-bottom: 20px;
    animation: slideUp .7s cubic-bezier(.16,1,.3,1) .35s both;
}
.hero-title .gradient-word {
    background: linear-gradient(135deg, #0067C0, #7719AA);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
}
@keyframes slideUp {
    from { opacity:0; transform: translateY(28px); }
    to   { opacity:1; transform: translateY(0); }
}

.hero-sub {
    font-size: 1.08rem; color: var(--muted); line-height: 1.65;
    max-width: 580px; margin: 0 auto 36px;
    animation: slideUp .7s cubic-bezier(.16,1,.3,1) .48s both;
}

.hero-actions {
    display: flex; align-items: center; justify-content: center;
    gap: 12px; flex-wrap: wrap;
    animation: slideUp .7s cubic-bezier(.16,1,.3,1) .58s both;
}
.btn-hero-primary {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 13px 32px; border-radius: var(--r);
    background: var(--blue-g); color: #fff;
    font-size: 0.95rem; font-weight: 700;
    box-shadow: 0 4px 18px rgba(0,103,192,0.38);
    transition: transform .2s, box-shadow .2s;
}
.btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,103,192,0.45); color: #fff; }
.btn-hero-secondary {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 28px; border-radius: var(--r);
    background: rgba(255,255,255,0.85); border: 1px solid var(--border);
    color: var(--text); font-size: 0.95rem; font-weight: 600;
    backdrop-filter: blur(8px);
    transition: transform .2s, box-shadow .2s, background .2s;
}
.btn-hero-secondary:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); background: #fff; }

/* ─ Hero stats strip ─ */
.hero-stats {
    display: flex; gap: 32px; justify-content: center; flex-wrap: wrap;
    margin-top: 56px; position: relative; z-index: 1;
    animation: slideUp .7s cubic-bezier(.16,1,.3,1) .7s both;
}
.hero-stat { text-align: center; }
.hero-stat-num {
    font-size: 1.7rem; font-weight: 800;
    background: linear-gradient(135deg, #0067C0, #7719AA);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
    line-height: 1;
}
.hero-stat-label { font-size: 0.75rem; color: var(--muted); margin-top: 3px; font-weight: 500; }
.hero-stat-divider { width: 1px; background: var(--border); }

/* ─ Hero mockup ─ */
.hero-mockup {
    position: relative; z-index: 1;
    margin-top: 60px;
    animation: mockupRise .9s cubic-bezier(.16,1,.3,1) .8s both;
    perspective: 1200px;
}
@keyframes mockupRise {
    from { opacity:0; transform: translateY(50px) rotateX(8deg); }
    to   { opacity:1; transform: translateY(0) rotateX(0deg); }
}
.mockup-frame {
    background: white;
    border-radius: var(--r-xl);
    box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
    overflow: hidden;
    max-width: 860px;
    margin: 0 auto;
}
.mockup-bar {
    background: rgba(243,243,243,0.95);
    border-bottom: 1px solid var(--border);
    padding: 10px 16px;
    display: flex; align-items: center; gap: 8px;
}
.mockup-dot { width: 10px; height: 10px; border-radius: 50%; }
.mockup-dot.red   { background: #ff5f56; }
.mockup-dot.amber { background: #ffbd2e; }
.mockup-dot.green { background: #27c93f; }
.mockup-url {
    flex: 1; background: rgba(0,0,0,0.05); border-radius: 6px;
    padding: 4px 12px; font-size: 0.72rem; color: var(--muted);
    font-family: monospace; max-width: 300px; margin: 0 auto;
}
.mockup-body {
    display: grid; grid-template-columns: 220px 1fr;
    min-height: 380px;
}
.mockup-sidebar {
    background: rgba(243,243,243,0.7);
    border-right: 1px solid var(--border);
    padding: 16px 10px;
}
.mock-brand {
    display: flex; align-items: center; gap: 8px; padding: 0 6px 12px;
    border-bottom: 1px solid var(--border); margin-bottom: 10px;
}
.mock-logo {
    width: 28px; height: 28px; border-radius: 7px;
    background: var(--blue-g); display: flex; align-items: center;
    justify-content: center; color: #fff; font-size: 0.65rem; font-weight: 900;
}
.mock-brand-text { font-size: 0.75rem; font-weight: 700; }
.mock-nav-section { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); padding: 8px 8px 3px; }
.mock-nav-item {
    display: flex; align-items: center; gap: 7px;
    padding: 6px 8px; border-radius: 6px; font-size: 0.72rem; color: var(--muted);
    margin-bottom: 1px;
}
.mock-nav-item.active { background: rgba(0,103,192,0.1); color: var(--blue); font-weight: 600; }
.mock-nav-item i { font-size: 0.8rem; }

.mockup-content { padding: 20px; background: var(--bg); }
.mock-stat-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; margin-bottom: 16px; }
.mock-stat {
    background: white; border-radius: 10px; padding: 12px 14px;
    border: 1px solid var(--border); box-shadow: var(--shadow-sm);
}
.mock-stat-label { font-size: 0.6rem; color: var(--muted); font-weight: 600; text-transform: uppercase; margin-bottom: 4px; }
.mock-stat-val { font-size: 1rem; font-weight: 800; }
.mock-stat-val.blue { color: var(--blue); }
.mock-stat-val.green { color: var(--green); }
.mock-stat-val.amber { color: var(--amber); }
.mock-stat-val.red { color: var(--red); }
.mock-stat-sub { font-size: 0.58rem; color: var(--muted); margin-top: 2px; }

.mock-table-card { background: white; border-radius: 10px; border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow-sm); }
.mock-table-header { padding: 10px 14px; font-size: 0.72rem; font-weight: 700; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.mock-badge { background: var(--blue); color: white; font-size: 0.58rem; padding: 2px 8px; border-radius: 4px; font-weight: 600; }
.mock-row { display: flex; padding: 8px 14px; font-size: 0.67rem; color: var(--muted); border-bottom: 1px solid rgba(0,0,0,0.03); align-items: center; gap: 8px; }
.mock-row:last-child { border-bottom: none; }
.mock-row-name { font-weight: 600; color: var(--text); flex: 1; font-size: 0.7rem; }
.mock-pill { padding: 2px 8px; border-radius: 4px; font-size: 0.6rem; font-weight: 600; }
.mock-pill.success { background: rgba(16,124,16,0.1); color: var(--green); }
.mock-pill.danger  { background: rgba(196,43,28,0.1); color: var(--red); }
.mock-pill.warning { background: rgba(157,93,0,0.1); color: var(--amber); }

/* ═══════════════════════════════════════════════
   SECTION BASE
═══════════════════════════════════════════════ */
section { padding: 96px 32px; }
.section-label {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--blue-l); border: 1px solid rgba(0,103,192,0.2);
    border-radius: 100px; padding: 4px 14px;
    font-size: 0.75rem; font-weight: 700; color: var(--blue);
    margin-bottom: 16px;
}
.section-title {
    font-size: clamp(1.8rem, 3.5vw, 2.6rem); font-weight: 800;
    line-height: 1.18; letter-spacing: -0.02em; margin-bottom: 14px;
}
.section-sub { font-size: 1rem; color: var(--muted); line-height: 1.65; max-width: 560px; }
.section-sub.center { margin: 0 auto; text-align: center; }
.text-center { text-align: center; }

/* ═══════════════════════════════════════════════
   FEATURES GRID
═══════════════════════════════════════════════ */
.features-section { background: white; }
.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px; margin-top: 52px;
    max-width: 1100px; margin-left: auto; margin-right: auto;
}
.feature-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    padding: 28px 26px;
    transition: transform .25s, box-shadow .25s, border-color .25s;
    cursor: default;
    position: relative; overflow: hidden;
}
.feature-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    border-radius: var(--r-lg) var(--r-lg) 0 0;
    opacity: 0;
    transition: opacity .25s;
}
.feature-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-md); border-color: rgba(0,103,192,0.15); }
.feature-card:hover::before { opacity: 1; }

.fc-blue::before   { background: linear-gradient(90deg, #0067C0, #4DA6FF); }
.fc-purple::before { background: linear-gradient(90deg, #7719AA, #C084FC); }
.fc-green::before  { background: linear-gradient(90deg, #107C10, #4ADE80); }
.fc-amber::before  { background: linear-gradient(90deg, #9D5D00, #FCD34D); }
.fc-red::before    { background: linear-gradient(90deg, #C42B1C, #FCA5A5); }
.fc-teal::before   { background: linear-gradient(90deg, #0D7A5F, #5EEAD4); }
.fc-indigo::before { background: linear-gradient(90deg, #3730A3, #A5B4FC); }
.fc-rose::before   { background: linear-gradient(90deg, #9D174D, #FECDD3); }

.feature-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; margin-bottom: 16px;
}
.fi-blue   { background: rgba(0,103,192,0.1);  color: var(--blue); }
.fi-purple { background: rgba(119,25,170,0.1); color: #7719AA; }
.fi-green  { background: rgba(16,124,16,0.1);  color: var(--green); }
.fi-amber  { background: rgba(157,93,0,0.1);   color: var(--amber); }
.fi-red    { background: rgba(196,43,28,0.1);  color: var(--red); }
.fi-teal   { background: rgba(13,122,95,0.1);  color: #0D7A5F; }
.fi-indigo { background: rgba(55,48,163,0.1);  color: #3730A3; }
.fi-rose   { background: rgba(157,23,77,0.1);  color: #9D174D; }

.feature-title { font-size: 0.95rem; font-weight: 700; margin-bottom: 6px; }
.feature-desc  { font-size: 0.82rem; color: var(--muted); line-height: 1.6; }
.feature-pills { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 14px; }
.fpill {
    font-size: 0.68rem; font-weight: 600; padding: 2px 9px; border-radius: 4px;
    background: rgba(0,0,0,0.05); color: var(--muted);
}

/* ═══════════════════════════════════════════════
   SHOWCASE SECTION
═══════════════════════════════════════════════ */
.showcase-section { background: var(--bg); }
.showcase-inner { max-width: 1100px; margin: 0 auto; }
.showcase-grid { display: flex; flex-direction: column; gap: 72px; margin-top: 56px; }

.showcase-item {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 52px; align-items: center;
}
.showcase-item.reverse { direction: rtl; }
.showcase-item.reverse > * { direction: ltr; }

.showcase-text .tag {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.72rem; font-weight: 700; color: var(--blue);
    text-transform: uppercase; letter-spacing: .8px;
    margin-bottom: 12px;
}
.showcase-title { font-size: 1.6rem; font-weight: 800; margin-bottom: 12px; line-height: 1.25; }
.showcase-desc  { font-size: 0.9rem; color: var(--muted); line-height: 1.7; margin-bottom: 18px; }
.showcase-points { list-style: none; display: flex; flex-direction: column; gap: 8px; }
.showcase-points li {
    display: flex; align-items: flex-start; gap: 9px;
    font-size: 0.85rem; color: var(--text);
}
.showcase-points li i { color: var(--green); margin-top: 2px; flex-shrink: 0; font-size: 0.9rem; }

/* ─ UI Mockup cards ─ */
.mockup-card {
    background: white;
    border-radius: var(--r-xl);
    box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
    overflow: hidden;
    position: relative;
    transition: transform .3s;
}
.mockup-card:hover { transform: translateY(-4px) rotate(0.3deg); }
.mc-header {
    padding: 12px 16px;
    display: flex; align-items: center; gap: 10px;
    border-bottom: 1px solid var(--border);
}
.mc-header-icon {
    width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; color: #fff;
}
.mc-header-title { font-size: 0.8rem; font-weight: 700; }
.mc-header-sub   { font-size: 0.65rem; color: var(--muted); }

/* Invoice mockup */
.inv-mock { padding: 16px; }
.inv-mock-header {
    background: linear-gradient(135deg, #1563a8, #0a3d6e);
    border-radius: 10px; padding: 12px 14px; margin-bottom: 10px;
}
.inv-mock-shop { color: #ff4040; font-weight: 800; font-size: 0.85rem; }
.inv-mock-en   { color: rgba(255,255,255,0.85); font-size: 0.65rem; font-family: Arial; }
.inv-mock-meta { display: flex; justify-content: space-between; font-size: 0.62rem; color: var(--muted); background: #f5f5f5; padding: 5px 8px; border-radius: 6px; margin-bottom: 8px; }
.inv-mock-table { width: 100%; border-collapse: collapse; font-size: 0.65rem; }
.inv-mock-table th { background: #c82000; color: white; padding: 4px 6px; text-align: center; }
.inv-mock-table td { padding: 4px 6px; text-align: center; border: 1px solid #e8e8e8; }
.inv-mock-table tr:nth-child(even) td { background: #f5c8a0; }

/* Customer mockup */
.cust-rows { padding: 8px; display: flex; flex-direction: column; gap: 3px; }
.cust-row {
    display: flex; align-items: center; gap: 10px; padding: 8px 10px;
    border-radius: 8px; transition: background .15s;
}
.cust-row:hover { background: rgba(0,103,192,0.04); }
.cust-avatar {
    width: 28px; height: 28px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.65rem; font-weight: 800; color: #fff; flex-shrink: 0;
}
.cust-name { font-size: 0.72rem; font-weight: 600; flex: 1; }
.cust-shop { font-size: 0.6rem; color: var(--muted); }
.cust-debt { font-size: 0.72rem; font-weight: 700; color: var(--red); }

/* Stock mockup */
.stock-bars { padding: 14px 16px; }
.stock-bar-item { margin-bottom: 12px; }
.stock-bar-label { display: flex; justify-content: space-between; font-size: 0.67rem; color: var(--muted); margin-bottom: 4px; }
.stock-bar-label span:last-child { font-weight: 700; color: var(--text); }
.stock-bar-track { height: 8px; background: rgba(0,0,0,0.06); border-radius: 100px; overflow: hidden; }
.stock-bar-fill  { height: 100%; border-radius: 100px; }

/* ═══════════════════════════════════════════════
   DUAL CURRENCY
═══════════════════════════════════════════════ */
.currency-section { background: white; }
.currency-inner { max-width: 1000px; margin: 0 auto; }
.currency-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 40px; margin-top: 52px; align-items: center;
}
.currency-visual {
    background: linear-gradient(135deg, #0067C0 0%, #003E92 60%, #7719AA 100%);
    border-radius: var(--r-xl);
    padding: 36px 32px;
    color: white; position: relative; overflow: hidden;
}
.currency-visual::before {
    content: '';
    position: absolute; top: -60px; right: -60px;
    width: 200px; height: 200px; border-radius: 50%;
    background: rgba(255,255,255,0.06);
}
.curr-rate { font-size: 0.75rem; opacity: 0.75; margin-bottom: 4px; }
.curr-display { font-size: 2.2rem; font-weight: 800; margin-bottom: 18px; }
.curr-row {
    display: flex; justify-content: space-between; align-items: center;
    background: rgba(255,255,255,0.1); border-radius: 10px;
    padding: 10px 14px; margin-bottom: 8px;
}
.curr-row-label { font-size: 0.78rem; opacity: 0.8; }
.curr-row-val   { font-size: 0.9rem; font-weight: 700; }
.curr-row-val.afn   { color: #7dd3fc; }
.curr-row-val.usd   { color: #86efac; }

.currency-points { display: flex; flex-direction: column; gap: 20px; }
.curr-point { display: flex; gap: 14px; align-items: flex-start; }
.curr-point-icon {
    width: 40px; height: 40px; border-radius: 10px;
    background: var(--blue-l); display: flex; align-items: center;
    justify-content: center; font-size: 1rem; color: var(--blue); flex-shrink: 0;
}
.curr-point-title { font-size: 0.9rem; font-weight: 700; margin-bottom: 3px; }
.curr-point-desc  { font-size: 0.82rem; color: var(--muted); line-height: 1.5; }

/* ═══════════════════════════════════════════════
   BILINGUAL STRIP
═══════════════════════════════════════════════ */
.bilingual-section {
    background: linear-gradient(135deg, #0067C0 0%, #003E92 50%, #7719AA 100%);
    padding: 64px 32px;
    color: white; text-align: center; overflow: hidden; position: relative;
}
.bilingual-section::before {
    content: '';
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.bi-title { font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 800; margin-bottom: 10px; position: relative; }
.bi-sub { font-size: 1rem; opacity: 0.82; margin-bottom: 36px; position: relative; }
.bi-cards { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; position: relative; }
.bi-card {
    background: rgba(255,255,255,0.12);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: var(--r-lg); padding: 20px 28px;
    min-width: 200px; transition: transform .2s, background .2s;
}
.bi-card:hover { transform: translateY(-4px); background: rgba(255,255,255,0.18); }
.bi-card-flag { font-size: 1.6rem; margin-bottom: 6px; }
.bi-card-lang { font-size: 1rem; font-weight: 700; }
.bi-card-dir  { font-size: 0.75rem; opacity: 0.75; margin-top: 2px; }
.bi-card-sample { font-size: 0.8rem; opacity: 0.85; margin-top: 8px; font-style: italic; }

/* ═══════════════════════════════════════════════
   STATS
═══════════════════════════════════════════════ */
.stats-section { background: var(--bg); }
.stats-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px; max-width: 900px; margin: 48px auto 0;
}
.stat-card {
    background: white; border: 1px solid var(--border); border-radius: var(--r-lg);
    padding: 28px 24px; text-align: center;
    box-shadow: var(--shadow-sm); transition: transform .2s, box-shadow .2s;
}
.stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
.stat-icon {
    width: 48px; height: 48px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; margin: 0 auto 12px;
}
.stat-num {
    font-size: 2rem; font-weight: 800; line-height: 1;
    background: linear-gradient(135deg, #0067C0, #7719AA);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    margin-bottom: 4px;
}
.stat-label { font-size: 0.8rem; color: var(--muted); font-weight: 500; }

/* ═══════════════════════════════════════════════
   PRINT SECTION
═══════════════════════════════════════════════ */
.print-section { background: white; }
.print-inner { max-width: 1000px; margin: 0 auto; }
.print-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 52px; margin-top: 52px; align-items: center; }
.print-mockup-wrap { position: relative; }
.print-mockup-wrap::before {
    content: '';
    position: absolute; inset: -20px;
    background: linear-gradient(135deg, rgba(0,103,192,0.07), rgba(119,25,170,0.05));
    border-radius: 28px; z-index: 0;
}
.print-invoice-mock {
    position: relative; z-index: 1;
    background: white; border-radius: 12px;
    box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
    overflow: hidden; font-family: Arial, sans-serif;
}
.pim-header {
    background: linear-gradient(135deg, #1563a8, #0a3d6e);
    padding: 12px 14px; display: flex; justify-content: space-between; align-items: flex-start;
}
.pim-shop-dari { color: #ff4040; font-weight: 800; font-size: 1rem; direction: rtl; }
.pim-shop-en   { color: rgba(255,255,255,0.85); font-size: 0.62rem; font-family: Arial; }
.pim-contact   { color: rgba(255,255,255,0.85); font-size: 0.6rem; text-align: right; }
.pim-phones    { color: white; font-weight: 700; font-size: 0.7rem; }
.pim-meta { display: flex; justify-content: space-between; background: #f5f5f5; padding: 6px 10px; font-size: 0.62rem; color: #555; border-bottom: 1px solid #eee; direction: rtl; }
.pim-table { width: 100%; border-collapse: collapse; font-size: 0.62rem; direction: rtl; }
.pim-table th { background: #c82000; color: white; padding: 5px 7px; text-align: center; border: 1px solid #a81a00; }
.pim-table td { padding: 5px 7px; text-align: center; border: 1px solid #e0e0e0; height: 22px; }
.pim-table tr:nth-child(even) td { background: #f5c8a0; }
.pim-footer { display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; border-top: 1px solid #eee; }
.pim-sig  { font-size: 0.62rem; color: #555; }
.pim-note { background: #c82000; color: white; font-size: 0.6rem; padding: 4px 12px; border-radius: 20px; direction: rtl; }

/* ═══════════════════════════════════════════════
   CTA
═══════════════════════════════════════════════ */
.cta-section {
    padding: 96px 32px;
    background: linear-gradient(135deg, #0067C0 0%, #003E92 50%, #7719AA 100%);
    text-align: center; color: white; position: relative; overflow: hidden;
}
.cta-section::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse 80% 80% at 50% 0%, rgba(255,255,255,0.08) 0%, transparent 70%);
}
.cta-title { font-size: clamp(1.8rem, 3.5vw, 2.8rem); font-weight: 800; margin-bottom: 14px; position: relative; }
.cta-sub   { font-size: 1rem; opacity: 0.82; margin-bottom: 36px; position: relative; }
.cta-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; position: relative; }
.btn-cta-primary {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 13px 32px; border-radius: var(--r);
    background: white; color: var(--blue);
    font-size: 0.95rem; font-weight: 700;
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
    transition: transform .2s, box-shadow .2s;
}
.btn-cta-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,0,0,0.25); }
.btn-cta-secondary {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 28px; border-radius: var(--r);
    background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);
    color: white; font-size: 0.95rem; font-weight: 600;
    backdrop-filter: blur(8px);
    transition: transform .2s, background .2s;
}
.btn-cta-secondary:hover { transform: translateY(-2px); background: rgba(255,255,255,0.22); }

/* ═══════════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════════ */
.footer {
    background: var(--text);
    color: rgba(255,255,255,0.65);
    padding: 36px 32px;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.footer-brand { display: flex; align-items: center; gap: 10px; }
.footer-logo {
    width: 30px; height: 30px; border-radius: 8px;
    background: var(--blue-g); display: flex; align-items: center;
    justify-content: center; color: white; font-weight: 900; font-size: 0.7rem;
}
.footer-name { font-size: 0.85rem; font-weight: 700; color: white; }
.footer-copy { font-size: 0.75rem; }
.footer-links { display: flex; gap: 20px; }
.footer-links a { font-size: 0.78rem; color: rgba(255,255,255,0.55); transition: color .15s; }
.footer-links a:hover { color: white; }

/* ═══════════════════════════════════════════════
   SCROLL ANIMATIONS
═══════════════════════════════════════════════ */
.reveal {
    opacity: 0; transform: translateY(32px);
    transition: opacity .65s cubic-bezier(.16,1,.3,1), transform .65s cubic-bezier(.16,1,.3,1);
}
.reveal.visible { opacity: 1; transform: translateY(0); }
.reveal-delay-1 { transition-delay: .1s; }
.reveal-delay-2 { transition-delay: .2s; }
.reveal-delay-3 { transition-delay: .3s; }
.reveal-delay-4 { transition-delay: .4s; }

/* ═══════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════ */
@media (max-width: 768px) {
    section { padding: 64px 20px; }
    .navbar { padding: 0 16px; }
    .hero { padding: 100px 20px 60px; }
    .nav-links .nav-link { display: none; }
    .mockup-body { grid-template-columns: 1fr; }
    .mockup-sidebar { display: none; }
    .showcase-item, .showcase-item.reverse { grid-template-columns: 1fr; direction: ltr; }
    .currency-grid, .print-grid { grid-template-columns: 1fr; }
    .hero-stat-divider { display: none; }
    .footer { flex-direction: column; text-align: center; }
    .footer-links { justify-content: center; }
}
</style>
</head>
<body>

<!-- ══════════ NAVBAR ══════════ -->
<nav class="navbar" id="navbar">
    <div class="nav-brand">
        <div class="nav-logo">FZL</div>
        FZL System
    </div>
    <div class="nav-links">
        <a href="#features" class="nav-link">Features</a>
        <a href="#showcase" class="nav-link">Showcase</a>
        <a href="#print" class="nav-link">Print Invoice</a>
        <a href="/fzl/auth/login.php" class="nav-link nav-cta">
            <i class="bi bi-box-arrow-in-right"></i> Sign In
        </a>
    </div>
</nav>

<!-- ══════════ HERO ══════════ -->
<section class="hero">
    <div class="hero-bg"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <div class="hero-content">
        <div class="hero-badge">
            <i class="bi bi-stars"></i> Business Management System
        </div>
        <h1 class="hero-title">
            Manage Your Cloth Business<br>
            <span class="gradient-word">The Smart Way</span>
        </h1>
        <p class="hero-sub">
            FZL brings customers, invoices, payments, stock, and reports into one powerful platform — built for Afghan cloth wholesalers with full Pashto RTL support.
        </p>
        <div class="hero-actions">
            <a href="/fzl/auth/login.php" class="btn-hero-primary">
                <i class="bi bi-box-arrow-in-right"></i> Open Dashboard
            </a>
            <a href="#showcase" class="btn-hero-secondary">
                <i class="bi bi-play-circle"></i> See How It Works
            </a>
        </div>
    </div>

    <div class="hero-stats">
        <div class="hero-stat">
            <div class="hero-stat-num" data-count="8">0</div>
            <div class="hero-stat-label">Modules</div>
        </div>
        <div class="hero-stat-divider"></div>
        <div class="hero-stat">
            <div class="hero-stat-num" data-count="2">0</div>
            <div class="hero-stat-label">Languages</div>
        </div>
        <div class="hero-stat-divider"></div>
        <div class="hero-stat">
            <div class="hero-stat-num" data-count="150">0</div>
            <div class="hero-stat-label">Translation Keys</div>
        </div>
        <div class="hero-stat-divider"></div>
        <div class="hero-stat">
            <div class="hero-stat-num" data-count="2">0</div>
            <div class="hero-stat-label">Currencies</div>
        </div>
    </div>

    <!-- App Mockup -->
    <div class="hero-mockup" style="width:100%;max-width:860px;">
        <div class="mockup-frame">
            <div class="mockup-bar">
                <div class="mockup-dot red"></div>
                <div class="mockup-dot amber"></div>
                <div class="mockup-dot green"></div>
                <div class="mockup-url">localhost/fzl/dashboard.php</div>
            </div>
            <div class="mockup-body">
                <div class="mockup-sidebar">
                    <div class="mock-brand">
                        <div class="mock-logo">FZL</div>
                        <div class="mock-brand-text">FZL System</div>
                    </div>
                    <div class="mock-nav-section">Main</div>
                    <div class="mock-nav-item active"><i class="bi bi-grid-1x2"></i> Dashboard</div>
                    <div class="mock-nav-section">Business</div>
                    <div class="mock-nav-item"><i class="bi bi-people"></i> Customers</div>
                    <div class="mock-nav-item"><i class="bi bi-box-seam"></i> Products</div>
                    <div class="mock-nav-item"><i class="bi bi-receipt"></i> Sales</div>
                    <div class="mock-nav-item"><i class="bi bi-cash-coin"></i> Payments</div>
                    <div class="mock-nav-item"><i class="bi bi-archive"></i> Stock Log</div>
                    <div class="mock-nav-section">Admin</div>
                    <div class="mock-nav-item"><i class="bi bi-bar-chart"></i> Reports</div>
                    <div class="mock-nav-item"><i class="bi bi-currency-exchange"></i> Rate</div>
                </div>
                <div class="mockup-content">
                    <div class="mock-stat-row">
                        <div class="mock-stat">
                            <div class="mock-stat-label">Today's Sales</div>
                            <div class="mock-stat-val blue">؋ 84,000</div>
                            <div class="mock-stat-sub">4 invoices today</div>
                        </div>
                        <div class="mock-stat">
                            <div class="mock-stat-label">Receivable</div>
                            <div class="mock-stat-val amber">؋ 312,500</div>
                            <div class="mock-stat-sub">Outstanding credit</div>
                        </div>
                        <div class="mock-stat">
                            <div class="mock-stat-label">Total Stock</div>
                            <div class="mock-stat-val green">2,840 pcs</div>
                            <div class="mock-stat-sub">12 product types</div>
                        </div>
                        <div class="mock-stat">
                            <div class="mock-stat-label">Customers</div>
                            <div class="mock-stat-val red">38</div>
                            <div class="mock-stat-sub">28 with balance</div>
                        </div>
                    </div>
                    <div class="mock-table-card">
                        <div class="mock-table-header">
                            Recent Invoices
                            <span class="mock-badge">New Invoice</span>
                        </div>
                        <div class="mock-row">
                            <div class="mock-row-name">Ahmad Shah — Sarai Shahzada</div>
                            <span class="mock-pill success">Paid</span>
                            <span>؋ 24,000</span>
                        </div>
                        <div class="mock-row">
                            <div class="mock-row-name">Noor Khan — Mandawi</div>
                            <span class="mock-pill danger">Debt ؋ 15,500</span>
                            <span>؋ 38,000</span>
                        </div>
                        <div class="mock-row">
                            <div class="mock-row-name">Gul Rahman — Pul-e-Khishti</div>
                            <span class="mock-pill warning">Partial</span>
                            <span>؋ 22,000</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════ FEATURES ══════════ -->
<section class="features-section" id="features">
    <div style="max-width:1100px;margin:0 auto;">
        <div class="text-center reveal">
            <div class="section-label"><i class="bi bi-grid-3x3-gap"></i> Everything You Need</div>
            <h2 class="section-title">8 Modules. One Platform.</h2>
            <p class="section-sub center">Every feature your cloth wholesale business needs — tightly integrated so data flows automatically between modules.</p>
        </div>

        <div class="features-grid">
            <div class="feature-card fc-blue reveal reveal-delay-1">
                <div class="feature-icon fi-blue"><i class="bi bi-people-fill"></i></div>
                <div class="feature-title">Customer Management</div>
                <div class="feature-desc">Track all shopkeepers, their credit history, outstanding debts, and full invoice and payment history in one place.</div>
                <div class="feature-pills">
                    <span class="fpill">Debt Tracking</span>
                    <span class="fpill">Invoice History</span>
                    <span class="fpill">Payment Log</span>
                </div>
            </div>
            <div class="feature-card fc-purple reveal reveal-delay-2">
                <div class="feature-icon fi-purple"><i class="bi bi-receipt"></i></div>
                <div class="feature-title">Sales & Invoicing</div>
                <div class="feature-desc">Create invoices in seconds. Add multiple products, record partial payment, and auto-update customer debt balance.</div>
                <div class="feature-pills">
                    <span class="fpill">Multi-Product</span>
                    <span class="fpill">Partial Payment</span>
                    <span class="fpill">Auto Debt</span>
                </div>
            </div>
            <div class="feature-card fc-green reveal reveal-delay-3">
                <div class="feature-icon fi-green"><i class="bi bi-cash-coin"></i></div>
                <div class="feature-title">Payment Collection</div>
                <div class="feature-desc">Record payments in AFN or USD. Automatic currency conversion reduces customer debt at the current exchange rate.</div>
                <div class="feature-pills">
                    <span class="fpill">AFN & USD</span>
                    <span class="fpill">Auto Convert</span>
                    <span class="fpill">Debt Reduce</span>
                </div>
            </div>
            <div class="feature-card fc-amber reveal reveal-delay-4">
                <div class="feature-icon fi-amber"><i class="bi bi-box-seam"></i></div>
                <div class="feature-title">Product Catalog</div>
                <div class="feature-desc">Manage your full product catalog with size, color, wholesale price, and real-time stock levels per item.</div>
                <div class="feature-pills">
                    <span class="fpill">Size & Color</span>
                    <span class="fpill">Live Stock</span>
                    <span class="fpill">Pricing</span>
                </div>
            </div>
            <div class="feature-card fc-teal reveal reveal-delay-1">
                <div class="feature-icon fi-teal"><i class="bi bi-archive"></i></div>
                <div class="feature-title">Stock Management</div>
                <div class="feature-desc">Full in/out stock log. Stock auto-decrements on sale and restores on delete. Manual adjustments supported.</div>
                <div class="feature-pills">
                    <span class="fpill">Stock In/Out</span>
                    <span class="fpill">Auto Log</span>
                    <span class="fpill">Movement History</span>
                </div>
            </div>
            <div class="feature-card fc-indigo reveal reveal-delay-2">
                <div class="feature-icon fi-indigo"><i class="bi bi-bar-chart-line"></i></div>
                <div class="feature-title">Reports & Analytics</div>
                <div class="feature-desc">Date-range reports show total sales, collected, pending, top customers, and best-selling products.</div>
                <div class="feature-pills">
                    <span class="fpill">Date Range</span>
                    <span class="fpill">Top Customers</span>
                    <span class="fpill">Top Products</span>
                </div>
            </div>
            <div class="feature-card fc-red reveal reveal-delay-3">
                <div class="feature-icon fi-red"><i class="bi bi-currency-exchange"></i></div>
                <div class="feature-title">Exchange Rate</div>
                <div class="feature-desc">Configurable AFN ↔ USD rate with full change history log. All dual-currency displays update instantly.</div>
                <div class="feature-pills">
                    <span class="fpill">Rate History</span>
                    <span class="fpill">Quick Converter</span>
                    <span class="fpill">Auto Display</span>
                </div>
            </div>
            <div class="feature-card fc-rose reveal reveal-delay-4">
                <div class="feature-icon fi-rose"><i class="bi bi-person-gear"></i></div>
                <div class="feature-title">User Management</div>
                <div class="feature-desc">Admin and Assistant roles. Admins can delete, view reports, and manage users. Assistants handle daily operations.</div>
                <div class="feature-pills">
                    <span class="fpill">Admin Role</span>
                    <span class="fpill">Assistant Role</span>
                    <span class="fpill">Permissions</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════ SHOWCASE ══════════ -->
<section class="showcase-section" id="showcase">
    <div class="showcase-inner">
        <div class="text-center reveal">
            <div class="section-label"><i class="bi bi-eye"></i> Module Showcase</div>
            <h2 class="section-title">See Each Module In Action</h2>
            <p class="section-sub center">Every part of FZL is purpose-built for how Afghan cloth wholesalers actually work.</p>
        </div>

        <div class="showcase-grid">

            <!-- Customers -->
            <div class="showcase-item reveal">
                <div class="showcase-text">
                    <div class="tag"><i class="bi bi-people"></i> Customers</div>
                    <h3 class="showcase-title">Know Every Shopkeeper's Balance Instantly</h3>
                    <p class="showcase-desc">Each customer has a running debt balance updated automatically with every sale and payment. No manual ledger needed.</p>
                    <ul class="showcase-points">
                        <li><i class="bi bi-check-circle-fill"></i> Full invoice and payment history per customer</li>
                        <li><i class="bi bi-check-circle-fill"></i> Debt shown in both ؋ AFN and secondary currency</li>
                        <li><i class="bi bi-check-circle-fill"></i> Quick-add payment directly from customer profile</li>
                        <li><i class="bi bi-check-circle-fill"></i> Search by name, shop, or phone number</li>
                    </ul>
                </div>
                <div class="mockup-card">
                    <div class="mc-header">
                        <div class="mc-header-icon" style="background:var(--blue-g)"><i class="bi bi-people-fill" style="color:white"></i></div>
                        <div><div class="mc-header-title">Customers</div><div class="mc-header-sub">38 registered · 28 with balance</div></div>
                    </div>
                    <div class="cust-rows">
                        <div class="cust-row">
                            <div class="cust-avatar" style="background:linear-gradient(135deg,#0067C0,#003E92)">A</div>
                            <div style="flex:1"><div class="cust-name">Ahmad Shah</div><div class="cust-shop">Sarai Shahzada</div></div>
                            <div class="cust-debt">؋ 84,000</div>
                        </div>
                        <div class="cust-row">
                            <div class="cust-avatar" style="background:linear-gradient(135deg,#7719AA,#4A0072)">N</div>
                            <div style="flex:1"><div class="cust-name">Noor Khan</div><div class="cust-shop">Mandawi Bazaar</div></div>
                            <div class="cust-debt">؋ 62,500</div>
                        </div>
                        <div class="cust-row">
                            <div class="cust-avatar" style="background:linear-gradient(135deg,#107C10,#054A05)">G</div>
                            <div style="flex:1"><div class="cust-name">Gul Rahman</div><div class="cust-shop">Pul-e-Khishti</div></div>
                            <div class="cust-debt">؋ 38,200</div>
                        </div>
                        <div class="cust-row">
                            <div class="cust-avatar" style="background:linear-gradient(135deg,#9D5D00,#5A3500)">H</div>
                            <div style="flex:1"><div class="cust-name">Haji Daoud</div><div class="cust-shop">Jad-e-Maiwand</div></div>
                            <div class="cust-debt" style="color:var(--green)">Cleared</div>
                        </div>
                        <div class="cust-row">
                            <div class="cust-avatar" style="background:linear-gradient(135deg,#C42B1C,#8B1E14)">S</div>
                            <div style="flex:1"><div class="cust-name">Sayed Zaman</div><div class="cust-shop">Kart-e-Parwan</div></div>
                            <div class="cust-debt">؋ 21,000</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock -->
            <div class="showcase-item reverse reveal">
                <div class="showcase-text">
                    <div class="tag"><i class="bi bi-archive"></i> Stock</div>
                    <h3 class="showcase-title">Real-Time Stock Levels, Always Accurate</h3>
                    <p class="showcase-desc">Every sale automatically deducts from stock. Add new shipments with stock-in entries. Full movement history is always logged.</p>
                    <ul class="showcase-points">
                        <li><i class="bi bi-check-circle-fill"></i> Auto-deduct on sale creation and restore on delete</li>
                        <li><i class="bi bi-check-circle-fill"></i> Manual stock-in and stock-out entries</li>
                        <li><i class="bi bi-check-circle-fill"></i> Full movement log with date and user</li>
                        <li><i class="bi bi-check-circle-fill"></i> Filter by product, type (in/out), or date</li>
                    </ul>
                </div>
                <div class="mockup-card">
                    <div class="mc-header">
                        <div class="mc-header-icon" style="background:linear-gradient(135deg,#0D7A5F,#054A35)"><i class="bi bi-archive-fill" style="color:white"></i></div>
                        <div><div class="mc-header-title">Stock Levels</div><div class="mc-header-sub">2,840 pcs total warehouse</div></div>
                    </div>
                    <div class="stock-bars">
                        <div class="stock-bar-item">
                            <div class="stock-bar-label"><span>Kameez Partog — Blue (L)</span><span>840 pcs</span></div>
                            <div class="stock-bar-track"><div class="stock-bar-fill" style="width:84%;background:linear-gradient(90deg,#0067C0,#4DA6FF)"></div></div>
                        </div>
                        <div class="stock-bar-item">
                            <div class="stock-bar-label"><span>Chapan — Brown (XL)</span><span>560 pcs</span></div>
                            <div class="stock-bar-track"><div class="stock-bar-fill" style="width:56%;background:linear-gradient(90deg,#9D5D00,#FCD34D)"></div></div>
                        </div>
                        <div class="stock-bar-item">
                            <div class="stock-bar-label"><span>Kameez — White (M)</span><span>320 pcs</span></div>
                            <div class="stock-bar-track"><div class="stock-bar-fill" style="width:32%;background:linear-gradient(90deg,#107C10,#4ADE80)"></div></div>
                        </div>
                        <div class="stock-bar-item">
                            <div class="stock-bar-label"><span>Lungee — Red</span><span>180 pcs</span></div>
                            <div class="stock-bar-track"><div class="stock-bar-fill" style="width:18%;background:linear-gradient(90deg,#C42B1C,#FCA5A5)"></div></div>
                        </div>
                        <div class="stock-bar-item">
                            <div class="stock-bar-label"><span>Shalwar — Gray (S)</span><span>940 pcs</span></div>
                            <div class="stock-bar-track"><div class="stock-bar-fill" style="width:94%;background:linear-gradient(90deg,#7719AA,#C084FC)"></div></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ══════════ DUAL CURRENCY ══════════ -->
<section class="currency-section" id="currency">
    <div class="currency-inner">
        <div class="text-center reveal">
            <div class="section-label"><i class="bi bi-currency-exchange"></i> Dual Currency</div>
            <h2 class="section-title">؋ AFN Primary. USD Secondary.</h2>
            <p class="section-sub center">All prices and debts are stored in Afghani. USD (or any currency) is shown alongside for quick reference. One rate change updates the whole system.</p>
        </div>
        <div class="currency-grid reveal">
            <div class="currency-visual">
                <div class="curr-rate">Current Exchange Rate</div>
                <div class="curr-display">1 USD = 71.50 ؋</div>
                <div class="curr-row">
                    <div class="curr-row-label">Invoice Total</div>
                    <div style="text-align:right">
                        <div class="curr-row-val afn">؋ 84,000</div>
                        <div style="font-size:0.7rem;opacity:0.65;">≈ $ 1,174.83</div>
                    </div>
                </div>
                <div class="curr-row">
                    <div class="curr-row-label">Payment Received</div>
                    <div style="text-align:right">
                        <div class="curr-row-val usd">$ 500.00</div>
                        <div style="font-size:0.7rem;opacity:0.65;">= ؋ 35,750</div>
                    </div>
                </div>
                <div class="curr-row">
                    <div class="curr-row-label">Remaining Debt</div>
                    <div style="text-align:right">
                        <div class="curr-row-val" style="color:#fda4af">؋ 48,250</div>
                        <div style="font-size:0.7rem;opacity:0.65;">≈ $ 674.83</div>
                    </div>
                </div>
            </div>
            <div class="currency-points">
                <div class="curr-point">
                    <div class="curr-point-icon"><i class="bi bi-database-check"></i></div>
                    <div>
                        <div class="curr-point-title">AFN is the source of truth</div>
                        <div class="curr-point-desc">All product prices, invoice totals, and customer debt are stored in ؋ AFN. USD is always calculated, never stored.</div>
                    </div>
                </div>
                <div class="curr-point">
                    <div class="curr-point-icon"><i class="bi bi-arrow-repeat"></i></div>
                    <div>
                        <div class="curr-point-title">Accept payments in any currency</div>
                        <div class="curr-point-desc">Record a payment in USD and FZL automatically converts it to AFN at the current rate and deducts it from the debt.</div>
                    </div>
                </div>
                <div class="curr-point">
                    <div class="curr-point-icon"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <div class="curr-point-title">Full rate change history</div>
                        <div class="curr-point-desc">Every exchange rate update is logged with the date and the user who changed it, so you always have an audit trail.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════ BILINGUAL ══════════ -->
<div class="bilingual-section">
    <h2 class="bi-title">English &amp; پښتو — Fully Bilingual</h2>
    <p class="bi-sub">Switch between English and Pashto at any time. The full interface flips RTL instantly — layout, sidebar, buttons, and all.</p>
    <div class="bi-cards">
        <div class="bi-card">
            <div class="bi-card-flag">🇬🇧</div>
            <div class="bi-card-lang">English</div>
            <div class="bi-card-dir">Left-to-Right (LTR)</div>
            <div class="bi-card-sample">"Record Payment"</div>
        </div>
        <div class="bi-card">
            <div class="bi-card-flag">🇦🇫</div>
            <div class="bi-card-lang">پښتو</div>
            <div class="bi-card-dir">Right-to-Left (RTL)</div>
            <div class="bi-card-sample">"تادیه ثبت کول"</div>
        </div>
        <div class="bi-card" style="min-width:260px;">
            <div class="bi-card-flag">⚡</div>
            <div class="bi-card-lang">Instant Switch</div>
            <div class="bi-card-dir">One Click, Any Page</div>
            <div class="bi-card-sample">Bootstrap RTL auto-loads</div>
        </div>
    </div>
</div>

<!-- ══════════ PRINT INVOICE ══════════ -->
<section class="print-section" id="print">
    <div class="print-inner">
        <div class="text-center reveal">
            <div class="section-label"><i class="bi bi-printer"></i> Print Invoice</div>
            <h2 class="section-title">Professional Invoices, Print-Ready</h2>
            <p class="section-sub center">Every invoice generates a branded A4 print layout matching the traditional Afghan cloth shop bill — RTL, striped rows, shop header, and signature footer.</p>
        </div>
        <div class="print-grid reveal">
            <div class="print-mockup-wrap">
                <div class="print-invoice-mock">
                    <div class="pim-header">
                        <div>
                            <div class="pim-shop-dari">دوکان رخت فروشی FZL</div>
                            <div class="pim-shop-en">FZL Cloth House</div>
                        </div>
                        <div class="pim-contact">
                            <div style="font-size:0.55rem;opacity:0.7;">شماره های تماس:</div>
                            <div class="pim-phones">0731-084435 · 0783-036908</div>
                            <div style="margin-top:3px;">مسؤل: فضل الحق</div>
                        </div>
                    </div>
                    <div class="pim-meta">
                        <span>شماره: <strong>0042</strong></span>
                        <span>اسم مشتری: <strong>Ahmad Shah</strong></span>
                        <span>تاریخ: <strong>2025/05/07</strong></span>
                    </div>
                    <table class="pim-table">
                        <thead>
                            <tr>
                                <th>شماره</th>
                                <th>نوع جنس</th>
                                <th>گزانه</th>
                                <th>نرخ (؋)</th>
                                <th>جمله (؋)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>1</td><td style="text-align:right;padding-right:8px;">کامیز پرتوگ — آبی (L)</td><td>240</td><td>350</td><td>84,000</td></tr>
                            <tr><td>2</td><td style="text-align:right;padding-right:8px;">چپن — بنفشه (XL)</td><td>60</td><td>280</td><td>16,800</td></tr>
                            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                        </tbody>
                    </table>
                    <div style="background:#f5f5f5;padding:5px 10px;font-size:0.62rem;display:flex;gap:20px;direction:rtl;border-top:1px solid #eee;">
                        <span>ټول: <strong>؋ 100,800</strong></span>
                        <span>ورکړل شوی: <strong style="color:#107c10">؋ 50,000</strong></span>
                        <span>پاتې پور: <strong style="color:#c82000">؋ 50,800</strong></span>
                    </div>
                    <div class="pim-footer">
                        <div class="pim-sig">امضاء: ...............................</div>
                        <div class="pim-note">سوت خرچ شوی مال نه واپس کیری</div>
                    </div>
                </div>
            </div>
            <div>
                <ul class="showcase-points">
                    <li><i class="bi bi-check-circle-fill"></i> Business header: shop name in Dari, English, phones, manager</li>
                    <li><i class="bi bi-check-circle-fill"></i> RTL table: شماره، نوع جنس، گزانه، نرخ، جمله</li>
                    <li><i class="bi bi-check-circle-fill"></i> Alternating orange/white striped rows like traditional paper</li>
                    <li><i class="bi bi-check-circle-fill"></i> Total, paid, and remaining debt shown at bottom</li>
                    <li><i class="bi bi-check-circle-fill"></i> Signature line + customizable footer note</li>
                    <li><i class="bi bi-check-circle-fill"></i> Clean A4 print with no UI chrome or sidebar</li>
                    <li><i class="bi bi-check-circle-fill"></i> Business info configurable from admin settings</li>
                </ul>
                <div style="margin-top:24px;">
                    <a href="/fzl/auth/login.php" class="btn-hero-primary" style="display:inline-flex;">
                        <i class="bi bi-printer"></i> Open & Print an Invoice
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════ STATS ══════════ -->
<section class="stats-section">
    <div style="text-align:center;" class="reveal">
        <div class="section-label"><i class="bi bi-graph-up"></i> Built For Scale</div>
        <h2 class="section-title">Numbers That Matter</h2>
    </div>
    <div class="stats-grid">
        <div class="stat-card reveal reveal-delay-1">
            <div class="stat-icon" style="background:rgba(0,103,192,0.1);"><i class="bi bi-receipt" style="color:var(--blue);font-size:1.3rem;"></i></div>
            <div class="stat-num" data-count="∞">∞</div>
            <div class="stat-label">Invoices Supported</div>
        </div>
        <div class="stat-card reveal reveal-delay-2">
            <div class="stat-icon" style="background:rgba(119,25,170,0.1);"><i class="bi bi-translate" style="color:#7719AA;font-size:1.3rem;"></i></div>
            <div class="stat-num">150+</div>
            <div class="stat-label">Translation Strings</div>
        </div>
        <div class="stat-card reveal reveal-delay-3">
            <div class="stat-icon" style="background:rgba(16,124,16,0.1);"><i class="bi bi-shield-check" style="color:var(--green);font-size:1.3rem;"></i></div>
            <div class="stat-num">2</div>
            <div class="stat-label">User Roles</div>
        </div>
        <div class="stat-card reveal reveal-delay-4">
            <div class="stat-icon" style="background:rgba(157,93,0,0.1);"><i class="bi bi-currency-exchange" style="color:var(--amber);font-size:1.3rem;"></i></div>
            <div class="stat-num">Any</div>
            <div class="stat-label">Secondary Currency</div>
        </div>
    </div>
</section>

<!-- ══════════ CTA ══════════ -->
<section class="cta-section">
    <h2 class="cta-title">Ready to manage your business smarter?</h2>
    <p class="cta-sub">Sign in to your FZL dashboard and take control of customers, invoices, payments, and stock — all in one place.</p>
    <div class="cta-actions">
        <a href="/fzl/auth/login.php" class="btn-cta-primary">
            <i class="bi bi-box-arrow-in-right"></i> Sign In to Dashboard
        </a>
        <a href="#features" class="btn-cta-secondary">
            <i class="bi bi-grid-3x3-gap"></i> Explore Features
        </a>
    </div>
</section>

<!-- ══════════ FOOTER ══════════ -->
<footer class="footer">
    <div class="footer-brand">
        <div class="footer-logo">FZL</div>
        <div class="footer-name">FZL Business System</div>
    </div>
    <div class="footer-copy">© <?= date('Y') ?> FZL — Cloth Wholesale Management</div>
    <div class="footer-links">
        <a href="/fzl/auth/login.php">Dashboard</a>
        <a href="#features">Features</a>
        <a href="#showcase">Showcase</a>
    </div>
</footer>

<script>
/* ── Navbar scroll shadow ── */
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 20);
});

/* ── Scroll reveal ── */
const revealEls = document.querySelectorAll('.reveal');
const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => {
        if (e.isIntersecting) {
            e.target.classList.add('visible');
            observer.unobserve(e.target);
        }
    });
}, { threshold: 0.12 });
revealEls.forEach(el => observer.observe(el));

/* ── Counter animation ── */
function animateCounter(el, target, duration = 1400) {
    if (typeof target !== 'number') return;
    let start = 0;
    const step = (timestamp) => {
        if (!start) start = timestamp;
        const progress = Math.min((timestamp - start) / duration, 1);
        const ease = 1 - Math.pow(1 - progress, 3);
        el.textContent = Math.floor(ease * target);
        if (progress < 1) requestAnimationFrame(step);
        else el.textContent = target;
    };
    requestAnimationFrame(step);
}

const counterEls = document.querySelectorAll('[data-count]');
const counterObserver = new IntersectionObserver((entries) => {
    entries.forEach(e => {
        if (e.isIntersecting) {
            const val = e.target.dataset.count;
            const num = parseInt(val);
            if (!isNaN(num)) animateCounter(e.target, num);
            counterObserver.unobserve(e.target);
        }
    });
}, { threshold: 0.5 });
counterEls.forEach(el => counterObserver.observe(el));

/* ── Smooth nav links ── */
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

/* ── Parallax orbs ── */
document.addEventListener('mousemove', e => {
    const x = (e.clientX / window.innerWidth - 0.5) * 18;
    const y = (e.clientY / window.innerHeight - 0.5) * 18;
    document.querySelectorAll('.orb').forEach((orb, i) => {
        const factor = (i + 1) * 0.4;
        orb.style.transform = `translate(${x * factor}px, ${y * factor}px)`;
    });
});
</script>

</body>
</html>
