<?php
ob_start();
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'cashier') {
    ob_end_clean();
    header("Location: ../auth/login.php"); exit();
}

$cashier_id = (int)$_SESSION['user']['id'];
$today      = date('Y-m-d');

/* ════════════════════════════════════════════
   AJAX HANDLERS
════════════════════════════════════════════ */
if (isset($_GET['action'])) {
    ob_end_clean();
    session_write_close();
    header('Content-Type: application/json');

    /* ── pay: process payment ── */
    if ($_GET['action'] === 'pay') {
        $oid    = (int)($_POST['order_id'] ?? 0);
        $method = $_POST['payment_method'] ?? '';
        if (!$oid || !in_array($method, ['cash','card','qr'])) {
            echo json_encode(['success'=>false,'error'=>'Invalid parameters']); exit();
        }

        $order = $conn->query("SELECT * FROM orders WHERE id=$oid AND status NOT IN ('completed','cancelled')")->fetch_assoc();
        if (!$order) {
            echo json_encode(['success'=>false,'error'=>'Order not found or already completed']); exit();
        }

        /* Prevent duplicate billing */
        $dup = $conn->query("SELECT id FROM billing WHERE order_id=$oid AND payment_status='completed'")->fetch_assoc();
        if ($dup) {
            echo json_encode(['success'=>false,'error'=>'This order has already been paid']); exit();
        }

        $has_up   = $conn->query("SHOW COLUMNS FROM order_items LIKE 'unit_price'")->num_rows > 0;
        $px       = $has_up ? "COALESCE(oi.unit_price, oi.price, 0)" : "COALESCE(oi.price, 0)";
        $items_q  = $conn->query("SELECT oi.quantity, $px AS price FROM order_items oi WHERE oi.order_id=$oid");
        $subtotal = 0.0;
        while ($i = $items_q->fetch_assoc()) {
            $subtotal += (float)$i['price'] * (int)$i['quantity'];
        }
        $tax      = round($subtotal * 0.10, 2);
        $discount = max(0, (float)($_POST['discount'] ?? 0));
        $total    = max(0, round($subtotal + $tax - $discount, 2));

        $has_disc = $conn->query("SHOW COLUMNS FROM billing LIKE 'discount'")->num_rows > 0;
        if ($has_disc) {
            $stmt = $conn->prepare(
                "INSERT INTO billing (order_id, subtotal, tax, discount, total, payment_method, payment_status, cashier_id, billing_date)
                 VALUES (?,?,?,?,?,?,'completed',?,NOW())"
            );
            if (!$stmt) { echo json_encode(['success'=>false,'error'=>'Billing error: '.$conn->error]); exit(); }
            $stmt->bind_param("iddddsi", $oid, $subtotal, $tax, $discount, $total, $method, $cashier_id);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO billing (order_id, subtotal, tax, total, payment_method, payment_status, cashier_id, billing_date)
                 VALUES (?,?,?,?,?,?,'completed',?,NOW())"
            );
            if (!$stmt) { echo json_encode(['success'=>false,'error'=>'Billing error: '.$conn->error]); exit(); }
            $stmt->bind_param("idddsi", $oid, $subtotal, $tax, $total, $method, $cashier_id);
        }
        if (!$stmt->execute()) { echo json_encode(['success'=>false,'error'=>'Billing failed: '.$stmt->error]); exit(); }
        $billing_id = (int)$conn->insert_id;

        $stmt2 = $conn->prepare(
            "INSERT INTO transactions (billing_id, amount, payment_method, status) VALUES (?,?,?,'completed')"
        );
        $stmt2->bind_param("ids", $billing_id, $total, $method);
        $stmt2->execute();

        $conn->query("UPDATE orders SET status='completed' WHERE id=$oid");
        $tid = (int)$order['table_id'];
        if ($tid) {
            $conn->query("UPDATE `tables` SET status='available' WHERE id=$tid");
        }

        /* Complete linked booking */
        $has_res_col2 = $conn->query("SHOW COLUMNS FROM orders LIKE 'reservation_id'")->num_rows > 0;
        if ($has_res_col2 && !empty($order['reservation_id'])) {
            $conn->query("UPDATE bookings SET status='completed' WHERE id=".(int)$order['reservation_id']);
        } elseif ($tid) {
            $conn->query("UPDATE bookings SET status='completed' WHERE assigned_table=$tid AND booking_date=CURDATE() AND status NOT IN ('cancelled','completed')");
        }

        echo json_encode(['success'=>true,'billing_id'=>$billing_id,'total'=>$total]);
        exit();
    }

    /* ── bill: fetch order bill details ── */
    if ($_GET['action'] === 'bill') {
        $oid = (int)($_GET['order_id'] ?? 0);
        if (!$oid) { echo json_encode(['success'=>false,'error'=>'Missing order_id']); exit(); }

        $has_ot = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_type'")->num_rows > 0;
        $ot_sel = $has_ot ? ", o.order_type" : "";

        $order = $conn->query("
            SELECT o.id, o.order_number, o.created_at, o.total_amount, o.status $ot_sel,
                   t.table_number, u.username AS waiter
            FROM orders o
            LEFT JOIN `tables` t ON o.table_id = t.id
            LEFT JOIN users u ON o.waiter_id = u.id
            WHERE o.id=$oid AND o.status NOT IN ('completed','cancelled')
        ")->fetch_assoc();

        if (!$order) { echo json_encode(['success'=>false,'error'=>'Order not found or already completed']); exit(); }

        $has_up2 = $conn->query("SHOW COLUMNS FROM order_items LIKE 'unit_price'")->num_rows > 0;
        $px2     = $has_up2 ? "COALESCE(oi.unit_price, oi.price, mi.price, 0)" : "COALESCE(oi.price, mi.price, 0)";
        $items_q = $conn->query("
            SELECT mi.name, oi.quantity, $px2 AS price
            FROM order_items oi
            JOIN menu_items mi ON oi.menu_item_id = mi.id
            WHERE oi.order_id=$oid
            ORDER BY oi.id ASC
        ");
        $items    = [];
        $subtotal = 0.0;
        while ($row = $items_q->fetch_assoc()) {
            $line      = round((float)$row['price'] * (int)$row['quantity'], 2);
            $subtotal += $line;
            $items[]   = [
                'name'     => $row['name'],
                'quantity' => (int)$row['quantity'],
                'price'    => (float)$row['price'],
                'subtotal' => $line,
            ];
        }
        $tax   = round($subtotal * 0.10, 2);
        $total = round($subtotal + $tax, 2);

        $order_out = [
            'order_number' => $order['order_number'],
            'table_number' => $order['table_number'] ?? '?',
            'waiter'       => $order['waiter'] ?? '',
            'created_at'   => $order['created_at'],
        ];
        if ($has_ot) $order_out['order_type'] = $order['order_type'];

        echo json_encode([
            'success'  => true,
            'order'    => $order_out,
            'items'    => $items,
            'subtotal' => round($subtotal, 2),
            'tax'      => $tax,
            'total'    => $total,
        ]);
        exit();
    }

    /* ── delete_booking: cancel upcoming booking ── */
    if ($_GET['action'] === 'delete_booking') {
        $bid = (int)($_POST['booking_id'] ?? 0);
        if (!$bid) { echo json_encode(['success'=>false,'error'=>'Missing booking_id']); exit(); }

        $booking = $conn->query("SELECT assigned_table FROM bookings WHERE id=$bid")->fetch_assoc();
        if (!$booking) { echo json_encode(['success'=>false,'error'=>'Booking not found']); exit(); }

        $conn->query("UPDATE bookings SET status='cancelled' WHERE id=$bid");

        $at = (int)$booking['assigned_table'];
        if ($at) {
            $conn->query("
                UPDATE `tables` SET status='available'
                WHERE id=$at AND status='reserved'
                  AND id NOT IN (
                    SELECT table_id FROM orders
                    WHERE status IN ('pending','preparing','ready')
                  )
            ");
        }

        echo json_encode(['success'=>true]);
        exit();
    }

    /* ── poll_queue: lightweight status check for auto-refresh ── */
    if ($_GET['action'] === 'poll_queue') {
        $q = $conn->query("
            SELECT o.id, o.order_number, o.status, t.table_number
            FROM orders o
            LEFT JOIN `tables` t ON o.table_id = t.id
            WHERE o.status IN ('served','ready')
              AND o.status NOT IN ('completed','cancelled')
            ORDER BY FIELD(o.status,'served','ready','preparing','pending'), o.created_at ASC
        ");
        $queue = [];
        while ($row = $q->fetch_assoc()) $queue[] = $row;
        echo json_encode(['success'=>true,'queue'=>$queue,'count'=>count($queue)]);
        exit();
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit();
}

/* ════════════════════════════════════════════
   PHP DATA
════════════════════════════════════════════ */

/* Ready orders queue */
$ready_orders = $conn->query("
    SELECT o.id, o.order_number, o.created_at, o.total_amount, o.status,
           t.table_number, u.username AS waiter,
           (SELECT COUNT(*) FROM order_items WHERE order_id=o.id) AS item_count
    FROM orders o
    LEFT JOIN `tables` t ON o.table_id = t.id
    LEFT JOIN users u ON o.waiter_id = u.id
    WHERE o.status NOT IN ('completed','cancelled')
    ORDER BY FIELD(o.status,'served','ready','preparing','pending'), o.created_at ASC
");
$ready_rows = [];
while ($row = $ready_orders->fetch_assoc()) $ready_rows[] = $row;

/* Stats */
$r = $conn->query("
    SELECT COALESCE(SUM(total),0) AS total,
           COALESCE(SUM(CASE WHEN payment_method='cash' THEN total ELSE 0 END),0) AS cash,
           COALESCE(SUM(CASE WHEN payment_method='card' THEN total ELSE 0 END),0) AS card,
           COALESCE(SUM(CASE WHEN payment_method='qr'   THEN total ELSE 0 END),0) AS qr
    FROM billing WHERE cashier_id=$cashier_id AND DATE(billing_date)='$today' AND payment_status='completed'
");
$rev        = $r->fetch_assoc();
$my_revenue = (float)$rev['total'];
$my_rev_cash = (float)$rev['cash'];
$my_rev_card = (float)$rev['card'];
$my_rev_qr   = (float)$rev['qr'];

$r        = $conn->query("SELECT COUNT(*) AS v FROM billing WHERE cashier_id=$cashier_id AND DATE(billing_date)='$today' AND payment_status='completed'");
$my_bills = (int)$r->fetch_assoc()['v'];

$r       = $conn->query("SELECT COALESCE(AVG(total),0) AS v FROM billing WHERE cashier_id=$cashier_id AND DATE(billing_date)='$today' AND payment_status='completed'");
$my_avg  = (float)$r->fetch_assoc()['v'];

$ready_count = count($ready_rows);

/* Today's transactions (last 10) */
$recent_tx = $conn->query("
    SELECT b.id, b.total, b.payment_method, b.billing_date, b.discount,
           o.order_number, t.table_number
    FROM billing b
    JOIN orders o ON b.order_id = o.id
    LEFT JOIN `tables` t ON o.table_id = t.id
    WHERE b.cashier_id=$cashier_id AND DATE(b.billing_date)='$today'
    ORDER BY b.billing_date DESC LIMIT 10
");
$tx_rows = [];
while ($row = $recent_tx->fetch_assoc()) $tx_rows[] = $row;

/* Upcoming reservations */
$upcoming_res = $conn->query("
    SELECT b.id, b.customer_name, b.email, b.phone,
           b.booking_date, b.booking_time, b.num_guests, b.status,
           t.table_number
    FROM bookings b
    LEFT JOIN `tables` t ON b.assigned_table = t.id
    WHERE b.booking_date >= CURDATE()
      AND b.status NOT IN ('cancelled','completed')
    ORDER BY b.booking_date ASC, b.booking_time ASC
    LIMIT 50
");
$res_rows = [];
while ($row = $upcoming_res->fetch_assoc()) $res_rows[] = $row;

/* Menu items — defensive column check */
$has_img = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'image'")->num_rows > 0;
$has_cat = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'category'")->num_rows > 0;
$img_col = $has_img ? ", image" : "";
$cat_col = $has_cat ? ", category" : "";
$menu_q  = $conn->query("SELECT id, name, price $cat_col $img_col FROM menu_items WHERE availability=1 ORDER BY name");
$menu_items_rows = [];
$menu_categories = [];
while ($row = $menu_q->fetch_assoc()) {
    $menu_items_rows[] = $row;
    if ($has_cat && !empty($row['category']) && !in_array($row['category'], $menu_categories)) {
        $menu_categories[] = $row['category'];
    }
}
sort($menu_categories);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Cashier — RestaurantMS</title>
<?php $role_accent='#22c55e'; $role_accent2='#4ade80'; include("../config/staff_head.php"); ?>
<style>
body {
  overflow-x: hidden;
  background-image:
    radial-gradient(ellipse at 80% 0%,   rgba(34,197,94,.12)  0%, transparent 55%),
    radial-gradient(ellipse at 20% 100%, rgba(16,185,129,.08) 0%, transparent 55%) !important;
}

/* ══════════════════════════════════════════
   TAB BAR
══════════════════════════════════════════ */
.tab-bar {
  display: flex;
  align-items: center;
  gap: 2px;
  border-bottom: 1px solid var(--border);
  padding: 0 4px;
  background: rgba(18,18,26,.85);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  flex-shrink: 0;
  position: sticky;
  top: 0;
  z-index: 10;
  margin-bottom: 0;
}
.tab-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 12px 18px;
  border: none;
  background: none;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  color: var(--muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: .15s;
  white-space: nowrap;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
.tab-pane { display: none; }
.tab-pane.active { display: block; padding-top: 20px; }

/* ══════════════════════════════════════════
   STAT CARDS — suppress default ::before,
   use per-card colored lines instead
══════════════════════════════════════════ */
.stat-card::before { display: none; }
.stat-line-green  { position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--green),transparent);z-index:1; }
.stat-line-blue   { position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--blue),transparent);z-index:1; }
.stat-line-gold   { position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--gold),transparent);z-index:1; }
.stat-line-orange { position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--orange),transparent);z-index:1; }

/* ══════════════════════════════════════════
   ORDER LIST
══════════════════════════════════════════ */
.order-list { display: flex; flex-direction: column; gap: 10px; }
.order-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 17px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-left: 3px solid rgba(34,197,94,.3);
  border-radius: 14px;
  cursor: pointer;
  transition: all .22s cubic-bezier(.16,1,.3,1);
  position: relative;
  overflow: hidden;
}
.order-row::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, rgba(34,197,94,.03) 0%, transparent 40%);
  pointer-events: none;
}
.order-row:hover {
  border-color: rgba(34,197,94,.45);
  border-left-color: rgba(34,197,94,.8);
  background: var(--surface3);
  transform: translateX(4px);
  box-shadow: 0 8px 32px rgba(34,197,94,.1), 0 4px 16px rgba(0,0,0,.4);
}
.order-row.selected {
  border-color: rgba(34,197,94,.55);
  border-left-color: var(--accent);
  background: rgba(34,197,94,.07);
  box-shadow: 0 0 0 1px rgba(34,197,94,.15);
}
.or-icon {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  background: var(--green-d);
  border: 1px solid rgba(34,197,94,.22);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--green);
  font-size: 15px;
  flex-shrink: 0;
}
.or-num  { font-size: 14px; font-weight: 700; color: var(--text); }
.or-meta { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.or-wait { font-size: 11px; color: var(--orange); font-weight: 600; margin-top: 2px; }
.or-amount {
  font-size: 16px;
  font-weight: 700;
  color: var(--gold);
  font-family: 'Playfair Display', serif;
}
.or-tap { font-size: 11px; color: var(--accent); margin-top: 3px; }

/* ══════════════════════════════════════════
   TRANSACTIONS LIST
══════════════════════════════════════════ */
.tx-list { display: flex; flex-direction: column; gap: 7px; }
.tx-item {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 10px 12px;
  background: var(--surface2);
  border-radius: 11px;
  border: 1px solid var(--border);
  transition: .15s;
}
.tx-item:hover { border-color: var(--border2); background: var(--surface3); }
.tx-icon {
  width: 32px;
  height: 32px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  flex-shrink: 0;
}

/* ══════════════════════════════════════════
   BILL SLIDE PANEL
══════════════════════════════════════════ */
.bill-panel {
  position: fixed;
  right: 0; top: 0; bottom: 0;
  width: 440px;
  background: rgba(18,18,26,.96);
  backdrop-filter: blur(24px);
  -webkit-backdrop-filter: blur(24px);
  border-left: 1px solid rgba(34,197,94,.15);
  box-shadow: -24px 0 80px rgba(0,0,0,.7), -1px 0 0 rgba(34,197,94,.08);
  display: flex;
  flex-direction: column;
  z-index: 300;
  transform: translateX(100%);
  transition: transform .28s cubic-bezier(.16,1,.3,1);
}
.bill-panel.open { transform: none; }
.bp-overlay {
  position: fixed;
  inset: 0;
  z-index: 299;
  background: rgba(0,0,0,.5);
  display: none;
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
}
.bp-overlay.open { display: block; }
.bp-hd {
  padding: 18px 22px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}
.bp-body { flex: 1; overflow-y: auto; padding: 20px 22px; }
.bp-ft {
  padding: 16px 22px 20px;
  border-top: 1px solid var(--border);
  flex-shrink: 0;
  background: var(--surface);
}

/* Receipt styles */
.receipt-hd {
  text-align: center;
  margin-bottom: 16px;
  padding-bottom: 14px;
  border-bottom: 1px dashed rgba(255,255,255,.08);
}
.receipt-item {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 8px 0;
  border-bottom: 1px solid rgba(255,255,255,.04);
  font-size: 13px;
}
.receipt-item:last-child { border-bottom: none; }
.receipt-totals {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px dashed rgba(255,255,255,.08);
}
.rt-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
  padding: 4px 0;
  color: var(--sub);
}
.rt-row.grand {
  font-size: 17px;
  font-weight: 700;
  margin-top: 8px;
  padding-top: 10px;
  border-top: 1px solid var(--border2);
}
.rt-row.grand .rt-lbl { color: var(--text); }
.rt-row.grand .rt-val { color: var(--gold); font-family: 'Playfair Display', serif; }

