<?php
session_start();
include("../config/db.php");
require_once('../tcpdf/tcpdf.php');

if($_SESSION['user']['role'] != 'admin'){
    header("Location: ../auth/login.php");
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');

/* KPI DATA */
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

/* DAILY SALES */
$dailySales = $conn->query("
    SELECT DATE(billing_date) as sale_date,
           SUM(total) as total_sales
    FROM billing
    WHERE payment_status='completed'
    AND DATE(billing_date) BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(billing_date)
    ORDER BY sale_date ASC
");

/* POPULAR ITEMS */
$popularItems = $conn->query("
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

/* CREATE PDF */
$pdf = new TCPDF();
$pdf->SetCreator('Restaurant Management System');
$pdf->SetAuthor('Admin');
$pdf->SetTitle('Analytics Report');
$pdf->AddPage();

/* Header */
$pdf->SetFont('helvetica', 'B', 20);
$pdf->Cell(0, 10, 'Restaurant Analytics Report', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, "Date Range: $start_date to $end_date", 0, 1, 'C');
$pdf->Ln(5);

/* KPI Section */
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Key Performance Indicators', 0, 1);

$pdf->SetFont('helvetica', '', 12);

$htmlKPI = "
<table border='1' cellpadding='6'>
<tr bgcolor='#f2f2f2'>
    <th width='33%'>Total Revenue</th>
    <th width='33%'>Total Orders</th>
    <th width='34%'>Average Order Value</th>
</tr>
<tr>
    <td>$" . number_format($total_revenue,2) . "</td>
    <td>$total_orders</td>
    <td>$" . number_format($avg_order,2) . "</td>
</tr>
</table>
";

$pdf->writeHTML($htmlKPI);
$pdf->Ln(8);

/* Daily Sales Table */
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Daily Sales Breakdown', 0, 1);

$pdf->SetFont('helvetica', '', 12);

$htmlDaily = "<table border='1' cellpadding='5'>
<tr bgcolor='#f2f2f2'>
    <th width='50%'>Date</th>
    <th width='50%'>Total Sales</th>
</tr>";

while($row = $dailySales->fetch_assoc()){
    $htmlDaily .= "
    <tr>
        <td>{$row['sale_date']}</td>
        <td>$" . number_format($row['total_sales'],2) . "</td>
    </tr>";
}

$htmlDaily .= "</table>";

$pdf->writeHTML($htmlDaily);
$pdf->Ln(8);

/* Popular Items */
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Top 5 Popular Dishes', 0, 1);

$pdf->SetFont('helvetica', '', 12);

$htmlPopular = "<table border='1' cellpadding='5'>
<tr bgcolor='#f2f2f2'>
    <th width='70%'>Dish</th>
    <th width='30%'>Quantity Sold</th>
</tr>";

while($row = $popularItems->fetch_assoc()){
    $htmlPopular .= "
    <tr>
        <td>{$row['name']}</td>
        <td>{$row['total_sold']}</td>
    </tr>";
}

$htmlPopular .= "</table>";

$pdf->writeHTML($htmlPopular);

/* Footer */
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 10);
$pdf->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'R');

$pdf->Output("analytics_report.pdf", "I");
?>
