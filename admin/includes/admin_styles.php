<!-- Shared admin design system -->
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════
   RESTAURANTMS — PREMIUM ADMIN DESIGN SYSTEM
═══════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #07080d;
  --surface:   #0e0f18;
  --surface2:  #14162080;
  --surface3:  #1c1e2e;
  --border:    rgba(255,255,255,0.06);
  --border2:   rgba(255,255,255,0.11);
  --gold:      #c9a227;
  --gold2:     #e8c060;
  --gold-glow: rgba(201,162,39,0.18);
  --text:      #ecedf5;
  --muted:     rgba(232,233,240,0.45);
  --muted2:    rgba(232,233,240,0.2);
  --green:     #22c55e;
  --green-dim: rgba(34,197,94,0.14);
  --red:       #ef4444;
  --red-dim:   rgba(239,68,68,0.14);
  --blue:      #60a5fa;
  --blue-dim:  rgba(96,165,250,0.14);
  --orange:    #f97316;
  --orange-dim:rgba(249,115,22,0.14);
  --purple:    #a78bfa;
  --sidebar-w: 240px;
  --sidebar-sm: 64px;
  --topbar-h:  62px;
  --r:         14px;
  --r-sm:      9px;
}

html, body { height: 100%; }

body {
  font-family: 'Inter', sans-serif;
  background: var(--bg);
  color: var(--text);
  display: flex;
  overflow: hidden;
  font-size: 14px;
  -webkit-font-smoothing: antialiased;
}

/* Subtle noise texture on body */
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0;
  pointer-events: none;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
  opacity: .45;
}

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.18); }

/* ═══════════════
   SIDEBAR
═══════════════ */
.sidebar {
  width: var(--sidebar-w);
  flex-shrink: 0;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  height: 100vh;
  position: fixed;
  top: 0; left: 0; z-index: 200;
  transition: width .28s cubic-bezier(.16,1,.3,1);
  overflow: hidden;
}

/* Sidebar inner gradient */
.sidebar::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(160deg, rgba(201,162,39,.03) 0%, transparent 60%);
  pointer-events: none; z-index: 0;
}

.sidebar > * { position: relative; z-index: 1; }

.sidebar.collapsed { width: var(--sidebar-sm); }
.sidebar.collapsed .brand-name,
.sidebar.collapsed .su-info,
.sidebar.collapsed .sb-section-label,
.sidebar.collapsed .nav-item span,
.sidebar.collapsed .nav-badge { display: none; }
.sidebar.collapsed .nav-item  { justify-content: center; padding: 10px 0; }
.sidebar.collapsed .nav-item i { font-size: 16px; }
.sidebar.collapsed .su-avatar { margin: 0; }
.sidebar.collapsed .sidebar-user { justify-content: center; }
.sidebar.collapsed .btn-logout { display: none; }
.sidebar.collapsed .sidebar-header { padding: 0 14px; }

/* Header */
.sidebar-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 18px; height: var(--topbar-h);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.sidebar-brand { display: flex; align-items: center; gap: 11px; text-decoration: none; }

.brand-icon {
  width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0;
  background: linear-gradient(135deg, #e8c060 0%, #9a7314 100%);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; color: #fff;
  box-shadow: 0 0 0 1px rgba(201,162,39,.3), 0 4px 16px rgba(201,162,39,.25);
}

.brand-name {
  font-family: 'Playfair Display', serif;
  font-size: 16px; font-weight: 600;
  background: linear-gradient(135deg, var(--gold2), var(--gold));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text; white-space: nowrap;
}

.sidebar-toggle {
  background: none; border: none; cursor: pointer;
  color: var(--muted); font-size: 13px; padding: 7px;
  border-radius: 7px; transition: .18s; flex-shrink: 0;
}
.sidebar-toggle:hover { color: var(--text); background: rgba(255,255,255,.06); }

/* Section labels */
.sb-section-label {
  font-size: 9.5px; font-weight: 700; letter-spacing: 1.8px;
  text-transform: uppercase; color: var(--muted2);
  padding: 20px 18px 6px; white-space: nowrap;
}

/* Nav */
.sidebar-nav { display: flex; flex-direction: column; gap: 2px; padding: 0 10px; }

.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border-radius: 9px;
  color: var(--muted); font-size: 13px; font-weight: 500;
  text-decoration: none; transition: .18s; white-space: nowrap;
  position: relative;
}
.nav-item i { font-size: 13.5px; flex-shrink: 0; min-width: 17px; text-align: center; transition: .18s; }

