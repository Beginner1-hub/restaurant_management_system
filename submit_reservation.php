<?php
include("config/db.php");
include("config/mail.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: reserve.php");
    exit;
}

$name   = trim($_POST['name']);
$email  = trim($_POST['email']);
$phone  = trim($_POST['phone']);
$date   = trim($_POST['date']);
$time   = trim($_POST['time']);
$guests = intval($_POST['guests']);

/* ── find an available table ─────────────────────────────────────── */
$sql = "
    SELECT * FROM tables
    WHERE capacity >= ?
    AND id NOT IN (
        SELECT assigned_table FROM bookings
        WHERE booking_date = ?
        AND booking_time  = ?
        AND status != 'cancelled'
    )
    ORDER BY capacity ASC
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $guests, $date, $time);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: reserve.php?error=no_table&date=$date&time=$time&guests=$guests");
    exit;
}

$table_id = $result->fetch_assoc()['id'];

/* ── generate a secure cancellation token ────────────────────────── */
$cancel_token = bin2hex(random_bytes(32));

/* ── insert booking ──────────────────────────────────────────────── */
$ins = $conn->prepare("
    INSERT INTO bookings
    (customer_name, email, phone, booking_date, booking_time, num_guests, assigned_table, status, cancel_token)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)
");
$ins->bind_param("sssssiss", $name, $email, $phone, $date, $time, $guests, $table_id, $cancel_token);
$ins->execute();
$booking_id = $conn->insert_id;

/* ── build cancel link ───────────────────────────────────────────── */
$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
      . '://' . $_SERVER['HTTP_HOST']
      . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

$cancel_link    = $base . "/cancel_booking.php?token=" . $cancel_token;
$formatted_date = date("l, d F Y", strtotime($date));
$year           = date('Y');

/* ── professional confirmation email ────────────────────────────── */
$subject = "Reservation Confirmed - $formatted_date";

$body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#f4f4f4;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

  <!-- HEADER -->
  <tr>
    <td style="background:#1a1a2e;padding:36px 40px;border-radius:8px 8px 0 0;text-align:center;">
      <p style="margin:0 0 6px 0;font-size:12px;letter-spacing:3px;color:#c9a227;text-transform:uppercase;">Restaurant</p>
      <h1 style="margin:0;font-size:28px;font-weight:300;color:#ffffff;letter-spacing:1px;">Reservation Confirmed</h1>
      <div style="width:50px;height:2px;background:#c9a227;margin:16px auto 0;"></div>
    </td>
  </tr>

  <!-- GOLD BANNER -->
  <tr>
    <td style="background:#c9a227;padding:14px 40px;text-align:center;">
      <p style="margin:0;font-size:14px;color:#fff;letter-spacing:1px;">
        Booking Reference &nbsp;&#9474;&nbsp; <strong style="font-size:16px;">#$booking_id</strong>
      </p>
    </td>
  </tr>

  <!-- BODY -->
  <tr>
    <td style="background:#ffffff;padding:40px;">

      <p style="margin:0 0 8px;font-size:16px;color:#333;">Dear <strong>$name</strong>,</p>
      <p style="margin:0 0 30px;font-size:14px;color:#666;line-height:1.6;">
        Thank you for your reservation. We are delighted to confirm your upcoming visit and look forward to welcoming you.
      </p>

      <!-- BOOKING DETAILS BOX -->
      <table width="100%" cellpadding="0" cellspacing="0"
             style="border:1px solid #e8e8e8;border-radius:6px;overflow:hidden;margin-bottom:30px;">
        <tr style="background:#fafafa;">
          <td colspan="2" style="padding:14px 20px;border-bottom:1px solid #e8e8e8;">
            <p style="margin:0;font-size:11px;letter-spacing:2px;color:#c9a227;text-transform:uppercase;font-weight:bold;">
              Booking Details
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:13px 20px;color:#888;font-size:13px;border-bottom:1px solid #f0f0f0;width:130px;">Date</td>
          <td style="padding:13px 20px;color:#222;font-size:13px;font-weight:600;border-bottom:1px solid #f0f0f0;">$formatted_date</td>
        </tr>
        <tr style="background:#fafafa;">
          <td style="padding:13px 20px;color:#888;font-size:13px;border-bottom:1px solid #f0f0f0;">Time</td>
          <td style="padding:13px 20px;color:#222;font-size:13px;font-weight:600;border-bottom:1px solid #f0f0f0;">$time</td>
        </tr>
        <tr>
          <td style="padding:13px 20px;color:#888;font-size:13px;border-bottom:1px solid #f0f0f0;">Guests</td>
          <td style="padding:13px 20px;color:#222;font-size:13px;font-weight:600;border-bottom:1px solid #f0f0f0;">$guests</td>
        </tr>
        <tr style="background:#fafafa;">
          <td style="padding:13px 20px;color:#888;font-size:13px;border-bottom:1px solid #f0f0f0;">Table</td>
          <td style="padding:13px 20px;color:#222;font-size:13px;font-weight:600;border-bottom:1px solid #f0f0f0;">Table $table_id</td>
        </tr>
        <tr>
          <td style="padding:13px 20px;color:#888;font-size:13px;">Status</td>
          <td style="padding:13px 20px;font-size:13px;">
            <span style="background:#e6f9f0;color:#1a9e5c;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid #a3e0c1;">
              Confirmed
            </span>
          </td>
        </tr>
      </table>

      <!-- NOTE -->
      <table width="100%" cellpadding="0" cellspacing="0"
             style="background:#f9f5ea;border-left:3px solid #c9a227;border-radius:0 4px 4px 0;margin-bottom:30px;">
        <tr>
          <td style="padding:14px 18px;font-size:13px;color:#666;line-height:1.6;">
            Please arrive 5-10 minutes before your reservation time. If you have any special requests or dietary requirements, feel free to contact us.
          </td>
        </tr>
      </table>

      <!-- CANCEL SECTION -->
      <p style="margin:0 0 16px;font-size:13px;color:#888;text-align:center;">
        Plans changed? You can cancel your reservation anytime using the button below.
      </p>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td align="center">
            <a href="$cancel_link"
               style="display:inline-block;padding:12px 32px;background:#ffffff;color:#cc3333;
                      font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;
                      border:2px solid #cc3333;letter-spacing:0.5px;">
              Cancel My Reservation
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
        We look forward to seeing you
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

sendEmail($email, $subject, $body);

/* ── redirect to confirmation page ──────────────────────────────── */
header("Location: booking_confirmed.php?id=$booking_id&token=$cancel_token");
exit;
?>