/* Payment method buttons */
.pay-methods {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 8px;
  margin-bottom: 14px;
}
.pay-btn {
  padding: 10px 8px;
  border-radius: 12px;
  border: 1px solid var(--border2);
  background: var(--surface2);
  color: var(--muted);
  text-align: center;
  cursor: pointer;
  transition: .15s;
}
.pay-btn:hover { border-color: var(--border2); color: var(--text); background: var(--surface3); }
.pay-btn.active {
  border-color: var(--accent);
  background: var(--green-d);
  color: var(--green);
  box-shadow: 0 0 0 1px var(--accent) inset;
}
.pay-btn i { display: block; font-size: 18px; margin-bottom: 5px; }
.pay-btn span { font-size: 11px; font-weight: 700; }

/* Change calculator */
.change-calc {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 13px 15px;
  margin-bottom: 12px;
}
.cc-row { display: flex; align-items: center; gap: 10px; margin-bottom: 9px; }
.cc-row:last-child { margin-bottom: 0; }
.cc-lbl { font-size: 12px; color: var(--muted); width: 76px; flex-shrink: 0; }
.cc-val { font-size: 14px; font-weight: 700; }
.cc-val.positive { color: var(--green); }
.cc-val.negative { color: var(--red); }

/* ══════════════════════════════════════════
   RESERVATIONS TAB
══════════════════════════════════════════ */
.res-search-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.res-search-bar input {
  flex: 1;
  padding: 9px 14px 9px 38px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 11px;
  color: var(--text);
  font-family: inherit;
  font-size: 13px;
  outline: none;
  transition: .2s;
}
.res-search-bar input:focus {
  border-color: rgba(34,197,94,.4);
  background: var(--surface3);
  box-shadow: 0 0 0 3px rgba(34,197,94,.08);
}
.res-search-wrap { position: relative; flex: 1; }
.res-search-wrap i {
  position: absolute;
  left: 13px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  font-size: 12px;
  pointer-events: none;
}

