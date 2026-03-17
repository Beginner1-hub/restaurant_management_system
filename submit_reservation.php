<?php
include("config/db.php");

$name=$_POST['name'];
$email=$_POST['email'];
$phone=$_POST['phone'];
$date=$_POST['date'];
$time=$_POST['time'];
$guests=$_POST['guests'];

$sql="
SELECT * FROM tables
WHERE capacity >= $guests
AND id NOT IN (
SELECT assigned_table FROM bookings
WHERE booking_date='$date'
AND booking_time='$time'
AND status!='cancelled'
)
ORDER BY capacity ASC
LIMIT 1
";

$result=$conn->query($sql);

if($result->num_rows>0){

$table=$result->fetch_assoc()['id'];

$stmt=$conn->prepare("
INSERT INTO bookings
(customer_name,email,phone,booking_date,booking_time,num_guests,assigned_table,status)
VALUES(?,?,?,?,?,?,?,'pending')
");

$stmt->bind_param("ssssssi",$name,$email,$phone,$date,$time,$guests,$table);
$stmt->execute();

echo "Reservation successful";

}else{

echo "No tables available";

}
?>