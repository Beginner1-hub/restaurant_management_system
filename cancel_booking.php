<?php
include("config/db.php");
include("config/mail.php");

$token = trim($_GET['token'] ?? '');

if (!$token) {
    header("Location: reserve.php");
    exit;
}

/* ── look up the booking ─────────────────────────────────────────── */
$stmt = $conn->prepare("SELECT * FROM bookings WHERE cancel_token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

$error   = '';
$success = false;

if (!$booking) {
    $error = "Invalid or expired cancellation link.";
} elseif ($booking['status'] === 'cancelled') {
    $error = "This reservation has already been cancelled.";
} elseif (in_array($booking['status'], ['seated', 'completed'])) {
    $error = "This reservation cannot be cancelled as it is already {$booking['status']}.";
}

/* ── handle cancellation POST ────────────────────────────────────── */
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_cancel'])) {

    $upd = $conn->prepare("
        UPDATE bookings SET status = 'cancelled' WHERE cancel_token = ? AND status NOT IN ('seated','completed','cancelled')
    ");
    $upd->bind_param("s", $token);
    $upd->execute();

    if ($upd->affected_rows > 0) {
        $success = true;

        /* send cancellation confirmation email */
        $cust_name      = $booking['customer_name'];
        $cust_time      = date("H:i", strtotime($booking['booking_time']));
        $cust_guests    = $booking['num_guests'];
        $formatted_date = date("l, d F Y", strtotime($booking['booking_date']));
        $year           = date('Y');
        $base_url       = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                        . '://' . $_SERVER['HTTP_HOST']
                        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $reserve_link   = $base_url . "/reserve.php";

        $subject = "Reservation Cancelled - $formatted_date";

        $message = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#f4f4f4;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

  <!-- HEADER -->
  <tr>
    <td style="background:#1a1a2e;padding:36px 40px;border-radius:8px 8px 0 0;text-align:center;">
      <p style="margin:0 0 6px 0;font-size:12px;letter-spacing:3px;color:#c9a227;text-transform:uppercase;">Restaurant</p>
      <h1 style="margin:0;font-size:28px;font-weight:300;color:#ffffff;letter-spacing:1px;">Reservation Cancelled</h1>
      <div style="width:50px;height:2px;background:#c9a227;margin:16px auto 0;"></div>
    </td>
  </tr>

  <!-- RED BANNER -->
  <tr>
    <td style="background:#c0392b;padding:14px 40px;text-align:center;">
      <p style="margin:0;font-size:14px;color:#fff;letter-spacing:1px;">
        Your reservation has been successfully cancelled
      </p>
    </td>
  </tr>

  <!-- BODY -->
  <tr>
    <td style="background:#ffffff;padding:40px;">

      <p style="margin:0 0 8px;font-size:16px;color:#333;">Dear <strong>$cust_name</strong>,</p>
      <p style="margin:0 0 30px;font-size:14px;color:#666;line-height:1.6;">
        We have received your cancellation request and your reservation has been removed from our system.
      </p>

      <!-- CANCELLED BOOKING DETAILS -->
      <table width="100%" cellpadding="0" cellspacing="0"
             style="border:1px solid #e8e8e8;border-radius:6px;overflow:hidden;margin-bottom:30px;">
        <tr style="background:#fafafa;">
          <td colspan="2" style="padding:14px 20px;border-bottom:1px solid #e8e8e8;">
            <p style="margin:0;font-size:11px;letter-spacing:2px;color:#999;text-transform:uppercase;font-weight:bold;">
              Cancelled Booking
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:13px 20px;color:#888;font-size:13px;border-bottom:1px solid #f0f0f0;width:130px;">Date</td>
          <td style="padding:13px 20px;color:#aaa;font-size:13px;text-decoration:line-through;border-bottom:1px solid #f0f0f0;">$formatted_date</td>
        </tr>
        <tr style="background:#fafafa;">
          <td style="padding:13px 20px;color:#888;font-size:13px;border-bottom:1px solid #f0f0f0;">Time</td>
          <td style="padding:13px 20px;color:#aaa;font-size:13px;text-decoration:line-through;border-bottom:1px solid #f0f0f0;">$cust_time</td>
        </tr>
        <tr>
          <td style="padding:13px 20px;color:#888;font-size:13px;">Guests</td>
          <td style="padding:13px 20px;color:#aaa;font-size:13px;text-decoration:line-through;">$cust_guests</td>
        </tr>
      </table>

      <!-- REBOOK CTA -->
      <p style="margin:0 0 16px;font-size:13px;color:#888;text-align:center;">
        We hope to welcome you another time. Make a new reservation anytime.
      </p>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td align="center">
            <a href="$reserve_link"
               style="display:inline-block;padding:12px 32px;background:#c9a227;color:#ffffff;
                      font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;letter-spacing:0.5px;">
              Make a New Reservation
            </a>
          </td>
        </tr>
      </table>

    </td>
  </tr>

  <!-- FOOTER -->
  <tr>
    <td style="background:#1a1a2e;padding:24px 40px;border-radius:0 0 8px 8px;text-align:center;">
      <p style="margin:0 0 6px;font-size:12px;color:#c9a227;letter-spacing:2px;text-transform:uppercase;">
        Thank you for letting us know
      </p>
      <p style="margin:0;font-size:11px;color:#666;">
        &copy; $year Restaurant. All rights reserved.
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
        sendEmail($booking['email'], $subject, $message);

    } else {
        $error = "Unable to cancel. The booking may have already been processed.";
    }
}

$formatted_date = $booking ? date("l, d F Y", strtotime($booking['booking_date'])) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cancel Reservation</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Inter:wght@400;500&display=swap" rel="stylesheet">
<style>

body {
  font-family: 'Inter', sans-serif;
  background: #f8f8f8;
  margin: 0;
  padding: 0;
}

.container {
  max-width: 580px;
  margin: 70px auto;
  background: white;
  border-radius: 8px;
  box-shadow: 0 8px 25px rgba(0,0,0,0.08);
  overflow: hidden;
}

.banner {
  padding: 30px;
  text-align: center;
}

.banner.cancel-banner  { background: #dc3545; }
.banner.error-banner   { background: #6c757d; }
.banner.success-banner { background: #28a745; }

.banner h2 {
  font-family: 'Playfair Display', serif;
  color: white;
  margin: 0;
  font-size: 26px;
}

.banner p {
  color: rgba(255,255,255,0.85);
  margin: 8px 0 0;
}

.body {
  padding: 36px;
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

.detail-row:last-child { border-bottom: none; }

.detail-row .label {
  width: 120px;
  padding: 11px 16px;
  color: #888;
  font-size: 14px;
  background: #fafafa;
  flex-shrink: 0;
}

.detail-row .value {
  padding: 11px 16px;
  color: #222;
  font-size: 14px;
  font-weight: 500;
}

.warning-box {
  background: #fff8e6;
  border: 1px solid #f5c842;
  border-radius: 6px;
  padding: 14px 16px;
  font-size: 14px;
  color: #7a5800;
  margin-bottom: 24px;
}

.actions {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
}

.btn {
  flex: 1;
  padding: 12px;
  border-radius: 5px;
  text-align: center;
  font-weight: 500;
  font-size: 15px;
  cursor: pointer;
  transition: 0.2s;
  text-decoration: none;
  display: inline-block;
}

.btn-danger {
  background: #dc3545;
  color: white;
  border: none;
}

.btn-danger:hover { background: #b02a37; }

.btn-secondary {
  background: white;
  color: #555;
  border: 1px solid #ccc;
}

.btn-secondary:hover { background: #f0f0f0; }

.error-msg {
  color: #dc3545;
  background: #fdf0f0;
  border: 1px solid #f5c6cb;
  border-radius: 6px;
  padding: 14px 16px;
  font-size: 14px;
}

</style>
</head>
<body>

<div class="container">

<?php if ($error): ?>

  <div class="banner error-banner">
    <h2>Cannot Cancel</h2>
  </div>
  <div class="body">
    <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
    <br>
    <a href="reserve.php" class="btn btn-secondary" style="text-align:center;display:block;">
      Make a New Reservation
    </a>
  </div>

<?php elseif ($success): ?>

  <div class="banner success-banner">
    <h2>Reservation Cancelled</h2>
    <p>We're sorry to see you go</p>
  </div>
  <div class="body">
    <p style="color:#444;">
      Your reservation for <strong><?php echo $formatted_date; ?></strong>
      at <strong><?php echo date("H:i", strtotime($booking['booking_time'])); ?></strong> has been cancelled.
      A confirmation email has been sent to <strong><?php echo htmlspecialchars($booking['email']); ?></strong>.
    </p>
    <a href="reserve.php" class="btn btn-secondary" style="display:block;text-align:center;color:#c9a227;border-color:#c9a227;">
      Make a New Reservation
    </a>
  </div>

<?php else: ?>

  <div class="banner cancel-banner">
    <h2>Cancel Reservation</h2>
    <p>Booking #<?php echo $booking['id']; ?></p>
  </div>

  <div class="body">

    <p style="color:#444;margin-top:0;">
      You are about to cancel the following reservation:
    </p>

    <div class="details">
      <div class="detail-row">
        <div class="label">Name</div>
        <div class="value"><?php echo htmlspecialchars($booking['customer_name']); ?></div>
      </div>
      <div class="detail-row">
        <div class="label">Date</div>
        <div class="value"><?php echo $formatted_date; ?></div>
      </div>
      <div class="detail-row">
        <div class="label">Time</div>
        <div class="value"><?php echo date("H:i", strtotime($booking['booking_time'])); ?></div>
      </div>
      <div class="detail-row">
        <div class="label">Guests</div>
        <div class="value"><?php echo $booking['num_guests']; ?></div>
      </div>
      <div class="detail-row">
        <div class="label">Table</div>
        <div class="value">Table <?php echo $booking['assigned_table']; ?></div>
      </div>
    </div>

    <div class="warning-box">
      &#9888; This action cannot be undone. You will receive a cancellation email once confirmed.
    </div>

    <form method="POST">
      <div class="actions">
        <a href="index.php" class="btn btn-secondary">Keep My Reservation</a>
        <button type="submit" name="confirm_cancel" class="btn btn-danger">
          Yes, Cancel It
        </button>
      </div>
    </form>

  </div>

<?php endif; ?>

</div>

</body>
</html>
