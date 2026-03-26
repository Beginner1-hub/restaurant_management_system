<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

$sel_year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$sel_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

$sel_year  = max(2020, min((int)date('Y'), $sel_year));
$sel_month = max(1, min(12, $sel_month));

$ym = sprintf('%04d-%02d', $sel_year, $sel_month);

/* ── Month KPIs ── */
$r = $conn->query("
    SELECT COALESCE(SUM(total),0) AS revenue, COUNT(*) AS bills
    FROM billing WHERE payment_status='completed'
    AND DATE_FORMAT(billing_date,'%Y-%m')='$ym'
");
$month_kpi = $r->fetch_assoc();

/* ── Best day this month ── */
$r = $conn->query("
    SELECT DATE(billing_date) AS d, SUM(total) AS s
    FROM billing WHERE payment_status='completed'
    AND DATE_FORMAT(billing_date,'%Y-%m')='$ym'
    GROUP BY DATE(billing_date) ORDER BY s DESC LIMIT 1
");
$best_day = $r->fetch_assoc();

/* ── Monthly summary table (all months in year) ── */
$monthly = [];
for ($m = 1; $m <= 12; $m++) {
    $my = sprintf('%04d-%02d', $sel_year, $m);
    $r  = $conn->query("SELECT COALESCE(SUM(total),0) AS s, COUNT(*) AS c FROM billing WHERE payment_status='completed' AND DATE_FORMAT(billing_date,'%Y-%m')='$my'");
    $row = $r->fetch_assoc();
    $monthly[$m] = ['month'=>$my,'rev'=>(float)$row['s'],'cnt'=>(int)$row['c'],'name'=>date('F', mktime(0,0,0,$m,1,$sel_year))];
}
$year_total = array_sum(array_column($monthly, 'rev'));
$month_max  = max(array_column($monthly,'rev') ?: [1]);

/* ── Daily breakdown for selected month ── */
$daily_q = $conn->query("
    SELECT DATE(billing_date) AS d, SUM(total) AS s, COUNT(*) AS c
    FROM billing WHERE payment_status='completed'
    AND DATE_FORMAT(billing_date,'%Y-%m')='$ym'
    GROUP BY DATE(billing_date) ORDER BY d ASC
");
$daily_labels=[]; $daily_data=[];
while ($row = $daily_q->fetch_assoc()){ $daily_labels[]=date('j',strtotime($row['d'])); $daily_data[]=(float)$row['s']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monthly Sales — RestaurantMS Admin</title>
<?php include('includes/admin_styles.php'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.nav-bar {
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);padding:14px 20px;margin-bottom:24px;
}
.nav-bar select {
  background:var(--surface2);border:1px solid var(--border);
  border-radius:8px;padding:8px 14px;color:var(--text);
  font-family:inherit;font-size:13px;outline:none;
}
.nav-bar select:focus{border-color:rgba(201,162,39,.5);}
.btn-go{padding:8px 20px;background:linear-gradient(135deg,var(--gold),#9a7314);
  color:#0a0b0f;border:none;border-radius:8px;font-size:13px;font-weight:700;
  cursor:pointer;font-family:inherit;}
.month-bar-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.month-bar-label{font-size:12px;color:var(--muted);width:82px;flex-shrink:0;}
.month-bar-track{flex:1;height:7px;background:var(--surface2);border-radius:4px;overflow:hidden;}
.month-bar-fill{height:100%;border-radius:4px;transition:.4s;}
.month-bar-val{font-size:12px;font-weight:600;color:var(--gold);width:90px;text-align:right;flex-shrink:0;}
.chart-wrap{height:200px;position:relative;}
</style>
</head>
<body>

<?php include('includes/sidebar.php'); ?>

<div class="admin-main" id="adminMain">
  <div class="topbar">
    <div>
      <div class="topbar-title">Monthly Sales</div>
      <div class="topbar-sub">Year-over-month summary</div>
    </div>
  </div>

  <div class="page-content">

    <!-- Nav -->
    <form method="GET" class="nav-bar">
      <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;">Period</label>
      <select name="month">
        <?php for($m=1;$m<=12;$m++): ?>
        <option value="<?php echo $m; ?>" <?php echo $m===$sel_month?'selected':''; ?>><?php echo date('F',mktime(0,0,0,$m,1)); ?></option>
        <?php endfor; ?>
      </select>
      <select name="year">
        <?php for($y=(int)date('Y');$y>=2020;$y--): ?>
        <option value="<?php echo $y; ?>" <?php echo $y===$sel_year?'selected':''; ?>><?php echo $y; ?></option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="btn-go"><i class="fa-solid fa-arrow-right"></i> View</button>
    </form>

    <!-- KPIs -->
    <div class="kpi-grid" style="margin-bottom:24px;">
      <div class="kpi-card kpi-gold">
        <i class="fa-solid fa-sack-dollar kpi-icon"></i>
        <div class="kpi-label">Month Revenue</div>
        <div class="kpi-val">Rs.&nbsp;<?php echo number_format($month_kpi['revenue'],0); ?></div>
        <div class="kpi-sub"><?php echo date('F Y',mktime(0,0,0,$sel_month,1,$sel_year)); ?></div>
      </div>
      <div class="kpi-card kpi-blue">
        <i class="fa-solid fa-receipt kpi-icon"></i>
        <div class="kpi-label">Bills This Month</div>
        <div class="kpi-val"><?php echo $month_kpi['bills']; ?></div>
        <div class="kpi-sub">Completed transactions</div>
      </div>
      <div class="kpi-card kpi-green">
        <i class="fa-solid fa-calendar-star kpi-icon"></i>
        <div class="kpi-label">Best Day</div>
        <div class="kpi-val" style="font-size:18px;"><?php echo $best_day ? date('M j', strtotime($best_day['d'])) : '—'; ?></div>
        <div class="kpi-sub"><?php echo $best_day ? 'Rs.'.number_format($best_day['s'],0) : 'No data'; ?></div>
      </div>
      <div class="kpi-card kpi-orange">
        <i class="fa-solid fa-chart-line kpi-icon"></i>
        <div class="kpi-label">Year Total <?php echo $sel_year; ?></div>
        <div class="kpi-val">Rs.&nbsp;<?php echo number_format($year_total,0); ?></div>
        <div class="kpi-sub">All <?php echo $sel_year; ?> sales</div>
      </div>
    </div>

    <!-- Daily chart for month -->
    <div class="card" style="margin-bottom:24px;">
      <div class="section-hd"><h3><i class="fa-solid fa-chart-bar" style="color:var(--gold);margin-right:8px;"></i>Daily Revenue in <?php echo date('F Y',mktime(0,0,0,$sel_month,1,$sel_year)); ?></h3></div>
      <div class="chart-wrap"><canvas id="dailyChart"></canvas></div>
    </div>

    <!-- Full year breakdown -->
    <div class="card">
      <div class="section-hd">
        <h3><i class="fa-solid fa-calendar-days" style="color:var(--gold);margin-right:8px;"></i>All Months — <?php echo $sel_year; ?></h3>
        <span style="font-size:12px;color:var(--muted);">Total: Rs.&nbsp;<?php echo number_format($year_total,0); ?></span>
      </div>
      <?php foreach ($monthly as $m => $d): ?>
      <div class="month-bar-row">
        <div class="month-bar-label"><?php echo substr($d['name'],0,3); ?></div>
        <div class="month-bar-track">
          <div class="month-bar-fill" style="width:<?php echo $month_max>0?round(($d['rev']/$month_max)*100):0; ?>%;background:<?php echo $m===$sel_month?'var(--gold)':'rgba(201,162,39,.35)'; ?>;"></div>
        </div>
        <div class="month-bar-val">Rs.&nbsp;<?php echo number_format($d['rev'],0); ?></div>
        <div style="font-size:11px;color:var(--muted);width:50px;text-align:right;"><?php echo $d['cnt']; ?> bills</div>
      </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<script>
document.getElementById('sidebarToggle').addEventListener('click', () => {
  document.getElementById('sidebar').classList.toggle('collapsed');
  document.getElementById('adminMain').classList.toggle('expanded');
});
(function() {
  const ctx = document.getElementById('dailyChart').getContext('2d');
  const g = ctx.createLinearGradient(0,0,0,200);
  g.addColorStop(0,'rgba(201,162,39,.3)'); g.addColorStop(1,'rgba(201,162,39,0)');
  new Chart(ctx, {
    type:'line',
    data:{ labels:<?php echo json_encode($daily_labels); ?>,
      datasets:[{ data:<?php echo json_encode($daily_data); ?>,
        borderColor:'#c9a227', backgroundColor:g, borderWidth:2.5,
        fill:true, tension:.4, pointBackgroundColor:'#c9a227', pointRadius:3, pointHoverRadius:5 }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false} },
      scales:{
        x:{ grid:{color:'rgba(255,255,255,.05)'}, ticks:{color:'rgba(255,255,255,.4)',font:{size:11}} },
        y:{ grid:{color:'rgba(255,255,255,.05)'}, ticks:{color:'rgba(255,255,255,.4)',font:{size:11},callback:v=>'Rs.'+v} }
      }
    }
  });
})();
</script>

</body>
</html>