.nav-item:hover {
  background: rgba(255,255,255,.04);
  color: var(--text);
}
.nav-item:hover i { color: var(--text); }

.nav-item.active {
  background: linear-gradient(90deg, rgba(201,162,39,.14), rgba(201,162,39,.04));
  color: var(--gold2);
  border: 1px solid rgba(201,162,39,.18);
  box-shadow: inset 3px 0 0 var(--gold);
}
.nav-item.active i { color: var(--gold); }

.nav-badge {
  margin-left: auto; background: var(--red);
  color: #fff; font-size: 9px; font-weight: 700;
  border-radius: 10px; padding: 1px 6px; flex-shrink: 0;
}

.sidebar-spacer { flex: 1; }

/* Footer */
.sidebar-footer {
  padding: 12px 10px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px;
}
.sidebar-user { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.su-avatar {
  width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--gold), #7a5c10);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; color: #fff;
  box-shadow: 0 0 0 2px rgba(201,162,39,.25);
}
.su-name { font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.su-role { font-size: 10.5px; color: var(--muted); }
.btn-logout {
  flex-shrink: 0; background: none; border: none; cursor: pointer;
  color: var(--muted); font-size: 13px; padding: 7px;
  border-radius: 7px; transition: .18s; text-decoration: none;
}
.btn-logout:hover { color: var(--red); background: var(--red-dim); }

/* ═══════════════
   MAIN AREA
═══════════════ */
.admin-main {
  margin-left: var(--sidebar-w);
  flex: 1; display: flex; flex-direction: column;
  height: 100vh; overflow: hidden;
  transition: margin-left .28s cubic-bezier(.16,1,.3,1);
}
.admin-main.expanded { margin-left: var(--sidebar-sm); }

/* ── TOPBAR ── */
.topbar {
  height: var(--topbar-h); flex-shrink: 0;
  background: rgba(14,15,24,0.95);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center;
  padding: 0 26px; gap: 14px;
  position: relative; z-index: 10;
}
.topbar::after {
  content: ''; position: absolute; bottom: -1px; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, rgba(201,162,39,.15), transparent);
}
.topbar-title {
  font-family: 'Playfair Display', serif;
  font-size: 19px; font-weight: 600;
}
.topbar-sub { font-size: 11.5px; color: var(--muted); margin-left: 2px; }
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: 10px; }

/* Live badge */
.live-badge {
  display: flex; align-items: center; gap: 6px;
  font-size: 10.5px; font-weight: 700; color: var(--green);
  background: var(--green-dim); border: 1px solid rgba(34,197,94,.2);
  border-radius: 20px; padding: 3px 10px; letter-spacing: .5px;
}
.live-dot {
  width: 6px; height: 6px; border-radius: 50%; background: var(--green);
  animation: livePulse 2s infinite;
}
@keyframes livePulse { 0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.5);} 50%{box-shadow:0 0 0 4px rgba(34,197,94,0);} }

/* Command trigger */
.cmd-trigger {
  display: flex; align-items: center; gap: 7px;
  background: rgba(255,255,255,.04); border: 1px solid var(--border);
  border-radius: 9px; padding: 7px 13px;
  font-size: 12px; color: var(--muted);
  cursor: pointer; transition: .18s; font-family: inherit;
}
.cmd-trigger:hover { border-color: var(--border2); color: var(--text); background: rgba(255,255,255,.06); }
.cmd-trigger .kbd { background: var(--border); border-radius: 4px; padding: 1px 6px; font-size: 10px; }

.topbar-icon-btn {
  width: 34px; height: 34px; border-radius: 8px;
  background: rgba(255,255,255,.04); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  color: var(--muted); cursor: pointer; transition: .18s;
  position: relative; text-decoration: none; font-size: 13px;
}
.topbar-icon-btn:hover { border-color: var(--border2); color: var(--text); }
.badge-dot {
  position: absolute; top: 6px; right: 6px;
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--red); border: 1.5px solid var(--surface);
  animation: livePulse 2s infinite;
}

.live-clock {
  font-size: 12px; color: var(--muted); font-weight: 500;
  font-variant-numeric: tabular-nums; letter-spacing: .5px;
}

