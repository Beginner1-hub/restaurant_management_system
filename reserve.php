<?php
/* No login required — open to all visitors */
include("config/db.php");

$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'no_table') {
    $error = "Sorry, no tables are available for your selected date, time, and party size. Please try a different time slot.";
}

$pre_date   = isset($_GET['date'])   ? htmlspecialchars($_GET['date'])   : '';
$pre_time   = isset($_GET['time'])   ? htmlspecialchars($_GET['time'])   : '';
$pre_guests = isset($_GET['guests']) ? intval($_GET['guests'])           : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reserve Table</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Inter:wght@400;500&display=swap" rel="stylesheet">

<style>

body{
font-family: 'Inter', sans-serif;
background:#f8f8f8;
margin:0;
padding:0;
}

/* container */

.reserve-container{
max-width:850px;
margin:70px auto;
background:white;
padding:45px;
border-radius:8px;
box-shadow:0 8px 25px rgba(0,0,0,0.08);
}

/* title */

.reserve-title{
text-align:center;
margin-bottom:35px;
}

.reserve-title h2{
font-family:'Playfair Display',serif;
font-size:34px;
margin:0;
color:#222;
}

.reserve-title p{
color:#777;
margin-top:8px;
}

/* divider */

.divider{
width:60px;
height:3px;
background:#c9a227;
margin:15px auto 25px auto;
}

/* grid */

.form-grid{
display:grid;
grid-template-columns:1fr 1fr;
gap:20px;
}

/* form fields */

.form-group{
display:flex;
flex-direction:column;
}

.form-group label{
font-size:14px;
margin-bottom:6px;
color:#444;
font-weight:500;
}

.form-group input,
.form-group select{
padding:11px;
border:1px solid #ddd;
border-radius:5px;
font-size:14px;
transition:0.25s;
background:#fafafa;
}

.form-group input:focus,
.form-group select:focus{
border-color:#c9a227;
background:white;
outline:none;
box-shadow:0 0 4px rgba(201,162,39,0.3);
}

/* button */

.reserve-btn{
margin-top:30px;
width:100%;
padding:13px;
border:none;
background:#c9a227;
color:white;
font-size:16px;
font-weight:500;
border-radius:5px;
cursor:pointer;
transition:0.3s;
letter-spacing:0.5px;
}

.reserve-btn:hover{
background:#a8861f;
transform:translateY(-1px);
}

/* back link */

.back-home{
display:block;
text-align:center;
margin-top:18px;
text-decoration:none;
color:#666;
font-size:14px;
}

.back-home:hover{
color:#000;
}

/* responsive */

@media(max-width:700px){

.form-grid{
grid-template-columns:1fr;
}

.reserve-container{
margin:40px 15px;
padding:30px;
}

}

</style>

</head>

<body>

<div class="reserve-container">

<div class="reserve-title">
<h2>Reserve Your Table</h2>
<div class="divider"></div>
<p>Book in advance to enjoy a perfect dining experience</p>
</div>

<?php if($error): ?>
<div style="background:#fdf0f0;border:1px solid #f5c6cb;border-radius:6px;
            padding:13px 16px;margin-bottom:22px;color:#dc3545;font-size:14px;">
  <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<form method="POST" action="submit_reservation.php">

<div class="form-grid">

<div class="form-group">
<label>Full Name</label>
<input type="text" name="name" required>
</div>

<div class="form-group">
<label>Email</label>
<input type="email" name="email" required>
</div>

<div class="form-group">
<label>Phone</label>
<input type="text" name="phone" required>
</div>

<div class="form-group">
<label>Guests</label>
<input type="number" name="guests" min="1" max="12" required
  value="<?php echo $pre_guests ?: ''; ?>">
</div>

<div class="form-group">
<label>Date</label>
<input type="text" id="date" name="date" required
  value="<?php echo $pre_date; ?>">
</div>

<div class="form-group">
<label>Time</label>
<select name="time">
<?php
$times = ['17:00','17:30','18:00','18:30','19:00','19:30','20:00'];
foreach($times as $t){
  $sel = ($pre_time === $t) ? ' selected' : '';
  echo "<option$sel>$t</option>";
}
?>
</select>
</div>

</div>

<button class="reserve-btn" type="submit" name="reserve">
Reserve Table
</button>

</form>

<a href="index.php" class="back-home">← Back to Home</a>

</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
flatpickr("#date",{
minDate:"today",
dateFormat:"Y-m-d"
});
</script>

</body>

</html>