/* ══════════════════════════════════════════
   MENU TAB
══════════════════════════════════════════ */
.menu-search-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.menu-search-wrap { position: relative; flex: 1; min-width: 180px; }
.menu-search-wrap input {
  width: 100%;
  padding: 9px 14px 9px 38px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 11px;
  color: var(--text);
  font-family: inherit;
  font-size: 13px;
  outline: none;
  transition: .2s;
}
.menu-search-wrap input:focus {
  border-color: rgba(34,197,94,.4);
  background: var(--surface3);
  box-shadow: 0 0 0 3px rgba(34,197,94,.08);
}
.menu-search-wrap i {
  position: absolute;
  left: 13px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  font-size: 12px;
  pointer-events: none;
}
.cat-filters { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.cat-btn {
  padding: 6px 14px;
  border-radius: 20px;
  border: 1px solid var(--border2);
  background: var(--surface2);
  color: var(--muted);
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: .15s;
}
.cat-btn:hover { border-color: rgba(255,255,255,.15); color: var(--text); }
.cat-btn.active {
  border-color: var(--accent);
  background: var(--green-d);
  color: var(--accent);
}
.menu-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 13px;
}
.menu-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  overflow: hidden;
  transition: .2s;
}
.menu-card:hover {
  border-color: var(--border2);
  transform: translateY(-2px);
  box-shadow: 0 10px 28px rgba(0,0,0,.35);
}
.menu-img-wrap {
  width: 100%;
  height: 115px;
  overflow: hidden;
  background: var(--surface2);
}
.menu-card-img {
  width: 100%;
  height: 115px;
  object-fit: cover;
  display: block;
  transition: transform .3s;
}
.menu-card:hover .menu-card-img { transform: scale(1.04); }
.menu-card-body { padding: 13px; }
.menu-card-name { font-size: 13px; font-weight: 700; margin-bottom: 6px; color: var(--text); }
.menu-card-price {
  font-size: 15px;
  font-weight: 700;
  color: var(--gold);
  font-family: 'Playfair Display', serif;
}
.menu-card-cat {
  display: inline-block;
  margin-top: 7px;
  padding: 3px 9px;
  background: var(--green-d);
  color: var(--green);
  border-radius: 20px;
  font-size: 10.5px;
  font-weight: 700;
  text-transform: capitalize;
  border: 1px solid rgba(34,197,94,.18);
}

/* ═══════════════════════════════════════════
   CASHIER RESPONSIVE
═══════════════════════════════════════════ */

@media (max-width: 1024px) {
  .page-wrap { padding: 16px 18px; }
  .menu-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
}

@media (max-width: 860px) {
  /* Tab bar scrolls horizontally */
  .tab-bar { overflow-x: auto; flex-wrap: nowrap; }
  .tab-btn { padding: 10px 14px; font-size: 12px; }

  /* Order table: make it scroll horizontally */
  .order-table-wrap { overflow-x: auto; }
  table { min-width: 560px; }

  /* Stats condensed */
  .stat-row { grid-template-columns: repeat(3, 1fr) !important; }
}

@media (max-width: 640px) {
  .tab-btn { padding: 9px 11px; font-size: 11.5px; gap: 5px; }
  .menu-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
  .menu-img-wrap, .menu-card-img { height: 90px; }
  .stat-row { grid-template-columns: repeat(2, 1fr) !important; }
  .bill-panel { width: 100%; border-radius: 16px 16px 0 0; top: auto; bottom: 0; max-height: 90vh; }
  .pay-methods { grid-template-columns: 1fr 1fr 1fr; gap: 6px; }
  .pay-btn { padding: 8px 5px; }
  .pay-btn i { font-size: 15px; margin-bottom: 3px; }
}

