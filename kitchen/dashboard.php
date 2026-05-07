<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'kitchen') {
    header("Location: ../auth/login.php"); exit();
}
$uid = (int)$_SESSION['user']['id'];

/* ══════════════════════════════════════════
   AJAX HANDLERS
══════════════════════════════════════════ */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'accept' && isset($_POST['order_id'])) {
        $oid     = (int)$_POST['order_id'];
        $has_csa = $conn->query("SHOW COLUMNS FROM orders LIKE 'cooking_started_at'")->num_rows > 0;
        if ($has_csa) {
            $conn->query("UPDATE orders SET status='preparing', cooking_started_at=NOW() WHERE id=$oid AND status='pending'");
        } else {
            $conn->query("UPDATE orders SET status='preparing' WHERE id=$oid AND status='pending'");
        }
        $conn->query("UPDATE order_items SET item_status='preparing' WHERE order_id=$oid AND item_status='pending'");
        echo json_encode(['success' => true]);
        exit();
    }

    if ($_GET['action'] === 'item_toggle' && isset($_POST['item_id'], $_POST['order_id'])) {
        $iid  = (int)$_POST['item_id'];
        $oid  = (int)$_POST['order_id'];
        $item = $conn->query("SELECT item_status FROM order_items WHERE id=$iid AND order_id=$oid")->fetch_assoc();
        if (!$item) { echo json_encode(['success' => false, 'error' => 'Item not found']); exit(); }
        $new_status = ($item['item_status'] === 'ready') ? 'preparing' : 'ready';
        $conn->query("UPDATE order_items SET item_status='$new_status' WHERE id=$iid");
        $totals    = $conn->query("SELECT COUNT(*) AS total, SUM(item_status='ready') AS ready FROM order_items WHERE order_id=$oid")->fetch_assoc();
        $total     = (int)$totals['total'];
        $ready     = (int)$totals['ready'];
        $all_ready = ($total > 0 && $ready === $total);
        if ($all_ready) {
            $conn->query("UPDATE orders SET status='ready' WHERE id=$oid");
            $has_bell = $conn->query("SHOW COLUMNS FROM orders LIKE 'bell_rung_at'")->num_rows > 0;
            if ($has_bell) $conn->query("UPDATE orders SET bell_rung_at=NOW() WHERE id=$oid");
        } elseif ($new_status === 'preparing') {
            $conn->query("UPDATE orders SET status='preparing' WHERE id=$oid AND status='ready'");
        }
        echo json_encode(['success' => true, 'item_status' => $new_status, 'ready' => $ready, 'total' => $total, 'all_ready' => $all_ready]);
        exit();
    }

    if ($_GET['action'] === 'order_ready' && isset($_POST['order_id'])) {
        $oid = (int)$_POST['order_id'];
        $conn->query("UPDATE order_items SET item_status='ready' WHERE order_id=$oid");
        $conn->query("UPDATE orders SET status='ready' WHERE id=$oid");
        $has_bell = $conn->query("SHOW COLUMNS FROM orders LIKE 'bell_rung_at'")->num_rows > 0;
        if ($has_bell) $conn->query("UPDATE orders SET bell_rung_at=NOW() WHERE id=$oid");
        echo json_encode(['success' => true]);
        exit();
    }

    if ($_GET['action'] === 'get_orders') {
        $has_notes = $conn->query("SHOW COLUMNS FROM orders LIKE 'notes'")->num_rows > 0;
        $has_otype = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_type'")->num_rows > 0;
        $extra     = ($has_otype ? ', o.order_type' : '') . ($has_notes ? ', o.notes' : '');
        $q = $conn->query("
            SELECT o.id, o.order_number, o.created_at, o.status $extra,
                   t.table_number, u.username AS waiter
            FROM orders o
            LEFT JOIN `tables` t ON o.table_id = t.id
            LEFT JOIN users    u ON o.waiter_id = u.id
            WHERE o.status IN ('pending','preparing','ready')
            ORDER BY o.created_at ASC
        ");
        $orders = [];
        while ($row = $q->fetch_assoc()) {
            $iq = $conn->query("
                SELECT oi.id, oi.quantity, oi.item_status, mi.name
                FROM order_items oi
                JOIN menu_items mi ON oi.menu_item_id = mi.id
                WHERE oi.order_id = {$row['id']}
                ORDER BY oi.id ASC
            ");
            $row['items'] = [];
            while ($item = $iq->fetch_assoc()) $row['items'][] = $item;
            $orders[] = $row;
        }
        echo json_encode(['orders' => $orders, 'ts' => time()]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit();
}

/* ══════════════════════════════════════════
   PAGE DATA
══════════════════════════════════════════ */
$has_csa   = $conn->query("SHOW COLUMNS FROM orders LIKE 'cooking_started_at'")->num_rows > 0;
$has_otype = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_type'")->num_rows > 0;
$has_notes = $conn->query("SHOW COLUMNS FROM orders LIKE 'notes'")->num_rows > 0;

$extra_sel = '';
if ($has_otype) $extra_sel .= ', o.order_type';
if ($has_notes) $extra_sel .= ', o.notes';

$orders_q = $conn->query("
    SELECT o.id, o.order_number, o.created_at, o.status, o.total_amount
           $extra_sel,
           t.table_number,
           u.username AS waiter,
           COUNT(oi.id)                AS total_items,
           SUM(oi.item_status='ready') AS ready_items
    FROM orders o
    LEFT JOIN `tables` t       ON o.table_id  = t.id
    LEFT JOIN users u          ON o.waiter_id = u.id
    LEFT JOIN order_items oi   ON oi.order_id = o.id
    WHERE o.status IN ('pending','preparing','ready')
    GROUP BY o.id
    ORDER BY o.created_at ASC
");
$orders = [];
while ($row = $orders_q->fetch_assoc()) {
    $iq = $conn->query("
        SELECT oi.id, oi.quantity, oi.item_status, mi.name
        FROM order_items oi
        JOIN menu_items mi ON oi.menu_item_id = mi.id
        WHERE oi.order_id = {$row['id']}
        ORDER BY oi.id ASC
    ");
    $row['items'] = [];
    while ($item = $iq->fetch_assoc()) $row['items'][] = $item;
    $orders[] = $row;
}
$cols = ['pending' => [], 'preparing' => [], 'ready' => []];
foreach ($orders as $o) {
    if (isset($cols[$o['status']])) $cols[$o['status']][] = $o;
}

$today         = date('Y-m-d');
$done_today    = (int)$conn->query("SELECT COUNT(*) AS v FROM orders WHERE status='completed' AND DATE(created_at)='$today'")->fetch_assoc()['v'];
$avg_wait      = 0;
if ($has_csa) {
    $ar = $conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, cooking_started_at)) AS v FROM orders WHERE status IN ('preparing','ready','completed') AND DATE(created_at)='$today' AND cooking_started_at IS NOT NULL")->fetch_assoc();
    $avg_wait = $ar ? round((float)$ar['v']) : 0;
}
$now_ts          = time();
$pending_count   = count($cols['pending']);
$preparing_count = count($cols['preparing']);
$ready_count     = count($cols['ready']);

$bookings_today = [];
$bq = $conn->query("
    SELECT b.customer_name, b.num_guests, b.status,
           TIME_FORMAT(b.booking_time,'%H:%i') AS booking_time,
           t.table_number
    FROM bookings b
    LEFT JOIN `tables` t ON b.assigned_table = t.id
    WHERE b.booking_date='$today' AND b.status NOT IN ('cancelled')
    ORDER BY b.booking_time ASC
");
if ($bq) while ($br = $bq->fetch_assoc()) $bookings_today[] = $br;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kitchen Display — RestaurantMS</title>
<?php $role_accent = '#f97316'; $role_accent2 = '#fb923c'; include("../config/staff_head.php"); ?>
<style>
/* ═══════════════════════════════════════
   KDS SHELL — fixed-height, no overflow
═══════════════════════════════════════ */
html, body {
  height: 100%;
  overflow: hidden;           /* KDS never scrolls at the page level */
  background: #080a0e;
}

.kds-root {
  display: flex;
  flex-direction: column;
  height: 100vh;
  overflow: hidden;
}

/* ── Topbar: reuse staff_head .topbar ── */
/* no overrides needed — staff_head handles it */

/* ─── Stat Strip ─── */
.kds-stats {
  display: flex;
  align-items: stretch;
  background: #0c0e14;
  border-bottom: 1px solid rgba(255,255,255,.06);
  flex-shrink: 0;
}
.kds-stat {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 20px;
  border-right: 1px solid rgba(255,255,255,.04);
}
.kds-stat:last-child { border-right: none; }
.kds-stat-icon {
  width: 36px; height: 36px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.kds-stat-val {
  font-size: 22px;
  font-weight: 800;
  line-height: 1;
  letter-spacing: -.5px;
  font-variant-numeric: tabular-nums;
}
.kds-stat-lbl {
  font-size: 10px;
  color: rgba(255,255,255,.3);
  margin-top: 2px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .07em;
}

/* ─── Tab bar ─── */
.kds-tabs {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 7px 14px;
  background: #0c0e14;
  border-bottom: 1px solid rgba(255,255,255,.06);
  flex-shrink: 0;
}
.kds-tab {
  display: flex; align-items: center; gap: 7px;
  padding: 6px 14px;
  border-radius: 8px;
  border: 1px solid transparent;
  background: none;
  color: rgba(255,255,255,.35);
  font-family: inherit; font-size: 12.5px; font-weight: 700;
  cursor: pointer; transition: .15s;
}
.kds-tab:hover { color: rgba(255,255,255,.7); background: rgba(255,255,255,.04); }
.kds-tab.active { color: #fff; background: rgba(255,255,255,.07); border-color: rgba(255,255,255,.1); }
.kds-tab-badge {
  padding: 1px 7px; border-radius: 20px;
  font-size: 9.5px; font-weight: 800;
  background: #f97316; color: #000;
  line-height: 1.6;
}
.kds-tab-right { margin-left: auto; }
.kds-poll-lbl  { font-size: 10.5px; color: rgba(255,255,255,.2); }

/* ─── Content area — fills remaining viewport height ─── */
.kds-content {
  flex: 1;
  min-height: 0;           /* CRITICAL — allow flex child to shrink */
  overflow: hidden;
  position: relative;
}

/* ─── Panes ─── */
.kds-pane {
  display: none;
  height: 100%;
  width: 100%;
}
.kds-pane.active { display: flex; flex-direction: column; }

/* ─── Kanban board ─────────────────────────────────────
   Uses CSS grid. Each column is a flex column.
   .kds-cards scrolls independently — cards NEVER overlap.
───────────────────────────────────────────────────────── */
.kds-board {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  height: 100%;             /* fills the pane */
  min-height: 0;
  overflow: hidden;
}

.kds-col {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;            /* allow shrink */
  border-right: 1px solid rgba(255,255,255,.05);
  background: #090b10;
}
.kds-col:last-child { border-right: none; }

/* Column header — fixed, never scrolls */
.kds-col-head {
  display: flex; align-items: center; gap: 9px;
  padding: 13px 16px;
  background: #0c0f16;
  border-bottom: 2px solid;
  flex-shrink: 0;
}
.col-new  .kds-col-head { border-bottom-color: #f97316; }
.col-cook .kds-col-head { border-bottom-color: #818cf8; }
.col-done .kds-col-head { border-bottom-color: #22c55e; }

.col-head-dot {
  width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0;
}
.col-new  .col-head-dot { background: #f97316; box-shadow: 0 0 8px rgba(249,115,22,.6); }
.col-cook .col-head-dot { background: #818cf8; box-shadow: 0 0 8px rgba(129,140,248,.5); }
.col-done .col-head-dot { background: #22c55e; box-shadow: 0 0 8px rgba(34,197,94,.5); }

.col-head-title {
  font-size: 12px; font-weight: 800;
  text-transform: uppercase; letter-spacing: .09em;
}
.col-new  .col-head-title { color: #f97316; }
.col-cook .col-head-title { color: #818cf8; }
.col-done .col-head-title { color: #22c55e; }

.col-head-count {
  margin-left: auto;
  font-size: 11px; font-weight: 700;
  padding: 2px 10px; border-radius: 20px;
}
.col-new  .col-head-count { background: rgba(249,115,22,.12);  color: #f97316; }
.col-cook .col-head-count { background: rgba(129,140,248,.12); color: #818cf8; }
.col-done .col-head-count { background: rgba(34,197,94,.12);   color: #22c55e; }

/* ─── Card scroll container ────────────────────────────
   flex: 1 + min-height: 0 = fills remaining column height.
   overflow-y: auto = scrollbar appears when cards overflow.
   Cards stack vertically and scroll — zero overlap.
───────────────────────────────────────────────────────── */
.kds-cards {
  flex: 1;
  min-height: 0;            /* CRITICAL — without this, cards push outside */
  overflow-y: auto;
  overflow-x: hidden;
  padding: 12px 10px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,.08) transparent;
}
.kds-cards::-webkit-scrollbar { width: 4px; }
.kds-cards::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 4px; }
.kds-cards::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.18); }

/* ─── Empty state ─── */
.kds-empty {
  flex: 1;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 10px;
  color: rgba(255,255,255,.1);
  font-size: 12px; font-weight: 600;
  text-align: center;
  padding: 48px 20px;
  pointer-events: none;
  user-select: none;
}
.kds-empty i { font-size: 32px; opacity: .5; }

/* ─── Ticket card ─── */
@keyframes cardIn {
  from { opacity: 0; transform: translateY(-8px) scale(.97); }
  to   { opacity: 1; transform: none; }
}
.ticket {
  background: #0f1118;
  border: 1px solid rgba(255,255,255,.07);
  border-radius: 12px;
  border-top-width: 3px;
  overflow: hidden;
  transition: border-color .2s, box-shadow .2s;
  flex-shrink: 0;           /* CRITICAL — card must not shrink to fit column */
}
.ticket.new-in { animation: cardIn .3s cubic-bezier(.22,1,.36,1); }
.ticket:hover  { border-color: rgba(255,255,255,.14); box-shadow: 0 6px 28px rgba(0,0,0,.5); }

/* Urgency top border */
.ticket.urg-ok   { border-top-color: #22c55e; }
.ticket.urg-warn { border-top-color: #f59e0b; }
.ticket.urg-hot  { border-top-color: #ef4444; }
.ticket.urg-done { border-top-color: #22c55e; opacity: .85; }

/* ─── Ticket: header ─── */
.tk-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  padding: 12px 14px 8px;
  gap: 8px;
}
.tk-table {
  font-size: 28px; font-weight: 900;
  color: #fff; line-height: 1;
  letter-spacing: -.6px;
}
.tk-order-num {
  font-size: 10.5px; color: rgba(255,255,255,.25);
  margin-top: 3px; font-weight: 600;
}
.tk-timer-wrap { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.tk-timer {
  font-size: 14px; font-weight: 800;
  font-variant-numeric: tabular-nums;
  padding: 3px 10px; border-radius: 20px;
  letter-spacing: .03em;
}
.tk-timer.t-ok   { background: rgba(34,197,94,.1);  color: #22c55e; }
.tk-timer.t-warn { background: rgba(245,158,11,.1); color: #f59e0b; }
.tk-timer.t-hot  { background: rgba(239,68,68,.12); color: #ef4444; animation: timerPulse .9s ease infinite; }
@keyframes timerPulse { 0%,100%{opacity:1} 50%{opacity:.45} }

.tk-waiter {
  font-size: 9.5px; color: rgba(255,255,255,.2);
  font-weight: 600; letter-spacing: .02em;
}

/* ─── Progress bar ─── */
.tk-prog { padding: 0 14px 9px; }
.tk-prog-track {
  height: 5px; background: rgba(255,255,255,.05);
  border-radius: 3px; overflow: hidden;
}
.tk-prog-fill {
  height: 100%; border-radius: 3px;
  background: linear-gradient(90deg, #818cf8, #a78bfa);
  transition: width .35s ease;
}
.tk-prog-label {
  font-size: 9.5px; color: rgba(255,255,255,.25);
  margin-top: 4px; font-weight: 600;
}

/* ─── Notes banner ─── */
.tk-notes {
  margin: 0 14px 10px;
  padding: 7px 10px;
  background: rgba(245,158,11,.07);
  border: 1px solid rgba(245,158,11,.2);
  border-radius: 7px;
  font-size: 11.5px; color: #d97706; line-height: 1.4;
  display: flex; align-items: flex-start; gap: 7px;
}
.tk-notes i { margin-top: 1px; flex-shrink: 0; font-size: 10px; }

/* ─── Item list ─── */
.tk-items { padding: 0 14px 10px; display: flex; flex-direction: column; gap: 3px; }
.tk-item {
  display: flex; align-items: center; gap: 10px;
  padding: 7px 9px; border-radius: 7px;
  cursor: pointer; transition: background .1s;
  user-select: none;
}
.tk-item:hover { background: rgba(255,255,255,.04); }
.tk-item.no-click { cursor: default; }
.tk-item.no-click:hover { background: none; }

.tk-check {
  width: 21px; height: 21px; border-radius: 6px;
  border: 2px solid rgba(255,255,255,.12); flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; transition: .15s;
}
.tk-item.i-ready    .tk-check { background: #22c55e; border-color: #22c55e; color: #000; }
.tk-item.i-preparing .tk-check { border-color: #818cf8; }

.tk-name { flex: 1; font-size: 13px; color: rgba(255,255,255,.8); font-weight: 500; line-height: 1.3; }
.tk-item.i-ready .tk-name { color: rgba(255,255,255,.22); text-decoration: line-through; }

.tk-qty {
  font-size: 12px; font-weight: 800; color: #fff;
  background: rgba(255,255,255,.07); border-radius: 5px;
  padding: 2px 8px; min-width: 28px; text-align: center; flex-shrink: 0;
}
.tk-item.i-ready .tk-qty { background: rgba(255,255,255,.03); color: rgba(255,255,255,.2); }

/* ─── Divider ─── */
.tk-divider { height: 1px; background: rgba(255,255,255,.05); margin: 0 14px; }

/* ─── Footer actions ─── */
.tk-foot { padding: 10px 14px 12px; display: flex; gap: 8px; }
.tk-btn {
  flex: 1; display: flex; align-items: center; justify-content: center; gap: 7px;
  padding: 9px 14px; border-radius: 8px;
  border: 1px solid; font-family: inherit; font-size: 12px; font-weight: 700;
  cursor: pointer; transition: .15s; letter-spacing: .01em;
}
.tk-btn:disabled { opacity: .35; cursor: not-allowed; }
.tk-btn-start {
  background: rgba(249,115,22,.1); border-color: rgba(249,115,22,.3); color: #f97316;
}
.tk-btn-start:hover:not(:disabled) { background: rgba(249,115,22,.2); border-color: rgba(249,115,22,.5); }
.tk-btn-done {
  background: rgba(34,197,94,.1); border-color: rgba(34,197,94,.3); color: #22c55e;
}
.tk-btn-done:hover:not(:disabled) { background: rgba(34,197,94,.18); border-color: rgba(34,197,94,.5); }

/* ─── Ready footer ─── */
.tk-ready-banner {
  display: flex; align-items: center; justify-content: center; gap: 7px;
  padding: 10px 14px 12px;
  font-size: 11.5px; font-weight: 700; color: #22c55e;
  letter-spacing: .02em;
}

/* ─── Bookings pane ─── */
.bk-pane {
  flex: 1; min-height: 0;
  overflow-y: auto;
  padding: 16px;
}
.bk-card {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 18px;
  background: #0f1118;
  border: 1px solid rgba(255,255,255,.07);
  border-radius: 12px;
  margin-bottom: 8px;
  transition: border-color .15s;
}
.bk-card:hover { border-color: rgba(255,255,255,.13); }
.bk-time  { font-size: 20px; font-weight: 800; min-width: 56px; }
.bk-info  { flex: 1; min-width: 0; }
.bk-name  { font-size: 14px; font-weight: 700; color: #e8e8f0; }
.bk-sub   { font-size: 11.5px; color: rgba(255,255,255,.3); margin-top: 2px; }
.bk-badge {
  font-size: 10.5px; font-weight: 700; padding: 3px 12px;
  border-radius: 20px; text-transform: capitalize;
}

/* ─── Responsive ─── */
@media (max-width: 900px) {
  html, body { overflow: auto; }
  .kds-root { height: auto; min-height: 100vh; overflow: visible; }
  .kds-content { overflow: visible; min-height: unset; }
  .kds-pane { height: auto; }
  .kds-pane.active { display: block; }
  .kds-board { display: flex; flex-direction: column; height: auto; }
  .kds-col { height: auto; min-height: unset; border-right: none; border-bottom: 1px solid rgba(255,255,255,.05); }
  .kds-cards { max-height: none; overflow-y: visible; }
  .kds-stats { flex-wrap: wrap; }
  .kds-stat { flex: 1 1 50%; }
}
@media (max-width: 480px) {
  .kds-stat { flex: 1 1 100%; }
  .tk-table { font-size: 22px; }
}
</style>
</head>
<body>
<div class="kds-root">

<!-- ══ TOP BAR ══ -->
<header class="topbar">
  <a href="dashboard.php" class="tb-brand">
    <div class="tb-icon"><i class="fa-solid fa-kitchen-set"></i></div>
    <div>
      <div class="tb-title">Kitchen Display</div>
    </div>
  </a>

  <div style="display:flex;align-items:center;gap:6px;margin-left:18px;">
    <span class="live-dot"></span>
    <span style="font-size:11px;font-weight:700;color:rgba(255,255,255,.3);letter-spacing:.05em;">LIVE</span>
  </div>

  <div class="tb-right">
    <span class="tb-clock" id="clock"></span>
    <button id="soundBtn" class="tb-btn on" onclick="toggleSound()">
      <i class="fa-solid fa-volume-high" id="snd-icon"></i>
      <span>Sound</span>
    </button>
    <button class="tb-btn" onclick="location.reload()" title="Refresh">
      <i class="fa-solid fa-rotate-right"></i>
    </button>
    <div class="tb-user">
      <div class="tb-avatar"><?php echo strtoupper(substr($_SESSION['user']['username'], 0, 1)); ?></div>
      <div>
        <div class="tb-name"><?php echo htmlspecialchars($_SESSION['user']['username']); ?></div>
        <div class="tb-role">Kitchen</div>
      </div>
    </div>
    <a href="../auth/logout.php" class="tb-btn danger">
      <i class="fa-solid fa-right-from-bracket"></i>
      <span>Logout</span>
    </a>
  </div>
</header>

<!-- ══ STAT STRIP ══ -->
<div class="kds-stats">
  <div class="kds-stat">
    <div class="kds-stat-icon" style="background:rgba(249,115,22,.1);color:#f97316;">
      <i class="fa-solid fa-hourglass-half"></i>
    </div>
    <div>
      <div class="kds-stat-val" style="color:#f97316;" id="cnt-pending"><?php echo $pending_count; ?></div>
      <div class="kds-stat-lbl">New</div>
    </div>
  </div>
  <div class="kds-stat">
    <div class="kds-stat-icon" style="background:rgba(129,140,248,.1);color:#818cf8;">
      <i class="fa-solid fa-fire-burner"></i>
    </div>
    <div>
      <div class="kds-stat-val" style="color:#818cf8;" id="cnt-cooking"><?php echo $preparing_count; ?></div>
      <div class="kds-stat-lbl">Cooking</div>
    </div>
  </div>
  <div class="kds-stat">
    <div class="kds-stat-icon" style="background:rgba(34,197,94,.1);color:#22c55e;">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div>
      <div class="kds-stat-val" style="color:#22c55e;" id="cnt-ready"><?php echo $ready_count; ?></div>
      <div class="kds-stat-lbl">Ready</div>
    </div>
  </div>
  <div class="kds-stat">
    <div class="kds-stat-icon" style="background:rgba(100,116,139,.1);color:#64748b;">
      <i class="fa-solid fa-circle-check"></i>
    </div>
    <div>
      <div class="kds-stat-val" style="color:#64748b;"><?php echo $done_today; ?></div>
      <div class="kds-stat-lbl">Done Today</div>
    </div>
  </div>
  <?php if ($avg_wait > 0): ?>
  <div class="kds-stat">
    <div class="kds-stat-icon" style="background:rgba(96,165,250,.08);color:#60a5fa;">
      <i class="fa-solid fa-stopwatch"></i>
    </div>
    <div>
      <div class="kds-stat-val" style="color:#60a5fa;font-size:18px;"><?php echo $avg_wait; ?>m</div>
      <div class="kds-stat-lbl">Avg Wait</div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ══ TAB BAR ══ -->
<div class="kds-tabs">
  <button class="kds-tab active" id="tab-queue" onclick="switchTab('queue')">
    <i class="fa-solid fa-layer-group"></i>
    Order Queue
    <?php if ($pending_count > 0): ?>
    <span class="kds-tab-badge"><?php echo $pending_count; ?></span>
    <?php endif; ?>
  </button>
  <?php if (!empty($bookings_today)): ?>
  <button class="kds-tab" id="tab-bookings" onclick="switchTab('bookings')">
    <i class="fa-solid fa-calendar-check"></i>
    Today's Bookings
    <span style="background:rgba(96,165,250,.18);color:#60a5fa;border-radius:20px;font-size:9.5px;font-weight:800;padding:2px 8px;"><?php echo count($bookings_today); ?></span>
  </button>
  <?php endif; ?>
  <div class="kds-tab-right">
    <span class="kds-poll-lbl" id="poll-status">Connecting…</span>
  </div>
</div>

<!-- ══ CONTENT ══ -->
<div class="kds-content">

<!-- ── ORDER QUEUE PANE ── -->
<div class="kds-pane active" id="pane-queue">
<div class="kds-board">

  <!-- NEW ORDERS column -->
  <div class="kds-col col-new">
    <div class="kds-col-head">
      <div class="col-head-dot"></div>
      <span class="col-head-title">New Orders</span>
      <span class="col-head-count" id="badge-pending"><?php echo $pending_count; ?></span>
    </div>
    <div class="kds-cards" id="col-pending">
    <?php if (empty($cols['pending'])): ?>
      <div class="kds-empty" id="empty-pending">
        <i class="fa-solid fa-circle-check" style="color:#22c55e;"></i>
        All clear
      </div>
    <?php else: ?>
      <?php foreach ($cols['pending'] as $o):
        $elapsed = $now_ts - strtotime($o['created_at']);
        $mins = (int)floor($elapsed / 60); $secs = $elapsed % 60;
        $urg  = $mins >= 20 ? 'urg-hot'  : ($mins >= 10 ? 'urg-warn'  : 'urg-ok');
        $tcls = $mins >= 20 ? 't-hot'    : ($mins >= 10 ? 't-warn'    : 't-ok');
      ?>
      <div class="ticket <?php echo $urg; ?>"
           id="card-<?php echo $o['id']; ?>"
           data-ts="<?php echo strtotime($o['created_at']); ?>"
           data-status="pending">
        <div class="tk-head">
          <div>
            <div class="tk-table">T<?php echo (int)($o['table_number'] ?? 0); ?></div>
            <div class="tk-order-num">
              <?php echo htmlspecialchars($o['order_number']); ?>
              <?php if (isset($o['order_type']) && $o['order_type'] === 'reservation'): ?>
                &middot; <span style="color:#60a5fa;">RSV</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="tk-timer-wrap">
            <span class="tk-timer <?php echo $tcls; ?>"
                  id="tmr-<?php echo $o['id']; ?>"
                  data-ts="<?php echo strtotime($o['created_at']); ?>">
              <?php echo sprintf('%02d:%02d', $mins, $secs); ?>
            </span>
            <span class="tk-waiter"><?php echo htmlspecialchars($o['waiter'] ?? '—'); ?></span>
          </div>
        </div>
        <?php if ($has_notes && !empty($o['notes'])): ?>
        <div class="tk-notes">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <?php echo htmlspecialchars($o['notes']); ?>
        </div>
        <?php endif; ?>
        <div class="tk-items">
          <?php foreach ($o['items'] as $item): ?>
          <div class="tk-item no-click i-<?php echo htmlspecialchars($item['item_status']); ?>"
               id="item-<?php echo $item['id']; ?>">
            <div class="tk-check"></div>
            <div class="tk-name"><?php echo htmlspecialchars($item['name']); ?></div>
            <div class="tk-qty">×<?php echo (int)$item['quantity']; ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="tk-divider"></div>
        <div class="tk-foot">
          <button class="tk-btn tk-btn-start" onclick="acceptOrder(<?php echo $o['id']; ?>, this)">
            <i class="fa-solid fa-fire-burner"></i> Start Cooking
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>
  </div>

  <!-- COOKING column -->
  <div class="kds-col col-cook">
    <div class="kds-col-head">
      <div class="col-head-dot"></div>
      <span class="col-head-title">Cooking</span>
      <span class="col-head-count" id="badge-cooking"><?php echo $preparing_count; ?></span>
    </div>
    <div class="kds-cards" id="col-cooking">
    <?php if (empty($cols['preparing'])): ?>
      <div class="kds-empty" id="empty-cooking">
        <i class="fa-solid fa-fire-burner" style="color:#818cf8;"></i>
        Nothing cooking
      </div>
    <?php else: ?>
      <?php foreach ($cols['preparing'] as $o):
        $elapsed = $now_ts - strtotime($o['created_at']);
        $mins = (int)floor($elapsed / 60); $secs = $elapsed % 60;
        $urg  = $mins >= 20 ? 'urg-hot'  : ($mins >= 10 ? 'urg-warn'  : 'urg-ok');
        $tcls = $mins >= 20 ? 't-hot'    : ($mins >= 10 ? 't-warn'    : 't-ok');
        $total_i = (int)$o['total_items'];
        $ready_i = (int)$o['ready_items'];
        $pct = $total_i > 0 ? round(($ready_i / $total_i) * 100) : 0;
      ?>
      <div class="ticket <?php echo $urg; ?>"
           id="card-<?php echo $o['id']; ?>"
           data-ts="<?php echo strtotime($o['created_at']); ?>"
           data-status="preparing">
        <div class="tk-head">
          <div>
            <div class="tk-table">T<?php echo (int)($o['table_number'] ?? 0); ?></div>
            <div class="tk-order-num"><?php echo htmlspecialchars($o['order_number']); ?></div>
          </div>
          <div class="tk-timer-wrap">
            <span class="tk-timer <?php echo $tcls; ?>"
                  id="tmr-<?php echo $o['id']; ?>"
                  data-ts="<?php echo strtotime($o['created_at']); ?>">
              <?php echo sprintf('%02d:%02d', $mins, $secs); ?>
            </span>
            <span class="tk-waiter"><?php echo htmlspecialchars($o['waiter'] ?? '—'); ?></span>
          </div>
        </div>
        <div class="tk-prog">
          <div class="tk-prog-track">
            <div class="tk-prog-fill" id="prog-<?php echo $o['id']; ?>" style="width:<?php echo $pct; ?>%;"></div>
          </div>
          <div class="tk-prog-label" id="meta-<?php echo $o['id']; ?>"><?php echo $ready_i; ?>/<?php echo $total_i; ?> items ready</div>
        </div>
        <?php if ($has_notes && !empty($o['notes'])): ?>
        <div class="tk-notes">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <?php echo htmlspecialchars($o['notes']); ?>
        </div>
        <?php endif; ?>
        <div class="tk-items">
          <?php foreach ($o['items'] as $item): ?>
          <div class="tk-item i-<?php echo htmlspecialchars($item['item_status']); ?>"
               id="item-<?php echo $item['id']; ?>"
               onclick="toggleItem(<?php echo $item['id']; ?>, <?php echo $o['id']; ?>)">
            <div class="tk-check">
              <?php if ($item['item_status'] === 'ready'): ?>
                <i class="fa-solid fa-check"></i>
              <?php elseif ($item['item_status'] === 'preparing'): ?>
                <i class="fa-solid fa-fire" style="font-size:8px;color:#818cf8;"></i>
              <?php endif; ?>
            </div>
            <div class="tk-name"><?php echo htmlspecialchars($item['name']); ?></div>
            <div class="tk-qty">×<?php echo (int)$item['quantity']; ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="tk-divider"></div>
        <div class="tk-foot">
          <button class="tk-btn tk-btn-done" onclick="markReady(<?php echo $o['id']; ?>, this)">
            <i class="fa-solid fa-bell"></i> Done — Notify Waiter
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>
  </div>

  <!-- READY column -->
  <div class="kds-col col-done">
    <div class="kds-col-head">
      <div class="col-head-dot"></div>
      <span class="col-head-title">Ready to Serve</span>
      <span class="col-head-count" id="badge-ready"><?php echo $ready_count; ?></span>
    </div>
    <div class="kds-cards" id="col-ready">
    <?php if (empty($cols['ready'])): ?>
      <div class="kds-empty" id="empty-ready">
        <i class="fa-solid fa-bell" style="color:#22c55e;"></i>
        Nothing ready yet
      </div>
    <?php else: ?>
      <?php foreach ($cols['ready'] as $o):
        $elapsed = $now_ts - strtotime($o['created_at']);
        $mins    = (int)floor($elapsed / 60);
      ?>
      <div class="ticket urg-done"
           id="card-<?php echo $o['id']; ?>"
           data-status="ready">
        <div class="tk-head">
          <div>
            <div class="tk-table">T<?php echo (int)($o['table_number'] ?? 0); ?></div>
            <div class="tk-order-num"><?php echo htmlspecialchars($o['order_number']); ?></div>
          </div>
          <div class="tk-timer-wrap">
            <span style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:rgba(34,197,94,.08);color:#22c55e;">
              <?php echo $mins; ?>m
            </span>
            <span class="tk-waiter"><?php echo htmlspecialchars($o['waiter'] ?? '—'); ?></span>
          </div>
        </div>
        <div class="tk-items">
          <?php foreach ($o['items'] as $item): ?>
          <div class="tk-item no-click i-ready">
            <div class="tk-check"><i class="fa-solid fa-check"></i></div>
            <div class="tk-name"><?php echo htmlspecialchars($item['name']); ?></div>
            <div class="tk-qty">×<?php echo (int)$item['quantity']; ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="tk-divider"></div>
        <div class="tk-ready-banner">
          <i class="fa-solid fa-bell"></i> Waiter notified — awaiting pickup
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>
  </div>

</div><!-- /kds-board -->
</div><!-- /pane-queue -->

<!-- ── BOOKINGS PANE ── -->
<?php if (!empty($bookings_today)): ?>
<div class="kds-pane" id="pane-bookings">
<div class="bk-pane">
  <?php
  $scmap = [
    'confirmed' => ['#22c55e','rgba(34,197,94,.1)', 'rgba(34,197,94,.2)'],
    'pending'   => ['#f59e0b','rgba(245,158,11,.1)','rgba(245,158,11,.2)'],
    'seated'    => ['#60a5fa','rgba(96,165,250,.1)', 'rgba(96,165,250,.2)'],
    'completed' => ['#555',   'rgba(100,116,139,.1)','rgba(100,116,139,.2)'],
  ];
  $now_time = date('H:i');
  foreach ($bookings_today as $bk):
    [$sc, $sbg, $sb] = $scmap[$bk['status']] ?? $scmap['pending'];
    $soon = $bk['booking_time'] > $now_time && $bk['booking_time'] <= date('H:i', strtotime('+60 minutes'));
  ?>
  <div class="bk-card" style="<?php if ($soon): ?>border-left:3px solid #f97316;<?php endif; ?>">
    <div class="bk-time" style="color:<?php echo $sc; ?>"><?php echo htmlspecialchars($bk['booking_time']); ?></div>
    <div class="bk-info">
      <div class="bk-name"><?php echo htmlspecialchars($bk['customer_name']); ?></div>
      <div class="bk-sub">
        <?php echo (int)$bk['num_guests']; ?> guests
        <?php if ($bk['table_number']): ?> &middot; Table <?php echo htmlspecialchars($bk['table_number']); ?><?php endif; ?>
        <?php if ($soon): ?> &middot; <span style="color:#f97316;font-weight:700;">Arriving soon</span><?php endif; ?>
      </div>
    </div>
    <div class="bk-badge" style="background:<?php echo $sbg; ?>;color:<?php echo $sc; ?>;border:1px solid <?php echo $sb; ?>;">
      <?php echo htmlspecialchars($bk['status']); ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
</div>
<?php endif; ?>

</div><!-- /kds-content -->
</div><!-- /kds-root -->

<div id="toast-wrap"></div>

<script>
/* ── Clock ── */
(function tick() {
    const n = new Date(), p = v => String(v).padStart(2,'0');
    const el = document.getElementById('clock');
    if (el) el.textContent = p(n.getHours()) + ':' + p(n.getMinutes()) + ':' + p(n.getSeconds());
    setTimeout(tick, 1000);
})();

/* ── Tab switch ── */
function switchTab(name) {
    document.querySelectorAll('.kds-tab').forEach(t => t.classList.toggle('active', t.id === 'tab-' + name));
    document.querySelectorAll('.kds-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + name));
}

/* ── Sound ── */
let audioCtx = null, soundOn = true;
try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e){}
document.addEventListener('click', function u() {
    if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
    document.removeEventListener('click', u);
}, { once: true });

function toggleSound() {
    soundOn = !soundOn;
    const btn = document.getElementById('soundBtn');
    const ico = document.getElementById('snd-icon');
    btn.classList.toggle('on', soundOn);
    if (ico) ico.className = soundOn ? 'fa-solid fa-volume-high' : 'fa-solid fa-volume-xmark';
    const lbl = btn.querySelector('span');
    if (lbl) lbl.textContent = soundOn ? 'Sound' : 'Muted';
    if (soundOn && audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
}

function _tone(freq, off, dur) {
    if (!soundOn || !audioCtx) return;
    try {
        if (audioCtx.state === 'suspended') audioCtx.resume();
        const osc = audioCtx.createOscillator();
        const g   = audioCtx.createGain();
        osc.connect(g); g.connect(audioCtx.destination);
        osc.type = 'sine'; osc.frequency.value = freq;
        const t = audioCtx.currentTime + off;
        g.gain.setValueAtTime(0, t);
        g.gain.linearRampToValueAtTime(0.35, t + 0.012);
        g.gain.exponentialRampToValueAtTime(0.001, t + dur);
        osc.start(t); osc.stop(t + dur + 0.05);
    } catch(e){}
}
function beep()    { _tone(880, 0, 0.4); }
function beepNew() { _tone(660,0,.45); _tone(880,.18,.42); _tone(1047,.36,.4); }

/* ── Toast ── */
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function toast(msg, type) {
    type = type || 'info';
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = '<i class="fa-solid ' + (icons[type]||icons.info) + '"></i><span>' + escHtml(msg) + '</span>';
    document.getElementById('toast-wrap').appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

/* ── Live timers ── */
function updateTimers() {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('.tk-timer[data-ts]').forEach(el => {
        const sec = now - parseInt(el.dataset.ts);
        const m = Math.floor(sec / 60), s = sec % 60;
        el.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        const card = el.closest('.ticket');
        if (!card || card.dataset.status === 'ready') return;
        const hot = m >= 20, warn = m >= 10;
        el.className = 'tk-timer ' + (hot ? 't-hot' : warn ? 't-warn' : 't-ok');
        card.className = card.className.replace(/\burg-\w+/g,'') + ' ' + (hot ? 'urg-hot' : warn ? 'urg-warn' : 'urg-ok');
    });
}
setInterval(updateTimers, 1000);

/* ── Accept order ── */
async function acceptOrder(oid, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Starting…';
    try {
        const r = await fetch('dashboard.php?action=accept', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'order_id='+oid
        });
        const d = await r.json();
        if (d.success) {
            moveCard(oid, 'cooking');
            updateBadges();
            toast('Cooking started!', 'success');
        } else {
            btn.disabled = false; btn.innerHTML = orig;
            toast('Error starting order', 'error');
        }
    } catch(e) { btn.disabled = false; btn.innerHTML = orig; toast('Network error', 'error'); }
}

/* ── Mark ready ── */
async function markReady(oid, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Notifying…';
    try {
        const r = await fetch('dashboard.php?action=order_ready', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'order_id='+oid
        });
        const d = await r.json();
        if (d.success) {
            moveCard(oid, 'ready');
            updateBadges();
            toast('Order ready — waiter notified!', 'success');
            beep();
        } else {
            btn.disabled = false; btn.innerHTML = orig;
            toast('Error marking ready', 'error');
        }
    } catch(e) { btn.disabled = false; btn.innerHTML = orig; toast('Network error', 'error'); }
}

/* ── Toggle item ── */
async function toggleItem(iid, oid) {
    const itemEl = document.getElementById('item-' + iid);
    if (!itemEl) return;
    try {
        const r = await fetch('dashboard.php?action=item_toggle', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'item_id='+iid+'&order_id='+oid
        });
        const d = await r.json();
        if (!d.success) return;
        itemEl.className = 'tk-item i-' + d.item_status;
        const chk = itemEl.querySelector('.tk-check');
        if (chk) {
            chk.innerHTML = d.item_status === 'ready'
                ? '<i class="fa-solid fa-check"></i>'
                : '<i class="fa-solid fa-fire" style="font-size:8px;color:#818cf8;"></i>';
        }
        const fill = document.getElementById('prog-' + oid);
        if (fill) fill.style.width = (d.total > 0 ? Math.round((d.ready/d.total)*100) : 0) + '%';
        const meta = document.getElementById('meta-' + oid);
        if (meta) meta.textContent = d.ready + '/' + d.total + ' items ready';
        if (d.all_ready) {
            moveCard(oid, 'ready');
            updateBadges();
            toast('All items ready — waiter notified!', 'success');
            beep();
        }
    } catch(e) {}
}

/* ── Move card between columns ── */
function moveCard(oid, target) {
    const card = document.getElementById('card-' + oid);
    if (!card) return;
    const colMap  = { cooking:'col-cooking', ready:'col-ready' };
    const dest    = document.getElementById(colMap[target]);
    if (!dest) return;

    dest.querySelector('.kds-empty')?.remove();
    const oldStatus = card.dataset.status;

    if (target === 'cooking') {
        card.dataset.status = 'preparing';
        if (!card.querySelector('.tk-prog')) {
            card.querySelector('.tk-head')?.insertAdjacentHTML('afterend',
                '<div class="tk-prog"><div class="tk-prog-track"><div class="tk-prog-fill" id="prog-'+oid+'" style="width:0%;"></div></div>' +
                '<div class="tk-prog-label" id="meta-'+oid+'">0/? items ready</div></div>'
            );
        }
        card.querySelectorAll('.tk-item').forEach(item => {
            item.classList.remove('no-click');
            const iid = item.id.replace('item-','');
            item.onclick = () => toggleItem(iid, oid);
        });
        const foot = card.querySelector('.tk-foot');
        if (foot) foot.innerHTML = '<button class="tk-btn tk-btn-done" onclick="markReady('+oid+',this)"><i class="fa-solid fa-bell"></i> Done — Notify Waiter</button>';

    } else if (target === 'ready') {
        card.dataset.status = 'ready';
        card.className = card.className.replace(/\burg-\w+/g,'') + ' urg-done';
        const tmr = card.querySelector('.tk-timer');
        if (tmr) { tmr.className = ''; tmr.removeAttribute('data-ts'); }
        card.querySelector('.tk-prog')?.remove();
        card.querySelectorAll('.tk-item').forEach(item => {
            item.className = 'tk-item no-click i-ready';
            item.onclick = null;
            const chk = item.querySelector('.tk-check');
            if (chk) chk.innerHTML = '<i class="fa-solid fa-check"></i>';
        });
        const foot = card.querySelector('.tk-foot');
        if (foot) foot.outerHTML = '<div class="tk-divider"></div><div class="tk-ready-banner"><i class="fa-solid fa-bell"></i> Waiter notified — awaiting pickup</div>';
    }

    dest.appendChild(card);

    /* Restore empty state on source column if now empty */
    const srcId  = oldStatus === 'pending' ? 'col-pending' : 'col-cooking';
    const srcCol = document.getElementById(srcId);
    if (srcCol && srcCol.querySelectorAll('.ticket').length === 0 && !srcCol.querySelector('.kds-empty')) {
        const msgs = {'col-pending':'All clear','col-cooking':'Nothing cooking'};
        const icns = {'col-pending':'fa-circle-check" style="color:#22c55e','col-cooking':'fa-fire-burner" style="color:#818cf8'};
        srcCol.innerHTML = '<div class="kds-empty"><i class="fa-solid '+icns[srcId]+'"></i>'+msgs[srcId]+'</div>';
    }
}

/* ── Update count badges ── */
function updateBadges() {
    const p = document.querySelectorAll('#col-pending .ticket').length;
    const c = document.querySelectorAll('#col-cooking .ticket').length;
    const r = document.querySelectorAll('#col-ready .ticket').length;
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('badge-pending', p); set('cnt-pending', p);
    set('badge-cooking', c); set('cnt-cooking', c);
    set('badge-ready',   r); set('cnt-ready',   r);
    const tabBadge = document.querySelector('#tab-queue .kds-tab-badge');
    if (tabBadge) tabBadge.textContent = p;
    else if (p > 0) {
        const tab = document.getElementById('tab-queue');
        if (tab && !tab.querySelector('.kds-tab-badge')) {
            const sp = document.createElement('span');
            sp.className = 'kds-tab-badge';
            sp.textContent = p;
            tab.appendChild(sp);
        }
    }
}

/* ── Live polling ── */
let knownOrders = {};
document.querySelectorAll('.ticket[id^="card-"]').forEach(el => {
    knownOrders[el.id.replace('card-','')] = el.dataset.status || 'pending';
});
let pollBusy = false;

async function pollOrders() {
    if (pollBusy) return;
    pollBusy = true;
    try {
        const r = await fetch('dashboard.php?action=get_orders');
        const d = await r.json();
        if (!d.orders) { pollBusy = false; return; }

        const serverIds = new Set(d.orders.map(o => String(o.id)));
        let hasNew = false;

        d.orders.forEach(o => {
            const sid = String(o.id);
            if (knownOrders[sid] === undefined) {
                knownOrders[sid] = o.status;
                injectCard(o);
                hasNew = true;
            } else if (knownOrders[sid] !== o.status) {
                knownOrders[sid] = o.status;
            }
        });

        /* Remove completed orders from display */
        Object.keys(knownOrders).forEach(sid => {
            if (!serverIds.has(sid)) {
                delete knownOrders[sid];
                document.getElementById('card-' + sid)?.remove();
                ['col-pending','col-cooking','col-ready'].forEach(colId => {
                    const col = document.getElementById(colId);
                    if (col && col.querySelectorAll('.ticket').length === 0 && !col.querySelector('.kds-empty')) {
                        const msgs = {'col-pending':'All clear','col-cooking':'Nothing cooking','col-ready':'Nothing ready yet'};
                        const icns = {'col-pending':'fa-circle-check" style="color:#22c55e','col-cooking':'fa-fire-burner" style="color:#818cf8','col-ready':'fa-bell" style="color:#22c55e'};
                        col.innerHTML = '<div class="kds-empty"><i class="fa-solid '+icns[colId]+'"></i>'+msgs[colId]+'</div>';
                    }
                });
                updateBadges();
            }
        });

        if (hasNew) { beepNew(); updateBadges(); toast('New order received!', 'warning'); }

        const pl = document.getElementById('poll-status');
        if (pl) pl.textContent = 'Updated ' + new Date().toLocaleTimeString();
    } catch(e) {
        const pl = document.getElementById('poll-status');
        if (pl) pl.textContent = 'Connection issue…';
    }
    pollBusy = false;
}
setInterval(pollOrders, 3000);

/* ── Build injected card HTML ── */
function injectCard(o) {
    const colMap = { pending:'col-pending', preparing:'col-cooking', ready:'col-ready' };
    const col = document.getElementById(colMap[o.status] || 'col-pending');
    if (!col) return;
    col.querySelector('.kds-empty')?.remove();

    const now = Math.floor(Date.now() / 1000);
    const ts  = Math.floor(new Date(o.created_at.replace(' ','T')).getTime() / 1000);
    const sec = now - ts;
    const m   = Math.floor(sec / 60), s = sec % 60;
    const urg  = m >= 20 ? 'urg-hot'  : m >= 10 ? 'urg-warn'  : 'urg-ok';
    const tcls = m >= 20 ? 't-hot'    : m >= 10 ? 't-warn'    : 't-ok';
    const tnum = o.table_number || 0;
    const isRsv = o.order_type === 'reservation';

    const itemsHtml = (o.items || []).map(item => {
        const iReady = item.item_status === 'ready';
        const chkInner = iReady
            ? '<i class="fa-solid fa-check"></i>'
            : (item.item_status === 'preparing' ? '<i class="fa-solid fa-fire" style="font-size:8px;color:#818cf8;"></i>' : '');
        const clickable = o.status === 'preparing' ? '' : 'no-click';
        const onclick   = o.status === 'preparing' ? `onclick="toggleItem(${item.id},${o.id})"` : '';
        return `<div class="tk-item ${clickable} i-${escHtml(item.item_status)}" id="item-${item.id}" ${onclick}>
            <div class="tk-check">${chkInner}</div>
            <div class="tk-name">${escHtml(item.name)}</div>
            <div class="tk-qty">×${item.quantity}</div>
        </div>`;
    }).join('');

    let progHtml = '', footHtml = '';
    if (o.status === 'pending') {
        footHtml = `<div class="tk-divider"></div><div class="tk-foot">
            <button class="tk-btn tk-btn-start" onclick="acceptOrder(${o.id},this)">
                <i class="fa-solid fa-fire-burner"></i> Start Cooking
            </button></div>`;
    } else if (o.status === 'preparing') {
        const ready = (o.items||[]).filter(i=>i.item_status==='ready').length;
        const total = (o.items||[]).length;
        const pct   = total > 0 ? Math.round((ready/total)*100) : 0;
        progHtml = `<div class="tk-prog">
            <div class="tk-prog-track"><div class="tk-prog-fill" id="prog-${o.id}" style="width:${pct}%;"></div></div>
            <div class="tk-prog-label" id="meta-${o.id}">${ready}/${total} items ready</div>
        </div>`;
        footHtml = `<div class="tk-divider"></div><div class="tk-foot">
            <button class="tk-btn tk-btn-done" onclick="markReady(${o.id},this)">
                <i class="fa-solid fa-bell"></i> Done — Notify Waiter
            </button></div>`;
    } else {
        footHtml = `<div class="tk-divider"></div><div class="tk-ready-banner">
            <i class="fa-solid fa-bell"></i> Waiter notified — awaiting pickup
        </div>`;
    }

    const notesHtml = o.notes
        ? `<div class="tk-notes"><i class="fa-solid fa-triangle-exclamation"></i> ${escHtml(o.notes)}</div>`
        : '';

    col.insertAdjacentHTML('afterbegin',
        `<div class="ticket ${urg} new-in" id="card-${o.id}" data-ts="${ts}" data-status="${o.status}">
            <div class="tk-head">
                <div>
                    <div class="tk-table">T${tnum}</div>
                    <div class="tk-order-num">${escHtml(o.order_number)}${isRsv?' &middot; <span style="color:#60a5fa;">RSV</span>':''}</div>
                </div>
                <div class="tk-timer-wrap">
                    <span class="tk-timer ${tcls}" id="tmr-${o.id}" data-ts="${ts}">${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}</span>
                    <span class="tk-waiter">${escHtml(o.waiter||'—')}</span>
                </div>
            </div>
            ${progHtml}${notesHtml}
            <div class="tk-items">${itemsHtml}</div>
            ${footHtml}
        </div>`
    );
}
</script>
</body>
</html>
