<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

$period = $_GET['period'] ?? 'today';
$periods = [
    'today'   => ['label'=>'Today',        'sql'=>"DATE(o.created_at)='".date('Y-m-d')."'"],
    'week'    => ['label'=>'This Week',     'sql'=>"o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"],
    'month'   => ['label'=>'This Month',    'sql'=>"DATE_FORMAT(o.created_at,'%Y-%m')='".date('Y-m')."'"],
    'alltime' => ['label'=>'All Time',      'sql'=>'1=1'],
];
if (!isset($periods[$period])) $period = 'today';
$where = $periods[$period]['sql'];

/* ── Top items ── */
$items = $conn->query("
    SELECT mi.name, mi.price,
           SUM(oi.quantity) AS qty,
           SUM(oi.quantity * mi.price) AS revenue
    FROM order_items oi
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN orders o ON oi.order_id = o.id
    WHERE $where
    GROUP BY mi.name, mi.price
    ORDER BY qty DESC
    LIMIT 12
");
$item_rows = [];
$max_qty   = 1;
while ($r = $items->fetch_assoc()) {
    $item_rows[] = $r;
    if ((int)$r['qty'] > $max_qty) $max_qty = (int)$r['qty'];
}

/* ── Category breakdown (if category col exists — safe fallback) ── */
$total_sold    = array_sum(array_column($item_rows, 'qty'));
$total_revenue = array_sum(array_column($item_rows, 'revenue'));

/* ── Chart data ── */
$chart_names = array_column(array_slice($item_rows,0,8), 'name');
$chart_qty   = array_column(array_slice($item_rows,0,8), 'qty');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Popular Items — RestaurantMS Admin</title>
<?php include('includes/admin_styles.php'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.tab-bar { display:flex; gap:6px; margin-bottom:24px; flex-wrap:wrap; }
.tab-btn {
  padding:8px 18px; border-radius:8px; border:1px solid var(--border);
  background:var(--surface); color:var(--muted); font-size:12px; font-weight:600;
  cursor:pointer; text-decoration:none; transition:.18s; font-family:inherit;
}
.tab-btn:hover  { border-color:var(--border2); color:var(--text); }
.tab-btn.active { background:rgba(201,162,39,.12); border-color:rgba(201,162,39,.3); color:var(--gold); }
.item-rank { font-size:18px; font-weight:700; color:var(--muted2); min-width:28px; }
.item-rank.top1 { color:#fbbf24; }
.item-rank.top2 { color:#94a3b8; }
.item-rank.top3 { color:#cd7c2f; }
.chart-wrap { height:240px; position:relative; }
</style>
</head>
<body>

<?php include('includes/sidebar.php'); ?>

<div class="admin-main" id="adminMain">
  <div class="topbar">
    <div>
      <div class="topbar-title">Popular Items</div>
      <div class="topbar-sub">Best-selling menu items</div>
    </div>
  </div>

  <div class="page-content">

    <!-- Period tabs -->
    <div class="tab-bar">
      <?php foreach ($periods as $k => $p): ?>
      <a href="?period=<?php echo $k; ?>" class="tab-btn <?php echo $k===$period?'active':''; ?>"><?php echo $p['label']; ?></a>
      <?php endforeach; ?>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid" style="margin-bottom:24px;">
      <div class="kpi-card kpi-gold">
        <i class="fa-solid fa-fire kpi-icon"></i>
        <div class="kpi-label">Top Item</div>
        <div class="kpi-val" style="font-size:18px;line-height:1.3;"><?php echo !empty($item_rows) ? htmlspecialchars($item_rows[0]['name']) : '—'; ?></div>
        <div class="kpi-sub"><?php echo !empty($item_rows) ? $item_rows[0]['qty'].' sold' : 'No data'; ?></div>
      </div>
      <div class="kpi-card kpi-green">
        <i class="fa-solid fa-bag-shopping kpi-icon"></i>
        <div class="kpi-label">Total Sold</div>
        <div class="kpi-val"><?php echo number_format($total_sold); ?></div>
        <div class="kpi-sub">Items across all dishes</div>
      </div>
      <div class="kpi-card kpi-blue">
        <i class="fa-solid fa-utensils kpi-icon"></i>
        <div class="kpi-label">Unique Dishes</div>
        <div class="kpi-val"><?php echo count($item_rows); ?></div>
        <div class="kpi-sub">Ordered in period</div>
      </div>
      <div class="kpi-card kpi-orange">
        <i class="fa-solid fa-coins kpi-icon"></i>
        <div class="kpi-label">Item Revenue</div>
        <div class="kpi-val">Rs.&nbsp;<?php echo number_format($total_revenue,0); ?></div>
        <div class="kpi-sub">Top 12 items combined</div>
      </div>
    </div>

    <!-- Chart + Ranked list -->
    <div class="grid-2">

      <div class="card">
        <div class="section-hd"><h3><i class="fa-solid fa-chart-bar" style="color:var(--gold);margin-right:8px;"></i>Top 8 by Quantity</h3></div>
        <div class="chart-wrap"><canvas id="itemChart"></canvas></div>
      </div>

      <div class="card">
        <div class="section-hd"><h3><i class="fa-solid fa-trophy" style="color:var(--gold);margin-right:8px;"></i>Full Rankings</h3></div>
        <?php if (!empty($item_rows)):
          foreach ($item_rows as $i => $row):
            $pct = round(($row['qty'] / $max_qty) * 100);
            $rankClass = $i===0?'top1':($i===1?'top2':($i===2?'top3':''));
        ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
          <div class="item-rank <?php echo $rankClass; ?>"><?php echo $i+1; ?></div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;">
              <span style="font-weight:<?php echo $i<3?'600':'400'; ?>;"><?php echo htmlspecialchars($row['name']); ?></span>
              <span style="color:var(--gold);font-weight:600;"><?php echo $row['qty']; ?></span>
            </div>
            <div style="height:4px;background:var(--surface2);border-radius:3px;overflow:hidden;">
              <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $i===0?'var(--gold)':($i===1?'#94a3b8':($i===2?'#cd7c2f':'rgba(201,162,39,.35)')); ?>;border-radius:3px;"></div>
            </div>
          </div>
          <div style="font-size:11px;color:var(--muted);min-width:60px;text-align:right;">Rs.&nbsp;<?php echo number_format($row['revenue'],0); ?></div>
        </div>
        <?php endforeach;
        else: ?>
        <div style="text-align:center;color:var(--muted);padding:40px 0;">
          <i class="fa-solid fa-utensils" style="font-size:30px;display:block;margin-bottom:10px;opacity:.25;"></i>
          No sales data for this period
        </div>
        <?php endif; ?>
      </div>

    </div>

  </div>
</div>

<script>
document.getElementById('sidebarToggle').addEventListener('click', () => {
  document.getElementById('sidebar').classList.toggle('collapsed');
  document.getElementById('adminMain').classList.toggle('expanded');
});
(function() {
  const ctx = document.getElementById('itemChart').getContext('2d');
  const colors = ['#c9a227','#94a3b8','#cd7c2f','#60a5fa','#22c55e','#c084fc','#f97316','#ef4444'];
  new Chart(ctx, {
    type:'bar',
    data:{ labels:<?php echo json_encode($chart_names); ?>,
      datasets:[{ data:<?php echo json_encode($chart_qty); ?>,
        backgroundColor: <?php echo json_encode(array_slice($colors,0,count($chart_names))); ?>,
        borderWidth:0, borderRadius:5 }] },
    options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y',
      plugins:{ legend:{display:false} },
      scales:{
        x:{ grid:{color:'rgba(255,255,255,.05)'}, ticks:{color:'rgba(255,255,255,.4)',font:{size:11}} },
        y:{ grid:{display:false}, ticks:{color:'rgba(255,255,255,.5)',font:{size:11}} }
      }
    }
  });
})();
</script>

</body>
</html>