@media (max-width: 480px) {
  .menu-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; }
  .stat-row { grid-template-columns: repeat(2, 1fr) !important; }
  .res-search-bar { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <a href="dashboard.php" class="tb-brand">
    <div class="tb-icon"><i class="fa-solid fa-cash-register"></i></div>
    <span class="tb-title">Cashier</span>
  </a>
  <div class="tb-right">
    <div class="live-dot"></div>
    <span class="tb-clock" id="clock"></span>
    <div class="tb-user">
      <div class="tb-avatar"><?php echo strtoupper(substr($_SESSION['user']['username'],0,1)); ?></div>
      <div>
        <div class="tb-name"><?php echo htmlspecialchars($_SESSION['user']['username']); ?></div>
        <div class="tb-role">Cashier</div>
      </div>
    </div>
    <a href="../auth/logout.php" class="tb-btn danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </div>
</div>

<div class="page-wrap">

  <!-- STATS -->
  <div class="stat-row mb-20">
    <div class="stat-card">
      <div class="stat-line-green"></div>
      <i class="fa-solid fa-sack-dollar stat-icon" style="color:var(--green);"></i>
      <div class="stat-label">My Revenue Today</div>
      <div class="stat-val" style="font-size:20px;">DKK&nbsp;<?php echo number_format($my_revenue,0); ?></div>
      <div class="stat-sub" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
        <?php if($my_rev_cash>0): ?><span style="color:var(--green);font-size:10px;"><i class="fa-solid fa-money-bill-wave"></i> <?php echo number_format($my_rev_cash,0); ?></span><?php endif; ?>
        <?php if($my_rev_card>0): ?><span style="color:var(--blue);font-size:10px;"><i class="fa-solid fa-credit-card"></i> <?php echo number_format($my_rev_card,0); ?></span><?php endif; ?>
        <?php if($my_rev_qr>0): ?><span style="color:var(--purple);font-size:10px;"><i class="fa-solid fa-qrcode"></i> <?php echo number_format($my_rev_qr,0); ?></span><?php endif; ?>
        <?php if($my_rev_cash==0&&$my_rev_card==0&&$my_rev_qr==0): ?><span style="color:var(--muted);font-size:10px;">No payments yet</span><?php endif; ?>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-line-blue"></div>
      <i class="fa-solid fa-receipt stat-icon" style="color:var(--blue);"></i>
      <div class="stat-label">Bills Processed</div>
      <div class="stat-val"><?php echo $my_bills; ?></div>
      <div class="stat-sub">Completed today</div>
    </div>
    <div class="stat-card">
      <div class="stat-line-gold"></div>
      <i class="fa-solid fa-calculator stat-icon" style="color:var(--gold);"></i>
      <div class="stat-label">Avg Bill</div>
      <div class="stat-val" style="font-size:18px;">DKK&nbsp;<?php echo number_format($my_avg,0); ?></div>
      <div class="stat-sub">Per transaction</div>
    </div>
    <div class="stat-card">
      <div class="stat-line-orange"></div>
      <i class="fa-solid fa-bell stat-icon" style="color:var(--orange);"></i>
      <div class="stat-label">Active Orders</div>
      <div class="stat-val" style="color:<?php echo $ready_count>0?'var(--orange)':'var(--text)'; ?>;"><?php echo $ready_count; ?></div>
      <div class="stat-sub">Awaiting payment</div>
    </div>
  </div>

  <!-- TAB BAR -->
  <div class="tab-bar">
    <button class="tab-btn active" data-tab="bills" onclick="switchTab('bills')">
      <i class="fa-solid fa-receipt"></i> Bills
      <?php if ($ready_count>0): ?><span class="badge badge-orange"><?php echo $ready_count; ?></span><?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="reservations" onclick="switchTab('reservations')">
      <i class="fa-solid fa-calendar-check"></i> Reservations
      <?php if (count($res_rows)>0): ?><span class="badge badge-blue"><?php echo count($res_rows); ?></span><?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="menu" onclick="switchTab('menu')">
      <i class="fa-solid fa-utensils"></i> Menu
    </button>
  </div>

  <!-- ══════ TAB 1: BILLS ══════ -->
  <div class="tab-pane active" id="tab-bills">
    <div class="grid-73">

      <!-- Ready orders -->
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <h3 style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-bell" style="color:var(--green);"></i> Active Orders
            <?php if ($ready_count>0): ?>
            <span class="badge badge-green"><?php echo $ready_count; ?></span>
            <?php endif; ?>
          </h3>
          <button class="tb-btn" onclick="location.reload()"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
        </div>

        <?php if (!empty($ready_rows)): ?>
        <div class="order-list" id="orderList">
          <?php foreach ($ready_rows as $o):
            $elapsed = time() - strtotime($o['created_at']);
            $mins = floor($elapsed / 60);
          ?>
          <?php
            $st = $o['status'] ?? 'pending';
            $st_icon  = ['served'=>'fa-plate-wheat','ready'=>'fa-bell','preparing'=>'fa-fire','pending'=>'fa-utensils'][$st] ?? 'fa-utensils';
            $st_color = ['served'=>'#a78bfa','ready'=>'var(--green)','preparing'=>'var(--orange)','pending'=>'var(--muted)'][$st] ?? 'var(--muted)';
            $st_bg    = ['served'=>'rgba(167,139,250,.1)','ready'=>'rgba(34,197,94,.07)','preparing'=>'rgba(249,115,22,.07)','pending'=>'rgba(255,255,255,.03)'][$st] ?? '';
            $st_bd    = ['served'=>'rgba(167,139,250,.35)','ready'=>'rgba(34,197,94,.3)','preparing'=>'rgba(249,115,22,.25)','pending'=>'rgba(255,255,255,.08)'][$st] ?? '';
            $st_label = ['served'=>'Served — awaiting payment','ready'=>'Ready — collect from kitchen','preparing'=>'In kitchen — being prepared','pending'=>'Waiting to be prepared'][$st] ?? $st;
          ?>
          <div class="order-row" id="order-row-<?php echo (int)$o['id']; ?>"
               onclick="openBill(<?php echo (int)$o['id']; ?>)"
               style="border-color:<?php echo $st_bd; ?>;background:<?php echo $st_bg; ?>;">

            <div class="or-icon"
                 style="background:<?php echo $st_bg; ?>;border-color:<?php echo $st_bd; ?>;color:<?php echo $st_color; ?>;">
              <i class="fa-solid <?php echo $st_icon; ?>"></i>
            </div>
            <div style="flex:1;min-width:0;">
              <div class="or-num"><?php echo htmlspecialchars($o['order_number']); ?></div>
              <div class="or-meta">
                Table <?php echo htmlspecialchars($o['table_number'] ?? '?'); ?>
                &bull; <?php echo htmlspecialchars($o['waiter'] ?? '—'); ?>
                &bull; <?php echo (int)$o['item_count']; ?> item<?php echo $o['item_count']!=1?'s':''; ?>
              </div>
              <div style="font-size:11px;font-weight:600;margin-top:2px;color:<?php echo $st_color; ?>;">
                <i class="fa-solid <?php echo $st_icon; ?>" style="font-size:9px;"></i>
                <?php echo htmlspecialchars($st_label); ?>
              </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
              <div class="or-amount">DKK&nbsp;<?php echo number_format((float)$o['total_amount'],0); ?></div>
              <div class="or-tap">Tap to bill <i class="fa-solid fa-arrow-right" style="font-size:9px;"></i></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;color:var(--muted);padding:60px 20px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);">
          <i class="fa-solid fa-check-circle" style="font-size:36px;display:block;margin-bottom:12px;color:var(--green);opacity:.3;"></i>
          <div style="font-size:14px;font-weight:600;margin-bottom:4px;">All Clear!</div>
          <div style="font-size:13px;">No active orders at the moment.</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Today's transactions -->
      <div class="card" style="align-self:start;">
        <div class="card-hd">
          <h3><i class="fa-solid fa-clock-rotate-left"></i> Today's Transactions</h3>
          <span style="font-size:11px;color:var(--muted);"><?php echo count($tx_rows); ?> records</span>
        </div>
        <?php if (!empty($tx_rows)):
          $pm_icons  = ['cash'=>'fa-money-bill-wave','card'=>'fa-credit-card','qr'=>'fa-qrcode'];
          $pm_colors = ['cash'=>'var(--green)','card'=>'var(--blue)','qr'=>'var(--purple)'];
        ?>
        <div class="tx-list">
          <?php foreach ($tx_rows as $tx):
            $icon  = $pm_icons[$tx['payment_method']] ?? 'fa-receipt';
            $color = $pm_colors[$tx['payment_method']] ?? 'var(--muted)';
          ?>
          <div class="tx-item">
            <div class="tx-icon" style="background:rgba(34,197,94,.1);color:<?php echo $color; ?>;">
              <i class="fa-solid <?php echo $icon; ?>"></i>
            </div>
            <div style="min-width:0;">
              <div style="font-size:12.5px;font-weight:600;"><?php echo htmlspecialchars($tx['order_number']); ?></div>
              <div style="font-size:11px;color:var(--muted);">
                T-<?php echo htmlspecialchars($tx['table_number'] ?? '?'); ?>
                &bull; <?php echo date('H:i', strtotime($tx['billing_date'])); ?>
                <?php if ($tx['discount']>0): ?>
                &bull; <span style="color:var(--green);">-DKK&nbsp;<?php echo number_format((float)$tx['discount'],0); ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div style="margin-left:auto;text-align:right;flex-shrink:0;">
              <div style="font-size:13px;font-weight:700;color:var(--green);">DKK&nbsp;<?php echo number_format((float)$tx['total'],0); ?></div>
              <div style="font-size:10px;color:var(--muted);text-transform:capitalize;"><?php echo htmlspecialchars($tx['payment_method']); ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;color:var(--muted);padding:28px 0;font-size:13px;">
          <i class="fa-solid fa-receipt" style="display:block;font-size:22px;opacity:.25;margin-bottom:8px;"></i>
          No transactions yet today
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div><!-- /tab-bills -->

  <!-- ══════ TAB 2: RESERVATIONS ══════ -->
  <div class="tab-pane" id="tab-reservations">
    <div class="card">
      <div class="card-hd" style="margin-bottom:14px;">
        <h3><i class="fa-solid fa-calendar-check"></i> Upcoming Reservations</h3>
        <span style="font-size:12px;color:var(--muted);"><?php echo count($res_rows); ?> upcoming</span>
      </div>

      <?php if (!empty($res_rows)): ?>
      <div class="res-search-bar">
        <div class="res-search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="resSearch" placeholder="Search by customer name…" oninput="filterReservations()">
        </div>
      </div>

      <div style="overflow-x:auto;">
        <table class="data-table" id="resTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Customer</th>
              <th>Contact</th>
              <th>Date</th>
              <th>Time</th>
              <th>Guests</th>
              <th>Table</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($res_rows as $idx => $res):
              $status_map = [
                'pending'   => 'pill-pending',
                'confirmed' => 'pill-confirmed',
                'seated'    => 'pill-preparing',
                'preparing' => 'pill-preparing',
                'ready'     => 'pill-ready',
                'completed' => 'pill-completed',
                'cancelled' => 'pill-cancelled',
              ];
              $pill_class = $status_map[$res['status']] ?? 'pill-pending';
            ?>
            <tr id="booking-row-<?php echo (int)$res['id']; ?>">
              <td style="color:var(--muted);font-size:12px;"><?php echo $idx+1; ?></td>
              <td style="font-weight:600;"><?php echo htmlspecialchars($res['customer_name']); ?></td>
              <td>
                <div style="font-size:12.5px;"><?php echo htmlspecialchars($res['email'] ?? '—'); ?></div>
                <div style="font-size:11px;color:var(--muted);"><?php echo htmlspecialchars($res['phone'] ?? '—'); ?></div>
              </td>
              <td><?php echo htmlspecialchars(date('M j, Y', strtotime($res['booking_date']))); ?></td>
              <td><?php echo htmlspecialchars(date('H:i', strtotime($res['booking_time']))); ?></td>
              <td style="text-align:center;">
                <span style="font-weight:700;"><?php echo (int)$res['num_guests']; ?></span>
              </td>
              <td>
                <?php if ($res['table_number']): ?>
                <span style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:2px 8px;font-size:12px;font-weight:600;">
                  T-<?php echo htmlspecialchars($res['table_number']); ?>
                </span>
                <?php else: ?>
                <span style="color:var(--muted);font-size:12px;">—</span>
                <?php endif; ?>
              </td>
              <td><span class="pill <?php echo $pill_class; ?>"><?php echo htmlspecialchars($res['status']); ?></span></td>
              <td>
                <button class="btn btn-danger btn-xs" data-id="<?php echo (int)$res['id']; ?>" onclick="deleteBooking(<?php echo (int)$res['id']; ?>)">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php else: ?>
      <div style="text-align:center;color:var(--muted);padding:60px 20px;">
        <i class="fa-solid fa-calendar-xmark" style="font-size:36px;display:block;margin-bottom:12px;opacity:.25;"></i>
        <div style="font-size:14px;font-weight:600;margin-bottom:4px;">No Upcoming Reservations</div>
        <div style="font-size:13px;">All reservations have been handled.</div>
      </div>
      <?php endif; ?>
    </div>
  </div><!-- /tab-reservations -->

  <!-- ══════ TAB 3: MENU ══════ -->
  <div class="tab-pane" id="tab-menu">
    <div class="menu-search-bar">
      <div class="menu-search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="menuSearch" placeholder="Search menu items…" oninput="filterMenu()">
      </div>
      <?php if ($has_cat && !empty($menu_categories)): ?>
      <div class="cat-filters">
        <button class="cat-btn active" data-cat="all" onclick="filterCat(this,'all')">All</button>
        <?php foreach ($menu_categories as $cat): ?>
        <button class="cat-btn" data-cat="<?php echo htmlspecialchars($cat); ?>" onclick="filterCat(this,'<?php echo htmlspecialchars(addslashes($cat)); ?>')">
          <?php echo htmlspecialchars($cat); ?>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($menu_items_rows)): ?>
    <div class="menu-grid" id="menuGrid">
      <?php foreach ($menu_items_rows as $mi):
        $cat_val = ($has_cat && !empty($mi['category'])) ? $mi['category'] : '';
      ?>
      <div class="menu-card"
           data-name="<?php echo htmlspecialchars(strtolower($mi['name'])); ?>"
           data-cat="<?php echo htmlspecialchars($cat_val); ?>">
        <?php if ($has_img && !empty($mi['image'])): ?>
        <div class="menu-img-wrap">
          <img class="menu-card-img"
               src="<?php echo htmlspecialchars($mi['image']); ?>"
               alt="<?php echo htmlspecialchars($mi['name']); ?>"
               onerror="this.parentElement.style.display='none'">
        </div>
        <?php endif; ?>
        <div class="menu-card-body">
          <div class="menu-card-name"><?php echo htmlspecialchars($mi['name']); ?></div>
          <div class="menu-card-price">DKK&nbsp;<?php echo number_format((float)$mi['price'],2); ?></div>
          <?php if ($has_cat && !empty($mi['category'])): ?>
          <div class="menu-card-cat"><?php echo htmlspecialchars($mi['category']); ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div id="menuEmpty" style="display:none;text-align:center;color:var(--muted);padding:60px 20px;">
      <i class="fa-solid fa-utensils" style="font-size:32px;display:block;margin-bottom:12px;opacity:.25;"></i>
      <div style="font-size:14px;font-weight:600;">No items found</div>
    </div>
    <?php else: ?>
    <div style="text-align:center;color:var(--muted);padding:60px 20px;">
      <i class="fa-solid fa-utensils" style="font-size:36px;display:block;margin-bottom:12px;opacity:.25;"></i>
      <div style="font-size:14px;font-weight:600;">No menu items available</div>
    </div>
    <?php endif; ?>
  </div><!-- /tab-menu -->

