<?php include("config/db.php"); ?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reserve Table</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

*{
margin:0;
padding:0;
box-sizing:border-box;
font-family: 'Segoe UI', sans-serif;
}

body{
height:100vh;
background:
linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)),
url('images/restaurant-bg.jpg');
background-size:cover;
background-position:center;
display:flex;
align-items:center;
justify-content:center;
}

.reserve-wrapper{
width:100%;
max-width:450px;
padding:20px;
}

.reserve-card{
background:rgba(255,255,255,0.1);
backdrop-filter:blur(15px);
border-radius:15px;
padding:35px;
color:white;
box-shadow:0 10px 30px rgba(0,0,0,0.4);
}

.reserve-card h2{
text-align:center;
margin-bottom:25px;
font-size:28px;
letter-spacing:1px;
}

form label{
font-size:14px;
margin-top:12px;
display:block;
}

.input-group{
position:relative;
margin-top:5px;
}

.input-group i{
position:absolute;
left:12px;
top:12px;
color:#ccc;
}

.input-group input,
.input-group select{
width:100%;
padding:10px 10px 10px 35px;
border:none;
border-radius:6px;
outline:none;
font-size:14px;
}

.input-group input:focus,
.input-group select:focus{
box-shadow:0 0 5px #ff9800;
}

button{
width:100%;
margin-top:20px;
padding:12px;
border:none;
border-radius:6px;
background:#ff9800;
color:white;
font-size:16px;
cursor:pointer;
transition:0.3s;
}

button:hover{
background:#e68900;
transform:scale(1.03);
}

.back-home{
display:block;
text-align:center;
margin-top:18px;
color:#ddd;
text-decoration:none;
font-size:14px;
}

.back-home:hover{
color:white;
}

@media(max-width:500px){
.reserve-card{
padding:25px;
}
}

</style>

</head>

<body>

<div class="reserve-wrapper">

<div class="reserve-card">

<h2>🍽 Reserve a Table</h2>

<form method="POST" action="submit_reservation.php">

<label>Full Name</label>
<div class="input-group">
<i class="fa fa-user"></i>
<input type="text" name="name" required>
</div>

<label>Email</label>
<div class="input-group">
<i class="fa fa-envelope"></i>
<input type="email" name="email" required>
</div>

<label>Phone</label>
<div class="input-group">
<i class="fa fa-phone"></i>
<input type="text" name="phone" required>
</div>

<label>Date</label>
<div class="input-group">
<i class="fa fa-calendar"></i>
<input type="text" id="date" name="date" required>
</div>

<label>Guests</label>
<div class="input-group">
<i class="fa fa-users"></i>
<input type="number" name="guests" min="1" max="12" required>
</div>

<label>Time</label>
<div class="input-group">
<i class="fa fa-clock"></i>
<select name="time">
<option>17:00</option>
<option>17:30</option>
<option>18:00</option>
<option>18:30</option>
<option>19:00</option>
<option>19:30</option>
<option>20:00</option>
</select>
</div>

<button type="submit" name="reserve">Reserve Table</button>

</form>

<a href="index.php" class="back-home">← Back to Home</a>

</div>

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