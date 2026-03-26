<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

$selected = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected)) $selected = date('Y-m-d');

/* ── Summary ── */
$r = $conn->query("
    SELECT COALESCE(SUM(total),0) AS revenue, COUNT(*) AS bills
    FROM billing WHERE payment_status='completed' AND DATE(billing_date)='$selected'
");
$summary = $r->fetch_assoc();

/* ── Per-order breakdown ── */
$rows = $conn->query("
    SELECT b.id, b.total, b.billing_date,
           o.id AS order_id,
           t.table_number,
           u.username AS cashier
    FROM billing b
    LEFT JOIN orders o ON b.order_id = o.id
    LEFT JOIN `tables` t ON o.table_id = t.id
    LEFT JOIN users u ON b.cashier_id = u.id
    WHERE b.payment_status='completed' AND DATE(b.billing_date)='$selected'
    ORDER BY b.billing_date DESC
");

/* ── 30-day mini chart ── */
$chart_labels = []; $chart_data = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $r = $conn->query("SELECT COALESCE(SUM(total),0) AS v FROM billing WHERE payment_status='completed' AND DATE(billing_date)='$d'");
    $chart_labels[] = date('M j', strtotime($d));
    $chart_data[]   = (float)$r->fetch_assoc()['v'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Sales — RestaurantMS Admin</title>
<?php include('includes/admin_styles.php'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.date-nav {
  display: flex; align-items: center; gap: 10px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 14px 20px; margin-bottom: 24px;
  flex-wrap: wrap;
}
.date-nav input {
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: 8px; padding: 8px 14px; color: var(--text);
  font-family: inherit; font-size: 13px; outline: none; transition: .2s;
}
.date-nav input:focus { border-color: rgba(201,162,39,.5); }
.btn-go {
  padding: 8px 20px; background: linear-gradient(135deg,var(--gold),#9a7314);
  color: #0a0b0f; border: none; border-radius: 8px;
  font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
}
.btn-go:hover { filter: brightness(1.1); }
.chart-wrap { height: 180px; position: relative; }
</style>
</head>
<body>

<?php include('includes/sidebar.php'); ?>

<div class="admin-main" id="adminMain">
  <div class="topbar">
    <div>
      <div class="topbar-title">Daily Sales</div>
      <div class="topbar-sub">Breakdown by day</div>
    </div>
    <div class="topbar-right">
      <a href="analytics.php" class="topbar-icon-btn" title="Analytics" style="width:auto;padding:0 14px;gap:7px;font-size:12px;font-weight:600;color:var(--text);text-decoration:none;">
        <i class="fa-solid fa-chart-line" style="color:var(--gold);"></i> Analytics
      </a>
    </div>
  </div>

  <div class="page-content">

    <!-- Date picker -->
    <form method="GET" class="date-nav">
      <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;">Select Date</label>
      <input type="date" name="date" value="<?php echo $selected; ?>">
      <button type="submit" class="btn-go"><i class="fa-solid fa-arrow-right"></i> View</button>
      <span style="margin-left:auto;font-size:13px;color:var(--muted);">
        <?php echo date('l, F j, Y', strtotime($selected)); ?>
      </span>
    </form>

    <!-- KPIs -->
    <div class="kpi-grid" style="margin-bottom:24px;">
      <div class="kpi-card kpi-gold">
        <i class="fa-solid fa-sack-dollar kpi-icon"></i>
        <div class="kpi-label">Day Revenue</div>
        <div class="kpi-val">Rs.&nbsp;<?php echo number_format($summary['revenue'],0); ?></div>
        <div class="kpi-sub"><?php echo $selected; ?></div>
      </div>
      <div class="kpi-card kpi-blue">
        <i class="fa-solid fa-receipt kpi-icon"></i>
        <div class="kpi-label">Bills Issued</div>
        <div class="kpi-val"><?php echo $summary['bills']; ?></div>
        <div class="kpi-sub">Completed payments</div>
      </div>
      <div class="kpi-card kpi-green">
        <i class="fa-solid fa-calculator kpi-icon"></i>
        <div class="kpi-label">Avg Bill</div>
        <div class="kpi-val">Rs.&nbsp;<?php echo $summary['bills'] > 0 ? number_format($summary['revenue']/$summary['bills'],0) : 0; ?></div>
        <div class="kpi-sub">Per transaction</div>
      </div>
      <div class="kpi-card kpi-orange">
        <i class="fa-solid fa-calendar kpi-icon"></i>
        <div class="kpi-label">Day of Week</div>
        <div class="kpi-val" style="font-size:20px;"><?php echo date('l', strtotime($selected)); ?></div>
        <div class="kpi-sub">Week <?php echo date('W', strtotime($selected)); ?> of <?php echo date('Y', strtotime($selected)); ?></div>
      </div>
    </div>

    <!-- 30-day chart -->
    <div class="card" style="margin-bottom:24px;">
      <div class="section-hd"><h3><i class="fa-solid fa-chart-area" style="color:var(--gold);margin-right:8px;"></i>Last 30 Days Revenue</h3></div>
      <div class="chart-wrap"><canvas id="miniChart"></canvas></div>
    </div>

    <!-- Transactions table -->
    <div class="card">
      <div class="section-hd">
        <h3><i class="fa-solid fa-list-ul" style="color:var(--gold);margin-right:8px;"></i>Transactions on <?php echo date('M j, Y', strtotime($selected)); ?></h3>
        <span style="font-size:12px;color:var(--muted);"><?php echo $summary['bills']; ?> records</span>
      </div>
      <?php if ($rows && $rows->num_rows > 0): ?>
      <table class="data-table">
        <thead>
          <tr><th>Bill #</th><th>Order #</th><th>Table</th><th>Cashier</th><th>Time</th><th style="text-align:right;">Total</th></tr>
        </thead>
        <tbody>
          <?php while ($row = $rows->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--muted);">#<?php echo $row['id']; ?></td>
            <td>#<?php echo $row['order_id'] ?? '—'; ?></td>
            <td>T-<?php echo $row['table_number'] ?? '?'; ?></td>
            <td><?php echo htmlspecialchars($row['cashier'] ?? '—'); ?></td>
            <td style="color:var(--muted);"><?php echo date('H:i', strtotime($row['billing_date'])); ?></td>
            <td style="text-align:right;color:var(--gold);font-weight:600;">Rs.&nbsp;<?php echo number_format($row['total'],2); ?></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div style="text-align:center;color:var(--muted);padding:40px 0;">
        <i class="fa-regular fa-folder-open" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3;"></i>
        No completed sales on <?php echo date('F j, Y', strtotime($selected)); ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
document.getElementById('sidebarToggle').addEventListener('click', () => {
  document.getElementById('sidebar').classList.toggle('collapsed');
  document.getElementById('adminMain').classList.toggle('expanded');
});

(function() {
  const ctx = document.getElementById('miniChart').getContext('2d');
  const g = ctx.createLinearGradient(0,0,0,180);
  g.addColorStop(0,'rgba(201,162,39,.25)'); g.addColorStop(1,'rgba(201,162,39,0)');
  const labels = <?php echo json_encode($chart_labels); ?>;
  const data   = <?php echo json_encode($chart_data); ?>;
  // highlight selected date
  const selIdx = labels.indexOf('<?php echo date("M j", strtotime($selected)); ?>');
  new Chart(ctx, {
    type:'bar',
    data:{ labels, datasets:[{
      data, borderRadius:4,
      backgroundColor: data.map((_,i) => i===selIdx ? '#c9a227' : 'rgba(201,162,39,.25)'),
      borderColor: data.map((_,i) => i===selIdx ? '#e8c060' : 'rgba(201,162,39,.5)'),
      borderWidth:1
    }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false} },
      scales:{
        x:{ grid:{display:false}, ticks:{ color:'rgba(255,255,255,.3)', font:{size:10}, maxRotation:45, maxTicksLimit:10 } },
        y:{ grid:{ color:'rgba(255,255,255,.05)' }, ticks:{ color:'rgba(255,255,255,.4)', font:{size:11}, callback:v=>'Rs.'+v } }
      }
    }
  });
})();
</script>

</body>
</html>