</div><!-- /page-wrap -->

<!-- BILL PANEL OVERLAY -->
<div class="bp-overlay" id="bpOverlay" onclick="closeBill()"></div>

<!-- BILL SLIDE PANEL -->
<div class="bill-panel" id="billPanel">
  <div class="bp-hd">
    <div style="width:38px;height:38px;border-radius:9px;background:var(--green-d);border:1px solid rgba(34,197,94,.2);display:flex;align-items:center;justify-content:center;color:var(--green);font-size:15px;flex-shrink:0;">
      <i class="fa-solid fa-receipt"></i>
    </div>
    <div style="min-width:0;">
      <div style="font-size:15px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="bp-order-num">Bill</div>
      <div style="font-size:12px;color:var(--muted);" id="bp-table">—</div>
    </div>
    <button onclick="closeBill()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--muted);font-size:18px;padding:4px 6px;border-radius:6px;transition:.15s;flex-shrink:0;" onmouseover="this.style.color='var(--red)'" onmouseout="this.style.color='var(--muted)'">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>

  <div class="bp-body" id="bpBody">
    <div style="text-align:center;color:var(--muted);padding:50px 0;">
      <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;"></i>
      Loading bill…
    </div>
  </div>

  <div class="bp-ft">
    <input type="hidden" id="bp-order-id" value="">

    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">Payment Method</div>
    <div class="pay-methods">
      <div class="pay-btn active" id="pm-cash" onclick="selectMethod('cash')">
        <i class="fa-solid fa-money-bill-wave"></i><span>Cash</span>
      </div>
      <div class="pay-btn" id="pm-card" onclick="selectMethod('card')">
        <i class="fa-solid fa-credit-card"></i><span>Card</span>
      </div>
      <div class="pay-btn" id="pm-qr" onclick="selectMethod('qr')">
        <i class="fa-solid fa-qrcode"></i><span>QR / Online</span>
      </div>
    </div>

    <!-- Cash change calculator (cash only) -->
    <div class="change-calc" id="changeCalc">
      <div class="cc-row">
        <div class="cc-lbl">Total Due</div>
        <div class="cc-val" id="cc-total" style="color:var(--text);">DKK 0.00</div>
      </div>
      <div class="cc-row">
        <div class="cc-lbl">Tendered</div>
        <input type="number" id="cc-tendered" placeholder="Amount given…" min="0" step="1"
               style="flex:1;padding:8px 12px;background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:inherit;font-size:13px;outline:none;transition:.2s;"
               onfocus="this.style.borderColor='rgba(34,197,94,.4)'" onblur="this.style.borderColor='var(--border)'"
               oninput="calcChange()">
      </div>
      <div class="cc-row">
        <div class="cc-lbl">Change</div>
        <div class="cc-val positive" id="cc-change">DKK —</div>
      </div>
    </div>

    <!-- Discount -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
      <label style="font-size:12px;color:var(--muted);font-weight:600;flex-shrink:0;white-space:nowrap;">Discount (DKK)</label>
      <input type="number" id="discount-input" value="0" min="0" step="1"
             style="flex:1;padding:7px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:inherit;font-size:13px;outline:none;transition:.2s;"
             onfocus="this.style.borderColor='rgba(34,197,94,.4)'" onblur="this.style.borderColor='var(--border)'"
             oninput="applyDiscount()">
    </div>

    <div style="display:flex;gap:8px;margin-bottom:8px;">
      <button class="btn btn-ghost" style="flex:1;justify-content:center;" onclick="printCurrentBill(false)">
        <i class="fa-solid fa-print"></i> Print Preview
      </button>
    </div>
    <button class="btn btn-primary" id="payBtn" style="width:100%;justify-content:center;padding:12px;" onclick="processPayment()">
      <i class="fa-solid fa-check-circle"></i> Complete Payment
    </button>
  </div>
