<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');

// Sanitise dates
$start_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ? $start_date : date('Y-m-01');
$end_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)   ? $end_date   : date('Y-m-d');

/* ── KPIs ── */
$kpiQ = $conn->query("
    SELECT COALESCE(SUM(total),0) AS revenue,
           COUNT(id)              AS orders,
           COALESCE(AVG(total),0) AS avg_val
    FROM billing
    WHERE payment_status='completed'
      AND DATE(billing_date) BETWEEN '$start_date' AND '$end_date'
");
$kpi = $kpiQ->fetch_assoc();

/* ── Daily sales for line chart ── */
$dates = []; $sales = [];
$r = $conn->query("
    SELECT DATE(billing_date) AS d, SUM(total) AS s
    FROM billing
    WHERE payment_status='completed'
      AND DATE(billing_date) BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(billing_date)
    ORDER BY d ASC
");
while ($row = $r->fetch_assoc()) { $dates[] = $row['d']; $sales[] = (float)$row['s']; }

/* ── Popular items for bar chart ── */
$iNames = []; $iQty = [];
$r2 = $conn->query("
    SELECT mi.name, SUM(oi.quantity) AS qty
    FROM order_items oi
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN orders     o  ON oi.order_id     = o.id
    JOIN billing    b  ON b.order_id      = o.id
    WHERE b.payment_status='completed'
      AND DATE(b.billing_date) BETWEEN '$start_date' AND '$end_date'
    GROUP BY mi.name
    ORDER BY qty DESC
    LIMIT 8
");
while ($row = $r2->fetch_assoc()) { $iNames[] = $row['name']; $iQty[] = (int)$row['qty']; }

/* ── Hourly breakdown ── */
$hLabels = []; $hData = [];
for ($h = 0; $h < 24; $h++) {
    $r = $conn->query("
        SELECT COALESCE(SUM(total),0) AS s FROM billing
        WHERE payment_status='completed'
          AND DATE(billing_date) BETWEEN '$start_date' AND '$end_date'
          AND HOUR(billing_date) = $h
    ");
    $hLabels[] = sprintf('%02d:00', $h);
    $hData[]   = (float)$r->fetch_assoc()['s'];
}

/* ── Order statuses in range ── */
$statusLabels = []; $statusData = [];
$r = $conn->query("
    SELECT o.status, COUNT(*) AS cnt
    FROM orders o
    WHERE DATE(o.created_at) BETWEEN '$start_date' AND '$end_date'
    GROUP BY o.status
");
while ($row = $r->fetch_assoc()) { $statusLabels[] = $row['status']; $statusData[] = (int)$row['cnt']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — RestaurantMS Admin</title>
<?php include('includes/admin_styles.php'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.filter-bar {
  display: flex; align-items: flex-end; gap: 14px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 18px 22px;
  margin-bottom: 24px; flex-wrap: wrap;
}
.filter-field { display: flex; flex-direction: column; gap: 6px; }
.filter-field label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; }
.filter-field input {
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: 8px; padding: 9px 14px;
  color: var(--text); font-family: inherit; font-size: 13px; outline: none;
  transition: .2s;
}
.filter-field input:focus { border-color: rgba(201,162,39,.5); }
.btn-filter {
  padding: 9px 22px; background: linear-gradient(135deg,var(--gold),#9a7314);
  color: #0a0b0f; border: none; border-radius: 8px;
  font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
  transition: .2s; align-self: flex-end;
}
.btn-filter:hover { filter: brightness(1.1); }
.chart-tall { height: 260px; position: relative; }
.chart-sm   { height: 200px; position: relative; }
</style>
</head>
<body>

<?php include('includes/sidebar.php'); ?>

<div class="admin-main" id="adminMain">

  <div class="topbar">
    <div>
      <div class="topbar-title">Analytics</div>
      <div class="topbar-sub">Sales insights &amp; trends</div>
    </div>
    <div class="topbar-right">
      <a href="export_pdf.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>"
         class="topbar-icon-btn" title="Export PDF" style="width:auto;padding:0 14px;gap:7px;font-size:12px;font-weight:600;color:var(--text);">
        <i class="fa-solid fa-file-pdf" style="color:#ef4444;"></i> Export PDF
      </a>
    </div>
  </div>

  <div class="page-content">

    <!-- Filter -->
    <form method="GET" class="filter-bar">
      <div class="filter-field">
        <label>From</label>
        <input type="date" name="start_date" value="<?php echo $start_date; ?>">
      </div>
      <div class="filter-field">
        <label>To</label>
        <input type="date" name="end_date" value="<?php echo $end_date; ?>">
      </div>
      <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Apply</button>
    </form>

    <!-- KPI Cards -->
    <div class="kpi-grid" style="margin-bottom:24px;">
      <div class="kpi-card kpi-gold">
        <i class="fa-solid fa-sack-dollar kpi-icon"></i>
        <div class="kpi-label">Total Revenue</div>
        <div class="kpi-val">Rs.&nbsp;<?php echo number_format($kpi['revenue'],0); ?></div>
        <div class="kpi-sub">Selected period</div>
      </div>
      <div class="kpi-card kpi-orange">
        <i class="fa-solid fa-receipt kpi-icon"></i>
        <div class="kpi-label">Total Orders</div>
        <div class="kpi-val"><?php echo number_format($kpi['orders']); ?></div>
        <div class="kpi-sub">Completed bills</div>
      </div>
      <div class="kpi-card kpi-blue">
        <i class="fa-solid fa-chart-bar kpi-icon"></i>
        <div class="kpi-label">Avg Order Value</div>
        <div class="kpi-val">Rs.&nbsp;<?php echo number_format($kpi['avg_val'],0); ?></div>
        <div class="kpi-sub">Per completed bill</div>
      </div>
      <div class="kpi-card kpi-green">
        <i class="fa-solid fa-calendar-week kpi-icon"></i>
        <div class="kpi-label">Period</div>
        <div class="kpi-val" style="font-size:16px;"><?php echo date('M j', strtotime($start_date)); ?> &rarr; <?php echo date('M j', strtotime($end_date)); ?></div>
        <div class="kpi-sub"><?php echo (int)((strtotime($end_date)-strtotime($start_date))/86400)+1; ?> days</div>
      </div>
    </div>

    <!-- Revenue Trend + Order Status -->
    <div class="grid-2">
      <div class="card">
        <div class="section-hd"><h3><i class="fa-solid fa-chart-line" style="color:var(--gold);margin-right:8px;"></i>Daily Revenue Trend</h3></div>
        <div class="chart-tall"><canvas id="lineChart"></canvas></div>
      </div>
      <div class="card">
        <div class="section-hd"><h3><i class="fa-solid fa-circle-half-stroke" style="color:var(--gold);margin-right:8px;"></i>Order Status Mix</h3></div>
        <div style="display:flex;justify-content:center;"><canvas id="statusChart" style="max-width:220px;max-height:220px;"></canvas></div>
      </div>
    </div>

    <!-- Popular Items + Hourly -->
    <div class="grid-2">
      <div class="card">
        <div class="section-hd"><h3><i class="fa-solid fa-fire" style="color:var(--gold);margin-right:8px;"></i>Top Selling Items</h3></div>
        <div class="chart-tall"><canvas id="barChart"></canvas></div>
      </div>
      <div class="card">
        <div class="section-hd"><h3><i class="fa-solid fa-clock" style="color:var(--gold);margin-right:8px;"></i>Revenue by Hour</h3></div>
        <div class="chart-tall"><canvas id="hourlyChart"></canvas></div>
      </div>
    </div>

  </div>
</div>

<script>
/* Sidebar toggle */
document.getElementById('sidebarToggle').addEventListener('click', () => {
  document.getElementById('sidebar').classList.toggle('collapsed');
  document.getElementById('adminMain').classList.toggle('expanded');
});

const GRID = { color: 'rgba(255,255,255,.05)' };
const TICK = { color: 'rgba(255,255,255,.4)', font:{size:11} };

/* Revenue line */
(function() {
  const ctx = document.getElementById('lineChart').getContext('2d');
  const g = ctx.createLinearGradient(0,0,0,260);
  g.addColorStop(0,'rgba(201,162,39,.3)'); g.addColorStop(1,'rgba(201,162,39,0)');
  new Chart(ctx, {
    type:'line',
    data:{ labels:<?php echo json_encode($dates); ?>,
      datasets:[{ data:<?php echo json_encode($sales); ?>, borderColor:'#c9a227',
        backgroundColor:g, borderWidth:2.5, fill:true, tension:.4,
        pointBackgroundColor:'#c9a227', pointRadius:4, pointHoverRadius:6 }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false} },
      scales:{ x:{grid:GRID,ticks:TICK}, y:{grid:GRID,ticks:{...TICK,callback:v=>'Rs.'+v}} } }
  });
})();

/* Status doughnut */
(function() {
  const ctx = document.getElementById('statusChart').getContext('2d');
  new Chart(ctx, {
    type:'doughnut',
    data:{ labels:<?php echo json_encode($statusLabels); ?>,
      datasets:[{ data:<?php echo json_encode($statusData); ?>,
        backgroundColor:['#f97316','#c084fc','#22c55e','#60a5fa','#ef4444'],
        borderWidth:2, borderColor:'#13141c' }] },
    options:{ cutout:'65%', plugins:{ legend:{ position:'bottom', labels:{ color:'rgba(255,255,255,.5)', padding:10, font:{size:11} } } } }
  });
})();

/* Popular items bar */
(function() {
  const ctx = document.getElementById('barChart').getContext('2d');
  new Chart(ctx, {
    type:'bar',
    data:{ labels:<?php echo json_encode($iNames); ?>,
      datasets:[{ data:<?php echo json_encode($iQty); ?>,
        backgroundColor:'rgba(201,162,39,.7)', borderColor:'#c9a227',
        borderWidth:1, borderRadius:5 }] },
    options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y',
      plugins:{ legend:{display:false} },
      scales:{ x:{grid:GRID,ticks:TICK}, y:{grid:{display:false},ticks:TICK} } }
  });
})();

/* Hourly revenue bar */
(function() {
  const ctx = document.getElementById('hourlyChart').getContext('2d');
  const data = <?php echo json_encode($hData); ?>;
  const bg = data.map((v,i) => {
    const g = ctx.createLinearGradient(0,0,0,260);
    g.addColorStop(0, v>0 ? 'rgba(96,165,250,.8)' : 'rgba(255,255,255,.06)');
    g.addColorStop(1, 'rgba(96,165,250,.1)');
    return g;
  });
  new Chart(ctx, {
    type:'bar',
    data:{ labels:<?php echo json_encode($hLabels); ?>,
      datasets:[{ data, backgroundColor:'rgba(96,165,250,.55)', borderColor:'#60a5fa',
        borderWidth:1, borderRadius:4 }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false} },
      scales:{ x:{grid:GRID,ticks:{...TICK,maxRotation:45}}, y:{grid:GRID,ticks:{...TICK,callback:v=>'Rs.'+v}} } }
  });
})();
</script>

</body>
</html>