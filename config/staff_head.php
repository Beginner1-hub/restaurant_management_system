<?php
/* Shared staff page head — include INSIDE <head> after opening tag.
   Usage: include("../config/staff_head.php"); or include("../../config/staff_head.php");
   Set $page_title and $role_accent before including. */
$role_accent  = $role_accent  ?? '#c9a227';
$role_accent2 = $role_accent2 ?? '#e8c060';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════════════════════
   RESTAURANTMS — PREMIUM STAFF DESIGN SYSTEM v2
═══════════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  /* Backgrounds */
  --bg:      #0a0a0f;
  --surface: #12121a;
  --surface2:#1a1a26;
  --surface3:#222230;

  /* Borders */
  --border:  rgba(255,255,255,0.06);
  --border2: rgba(255,255,255,0.10);

  /* Text */
  --text:    #f0f0ff;
  --muted:   #6b7280;
  --sub:     #9ca3af;
  --muted2:  rgba(240,240,255,0.2);

  /* Semantic */
  --green:    #22c55e; --green-d:  rgba(34,197,94,.12);
  --blue:     #60a5fa; --blue-d:   rgba(96,165,250,.12);
  --orange:   #f97316; --orange-d: rgba(249,115,22,.12);
  --red:      #ef4444; --red-d:    rgba(239,68,68,.12);
  --purple:   #a78bfa; --purple-d: rgba(167,139,250,.12);
  --gold:     #f59e0b; --gold-d:   rgba(245,158,11,.12);

  /* Legacy compat */
  --gold2: #fbbf24;

  /* Role accent — overridden per dashboard */
  --accent:  <?php echo $role_accent; ?>;
  --accent2: <?php echo $role_accent2; ?>;

  /* Layout */
  --topbar-h: 60px;
  --r: 14px;
}

html, body { height: 100%; }
body {
  font-family: 'Inter', sans-serif;
  background: var(--bg);
  background-image:
    radial-gradient(ellipse at 20% 0%, rgba(99,102,241,.07) 0%, transparent 60%),
    radial-gradient(ellipse at 80% 100%, rgba(139,92,246,.05) 0%, transparent 60%);
  color: var(--text);
  font-size: 14px;
  line-height: 1.6;
  overflow-x: hidden;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/* ── Scrollbar ── */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.18); }

/* ══════════════════════════════════════════
   TOPBAR — glass morphism
══════════════════════════════════════════ */
.topbar {
  height: var(--topbar-h);
  background: rgba(18,18,26,.88);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border2);
  display: flex;
  align-items: center;
  padding: 0 24px;
  gap: 12px;
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: 0 1px 0 0 rgba(255,255,255,.04), 0 4px 20px rgba(0,0,0,.35);
}
.topbar::after {
  content: '';
  position: absolute;
  bottom: -1px;
  left: 0;
  right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
  opacity: .3;
  pointer-events: none;
}

/* Topbar brand */
.tb-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
  color: var(--text);
}
.tb-icon {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 70%, #000));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  color: #fff;
  flex-shrink: 0;
  box-shadow: 0 4px 12px color-mix(in srgb, var(--accent) 25%, transparent);
}
.tb-title {
  font-size: 15px;
  font-weight: 700;
  letter-spacing: -.01em;
  color: var(--text);
}

/* Topbar right section */
.tb-right {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-left: auto;
}
.tb-clock {
  font-size: 13px;
  font-weight: 700;
  font-family: 'Playfair Display', serif;
  color: var(--sub);
  letter-spacing: .04em;
  font-variant-numeric: tabular-nums;
}
.tb-user {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 13px;
}
.tb-avatar {
  width: 32px;
  height: 32px;
  border-radius: 9px;
  flex-shrink: 0;
  background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 60%, #000));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  color: #fff;
  box-shadow: 0 0 0 2px rgba(255,255,255,.08);
}
.tb-name {
  font-size: 13px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--text);
}
.tb-role {
  font-size: 10.5px;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .06em;
}

/* Topbar buttons */
.tb-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border-radius: 9px;
  background: var(--surface2);
  border: 1px solid var(--border2);
  color: var(--sub);
  font-size: 12.5px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  text-decoration: none;
  transition: .15s;
  white-space: nowrap;
}
.tb-btn:hover {
  background: var(--surface3);
  color: var(--text);
  border-color: rgba(255,255,255,.15);
}
.tb-btn.accent {
  background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 70%, #000));
  color: #fff;
  border-color: transparent;
  font-weight: 700;
}
.tb-btn.accent:hover { filter: brightness(1.1); }
.tb-btn.danger {
  background: var(--red-d);
  color: var(--red);
  border-color: rgba(239,68,68,.2);
}
.tb-btn.danger:hover { background: rgba(239,68,68,.2); }
.tb-btn.on {
  border-color: rgba(249,115,22,.35);
  color: var(--orange);
  background: rgba(249,115,22,.08);
}