</div>

<!-- RECEIPT MODAL (shown after payment) -->
<div id="receiptModal" style="display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.7);
     backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px;">
  <div style="background:var(--surface);border:1px solid var(--border2);border-radius:18px;
              width:100%;max-width:420px;overflow:hidden;animation:billIn .25s cubic-bezier(.16,1,.3,1);">
    <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;">
      <div style="width:38px;height:38px;border-radius:9px;background:var(--green-d);border:1px solid rgba(34,197,94,.25);
                  display:flex;align-items:center;justify-content:center;color:var(--green);font-size:16px;">
        <i class="fa-solid fa-check"></i>
      </div>
      <div>
        <div style="font-size:15px;font-weight:700;">Payment Complete</div>
        <div style="font-size:12px;color:var(--muted);" id="rmSubtitle">—</div>
      </div>
    </div>
    <div style="padding:18px 22px;" id="rmBody"></div>
    <div style="padding:12px 22px 20px;display:flex;gap:8px;">
      <button class="btn btn-ghost" style="flex:1;justify-content:center;" onclick="printReceiptModal()">
        <i class="fa-solid fa-print"></i> Print Receipt
      </button>
      <button class="btn btn-primary" style="flex:1;justify-content:center;" onclick="closeReceiptModal()">
        <i class="fa-solid fa-check"></i> Done
      </button>
    </div>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div id="toast-wrap"></div>

<script>
/* ── Clock ── */
(function tick(){
  const n=new Date(), p=v=>String(v).padStart(2,'0');
  document.getElementById('clock').textContent = p(n.getHours())+':'+p(n.getMinutes())+':'+p(n.getSeconds());
  setTimeout(tick,1000);
})();

/* ── Tab switching ── */
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab===name));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id==='tab-'+name));
}

