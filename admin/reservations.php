<?php

include("../config/db.php");

$result=$conn->query("

SELECT b.*,t.table_number

FROM bookings b

LEFT JOIN tables t

ON b.assigned_table=t.id

ORDER BY booking_date,booking_time

");

?>

<h2>Reservations</h2>

<table border="1">

<tr>

<th>Name</th>
<th>Date</th>
<th>Time</th>
<th>Guests</th>
<th>Table</th>
<th>Status</th>
<th>Action</th>

</tr>

<?php while($row=$result->fetch_assoc()){ ?>

<tr>

<td><?php echo $row['customer_name']; ?></td>
<td><?php echo $row['booking_date']; ?></td>
<td><?php echo $row['booking_time']; ?></td>
<td><?php echo $row['num_guests']; ?></td>
<td><?php echo $row['table_number']; ?></td>
<td><?php echo $row['status']; ?></td>

<td>

<a href="update_status.php?id=<?php echo $row['id']; ?>&status=confirmed">Confirm</a>

<a href="update_status.php?id=<?php echo $row['id']; ?>&status=seated">Seat</a>

<a href="update_status.php?id=<?php echo $row['id']; ?>&status=completed">Complete</a>

<a href="update_status.php?id=<?php echo $row['id']; ?>&status=cancelled">Cancel</a>

</td>

</tr>

<?php } ?>

</table>