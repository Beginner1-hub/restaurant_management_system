<?php
session_start();
include("../../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorised']); exit();
}

$id    = (int)($_POST['id']    ?? 0);
$table = (int)($_POST['table'] ?? 0);

if ($id < 1 || $table < 1) {
    echo "error"; exit();
}

$stmt = $conn->prepare("UPDATE bookings SET assigned_table=? WHERE id=?");
$stmt->bind_param("ii", $table, $id);
$stmt->execute();
$stmt->close();

echo "ok";
