<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$now_hour  = (int)date('H');

/* ─── KPI: Revenue ─── */
$r = $conn->query("SELECT COALESCE(SUM(total),0) AS v FROM billing WHERE payment_status='completed' AND DATE(billing_date)='$today'");
$today_revenue = (float)$r->fetch_assoc()['v'];
$r = $conn->query("SELECT COALESCE(SUM(total),0) AS v FROM billing WHERE payment_status='completed' AND DATE(billing_date)='$yesterday'");
$yest_revenue  = (float)$r->fetch_assoc()['v'];
$rev_pct = $yest_revenue > 0 ? round((($today_revenue - $yest_revenue) / $yest_revenue) * 100, 1) : 0;

/* ─── KPI: Orders ─── */
$r = $conn->query("SELECT COUNT(*) AS v FROM orders WHERE DATE(created_at)='$today'");
$today_orders = (int)$r->fetch_assoc()['v'];
$r = $conn->query("SELECT COUNT(*) AS v FROM orders WHERE status IN ('pending','preparing')");
$active_orders = (int)$r->fetch_assoc()['v'];

/* ─── KPI: Tables ─── */
$r = $conn->query("SELECT COUNT(*) AS v FROM `tables` WHERE status='occupied'");
$occupied = (int)$r->fetch_assoc()['v'];
$r = $conn->query("SELECT COUNT(*) AS v FROM `tables`");
$total_tables = (int)$r->fetch_assoc()['v'];
$occ_pct = $total_tables > 0 ? round(($occupied / $total_tables) * 100) : 0;

/* ─── KPI: Reservations ─── */
$r = $conn->query("SELECT COUNT(*) AS v FROM bookings WHERE booking_date='$today' AND status NOT IN ('cancelled')");
$today_res = (int)$r->fetch_assoc()['v'];
$r = $conn->query("SELECT COUNT(*) AS v FROM bookings WHERE booking_date='$today' AND status='confirmed'");
$confirmed_res = (int)$r->fetch_assoc()['v'];

/* ─── 7-day sparkline data ─── */
$spark_rev = []; $spark_orders = [];
$chart_labels = []; $chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $r = $conn->query("SELECT COALESCE(SUM(total),0) AS v FROM billing WHERE payment_status='completed' AND DATE(billing_date)='$d'");
    $v = (float)$r->fetch_assoc()['v'];
    $spark_rev[] = $v;
    $chart_labels[] = date('D', strtotime($d));
    $chart_data[]   = $v;

    $r = $conn->query("SELECT COUNT(*) AS v FROM orders WHERE DATE(created_at)='$d'");
    $spark_orders[] = (int)$r->fetch_assoc()['v'];
}

/* ─── Order status breakdown ─── */
$r = $conn->query("SELECT status, COUNT(*) AS cnt FROM orders WHERE DATE(created_at)='$today' GROUP BY status");
$status_map = ['pending'=>0,'preparing'=>0,'ready'=>0,'completed'=>0,'cancelled'=>0];
while ($row = $r->fetch_assoc()) $status_map[$row['status']] = (int)$row['cnt'];