/* Live indicator dot */
.live-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--green);
  display: inline-block;
  flex-shrink: 0;
  box-shadow: 0 0 0 0 rgba(34,197,94,.4);
  animation: livePulse 2s ease-in-out infinite;
}
@keyframes livePulse {
  0%,100% { box-shadow: 0 0 0 0 rgba(34,197,94,.4); }
  50%     { box-shadow: 0 0 0 5px rgba(34,197,94,0); }
}

/* ══════════════════════════════════════════
   PAGE WRAPPER
══════════════════════════════════════════ */
.page-wrap {
  padding: 24px 28px;
  max-width: 1600px;
  margin: 0 auto;
}

/* ══════════════════════════════════════════
   BUTTONS — premium micro-interactions
══════════════════════════════════════════ */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 16px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  font-family: inherit;
  border: 1px solid transparent;
  cursor: pointer;
  text-decoration: none;
  transition: all .2s cubic-bezier(.16,1,.3,1);
  letter-spacing: .01em;
  white-space: nowrap;
}
.btn:active { transform: scale(.97); }
.btn-primary {
  background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 70%, #000));
  color: #fff;
  border-color: transparent;
  box-shadow: 0 4px 15px color-mix(in srgb, var(--accent) 28%, transparent);
}
.btn-primary:hover {
  filter: brightness(1.1);
  box-shadow: 0 6px 20px color-mix(in srgb, var(--accent) 40%, transparent);
  transform: translateY(-1px);
}
.btn-ghost {
  background: var(--surface2);
  color: var(--sub);
  border-color: var(--border2);
}
.btn-ghost:hover {
  background: var(--surface3);
  color: var(--text);
  border-color: rgba(255,255,255,.15);
}
.btn-danger, .btn.danger {
  background: var(--red-d);
  color: var(--red);
  border-color: rgba(239,68,68,.2);
}
.btn-danger:hover, .btn.danger:hover { background: rgba(239,68,68,.2); }
.btn-green {
  background: var(--green-d);
  color: var(--green);
  border-color: rgba(34,197,94,.2);
}
.btn-green:hover { background: rgba(34,197,94,.2); }
.btn-sm  { padding: 7px 13px; font-size: 12.5px; }
.btn-xs  { padding: 5px 10px; font-size: 11.5px; border-radius: 8px; }
.btn[disabled], .btn:disabled { opacity: .4; cursor: not-allowed; pointer-events: none; }

/* ══════════════════════════════════════════
   STATUS PILLS
══════════════════════════════════════════ */
.pill {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}
.pill-pending   { background: var(--orange-d); color: var(--orange); border: 1px solid rgba(249,115,22,.22); }
.pill-preparing { background: var(--purple-d); color: var(--purple); border: 1px solid rgba(167,139,250,.22); }
.pill-ready     { background: var(--green-d);  color: var(--green);  border: 1px solid rgba(34,197,94,.22); }
.pill-served    { background: rgba(167,139,250,.1); color: #c4b5fd; border: 1px solid rgba(167,139,250,.22); }
.pill-completed { background: rgba(100,100,120,.15); color: var(--muted); border: 1px solid rgba(255,255,255,.07); }
.pill-cancelled { background: var(--red-d); color: var(--red); border: 1px solid rgba(239,68,68,.22); }
.pill-confirmed { background: var(--green-d); color: var(--green); border: 1px solid rgba(34,197,94,.22); }
.pill-seated    { background: var(--blue-d);  color: var(--blue);  border: 1px solid rgba(96,165,250,.22); }
.pill-available { background: var(--green-d); color: var(--green); border: 1px solid rgba(34,197,94,.22); }
.pill-occupied  { background: var(--red-d);   color: var(--red);   border: 1px solid rgba(239,68,68,.22); }

/* ══════════════════════════════════════════
   BADGES (count bubbles)
══════════════════════════════════════════ */
.badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 700;
}
.badge-red    { background: var(--red-d);    color: var(--red);    border: 1px solid rgba(239,68,68,.25); }
.badge-green  { background: var(--green-d);  color: var(--green);  border: 1px solid rgba(34,197,94,.25); }
.badge-blue   { background: var(--blue-d);   color: var(--blue);   border: 1px solid rgba(96,165,250,.25); }
.badge-orange { background: var(--orange-d); color: var(--orange); border: 1px solid rgba(249,115,22,.25); }
.badge-gold   { background: var(--gold-d);   color: var(--gold);   border: 1px solid rgba(245,158,11,.25); }

