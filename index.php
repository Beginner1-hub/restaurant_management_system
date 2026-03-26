<?php
session_start();
if (isset($_SESSION['user'])) {
    $rd = ['admin'=>'admin/dashboard.php','waiter'=>'waiter/dashboard.php',
           'cashier'=>'cashier/dashboard.php','kitchen'=>'kitchen/dashboard.php'];
    $r = $_SESSION['user']['role'];
    if (isset($rd[$r])) { header("Location: ".$rd[$r]); exit(); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RestaurantMS — Management Platform</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── RESET ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --gold:   #c9a227;
  --gold2:  #e8c060;
  --gold3:  rgba(201,162,39,0.15);
  --dark:   #0a0b0f;
  --dark2:  #111218;
  --glass:  rgba(255,255,255,0.04);
  --glass2: rgba(255,255,255,0.07);
  --border: rgba(255,255,255,0.07);
  --border2:rgba(255,255,255,0.12);
  --text:   #ecedf5;
  --muted:  rgba(255,255,255,0.42);
}

html { scroll-behavior: smooth; }

body {
  font-family: 'Inter', sans-serif;
  background: var(--dark);
  color: var(--text);
  overflow-x: hidden;
}

/* ── PROGRESS BAR ── */
#progress-bar {
  position: fixed; top: 0; left: 0; height: 2px; z-index: 200;
  background: linear-gradient(90deg, var(--gold), var(--gold2));
  width: 0%; transition: width .1s linear;
}

/* ── BACKGROUND ── */
.bg-wrap {
  position: fixed; inset: 0; z-index: 0;
  overflow: hidden;
}
.bg-image {
  position: absolute; inset: 0;
  background: url('images/hm.jpg') center/cover no-repeat;
  filter: brightness(0.82) saturate(0.85);
  transform: scale(1.04);
  transition: transform 8s ease;
}
.bg-overlay {
  position: absolute; inset: 0;
  background:
    linear-gradient(to right, rgba(10,11,15,0.75) 0%, rgba(10,11,15,0.35) 55%, rgba(10,11,15,0.0) 100%),
    linear-gradient(to bottom, rgba(10,11,15,0.1) 0%, transparent 30%, rgba(10,11,15,0.75) 100%);
}

