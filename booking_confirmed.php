<?php
include("config/db.php");

$id    = intval($_GET['id'] ?? 0);
$token = trim($_GET['token'] ?? '');

if (!$id || !$token) {
    header("Location: reserve.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT * FROM bookings WHERE id = ? AND cancel_token = ?
");
$stmt->bind_param("is", $id, $token);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: reserve.php");
    exit;
}

$formatted_date = date("l, d F Y", strtotime($booking['booking_date']));
$formatted_time = date("H:i", strtotime($booking['booking_time']));
$cancel_url = "cancel_booking.php?token=" . urlencode($token);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Confirmed</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Inter:wght@400;500&display=swap" rel="stylesheet">
<style>

body {
  font-family: 'Inter', sans-serif;
  background: #f8f8f8;
  margin: 0;
  padding: 0;
}

.container {
  max-width: 620px;
  margin: 70px auto;
  background: white;
  border-radius: 8px;
  box-shadow: 0 8px 25px rgba(0,0,0,0.08);
  overflow: hidden;
}

.banner {
  background: #c9a227;
  padding: 36px;
  text-align: center;
}

.banner .checkmark {
  width: 60px;
  height: 60px;
  background: white;
  border-radius: 50%;
  margin: 0 auto 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  color: #c9a227;
}

.banner h2 {
  font-family: 'Playfair Display', serif;
  color: white;
  margin: 0;
  font-size: 28px;
}

.banner p {
  color: rgba(255,255,255,0.85);
  margin: 8px 0 0;
}

.body {
  padding: 36px;
}

.body p.intro {
  color: #444;
  margin-top: 0;
}

.details {
  border: 1px solid #eee;
  border-radius: 6px;
  overflow: hidden;
  margin: 24px 0;
}

.detail-row {
  display: flex;
  border-bottom: 1px solid #eee;
}

.detail-row:last-child {
  border-bottom: none;
}

.detail-row .label {
  width: 130px;
  padding: 12px 16px;
  color: #888;
  font-size: 14px;
  background: #fafafa;
  flex-shrink: 0;
}

.detail-row .value {
  padding: 12px 16px;
  color: #222;
  font-size: 14px;
  font-weight: 500;
}

.email-notice {
  background: #f0f7ff;
  border: 1px solid #c6def8;
  border-radius: 6px;
  padding: 14px 16px;
  font-size: 14px;
  color: #1a5ea8;
  margin-bottom: 28px;
}

.actions {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
}

.btn-home {
  flex: 1;
  padding: 12px;
  background: #c9a227;
  color: white;
  text-decoration: none;
  border-radius: 5px;
  text-align: center;
  font-weight: 500;
  transition: 0.2s;
}

.btn-home:hover {
  background: #a8861f;
}

.btn-cancel {
  flex: 1;
  padding: 12px;
  background: white;
  color: #dc3545;
  border: 1px solid #dc3545;
  text-decoration: none;
  border-radius: 5px;
  text-align: center;
  font-weight: 500;
  transition: 0.2s;
}

.btn-cancel:hover {
  background: #dc3545;
  color: white;
}

.footer {
  padding: 16px 36px;
  border-top: 1px solid #eee;
  font-size: 12px;
  color: #aaa;
  text-align: center;
}

</style>
</head>
<body>

<div class="container">

  <div class="banner">
    <div class="checkmark">&#10003;</div>
    <h2>Reservation Confirmed!</h2>
    <p>Booking #<?php echo $booking['id']; ?></p>
  </div>

  <div class="body">

    <p class="intro">
      Hi <strong><?php echo htmlspecialchars($booking['customer_name']); ?></strong>,
      your table has been reserved. A confirmation email has been sent to
      <strong><?php echo htmlspecialchars($booking['email']); ?></strong>.
    </p>

    <div class="details">
      <div class="detail-row">
        <div class="label">Date</div>
        <div class="value"><?php echo $formatted_date; ?></div>
      </div>
      <div class="detail-row">
        <div class="label">Time</div>
        <div class="value"><?php echo $formatted_time; ?></div>
      </div>
      <div class="detail-row">
        <div class="label">Guests</div>
        <div class="value"><?php echo $booking['num_guests']; ?></div>
      </div>
      <div class="detail-row">
        <div class="label">Table</div>
        <div class="value">Table <?php echo $booking['assigned_table']; ?></div>
      </div>
      <div class="detail-row">
        <div class="label">Status</div>
        <?php
        $status_colors = [
            'confirmed' => '#1a9e5c',
            'pending'   => '#e67e00',
            'cancelled' => '#dc3545',
            'seated'    => '#007bff',
            'completed' => '#6c757d',
        ];
        $sc = $status_colors[$booking['status']] ?? '#555';
        ?>
        <div class="value" style="color:<?php echo $sc; ?>;text-transform:capitalize;font-weight:600;">
          <?php echo htmlspecialchars($booking['status']); ?>
        </div>
      </div>
    </div>

    <div class="email-notice">
      &#9993; A confirmation email with a cancellation link has been sent to your inbox.
      Check your spam folder if you don't see it.
    </div>

    <div class="actions">
      <a href="index.php" class="btn-home">Back to Home</a>
      <a href="<?php echo $cancel_url; ?>" class="btn-cancel">Cancel This Reservation</a>
    </div>

  </div>

  <div class="footer">
    Keep this page bookmarked or use the link in your email to manage your reservation.
  </div>

</div>

</body>
</html>
