<?php
session_start();
include("../config/db.php");

/* ── ROLE ACCESS ─────────────────────────────────────────────────
 *  admin          → full (drag, walk-in, status update)
 *  waiter/kitchen/cashier → view only
 * ──────────────────────────────────────────────────────────────── */
if (!isset($_SESSION['user'])) { header("Location: ../auth/login.php"); exit(); }
$role     = $_SESSION['user']['role'];
$username = $_SESSION['user']['username'];
$is_admin = ($role === 'admin');
if (!in_array($role, ['admin','waiter','kitchen','cashier'])) {
    header("Location: ../auth/login.php"); exit();
}

/* ── LIST-VIEW STATUS FORM (regular POST) ───────────────────────── */
if ($is_admin && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_status'])) {
    $bid = intval($_POST['booking_id'] ?? 0);
    $bs  = $_POST['new_status'] ?? '';
    if ($bid && in_array($bs, ['confirmed','pending','seated','completed','cancelled'])) {
        $s = $conn->prepare("UPDATE bookings SET status=? WHERE id=?");
        $s->bind_param("si", $bs, $bid); $s->execute();
    }
    $rd = $_POST['current_date'] ?? date('Y-m-d');
    header("Location: reservations.php?date=$rd"); exit;
}

/* ── AJAX HANDLERS ───────────────────────────────────────────────
 * Handled in the same file — returns JSON, then exits
 * ──────────────────────────────────────────────────────────────── */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (!$is_admin) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

    /* ── update booking status ── */
    if ($_GET['action'] === 'update_status') {
        $id  = intval($_POST['booking_id'] ?? 0);
        $st  = $_POST['new_status'] ?? '';
        $allowed = ['confirmed','pending','seated','completed','cancelled'];
        if (!$id || !in_array($st, $allowed)) {
            echo json_encode(['success'=>false,'error'=>'Invalid request']); exit;
        }
        $s = $conn->prepare("UPDATE bookings SET status=? WHERE id=?");
        $s->bind_param("si", $st, $id); $s->execute();
        echo json_encode(['success'=>true,'status'=>$st]);
        exit;
    }

    /* ── move booking to new table ── */
    if ($_GET['action'] === 'update_table') {
        $id       = intval($_POST['id'] ?? 0);
        $newTable = intval($_POST['table'] ?? 0);
        $date     = $conn->real_escape_string($_POST['date'] ?? date('Y-m-d'));

        /* get booking details */
        $bq = $conn->prepare("SELECT * FROM bookings WHERE id=?");
        $bq->bind_param("i", $id); $bq->execute();
        $bk = $bq->get_result()->fetch_assoc();
        if (!$bk) { echo json_encode(['success'=>false,'error'=>'Booking not found']); exit; }

        /* conflict check: any non-cancelled booking on newTable within 90 min? */
        $cq = $conn->prepare("
            SELECT id FROM bookings
            WHERE assigned_table=? AND booking_date=? AND id!=?
            AND status NOT IN ('cancelled','completed')
            AND ABS(TIME_TO_SEC(booking_time) - TIME_TO_SEC(?)) < 5400
        ");
        $cq->bind_param("isis", $newTable, $date, $id, $bk['booking_time']);
        $cq->execute();
        if ($cq->get_result()->num_rows > 0) {
            echo json_encode(['success'=>false,'error'=>"Table $newTable is already booked at that time"]); exit;
        }

        $upd = $conn->prepare("UPDATE bookings SET assigned_table=? WHERE id=?");
        $upd->bind_param("ii", $newTable, $id); $upd->execute();
        echo json_encode(['success'=>true,'old_table'=>$bk['assigned_table'],'new_table'=>$newTable]);
        exit;
    }

    /* ── walk-in booking ── */
    if ($_GET['action'] === 'walkin') {
        $name   = trim($_POST['name'] ?? '');
        $guests = intval($_POST['guests'] ?? 1);
        $time   = $_POST['time'] ?? date('H:00');
        $date   = $_POST['date'] ?? date('Y-m-d');
        if (!$name) { echo json_encode(['success'=>false,'error'=>'Name required']); exit; }

        /* find a free table */
        $tq = $conn->prepare("
            SELECT id FROM tables WHERE capacity >= ?
            AND id NOT IN (
                SELECT assigned_table FROM bookings
                WHERE booking_date=? AND status NOT IN ('cancelled','completed')
                AND ABS(TIME_TO_SEC(booking_time) - TIME_TO_SEC(?)) < 5400
            ) ORDER BY capacity ASC LIMIT 1
        ");
        $tq->bind_param("iss", $guests, $date, $time); $tq->execute();
        $t = $tq->get_result()->fetch_assoc();
        if (!$t) { echo json_encode(['success'=>false,'error'=>'No tables available for that time']); exit; }

        $token = bin2hex(random_bytes(16));
        $phone = 'Walk-in'; $email = '';
        $ins = $conn->prepare("
            INSERT INTO bookings
            (customer_name,email,phone,booking_date,booking_time,num_guests,assigned_table,status,cancel_token)
            VALUES(?,?,?,?,?,?,?,'seated',?)
        ");
        $ins->bind_param("sssssiss", $name, $email, $phone, $date, $time, $guests, $t['id'], $token);
        $ins->execute();
        $newId = $conn->insert_id;

        echo json_encode(['success'=>true,'booking'=>[
            'id'=>$newId,'customer_name'=>$name,'email'=>'','phone'=>'Walk-in',
            'booking_date'=>$date,'booking_time'=>$time,'num_guests'=>$guests,
            'assigned_table'=>$t['id'],'status'=>'seated','cancel_token'=>$token
        ]]);
        exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']); exit;
}

/* ── DATE & CALENDAR ─────────────────────────────────────────────── */
$date      = $_GET['date'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime("$date -1 day"));
$next_date = date('Y-m-d', strtotime("$date +1 day"));
$fmt_long  = date('l j F Y', strtotime($date));
$is_today  = ($date === date('Y-m-d'));

$cal_y = (int)substr($date,0,4); $cal_m = (int)substr($date,5,2);
if (isset($_GET['cal'])) [$cal_y,$cal_m] = array_map('intval', explode('-',$_GET['cal']));

/* ── FETCH DATA ──────────────────────────────────────────────────── */
$tables_res = $conn->query("SELECT * FROM tables ORDER BY id");
$tables_arr = $tables_res->fetch_all(MYSQLI_ASSOC);

$bs = $conn->prepare("SELECT * FROM bookings WHERE booking_date=? ORDER BY booking_time ASC");
$bs->bind_param("s",$date); $bs->execute();
$bookings_arr = $bs->get_result()->fetch_all(MYSQLI_ASSOC);

$total_b = count($bookings_arr);
$total_g = array_sum(array_column($bookings_arr,'num_guests'));
$active  = array_filter($bookings_arr, fn($b)=>!in_array($b['status'],['completed','cancelled']));
$rem_g   = array_sum(array_column($active,'num_guests'));

/* occupancy per table (for labels) */
$table_bk_count = [];
foreach ($bookings_arr as $b) {
    if ($b['status'] !== 'cancelled') {
        $table_bk_count[$b['assigned_table']] = ($table_bk_count[$b['assigned_table']] ?? 0) + 1;
    }
}

/* ── MINI CALENDAR ───────────────────────────────────────────────── */
function miniCal($y,$m,$sel) {
    $py=$m===1?$y-1:$y; $pm=$m===1?12:$m-1;
    $ny=$m===12?$y+1:$y; $nm=$m===12?1:$m+1;
    $ft=mktime(0,0,0,$m,1,$y); $dim=date('t',$ft); $dow=(int)date('N',$ft); $td=date('Y-m-d');
    $h='<div class="mc"><div class="mc-hd">';
    $h.="<a href='?cal={$py}-{$pm}&date=$sel'>&#8249;</a><span>".date('F Y',$ft)."</span><a href='?cal={$ny}-{$nm}&date=$sel'>&#8250;</a></div>";
    $h.='<table><tr>';
    foreach(['Mo','Tu','We','Th','Fr','Sa','Su'] as $d) $h.="<th>$d</th>";
    $h.='</tr><tr>';
    for($i=1;$i<$dow;$i++) $h.='<td></td>';
    $col=$dow;
    for($d=1;$d<=$dim;$d++){
        $ds=sprintf('%04d-%02d-%02d',$y,$m,$d); $c='';
        if($ds===$td) $c.=' mc-td'; if($ds===$sel) $c.=' mc-sel';
        $h.="<td class='mc-day$c'><a href='?date=$ds'>$d</a></td>";
        if($col%7===0&&$d<$dim) $h.='</tr><tr>';
        $col++;
    }
    return $h.'</tr></table></div>';
}

/* ── TIMELINE CONSTANTS ──────────────────────────────────────────── */
define('T_S', 17*60); define('T_E', 22*60); define('SW', 110); define('DUR', 90);
function lpx($t){ $p=explode(':',$t); return ((int)$p[0]*60+(int)$p[1]-T_S)/30*SW; }
$sc=['confirmed'=>['#0d9e78','#0a7d61'],'pending'=>['#d4920a','#b07a08'],
     'seated'   =>['#2271d4','#1a5aaa'],'completed'=>['#4a4e5a','#3a3e48'],
     'cancelled'=>['#c0392b','#962d22']];
$back=['admin'=>'../admin/dashboard.php','waiter'=>'../waiter/dashboard.php',
       'kitchen'=>'../kitchen/dashboard.php','cashier'=>'../cashier/dashboard.php'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reservations</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#13141a;--s1:#1a1b23;--s2:#22232d;--s3:#2a2b36;
  --bd:#2e2f3d;--txt:#e2e3ec;--mut:#5c5f7a;--acc:#00c896;--gold:#c9a227;
  --red:#c0392b;--blue:#2271d4;--orange:#d4920a;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--txt);height:100vh;overflow:hidden;display:flex;flex-direction:column;user-select:none;}

/* ── TOPBAR ── */
.topbar{height:50px;background:var(--s1);border-bottom:1px solid var(--bd);display:flex;align-items:center;padding:0 18px;gap:20px;flex-shrink:0;}
.brand{color:var(--acc);font-weight:700;font-size:14px;letter-spacing:.5px;}
.topbar .sep{width:1px;height:18px;background:var(--bd);}
.topbar a{color:var(--mut);text-decoration:none;font-size:13px;transition:color .15s;}
.topbar a:hover,.topbar a.active{color:var(--txt);}
.sp{flex:1;}
.rbadge{padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;}
.rbadge.admin  {background:rgba(0,200,150,.15);color:var(--acc);border:1px solid rgba(0,200,150,.25);}
.rbadge.waiter {background:rgba(201,162,39,.15);color:var(--gold);border:1px solid rgba(201,162,39,.25);}
.rbadge.kitchen{background:rgba(34,113,212,.15);color:#6ab4ff;border:1px solid rgba(34,113,212,.25);}
.rbadge.cashier{background:rgba(192,57,43,.15);color:#ff9090;border:1px solid rgba(192,57,43,.25);}

/* ── LAYOUT ── */
.layout{display:flex;flex:1;overflow:hidden;}

/* ── SIDEBAR ── */
.sidebar{width:255px;background:var(--s1);border-right:1px solid var(--bd);display:flex;flex-direction:column;overflow-y:auto;flex-shrink:0;}

.mc{padding:14px;}
.mc-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.mc-hd span{font-size:13px;font-weight:500;}
.mc-hd a{color:var(--mut);text-decoration:none;font-size:17px;padding:2px 5px;border-radius:3px;}
.mc-hd a:hover{background:var(--s2);color:var(--txt);}
.mc table{width:100%;border-collapse:collapse;}
.mc th{text-align:center;font-size:10px;color:var(--mut);padding:3px 0;font-weight:500;}
.mc-day{text-align:center;padding:1px;}
.mc-day a{display:block;width:25px;height:25px;line-height:25px;border-radius:50%;font-size:11px;color:var(--mut);text-decoration:none;margin:auto;transition:.12s;}
.mc-day a:hover{background:var(--s2);color:var(--txt);}
.mc-day.mc-td a{color:var(--txt);font-weight:600;}
.mc-day.mc-sel a{background:var(--acc) !important;color:#fff !important;}

/* search */
.search-wrap{padding:0 12px 10px;}
.search-wrap input{width:100%;background:var(--s2);border:1px solid var(--bd);color:var(--txt);padding:7px 10px;border-radius:5px;font-size:12px;font-family:inherit;}
.search-wrap input:focus{outline:none;border-color:var(--acc);}
.search-wrap input::placeholder{color:var(--mut);}

/* stats */
.day-stats{padding:10px 12px;border-top:1px solid var(--bd);border-bottom:1px solid var(--bd);display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.sbox{background:var(--s2);border-radius:6px;padding:10px;}
.sbox .sv{font-size:22px;font-weight:700;line-height:1;}
.sbox .sl{font-size:10px;color:var(--mut);margin-top:3px;}
.sbox .sr{font-size:10px;color:var(--acc);margin-top:2px;}

/* booking list */
.bl-hdr{padding:10px 14px 6px;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--mut);font-weight:500;}
.bitem{padding:9px 14px;border-bottom:1px solid var(--bd);cursor:pointer;display:flex;gap:9px;align-items:flex-start;transition:background .12s;}
.bitem:hover{background:var(--s2);}
.bitem.active{background:rgba(0,200,150,.07);border-left:2px solid var(--acc);}
.bitem.search-hidden{display:none;}
.bitem.search-match .bi-name{color:var(--acc);}
.bi-dot{width:7px;height:7px;border-radius:50%;margin-top:5px;flex-shrink:0;}
.bi-info{flex:1;min-width:0;}
.bi-name{font-size:12px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bi-sub{font-size:10px;color:var(--mut);margin-top:2px;}
.bi-meta{text-align:right;flex-shrink:0;}
.bi-g{font-size:12px;font-weight:600;}
.bi-t{font-size:10px;color:var(--mut);margin-top:2px;}

/* ── MAIN ── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;position:relative;}

/* refresh bar */
.refresh-bar{height:2px;background:var(--bd);}
.refresh-prog{height:100%;background:var(--acc);transition:width 1s linear;width:100%;}

/* date header */
.dhdr{padding:12px 20px;border-bottom:1px solid var(--bd);background:var(--s1);display:flex;align-items:center;gap:12px;flex-shrink:0;}
.dbtns{display:flex;gap:5px;}
.dbtns a,.dbtns button{width:26px;height:26px;border-radius:4px;background:var(--s2);color:var(--mut);text-decoration:none;display:flex;align-items:center;justify-content:center;font-size:13px;border:1px solid var(--bd);cursor:pointer;transition:.12s;font-family:inherit;}
.dbtns a:hover,.dbtns button:hover{background:var(--s3);color:var(--txt);}
.dbtns button.today-btn{width:auto;padding:0 10px;font-size:11px;}
.dlabel{font-size:16px;font-weight:600;}
.dh-sp{flex:1;}
.vtabs{display:flex;gap:2px;background:var(--s2);padding:3px;border-radius:6px;}
.vtab{padding:4px 12px;border-radius:4px;font-size:12px;font-weight:500;color:var(--mut);cursor:pointer;border:none;background:transparent;font-family:inherit;transition:.12s;}
.vtab.active{background:var(--s1);color:var(--txt);}
.ftabs{display:flex;gap:0;}
.ftab{padding:4px 12px;border-radius:4px;font-size:12px;font-weight:500;color:var(--mut);cursor:pointer;border:none;background:transparent;font-family:inherit;transition:.12s;}
.ftab.active,.ftab:hover{color:var(--txt);}
.ftab.active{border-bottom:2px solid var(--acc);}
.sep-v{width:1px;height:18px;background:var(--bd);align-self:center;}
.btn{padding:6px 14px;border-radius:5px;font-size:12px;font-weight:500;cursor:pointer;font-family:inherit;border:none;transition:.15s;display:inline-flex;align-items:center;gap:5px;text-decoration:none;}
.btn-acc{background:var(--acc);color:#fff;}
.btn-acc:hover{background:#0ab585;}
.btn-ol{background:transparent;color:var(--txt);border:1px solid var(--bd);}
.btn-ol:hover{background:var(--s2);}
.btn-warn{background:rgba(212,146,10,.2);color:#f0b830;border:1px solid rgba(212,146,10,.3);}
.btn-warn:hover{background:rgba(212,146,10,.35);}

/* ── DIAGRAM ── */
#vdiagram{flex:1;overflow:auto;position:relative;}
.tl-wrap{min-width:max-content;}
.tl-top{display:flex;margin-left:140px;position:sticky;top:0;background:var(--s1);z-index:10;border-bottom:1px solid var(--bd);}
.tl-slt{width:<?php echo SW;?>px;flex-shrink:0;padding:7px 0 7px 10px;font-size:11px;color:var(--mut);border-left:1px solid var(--bd);}
.tl-slt.hot-1{background:rgba(0,200,150,.04);}
.tl-slt.hot-2{background:rgba(0,200,150,.1);}
.tl-slt.hot-3{background:rgba(0,200,150,.2);}
.tl-slt.hot-4{background:rgba(0,200,150,.32);}
.tl-row{display:flex;border-bottom:1px solid var(--bd);min-height:64px;position:relative;transition:background .15s;}
.tl-lbl{width:140px;flex-shrink:0;padding:10px 14px;display:flex;flex-direction:column;justify-content:center;border-right:1px solid var(--bd);background:var(--s1);position:sticky;left:0;z-index:5;}
.tl-lbl .ln{font-size:13px;font-weight:500;}
.tl-lbl .lc{font-size:10px;color:var(--mut);margin-top:2px;}
.tl-lbl .lo{font-size:10px;margin-top:3px;}
.occ-bar{height:3px;border-radius:2px;background:var(--bd);margin-top:4px;overflow:hidden;}
.occ-fill{height:100%;border-radius:2px;background:var(--acc);transition:width .3s;}
.tl-grid{flex:1;position:relative;display:flex;min-width:<?php echo (T_E-T_S)/30*SW;?>px;transition:background .15s;}
.tl-cell{width:<?php echo SW;?>px;flex-shrink:0;border-left:1px solid var(--bd);}

/* drag states */
.tl-row.drop-target .tl-grid{background:rgba(0,200,150,.06);}
.tl-row.drop-ok .tl-grid    {background:rgba(0,200,150,.15);box-shadow:inset 0 0 0 1px rgba(0,200,150,.4);}
.tl-row.drop-bad .tl-grid   {background:rgba(192,57,43,.15); box-shadow:inset 0 0 0 1px rgba(192,57,43,.4);}
.tl-row.drop-target .tl-lbl  {background:rgba(0,200,150,.05);}

/* booking block */
.bk{position:absolute;top:7px;height:50px;border-radius:6px;padding:5px 9px;overflow:hidden;
    display:flex;flex-direction:column;justify-content:center;border-left:3px solid rgba(0,0,0,.25);
    transition:box-shadow .15s,filter .15s,transform .15s;}
.bk.admin-drag{cursor:grab;}
.bk.admin-drag:hover{filter:brightness(1.1);}
.bk.dragging-src{opacity:.3;transform:scale(.97);}
.bk.search-fade{opacity:.2;}
.bk.search-glow{box-shadow:0 0 0 2px #fff,0 0 12px rgba(255,255,255,.4);}
.bk-name{font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bk-sub{font-size:10px;opacity:.85;margin-top:2px;display:flex;align-items:center;gap:3px;}

/* drag ghost */
#drag-ghost{position:fixed;z-index:9999;pointer-events:none;border-radius:6px;padding:5px 9px;
            border-left:3px solid rgba(0,0,0,.25);display:flex;flex-direction:column;justify-content:center;
            box-shadow:0 8px 24px rgba(0,0,0,.5);transform:rotate(2deg) scale(1.04);display:none;}

/* current time line */
#time-line{position:absolute;top:0;bottom:0;width:2px;background:#e74c3c;z-index:20;pointer-events:none;display:none;}
#time-line::before{content:attr(data-time);position:absolute;top:0;left:4px;font-size:10px;color:#e74c3c;background:var(--bg);padding:2px 4px;border-radius:3px;white-space:nowrap;}

/* ── LIST VIEW ── */
#vlist{flex:1;overflow:auto;padding:20px;display:none;}
.ltable{width:100%;border-collapse:collapse;}
.ltable th{background:var(--s1);padding:10px 14px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--mut);position:sticky;top:0;border-bottom:1px solid var(--bd);font-weight:500;}
.ltable td{padding:11px 14px;font-size:13px;border-bottom:1px solid var(--bd);vertical-align:middle;}
.ltable tr:hover td{background:var(--s2);}
.cn{font-weight:500;}.cs{font-size:11px;color:var(--mut);margin-top:2px;}

/* status badges */
.sb{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:capitalize;}
.sb-confirmed{background:rgba(13,158,120,.15);color:#00c896;border:1px solid rgba(13,158,120,.3);}
.sb-pending  {background:rgba(212,146,10,.15);color:#f0b830;border:1px solid rgba(212,146,10,.3);}
.sb-seated   {background:rgba(34,113,212,.15);color:#6ab4ff;border:1px solid rgba(34,113,212,.3);}
.sb-completed{background:rgba(74,78,90,.25);  color:#8a8fa8;border:1px solid rgba(74,78,90,.4);}
.sb-cancelled{background:rgba(192,57,43,.15); color:#e07070;border:1px solid rgba(192,57,43,.3);}

/* status form */
.sf select{background:var(--s2);border:1px solid var(--bd);color:var(--txt);padding:4px 7px;border-radius:4px;font-size:11px;font-family:inherit;cursor:pointer;}
.sf button{background:var(--acc);border:none;color:#fff;padding:4px 9px;border-radius:4px;font-size:10px;cursor:pointer;font-family:inherit;margin-left:3px;}

/* ── MODALS ── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;display:none;align-items:center;justify-content:center;}
.overlay.open{display:flex;}
.modal{background:var(--s1);border:1px solid var(--bd);border-radius:10px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.mhd{padding:18px 22px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;}
.mhd h3{font-size:15px;font-weight:600;}
.mclose{background:none;border:none;color:var(--mut);font-size:18px;cursor:pointer;line-height:1;padding:3px;font-family:inherit;}
.mclose:hover{color:var(--txt);}
.mbody{padding:20px 22px;}
.mrow{display:flex;padding:9px 0;border-bottom:1px solid var(--bd);}
.mrow:last-child{border-bottom:none;}
.mlbl{width:100px;font-size:11px;color:var(--mut);flex-shrink:0;padding-top:1px;}
.mval{font-size:13px;font-weight:500;}
.mfoot{padding:14px 22px;border-top:1px solid var(--bd);}
.mfoot select{width:100%;background:var(--s2);border:1px solid var(--bd);color:var(--txt);padding:8px;border-radius:5px;font-size:13px;font-family:inherit;margin-bottom:10px;}
.mfoot .btn{width:100%;justify-content:center;padding:10px;}

/* walk-in form */
.wi-form{display:flex;flex-direction:column;gap:14px;}
.wi-field label{display:block;font-size:11px;color:var(--mut);margin-bottom:5px;text-transform:uppercase;letter-spacing:.8px;}
.wi-field input,.wi-field select{width:100%;background:var(--s2);border:1px solid var(--bd);color:var(--txt);padding:9px 12px;border-radius:5px;font-size:13px;font-family:inherit;}
.wi-field input:focus,.wi-field select:focus{outline:none;border-color:var(--acc);}
.wi-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

/* ── TOASTS ── */
#toast-wrap{position:fixed;bottom:24px;right:24px;z-index:500;display:flex;flex-direction:column;gap:8px;pointer-events:none;}
.toast{background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:12px 16px;font-size:13px;min-width:260px;box-shadow:0 8px 24px rgba(0,0,0,.4);display:flex;align-items:center;gap:10px;animation:slideUp .25s ease;pointer-events:all;}
.toast.success{border-left:3px solid var(--acc);}
.toast.error  {border-left:3px solid var(--red);}
.toast.info   {border-left:3px solid var(--blue);}
.toast.undo-t {border-left:3px solid var(--orange);}
.toast-msg{flex:1;font-size:12px;}
.toast-undo{background:rgba(212,146,10,.2);color:#f0b830;border:1px solid rgba(212,146,10,.3);border-radius:4px;padding:3px 10px;font-size:11px;cursor:pointer;font-family:inherit;}
.toast-close{background:none;border:none;color:var(--mut);font-size:14px;cursor:pointer;font-family:inherit;line-height:1;}
@keyframes slideUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}

/* empty */
.empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:80px;color:var(--mut);gap:10px;}
.empty-icon{font-size:44px;opacity:.2;}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <span class="brand">&#9670; RestaurantMS</span>
  <div class="sep"></div>
  <a href="<?php echo $back[$role]; ?>">Dashboard</a>
  <a href="reservations.php" class="active">Reservations</a>
  <?php if($is_admin): ?><a href="analytics.php">Analytics</a><?php endif; ?>
  <div class="sp"></div>
  <span style="font-size:12px;color:var(--mut);"><?php echo htmlspecialchars($username); ?></span>
  <span class="rbadge <?php echo $role; ?>"><?php echo $role; ?></span>
  <div class="sep"></div>
  <a href="../auth/logout.php" style="color:#e07070;font-size:12px;">Logout</a>
</div>

<div class="layout">

<!-- SIDEBAR -->
<div class="sidebar">
  <?php echo miniCal($cal_y,$cal_m,$date); ?>

  <div class="search-wrap">
    <input type="text" id="search-input" placeholder="&#128269; Search guest name...">
  </div>

  <div class="day-stats">
    <div class="sbox">
      <div class="sv"><?php echo $total_b; ?></div>
      <div class="sl">Bookings today</div>
      <div class="sr">Remaining <?php echo count($active); ?></div>
    </div>
    <div class="sbox">
      <div class="sv"><?php echo $total_g; ?></div>
      <div class="sl">Guests today</div>
      <div class="sr">Remaining <?php echo $rem_g; ?></div>
    </div>
  </div>

  <div class="bl-hdr"><?php echo date('d M', strtotime($date)); ?> — Bookings</div>
  <div id="bl">
  <?php if(empty($bookings_arr)): ?>
    <div style="padding:18px 14px;font-size:12px;color:var(--mut);">No bookings for this day.</div>
  <?php else: foreach($bookings_arr as $i=>$b):
    $c=$sc[$b['status']]??$sc['pending'];
    $ft=date('H:i',strtotime($b['booking_time']));
  ?>
    <div class="bitem" data-id="<?php echo $b['id'];?>" data-name="<?php echo htmlspecialchars(strtolower($b['customer_name']));?>"
         onclick="openDetail(<?php echo $i;?>)">
      <div class="bi-dot" style="background:<?php echo $c[0];?>;"></div>
      <div class="bi-info">
        <div class="bi-name"><?php echo htmlspecialchars($b['customer_name']);?></div>
        <div class="bi-sub"><?php echo $ft;?> &middot; Table <?php echo $b['assigned_table'];?></div>
      </div>
      <div class="bi-meta">
        <div class="bi-g"><?php echo $b['num_guests'];?> &#128100;</div>
        <div class="bi-t"><span class="sb sb-<?php echo $b['status'];?>"><?php echo $b['status'];?></span></div>
      </div>
    </div>
  <?php endforeach; endif; ?>
  </div>
</div><!-- /sidebar -->

<!-- MAIN -->
<div class="main">
  <div class="refresh-bar"><div class="refresh-prog" id="ref-prog"></div></div>

  <!-- DATE HEADER -->
  <div class="dhdr">
    <div class="dbtns">
      <a href="?date=<?php echo $prev_date;?>&cal=<?php echo date('Y-n',strtotime($prev_date));?>">&#8249;</a>
      <a href="?date=<?php echo $next_date;?>&cal=<?php echo date('Y-n',strtotime($next_date));?>">&#8250;</a>
      <?php if(!$is_today):?><button class="today-btn" onclick="location.href='?date=<?php echo date('Y-m-d');?>'">Today</button><?php endif;?>
    </div>
    <div class="dlabel"><?php echo $fmt_long;?></div>

    <div class="dh-sp"></div>

    <div class="vtabs">
      <button class="vtab active" id="tab-d" onclick="switchView('d')">&#9783; Diagram</button>
      <button class="vtab"        id="tab-l" onclick="switchView('l')">&#9776; List</button>
    </div>
    <div class="sep-v"></div>
    <div class="ftabs">
      <button class="ftab active" onclick="filterTime('all',this)">All</button>
      <button class="ftab"        onclick="filterTime('morning',this)">Morning</button>
      <button class="ftab"        onclick="filterTime('lunch',this)">Lunch</button>
      <button class="ftab"        onclick="filterTime('evening',this)">Evening</button>
    </div>
    <?php if($is_admin):?>
    <div class="sep-v"></div>
    <button class="btn btn-warn" onclick="openWalkIn()">&#43; Walk-In</button>
    <a href="../reserve.php" class="btn btn-ol" target="_blank">&#43; New Booking</a>
    <?php endif;?>
  </div>

  <!-- DIAGRAM -->
  <div id="vdiagram">
  <div class="tl-wrap" id="tl-wrap">

    <!-- TIME HEADER -->
    <div class="tl-top" id="tl-top">
      <?php for($t=T_S;$t<T_E;$t+=30):
        $slotIdx = ($t-T_S)/30;
        echo "<div class='tl-slt' id='slot-$slotIdx'>".sprintf('%02d:%02d',$t/60,$t%60)."</div>";
      endfor; ?>
    </div>

    <!-- TABLE ROWS -->
    <?php foreach($tables_arr as $table):
      $cnt = $table_bk_count[$table['id']] ?? 0;
      $maxDay = max(1, count(array_unique(array_column($bookings_arr,'assigned_table'))) ?: 1);
      $occ = min(100, round($cnt / max(1,$total_b > 0 ? ceil($total_b/count($tables_arr)) : 1) * 100));
    ?>
    <div class="tl-row" data-table-id="<?php echo $table['id'];?>">
      <div class="tl-lbl">
        <div class="ln">Table <?php echo $table['id'];?></div>
        <div class="lc">Seats <?php echo $table['capacity'];?></div>
        <div class="lo" style="color:<?php echo $cnt>0?'var(--acc)':'var(--mut)';?>"><?php echo $cnt;?> booking<?php echo $cnt!=1?'s':'';?></div>
        <div class="occ-bar"><div class="occ-fill" style="width:<?php echo $occ;?>%"></div></div>
      </div>
      <div class="tl-grid">
        <?php for($t=T_S;$t<T_E;$t+=30): ?>
        <div class="tl-cell"></div>
        <?php endfor; ?>

        <?php foreach($bookings_arr as $i=>$b):
          if($b['assigned_table']!=$table['id']) continue;
          $c=$sc[$b['status']]??$sc['pending'];
          $lp=lpx($b['booking_time']);
          $wp=DUR/30*SW;
          if($lp<0||$lp>=(T_E-T_S)/30*SW) continue;
          $ft=date('H:i',strtotime($b['booking_time']));
        ?>
        <div class="bk <?php echo $is_admin?'admin-drag':'';?>"
             id="bk-<?php echo $b['id'];?>"
             data-id="<?php echo $b['id'];?>"
             data-table="<?php echo $b['assigned_table'];?>"
             data-time="<?php echo $b['booking_time'];?>"
             data-bk="<?php echo $i;?>"
             data-name="<?php echo htmlspecialchars(strtolower($b['customer_name']));?>"
             style="left:<?php echo $lp;?>px;width:<?php echo $wp;?>px;
                    background:<?php echo $c[0];?>;color:#fff;border-left-color:<?php echo $c[1];?>;"
             onclick="openDetail(<?php echo $i;?>)"
             <?php if($is_admin): ?>
             onmousedown="startDrag(event,this)"
             <?php endif; ?>>
          <div class="bk-name"><?php echo htmlspecialchars($b['customer_name']);?></div>
          <div class="bk-sub"><span><?php echo $ft;?></span><span>&#183;</span><span><?php echo $b['num_guests'];?> &#128100;</span></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if(empty($bookings_arr)): ?>
    <div class="empty"><div class="empty-icon">&#128197;</div><p>No bookings for <?php echo date('d F Y',strtotime($date));?></p></div>
    <?php endif; ?>

  </div><!-- /tl-wrap -->

  <!-- CURRENT TIME LINE (today only) -->
  <?php if($is_today): ?>
  <div id="time-line"></div>
  <?php endif; ?>

  </div><!-- /vdiagram -->

  <!-- LIST VIEW -->
  <div id="vlist">
  <?php if(empty($bookings_arr)): ?>
    <div class="empty"><div class="empty-icon">&#128197;</div><p>No bookings for <?php echo date('d F Y',strtotime($date));?></p></div>
  <?php else: ?>
  <table class="ltable">
    <thead><tr><th>#</th><th>Guest</th><th>Time</th><th>Guests</th><th>Table</th><th>Status</th><?php if($is_admin):?><th>Update</th><?php endif;?></tr></thead>
    <tbody>
    <?php foreach($bookings_arr as $i=>$b):
      $ft=date('H:i',strtotime($b['booking_time']));
    ?>
    <tr style="cursor:pointer" onclick="openDetail(<?php echo $i;?>)">
      <td style="color:var(--gold);font-weight:600;">#<?php echo $b['id'];?></td>
      <td><div class="cn"><?php echo htmlspecialchars($b['customer_name']);?></div>
          <div class="cs"><?php echo htmlspecialchars($b['email']);?></div>
          <div class="cs"><?php echo htmlspecialchars($b['phone']);?></div></td>
      <td><?php echo $ft;?></td>
      <td><?php echo $b['num_guests'];?></td>
      <td>Table <?php echo $b['assigned_table'];?></td>
      <td><span class="sb sb-<?php echo $b['status'];?>"><?php echo $b['status'];?></span></td>
      <?php if($is_admin):?>
      <td onclick="event.stopPropagation()">
        <form method="POST" class="sf" style="display:flex;align-items:center;">
          <input type="hidden" name="booking_id" value="<?php echo $b['id'];?>">
          <input type="hidden" name="current_date" value="<?php echo $date;?>">
          <select name="new_status">
            <?php foreach(['confirmed','pending','seated','completed','cancelled'] as $s):?>
            <option value="<?php echo $s;?>" <?php if($b['status']===$s) echo 'selected';?>><?php echo ucfirst($s);?></option>
            <?php endforeach;?>
          </select>
          <button type="submit" name="update_status">Save</button>
        </form>
      </td>
      <?php endif;?>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
  </div><!-- /vlist -->

</div><!-- /main -->
</div><!-- /layout -->

<!-- DRAG GHOST -->
<div id="drag-ghost">
  <div class="bk-name" id="dg-name"></div>
  <div class="bk-sub" id="dg-sub"></div>
</div>

<!-- BOOKING DETAIL MODAL -->
<div class="overlay" id="detail-overlay" onclick="closeOverlay('detail-overlay',event)">
<div class="modal">
  <div class="mhd">
    <h3 id="m-title">Booking Details</h3>
    <button class="mclose" onclick="closeOverlay('detail-overlay')">&#10005;</button>
  </div>
  <div class="mbody" id="m-body"></div>
  <?php if($is_admin):?>
  <div class="mfoot">
    <input type="hidden" id="m-bid">
    <select id="m-sel" style="width:100%;background:var(--s2);border:1px solid var(--bd);color:var(--txt);padding:9px;border-radius:5px;font-size:13px;font-family:inherit;margin-bottom:10px;">
      <option value="confirmed">Confirmed</option>
      <option value="pending">Pending</option>
      <option value="seated">Seated</option>
      <option value="completed">Completed</option>
      <option value="cancelled">Cancelled</option>
    </select>
    <button class="btn btn-acc" style="width:100%;justify-content:center;padding:9px;" onclick="submitStatusUpdate()">
      Update Status
    </button>
  </div>
  <?php endif;?>
</div>
</div>

<!-- WALK-IN MODAL -->
<div class="overlay" id="wi-overlay" onclick="closeOverlay('wi-overlay',event)">
<div class="modal">
  <div class="mhd">
    <h3>&#128694; Walk-In Arrival</h3>
    <button class="mclose" onclick="closeOverlay('wi-overlay')">&#10005;</button>
  </div>
  <div class="mbody">
    <div class="wi-form">
      <div class="wi-field">
        <label>Guest Name *</label>
        <input type="text" id="wi-name" placeholder="Enter guest name" autocomplete="off">
      </div>
      <div class="wi-row2">
        <div class="wi-field">
          <label>Guests</label>
          <input type="number" id="wi-guests" value="2" min="1" max="20">
        </div>
        <div class="wi-field">
          <label>Time</label>
          <input type="time" id="wi-time" value="<?php echo date('H:00');?>">
        </div>
      </div>
      <div id="wi-err" style="color:#e07070;font-size:12px;display:none;"></div>
      <button class="btn btn-acc" style="width:100%;justify-content:center;padding:10px;font-size:14px;" onclick="submitWalkIn()">
        &#10003; Seat Now
      </button>
    </div>
  </div>
</div>
</div>

<!-- TOAST CONTAINER -->
<div id="toast-wrap"></div>

<script>
/* ─────────────────────────────────────────────────────────────
   DATA & CONSTANTS
───────────────────────────────────────────────────────────── */
const bookings  = <?php echo json_encode(array_values($bookings_arr));?>;
const tables    = <?php echo json_encode(array_values($tables_arr));?>;
const isAdmin   = <?php echo $is_admin?'true':'false';?>;
const curDate   = '<?php echo $date;?>';
const isToday   = <?php echo $is_today?'true':'false';?>;
const T_START   = <?php echo T_S;?>;  // minutes
const SLOT_W    = <?php echo SW;?>;
const DURATION  = <?php echo DUR;?>;  // default booking duration (min)

/* table-time index for fast conflict checking */
function buildIndex() {
    const idx = {};
    bookings.forEach(b => {
        if (!idx[b.assigned_table]) idx[b.assigned_table] = [];
        idx[b.assigned_table].push(b);
    });
    return idx;
}
let tableIndex = buildIndex();

function tMins(t) {
    const p = (t||'').split(':');
    return (parseInt(p[0])||0)*60 + (parseInt(p[1])||0);
}
function hasConflict(tableId, excludeId, timeStr) {
    const list = tableIndex[tableId] || [];
    const newM = tMins(timeStr);
    return list.some(b => b.id!=excludeId
        && !['cancelled','completed'].includes(b.status)
        && Math.abs(tMins(b.booking_time) - newM) < DURATION);
}

/* ─────────────────────────────────────────────────────────────
   DRAG & DROP — custom mouse-based
───────────────────────────────────────────────────────────── */
let dragging = false;
let dragBk   = null;       // booking object
let dragEl   = null;       // original .bk element
const ghost  = document.getElementById('drag-ghost');
const dgName = document.getElementById('dg-name');
const dgSub  = document.getElementById('dg-sub');

function startDrag(e, el) {
    if (!isAdmin || e.button !== 0) return;
    e.preventDefault(); e.stopPropagation();

    const idx = parseInt(el.dataset.bk);
    dragBk = bookings[idx]; if (!dragBk) return;
    dragEl = el;

    dragging = true;
    dragEl.classList.add('dragging-src');

    /* style ghost */
    ghost.style.cssText = `
        display:flex; width:${el.offsetWidth}px; height:${el.offsetHeight}px;
        background:${el.style.background}; color:#fff;
        border-left:3px solid rgba(0,0,0,.3);
        left:${e.clientX - el.offsetWidth/2}px;
        top:${e.clientY - el.offsetHeight/2}px;
    `;
    dgName.textContent = dragBk.customer_name;
    dgSub.textContent  = dragBk.booking_time.substring(0,5) + ' · ' + dragBk.num_guests + ' guests';

    /* highlight all rows */
    document.querySelectorAll('.tl-row').forEach(r => r.classList.add('drop-target'));

    document.addEventListener('mousemove', onDragMove);
    document.addEventListener('mouseup',   onDragEnd);
}

function onDragMove(e) {
    if (!dragging) return;
    ghost.style.left = (e.clientX - ghost.offsetWidth/2) + 'px';
    ghost.style.top  = (e.clientY - ghost.offsetHeight/2) + 'px';

    document.querySelectorAll('.tl-row').forEach(row => {
        const r = row.getBoundingClientRect();
        const over = e.clientY >= r.top && e.clientY <= r.bottom
                  && e.clientX >= r.left && e.clientX <= r.right;
        row.classList.toggle('drop-ok',  over && !hasConflict(parseInt(row.dataset.tableId), dragBk.id, dragBk.booking_time));
        row.classList.toggle('drop-bad', over &&  hasConflict(parseInt(row.dataset.tableId), dragBk.id, dragBk.booking_time));
    });
}

function onDragEnd(e) {
    if (!dragging) return;
    dragging = false;
    ghost.style.display = 'none';
    dragEl.classList.remove('dragging-src');
    document.removeEventListener('mousemove', onDragMove);
    document.removeEventListener('mouseup',   onDragEnd);

    /* find target row */
    let targetRow = null;
    document.querySelectorAll('.tl-row').forEach(row => {
        const r = row.getBoundingClientRect();
        if (e.clientY>=r.top && e.clientY<=r.bottom && e.clientX>=r.left && e.clientX<=r.right)
            targetRow = row;
        row.classList.remove('drop-target','drop-ok','drop-bad');
    });

    if (!targetRow) return;
    const newTbl = parseInt(targetRow.dataset.tableId);
    if (newTbl === dragBk.assigned_table) return;

    if (hasConflict(newTbl, dragBk.id, dragBk.booking_time)) {
        showToast(`Table ${newTbl} is already booked at ${dragBk.booking_time.substring(0,5)}`, 'error'); return;
    }

    doTableChange(dragBk.id, newTbl, dragBk.assigned_table, dragEl, targetRow);
}

function doTableChange(bookingId, newTbl, oldTbl, bkEl, targetRow) {
    fetch(`reservations.php?action=update_table`, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`id=${bookingId}&table=${newTbl}&date=${curDate}`
    })
    .then(r=>r.json())
    .then(data=>{
        if (!data.success) { showToast(data.error||'Failed to move', 'error'); return; }

        /* move DOM element */
        const newGrid = targetRow.querySelector('.tl-grid');
        bkEl.style.transition = 'opacity .2s';
        bkEl.style.opacity = '0';
        setTimeout(()=>{
            newGrid.appendChild(bkEl);
            bkEl.setAttribute('data-table', newTbl);
            bkEl.style.opacity = '1';
        }, 200);

        /* update data */
        const bk = bookings.find(b=>b.id==bookingId);
        if (bk) bk.assigned_table = newTbl;
        tableIndex = buildIndex();

        /* update sidebar */
        const si = document.querySelector(`.bitem[data-id="${bookingId}"] .bi-sub`);
        if (si) si.textContent = si.textContent.replace(/Table \d+/, `Table ${newTbl}`);

        /* update table labels */
        updateTableLabels();

        pushUndo({bookingId, oldTbl, newTbl, bkEl, targetRow, oldGrid: targetRow.parentElement?.querySelector(`[data-table-id="${oldTbl}"] .tl-grid`)});
        showToast(`Moved to Table ${newTbl}`, 'success');
    })
    .catch(()=>showToast('Network error', 'error'));
}

function updateTableLabels() {
    const counts = {};
    bookings.forEach(b=>{ if(b.status!=='cancelled') counts[b.assigned_table]=(counts[b.assigned_table]||0)+1; });
    document.querySelectorAll('.tl-row').forEach(row=>{
        const tid = parseInt(row.dataset.tableId);
        const lo  = row.querySelector('.lo');
        if (lo) { const c=counts[tid]||0; lo.textContent=`${c} booking${c!==1?'s':''}`; }
    });
}

/* ─────────────────────────────────────────────────────────────
   UNDO STACK
───────────────────────────────────────────────────────────── */
const undoStack = [];

function pushUndo(op) {
    undoStack.push(op);
    if (undoStack.length > 5) undoStack.shift();
    showUndoToast(op);
}

function showUndoToast(op) {
    const t = document.createElement('div');
    t.className = 'toast undo-t';
    t.innerHTML = `<span class="toast-msg">Moved to Table ${op.newTbl}</span>
      <button class="toast-undo" onclick="undoMove(${undoStack.indexOf(op)},this.closest('.toast'))">
        &#8630; Undo
      </button>
      <button class="toast-close" onclick="this.closest('.toast').remove()">&#10005;</button>`;
    document.getElementById('toast-wrap').appendChild(t);
    setTimeout(()=>t.remove(), 8000);
}

function undoMove(idx, toastEl) {
    const op = undoStack[idx]; if (!op) return;
    undoStack.splice(idx, 1);
    if (toastEl) toastEl.remove();

    const oldRow = document.querySelector(`.tl-row[data-table-id="${op.oldTbl}"]`);
    if (!oldRow) return;

    doTableChange(op.bookingId, op.oldTbl, op.newTbl, op.bkEl, oldRow);
}

document.addEventListener('keydown', e => {
    if ((e.ctrlKey||e.metaKey) && e.key==='z') {
        e.preventDefault();
        if (undoStack.length) undoMove(undoStack.length-1, null);
        else showToast('Nothing to undo', 'info');
    }
    if (e.key==='Escape') {
        closeOverlay('detail-overlay'); closeOverlay('wi-overlay');
    }
});

/* ─────────────────────────────────────────────────────────────
   TOAST NOTIFICATIONS
───────────────────────────────────────────────────────────── */
function showToast(msg, type='info') {
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `<span class="toast-msg">${msg}</span>
                   <button class="toast-close" onclick="this.closest('.toast').remove()">&#10005;</button>`;
    document.getElementById('toast-wrap').appendChild(t);
    setTimeout(()=>t.remove(), 4000);
}

/* ─────────────────────────────────────────────────────────────
   DETAIL MODAL
───────────────────────────────────────────────────────────── */
function openDetail(idx) {
    const b = bookings[idx]; if (!b) return;
    const ft = (b.booking_time||'').substring(0,5);
    const fd = b.booking_date ? new Date(b.booking_date+'T12:00:00')
        .toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'}) : '';
    const sbCls = `sb sb-${b.status}`;

    document.getElementById('m-title').textContent = 'Booking #'+b.id;
    document.getElementById('m-body').innerHTML = `
      <div class="mrow"><div class="mlbl">Guest</div><div class="mval">${esc(b.customer_name)}</div></div>
      <div class="mrow"><div class="mlbl">Email</div><div class="mval" style="font-weight:400;font-size:12px;">${esc(b.email||'—')}</div></div>
      <div class="mrow"><div class="mlbl">Phone</div><div class="mval">${esc(b.phone||'—')}</div></div>
      <div class="mrow"><div class="mlbl">Date</div><div class="mval">${fd}</div></div>
      <div class="mrow"><div class="mlbl">Time</div><div class="mval">${ft}</div></div>
      <div class="mrow"><div class="mlbl">Guests</div><div class="mval">${b.num_guests}</div></div>
      <div class="mrow"><div class="mlbl">Table</div><div class="mval">Table ${b.assigned_table}</div></div>
      <div class="mrow"><div class="mlbl">Status</div><div class="mval"><span class="${sbCls}">${b.status}</span></div></div>
    `;
    if (isAdmin) {
        document.getElementById('m-bid').value = b.id;
        const sel = document.getElementById('m-sel');
        for (let o of sel.options) o.selected = o.value===b.status;
    }
    document.querySelectorAll('.bitem').forEach(el=>el.classList.toggle('active',parseInt(el.dataset.id)===b.id));
    document.getElementById('detail-overlay').classList.add('open');
}

/* ─────────────────────────────────────────────────────────────
   STATUS UPDATE (AJAX — modal)
───────────────────────────────────────────────────────────── */
const statusColors = {
    confirmed: ['#0d9e78','#0a7d61'],
    pending:   ['#d4920a','#b07a08'],
    seated:    ['#2271d4','#1a5aaa'],
    completed: ['#4a4e5a','#3a3e48'],
    cancelled: ['#c0392b','#962d22'],
};

function submitStatusUpdate() {
    const bid    = document.getElementById('m-bid').value;
    const status = document.getElementById('m-sel').value;
    if (!bid || !status) return;

    fetch('reservations.php?action=update_status', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `booking_id=${bid}&new_status=${status}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { showToast(data.error || 'Update failed', 'error'); return; }

        /* update JS data array */
        const bk = bookings.find(b => b.id == bid);
        if (bk) bk.status = status;
        tableIndex = buildIndex();

        /* update the status badge inside the modal body */
        const badge = document.querySelector('#m-body .sb');
        if (badge) { badge.className = `sb sb-${status}`; badge.textContent = status; }

        /* update the booking block colour on the timeline */
        const bkEl = document.getElementById(`bk-${bid}`);
        if (bkEl && statusColors[status]) {
            bkEl.style.background        = statusColors[status][0];
            bkEl.style.borderLeftColor   = statusColors[status][1];
        }

        /* update sidebar badge */
        const siB = document.querySelector(`.bitem[data-id="${bid}"] .bi-t .sb`);
        if (siB) { siB.className = `sb sb-${status}`; siB.textContent = status; }

        /* update sidebar dot colour */
        const siD = document.querySelector(`.bitem[data-id="${bid}"] .bi-dot`);
        if (siD && statusColors[status]) siD.style.background = statusColors[status][0];

        closeOverlay('detail-overlay');
        showToast(`Status updated to "${status}"`, 'success');
    })
    .catch(() => showToast('Network error', 'error'));
}

/* ─────────────────────────────────────────────────────────────
   WALK-IN BOOKING
───────────────────────────────────────────────────────────── */
function openWalkIn() { document.getElementById('wi-overlay').classList.add('open'); }

function submitWalkIn() {
    const name   = document.getElementById('wi-name').value.trim();
    const guests = parseInt(document.getElementById('wi-guests').value)||1;
    const time   = document.getElementById('wi-time').value;
    const err    = document.getElementById('wi-err');
    if (!name) { err.textContent='Name is required'; err.style.display='block'; return; }
    err.style.display='none';

    fetch('reservations.php?action=walkin', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`name=${encodeURIComponent(name)}&guests=${guests}&time=${time}&date=${curDate}`
    })
    .then(r=>r.json())
    .then(data=>{
        if (!data.success) { err.textContent=data.error||'Failed'; err.style.display='block'; return; }
        closeOverlay('wi-overlay');
        showToast(`${name} seated at Table ${data.booking.assigned_table}`, 'success');
        setTimeout(()=>location.reload(), 1500);
    })
    .catch(()=>{ err.textContent='Network error'; err.style.display='block'; });
}

/* ─────────────────────────────────────────────────────────────
   OVERLAY HELPERS
───────────────────────────────────────────────────────────── */
function closeOverlay(id, e) {
    if (e && e.target.id !== id) return;
    document.getElementById(id).classList.remove('open');
    document.querySelectorAll('.bitem').forEach(el=>el.classList.remove('active'));
}
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ─────────────────────────────────────────────────────────────
   VIEW TOGGLE & TIME FILTER
───────────────────────────────────────────────────────────── */
function switchView(v) {
    document.getElementById('vdiagram').style.display = v==='d'?'block':'none';
    document.getElementById('vlist').style.display    = v==='l'?'block':'none';
    document.getElementById('tab-d').classList.toggle('active',v==='d');
    document.getElementById('tab-l').classList.toggle('active',v==='l');
}
function filterTime(f, el) {
    document.querySelectorAll('.ftab').forEach(t=>t.classList.remove('active'));
    el.classList.add('active');
    const ranges={all:[0,24],morning:[6,12],lunch:[12,17],evening:[17,24]};
    const [fr,to]=ranges[f]||[0,24];
    document.querySelectorAll('.bk,.bitem').forEach(el=>{
        const t=el.dataset.time||el.querySelector('.bi-sub')?.textContent||'';
        const h=parseInt(t.substring(0,2))||0;
        el.style.display=(h>=fr&&h<to)?'':'none';
    });
}

/* ─────────────────────────────────────────────────────────────
   LIVE SEARCH — highlights matching bookings
───────────────────────────────────────────────────────────── */
document.getElementById('search-input').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.bk').forEach(el=>{
        const nm = (el.dataset.name||'').toLowerCase();
        if (!q)      { el.classList.remove('search-fade','search-glow'); }
        else if (nm.includes(q)) { el.classList.remove('search-fade'); el.classList.add('search-glow'); }
        else         { el.classList.remove('search-glow'); el.classList.add('search-fade'); }
    });
    document.querySelectorAll('.bitem').forEach(el=>{
        const nm = (el.dataset.name||'').toLowerCase();
        if (!q) { el.classList.remove('search-hidden','search-match'); }
        else if (nm.includes(q)) { el.classList.remove('search-hidden'); el.classList.add('search-match'); }
        else    { el.classList.remove('search-match'); el.classList.add('search-hidden'); }
    });
});

/* ─────────────────────────────────────────────────────────────
   HEATMAP — colour time-header slots by booking density
───────────────────────────────────────────────────────────── */
(function applyHeatmap() {
    const slots = (<?php echo (T_E-T_S)/30; ?>);
    const density = new Array(slots).fill(0);
    bookings.forEach(b=>{
        if (b.status==='cancelled') return;
        const startSlot = (tMins(b.booking_time) - T_START) / 30;
        const endSlot   = startSlot + DURATION/30;
        for (let s=Math.floor(startSlot); s<endSlot && s<slots; s++)
            if (s>=0) density[s]++;
    });
    const mx = Math.max(...density, 1);
    density.forEach((d,i)=>{
        const el = document.getElementById(`slot-${i}`);
        if (!el) return;
        const lvl = Math.ceil((d/mx)*4);
        if (lvl>0) el.classList.add(`hot-${lvl}`);
        if (d>0) el.title = `${d} booking${d!==1?'s':''} overlap this slot`;
    });
})();

/* ─────────────────────────────────────────────────────────────
   CURRENT TIME INDICATOR (today only)
───────────────────────────────────────────────────────────── */
<?php if($is_today): ?>
function updateTimeLine() {
    const tl    = document.getElementById('time-line');
    const now   = new Date();
    const mins  = now.getHours()*60 + now.getMinutes();
    const label = now.getHours().toString().padStart(2,'0')+':'+now.getMinutes().toString().padStart(2,'0');
    if (mins < T_START || mins > <?php echo T_E;?>) { tl.style.display='none'; return; }
    const left = (mins - T_START)/30*SLOT_W + 140 + 2; // 140 = label width
    tl.style.display='block';
    tl.style.left = left+'px';
    tl.setAttribute('data-time', label);
}
updateTimeLine();
setInterval(updateTimeLine, 30000);
<?php endif; ?>

/* ─────────────────────────────────────────────────────────────
   AUTO-REFRESH COUNTDOWN (30 seconds)
───────────────────────────────────────────────────────────── */
let refreshSecs = 30;
const refProg = document.getElementById('ref-prog');
function tick() {
    refreshSecs--;
    refProg.style.width = (refreshSecs/30*100)+'%';
    if (refreshSecs <= 0) location.reload();
}
let refreshTimer = setInterval(tick, 1000);
/* reset on user interaction */
['mousedown','keydown','touchstart'].forEach(ev =>
    document.addEventListener(ev, ()=>{ refreshSecs=30; }, {passive:true})
);
</script>
</body>
</html>