/* ═══════════════
   PAGE
═══════════════ */
.page-content {
  flex: 1; overflow-y: auto; padding: 24px 26px;
  position: relative; z-index: 1;
}

/* ═══════════════
   CARDS
═══════════════ */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 20px 22px;
  position: relative;
  transition: border-color .2s, box-shadow .2s;
}
.card:hover { border-color: var(--border2); }

/* Glow card variant */
.card-glow {
  background: linear-gradient(145deg, rgba(201,162,39,.06), rgba(14,15,24,1));
  border-color: rgba(201,162,39,.18);
  box-shadow: 0 0 40px rgba(201,162,39,.06);
}

.card-title {
  font-size: 11.5px; font-weight: 700; color: var(--muted);
  text-transform: uppercase; letter-spacing: 1px;
  margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
}
.card-title i { color: var(--gold); }

/* ═══════════════
   KPI CARDS
═══════════════ */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px; margin-bottom: 20px;
}
.kpi-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 18px 20px 16px;
  position: relative; overflow: hidden;
  transition: .22s; cursor: default;
}
.kpi-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  opacity: .8;
}
.kpi-card:hover { transform: translateY(-3px); box-shadow: 0 12px 40px rgba(0,0,0,.4); border-color: var(--border2); }

.kpi-card.kpi-gold  { border-color: rgba(201,162,39,.2); }
.kpi-card.kpi-gold::before  { background: linear-gradient(90deg, var(--gold), transparent 70%); }
.kpi-card.kpi-green { border-color: rgba(34,197,94,.2); }
.kpi-card.kpi-green::before { background: linear-gradient(90deg, var(--green), transparent 70%); }
.kpi-card.kpi-blue  { border-color: rgba(96,165,250,.2); }
.kpi-card.kpi-blue::before  { background: linear-gradient(90deg, var(--blue), transparent 70%); }
.kpi-card.kpi-orange{ border-color: rgba(249,115,22,.2); }
.kpi-card.kpi-orange::before{ background: linear-gradient(90deg, var(--orange), transparent 70%); }
.kpi-card.kpi-purple{ border-color: rgba(167,139,250,.2); }
.kpi-card.kpi-purple::before{ background: linear-gradient(90deg, var(--purple), transparent 70%); }

/* Glow bg on hover */
.kpi-card.kpi-gold:hover  { background: linear-gradient(145deg, rgba(201,162,39,.06), var(--surface)); }
.kpi-card.kpi-green:hover { background: linear-gradient(145deg, rgba(34,197,94,.05),  var(--surface)); }
.kpi-card.kpi-blue:hover  { background: linear-gradient(145deg, rgba(96,165,250,.05), var(--surface)); }
.kpi-card.kpi-orange:hover{ background: linear-gradient(145deg, rgba(249,115,22,.05), var(--surface)); }

.kpi-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px; }
.kpi-label  { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; }
.kpi-icon-badge {
  width: 30px; height: 30px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; flex-shrink: 0;
}
.kpi-card.kpi-gold   .kpi-icon-badge { background: rgba(201,162,39,.12); color: var(--gold); }
.kpi-card.kpi-green  .kpi-icon-badge { background: var(--green-dim); color: var(--green); }
.kpi-card.kpi-blue   .kpi-icon-badge { background: var(--blue-dim);  color: var(--blue); }
.kpi-card.kpi-orange .kpi-icon-badge { background: var(--orange-dim);color: var(--orange); }
.kpi-card.kpi-purple .kpi-icon-badge { background: rgba(167,139,250,.12); color: var(--purple); }

.kpi-val {
  font-family: 'Playfair Display', serif;
  font-size: 26px; font-weight: 700; color: var(--text);
  line-height: 1; margin-bottom: 10px;
  font-variant-numeric: tabular-nums;
}
.kpi-footer { display: flex; align-items: center; justify-content: space-between; }
.kpi-sub {
  font-size: 11.5px; color: var(--muted);
  display: flex; align-items: center; gap: 5px;
}
.trend-up   { color: var(--green); }
.trend-down { color: var(--red); }
.trend-flat { color: var(--muted); }

/* ═══════════════
   GRIDS
═══════════════ */
.grid-2    { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; margin-bottom: 20px; }
.grid-3    { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 20px; }
.grid-half { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px; }
.grid-73   { display: grid; grid-template-columns: 3fr 2fr; gap: 14px; margin-bottom: 20px; }
.mb-14 { margin-bottom: 14px; }

