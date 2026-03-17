<?php
include("../config/db.php");

$id=$_GET['id'];

$conn->query("UPDATE bookings SET status='cancelled' WHERE id=$id");

header("Location: reservations.php");