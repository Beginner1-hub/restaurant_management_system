<?php
session_start();
include("../config/db.php");

if($_SESSION['user']['role'] != 'admin'){
    header("Location: ../auth/login.php");
    exit();
}
?>

<h2>Monthly Sales Summary</h2>
<a href="dashboard.php">Back</a>
<hr>

<?php
$result = $conn->query("
    SELECT DATE_FORMAT(billing_date, '%Y-%m') as month,
           SUM(total) as total_sales,
           COUNT(id) as total_bills
    FROM billing
    WHERE payment_status='completed'
    GROUP BY month
    ORDER BY month DESC
");

if($result->num_rows == 0){
    echo "No monthly data available.";
}

while($row = $result->fetch_assoc()){
    echo "<strong>Month:</strong> ".$row['month']."<br>";
    echo "Total Sales: $".number_format($row['total_sales'],2)."<br>";
    echo "Total Bills: ".$row['total_bills']."<br>";
    echo "<hr>";
}
?>