/* ═══════════════
   DATA TABLE
═══════════════ */
.data-table { width: 100%; border-collapse: collapse; }
.data-table th {
  font-size: 10.5px; font-weight: 700; color: var(--muted2);
  text-transform: uppercase; letter-spacing: .8px;
  padding: 8px 14px; text-align: left;
  border-bottom: 1px solid var(--border);
}
.data-table td {
  padding: 10px 14px;
  border-bottom: 1px solid rgba(255,255,255,.03);
  font-size: 13px;
}
.data-table tr:last-child td { border-bottom: none; }
.data-table tbody tr { transition: background .12s; }
.data-table tbody tr:hover { background: rgba(255,255,255,.022); }

/* ═══════════════
   STATUS PILLS
═══════════════ */
.pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 2px 9px; border-radius: 20px;
  font-size: 10.5px; font-weight: 600; text-transform: capitalize;
  letter-spacing: .2px;
}
.pill::before { content: ''; width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
.pill-pending   { background: var(--orange-dim); color: var(--orange); border: 1px solid rgba(249,115,22,.2); }
.pill-pending::before   { background: var(--orange); }
.pill-confirmed { background: var(--blue-dim);   color: var(--blue);   border: 1px solid rgba(96,165,250,.2); }
.pill-confirmed::before { background: var(--blue); }
.pill-seated    { background: rgba(201,162,39,.12); color: var(--gold); border: 1px solid rgba(201,162,39,.2); }
.pill-seated::before    { background: var(--gold); }
.pill-completed { background: var(--green-dim);  color: var(--green);  border: 1px solid rgba(34,197,94,.2); }
.pill-completed::before { background: var(--green); }
.pill-cancelled { background: var(--red-dim);    color: var(--red);    border: 1px solid rgba(239,68,68,.2); }
.pill-cancelled::before { background: var(--red); }
.pill-preparing { background: rgba(167,139,250,.12); color: var(--purple); border: 1px solid rgba(167,139,250,.2); }
.pill-preparing::before { background: var(--purple); }
.pill-ready     { background: var(--green-dim);  color: var(--green);  border: 1px solid rgba(34,197,94,.2); }
.pill-ready::before     { background: var(--green); }

/* ═══════════════
   SECTION HEADER
═══════════════ */
.section-hd {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}
.section-hd h3 {
  font-size: 13px; font-weight: 600; color: var(--text);
  display: flex; align-items: center; gap: 8px;
}
.section-hd h3 i { color: var(--gold); font-size: 13px; }
.section-hd a, .section-hd button {
  font-size: 11.5px; color: var(--muted); text-decoration: none;
  background: none; border: none; cursor: pointer;
  font-family: inherit; transition: .15s;
}
.section-hd a:hover, .section-hd button:hover { color: var(--gold); }

/* ═══════════════
   SMART PULSE
═══════════════ */
.pulse-grid { display: flex; flex-direction: column; gap: 8px; }
.pulse-item {
  display: flex; align-items: flex-start; gap: 11px;
  padding: 11px 14px; border-radius: 9px;
  border: 1px solid var(--border); background: rgba(255,255,255,.02);
  transition: .18s;
}
.pulse-item:hover { background: rgba(255,255,255,.035); border-color: var(--border2); }
.pulse-dot {
  width: 7px; height: 7px; border-radius: 50%;
  flex-shrink: 0; margin-top: 5px;
}
.pulse-item.critical .pulse-dot { background: var(--red);    box-shadow: 0 0 0 3px rgba(239,68,68,.2); animation: livePulse 1.5s infinite; }
.pulse-item.warning  .pulse-dot { background: var(--orange); box-shadow: 0 0 0 3px rgba(249,115,22,.2); }
.pulse-item.info     .pulse-dot { background: var(--blue); }
.pulse-item.positive .pulse-dot { background: var(--green); box-shadow: 0 0 0 3px rgba(34,197,94,.15); }
.pulse-msg  { font-size: 12.5px; line-height: 1.55; }
.pulse-time { font-size: 10.5px; color: var(--muted); margin-top: 3px; }

/* ═══════════════
   COMMAND PALETTE
═══════════════ */
.cmd-overlay {
  display: none; position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,.7); backdrop-filter: blur(8px);
  align-items: flex-start; justify-content: center; padding-top: 10vh;
}
.cmd-overlay.open { display: flex; }
.cmd-box {
  background: var(--surface3);
  border: 1px solid var(--border2);
  border-radius: 16px; width: 560px; max-width: 96vw;
  box-shadow: 0 40px 100px rgba(0,0,0,.8), 0 0 0 1px rgba(201,162,39,.08);
  overflow: hidden;
  animation: cmdIn .2s cubic-bezier(.16,1,.3,1);
}
@keyframes cmdIn { from{opacity:0;transform:scale(.95) translateY(-10px);} to{opacity:1;transform:none;} }
.cmd-input-wrap {
  display: flex; align-items: center; gap: 12px;
  padding: 16px 20px; border-bottom: 1px solid var(--border);
}
.cmd-input-wrap i { color: var(--muted); font-size: 14px; flex-shrink: 0; }
.cmd-input {
  flex: 1; background: none; border: none;
  color: var(--text); font-size: 14px;
  font-family: inherit; outline: none;
}
.cmd-input::placeholder { color: var(--muted2); }
.cmd-results { max-height: 320px; overflow-y: auto; padding: 6px; }
.cmd-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px; border-radius: 8px;
  cursor: pointer; color: var(--muted); font-size: 13px; transition: .1s;
}
.cmd-item:hover, .cmd-item.selected {
  background: rgba(201,162,39,.1); color: var(--text);
}
.cmd-item i { font-size: 13px; color: var(--gold); width: 16px; text-align: center; }
.cmd-item .cmd-desc { font-size: 11px; color: var(--muted2); margin-left: auto; }
.cmd-footer {
  padding: 10px 20px; border-top: 1px solid var(--border);
  display: flex; gap: 16px; font-size: 10.5px; color: var(--muted2);
}
.cmd-footer span { display: flex; align-items: center; gap: 5px; }
.cmd-footer kbd { background: var(--border2); border-radius: 3px; padding: 1px 5px; font-size: 10px; }