/* ─── Recent orders (last 6) ─── */
$recent = $conn->query("
    SELECT o.id, o.status, o.created_at,
           t.table_number, u.username AS waiter,
           COALESCE(b.total,0) AS total
    FROM orders o
    LEFT JOIN `tables` t ON o.table_id  = t.id
    LEFT JOIN users    u ON o.waiter_id = u.id
    LEFT JOIN billing  b ON b.order_id  = o.id
    ORDER BY o.created_at DESC LIMIT 7
");

/* ─── Upcoming reservations ─── */
$upcoming = $conn->query("
    SELECT customer_name, booking_time, num_guests, status
    FROM bookings
    WHERE booking_date='$today' AND status IN ('confirmed','pending')
    ORDER BY booking_time ASC LIMIT 6
");

/* ─── Popular items today ─── */
$popular = $conn->query("
    SELECT mi.name, SUM(oi.quantity) AS qty
    FROM order_items oi
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN orders o ON oi.order_id = o.id
    WHERE DATE(o.created_at)='$today'
    GROUP BY mi.name ORDER BY qty DESC LIMIT 5
");
$pop_rows = []; $pop_max = 1;
while ($row = $popular->fetch_assoc()) {
    $pop_rows[] = $row;
    if ((int)$row['qty'] > $pop_max) $pop_max = (int)$row['qty'];
}

/* ─── Hourly heatmap ─── */
$hourly = [];
for ($h = 9; $h <= 22; $h++) {
    $r = $conn->query("SELECT COUNT(*) AS v FROM orders WHERE DATE(created_at)='$today' AND HOUR(created_at)=$h");
    $hourly[$h] = (int)$r->fetch_assoc()['v'];
}
$hourly_max = max(array_merge(array_values($hourly), [1]));

/* ─── Table map ─── */
$tables_res = $conn->query("SELECT table_number, capacity, status FROM `tables` ORDER BY table_number ASC");
$tables_arr = [];
while ($t = $tables_res->fetch_assoc()) $tables_arr[] = $t;

/* ─── Smart Pulse ─── */
$pulse = [];
if ($active_orders >= 5)      $pulse[] = ['critical','Kitchen Alert','High kitchen load — '.$active_orders.' orders active.','Live'];
elseif ($active_orders > 0)   $pulse[] = ['warning', 'Kitchen Queue',$active_orders.' order(s) currently being prepared.','Live'];
if ($rev_pct > 5)             $pulse[] = ['positive','Revenue Up','Up '.$rev_pct.'% vs yesterday. Keep it up!','Today'];
elseif ($rev_pct < -5)        $pulse[] = ['warning', 'Revenue Down','Down '.abs($rev_pct).'% vs yesterday.','Today'];
if ($occ_pct >= 80)           $pulse[] = ['warning', 'Near Capacity',$occ_pct.'% tables occupied ('.$occupied.'/'.$total_tables.').','Live'];
elseif ($occ_pct > 0)         $pulse[] = ['info',    'Occupancy',$occ_pct.'% occupied ('.$occupied.'/'.$total_tables.' tables).','Live'];
$r = $conn->query("SELECT COUNT(*) AS v FROM bookings WHERE booking_date='$today' AND status='confirmed' AND booking_time BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 60 MINUTE)");
$soon = (int)$r->fetch_assoc()['v'];
if ($soon > 0)                $pulse[] = ['info','Upcoming Arrivals',$soon.' reservation(s) expected in 60 min.','Soon'];
if (empty($pulse))            $pulse[] = ['positive','All Clear','No alerts. Everything running smoothly.','Now'];

/* ─── SVG Sparkline helper ─── */
function sparkline(array $data, string $color = '#c9a227', int $w = 80, int $h = 32): string {
    $n = count($data);
    if ($n < 2) return "<svg width='$w' height='$h'></svg>";
    $max = max($data) ?: 1;
    $pts = [];
    foreach ($data as $i => $v) {
        $x = round(($i / ($n - 1)) * $w, 1);
        $y = round($h - ($v / $max) * ($h - 4) - 2, 1);
        $pts[] = "$x,$y";
    }
    $path = implode(' ', $pts);
    // area fill
    $area = $path . " $w,$h 0,$h";
    return "<svg width='$w' height='$h' viewBox='0 0 $w $h' fill='none' xmlns='http://www.w3.org/2000/svg'>
        <defs><linearGradient id='sg{$color}' x1='0' y1='0' x2='0' y2='1'>
            <stop offset='0%' stop-color='{$color}' stop-opacity='0.2'/>
            <stop offset='100%' stop-color='{$color}' stop-opacity='0'/>
        </linearGradient></defs>
        <polygon points='$area' fill='url(#sg{$color})'/>
        <polyline points='$path' stroke='$color' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/>
    </svg>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — RestaurantMS</title>
<?php include('includes/admin_styles.php'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── Dashboard-only styles ── */

/* Animated gradient border on revenue card */
@property --angle { syntax:'<angle>'; inherits:false; initial-value:0deg; }
@keyframes rotateBorder { to { --angle: 360deg; } }

.card-animated-border {
  border: 1px solid transparent;
  background: linear-gradient(var(--surface), var(--surface)) padding-box,
              conic-gradient(from var(--angle), rgba(201,162,39,.7), rgba(201,162,39,.05) 30%, rgba(201,162,39,.7) 60%, rgba(201,162,39,.05) 80%, rgba(201,162,39,.7)) border-box;
  animation: rotateBorder 6s linear infinite;
}

/* Revenue area chart */
.chart-area { height: 230px; position: relative; }

/* Activity feed */
.activity-feed { display: flex; flex-direction: column; }
.activity-item {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(255,255,255,.035);
}
.activity-item:last-child { border-bottom: none; }
.activity-icon {
  width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: 12px;
  margin-top: 1px;
}
.activity-icon.order   { background: var(--orange-dim); color: var(--orange); }
.activity-icon.res     { background: var(--blue-dim);   color: var(--blue); }
.activity-icon.payment { background: var(--green-dim);  color: var(--green); }
.activity-icon.table   { background: rgba(167,139,250,.12); color: var(--purple); }
.activity-body { flex: 1; min-width: 0; }
.activity-title { font-size: 12.5px; font-weight: 500; line-height: 1.4; }
.activity-title span { color: var(--text); font-weight: 600; }
.activity-time { font-size: 11px; color: var(--muted); margin-top: 2px; }
.activity-badge { flex-shrink: 0; }

/* Table map grid */
.table-floor {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(68px, 1fr));
  gap: 8px;
}
.tmap {
  border-radius: 10px; padding: 10px 6px;
  border: 1px solid var(--border); text-align: center;
  transition: .2s; background: rgba(255,255,255,.02);
  cursor: default;
}
.tmap:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.3); }
.tmap.available { border-color: rgba(34,197,94,.25); }
.tmap.occupied  { border-color: rgba(239,68,68,.3); background: rgba(239,68,68,.04); }
.tmap.reserved  { border-color: rgba(249,115,22,.3); background: rgba(249,115,22,.04); }
.tmap-icon { font-size: 20px; margin-bottom: 4px; }
.tmap.available .tmap-icon { color: var(--green); }
.tmap.occupied  .tmap-icon { color: var(--red); }
.tmap.reserved  .tmap-icon { color: var(--orange); }
.tmap-num { font-size: 13px; font-weight: 700; }
.tmap-cap { font-size: 9.5px; color: var(--muted); margin-top: 2px; }

/* Donut legend */
.donut-wrap { display: flex; gap: 20px; align-items: center; margin-top: 8px; }
.donut-canvas { flex-shrink: 0; }
.donut-legend { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.dl-row { display: flex; align-items: center; gap: 8px; font-size: 12px; }
.dl-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.dl-num { margin-left: auto; font-weight: 600; font-size: 13px; }

/* Heatmap */
.heatmap-track { display: flex; gap: 3px; align-items: flex-end; height: 80px; margin-bottom: 6px; }
.hm-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.hm-bar {
  width: 100%; border-radius: 3px 3px 0 0;
  min-height: 3px; transition: .3s;
  position: relative; cursor: default;
}
.hm-bar:hover::after {
  content: attr(data-tip);
  position: absolute; bottom: 108%; left: 50%; transform: translateX(-50%);
  background: var(--surface3); border: 1px solid var(--border2);
  padding: 4px 9px; border-radius: 6px; font-size: 11px;
  white-space: nowrap; z-index: 10; color: var(--text);
}
.hm-lbl { font-size: 9.5px; color: var(--muted); }

/* Progress ring for occupancy */
.occ-ring-wrap { display: flex; align-items: center; gap: 14px; }
.occ-ring { position: relative; width: 60px; height: 60px; flex-shrink: 0; }
.occ-ring svg { transform: rotate(-90deg); }
.occ-ring-val {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; color: var(--text);
}

/* Reservation row */
.res-row {
  display: flex; align-items: center; gap: 12px;
  padding: 9px 0; border-bottom: 1px solid rgba(255,255,255,.04);
}
.res-row:last-child { border-bottom: none; }
.res-avatar {
  width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; color: #fff;
  background: linear-gradient(135deg, var(--gold), #7a5c10);
}

/* Popular item bar */
.pop-row { margin-bottom: 13px; }
.pop-row:last-child { margin-bottom: 0; }
.pop-info { display: flex; justify-content: space-between; font-size: 12.5px; margin-bottom: 5px; }
.pop-track { height: 4px; background: rgba(255,255,255,.06); border-radius: 3px; overflow: hidden; }
.pop-fill { height: 100%; border-radius: 3px; transition: width .6s cubic-bezier(.16,1,.3,1); }

/* Counter animation */
.counter-num { display: inline-block; }

/* Quick stats row under KPI */
.qs-row {
  display: flex; gap: 8px; margin-bottom: 20px;
  overflow-x: auto; padding-bottom: 2px;
}
.qs-item {
  flex-shrink: 0; background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 9px; padding: 9px 16px;
  display: flex; align-items: center; gap: 10px;
  font-size: 12px; white-space: nowrap;
}
.qs-item i { font-size: 12px; }

/* Divider line */
.hdivider { border: none; border-top: 1px solid var(--border); margin: 0 0 20px; }
</style>
</head>
<body>

<?php include('includes/sidebar.php'); ?>

<div class="admin-main" id="adminMain">

  <!-- ── TOPBAR ── -->
  <div class="topbar">
    <div>
      <div class="topbar-title">Dashboard</div>
      <div class="topbar-sub"><?php echo date('l, F j, Y'); ?></div>
    </div>
    <div class="topbar-right">
      <div class="live-badge"><div class="live-dot"></div>LIVE</div>
      <button class="cmd-trigger" id="cmdTrigger">
        <i class="fa-solid fa-magnifying-glass"></i> Search&hellip;
        <span class="kbd">Ctrl+K</span>
      </button>
      <a href="reservations.php" class="topbar-icon-btn" title="Reservations">
        <i class="fa-solid fa-calendar-check"></i>
        <?php if ($confirmed_res > 0): ?><span class="badge-dot"></span><?php endif; ?>
      </a>
      <span class="live-clock" id="liveClock"></span>
    </div>
  </div>

  <!-- ── PAGE CONTENT ── -->
  <div class="page-content">

    <!-- ═══ KPI CARDS ═══ -->
    <div class="kpi-grid">

      <!-- Revenue -->
      <div class="kpi-card kpi-gold card-animated-border">
        <div class="kpi-header">
          <div class="kpi-label">Today's Revenue</div>
          <div class="kpi-icon-badge"><i class="fa-solid fa-sack-dollar"></i></div>
        </div>
        <div class="kpi-val">
          Rs.&nbsp;<span class="counter-num" data-target="<?php echo $today_revenue; ?>" data-prefix=""></span>
        </div>
        <div class="kpi-footer">
          <div class="kpi-sub">
            <?php if ($rev_pct > 0): ?>
              <i class="fa-solid fa-arrow-trend-up trend-up" style="font-size:10px;"></i>
              <span class="trend-up"><?php echo $rev_pct; ?>%</span><span class="trend-flat">vs yesterday</span>
            <?php elseif ($rev_pct < 0): ?>
              <i class="fa-solid fa-arrow-trend-down trend-down" style="font-size:10px;"></i>
              <span class="trend-down"><?php echo abs($rev_pct); ?>%</span><span class="trend-flat">vs yesterday</span>
            <?php else: ?>
              <span class="trend-flat">No prior data</span>
            <?php endif; ?>
          </div>
          <?php echo sparkline($spark_rev, '#c9a227'); ?>
        </div>
      </div>

      <!-- Orders -->
      <div class="kpi-card kpi-orange">
        <div class="kpi-header">
          <div class="kpi-label">Orders Today</div>
          <div class="kpi-icon-badge"><i class="fa-solid fa-receipt"></i></div>
        </div>
        <div class="kpi-val">
          <span class="counter-num" data-target="<?php echo $today_orders; ?>"></span>
        </div>
        <div class="kpi-footer">
          <div class="kpi-sub">
            <?php if ($active_orders > 0): ?>
              <span style="width:7px;height:7px;border-radius:50%;background:var(--orange);display:inline-block;animation:livePulse 1.5s infinite;"></span>
              <span><?php echo $active_orders; ?> active now</span>
            <?php else: ?>
              <span class="trend-flat">None active</span>
            <?php endif; ?>
          </div>
          <?php echo sparkline($spark_orders, '#f97316'); ?>
        </div>
      </div>

      <!-- Tables -->
      <div class="kpi-card kpi-blue">
        <div class="kpi-header">
          <div class="kpi-label">Tables Occupied</div>
          <div class="kpi-icon-badge"><i class="fa-solid fa-border-all"></i></div>
        </div>
        <div class="kpi-val">
          <span class="counter-num" data-target="<?php echo $occupied; ?>"></span>
          <span style="font-size:15px;color:var(--muted);font-family:'Inter',sans-serif;font-weight:400;">&nbsp;/ <?php echo $total_tables; ?></span>
        </div>
        <div class="kpi-footer">
          <div class="kpi-sub">
            <div class="occ-ring-wrap">
              <div class="occ-ring">
                <?php
                  $r_val = 24; $circumference = 2 * M_PI * $r_val;
                  $dash = round($circumference * $occ_pct / 100, 1);
                ?>
                <svg width="54" height="54" viewBox="0 0 54 54">
                  <circle cx="27" cy="27" r="<?php echo $r_val; ?>" stroke="rgba(255,255,255,.07)" stroke-width="4" fill="none"/>
                  <circle cx="27" cy="27" r="<?php echo $r_val; ?>"
                    stroke="#60a5fa" stroke-width="4" fill="none"
                    stroke-dasharray="<?php echo $dash; ?> <?php echo $circumference; ?>"
                    stroke-linecap="round"/>
                </svg>
                <div class="occ-ring-val"><?php echo $occ_pct; ?>%</div>
              </div>
              <span><?php echo $occ_pct; ?>% occupancy</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Reservations -->
      <div class="kpi-card kpi-green">
        <div class="kpi-header">
          <div class="kpi-label">Reservations Today</div>
          <div class="kpi-icon-badge"><i class="fa-solid fa-calendar-check"></i></div>
        </div>
        <div class="kpi-val">
          <span class="counter-num" data-target="<?php echo $today_res; ?>"></span>
        </div>
        <div class="kpi-footer">
          <div class="kpi-sub">
            <?php if ($confirmed_res > 0): ?>
              <i class="fa-solid fa-check-circle" style="color:var(--blue);font-size:10px;"></i>
              <span><?php echo $confirmed_res; ?> confirmed</span>
            <?php else: ?>
              <span class="trend-flat">None pending</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div><!-- /kpi-grid -->

    <!-- ═══ REVENUE CHART + DONUT ═══ -->
    <div class="grid-2 mb-14">

      <div class="card">
        <div class="section-hd">
          <h3><i class="fa-solid fa-chart-area"></i>Revenue — Last 7 Days</h3>
          <a href="daily_sales.php">Full report &rarr;</a>
        </div>
        <div class="chart-area"><canvas id="revenueChart"></canvas></div>
      </div>

      <div class="card">
        <div class="section-hd">
          <h3><i class="fa-solid fa-circle-half-stroke"></i>Today's Orders</h3>
        </div>
        <div class="donut-wrap">
          <canvas id="donutChart" class="donut-canvas" width="120" height="120"></canvas>
          <div class="donut-legend">
            <?php
            $dc = ['pending'=>'#f97316','preparing'=>'#a78bfa','ready'=>'#22c55e','completed'=>'#60a5fa','cancelled'=>'#ef4444'];
            $any = false;
            foreach ($status_map as $st => $cnt):
              if ($cnt === 0) continue; $any = true; ?>
            <div class="dl-row">
              <span class="dl-dot" style="background:<?php echo $dc[$st]??'#888'; ?>"></span>
              <span style="text-transform:capitalize;color:var(--muted);"><?php echo $st; ?></span>
              <span class="dl-num"><?php echo $cnt; ?></span>
            </div>
            <?php endforeach;
            if (!$any): ?><div style="color:var(--muted);font-size:12px;">No orders yet</div><?php endif; ?>
          </div>
        </div>
        <!-- Hourly heatmap (compact) -->
        <div style="margin-top:20px;">
          <div style="font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">Hourly Activity</div>
          <div class="heatmap-track">
            <?php foreach ($hourly as $h => $cnt):
              $pct   = round(($cnt / $hourly_max) * 100);
              $ht    = max(3, round($pct * 0.72));
              $isNow = ($h === $now_hour);
              $clr   = $isNow ? 'var(--gold)' : ($cnt===0 ? 'rgba(255,255,255,.06)' : ($pct>60 ? '#ef4444' : ($pct>30 ? '#f97316' : '#60a5fa')));
              $glow  = $isNow ? 'box-shadow:0 0 6px rgba(201,162,39,.5);' : '';
            ?>
            <div class="hm-col">
              <div class="hm-bar" style="height:<?php echo $ht; ?>px;background:<?php echo $clr; ?>;<?php echo $glow; ?>"
                   data-tip="<?php echo $h; ?>:00 — <?php echo $cnt; ?> orders"></div>
              <div class="hm-lbl"><?php echo $h; ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>

    <!-- ═══ ACTIVITY FEED + RESERVATIONS ═══ -->
    <div class="grid-73 mb-14">

      <!-- Recent Orders Activity -->
      <div class="card">
        <div class="section-hd">
          <h3><i class="fa-solid fa-timeline"></i>Recent Orders</h3>
          <a href="analytics.php">Analytics &rarr;</a>
        </div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Order</th><th>Table</th><th>Waiter</th><th>Status</th><th>Total</th><th>Time</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recent && $recent->num_rows > 0):
              while ($row = $recent->fetch_assoc()): ?>
            <tr>
              <td style="font-weight:600;">#<?php echo $row['id']; ?></td>
              <td style="color:var(--muted);">T-<?php echo $row['table_number'] ?? '?'; ?></td>
              <td><?php echo htmlspecialchars($row['waiter'] ?? '—'); ?></td>
              <td><span class="pill pill-<?php echo $row['status']; ?>"><?php echo $row['status']; ?></span></td>
              <td class="text-gold fw-600">Rs.&nbsp;<?php echo number_format($row['total'],0); ?></td>
              <td style="color:var(--muted);"><?php echo date('H:i', strtotime($row['created_at'])); ?></td>
            </tr>
            <?php endwhile;
            else: ?>
            <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:32px 0;">
              <i class="fa-regular fa-clipboard" style="font-size:24px;display:block;margin-bottom:8px;opacity:.25;"></i>
              No orders today yet
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Upcoming Reservations -->
      <div class="card">
        <div class="section-hd">
          <h3><i class="fa-solid fa-clock"></i>Upcoming</h3>
          <a href="reservations.php">View all &rarr;</a>
        </div>
        <?php if ($upcoming && $upcoming->num_rows > 0):
          while ($row = $upcoming->fetch_assoc()): ?>
        <div class="res-row">
          <div class="res-avatar"><?php echo strtoupper(substr($row['customer_name'],0,1)); ?></div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?php echo htmlspecialchars($row['customer_name']); ?>
            </div>
            <div style="font-size:11px;color:var(--muted);">
              <i class="fa-solid fa-users" style="font-size:10px;"></i>&nbsp;<?php echo $row['num_guests']; ?> guests
            </div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:13px;font-weight:700;"><?php echo date('H:i', strtotime($row['booking_time'])); ?></div>
            <span class="pill pill-<?php echo $row['status']; ?>"><?php echo $row['status']; ?></span>
          </div>
        </div>
        <?php endwhile;
        else: ?>
        <div style="text-align:center;color:var(--muted);padding:32px 0;font-size:13px;">
          <i class="fa-regular fa-calendar-xmark" style="font-size:26px;display:block;margin-bottom:8px;opacity:.25;"></i>
          No reservations today
        </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- ═══ POPULAR ITEMS + SMART PULSE + TABLE MAP ═══ -->
    <div class="grid-3 mb-14">

      <!-- Popular Items -->
      <div class="card">
        <div class="section-hd">
          <h3><i class="fa-solid fa-fire"></i>Top Items Today</h3>
          <a href="popular_items.php">All &rarr;</a>
        </div>
        <?php if (!empty($pop_rows)):
          $colors = ['var(--gold)','#94a3b8','#cd7c2f','var(--blue)','var(--green)'];
          foreach ($pop_rows as $i => $item):
            $pct = round(($item['qty'] / $pop_max) * 100);
        ?>
        <div class="pop-row">
          <div class="pop-info">
            <span style="font-weight:<?php echo $i===0?'600':'400'; ?>;">
              <?php if($i===0): ?><i class="fa-solid fa-crown" style="color:var(--gold);font-size:10px;margin-right:4px;"></i><?php endif; ?>
              <?php echo htmlspecialchars($item['name']); ?>
            </span>
            <span style="color:var(--gold);font-weight:600;"><?php echo $item['qty']; ?></span>
          </div>
          <div class="pop-track">
            <div class="pop-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $colors[$i]??'rgba(201,162,39,.4)'; ?>;"></div>
          </div>
        </div>
        <?php endforeach;
        else: ?>
        <div style="text-align:center;color:var(--muted);padding:28px 0;font-size:12.5px;">
          <i class="fa-solid fa-fire-flame-curved" style="font-size:24px;display:block;margin-bottom:8px;opacity:.2;"></i>
          No sales data today
        </div>
        <?php endif; ?>
      </div>

      <!-- Smart Pulse -->
      <div class="card">
        <div class="section-hd">
          <h3><i class="fa-solid fa-brain"></i>Smart Pulse</h3>
          <span style="font-size:10px;background:rgba(201,162,39,.12);border:1px solid rgba(201,162,39,.22);color:var(--gold);padding:2px 8px;border-radius:20px;">AI</span>
        </div>
        <div class="pulse-grid">
          <?php foreach ($pulse as [$type, $title, $msg, $time]): ?>
          <div class="pulse-item <?php echo $type; ?>">
            <div class="pulse-dot"></div>
            <div>
              <div style="font-size:11.5px;font-weight:700;margin-bottom:2px;"><?php echo htmlspecialchars($title); ?></div>
              <div class="pulse-msg"><?php echo htmlspecialchars($msg); ?></div>
              <div class="pulse-time"><?php echo htmlspecialchars($time); ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Table Map -->
      <div class="card">
        <div class="section-hd">
          <h3><i class="fa-solid fa-store"></i>Floor Plan</h3>
          <a href="reservations.php">Manage &rarr;</a>
        </div>
        <div class="table-floor">
          <?php foreach ($tables_arr as $t): ?>
          <div class="tmap <?php echo htmlspecialchars($t['status']); ?>" title="Table <?php echo $t['table_number']; ?> — <?php echo $t['capacity']; ?> seats — <?php echo $t['status']; ?>">
            <div class="tmap-icon">
              <?php echo $t['status']==='available' ? '🟢' : ($t['status']==='occupied' ? '🔴' : '🟠'); ?>
            </div>
            <div class="tmap-num"><?php echo $t['table_number']; ?></div>
            <div class="tmap-cap"><?php echo $t['capacity']; ?> seats</div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($tables_arr)): ?>
          <div style="color:var(--muted);font-size:12px;padding:16px 0;">No tables configured</div>
          <?php endif; ?>
        </div>
        <div style="margin-top:12px;display:flex;gap:12px;font-size:11px;color:var(--muted);">
          <span>🟢 Available</span>
          <span>🔴 Occupied</span>
          <span>🟠 Reserved</span>
        </div>
      </div>

    </div>

  </div><!-- /page-content -->
</div><!-- /admin-main -->

<!-- ═══ COMMAND PALETTE ═══ -->
<div class="cmd-overlay" id="cmdOverlay">
  <div class="cmd-box">
    <div class="cmd-input-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input class="cmd-input" id="cmdInput" placeholder="Search pages, actions..." autocomplete="off">
    </div>
    <div class="cmd-results" id="cmdResults"></div>
    <div class="cmd-footer">
      <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>
      <span><kbd>Enter</kbd> open</span>
      <span><kbd>Esc</kbd> close</span>
    </div>
  </div>
</div>

<div id="admin-toast-wrap"></div>

<script>
/* ── SIDEBAR ── */
document.getElementById('sidebarToggle').addEventListener('click', () => {
  document.getElementById('sidebar').classList.toggle('collapsed');
  document.getElementById('adminMain').classList.toggle('expanded');
});

/* ── LIVE CLOCK ── */
(function tick() {
  const n = new Date(), p = v => String(v).padStart(2,'0');
  document.getElementById('liveClock').textContent = p(n.getHours())+':'+p(n.getMinutes())+':'+p(n.getSeconds());
  setTimeout(tick, 1000);
})();

/* ── COUNTER ANIMATION ── */
document.querySelectorAll('.counter-num[data-target]').forEach(el => {
  const target = parseFloat(el.dataset.target);
  const isFloat = target !== Math.floor(target);
  const dur = 1400, step = 16, inc = target / (dur / step);
  let cur = 0;
  const timer = setInterval(() => {
    cur += inc;
    if (cur >= target) { cur = target; clearInterval(timer); }
    el.textContent = isFloat ? Math.floor(cur).toLocaleString() : Math.floor(cur).toLocaleString();
  }, step);
});

/* ── REVENUE CHART ── */
(function(){
  const ctx = document.getElementById('revenueChart').getContext('2d');
  const g = ctx.createLinearGradient(0,0,0,230);
  g.addColorStop(0,'rgba(201,162,39,0.22)');
  g.addColorStop(0.6,'rgba(201,162,39,0.05)');
  g.addColorStop(1,'rgba(201,162,39,0)');
  new Chart(ctx, {
    type:'line',
    data:{
      labels: <?php echo json_encode($chart_labels); ?>,
      datasets:[{
        data: <?php echo json_encode($chart_data); ?>,
        borderColor:'#c9a227', backgroundColor:g,
        borderWidth:2.5, fill:true, tension:.42,
        pointBackgroundColor:'#c9a227',
        pointRadius:4, pointHoverRadius:7,
        pointHoverBackgroundColor:'#e8c060',
        pointBorderColor:'transparent',
        pointHoverBorderWidth:0
      }]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false},
        tooltip:{ backgroundColor:'#1c1e2e', borderColor:'rgba(201,162,39,.3)', borderWidth:1,
          padding:10, cornerRadius:8, titleColor:'rgba(255,255,255,.6)', bodyColor:'#fff',
          callbacks:{ label: ctx=>' Rs. '+Number(ctx.parsed.y).toLocaleString() } }
      },
      scales:{
        x:{ grid:{color:'rgba(255,255,255,.04)',drawBorder:false},
            ticks:{color:'rgba(255,255,255,.35)',font:{size:11,family:'Inter'}} },
        y:{ grid:{color:'rgba(255,255,255,.04)',drawBorder:false},
            ticks:{color:'rgba(255,255,255,.35)',font:{size:11,family:'Inter'},callback:v=>'Rs.'+v} }
      }
    }
  });
})();