/* animated orbs */
.orb {
  position: absolute; border-radius: 50%; pointer-events: none;
  filter: blur(80px); opacity: 0.18;
  animation: orbFloat 14s ease-in-out infinite;
}
.orb1 { width: 500px; height: 500px; background: #c9a22730; top: -100px; right: 5%; animation-duration: 16s; }
.orb2 { width: 350px; height: 350px; background: #6ab4ff25; bottom: 10%; left: 10%; animation-duration: 20s; animation-delay: -7s; }
.orb3 { width: 260px; height: 260px; background: #c9a22720; top: 40%; right: 20%; animation-duration: 12s; animation-delay: -4s; }

@keyframes orbFloat {
  0%,100% { transform: translate(0,0) scale(1); }
  33%      { transform: translate(30px,-40px) scale(1.06); }
  66%      { transform: translate(-20px,30px) scale(0.95); }
}

/* ── NAVBAR ── */
nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100;
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 64px; height: 68px;
  background: rgba(10,11,15,0.8);
  backdrop-filter: blur(24px);
  border-bottom: 1px solid var(--border);
  transition: background .3s;
}
nav.scrolled { background: rgba(10,11,15,0.96); }

.nav-logo {
  display: flex; align-items: center; gap: 11px;
  font-family: 'Playfair Display', serif;
  font-size: 19px; font-weight: 600; color: var(--gold);
  text-decoration: none; letter-spacing: .3px;
}
.logo-icon {
  width: 36px; height: 36px; border-radius: 9px;
  background: linear-gradient(135deg, var(--gold) 0%, #9a7a14 100%);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; color: #fff;
  box-shadow: 0 4px 14px rgba(201,162,39,0.35);
}

.nav-links {
  display: flex; align-items: center; gap: 34px;
  position: absolute; left: 50%; transform: translateX(-50%);
}
.nav-links a {
  font-size: 13px; color: var(--muted); text-decoration: none;
  font-weight: 500; transition: color .2s; position: relative;
}
.nav-links a::after {
  content: ''; position: absolute; bottom: -4px; left: 0; right: 0;
  height: 1px; background: var(--gold); transform: scaleX(0);
  transform-origin: left; transition: transform .25s;
}
.nav-links a:hover { color: var(--text); }
.nav-links a:hover::after { transform: scaleX(1); }

.nav-right { display: flex; align-items: center; gap: 14px; }

.nav-reserve {
  padding: 8px 18px; background: transparent;
  border: 1px solid rgba(201,162,39,0.4); border-radius: 6px;
  font-size: 13px; font-weight: 500; color: var(--gold);
  text-decoration: none; transition: .2s;
}
.nav-reserve:hover { background: rgba(201,162,39,0.1); border-color: var(--gold); }

.nav-cta {
  padding: 8px 22px;
  background: linear-gradient(135deg, var(--gold), #a07a18);
  color: #0a0b0f; border: none; border-radius: 6px;
  font-size: 13px; font-weight: 700; cursor: none;
  font-family: inherit; transition: .2s; text-decoration: none;
  box-shadow: 0 4px 16px rgba(201,162,39,0.3);
}
.nav-cta:hover { transform: translateY(-1px); box-shadow: 0 6px 22px rgba(201,162,39,0.45); }

/* ── MAIN WRAPPER ── */
main { position: relative; z-index: 1; }

/* ── HERO ── */
.hero {
  min-height: 100vh;
  display: flex; align-items: center;
  padding: 120px 64px 90px;
  position: relative;
}
.hero::after {
  content: '';
  position: absolute; bottom: 0; left: 0; right: 0; height: 140px;
  background: linear-gradient(transparent, var(--dark));
  pointer-events: none;
}

.hero-content { max-width: 620px; position: relative; }

/* eyebrow */
.hero-eyebrow {
  display: inline-flex; align-items: center; gap: 9px;
  background: rgba(201,162,39,.1);
  border: 1px solid rgba(201,162,39,.3);
  border-radius: 24px; padding: 6px 16px;
  font-size: 11px; font-weight: 600; color: var(--gold);
  letter-spacing: 1.8px; text-transform: uppercase; margin-bottom: 30px;
}
.pulse-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--gold); animation: pulse 2.2s infinite;
}
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.3;transform:scale(.75);} }

/* headline */
.hero h1 {
  font-family: 'Playfair Display', serif;
  font-size: clamp(40px, 5.2vw, 62px);
  font-weight: 700; line-height: 1.12; margin-bottom: 24px; color: #fff;
  letter-spacing: -.5px;
}
.hero h1 .accent {
  background: linear-gradient(135deg, var(--gold), var(--gold2));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}
.hero h1 .line-wrap { display: block; overflow: hidden; }
.hero h1 .line-inner { display: block; transform: translateY(110%); animation: slideUp .8s cubic-bezier(.16,1,.3,1) forwards; }
.hero h1 .line-inner:nth-child(1) { animation-delay: .2s; }
.hero h1 .line-inner:nth-child(2) { animation-delay: .4s; }
@keyframes slideUp { to { transform: translateY(0); } }

.hero-sub {
  font-size: 16px; color: var(--muted); line-height: 1.78;
  margin-bottom: 40px; max-width: 490px;
  opacity: 0; animation: fadeUp .7s .6s forwards;
}
@keyframes fadeUp { from{opacity:0;transform:translateY(16px);} to{opacity:1;transform:translateY(0);} }

