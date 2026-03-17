<?php
include("../config/db.php");

$query="
SELECT 
id,
customer_name,
booking_date,
booking_time
FROM bookings
";

$result=$conn->query($query);

$events=[];

while($row=$result->fetch_assoc()){

$events[]=array(

'title'=>$row['customer_name'],
'start'=>$row['booking_date']."T".$row['booking_time']

);

}

?>

<!DOCTYPE html>

<html>

<head>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

</head>

<body>

<div id="calendar"></div>

<script>

var calendar = new FullCalendar.Calendar(document.getElementById('calendar'),{

initialView:'dayGridMonth',

events: <?php echo json_encode($events); ?>

});

calendar.render();

</script>

</body>

</html>