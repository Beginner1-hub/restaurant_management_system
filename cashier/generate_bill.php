<?php
session_start();
include("../config/db.php");

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'cashier'){
    header("Location: ../auth/login.php");
    exit();
}

if(!isset($_GET['order_id'])){
    header("Location: dashboard.php");
    exit();
}

$order_id = $_GET['order_id'];
$cashier_id = $_SESSION['user']['id'];

// Get order
$order = $conn->query("SELECT * FROM orders WHERE id=$order_id")->fetch_assoc();
$table_id = $order['table_id'];

// Get items
$items = $conn->query("
    SELECT order_items.*, menu_items.name
    FROM order_items
    JOIN menu_items ON order_items.menu_item_id = menu_items.id
    WHERE order_items.order_id=$order_id
");

$subtotal = 0;
?>

<h2>Bill - Order #<?php echo $order['order_number']; ?></h2>
<hr>

<?php
while($item = $items->fetch_assoc()){
    $lineTotal = $item['price'] * $item['quantity'];
    $subtotal += $lineTotal;

    echo $item['name']." x ".$item['quantity']." = $".$lineTotal."<br>";
}

$tax = $subtotal * 0.10; // 10% tax
$discount = 0;
$total = $subtotal + $tax - $discount;
?>

<hr>
Subtotal: $<?php echo number_format($subtotal,2); ?><br>
Tax (10%): $<?php echo number_format($tax,2); ?><br>
Total: <strong>$<?php echo number_format($total,2); ?></strong>
<hr>

<form method="POST">
    Payment Method:
    <select name="payment_method">
        <option value="cash">Cash</option>
        <option value="card">Card</option>
    </select>
    <br><br>
    <button type="submit" name="complete_payment">Complete Payment</button>
</form>

<?php
if(isset($_POST['complete_payment'])){

    $payment_method = $_POST['payment_method'];

    // Insert into billing
    $stmt = $conn->prepare("
        INSERT INTO billing (order_id, subtotal, tax, discount, total, payment_method, payment_status, cashier_id)
        VALUES (?, ?, ?, ?, ?, ?, 'completed', ?)
    ");
    $stmt->bind_param("iddddsi", $order_id, $subtotal, $tax, $discount, $total, $payment_method, $cashier_id);
    $stmt->execute();

    $billing_id = $stmt->insert_id;

    // Insert into transactions
    $stmt2 = $conn->prepare("
        INSERT INTO transactions (billing_id, amount, payment_method, status)
        VALUES (?, ?, ?, 'completed')
    ");
    $stmt2->bind_param("ids", $billing_id, $total, $payment_method);
    $stmt2->execute();

    // Update order status
    $conn->query("UPDATE orders SET status='completed' WHERE id=$order_id");

    // Reset table to available
    $conn->query("UPDATE tables SET status='available' WHERE id=$table_id");

    echo "<br><strong style='color:green;'>Payment Completed Successfully!</strong>";
}
?>