/* ════════════════ PRINT RECEIPT ════════════════ */
function buildReceiptHtml(bill, opts) {
  const { paid = false, paymentMethod = '', discount = 0 } = opts || {};
  const total = Math.max(0, bill.subtotal + bill.tax - discount);
  const o = bill.order || {};
  const now = new Date();
  const pad = v => String(v).padStart(2, '0');
  const dateStr = now.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
                + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());

  const itemRows = (bill.items || []).map(item => {
    return `<tr class="it">
      <td style="padding:5px 0 5px 0;font-size:12.5px;vertical-align:top;line-height:1.4;">${item.name}</td>
      <td style="text-align:center;padding:5px 6px;font-size:12.5px;vertical-align:top;width:26px;">${item.quantity}</td>
      <td style="text-align:right;padding:5px 0;font-size:12.5px;font-weight:700;vertical-align:top;white-space:nowrap;">
        DKK&nbsp;${Number(item.subtotal).toFixed(2)}
      </td>
    </tr>`;
  }).join('');

  return `<!DOCTYPE html><html><head><meta charset="UTF-8">
  <title>Receipt \u2014 ${o.order_number || ''}</title>
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Courier New',Courier,monospace;font-size:13px;color:#111;background:#fff;
         width:302px;margin:0 auto;padding:14px 10px}
    hr.s{border:none;border-top:2px solid #111;margin:9px 0}
    hr.d{border:none;border-top:1px dashed #aaa;margin:7px 0}
    .rn{font-size:23px;font-weight:900;letter-spacing:1.5px;text-align:center;line-height:1.1;margin-bottom:1px}
    .rt{font-size:10px;color:#555;text-align:center;letter-spacing:4px;text-transform:uppercase}
    .rc{font-size:10px;color:#999;text-align:center;margin-top:2px}
    table{width:100%;border-collapse:collapse}
    .ih th{font-size:10px;color:#555;text-transform:uppercase;letter-spacing:.6px;
           padding:4px 0;border-top:1px solid #222;border-bottom:1px solid #222}
    .it td{border-bottom:1px dotted #ddd}
    .it:last-child td{border-bottom:none}
    .tt td{padding:3px 0;font-size:12.5px}
    .ttr td{font-size:15px;font-weight:900;padding-top:8px;border-top:2px solid #111;letter-spacing:.5px}
    .stamp{display:inline-block;border:3px solid #111;font-size:16px;font-weight:900;
           padding:5px 24px;letter-spacing:4px;transform:rotate(-4deg);
           box-shadow:2px 2px 0 #ccc}
    .foot{text-align:center;font-size:11px;color:#666;margin-top:10px;line-height:1.9}
    @media print{body{width:80mm;padding:8px 6px}}
  </style></head><body>
  <div class="rn">&#x1F37D; Gourmet House</div>
  <div class="rt">Fine Dining &amp; Bar</div>
  <div class="rc">www.gourmethouse.dk &bull; +45 00 00 00 00</div>
  <hr class="s">
  <div style="text-align:center;margin:5px 0 6px;">
    <div style="font-size:14px;font-weight:700;letter-spacing:.5px;">${o.order_number || ''}</div>
    <div style="font-size:11px;color:#666;margin-top:1px;">${dateStr}</div>
    <div style="font-size:12.5px;margin-top:3px;">
      <strong>Table&nbsp;${o.table_number || '?'}</strong>
      ${o.waiter ? ' &bull; Served&nbsp;by:&nbsp;' + o.waiter : ''}
    </div>
  </div>
  <hr class="d">
  <table>
    <thead class="ih"><tr>
      <th style="text-align:left;">Description</th>
      <th style="text-align:center;">Qty</th>
      <th style="text-align:right;">Amount</th>
    </tr></thead>
    <tbody>${itemRows}</tbody>
  </table>
  <hr class="d">
  <table class="tt">
    <tr><td>Subtotal</td><td style="text-align:right;">DKK&nbsp;${Number(bill.subtotal).toFixed(2)}</td></tr>
    <tr><td>VAT&nbsp;(10%)</td><td style="text-align:right;">DKK&nbsp;${Number(bill.tax).toFixed(2)}</td></tr>
    ${discount > 0 ? `<tr><td style="color:#16a34a;">Discount</td><td style="text-align:right;color:#16a34a;">&minus;&nbsp;DKK&nbsp;${Number(discount).toFixed(2)}</td></tr>` : ''}
    <tr class="ttr"><td>TOTAL</td><td style="text-align:right;">DKK&nbsp;${Number(total).toFixed(2)}</td></tr>
    ${paid ? `<tr><td style="font-size:11px;color:#666;padding-top:6px;">Payment</td><td style="text-align:right;font-size:11px;color:#666;padding-top:6px;">${String(paymentMethod).toUpperCase()}</td></tr>` : ''}
  </table>
  <div style="text-align:center;margin:12px 0 8px;">
    ${paid
      ? '<span class="stamp">PAID</span>'
      : '<span style="font-size:11px;color:#aaa;font-style:italic;">&mdash;&nbsp;PREVIEW &mdash; NOT YET PAID&nbsp;&mdash;</span>'}
  </div>
  <hr class="d">
  <div class="foot">
    <div style="font-size:12px;font-weight:700;">Thank you for dining with us!</div>
    <div style="font-size:10px;color:#aaa;">We look forward to your next visit &hearts;</div>
    <div style="font-size:10px;color:#bbb;margin-top:6px;border-top:1px solid #eee;padding-top:5px;">
      VAT included &bull; ${o.order_number || ''} &bull; Printed ${dateStr}
    </div>
  </div>
  <script>window.onload=function(){window.print();}<\/script>
  </body></html>`;
}

function printReceipt(bill, opts) {
  const html = buildReceiptHtml(bill, opts);
  const win = window.open('', '_blank', 'width=460,height=640,toolbar=0,scrollbars=1,resizable=1');
  if (!win) { toast('Pop-up blocked — allow pop-ups and try again', 'error'); return; }
  win.document.write(html);
  win.document.close();
}

/* ════════════════ BILL PANEL ════════════════ */
let currentBill = null;
let selectedMethod = 'cash';
let _paidBillData = null; /* holds last paid bill for receipt modal */

async function openBill(oid) {
  document.querySelectorAll('.order-row').forEach(r => r.classList.remove('selected'));
  const row = document.getElementById('order-row-'+oid);
  if (row) row.classList.add('selected');

  document.getElementById('bp-order-id').value = oid;
  document.getElementById('bp-order-num').textContent = 'Loading…';
  document.getElementById('bp-table').textContent = '—';
  document.getElementById('bpBody').innerHTML =
    '<div style="text-align:center;color:var(--muted);padding:50px 0;">'
    +'<i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;"></i>Loading bill…</div>';
  document.getElementById('discount-input').value = '0';
  document.getElementById('cc-tendered').value = '';
  document.getElementById('cc-change').textContent = 'DKK —';
  document.getElementById('cc-change').className = 'cc-val';
  document.getElementById('billPanel').classList.add('open');
  document.getElementById('bpOverlay').classList.add('open');

  try {
    const res  = await fetch('dashboard.php?action=bill&order_id='+oid);
    const data = await res.json();
    if (!data.success) { toast(data.error||'Failed to load bill', 'error'); return; }
    currentBill = data;
    renderBill(data);
  } catch(e) {
    toast('Network error loading bill', 'error');
  }
}

function renderBill(data) {
  const o = data.order;
  document.getElementById('bp-order-num').textContent = o.order_number;
  document.getElementById('bp-table').textContent = 'Table '+(o.table_number||'?')+' — '+(o.waiter||'—');

  const discount = Math.max(0, parseFloat(document.getElementById('discount-input').value)||0);
  const total    = Math.max(0, data.subtotal + data.tax - discount);

  const dt = new Date();
  const pad = v=>String(v).padStart(2,'0');
  const dateStr = dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})
                + ' ' + pad(dt.getHours())+':'+pad(dt.getMinutes());

  let html = `<div class="receipt-hd">
    <div style="font-size:11px;color:var(--muted);margin-bottom:3px;">${dateStr}</div>
    <div style="font-size:14px;font-weight:700;">${esc(o.order_number)}</div>
    <div style="font-size:12px;color:var(--muted);">Table ${esc(o.table_number||'?')}</div>`;

  if (o.order_type === 'reservation') {
    html += `<div style="margin-top:6px;"><span style="background:rgba(96,165,250,.13);color:var(--blue);border-radius:20px;padding:2px 10px;font-size:10.5px;font-weight:700;"><i class="fa-solid fa-calendar" style="margin-right:4px;"></i>RSV</span></div>`;
  }
  html += `</div>`;

  html += `<div style="margin-bottom:14px;">`;
  data.items.forEach(item => {
    html += `<div class="receipt-item">
      <div>
        <div style="font-size:13px;font-weight:500;">${esc(item.name)}</div>
        <div style="font-size:11px;color:var(--muted);">&times;${item.quantity} &nbsp;@&nbsp; DKK&nbsp;${Number(item.price).toFixed(2)}</div>
      </div>
      <div style="font-size:13px;font-weight:600;flex-shrink:0;">DKK&nbsp;${Number(item.subtotal).toFixed(2)}</div>
    </div>`;
  });
  html += `</div>`;

  html += `<div class="receipt-totals">
    <div class="rt-row"><span style="color:var(--muted);">Subtotal</span><span>DKK&nbsp;${Number(data.subtotal).toFixed(2)}</span></div>
    <div class="rt-row"><span style="color:var(--muted);">Tax (10%)</span><span>DKK&nbsp;${Number(data.tax).toFixed(2)}</span></div>`;
  if (discount > 0) {
    html += `<div class="rt-row"><span style="color:var(--green);">Discount</span><span style="color:var(--green);">&minus; DKK&nbsp;${Number(discount).toFixed(2)}</span></div>`;
  }
  html += `<div class="rt-row grand"><span>Total Due</span><span class="rt-val">DKK&nbsp;${Number(total).toFixed(2)}</span></div>
  </div>`;

  document.getElementById('bpBody').innerHTML = html;
  document.getElementById('cc-total').textContent = 'DKK '+Number(total).toFixed(2);
  calcChange();
}

function applyDiscount() {
  if (currentBill) renderBill(currentBill);
}

function printCurrentBill(paid, paymentMethod) {
  if (!currentBill) { toast('Open a bill first', 'error'); return; }
  const discount = Math.max(0, parseFloat(document.getElementById('discount-input').value) || 0);
  printReceipt(currentBill, { paid: !!paid, paymentMethod: paymentMethod || selectedMethod, discount });
}