/* ══════════════════════════════════════════
   CARDS
══════════════════════════════════════════ */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 18px 20px;
  overflow: hidden;
  transition: border-color .2s, box-shadow .2s;
}
.card:hover { border-color: var(--border2); box-shadow: 0 8px 32px rgba(0,0,0,.35); }
.card-hd {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.card-hd h3 {
  font-size: 13px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--text);
}
.card-hd h3 i { color: var(--accent); }
.card-hd a, .card-hd button {
  font-size: 12px;
  color: var(--muted);
  text-decoration: none;
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  transition: .15s;
}
.card-hd a:hover, .card-hd button:hover { color: var(--accent); }

/* ══════════════════════════════════════════
   STAT CARDS
══════════════════════════════════════════ */
.stat-row {
  display: grid;
  grid-template-columns: repeat(4,1fr);
  gap: 14px;
  margin-bottom: 22px;
}
.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 20px 22px;
  position: relative;
  overflow: hidden;
  transition: .22s cubic-bezier(.16,1,.3,1);
}
.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: linear-gradient(90deg, var(--accent), transparent);
  opacity: .7;
}
.stat-card:hover {
  border-color: var(--border2);
  transform: translateY(-2px);
  box-shadow: 0 12px 40px rgba(0,0,0,.4);
}
.stat-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  margin-bottom: 8px;
}
.stat-val {
  font-family: 'Playfair Display', serif;
  font-size: 26px;
  font-weight: 700;
  color: var(--text);
  line-height: 1;
  margin-bottom: 6px;
}
.stat-sub {
  font-size: 11.5px;
  color: var(--muted);
  display: flex;
  align-items: center;
  gap: 5px;
}
.stat-icon {
  position: absolute;
  right: 16px;
  top: 16px;
  font-size: 22px;
  opacity: .08;
}

/* Colored top-line variants */
.stat-line-green  { position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--green),transparent); }
.stat-line-blue   { position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--blue),transparent); }
.stat-line-gold   { position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--gold),transparent); }
.stat-line-orange { position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--orange),transparent); }
.stat-line-purple { position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--purple),transparent); }

/* ══════════════════════════════════════════
   DATA TABLE
══════════════════════════════════════════ */
.data-table { width: 100%; border-collapse: collapse; }
.data-table th {
  font-size: 10.5px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .07em;
  padding: 9px 14px;
  border-bottom: 1px solid var(--border);
  text-align: left;
}
.data-table td {
  padding: 11px 14px;
  border-bottom: 1px solid rgba(255,255,255,.03);
  font-size: 13px;
}
.data-table tr:last-child td { border-bottom: none; }
.data-table tbody tr { transition: background .12s; }
.data-table tbody tr:hover { background: rgba(255,255,255,.025); }

/* ══════════════════════════════════════════
   MODAL
══════════════════════════════════════════ */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 500;
  background: rgba(0,0,0,.7);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  align-items: flex-start;
  justify-content: center;
  padding: 60px 20px;
  overflow-y: auto;
}
.modal-overlay.open { display: flex; }
.modal {
  background: var(--surface3);
  border: 1px solid var(--border2);
  border-radius: 18px;
  width: 100%;
  max-width: 540px;
  box-shadow: 0 40px 80px rgba(0,0,0,.7);
  animation: mIn .22s cubic-bezier(.16,1,.3,1);
}
.modal-lg { max-width: 760px; }
@keyframes mIn {
  from { opacity: 0; transform: scale(.95) translateY(-12px); }
  to   { opacity: 1; transform: none; }
}
.modal-hd {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
}
.modal-hd h3 { font-size: 16px; font-weight: 700; }
.modal-close {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--muted);
  font-size: 16px;
  padding: 4px 7px;
  border-radius: 7px;
  transition: .15s;
}
.modal-close:hover { color: var(--red); background: var(--red-d); }
.modal-body { padding: 22px 24px; }
.modal-ft {
  padding: 18px 24px;
  border-top: 1px solid var(--border);
  display: flex;
  gap: 10px;
  justify-content: flex-end;
}

