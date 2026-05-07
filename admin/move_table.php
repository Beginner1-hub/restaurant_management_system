<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

$id    = (int)($_POST['booking_id'] ?? 0);
$table = (int)($_POST['table_id']   ?? 0);

if ($id > 0 && $table > 0) {
    $stmt = $conn->prepare("UPDATE bookings SET assigned_table=? WHERE id=?");
    $stmt->bind_param("ii", $table, $id);
    $stmt->execute();
    $stmt->close();
}

header("Location: reservations.php"); exit();