/* ── DONUT ── */
(function(){
  const ctx = document.getElementById('donutChart').getContext('2d');
  const d = <?php echo json_encode(array_values($status_map)); ?>;
  const total = d.reduce((a,b)=>a+b,0);
  new Chart(ctx,{
    type:'doughnut',
    data:{
      labels: <?php echo json_encode(array_keys($status_map)); ?>,
      datasets:[{
        data: total>0 ? d : [1],
        backgroundColor: total>0 ? ['#f97316','#a78bfa','#22c55e','#60a5fa','#ef4444'] : ['rgba(255,255,255,.05)'],
        borderWidth:2, borderColor:'#0e0f18', hoverBorderColor:'#1c1e2e'
      }]
    },
    options:{
      responsive:false, cutout:'72%',
      plugins:{ legend:{display:false},
        tooltip:{enabled:total>0, backgroundColor:'#1c1e2e', borderColor:'rgba(255,255,255,.1)', borderWidth:1,
          padding:8, cornerRadius:8, callbacks:{label:ctx=>` ${ctx.label}: ${ctx.parsed}`} }
      }
    }
  });
})();

/* ── COMMAND PALETTE ── */
const CMDS = [
  {label:'Dashboard',     desc:'Overview',         icon:'fa-gauge-high',         href:'dashboard.php'},
  {label:'Reservations',  desc:'Manage bookings',  icon:'fa-calendar-check',     href:'reservations.php'},
  {label:'Analytics',     desc:'Charts & KPIs',    icon:'fa-chart-line',         href:'analytics.php'},
  {label:'Daily Sales',   desc:"Today's report",   icon:'fa-receipt',            href:'daily_sales.php'},
  {label:'Monthly Sales', desc:'Monthly summary',  icon:'fa-calendar-days',      href:'monthly_sales.php'},
  {label:'Popular Items', desc:'Best sellers',     icon:'fa-fire',               href:'popular_items.php'},
  {label:'Logout',        desc:'Sign out',         icon:'fa-right-from-bracket', href:'../auth/logout.php'},
];

