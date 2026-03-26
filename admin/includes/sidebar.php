<?php
/* Shared admin sidebar — include after session_start() + role check */
$current_page = basename($_SERVER['PHP_SELF']);
function nav_item($href, $icon, $label, $current) {
    $active = (basename($href) === $current) ? 'active' : '';
    echo "<a href=\"$href\" class=\"nav-item $active\"><i class=\"fa-solid $icon\"></i><span>$label</span></a>";
}
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <a href="dashboard.php" class="sidebar-brand">
      <div class="brand-icon"><i class="fa-solid fa-utensils"></i></div>
      <span class="brand-name">RestaurantMS</span>
    </a>
    <button class="sidebar-toggle" id="sidebarToggle" title="Collapse">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>

  <div class="sidebar-section-label">Overview</div>
  <nav class="sidebar-nav">
    <?php nav_item('dashboard.php',    'fa-gauge-high',        'Dashboard',      $current_page); ?>
    <?php nav_item('reservations.php', 'fa-calendar-check',   'Reservations',   $current_page); ?>
  </nav>

  <div class="sidebar-section-label">Operations</div>
  <nav class="sidebar-nav">
    <?php nav_item('analytics.php',     'fa-chart-line',      'Analytics',      $current_page); ?>
    <?php nav_item('daily_sales.php',   'fa-receipt',         'Daily Sales',    $current_page); ?>
    <?php nav_item('monthly_sales.php', 'fa-calendar-days',   'Monthly Sales',  $current_page); ?>
    <?php nav_item('popular_items.php', 'fa-fire',            'Popular Items',  $current_page); ?>
  </nav>

  <div class="sidebar-spacer"></div>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="su-avatar"><?php echo strtoupper(substr($_SESSION['user']['username'],0,1)); ?></div>
      <div class="su-info">
        <div class="su-name"><?php echo htmlspecialchars($_SESSION['user']['username']); ?></div>
        <div class="su-role">Administrator</div>
      </div>
    </div>
    <a href="../auth/logout.php" class="btn-logout" title="Logout">
      <i class="fa-solid fa-right-from-bracket"></i>
    </a>
  </div>
</aside>