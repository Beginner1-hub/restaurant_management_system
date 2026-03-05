<?php
session_start();
include("../config/db.php");

if($_SESSION['user']['role'] != 'admin'){
    header("Location: ../auth/login.php");
    exit();
}

/* -------- DATE FILTER -------- */
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');

/* -------- KPI DATA -------- */
$kpiQuery = $conn->query("
    SELECT 
        SUM(total) as total_revenue,
        COUNT(id) as total_orders,
        AVG(total) as avg_order_value
    FROM billing
    WHERE payment_status='completed'
    AND DATE(billing_date) BETWEEN '$start_date' AND '$end_date'
");

$kpi = $kpiQuery->fetch_assoc();

$total_revenue = $kpi['total_revenue'] ?? 0;
$total_orders  = $kpi['total_orders'] ?? 0;
$avg_order     = $kpi['avg_order_value'] ?? 0;

/* -------- DAILY SALES DATA -------- */
$dates = [];
$sales = [];

$result = $conn->query("
    SELECT DATE(billing_date) as sale_date,
           SUM(total) as total_sales
    FROM billing
    WHERE payment_status='completed'
    AND DATE(billing_date) BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(billing_date)
    ORDER BY sale_date ASC
");

while($row = $result->fetch_assoc()){
    $dates[] = $row['sale_date'];
    $sales[] = $row['total_sales'];
}

/* -------- POPULAR ITEMS -------- */
$itemNames = [];
$itemQty = [];

$result2 = $conn->query("
    SELECT menu_items.name,
           SUM(order_items.quantity) as total_sold
    FROM order_items
    JOIN menu_items ON order_items.menu_item_id = menu_items.id
    JOIN orders ON order_items.order_id = orders.id
    JOIN billing ON billing.order_id = orders.id
    WHERE billing.payment_status='completed'
    AND DATE(billing.billing_date) BETWEEN '$start_date' AND '$end_date'
    GROUP BY menu_items.name
    ORDER BY total_sold DESC
    LIMIT 5
");

while($row = $result2->fetch_assoc()){
    $itemNames[] = $row['name'];
    $itemQty[] = $row['total_sold'];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Analytics Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">

<div class="container mt-4">

<h2 class="mb-4">📊 Analytics Dashboard</h2>

<a href="dashboard.php" class="btn btn-secondary mb-3">Back</a>

<!-- DATE FILTER -->
<form method="GET" class="row g-3 mb-4">
    <div class="col-md-3">
        <label>Start Date</label>
        <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
    </div>
    <div class="col-md-3">
        <label>End Date</label>
        <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
    </div>
    <div class="col-md-3 d-flex align-items-end">
        <button class="btn btn-primary">Filter</button>
    </div>
</form>

<!-- KPI CARDS -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-white bg-success shadow">
            <div class="card-body">
                <h5>Total Revenue</h5>
                <h3>$<?php echo number_format($total_revenue,2); ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card text-white bg-primary shadow">
            <div class="card-body">
                <h5>Total Orders</h5>
                <h3><?php echo $total_orders; ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card text-white bg-dark shadow">
            <div class="card-body">
                <h5>Avg Order Value</h5>
                <h3>$<?php echo number_format($avg_order,2); ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS -->
<div class="row">
    <div class="col-md-6">
        <div class="card p-3 shadow">
            <h5>Daily Sales Trend</h5>
            <canvas id="salesChart"></canvas>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card p-3 shadow">
            <h5>Top 5 Popular Dishes</h5>
            <canvas id="popularChart"></canvas>
        </div>
    </div>
</div>

<div class="mt-4">
    <a href="export_pdf.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-danger">
        Export as PDF
    </a>
</div>

</div>

<script>
new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($dates); ?>,
        datasets: [{
            label: 'Daily Sales',
            data: <?php echo json_encode($sales); ?>,
            borderWidth: 2
        }]
    }
});

new Chart(document.getElementById('popularChart'), {
    type: 'pie',
    data: {
        labels: <?php echo json_encode($itemNames); ?>,
        datasets: [{
            data: <?php echo json_encode($itemQty); ?>
        }]
    }
});
</script>

</body>
</html>