const overlay=document.getElementById('cmdOverlay');
const cmdInp=document.getElementById('cmdInput');
const cmdRes=document.getElementById('cmdResults');
let sel=0;

function openCmd(){overlay.classList.add('open');cmdInp.value='';render('');cmdInp.focus();}
function closeCmd(){overlay.classList.remove('open');}
function render(q){
  const f=CMDS.filter(c=>(c.label+c.desc).toLowerCase().includes(q.toLowerCase()));
  sel=0;
  cmdRes.innerHTML=f.length
    ?f.map((c,i)=>`<div class="cmd-item${i===0?' selected':''}" data-href="${c.href}">
        <i class="fa-solid ${c.icon}"></i><span>${c.label}</span>
        <span class="cmd-desc">${c.desc}</span></div>`).join('')
    :'<div style="padding:22px;text-align:center;color:var(--muted);font-size:13px;">No results</div>';
  cmdRes.querySelectorAll('.cmd-item').forEach((el,i)=>{
    el.onclick=()=>location.href=el.dataset.href;
    el.onmouseover=()=>{sel=i;upSel();};
  });
}
function upSel(){
  cmdRes.querySelectorAll('.cmd-item').forEach((el,i)=>el.classList.toggle('selected',i===sel));
  cmdRes.querySelector('.selected')?.scrollIntoView({block:'nearest'});
}
cmdInp.addEventListener('input',e=>render(e.target.value));
cmdInp.addEventListener('keydown',e=>{
  const items=cmdRes.querySelectorAll('.cmd-item');
  if(e.key==='ArrowDown'){e.preventDefault();sel=Math.min(sel+1,items.length-1);upSel();}
  if(e.key==='ArrowUp')  {e.preventDefault();sel=Math.max(sel-1,0);upSel();}
  if(e.key==='Enter'&&items[sel])location.href=items[sel].dataset.href;
  if(e.key==='Escape')closeCmd();
});
document.getElementById('cmdTrigger').onclick=openCmd;
overlay.addEventListener('click',e=>{if(e.target===overlay)closeCmd();});
document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='k'){e.preventDefault();openCmd();}});
</script>
</body>
</html>