function closeBill() {
  document.getElementById('billPanel').classList.remove('open');
  document.getElementById('bpOverlay').classList.remove('open');
  document.querySelectorAll('.order-row').forEach(r => r.classList.remove('selected'));
  currentBill = null;
  const btn = document.getElementById('payBtn');
  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Complete Payment';
}

function selectMethod(m) {
  selectedMethod = m;
  ['cash','card','qr'].forEach(k =>
    document.getElementById('pm-'+k).classList.toggle('active', k===m)
  );
  document.getElementById('changeCalc').style.display = (m==='cash') ? 'block' : 'none';
}

function calcChange() {
  if (!currentBill) return;
  const discount  = Math.max(0, parseFloat(document.getElementById('discount-input').value)||0);
  const total     = Math.max(0, currentBill.subtotal + currentBill.tax - discount);
  const tendInput = document.getElementById('cc-tendered').value;
  const el        = document.getElementById('cc-change');
  if (!tendInput) { el.textContent = 'DKK —'; el.className = 'cc-val'; return; }
  const tendered = parseFloat(tendInput)||0;
  const change   = tendered - total;
  el.className   = 'cc-val ' + (change >= 0 ? 'positive' : 'negative');
  el.textContent = (change < 0 ? '− ' : '') + 'DKK '+Math.abs(change).toFixed(2);
}

async function processPayment() {
  const oid = document.getElementById('bp-order-id').value;
  if (!oid) return;
  const discount = Math.max(0, parseFloat(document.getElementById('discount-input').value)||0);
  const btn = document.getElementById('payBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

  try {
    const res  = await fetch('dashboard.php?action=pay', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: `order_id=${encodeURIComponent(oid)}&payment_method=${encodeURIComponent(selectedMethod)}&discount=${encodeURIComponent(discount)}`
    });
    const data = await res.json();

    if (data.success) {
      toast('Payment completed! DKK '+Number(data.total).toFixed(2), 'success');
      document.getElementById('order-row-'+oid)?.remove();
      /* Store for receipt modal */
      _paidBillData = {
        bill: currentBill,
        discount: Math.max(0, parseFloat(document.getElementById('discount-input').value) || 0),
        paymentMethod: selectedMethod,
        total: data.total,
      };
      closeBill();
      showReceiptModal(_paidBillData);
    } else {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Complete Payment';
      toast(data.error||'Payment failed', 'error');
    }
  } catch(e) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Complete Payment';
    toast('Network error. Please try again.', 'error');
  }
}

/* ════════════════ RECEIPT MODAL ════════════════ */
function showReceiptModal(paidData) {
  const total = Math.max(0, paidData.bill.subtotal + paidData.bill.tax - paidData.discount);
  document.getElementById('rmSubtitle').textContent =
    'DKK ' + Number(total).toFixed(2) + ' — ' + String(paidData.paymentMethod).toUpperCase();

  const o = paidData.bill.order || {};
  document.getElementById('rmBody').innerHTML = `
    <div style="text-align:center;padding:10px 0;">
      <div style="font-size:40px;margin-bottom:8px;">🧾</div>
      <div style="font-weight:700;font-size:16px;margin-bottom:4px;">${esc(o.order_number || '')}</div>
      <div style="font-size:13px;color:var(--muted);">Table ${esc(o.table_number || '?')} &bull; ${esc(o.waiter || '—')}</div>
      <div style="margin-top:14px;padding:12px;background:var(--surface2);border-radius:12px;font-size:13px;">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
          <span style="color:var(--muted);">Subtotal</span>
          <span>DKK ${Number(paidData.bill.subtotal).toFixed(2)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
          <span style="color:var(--muted);">Tax (10%)</span>
          <span>DKK ${Number(paidData.bill.tax).toFixed(2)}</span>
        </div>
        ${paidData.discount > 0 ? `<div style="display:flex;justify-content:space-between;margin-bottom:6px;">
          <span style="color:var(--green);">Discount</span>
          <span style="color:var(--green);">- DKK ${Number(paidData.discount).toFixed(2)}</span>
        </div>` : ''}
        <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:700;
                    border-top:1px solid var(--border);padding-top:8px;margin-top:4px;">
          <span>Total Paid</span>
          <span style="color:var(--gold);">DKK ${Number(total).toFixed(2)}</span>
        </div>
      </div>
    </div>`;
  document.getElementById('receiptModal').style.display = 'flex';
}

function printReceiptModal() {
  if (!_paidBillData) return;
  printReceipt(_paidBillData.bill, {
    paid: true,
    paymentMethod: _paidBillData.paymentMethod,
    discount: _paidBillData.discount,
  });
}

function closeReceiptModal() {
  document.getElementById('receiptModal').style.display = 'none';
  _paidBillData = null;
  setTimeout(() => location.reload(), 300);
}

/* ════════════════ AUTO-POLL ════════════════ */
let _lastQueueCount = <?php echo $ready_count; ?>;

async function pollQueue() {
  try {
    const r = await fetch('dashboard.php?action=poll_queue');
    const d = await r.json();
    if (!d.success) return;

    /* Badge update on tab title */
    const count = d.count;
    document.title = count > 0 ? '(' + count + ') Cashier — RestaurantMS' : 'Cashier — RestaurantMS';

    /* If queue changed significantly, reload order list */
    if (Math.abs(count - _lastQueueCount) > 0) {
      _lastQueueCount = count;
      /* Soft reload: only reload if bill panel is closed */
      if (!document.getElementById('billPanel').classList.contains('open')) {
        location.reload();
      }
    }
  } catch(e) {}
}
setInterval(pollQueue, 20000);

/* Init */
selectMethod('cash');

/* ════════════════ RESERVATIONS ════════════════ */
async function deleteBooking(bid) {
  if (!confirm('Cancel this reservation? This action cannot be undone.')) return;
  try {
    const res  = await fetch('dashboard.php?action=delete_booking', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'booking_id='+encodeURIComponent(bid)
    });
    const data = await res.json();
    if (data.success) {
      const row = document.getElementById('booking-row-'+bid);
      if (row) { row.style.transition='opacity .3s'; row.style.opacity='0'; setTimeout(()=>row.remove(),300); }
      toast('Reservation cancelled', 'success');
    } else {
      toast(data.error||'Failed to cancel reservation', 'error');
    }
  } catch(e) {
    toast('Network error', 'error');
  }
}

function filterReservations() {
  const q = document.getElementById('resSearch').value.toLowerCase().trim();
  document.querySelectorAll('#resTable tbody tr').forEach(row => {
    const name = row.cells[1]?.textContent.toLowerCase() || '';
    row.style.display = (!q || name.includes(q)) ? '' : 'none';
  });
}

/* ════════════════ MENU ════════════════ */
let activeCat = 'all';

function filterMenu() {
  const q = document.getElementById('menuSearch').value.toLowerCase().trim();
  let visible = 0;
  document.querySelectorAll('.menu-card').forEach(card => {
    const name    = card.dataset.name || '';
    const cat     = card.dataset.cat  || '';
    const matchQ  = !q || name.includes(q);
    const matchC  = (activeCat==='all') || (cat.toLowerCase()===activeCat.toLowerCase());
    const show    = matchQ && matchC;
    card.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  const empty = document.getElementById('menuEmpty');
  if (empty) empty.style.display = (visible===0) ? 'block' : 'none';
}

function filterCat(btn, cat) {
  activeCat = cat;
  document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  filterMenu();
}

/* ════════════════ TOAST ════════════════ */
function esc(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

function toast(msg, type='info') {
  const icons = {success:'fa-circle-check', error:'fa-circle-xmark', info:'fa-circle-info', warning:'fa-triangle-exclamation'};
  const t = document.createElement('div');
  t.className = 'toast '+type;
  t.innerHTML = `<i class="fa-solid ${icons[type]||'fa-circle-info'}"></i><span>${msg}</span>`;
  document.getElementById('toast-wrap').appendChild(t);
  setTimeout(() => { t.style.transition='opacity .3s'; t.style.opacity='0'; setTimeout(()=>t.remove(),300); }, 4700);
}
</script>
</body>
</html>
