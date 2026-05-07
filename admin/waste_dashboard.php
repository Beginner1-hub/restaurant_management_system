<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

/* ── Ensure analytics table exists ── */
$conn->query("
    CREATE TABLE IF NOT EXISTS menu_item_analytics (
        analytics_id   INT AUTO_INCREMENT PRIMARY KEY,
        item_id        INT NOT NULL,
        period_start   DATE NOT NULL,
        period_end     DATE NOT NULL,
        total_orders   INT NOT NULL DEFAULT 0,
        rank_position  INT NOT NULL DEFAULT 0,
        demand_level   ENUM('high','medium','low') NOT NULL DEFAULT 'low',
        waste_risk_flag TINYINT NOT NULL DEFAULT 0,
        suggested_action VARCHAR(255) NULL,
        generated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_item (item_id),
        INDEX idx_period (period_start, period_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ── Admin-configurable threshold (stored in a simple key-value, fallback to 3) ── */
$conn->query("
    CREATE TABLE IF NOT EXISTS app_settings (
        setting_key   VARCHAR(80) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('waste_threshold', '3')");

/* ── Save threshold if posted ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_threshold'])) {
    $thr = max(0, (int)($_POST['threshold'] ?? 3));
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('waste_threshold',?) ON DUPLICATE KEY UPDATE setting_value=?");
    $stmt->bind_param("ss", $thr, $thr);
    $stmt->execute();
}

$threshold_row = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key='waste_threshold'")->fetch_assoc();
$threshold = (int)($threshold_row['setting_value'] ?? 3);

/* ── Period selection ── */
$period = isset($_GET['period']) && $_GET['period'] === '30' ? 30 : 7;
$period_start = date('Y-m-d', strtotime("-{$period} days"));
$period_end   = date('Y-m-d');

/* ── Generate analytics on POST ── */
$generated = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {

    /* Count orders per menu item in the period */
    $q = $conn->query("
        SELECT mi.id, mi.name, mi.category,
               COALESCE(SUM(oi.quantity), 0) AS total_orders
        FROM menu_items mi
        LEFT JOIN order_items oi ON oi.menu_item_id = mi.id
        LEFT JOIN orders o       ON oi.order_id = o.id
            AND DATE(o.created_at) BETWEEN '$period_start' AND '$period_end'
            AND o.status NOT IN ('cancelled')
        GROUP BY mi.id, mi.name, mi.category
        ORDER BY total_orders DESC
    ");

    $rows = [];
    while ($r = $q->fetch_assoc()) $rows[] = $r;

    $total_items = count($rows);

    /* Delete existing analytics for this exact period */
    $conn->query("DELETE FROM menu_item_analytics WHERE period_start='$period_start' AND period_end='$period_end'");

    $ins = $conn->prepare("
        INSERT INTO menu_item_analytics
            (item_id, period_start, period_end, total_orders, rank_position, demand_level, waste_risk_flag, suggested_action)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($rows as $rank => $row) {
        $pos   = $rank + 1;
        $pct   = $total_items > 0 ? ($rank / $total_items) : 0;

        /* Demand level: top 30% = high, next 40% = medium, bottom 30% = low */
        if ($pct < 0.30)      $demand = 'high';
        elseif ($pct < 0.70)  $demand = 'medium';
        else                   $demand = 'low';

        $waste_risk = ($row['total_orders'] < $threshold) ? 1 : 0;

        /* Suggested action */
        if ($waste_risk) {
            if ($row['total_orders'] == 0) {
                $action = 'Item was not ordered in this period — consider removing from the menu.';
            } elseif ($demand === 'low') {
                $action = 'Low demand — reduce ingredient stock and consider seasonal replacement.';
            } else {
                $action = 'Below order threshold — monitor closely and reduce prep quantity.';
            }
        } else {
            $action = null;
        }

        $item_id  = (int)$row['id'];
        $orders   = (int)$row['total_orders'];
        $flag     = (int)$waste_risk;

        $ins->bind_param("issiisss",
            $item_id, $period_start, $period_end,
            $orders, $pos, $demand, $flag, $action
        );
        $ins->execute();
    }

    $generated = true;
}

/* ── Load latest analytics for the selected period ── */
$analytics = $conn->query("
    SELECT a.analytics_id, a.rank_position, a.total_orders,
           a.demand_level, a.waste_risk_flag, a.suggested_action, a.generated_at,
           mi.name, mi.category, mi.price
    FROM menu_item_analytics a
    JOIN menu_items mi ON a.item_id = mi.id
    WHERE a.period_start = '$period_start' AND a.period_end = '$period_end'
    ORDER BY a.rank_position ASC
");
$analytics_rows = [];
while ($r = $analytics->fetch_assoc()) $analytics_rows[] = $r;

/* ── Summary counts ── */
$total_analysed = count($analytics_rows);
$flagged_count  = count(array_filter($analytics_rows, fn($r) => $r['waste_risk_flag']));
$top_item       = !empty($analytics_rows) ? $analytics_rows[0]['name'] : '—';
$bottom_item    = !empty($analytics_rows) ? end($analytics_rows)['name'] : '—';
$last_generated = !empty($analytics_rows) ? $analytics_rows[0]['generated_at'] : null;

/* ── Flagged items only ── */
$flagged_rows = array_filter($analytics_rows, fn($r) => $r['waste_risk_flag']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Waste Reduction — RestaurantMS Admin</title>
<?php include('includes/admin_styles.php'); ?>
<style>
/* ── Demand badges ── */
.demand-high   { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.22); }
.demand-medium { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(245,158,11,.12);color:#f59e0b;border:1px solid rgba(245,158,11,.22); }
.demand-low    { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.22); }

/* ── Waste risk flag ── */
.risk-yes { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.22); }
.risk-no  { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(34,197,94,.08);color:#22c55e;border:1px solid rgba(34,197,94,.15); }

/* ── Alert row ── */
.alert-row { background:rgba(239,68,68,.06) !important; }
.alert-row:hover { background:rgba(239,68,68,.1) !important; }

/* ── Period tabs ── */
.period-tabs { display:flex; gap:6px; margin-bottom:24px; flex-wrap:wrap; }
.period-tab {
  padding:8px 20px; border-radius:8px; border:1px solid var(--border);
  background:var(--surface); color:var(--muted); font-size:12px; font-weight:600;
  cursor:pointer; text-decoration:none; transition:.18s; font-family:inherit;
}
.period-tab:hover  { border-color:var(--border2); color:var(--text); }
.period-tab.active { background:rgba(201,162,39,.12); border-color:rgba(201,162,39,.3); color:var(--gold); }

/* ── Generate bar ── */
.gen-bar {
  display:flex; align-items:center; gap:14px;
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); padding:14px 20px; margin-bottom:24px;
  flex-wrap:wrap;
}
.gen-bar-info { flex:1; min-width:0; font-size:13px; color:var(--muted); }
.gen-bar-info strong { color:var(--text); }

/* ── Threshold form ── */
.thr-form {
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); padding:14px 20px; margin-bottom:24px;
}
.thr-form label { font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.thr-input {
  width:72px; padding:7px 12px; border-radius:7px;
  border:1px solid var(--border2); background:var(--surface2);
  color:var(--text); font-size:14px; font-weight:600; text-align:center;
  font-family:inherit; outline:none; transition:.2s;
}
.thr-input:focus { border-color:rgba(201,162,39,.5); }
.thr-desc { font-size:12px; color:var(--muted); flex:1; }

/* ── Rank badge ── */
.rank-badge {
  display:inline-flex; align-items:center; justify-content:center;
  width:26px; height:26px; border-radius:50%; font-size:11px; font-weight:800;
}
.rank-1 { background:rgba(251,191,36,.2);  color:#fbbf24; }
.rank-2 { background:rgba(148,163,184,.2); color:#94a3b8; }
.rank-3 { background:rgba(205,124,47,.2);  color:#cd7c2f; }
.rank-n { background:rgba(255,255,255,.05); color:rgba(255,255,255,.3); }

/* ── Suggested action text ── */
.action-text { font-size:12px; color:var(--muted); font-style:italic; }

/* ── Success notice ── */
.gen-notice {
  display:flex; align-items:center; gap:10px;
  padding:12px 18px; border-radius:10px; margin-bottom:20px;
  background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.25); color:#22c55e;
  font-size:13px; font-weight:600;
}

/* ── Empty state ── */
.empty-state {
  text-align:center; padding:60px 20px; color:var(--muted);
}
.empty-state i { font-size:40px; display:block; margin-bottom:14px; opacity:.2; }

/* ── Section anchor ── */
.section-anchor {
  font-size:12px; color:var(--muted); font-weight:600;
  text-decoration:none; margin-left:auto;
  transition:.15s;
}
.section-anchor:hover { color:var(--text); }
</style>
</head>
<body>

<?php include('includes/sidebar.php'); ?>

<div class="admin-main" id="adminMain">
  <div class="topbar">
    <div>
      <div class="topbar-title">Waste Reduction</div>
      <div class="topbar-sub">Menu demand analysis &amp; food waste alerts</div>
    </div>
    <div class="topbar-right">
      <a href="popular_items.php" class="topbar-icon-btn" title="Popular Items"
         style="width:auto;padding:0 14px;gap:7px;font-size:12px;font-weight:600;color:var(--text);text-decoration:none;">
        <i class="fa-solid fa-fire" style="color:var(--gold);"></i> Popular Items
      </a>
    </div>
  </div>

  <div class="page-content">

    <?php if ($generated): ?>
    <div class="gen-notice">
      <i class="fa-solid fa-circle-check"></i>
      Analytics generated for <?php echo date('M j', strtotime($period_start)); ?> –
      <?php echo date('M j, Y', strtotime($period_end)); ?>.
      <?php echo $total_analysed; ?> items analysed,
      <?php echo $flagged_count; ?> flagged as waste risk.
    </div>
    <?php endif; ?>

    <!-- Period tabs -->
    <div class="period-tabs">
      <a href="?period=7"  class="period-tab <?php echo $period===7?'active':''; ?>">
        <i class="fa-solid fa-calendar-week"></i> Last 7 Days
      </a>
      <a href="?period=30" class="period-tab <?php echo $period===30?'active':''; ?>">
        <i class="fa-solid fa-calendar-days"></i> Last 30 Days
      </a>
    </div>

    <!-- Generate bar -->
    <form method="POST" action="?period=<?php echo $period; ?>">
      <input type="hidden" name="generate" value="1">
      <div class="gen-bar">
        <i class="fa-solid fa-rotate" style="color:var(--gold);font-size:16px;"></i>
        <div class="gen-bar-info">
          <strong>Period:</strong>
          <?php echo date('M j', strtotime($period_start)); ?> –
          <?php echo date('M j, Y', strtotime($period_end)); ?>
          <?php if ($last_generated): ?>
            &nbsp;&middot;&nbsp; Last generated:
            <span style="color:var(--sub);"><?php echo date('M j, H:i', strtotime($last_generated)); ?></span>
          <?php else: ?>
            &nbsp;&middot;&nbsp; <span style="color:#f59e0b;">No report generated yet for this period.</span>
          <?php endif; ?>
        </div>
        <button type="submit" class="btn-go" style="padding:9px 22px;background:linear-gradient(135deg,var(--gold),#9a7314);color:#0a0b0f;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">
          <i class="fa-solid fa-play"></i> Generate Report
        </button>
      </div>
    </form>

    <!-- Threshold setting -->
    <form method="POST" action="?period=<?php echo $period; ?>" class="thr-form">
      <input type="hidden" name="save_threshold" value="1">
      <label for="thr">Waste Threshold</label>
      <input type="number" id="thr" name="threshold" class="thr-input"
             value="<?php echo $threshold; ?>" min="0" max="9999">
      <span class="thr-desc">
        Items ordered fewer than <strong><?php echo $threshold; ?></strong> times in the period
        are flagged as <span style="color:#ef4444;font-weight:700;">Waste Risk</span>.
      </span>
      <button type="submit" class="btn-go" style="padding:7px 18px;background:var(--surface2);color:var(--text);border:1px solid var(--border2);border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;">
        Save
      </button>
    </form>

    <?php if (!empty($analytics_rows)): ?>

    <!-- KPI Summary cards -->
    <div class="kpi-grid" style="margin-bottom:24px;">
      <div class="kpi-card kpi-blue">
        <i class="fa-solid fa-utensils kpi-icon"></i>
        <div class="kpi-label">Items Analysed</div>
        <div class="kpi-val"><?php echo $total_analysed; ?></div>
        <div class="kpi-sub"><?php echo $period; ?>-day period</div>
      </div>
      <div class="kpi-card kpi-red" style="--kpi-color:#ef4444;">
        <i class="fa-solid fa-triangle-exclamation kpi-icon"></i>
        <div class="kpi-label">Waste Risk Items</div>
        <div class="kpi-val"><?php echo $flagged_count; ?></div>
        <div class="kpi-sub">Below threshold of <?php echo $threshold; ?> orders</div>
      </div>
      <div class="kpi-card kpi-gold">
        <i class="fa-solid fa-fire kpi-icon"></i>
        <div class="kpi-label">Most Popular</div>
        <div class="kpi-val" style="font-size:16px;line-height:1.3;"><?php echo htmlspecialchars($top_item); ?></div>
        <div class="kpi-sub"><?php echo !empty($analytics_rows) ? $analytics_rows[0]['total_orders'].' orders' : ''; ?></div>
      </div>
      <div class="kpi-card kpi-orange">
        <i class="fa-solid fa-arrow-trend-down kpi-icon"></i>
        <div class="kpi-label">Least Popular</div>
        <div class="kpi-val" style="font-size:16px;line-height:1.3;"><?php echo htmlspecialchars($bottom_item); ?></div>
        <div class="kpi-sub"><?php echo !empty($analytics_rows) ? end($analytics_rows)['total_orders'].' orders' : ''; ?></div>
      </div>
    </div>

    <!-- Section A: Full ranking table -->
    <div class="card" style="margin-bottom:24px;">
      <div class="section-hd">
        <h3><i class="fa-solid fa-ranking-star" style="color:var(--gold);margin-right:8px;"></i>
          Item Popularity Ranking
        </h3>
        <a href="#flagged" class="section-anchor">
          <?php echo $flagged_count; ?> flagged &darr;
        </a>
      </div>
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:52px;">Rank</th>
            <th>Item</th>
            <th>Category</th>
            <th style="text-align:center;">Orders</th>
            <th style="text-align:center;">Demand</th>
            <th style="text-align:center;">Waste Risk</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($analytics_rows as $row): ?>
          <tr <?php echo $row['waste_risk_flag'] ? 'class="alert-row"' : ''; ?>>
            <td>
              <?php
              $rc = $row['rank_position'] <= 3 ? 'rank-'.$row['rank_position'] : 'rank-n';
              ?>
              <span class="rank-badge <?php echo $rc; ?>"><?php echo $row['rank_position']; ?></span>
            </td>
            <td style="font-weight:600;"><?php echo htmlspecialchars($row['name']); ?></td>
            <td style="color:var(--muted);"><?php echo htmlspecialchars($row['category'] ?? '—'); ?></td>
            <td style="text-align:center;font-weight:700;color:var(--text);">
              <?php echo $row['total_orders']; ?>
            </td>
            <td style="text-align:center;">
              <span class="demand-<?php echo $row['demand_level']; ?>">
                <?php if ($row['demand_level']==='high'): ?>
                  <i class="fa-solid fa-arrow-trend-up"></i>
                <?php elseif ($row['demand_level']==='medium'): ?>
                  <i class="fa-solid fa-minus"></i>
                <?php else: ?>
                  <i class="fa-solid fa-arrow-trend-down"></i>
                <?php endif; ?>
                <?php echo ucfirst($row['demand_level']); ?>
              </span>
            </td>
            <td style="text-align:center;">
              <?php if ($row['waste_risk_flag']): ?>
                <span class="risk-yes"><i class="fa-solid fa-triangle-exclamation"></i> Yes</span>
              <?php else: ?>
                <span class="risk-no"><i class="fa-solid fa-check"></i> No</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Section B: Low-demand alerts -->
    <?php if (!empty($flagged_rows)): ?>
    <div class="card" id="flagged">
      <div class="section-hd">
        <h3>
          <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;margin-right:8px;"></i>
          Waste Risk Alerts
          <span style="margin-left:6px;padding:2px 9px;border-radius:20px;background:rgba(239,68,68,.12);color:#ef4444;font-size:11px;">
            <?php echo count($flagged_rows); ?> items
          </span>
        </h3>
      </div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Category</th>
            <th style="text-align:center;">Orders This Period</th>
            <th style="text-align:center;">Demand Level</th>
            <th>Suggested Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($flagged_rows as $row): ?>
          <tr class="alert-row">
            <td style="font-weight:600;color:#fca5a5;"><?php echo htmlspecialchars($row['name']); ?></td>
            <td style="color:var(--muted);"><?php echo htmlspecialchars($row['category'] ?? '—'); ?></td>
            <td style="text-align:center;">
              <span style="font-size:18px;font-weight:800;color:#ef4444;"><?php echo $row['total_orders']; ?></span>
              <span style="font-size:10px;color:var(--muted);display:block;">vs threshold: <?php echo $threshold; ?></span>
            </td>
            <td style="text-align:center;">
              <span class="demand-<?php echo $row['demand_level']; ?>">
                <?php echo ucfirst($row['demand_level']); ?>
              </span>
            </td>
            <td>
              <span class="action-text">
                <i class="fa-solid fa-lightbulb" style="color:#f59e0b;margin-right:5px;"></i>
                <?php echo htmlspecialchars($row['suggested_action'] ?? '—'); ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="card" id="flagged">
      <div class="section-hd">
        <h3><i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;margin-right:8px;"></i>Waste Risk Alerts</h3>
      </div>
      <div class="empty-state">
        <i class="fa-solid fa-circle-check" style="color:#22c55e;opacity:.6;"></i>
        No items flagged as waste risk for this period.<br>
        <span style="font-size:12px;">All items are above the threshold of <?php echo $threshold; ?> orders.</span>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- No report yet -->
    <div class="card">
      <div class="empty-state">
        <i class="fa-solid fa-chart-pie"></i>
        No report generated yet for
        <strong><?php echo date('M j', strtotime($period_start)); ?> – <?php echo date('M j, Y', strtotime($period_end)); ?></strong>.<br>
        <span style="font-size:12px;margin-top:6px;display:block;">
          Click <strong>Generate Report</strong> above to analyse menu demand and flag waste risks.
        </span>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /page-content -->
</div><!-- /admin-main -->

<script>
document.getElementById('sidebarToggle').addEventListener('click', () => {
  document.getElementById('sidebar').classList.toggle('collapsed');
  document.getElementById('adminMain').classList.toggle('expanded');
});
</script>
</body>
</html>