/* ═══════════════
   TOAST
═══════════════ */
#admin-toast-wrap {
  position: fixed; top: 72px; right: 22px; z-index: 900;
  display: flex; flex-direction: column; gap: 8px; pointer-events: none;
}
.admin-toast {
  background: var(--surface3); border: 1px solid var(--border2);
  border-radius: 10px; padding: 11px 16px;
  display: flex; align-items: center; gap: 10px;
  font-size: 12.5px; min-width: 240px;
  box-shadow: 0 10px 40px rgba(0,0,0,.6);
  animation: toastIn .3s cubic-bezier(.16,1,.3,1);
  pointer-events: all;
}
@keyframes toastIn { from{opacity:0;transform:translateX(28px);} to{opacity:1;transform:none;} }
.admin-toast i { font-size: 14px; flex-shrink: 0; }
.admin-toast.success i { color: var(--green); }
.admin-toast.error   i { color: var(--red); }
.admin-toast.info    i { color: var(--blue); }

/* ═══════════════
   UTILS
═══════════════ */
.text-gold   { color: var(--gold); }
.text-green  { color: var(--green); }
.text-red    { color: var(--red); }
.text-blue   { color: var(--blue); }
.text-muted  { color: var(--muted); }
.fw-600 { font-weight: 600; }
.fw-700 { font-weight: 700; }
.fs-12  { font-size: 12px; }
.fs-11  { font-size: 11px; }

/* ═══════════════
   RESPONSIVE
═══════════════ */
@media (max-width: 1280px) {
  .kpi-grid { grid-template-columns: repeat(2,1fr); }
  .grid-2   { grid-template-columns: 1fr; }
  .grid-73  { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
  .sidebar { width: var(--sidebar-sm); }
  .sidebar .brand-name, .sidebar .su-info, .sidebar .sb-section-label,
  .sidebar .nav-item span { display: none; }
  .sidebar .nav-item { justify-content: center; padding: 10px 0; }
  .admin-main { margin-left: var(--sidebar-sm); }
  .grid-half { grid-template-columns: 1fr; }
  .grid-3    { grid-template-columns: 1fr 1fr; }
  .page-content { padding: 16px; }
}
@media (max-width: 640px) {
  .kpi-grid { grid-template-columns: 1fr 1fr; }
  .grid-3   { grid-template-columns: 1fr; }
}
</style>