/* hero CTA buttons */
.hero-btns {
  display: flex; align-items: center; gap: 14px; margin-bottom: 52px;
  opacity: 0; animation: fadeUp .7s .75s forwards;
}
.btn-primary {
  display: inline-flex; align-items: center; gap: 9px;
  padding: 13px 28px;
  background: linear-gradient(135deg, var(--gold), #a07a18);
  color: #0a0b0f; border-radius: 8px;
  font-size: 14px; font-weight: 700;
  text-decoration: none; transition: .25s;
  box-shadow: 0 6px 22px rgba(201,162,39,0.35);
  letter-spacing: .2px;
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 32px rgba(201,162,39,0.5); }
.btn-ghost {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 13px 24px;
  background: rgba(255,255,255,0.05);
  border: 1px solid var(--border2);
  color: var(--text); border-radius: 8px;
  font-size: 14px; font-weight: 500;
  text-decoration: none; transition: .25s;
}
.btn-ghost:hover { background: rgba(255,255,255,0.09); border-color: rgba(255,255,255,0.22); }

/* STATS */
.stats-row {
  display: flex; gap: 0; margin-bottom: 44px;
  padding: 24px 28px;
  background: rgba(255,255,255,0.03);
  border: 1px solid var(--border);
  border-radius: 14px;
  opacity: 0; animation: fadeUp .7s .85s forwards;
  backdrop-filter: blur(12px);
}
.stat {
  flex: 1; text-align: center; position: relative;
}
.stat + .stat::before {
  content: '';
  position: absolute; left: 0; top: 10%; bottom: 10%;
  width: 1px; background: var(--border);
}
.stat .num {
  font-family: 'Playfair Display', serif;
  font-size: 32px; font-weight: 700; line-height: 1;
  background: linear-gradient(135deg, var(--gold), var(--gold2));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}
.stat .lbl { font-size: 11px; color: var(--muted); margin-top: 5px; letter-spacing: .5px; }

/* ROLE CARDS */
.roles-label {
  font-size: 11px; text-transform: uppercase; letter-spacing: 2.5px;
  color: var(--muted); font-weight: 500; margin-bottom: 14px;
  opacity: 0; animation: fadeUp .7s .95s forwards;
}
.role-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
  opacity: 0; animation: fadeUp .7s 1.05s forwards;
}
.role-card {
  background: rgba(255,255,255,0.04);
  border: 1px solid var(--border);
  border-radius: 12px; padding: 16px 18px;
  display: flex; align-items: center; gap: 14px;
  text-decoration: none; color: inherit;
  transition: transform .2s, background .2s, border-color .2s, box-shadow .2s;
  position: relative; overflow: hidden;
}
.role-card::before {
  content: ''; position: absolute; inset: 0;
  background: radial-gradient(circle at 50% 0%, rgba(201,162,39,0.08) 0%, transparent 70%);
  opacity: 0; transition: opacity .3s;
}
.role-card:hover::before { opacity: 1; }
.role-card:hover {
  background: rgba(255,255,255,0.07);
  border-color: rgba(201,162,39,0.4);
  transform: translateY(-3px);
  box-shadow: 0 12px 30px rgba(0,0,0,0.45);
}
.role-icon {
  width: 42px; height: 42px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 17px; flex-shrink: 0; transition: transform .25s;
}
.role-card:hover .role-icon { transform: scale(1.1) rotate(-3deg); }
.role-icon.admin   { background: rgba(201,162,39,.14); color: var(--gold); }
.role-icon.waiter  { background: rgba(0,200,150,.11);  color: #00c896; }
.role-icon.cashier { background: rgba(106,180,255,.11);color: #6ab4ff; }
.role-icon.kitchen { background: rgba(255,128,96,.11); color: #ff8060; }
.role-arrow { margin-left: auto; color: var(--muted); font-size: 11px; opacity: 0; transition: opacity .2s, transform .2s; }
.role-card:hover .role-arrow { opacity: 1; transform: translateX(3px); }
.role-info .rn { font-size: 13px; font-weight: 600; }
.role-info .rd { font-size: 11px; color: var(--muted); margin-top: 2px; }

/* ── SECTION COMMON ── */
.section {
  padding: 110px 64px;
  position: relative;
}
.section-tag {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: 11px; font-weight: 600; color: var(--gold);
  letter-spacing: 2.5px; text-transform: uppercase; margin-bottom: 16px;
}
.section-tag::before {
  content: ''; width: 28px; height: 1px; background: var(--gold);
}
.section h2 {
  font-family: 'Playfair Display', serif;
  font-size: clamp(28px, 3.5vw, 42px); font-weight: 600;
  color: #fff; margin-bottom: 16px; line-height: 1.2;
}
.section .sub {
  color: var(--muted); font-size: 15px;
  max-width: 500px; line-height: 1.75; margin-bottom: 64px;
}

/* ── FEATURES ── */
#features { background: var(--dark2); }

.feat-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1px;
  background: var(--border);
  border: 1px solid var(--border);
  border-radius: 16px;
  overflow: hidden;
}
.feat-card {
  background: var(--dark2);
  padding: 36px 32px;
  transition: background .25s;
  position: relative;
  overflow: hidden;
}
.feat-card::after {
  content: ''; position: absolute;
  bottom: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, var(--gold), transparent);
  transform: scaleX(0); transform-origin: left;
  transition: transform .35s;
}
.feat-card:hover { background: rgba(25,26,34,1); }
.feat-card:hover::after { transform: scaleX(1); }
.feat-icon {
  width: 50px; height: 50px; border-radius: 12px;
  background: var(--gold3);
  border: 1px solid rgba(201,162,39,0.18);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; color: var(--gold); margin-bottom: 22px;
  transition: transform .25s, box-shadow .25s;
}
.feat-card:hover .feat-icon {
  transform: scale(1.05) translateY(-2px);
  box-shadow: 0 8px 24px rgba(201,162,39,0.2);
}
.feat-card h3 { font-size: 15px; font-weight: 600; margin-bottom: 10px; color: #fff; }
.feat-card p  { font-size: 13px; color: var(--muted); line-height: 1.72; }

/* ── ACCESS SECTION ── */
#access { position: relative; }

.access-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}
.access-card {
  background: rgba(255,255,255,0.03);
  border: 1px solid var(--border);
  border-radius: 16px; padding: 36px 28px;
  transition: .25s; position: relative; overflow: hidden;
}
.access-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  opacity: 0; transition: opacity .3s;
}
.access-card.admin::before   { background: linear-gradient(90deg,var(--gold),transparent); }
.access-card.waiter::before  { background: linear-gradient(90deg,#00c896,transparent); }
.access-card.cashier::before { background: linear-gradient(90deg,#6ab4ff,transparent); }
.access-card.kitchen::before { background: linear-gradient(90deg,#ff8060,transparent); }
.access-card:hover::before { opacity: 1; }
.access-card:hover { transform: translateY(-6px); box-shadow: 0 20px 50px rgba(0,0,0,0.4); border-color: var(--border2); }

.access-icon {
  width: 52px; height: 52px; border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; margin-bottom: 22px;
  transition: transform .25s;
}
.access-card:hover .access-icon { transform: scale(1.1) rotate(-4deg); }
.access-card.admin   .access-icon { background:rgba(201,162,39,.12); color:var(--gold); }
.access-card.waiter  .access-icon { background:rgba(0,200,150,.10);  color:#00c896; }
.access-card.cashier .access-icon { background:rgba(106,180,255,.10);color:#6ab4ff; }
.access-card.kitchen .access-icon { background:rgba(255,128,96,.10); color:#ff8060; }

.access-card h3 { font-size: 16px; font-weight: 600; margin-bottom: 10px; }
.access-card.admin   h3 { color:var(--gold); }
.access-card.waiter  h3 { color:#00c896; }
.access-card.cashier h3 { color:#6ab4ff; }
.access-card.kitchen h3 { color:#ff8060; }
.access-card p { font-size: 13px; color: var(--muted); line-height: 1.7; margin-bottom: 20px; }

.access-badge {
  display: inline-block; padding: 3px 10px;
  border-radius: 20px; font-size: 10px; font-weight: 600;
  letter-spacing: 1px; text-transform: uppercase;
}
.access-card.admin   .access-badge { background:rgba(201,162,39,.12); color:var(--gold); }
.access-card.waiter  .access-badge { background:rgba(0,200,150,.10);  color:#00c896; }
.access-card.cashier .access-badge { background:rgba(106,180,255,.10);color:#6ab4ff; }
.access-card.kitchen .access-badge { background:rgba(255,128,96,.10); color:#ff8060; }

/* ── CTA BANNER ── */
.cta-section {
  padding: 90px 64px;
  background: var(--dark2);
  text-align: center;
  position: relative; overflow: hidden;
}
.cta-section::before {
  content: '';
  position: absolute; top: -1px; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
}
.cta-section::after {
  content: '';
  position: absolute; bottom: -1px; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
}
.cta-glow {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%,-50%);
  width: 600px; height: 200px;
  background: radial-gradient(ellipse, rgba(201,162,39,0.08) 0%, transparent 70%);
  pointer-events: none;
}
.cta-section .section-tag { justify-content: center; }
.cta-section .section-tag::before { display: none; }
.cta-section h2 { max-width: 500px; margin: 0 auto 16px; }
.cta-section .sub { margin: 0 auto 36px; }
.cta-btns { display: flex; align-items: center; justify-content: center; gap: 14px; }

/* ── FOOTER ── */
footer {
  position: relative; z-index: 1;
  padding: 28px 64px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  font-size: 13px; color: var(--muted);
}
.footer-logo {
  display: flex; align-items: center; gap: 9px;
  color: var(--gold); font-weight: 600;
  font-family: 'Playfair Display', serif;
  font-size: 16px; text-decoration: none;
}

/* ── REVEAL ── */
.reveal {
  opacity: 0; transform: translateY(28px);
  transition: opacity .7s ease, transform .7s ease;
}
.reveal.active { opacity: 1; transform: translateY(0); }
.reveal.delay-1 { transition-delay: .1s; }
.reveal.delay-2 { transition-delay: .2s; }
.reveal.delay-3 { transition-delay: .3s; }
.reveal.delay-4 { transition-delay: .4s; }

/* ── RESPONSIVE ── */
@media (max-width: 1100px) {
  .feat-grid { grid-template-columns: repeat(2,1fr); }
  .access-grid { grid-template-columns: repeat(2,1fr); }
}
@media (max-width: 768px) {
  nav { padding: 0 24px; }
  .nav-links { display: none; }
  .hero { padding: 110px 24px 80px; }
  .stats-row { padding: 18px; gap: 0; }
  .role-grid { grid-template-columns: 1fr; }
  .section { padding: 70px 24px; }
  .feat-grid { grid-template-columns: 1fr; }
  .access-grid { grid-template-columns: 1fr 1fr; }
  .cta-section { padding: 70px 24px; }
  footer { padding: 22px 24px; flex-direction: column; gap: 10px; text-align: center; }
}
@media (max-width: 480px) {
  .hero-btns { flex-direction: column; align-items: flex-start; }
  .access-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- SCROLL PROGRESS -->
<div id="progress-bar"></div>

<!-- BACKGROUND -->
<div class="bg-wrap">
  <div class="bg-image" id="bgImg"></div>
  <div class="bg-overlay"></div>
  <div class="orb orb1"></div>
  <div class="orb orb2"></div>
  <div class="orb orb3"></div>
</div>

<!-- NAVBAR -->
<nav id="navbar">
  <a href="#" class="nav-logo">
    <div class="logo-icon"><i class="fa-solid fa-utensils"></i></div>
    RestaurantMS
  </a>
  <div class="nav-links">
    <a href="#features">Features</a>
    <a href="#access">Roles</a>
    <a href="reserve.php">Reservations</a>
  </div>
  <div class="nav-right">
    <a href="reserve.php" class="nav-reserve">Book a Table</a>
    <a href="auth/login.php" class="nav-cta">Staff Login</a>
  </div>
</nav>

<main>

<!-- ── HERO ── -->
<section class="hero">
  <div class="hero-content">

    <div class="hero-eyebrow">
      <span class="pulse-dot"></span>
      Restaurant Management Platform
    </div>

    <h1>
      <span class="line-wrap"><span class="line-inner">Run Your Restaurant</span></span>
      <span class="line-wrap"><span class="line-inner">with <span class="accent">Precision</span></span></span>
    </h1>

    <p class="hero-sub">
      One integrated platform for orders, kitchen workflow, billing,
      reservations and real-time analytics — built for every role in your team.
    </p>

    <div class="hero-btns">
      <a href="reserve.php" class="btn-primary">
        <i class="fa-solid fa-calendar-plus"></i>
        Book a Table
      </a>
      <a href="#features" class="btn-ghost">
        <i class="fa-solid fa-play" style="font-size:10px;"></i>
        Explore Features
      </a>
    </div>

    <div class="stats-row">
      <div class="stat">
        <div class="num" data-target="4">0</div>
        <div class="lbl">Staff Roles</div>
      </div>
      <div class="stat">
        <div class="num" data-target="6">0</div>
        <div class="lbl">Core Modules</div>
      </div>
      <div class="stat">
        <div class="num" data-target="100" data-suffix="%">0</div>
        <div class="lbl">Real-time</div>
      </div>
    </div>

    <div class="roles-label">Select your role to access the system</div>
    <div class="role-grid">

      <a href="auth/login.php?role=admin" class="role-card">
        <div class="role-icon admin"><i class="fa-solid fa-chart-line"></i></div>
        <div class="role-info">
          <div class="rn">Admin</div>
          <div class="rd">Analytics &amp; full control</div>
        </div>
        <i class="fa-solid fa-arrow-right role-arrow"></i>
      </a>

      <a href="auth/login.php?role=waiter" class="role-card">
        <div class="role-icon waiter"><i class="fa-solid fa-clipboard-list"></i></div>
        <div class="role-info">
          <div class="rn">Waiter</div>
          <div class="rd">Orders &amp; table management</div>
        </div>
        <i class="fa-solid fa-arrow-right role-arrow"></i>
      </a>

      <a href="auth/login.php?role=cashier" class="role-card">
        <div class="role-icon cashier"><i class="fa-solid fa-cash-register"></i></div>
        <div class="role-info">
          <div class="rn">Cashier</div>
          <div class="rd">Billing &amp; payments</div>
        </div>
        <i class="fa-solid fa-arrow-right role-arrow"></i>
      </a>

      <a href="auth/login.php?role=kitchen" class="role-card">
        <div class="role-icon kitchen"><i class="fa-solid fa-kitchen-set"></i></div>
        <div class="role-info">
          <div class="rn">Kitchen</div>
          <div class="rd">Order queue &amp; prep status</div>
        </div>
        <i class="fa-solid fa-arrow-right role-arrow"></i>
      </a>

    </div>
  </div>
</section>

<!-- ── FEATURES ── -->
<section class="section" id="features">
  <div class="section-tag reveal">What's Included</div>
  <h2 class="reveal">Everything You Need</h2>
  <p class="sub reveal">A complete set of tools designed to streamline every aspect of restaurant operations.</p>

  <div class="feat-grid">

    <div class="feat-card reveal">
      <div class="feat-icon"><i class="fa-solid fa-clipboard-list"></i></div>
      <h3>Order Management</h3>
      <p>Waiters create and manage table orders in real time. Items are sent directly to the kitchen instantly.</p>
    </div>

    <div class="feat-card reveal delay-1">
      <div class="feat-icon"><i class="fa-solid fa-kitchen-set"></i></div>
      <h3>Kitchen Workflow</h3>
      <p>Kitchen staff see live order queues, mark items as ready, and keep service running at full speed.</p>
    </div>

    <div class="feat-card reveal delay-2">
      <div class="feat-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
      <h3>Billing &amp; Receipts</h3>
      <p>Cashiers generate accurate bills with one click and produce printable PDF receipts for customers.</p>
    </div>

    <div class="feat-card reveal">
      <div class="feat-icon"><i class="fa-solid fa-calendar-check"></i></div>
      <h3>Table Reservations</h3>
      <p>Customers book tables online, receive confirmation emails, and can cancel via a secure token link.</p>
    </div>

    <div class="feat-card reveal delay-1">
      <div class="feat-icon"><i class="fa-solid fa-chart-bar"></i></div>
      <h3>Sales Analytics</h3>
      <p>Admins access daily and monthly sales reports, popular dish tracking, and revenue charts.</p>
    </div>

    <div class="feat-card reveal delay-2">
      <div class="feat-icon"><i class="fa-solid fa-table-cells-large"></i></div>
      <h3>Reservation Timeline</h3>
      <p>Visual drag-and-drop reservation board with live table assignment, walk-in booking, and undo support.</p>
    </div>

  </div>
</section>

<!-- ── ROLES ── -->
<section class="section" id="access">
  <div class="section-tag reveal">Role-Based Access</div>
  <h2 class="reveal">The Right Tools for Every Role</h2>
  <p class="sub reveal">Each staff member gets a purpose-built interface with only what they need — nothing more.</p>

  <div class="access-grid">

    <div class="access-card admin reveal">
      <div class="access-icon"><i class="fa-solid fa-user-shield"></i></div>
      <h3>Admin</h3>
      <p>Full access — reservations, analytics, staff oversight, and complete system control.</p>
      <span class="access-badge">Full Access</span>
    </div>

    <div class="access-card waiter reveal delay-1">
      <div class="access-icon"><i class="fa-solid fa-person-walking"></i></div>
      <h3>Waiter</h3>
      <p>Table orders, order editing, reservation viewing, and real-time kitchen communication.</p>
      <span class="access-badge">Operations</span>
    </div>

    <div class="access-card cashier reveal delay-2">
      <div class="access-icon"><i class="fa-solid fa-coins"></i></div>
      <h3>Cashier</h3>
      <p>Bill generation, payment processing, and PDF receipt printing for completed orders.</p>
      <span class="access-badge">Billing</span>
    </div>

    <div class="access-card kitchen reveal delay-3">
      <div class="access-icon"><i class="fa-solid fa-fire-burner"></i></div>
      <h3>Kitchen</h3>
      <p>Live order queue, item-level status updates, and reservation timeline visibility.</p>
      <span class="access-badge">Kitchen View</span>
    </div>

  </div>
</section>

<!-- ── CTA BANNER ── -->
<section class="cta-section">
  <div class="cta-glow"></div>
  <div class="section-tag reveal">Ready to Book?</div>
  <h2 class="reveal">Reserve Your Table Today</h2>
  <p class="sub reveal">
    Book a table in seconds — no account required. Get instant confirmation delivered to your inbox.
  </p>
  <div class="cta-btns reveal delay-1">
    <a href="reserve.php" class="btn-primary">
      <i class="fa-solid fa-calendar-plus"></i>
      Make a Reservation
    </a>
    <a href="auth/login.php" class="btn-ghost">
      <i class="fa-solid fa-right-to-bracket"></i>
      Staff Login
    </a>
  </div>
</section>

</main>

<!-- FOOTER -->
<footer>
  <a href="#" class="footer-logo">
    <i class="fa-solid fa-utensils"></i>
    RestaurantMS
  </a>
  <span>&copy; <?php echo date('Y'); ?> Restaurant Management System. All rights reserved.</span>
  <a href="reserve.php" style="color:var(--gold);text-decoration:none;font-weight:500;font-size:13px;">
    Make a Reservation &rarr;
  </a>
</footer>

<script>
/* ── SCROLL PROGRESS ── */
const bar = document.getElementById('progress-bar');
window.addEventListener('scroll', () => {
  const s = document.documentElement;
  const pct = (s.scrollTop / (s.scrollHeight - s.clientHeight)) * 100;
  bar.style.width = pct + '%';
});

/* ── NAVBAR SCROLL CLASS ── */
const nav = document.getElementById('navbar');
window.addEventListener('scroll', () => {
  nav.classList.toggle('scrolled', window.scrollY > 60);
});

/* ── PARALLAX BACKGROUND ── */
const bgImg = document.getElementById('bgImg');
window.addEventListener('scroll', () => {
  bgImg.style.transform = `scale(1.04) translateY(${window.scrollY * 0.04}px)`;
});

/* ── SCROLL REVEAL ── */
function revealCheck() {
  document.querySelectorAll('.reveal').forEach(el => {
    if (el.getBoundingClientRect().top < window.innerHeight - 80)
      el.classList.add('active');
  });
}
window.addEventListener('scroll', revealCheck);
window.addEventListener('load', revealCheck);

/* ── COUNTER ANIMATION ── */
function animateCounters() {
  document.querySelectorAll('.num[data-target]').forEach(el => {
    const target = parseInt(el.dataset.target);
    const suffix = el.dataset.suffix || '+';
    const dur = 1400, step = 16;
    const inc = target / (dur / step);
    let current = 0;
    const timer = setInterval(() => {
      current += inc;
      if (current >= target) { current = target; clearInterval(timer); }
      el.textContent = Math.floor(current) + suffix;
    }, step);
  });
}
window.addEventListener('load', () => setTimeout(animateCounters, 500));

/* ── ROLE CARD 3D TILT ── */
document.querySelectorAll('.role-card').forEach(card => {
  card.addEventListener('mousemove', e => {
    const r = card.getBoundingClientRect();
    const x = (e.clientX - r.left) / r.width  - 0.5;
    const y = (e.clientY - r.top)  / r.height - 0.5;
    card.style.transform = `translateY(-3px) rotateX(${-y*6}deg) rotateY(${x*6}deg)`;
  });
  card.addEventListener('mouseleave', () => {
    card.style.transform = '';
  });
});

/* ── ACCESS CARD TILT ── */
document.querySelectorAll('.access-card').forEach(card => {
  card.addEventListener('mousemove', e => {
    const r = card.getBoundingClientRect();
    const x = (e.clientX - r.left) / r.width  - 0.5;
    const y = (e.clientY - r.top)  / r.height - 0.5;
    card.style.transform = `translateY(-6px) rotateX(${-y*5}deg) rotateY(${x*5}deg)`;
  });
  card.addEventListener('mouseleave', () => {
    card.style.transform = '';
  });
});
</script>

</body>
</html>