/* ══════════════════════════════════════════
   TOAST NOTIFICATIONS
══════════════════════════════════════════ */
#toast-wrap {
  position: fixed;
  bottom: 20px;
  right: 20px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  gap: 8px;
  pointer-events: none;
}
.toast {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-radius: 12px;
  min-width: 240px;
  max-width: 340px;
  font-size: 13px;
  font-weight: 500;
  font-family: inherit;
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  animation: toastIn .3s cubic-bezier(.16,1,.3,1);
  box-shadow: 0 8px 32px rgba(0,0,0,.5);
  pointer-events: all;
}
@keyframes toastIn {
  from { opacity: 0; transform: translateX(20px); }
  to   { opacity: 1; transform: none; }
}
.toast.success { background: rgba(34,197,94,.15);  border: 1px solid rgba(34,197,94,.28);  color: var(--green); }
.toast.error   { background: rgba(239,68,68,.15);  border: 1px solid rgba(239,68,68,.28);  color: var(--red); }
.toast.info    { background: rgba(96,165,250,.15); border: 1px solid rgba(96,165,250,.28); color: var(--blue); }
.toast.warning { background: rgba(245,158,11,.15); border: 1px solid rgba(245,158,11,.28); color: var(--gold); }
.toast i { font-size: 14px; flex-shrink: 0; }

/* ══════════════════════════════════════════
   FORM FIELDS
══════════════════════════════════════════ */
.field { margin-bottom: 16px; }
.field label {
  display: block;
  font-size: 11px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .07em;
  margin-bottom: 7px;
}
.field input,
.field select,
.field textarea {
  width: 100%;
  padding: 10px 14px;
  background: rgba(255,255,255,.04);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-family: inherit;
  font-size: 13.5px;
  outline: none;
  transition: .2s;
}
.field input::placeholder, .field textarea::placeholder { color: var(--muted); }
.field input:focus,
.field select:focus,
.field textarea:focus {
  border-color: color-mix(in srgb, var(--accent) 50%, transparent);
  background: rgba(255,255,255,.06);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 10%, transparent);
}
.field select option { background: var(--surface3); }
.field textarea { resize: vertical; min-height: 80px; }

/* ══════════════════════════════════════════
   GRID HELPERS
══════════════════════════════════════════ */
.grid-2  { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.grid-3  { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }
.grid-73 { display: grid; grid-template-columns: 3fr 2fr; gap: 18px; }
.grid-37 { display: grid; grid-template-columns: 2fr 3fr; gap: 18px; }
.mb-16 { margin-bottom: 16px; }
.mb-20 { margin-bottom: 20px; }

/* ══════════════════════════════════════════
   RESPONSIVE — shared across all pages
══════════════════════════════════════════ */

/* ── 1024px: compact topbar, condense nav items ── */
@media (max-width: 1024px) {
  .topbar { padding: 0 14px; gap: 8px; }
  .tb-btn  { padding: 6px 11px; font-size: 12px; }
  .page-wrap { padding: 18px 20px; }
}

/* ── 860px: hide username text, shrink clock ── */
@media (max-width: 860px) {
  .topbar { gap: 6px; }
  .tb-name, .tb-role  { display: none; }       /* keep avatar, lose name text */
  .tb-clock           { font-size: 12px; }
  .stat-row { grid-template-columns: repeat(2,1fr); }
  .grid-73, .grid-37, .grid-2 { grid-template-columns: 1fr; }
  .page-wrap { padding: 14px 16px; }
}

/* ── 640px: icon-only topbar buttons, hide clock ── */
@media (max-width: 640px) {
  :root { --topbar-h: 52px; }
  .topbar { padding: 0 10px; gap: 5px; }
  .tb-title { display: none; }                 /* keep brand icon, lose title text */
  .tb-clock { display: none; }
  .tb-btn   { padding: 7px 9px; gap: 0; }
  .tb-btn   span, .tb-btn > :not(i):not(svg) { font-size: 0; width: 0; overflow: hidden; margin: 0; padding: 0; }
  .tb-btn i { font-size: 13px; }
  .stat-row { grid-template-columns: 1fr 1fr; }
  .grid-3   { grid-template-columns: 1fr 1fr; }
  .modal    { width: calc(100vw - 24px); max-height: 90vh; }
  .modal-lg { width: calc(100vw - 24px); }
}

/* ── 420px: smallest phones ── */
@media (max-width: 420px) {
  .topbar { padding: 0 8px; gap: 4px; }
  .tb-avatar { width: 28px; height: 28px; font-size: 11px; }
  .stat-row  { grid-template-columns: 1fr 1fr; }
}
</style>
