<?php
ob_start();
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'waiter') {
    ob_end_clean();
    header("Location: ../auth/login.php"); exit();
}

$uid   = (int)$_SESSION['user']['id'];
$uname = $_SESSION['user']['username'];
$today = date('Y-m-d');

/* ════════════════════════════════════════════
   AJAX HANDLERS
════════════════════════════════════════════ */
if (isset($_GET['action'])) {
    ob_end_clean();
    session_write_close(); /* release session lock so concurrent polls don't block each other */
    header('Content-Type: application/json');

    /* ── preview_bill ── */
    if ($_GET['action'] === 'preview_bill' && isset($_GET['order_id'])) {
        $oid = (int)$_GET['order_id'];
        $order = $conn->query("
            SELECT o.id, o.order_number, o.status, o.total_amount,
                   t.table_number, u.username AS waiter
            FROM orders o
            LEFT JOIN `tables` t ON o.table_id = t.id
            LEFT JOIN users u ON o.waiter_id = u.id
            WHERE o.id = $oid
              AND o.waiter_id = $uid
              AND o.status NOT IN ('completed','cancelled')
        ")->fetch_assoc();

        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Order not found or access denied']);
            exit();
        }

        /* Defensive column check for order_items price column */
        $has_unit_price = $conn->query("SHOW COLUMNS FROM order_items LIKE 'unit_price'")->num_rows > 0;
        $has_is_rec     = $conn->query("SHOW COLUMNS FROM order_items LIKE 'is_recovery'")->num_rows > 0;
        $price_expr     = $has_unit_price ? "COALESCE(oi.unit_price, mi.price, 0)" : "COALESCE(oi.price, mi.price, 0)";
        $rec_col        = $has_is_rec ? ", oi.is_recovery" : "";

        $items_q = $conn->query("
            SELECT mi.name, oi.quantity,
                   $price_expr AS price
                   $rec_col
            FROM order_items oi
            JOIN menu_items mi ON oi.menu_item_id = mi.id
            WHERE oi.order_id = $oid
            ORDER BY oi.id ASC
        ");
        $items     = [];
        $subtotal  = 0.0;
        while ($row = $items_q->fetch_assoc()) {
            $is_rec = $has_is_rec ? (bool)$row['is_recovery'] : false;
            $price  = $is_rec ? 0.0 : (float)$row['price'];
            $line   = $price * (int)$row['quantity'];
            $subtotal += $line;
            $items[] = [
                'name'        => $row['name'],
                'quantity'    => (int)$row['quantity'],
                'price'       => $price,
                'subtotal'    => $line,
                'is_recovery' => $is_rec,
            ];
        }
        $tax   = round($subtotal * 0.10, 2);
        $total = round($subtotal + $tax, 2);

        echo json_encode([
            'success'  => true,
            'order'    => [
                'order_number' => $order['order_number'],
                'table_number' => $order['table_number'],
                'waiter'       => $order['waiter'] ?? '',
                'status'       => $order['status'],
            ],
            'items'    => $items,
            'subtotal' => round($subtotal, 2),
            'tax'      => $tax,
            'total'    => $total,
        ]);
        exit();
    }

    /* ── pay: process payment from waiter ── */
    if ($_GET['action'] === 'pay') {
        $oid    = (int)($_POST['order_id'] ?? 0);
        $method = $_POST['payment_method'] ?? '';
        if (!$oid || !in_array($method, ['cash','card','qr'])) {
            echo json_encode(['success'=>false,'error'=>'Invalid parameters']); exit();
        }

        /* Allow payment for any active order (waiter may collect payment at any time) */
        $order = $conn->query("SELECT * FROM orders WHERE id=$oid AND waiter_id=$uid AND status NOT IN ('completed','cancelled')")->fetch_assoc();
        if (!$order) {
            echo json_encode(['success'=>false,'error'=>'Order not found or already completed']); exit();
        }

        $has_unit_price = $conn->query("SHOW COLUMNS FROM order_items LIKE 'unit_price'")->num_rows > 0;
        $has_is_rec_pay = $conn->query("SHOW COLUMNS FROM order_items LIKE 'is_recovery'")->num_rows > 0;
        $price_expr     = $has_unit_price ? "COALESCE(oi.unit_price, mi.price, 0)" : "COALESCE(oi.price, mi.price, 0)";
        $rec_where      = $has_is_rec_pay ? "AND oi.is_recovery = 0" : "";
        $items_q = $conn->query("SELECT oi.quantity, $price_expr AS price FROM order_items oi JOIN menu_items mi ON oi.menu_item_id=mi.id WHERE oi.order_id=$oid $rec_where");
        $subtotal = 0.0;
        while ($i = $items_q->fetch_assoc()) $subtotal += (float)$i['price'] * (int)$i['quantity'];
        $tax      = round($subtotal * 0.10, 2);
        $discount = max(0, (float)($_POST['discount'] ?? 0));
        $total    = round($subtotal + $tax - $discount, 2);

        /* Defensive: check if billing.discount column exists */
        $has_discount_col = $conn->query("SHOW COLUMNS FROM billing LIKE 'discount'")->num_rows > 0;

        if ($has_discount_col) {
            $bill_stmt = $conn->prepare(
                "INSERT INTO billing (order_id, subtotal, tax, discount, total, payment_method, payment_status, cashier_id, billing_date)
                 VALUES (?,?,?,?,?,?,'completed',?,NOW())"
            );
        } else {
            $bill_stmt = $conn->prepare(
                "INSERT INTO billing (order_id, subtotal, tax, total, payment_method, payment_status, cashier_id, billing_date)
                 VALUES (?,?,?,?,?,?,'completed',?,NOW())"
            );
        }

        if (!$bill_stmt) {
            echo json_encode(['success'=>false,'error'=>'Billing error: '.$conn->error]); exit();
        }

        if ($has_discount_col) {
            $bill_stmt->bind_param("iddddsi", $oid, $subtotal, $tax, $discount, $total, $method, $uid);
        } else {
            $bill_stmt->bind_param("idddsi", $oid, $subtotal, $tax, $total, $method, $uid);
        }

        if (!$bill_stmt->execute()) {
            echo json_encode(['success'=>false,'error'=>'Billing insert failed: '.$bill_stmt->error]); exit();
        }
        $billing_id = (int)$conn->insert_id;

        $tx_stmt = $conn->prepare("INSERT INTO transactions (billing_id, amount, payment_method, status) VALUES (?,?,?,'completed')");
        if ($tx_stmt) {
            $tx_stmt->bind_param("ids", $billing_id, $total, $method);
            $tx_stmt->execute();
        }

        $conn->query("UPDATE orders SET status='completed' WHERE id=$oid");
        $tid = (int)$order['table_id'];
        if ($tid) {
            $conn->query("UPDATE `tables` SET status='available' WHERE id=$tid");
            /* Close the SQ session and auto-resolve any open alerts */
            try {
                include_once("../service_quality/functions.php");
                resetTableScore($conn, $tid);
            } catch (\Throwable $e) {}
        }

        /* Complete linked booking (reservation or walk-in) */
        $has_res_col = $conn->query("SHOW COLUMNS FROM orders LIKE 'reservation_id'")->num_rows > 0;
        if ($has_res_col && !empty($order['reservation_id'])) {
            $conn->query("UPDATE bookings SET status='completed' WHERE id=".(int)$order['reservation_id']);
        } elseif ($tid) {
            $conn->query("UPDATE bookings SET status='completed' WHERE assigned_table=$tid AND booking_date=CURDATE() AND status NOT IN ('cancelled','completed')");
        }

        echo json_encode(['success'=>true,'total'=>$total]);
        exit();
    }

    /* ── mark_served: ready → served (removes from kitchen) ── */
    if ($_GET['action'] === 'mark_served') {
        $oid = (int)($_POST['order_id'] ?? 0);
        if (!$oid) { echo json_encode(['success'=>false,'error'=>'Missing order_id']); exit(); }
        $ok = $conn->query("UPDATE orders SET status='served' WHERE id=$oid AND waiter_id=$uid AND status='ready'");
        if ($conn->affected_rows > 0) {
            /* Stamp served_at on all items that were ready but not yet served */
            $has_served_at = $conn->query("SHOW COLUMNS FROM order_items LIKE 'served_at'")->num_rows > 0;
            if ($has_served_at) {
                $conn->query("UPDATE order_items SET served_at=NOW() WHERE order_id=$oid AND item_status='ready' AND served_at IS NULL");
            }
            /* Recalculate score — food is now served, lift the "sitting at pass" penalty */
            try {
                include_once("../service_quality/functions.php");
                $trow = $conn->query("SELECT table_id FROM orders WHERE id=$oid")->fetch_assoc();
                if ($trow && $trow['table_id']) updateSatisfactionScore($conn, (int)$trow['table_id']);
            } catch (\Throwable $e) { /* silent */ }
            echo json_encode(['success'=>true]);
        } else {
            echo json_encode(['success'=>false,'error'=>'Order not found, not ready, or not yours']);
        }
        exit();
    }

    /* ── poll_orders: return current status of waiter's active orders ── */
    if ($_GET['action'] === 'poll_orders') {
        $rows = [];
        $q = $conn->query("
            SELECT o.id, o.status, o.order_number, t.table_number
            FROM orders o
            LEFT JOIN \`tables\` t ON o.table_id = t.id
            WHERE o.waiter_id = $uid
              AND o.status NOT IN ('completed','cancelled')
              AND DATE(o.created_at) = '$today'
        ");
        while ($row = $q->fetch_assoc()) {
            $rows[] = ['id' => (int)$row['id'], 'status' => $row['status'],
                       'order_number' => $row['order_number'], 'table_number' => $row['table_number']];
        }
        echo json_encode(['orders' => $rows]);
        exit();
    }

    /* ── check_bell: waiter polls for kitchen bell notifications ── */
    if ($_GET['action'] === 'check_bell') {
        $has_bell = $conn->query("SHOW COLUMNS FROM orders LIKE 'bell_rung_at'")->num_rows > 0;
        if (!$has_bell) {
            echo json_encode(['bells' => []]); exit();
        }
        $bells = [];
        $q = $conn->query("
            SELECT o.id, o.order_number, o.bell_rung_at,
                   t.table_number
            FROM orders o
            LEFT JOIN \`tables\` t ON o.table_id = t.id
            WHERE o.waiter_id = $uid
              AND o.status = 'ready'
              AND o.bell_rung_at IS NOT NULL
              AND o.bell_rung_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ORDER BY o.bell_rung_at DESC
        ");
        while ($row = $q->fetch_assoc()) $bells[] = $row;
        echo json_encode(['bells' => $bells]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit();
}

/* ════════════════════════════════════════════
   FLOOR PLAN QUERY (enriched with reservations)
════════════════════════════════════════════ */
$tables_q = $conn->query("
    SELECT t.id, t.table_number, t.capacity, t.status,
           o.id            AS order_id,
           o.order_number,
           o.status        AS order_status,
           o.total_amount,
           o.created_at    AS order_created,
           o.waiter_id,
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count,
           b.id            AS booking_id,
           b.customer_name AS guest_name,
           b.num_guests    AS booking_guests,
           TIME_FORMAT(b.booking_time, '%H:%i') AS booking_time,
           b.status        AS booking_status
    FROM `tables` t
    LEFT JOIN orders o
           ON o.table_id = t.id
          AND o.status IN ('pending','preparing','ready','served')
    LEFT JOIN bookings b
           ON b.assigned_table = t.id
          AND b.booking_date   = CURDATE()
          AND b.status IN ('confirmed','pending','seated')
    ORDER BY t.table_number ASC
");
$tables = [];
while ($row = $tables_q->fetch_assoc()) $tables[] = $row;

/* ════════════════════════════════════════════
   MY ACTIVE ORDERS TODAY
════════════════════════════════════════════ */
$active_q = $conn->query("
    SELECT o.id, o.order_number, o.status, o.total_amount, o.created_at,
           t.table_number,
           COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN `tables` t      ON o.table_id  = t.id
    LEFT JOIN order_items oi  ON oi.order_id = o.id
    WHERE o.waiter_id = $uid
      AND DATE(o.created_at) = '$today'
      AND o.status NOT IN ('completed','cancelled')
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$active_orders = [];
while ($row = $active_q->fetch_assoc()) $active_orders[] = $row;

/* ════════════════════════════════════════════
   STATS
════════════════════════════════════════════ */
/* tables_assigned: distinct tables with my active orders */
$r = $conn->query("SELECT COUNT(DISTINCT table_id) AS v FROM orders WHERE waiter_id=$uid AND status NOT IN ('completed','cancelled') AND DATE(created_at)='$today'");
$tables_assigned = (int)$r->fetch_assoc()['v'];

/* orders_today */
$r = $conn->query("SELECT COUNT(*) AS v FROM orders WHERE waiter_id=$uid AND DATE(created_at)='$today'");
$orders_today = (int)$r->fetch_assoc()['v'];

/* pending items across my active orders */
$r = $conn->query("
    SELECT COUNT(*) AS v FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE o.waiter_id = $uid
      AND o.status NOT IN ('completed','cancelled')
      AND oi.item_status = 'pending'
");
$pending_items = (int)$r->fetch_assoc()['v'];

/* total revenue today (from billing) — overall + per payment method */
$r = $conn->query("
    SELECT COALESCE(SUM(b.total), 0) AS total,
           COALESCE(SUM(CASE WHEN b.payment_method='cash' THEN b.total ELSE 0 END), 0) AS cash,
           COALESCE(SUM(CASE WHEN b.payment_method='card' THEN b.total ELSE 0 END), 0) AS card,
           COALESCE(SUM(CASE WHEN b.payment_method='qr'   THEN b.total ELSE 0 END), 0) AS qr
    FROM billing b
    JOIN orders o ON b.order_id = o.id
    WHERE o.waiter_id = $uid
      AND DATE(b.billing_date) = '$today'
      AND b.payment_status = 'completed'
");
$rev = $r->fetch_assoc();
$my_revenue      = (float)$rev['total'];
$my_rev_cash     = (float)$rev['cash'];
$my_rev_card     = (float)$rev['card'];
$my_rev_qr       = (float)$rev['qr'];

/* ── Today's bookings ── */
$bookings_q = $conn->query("
    SELECT b.id, b.customer_name, b.num_guests, b.status, b.phone,
           b.assigned_table AS table_id,
           TIME_FORMAT(b.booking_time, '%H:%i') AS booking_time,
           t.table_number
    FROM bookings b
    LEFT JOIN `tables` t ON b.assigned_table = t.id
    WHERE b.booking_date = '$today'
      AND b.status NOT IN ('cancelled','completed')
    ORDER BY b.booking_time ASC
");
$bookings_today = [];
while ($brow = $bookings_q->fetch_assoc()) $bookings_today[] = $brow;

$now_ts = time();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Waiter — RestaurantMS</title>
<?php $role_accent = '#818cf8'; $role_accent2 = '#a5b4fc'; include("../config/staff_head.php"); ?>
<style>
body {
  overflow: hidden;
  background-image:
    radial-gradient(ellipse at 22% 0%,   rgba(129,140,248,.16) 0%, transparent 55%),
    radial-gradient(ellipse at 78% 100%, rgba(167,139,250,.11) 0%, transparent 55%) !important;
}

/* ══════════════════════════════════════════
   TWO-COLUMN LAYOUT
══════════════════════════════════════════ */
.w-layout {
  display: flex;
  height: calc(100vh - var(--topbar-h));
  overflow: hidden;
}
.w-left {
  flex: 65;
  min-width: 0;
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--border);
  overflow: hidden;
}
.w-right {
  flex: 35;
  min-width: 290px;
  max-width: 420px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--surface);
}
.w-panel-hd {
  padding: 13px 18px 11px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--surface);
}
.w-panel-hd h3 {
  font-size: 12.5px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--text);
}
.w-panel-hd h3 i { color: var(--accent); }
.w-scroll { flex: 1; overflow-y: auto; padding: 14px 16px; }

/* ══════════════════════════════════════════
   STATS BAR
══════════════════════════════════════════ */
.w-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
  background: rgba(18,18,26,.82);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
}
.w-stat {
  padding: 13px 20px;
  border-right: 1px solid var(--border);
  position: relative;
  overflow: hidden;
  transition: background .18s;
}
.w-stat:hover { background: rgba(255,255,255,.022); }
.w-stat:last-child { border-right: none; }
.w-stat-top {
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
}
.w-stat-lbl {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  margin-bottom: 5px;
}
.w-stat-val {
  font-size: 24px;
  font-weight: 700;
  font-family: 'Playfair Display', serif;
  line-height: 1.1;
}
.w-stat-sub {
  font-size: 10.5px;
  color: var(--muted);
  margin-top: 3px;
}

/* ══════════════════════════════════════════
   FLOOR PLAN LEGEND
══════════════════════════════════════════ */
.legend { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.leg-item { display: flex; align-items: center; gap: 5px; font-size: 10.5px; color: var(--muted); }
.leg-dot { width: 7px; height: 7px; border-radius: 50%; }

/* ══════════════════════════════════════════
   FLOOR GRID — premium table tiles
══════════════════════════════════════════ */
.floor-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
  gap: 12px;
}

.table-tile {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 18px;
  padding: 18px 14px 14px;
  text-align: center;
  cursor: pointer;
  transition: all .22s cubic-bezier(.16,1,.3,1);
  position: relative;
  user-select: none;
  overflow: hidden;
}
.table-tile::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  background: radial-gradient(circle at 50% 0%, rgba(255,255,255,.03) 0%, transparent 70%);
  pointer-events: none;
}
.table-tile:hover { transform: translateY(-4px) scale(1.02); }

/* Available — no booking */
.table-tile.tt-free {
  border-color: rgba(34,197,94,.22);
  background: linear-gradient(145deg, var(--surface), rgba(34,197,94,.03));
}
.table-tile.tt-free:hover {
  border-color: rgba(34,197,94,.6);
  box-shadow: 0 16px 48px rgba(34,197,94,.18), 0 4px 16px rgba(0,0,0,.5);
}

/* Available — has booking today */
.table-tile.tt-reserved-free {
  border-color: rgba(245,158,11,.3);
  background: linear-gradient(145deg, var(--surface), rgba(245,158,11,.04));
  box-shadow: 0 0 0 1px rgba(245,158,11,.1);
}
.table-tile.tt-reserved-free:hover {
  border-color: rgba(245,158,11,.6);
  box-shadow: 0 12px 40px rgba(245,158,11,.12);
}

/* Occupied — my order */
.table-tile.tt-mine {
  border-color: rgba(129,140,248,.35);
  background: linear-gradient(145deg, var(--surface), rgba(129,140,248,.06));
  box-shadow: 0 0 0 1px rgba(129,140,248,.1);
}
.table-tile.tt-mine:hover {
  border-color: rgba(129,140,248,.8);
  box-shadow: 0 16px 48px rgba(129,140,248,.22), 0 4px 16px rgba(0,0,0,.5);
}

/* Occupied — served, awaiting payment */
.table-tile.tt-served {
  border-color: rgba(167,139,250,.4);
  background: linear-gradient(145deg, var(--surface), rgba(167,139,250,.06));
  animation: servedPulse 2.5s ease-in-out infinite;
}
.table-tile.tt-served:hover {
  border-color: rgba(167,139,250,.88);
  box-shadow: 0 16px 48px rgba(167,139,250,.25), 0 4px 16px rgba(0,0,0,.5);
}
@keyframes servedPulse {
  0%,100% { box-shadow: 0 0 0 0 rgba(167,139,250,0), 0 4px 16px rgba(0,0,0,.3); }
  50%     { box-shadow: 0 0 0 10px rgba(167,139,250,.1), 0 4px 16px rgba(0,0,0,.3); }
}

/* Occupied — other waiter */
.table-tile.tt-occupied {
  border-color: rgba(239,68,68,.3);
  background: linear-gradient(145deg, var(--surface), rgba(239,68,68,.03));
}
.table-tile.tt-occupied:hover {
  border-color: rgba(239,68,68,.6);
  box-shadow: 0 12px 40px rgba(239,68,68,.1);
}

/* Reserved with active order */
.table-tile.tt-reserved-occ {
  border-color: rgba(249,115,22,.3);
  background: linear-gradient(145deg, var(--surface), rgba(249,115,22,.04));
}
.table-tile.tt-reserved-occ:hover {
  border-color: rgba(249,115,22,.6);
}

/* Table tile content */
.tt-top-tag {
  position: absolute;
  top: 7px; right: 7px;
  font-size: 9px;
  background: var(--surface3);
  border: 1px solid var(--border2);
  color: var(--muted);
  padding: 2px 7px;
  border-radius: 8px;
  font-weight: 600;
}
.tt-icon { font-size: 26px; margin-bottom: 8px; line-height: 1; }
.tt-num  { font-size: 14px; font-weight: 700; margin-bottom: 2px; color: var(--text); }
.tt-cap  { font-size: 10px; color: var(--muted); margin-bottom: 8px; }
.tt-badge {
  font-size: 9.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  padding: 3px 9px;
  border-radius: 20px;
  display: inline-block;
}
.tt-badge.b-free    { background: var(--green-d); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
.tt-badge.b-mine    { background: rgba(129,140,248,.12); color: var(--accent); border: 1px solid rgba(129,140,248,.25); }
.tt-badge.b-served  { background: rgba(167,139,250,.12); color: #c4b5fd; border: 1px solid rgba(167,139,250,.25); }
.tt-badge.b-occ     { background: var(--red-d); color: var(--red); border: 1px solid rgba(239,68,68,.2); }
.tt-badge.b-rsv     { background: var(--gold-d); color: var(--gold); border: 1px solid rgba(245,158,11,.2); }
.tt-badge.b-rsv-occ { background: var(--orange-d); color: var(--orange); border: 1px solid rgba(249,115,22,.2); }
.tt-detail { font-size: 10px; color: var(--muted); margin-top: 6px; }

/* Elapsed time timer on tiles */
.tt-timer { font-size: 9.5px; color: var(--muted); margin-top: 4px; font-weight: 500; }
.tt-timer.t-warn { color: var(--orange); font-weight: 700; }
.tt-timer.t-late { color: var(--red); font-weight: 700; animation: livePulse 1.5s infinite; }

/* ══════════════════════════════════════════
   RIGHT PANEL TABS
══════════════════════════════════════════ */
.rp-tabs {
  display: flex;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
  background: var(--surface);
}
.rp-tab {
  flex: 1;
  padding: 11px 10px;
  border: none;
  background: none;
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: .15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.rp-tab:hover { color: var(--text); }
.rp-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.rp-pane { display: none; flex: 1; overflow-y: auto; padding: 14px; }
.rp-pane.active { display: block; }

/* ══════════════════════════════════════════
   ACTIVE ORDER ROWS (right sidebar)
══════════════════════════════════════════ */
.ao-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 13px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 12px;
  margin-bottom: 8px;
  cursor: default;
  transition: .15s;
}
.ao-row:hover { border-color: var(--border2); background: var(--surface3); }
.ao-info { flex: 1; min-width: 0; }
.ao-num  { font-size: 13px; font-weight: 700; color: var(--text); }
.ao-sub  { font-size: 10.5px; color: var(--muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ao-right { text-align: right; flex-shrink: 0; }
.ao-amt  { font-size: 12.5px; font-weight: 700; color: var(--gold); font-family: 'Playfair Display', serif; }
.ao-actions { display: flex; align-items: center; gap: 5px; margin-top: 5px; justify-content: flex-end; }

/* ══════════════════════════════════════════
   SLIDE-IN DETAIL PANEL
══════════════════════════════════════════ */
.dp-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 299;
  background: rgba(0,0,0,.6);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
}
.dp-overlay.open { display: block; }

.detail-panel {
  position: fixed;
  right: 0; top: 0; bottom: 0;
  width: 390px;
  z-index: 300;
  background: var(--surface);
  border-left: 1px solid var(--border2);
  box-shadow: -24px 0 80px rgba(0,0,0,.6);
  display: flex;
  flex-direction: column;
  transform: translateX(100%);
  transition: transform .28s cubic-bezier(.16,1,.3,1);
}
.detail-panel.open { transform: translateX(0); }

.dp-hd {
  padding: 18px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}
.dp-icon {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  border: 1px solid;
}
.dp-title { font-size: 15px; font-weight: 700; color: var(--text); }
.dp-sub   { font-size: 12px; color: var(--muted); margin-top: 1px; }
.dp-close {
  margin-left: auto;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--muted);
  font-size: 16px;
  padding: 5px 8px;
  border-radius: 8px;
  transition: .15s;
}
.dp-close:hover { color: var(--red); background: var(--red-d); }
.dp-body { flex: 1; overflow-y: auto; padding: 20px; }
.dp-ft   {
  padding: 14px 20px;
  border-top: 1px solid var(--border);
  display: flex;
  gap: 8px;
  flex-shrink: 0;
  background: var(--surface);
}

.dp-section {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 14px 16px;
  margin-bottom: 12px;
}
.dp-section-lbl {
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--muted);
  margin-bottom: 10px;
}

/* ── Guest count selector ── */
.guest-sel {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--surface3);
  border: 1px solid var(--border2);
  border-radius: 10px;
  padding: 8px 12px;
}
.guest-sel span { flex: 1; text-align: center; font-size: 18px; font-weight: 700; }
.guest-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid var(--border2);
  background: rgba(255,255,255,.06);
  color: var(--text);
  font-size: 18px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: .15s;
}
.guest-btn:hover { background: rgba(255,255,255,.12); border-color: var(--accent); }

/* ══════════════════════════════════════════
   BILL PANEL (slide-in, cashier-style)
══════════════════════════════════════════ */
.bill-panel {
  position: fixed;
  right: 0; top: 0; bottom: 0;
  width: 430px;
  background: var(--surface);
  border-left: 1px solid var(--border2);
  box-shadow: -24px 0 80px rgba(0,0,0,.6);
  display: flex;
  flex-direction: column;
  z-index: 600;
  transform: translateX(100%);
  transition: transform .28s cubic-bezier(.16,1,.3,1);
}
.bill-panel.open { transform: none; }
.bp-overlay {
  position: fixed;
  inset: 0;
  z-index: 599;
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

/* Receipt */
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
  background: rgba(129,140,248,.1);
  color: var(--accent);
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

/* ═══════════════════════════════════════════
   WAITER DASHBOARD RESPONSIVE
═══════════════════════════════════════════ */

/* ── 1100px: tighten the right panel ── */
@media (max-width: 1100px) {
  .w-right { max-width: 340px; }
  .w-stats { grid-template-columns: repeat(2, 1fr); }
  .w-stat  { padding: 11px 14px; }
  .w-stat-val { font-size: 20px; }
}

/* ── 900px: stack left/right panels ── */
@media (max-width: 900px) {
  .w-layout {
    flex-direction: column;
    height: auto;
    overflow-y: auto;
    overflow-x: hidden;
  }
  .w-left {
    border-right: none;
    border-bottom: 1px solid var(--border);
    overflow: visible;
    height: auto;
    min-height: 0;
  }
  .w-right {
    max-width: 100%;
    min-width: 0;
    width: 100%;
    height: auto;
    min-height: 320px;
    max-height: none;
  }
  .w-stats { grid-template-columns: repeat(4, 1fr); }
  .floor-grid {
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
  }
  /* detail + bill panels go full width on smaller screens */
  .detail-panel, .bill-panel { width: 100%; }
}

/* ── 640px: further condense ── */
@media (max-width: 640px) {
  .w-stats { grid-template-columns: repeat(2, 1fr); }
  .w-stat  { padding: 10px 12px; }
  .w-stat-val { font-size: 18px; }
  .floor-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 8px; }
  .table-tile { padding: 12px 8px; }
  .tt-num  { font-size: 18px; }
  .tt-cap  { font-size: 10px; }
  .rp-tabs { gap: 4px; padding: 8px 10px; }
  .rp-tab  { padding: 6px 10px; font-size: 11.5px; }
  .ao-row  { padding: 10px 11px; gap: 8px; }
  .ao-num  { font-size: 12.5px; }
  .ao-sub  { font-size: 10.5px; }
}

/* ── 480px: phones ── */
@media (max-width: 480px) {
  .w-stats { grid-template-columns: repeat(2, 1fr); }
  .floor-grid { grid-template-columns: repeat(auto-fill, minmax(88px, 1fr)); gap: 7px; }
  .table-tile { padding: 10px 6px; }
  .tt-num { font-size: 16px; }
  .bill-panel, .detail-panel { width: 100%; border-radius: 16px 16px 0 0; bottom: 0; top: auto; max-height: 88vh; }
}

/* ── Sound toggle (same style as kitchen) ── */
.sound-btn {
  display: flex; align-items: center; gap: 5px;
  padding: 5px 11px; border-radius: 9px;
  border: 1px solid var(--border2); background: var(--surface2);
  color: var(--sub); font-size: 12px; font-family: inherit;
  cursor: pointer; transition: .15s; font-weight: 600;
}
.sound-btn:hover { border-color: rgba(255,255,255,.15); color: var(--text); background: var(--surface3); }
.sound-btn.on  { border-color: rgba(129,140,248,.35); color: #818cf8; background: rgba(129,140,248,.08); }
.sound-btn.off { border-color: var(--border); color: var(--muted); }
</style>
</head>
<body>

<!-- ══ TOPBAR ══ -->
<div class="topbar">
    <a href="dashboard.php" class="tb-brand">
        <div class="tb-icon"><i class="fa-solid fa-utensils"></i></div>
        <span class="tb-title">Waiter</span>
    </a>
    <a href="../admin/reservations.php" class="tb-btn" style="margin-left:8px;">
        <i class="fa-solid fa-calendar-days"></i> Reservations
    </a>
    <div class="tb-right">
        <div class="live-dot"></div>
        <button id="wSoundBtn" class="sound-btn on" onclick="toggleWaiterSound()" title="Toggle sound alerts">
            <i class="fa-solid fa-volume-high"></i> <span class="sb-lbl">Sound</span>
        </button>
        <button class="tb-btn" onclick="location.reload()" title="Refresh dashboard">
            <i class="fa-solid fa-rotate-right"></i> Refresh
        </button>
        <span class="tb-clock" id="clock"></span>
        <div class="tb-user">
            <div class="tb-avatar"><?php echo strtoupper(substr($uname, 0, 1)); ?></div>
            <div>
                <div class="tb-name"><?php echo htmlspecialchars($uname); ?></div>
                <div class="tb-role">Waiter</div>
            </div>
        </div>
        <a href="../auth/logout.php" class="tb-btn danger">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>

<!-- ══ MAIN LAYOUT ══ -->
<div class="w-layout">

    <!-- ══ LEFT: Floor plan ══ -->
    <div class="w-left">

        <!-- Stats bar -->
        <div class="w-stats">
            <div class="w-stat">
                <div class="w-stat-top" style="background:linear-gradient(90deg,var(--blue),transparent);"></div>
                <div class="w-stat-lbl">Tables Assigned</div>
                <div class="w-stat-val" style="color:var(--blue);"><?php echo $tables_assigned; ?></div>
                <div class="w-stat-sub">My active tables</div>
            </div>
            <div class="w-stat">
                <div class="w-stat-top" style="background:linear-gradient(90deg,var(--orange),transparent);"></div>
                <div class="w-stat-lbl">Orders Today</div>
                <div class="w-stat-val" style="color:var(--orange);"><?php echo $orders_today; ?></div>
                <div class="w-stat-sub">All placed today</div>
            </div>
            <div class="w-stat">
                <div class="w-stat-top" style="background:linear-gradient(90deg,var(--purple),transparent);"></div>
                <div class="w-stat-lbl">Pending Items</div>
                <div class="w-stat-val" style="color:var(--purple);"><?php echo $pending_items; ?></div>
                <div class="w-stat-sub">Awaiting kitchen</div>
            </div>
            <div class="w-stat" style="grid-column:span 1;">
                <div class="w-stat-top" style="background:linear-gradient(90deg,var(--gold),transparent);"></div>
                <div class="w-stat-lbl">Total Revenue</div>
                <div class="w-stat-val" style="color:var(--gold);font-size:17px;">DKK&nbsp;<?php echo number_format($my_revenue, 0); ?></div>
                <div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap;">
                    <span style="font-size:10px;color:var(--green);font-weight:700;">
                        <i class="fa-solid fa-money-bill-wave" style="font-size:9px;"></i> <?php echo number_format($my_rev_cash,0); ?>
                    </span>
                    <span style="font-size:10px;color:var(--blue);font-weight:700;">
                        <i class="fa-solid fa-credit-card" style="font-size:9px;"></i> <?php echo number_format($my_rev_card,0); ?>
                    </span>
                    <span style="font-size:10px;color:var(--purple);font-weight:700;">
                        <i class="fa-solid fa-qrcode" style="font-size:9px;"></i> <?php echo number_format($my_rev_qr,0); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Panel header -->
        <div class="w-panel-hd">
            <h3><i class="fa-solid fa-store"></i> Floor Plan</h3>
            <div class="legend">
                <div class="leg-item"><div class="leg-dot" style="background:var(--green);"></div>Available</div>
                <div class="leg-item"><div class="leg-dot" style="background:var(--gold);"></div>Reserved</div>
                <div class="leg-item"><div class="leg-dot" style="background:var(--blue);"></div>My Order</div>
                <div class="leg-item"><div class="leg-dot" style="background:var(--red);"></div>Occupied</div>
            </div>
        </div>

        <!-- Floor grid -->
        <div class="w-scroll">
            <div class="floor-grid">
                <?php if (empty($tables)): ?>
                <div style="grid-column:1/-1;color:var(--muted);padding:32px;text-align:center;font-size:13px;">
                    <i class="fa-solid fa-chair" style="font-size:28px;display:block;margin-bottom:10px;opacity:.25;"></i>
                    No tables configured.
                </div>
                <?php else: foreach ($tables as $t):
                    $has_order   = !empty($t['order_id']);
                    $has_booking = !empty($t['booking_id']);
                    $is_mine     = $has_order && (int)$t['waiter_id'] === $uid;

                    /* Determine tile CSS class and badge */
                    if ($has_order && $is_mine) {
                        if ($t['order_status'] === 'served') {
                            $tile_cls = 'tt-served'; $badge_cls = 'b-served'; $badge_txt = 'Served';
                            $icon = '🟣';
                        } else {
                            $tile_cls = 'tt-mine';   $badge_cls = 'b-mine'; $badge_txt = 'My Order';
                            $icon = '🔵';
                        }
                    } elseif ($has_order) {
                        $tile_cls = 'tt-occupied'; $badge_cls = 'b-occ'; $badge_txt = 'Occupied';
                        $icon = '🔴';
                        if ($has_booking) { $tile_cls = 'tt-reserved-occ'; $badge_cls = 'b-rsv-occ'; $badge_txt = 'Occupied'; }
                    } elseif ($has_booking) {
                        if ($t['booking_status'] === 'seated') {
                            /* Walk-in guest already seated, order not placed yet */
                            $tile_cls = 'tt-occupied'; $badge_cls = 'b-occ'; $badge_txt = 'Walk-in';
                            $icon = '🟠';
                        } else {
                            $tile_cls = 'tt-reserved-free'; $badge_cls = 'b-rsv'; $badge_txt = 'Reserved';
                            $icon = '🟡';
                        }
                    } elseif ($t['status'] === 'occupied') {
                        /* Occupied but no booking record (edge case) */
                        $tile_cls = 'tt-occupied'; $badge_cls = 'b-occ'; $badge_txt = 'Occupied';
                        $icon = '🔴';
                    } else {
                        $tile_cls = 'tt-free'; $badge_cls = 'b-free'; $badge_txt = 'Available';
                        $icon = '🟢';
                    }

                    $tile_data = json_encode([
                        'id'             => $t['id'],
                        'table_number'   => $t['table_number'],
                        'capacity'       => $t['capacity'],
                        'status'         => $t['status'],
                        'order_id'       => $t['order_id'],
                        'order_number'   => $t['order_number'],
                        'order_status'   => $t['order_status'],
                        'total_amount'   => $t['total_amount'],
                        'order_created'  => $t['order_created'],
                        'item_count'     => $t['item_count'],
                        'waiter_id'      => $t['waiter_id'],
                        'table_status'   => $t['status'],
                        'booking_id'     => $t['booking_id'],
                        'guest_name'     => $t['guest_name'],
                        'booking_guests' => $t['booking_guests'],
                        'booking_time'   => $t['booking_time'],
                        'booking_status' => $t['booking_status'],
                    ]);
                ?>
                <div class="table-tile <?php echo $tile_cls; ?>"
                     id="tt-<?php echo (int)$t['id']; ?>"
                     onclick='openPanel(<?php echo htmlspecialchars($tile_data, ENT_QUOTES); ?>)'>
                    <?php if ($has_order): ?>
                    <div class="tt-top-tag"><?php echo htmlspecialchars($t['order_number'] ?? ''); ?></div>
                    <?php elseif ($has_booking): ?>
                    <div class="tt-top-tag" style="color:var(--gold);border-color:rgba(201,162,39,.3);">
                        <i class="fa-solid fa-bookmark" style="font-size:8px;"></i> <?php echo htmlspecialchars($t['booking_time'] ?? ''); ?>
                    </div>
                    <?php endif; ?>
                    <div class="tt-icon"><?php echo $icon; ?></div>
                    <div class="tt-num">Table <?php echo (int)$t['table_number']; ?></div>
                    <div class="tt-cap"><i class="fa-solid fa-users" style="font-size:8px;"></i> <?php echo (int)$t['capacity']; ?> seats</div>
                    <span class="tt-badge <?php echo $badge_cls; ?>"><?php echo $badge_txt; ?></span>
                    <?php if ($has_booking && !$has_order): ?>
                    <div class="tt-detail"><?php echo htmlspecialchars(substr($t['guest_name'] ?? '', 0, 18)); ?></div>
                    <?php elseif ($has_order): ?>
                    <div class="tt-detail"><?php echo (int)$t['item_count']; ?> items</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

    </div><!-- /w-left -->

    <!-- ══ RIGHT: Tabbed panel ══ -->
    <div class="w-right">

        <!-- Tab bar -->
        <div class="rp-tabs">
            <button class="rp-tab active" id="rpt-orders" onclick="rpTab('orders')">
                <i class="fa-solid fa-receipt"></i> My Orders
                <?php $served_count = count(array_filter($active_orders, fn($o) => $o['status'] === 'served')); ?>
                <?php if ($served_count > 0): ?>
                <span style="background:#a78bfa;color:#000;border-radius:9px;padding:1px 6px;font-size:10px;"><?php echo $served_count; ?></span>
                <?php endif; ?>
            </button>
            <button class="rp-tab" id="rpt-bookings" onclick="rpTab('bookings')">
                <i class="fa-solid fa-calendar-check"></i> Bookings
                <?php if (!empty($bookings_today)): ?>
                <span style="background:var(--blue);color:#000;border-radius:9px;padding:1px 6px;font-size:10px;"><?php echo count($bookings_today); ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- Orders pane -->
        <div class="rp-pane active" id="rpp-orders">
            <?php if (empty($active_orders)): ?>
            <div style="text-align:center;color:var(--muted);padding:40px 0;font-size:12.5px;">
                <i class="fa-regular fa-clipboard" style="font-size:30px;display:block;margin-bottom:10px;opacity:.2;"></i>
                No active orders right now.
            </div>
            <?php else: foreach ($active_orders as $o):
                $elapsed = $now_ts - strtotime($o['created_at']);
                $mins    = (int)floor($elapsed / 60);
                $age_lbl = $mins < 60 ? $mins.'m ago' : floor($mins/60).'h '.($mins%60).'m ago';
                $is_ready  = $o['status'] === 'ready';
                $is_served = $o['status'] === 'served';
            ?>
            <div class="ao-row" id="ao-<?php echo (int)$o['id']; ?>"
                 style="<?php if ($is_served): ?>border-color:rgba(167,139,250,.35);background:rgba(167,139,250,.04);<?php elseif ($is_ready): ?>border-color:rgba(34,197,94,.3);<?php endif; ?>">
                <div class="ao-info">
                    <div class="ao-num"><?php echo htmlspecialchars($o['order_number']); ?></div>
                    <div class="ao-sub">
                        T-<?php echo (int)($o['table_number'] ?? 0); ?>
                        &bull; <?php echo (int)$o['item_count']; ?> items
                        &bull; <?php echo $age_lbl; ?>
                    </div>
                </div>
                <div class="ao-right">
                    <div class="ao-amt">DKK&nbsp;<?php echo number_format((float)$o['total_amount'], 0); ?></div>
                    <div class="ao-actions">
                        <?php if ($is_served): ?>
                        <span class="tt-badge b-served" style="font-size:9px;">✓ Served</span>
                        <?php else: ?>
                        <span class="pill pill-<?php echo htmlspecialchars($o['status']); ?>"><?php echo htmlspecialchars($o['status']); ?></span>
                        <?php endif; ?>
                        <?php if (!$is_served): ?>
                        <a href="edit_order.php?order_id=<?php echo $o['id']; ?>"
                           class="btn btn-ghost btn-xs" title="Add Items">
                            <i class="fa-solid fa-plus"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($is_ready): ?>
                        <button class="btn btn-xs"
                                style="background:rgba(167,139,250,.15);color:#a78bfa;border:1px solid rgba(167,139,250,.3);"
                                onclick="markServed(<?php echo $o['id']; ?>, this)"
                                title="Mark as Served">
                            <i class="fa-solid fa-plate-wheat"></i> Served
                        </button>
                        <?php elseif ($is_served): ?>
                        <button class="btn btn-primary btn-xs"
                                onclick="fetchBill(<?php echo $o['id']; ?>)"
                                title="Pay Bill">
                            <i class="fa-solid fa-cash-register"></i> Pay
                        </button>
                        <?php else: ?>
                        <button class="btn btn-ghost btn-xs"
                                onclick="fetchBill(<?php echo $o['id']; ?>)" title="Preview Bill">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Bookings pane -->
        <div class="rp-pane" id="rpp-bookings">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                <a href="../admin/reservations.php" class="btn btn-ghost btn-xs" style="flex:1;justify-content:center;">
                    <i class="fa-solid fa-calendar-days"></i> Full Reservations
                </a>
                <button class="btn btn-primary btn-xs" style="flex:1;justify-content:center;"
                        onclick="document.getElementById('quickWiOverlay').style.display='flex';">
                    <i class="fa-solid fa-person-walking-arrow-right"></i> Walk-in
                </button>
            </div>
            <?php if (empty($bookings_today)): ?>
            <div style="text-align:center;color:var(--muted);padding:30px 0;font-size:12.5px;">
                <i class="fa-solid fa-calendar-xmark" style="font-size:28px;display:block;margin-bottom:10px;opacity:.2;"></i>
                No bookings today.
            </div>
            <?php else: ?>
            <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:10px;">
                <?php echo date('l, d M'); ?> — <?php echo count($bookings_today); ?> reservation<?php echo count($bookings_today)!=1?'s':''; ?>
            </div>
            <?php
            $bk_status_colors = [
                'confirmed' => ['#22c55e','rgba(34,197,94,.12)'],
                'pending'   => ['var(--gold)','rgba(201,162,39,.1)'],
                'seated'    => ['#a78bfa','rgba(167,139,250,.1)'],
            ];
            $now_time = date('H:i');
            foreach ($bookings_today as $bk):
                [$sc, $sbg] = $bk_status_colors[$bk['status']] ?? ['var(--muted)','rgba(255,255,255,.04)'];
                $is_soon = $bk['booking_time'] > $now_time && $bk['booking_time'] <= date('H:i', strtotime('+60 minutes'));
            ?>
            <div style="background:var(--surface2);border:1px solid var(--border);
                        border-radius:10px;margin-bottom:7px;overflow:hidden;
                        <?php if ($is_soon): ?>border-left:3px solid var(--orange);<?php endif; ?>">
                <div style="display:flex;align-items:center;gap:10px;padding:10px 11px;">
                    <div style="text-align:center;flex-shrink:0;min-width:42px;">
                        <div style="font-size:15px;font-weight:700;color:<?php echo $sc; ?>;">
                            <?php echo htmlspecialchars($bk['booking_time']); ?>
                        </div>
                        <?php if ($is_soon): ?>
                        <div style="font-size:9px;color:var(--orange);font-weight:700;">SOON</div>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:12.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo htmlspecialchars($bk['customer_name']); ?>
                        </div>
                        <div style="font-size:11px;color:var(--muted);margin-top:1px;">
                            <i class="fa-solid fa-users" style="font-size:9px;"></i> <?php echo (int)$bk['num_guests']; ?> guests
                            <?php if ($bk['table_number']): ?>
                            &bull; T-<?php echo htmlspecialchars($bk['table_number']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span style="flex-shrink:0;font-size:10px;font-weight:700;padding:2px 8px;
                                 border-radius:20px;text-transform:capitalize;
                                 background:<?php echo $sbg; ?>;color:<?php echo $sc; ?>;">
                        <?php echo htmlspecialchars($bk['status']); ?>
                    </span>
                </div>
                <?php if (in_array($bk['status'], ['confirmed','pending','seated']) && $bk['table_id']): ?>
                <div style="padding:0 11px 10px;display:flex;gap:6px;">
                    <a href="create_order.php?table_id=<?php echo (int)$bk['table_id']; ?>&reservation_id=<?php echo (int)$bk['id']; ?>&order_type=reservation"
                       class="btn btn-primary btn-xs" style="flex:1;justify-content:center;">
                        <i class="fa-solid fa-chair"></i> Seat &amp; Start Order
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div><!-- /w-right -->

</div><!-- /w-layout -->

<!-- ══ DETAIL PANEL (slide-in) ══ -->
<div class="dp-overlay" id="dpOverlay" onclick="closePanel()"></div>
<div class="detail-panel" id="detailPanel">
    <div class="dp-hd" id="dp-hd">
        <div class="dp-icon" id="dp-icon"
             style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.2);color:var(--blue);">
            <i class="fa-solid fa-chair"></i>
        </div>
        <div>
            <div class="dp-title" id="dp-title">Table</div>
            <div class="dp-sub"   id="dp-sub">—</div>
        </div>
        <button class="dp-close" onclick="closePanel()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="dp-body" id="dp-body"></div>
    <div class="dp-ft"   id="dp-ft"></div>
</div>

<!-- ══ BILL PANEL (cashier-style) ══ -->
<div class="bp-overlay" id="bpOverlay" onclick="closeBillPanel()"></div>
<div class="bill-panel" id="billPanel">
    <div class="bp-hd">
        <div style="width:38px;height:38px;border-radius:9px;background:rgba(201,162,39,.12);border:1px solid rgba(201,162,39,.25);
                    display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:15px;flex-shrink:0;">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div style="min-width:0;">
            <div style="font-size:15px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="bp-order-num">Bill</div>
            <div style="font-size:12px;color:var(--muted);" id="bp-table">—</div>
        </div>
        <button onclick="closeBillPanel()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--muted);font-size:18px;padding:4px 6px;border-radius:6px;transition:.15s;flex-shrink:0;"
                onmouseover="this.style.color='var(--red)'" onmouseout="this.style.color='var(--muted)'">
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
            <div class="pay-btn active" id="wpm-cash" onclick="wSelectMethod('cash')">
                <i class="fa-solid fa-money-bill-wave"></i><span>Cash</span>
            </div>
            <div class="pay-btn" id="wpm-card" onclick="wSelectMethod('card')">
                <i class="fa-solid fa-credit-card"></i><span>Card</span>
            </div>
            <div class="pay-btn" id="wpm-qr" onclick="wSelectMethod('qr')">
                <i class="fa-solid fa-qrcode"></i><span>QR</span>
            </div>
        </div>

        <div class="change-calc" id="wChangeCalc">
            <div class="cc-row">
                <div class="cc-lbl">Total Due</div>
                <div class="cc-val" id="wcc-total">DKK 0.00</div>
            </div>
            <div class="cc-row">
                <div class="cc-lbl">Tendered</div>
                <input type="number" id="wcc-tendered" placeholder="Amount given…" min="0" step="1"
                       style="flex:1;padding:8px 12px;background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:inherit;font-size:13px;outline:none;transition:.2s;"
                       onfocus="this.style.borderColor='rgba(96,165,250,.4)'" onblur="this.style.borderColor='var(--border)'"
                       oninput="wCalcChange()">
            </div>
            <div class="cc-row">
                <div class="cc-lbl">Change</div>
                <div class="cc-val positive" id="wcc-change">DKK —</div>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
            <label style="font-size:12px;color:var(--muted);font-weight:600;flex-shrink:0;white-space:nowrap;">Discount (DKK)</label>
            <input type="number" id="wDiscount" value="0" min="0" step="1"
                   style="flex:1;padding:7px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:inherit;font-size:13px;outline:none;transition:.2s;"
                   onfocus="this.style.borderColor='rgba(96,165,250,.4)'" onblur="this.style.borderColor='var(--border)'"
                   oninput="wApplyDiscount()">
        </div>

        <div style="display:flex;gap:8px;margin-bottom:8px;">
            <button class="btn btn-ghost" style="flex:1;justify-content:center;" onclick="wPrintPreview()">
                <i class="fa-solid fa-print"></i> Print Preview
            </button>
        </div>
        <button class="btn btn-primary" id="wPayBtn" style="width:100%;justify-content:center;padding:12px;" onclick="wProcessPayment()">
            <i class="fa-solid fa-check-circle"></i> Complete Payment
        </button>
    </div>
</div>

<!-- ══ QUICK WALK-IN OVERLAY ══ -->
<div id="quickWiOverlay" style="display:none;position:fixed;inset:0;z-index:700;
     background:rgba(0,0,0,.7);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px;"
     onclick="if(event.target===this)this.style.display='none'">
<div style="background:var(--surface);border:1px solid var(--border2);border-radius:16px;
            width:100%;max-width:360px;overflow:hidden;
            animation:billIn .22s cubic-bezier(.16,1,.3,1);">
    <div class="bp-hd">
        <div style="font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-person-walking-arrow-right" style="color:var(--accent);"></i> Quick Walk-In
        </div>
        <button onclick="document.getElementById('quickWiOverlay').style.display='none'"
                style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--muted);font-size:18px;padding:4px 6px;border-radius:6px;">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <div class="bill-body" style="display:flex;flex-direction:column;gap:12px;">
        <div>
            <label style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Guest Name *</label>
            <input type="text" id="qwi-name" placeholder="Enter guest name" autocomplete="off"
                   style="width:100%;padding:9px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;outline:none;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div>
                <label style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Guests</label>
                <input type="number" id="qwi-guests" value="2" min="1" max="20"
                       style="width:100%;padding:9px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;outline:none;">
            </div>
            <div>
                <label style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Time</label>
                <input type="time" id="qwi-time" value="<?php echo date('H:00'); ?>"
                       style="width:100%;padding:9px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;outline:none;">
            </div>
        </div>
        <div id="qwi-err" style="color:var(--red);font-size:12px;display:none;"></div>
    </div>
    <div class="bp-ft" style="display:flex;gap:8px;">
        <button onclick="document.getElementById('quickWiOverlay').style.display='none'" class="btn btn-ghost btn-sm">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button onclick="submitQuickWalkIn()" class="btn btn-primary btn-sm" id="qwi-btn" style="margin-left:auto;">
            <i class="fa-solid fa-check"></i> Seat Now
        </button>
    </div>
</div>
</div>

<style>
@keyframes bellShake { 0%,100%{transform:rotate(0deg)} 25%{transform:rotate(-15deg)} 75%{transform:rotate(15deg)} }
#bell-icon.ringing { animation: bellShake .4s ease-in-out 3; }
</style>
<!-- Kitchen bell notification banner -->
<div id="bell-banner" style="display:none;position:fixed;top:calc(var(--topbar-h) + 10px);left:50%;transform:translateX(-50%);z-index:500;
     background:linear-gradient(135deg,rgba(34,197,94,.15),rgba(34,197,94,.08));
     border:1px solid rgba(34,197,94,.4);border-radius:14px;padding:12px 20px;
     backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
     align-items:center;gap:12px;min-width:280px;max-width:420px;
     box-shadow:0 4px 24px rgba(34,197,94,.2);">
  <div style="font-size:22px;" id="bell-icon">🔔</div>
  <div style="flex:1;">
    <div style="font-size:13px;font-weight:700;color:var(--green);">Order Ready!</div>
    <div style="font-size:12px;color:var(--muted);" id="bell-msg">Kitchen has notified you</div>
  </div>
  <button onclick="dismissBell()" style="background:none;border:none;color:var(--muted);font-size:14px;cursor:pointer;padding:4px 8px;">
    <i class="fa-solid fa-xmark"></i>
  </button>
</div>
<div id="toast-wrap"></div>

<script>
/* ══ Clock ══ */
(function tick() {
    const n = new Date(), p = v => String(v).padStart(2, '0');
    document.getElementById('clock').textContent = p(n.getHours()) + ':' + p(n.getMinutes()) + ':' + p(n.getSeconds());
    setTimeout(tick, 1000);
})();

/* ══ Tables data from PHP ══ */
const TABLES = <?php echo json_encode($tables); ?>;
const MY_UID = <?php echo $uid; ?>;

/* ══ Right panel tabs ══ */
function rpTab(name) {
    document.querySelectorAll('.rp-tab').forEach(t => t.classList.toggle('active', t.id === 'rpt-'+name));
    document.querySelectorAll('.rp-pane').forEach(p => p.classList.toggle('active', p.id === 'rpp-'+name));
}

/* ══ Mark as Served ══ */
async function markServed(oid, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const r = await fetch('dashboard.php?action=mark_served', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'order_id=' + oid
        });
        const d = await r.json();
        if (d.success) {
            toast('Order marked as served!', 'success');
            /* Remove the clicked button directly — it may not have .js-serve-btn
               if it was rendered server-side rather than injected by updateAoRow */
            btn.remove();
            /* Sync the rest of the row (pill, pay button upgrade, etc.) */
            knownStatuses[oid] = 'served';
            updateAoRow(oid, 'served', '', '');
        } else {
            btn.disabled = false; btn.innerHTML = orig;
            toast(d.error || 'Could not mark as served', 'error');
        }
    } catch(e) {
        btn.disabled = false; btn.innerHTML = orig;
        toast('Network error', 'error');
    }
}

/* ══ Current table state for walk-in panel ══ */
let currentTable = null;

/* ══ Open panel ══ */
function openPanel(t) {
    if (typeof t === 'string') t = JSON.parse(t);
    currentTable = t;

    const hasOrder   = !!t.order_id;
    const hasBooking = !!t.booking_id;
    const isMine     = hasOrder && parseInt(t.waiter_id) === MY_UID;

    /* Header */
    document.getElementById('dp-title').textContent = 'Table ' + t.table_number;
    document.getElementById('dp-sub').textContent   = t.capacity + ' seats';

    let body = '', ft = '';
    let iconColor = 'rgba(96,165,250,.1)', iconBorder = 'rgba(96,165,250,.2)', iconTxt = 'var(--blue)';

    if (!hasOrder && !hasBooking) {
        /* ── Available, no booking ── */
        iconColor = 'rgba(34,197,94,.1)'; iconBorder = 'rgba(34,197,94,.2)'; iconTxt = 'var(--green)';
        body = `
          <div style="text-align:center;margin-bottom:18px;">
            <div style="font-size:42px;margin-bottom:8px;">🟢</div>
            <div style="font-size:14px;font-weight:600;margin-bottom:4px;">Table Available</div>
            <div style="font-size:12px;color:var(--muted);">Ready to seat guests.</div>
          </div>`;
        ft = `<a href="create_order.php?table_id=${t.id}&order_type=walk_in"
                class="btn btn-primary" style="flex:1;justify-content:center;">
                <i class="fa-solid fa-utensils"></i> Take Order
              </a>
              <button class="btn btn-ghost" style="white-space:nowrap;"
                      onclick="document.getElementById('quickWiOverlay').dataset.tableId='${t.id}'; closePanel(); rpTab('bookings'); document.getElementById('quickWiOverlay').style.display='flex';">
                <i class="fa-solid fa-person-walking-arrow-right"></i> Walk-in
              </button>`;

    } else if (!hasOrder && hasBooking) {

        if (t.booking_status === 'seated') {
            /* ── Walk-in guest seated, order not placed yet ── */
            iconColor = 'rgba(249,115,22,.1)'; iconBorder = 'rgba(249,115,22,.25)'; iconTxt = 'var(--orange)';
            body = `
              <div style="text-align:center;margin-bottom:18px;">
                <div style="font-size:42px;margin-bottom:8px;">🟠</div>
                <div style="font-size:14px;font-weight:600;margin-bottom:4px;">Walk-in Seated</div>
                <div style="font-size:12px;color:var(--muted);">${escHtml(t.guest_name || 'Guest')} is seated — take their order now.</div>
              </div>
              <div class="dp-section" style="border-color:rgba(249,115,22,.25);">
                <div class="dp-section-lbl">Guest Info</div>
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:7px;">
                  <span style="color:var(--muted);">Name</span>
                  <strong>${escHtml(t.guest_name || '—')}</strong>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px;">
                  <span style="color:var(--muted);">Party Size</span>
                  <strong>${t.booking_guests || '—'} guests</strong>
                </div>
              </div>`;
            ft = `<a href="create_order.php?table_id=${t.id}&order_type=walk_in"
                    class="btn btn-primary" style="flex:1;justify-content:center;">
                    <i class="fa-solid fa-utensils"></i> Take Order
                  </a>
                  <button class="btn btn-ghost" onclick="closePanel()">Close</button>`;

        } else {
            /* ── Reservation waiting to be seated ── */
            iconColor = 'rgba(201,162,39,.1)'; iconBorder = 'rgba(201,162,39,.25)'; iconTxt = 'var(--gold)';
            body = `
              <div style="text-align:center;margin-bottom:18px;">
                <div style="font-size:42px;margin-bottom:8px;">🟡</div>
                <div style="font-size:14px;font-weight:600;margin-bottom:4px;">Reservation Waiting</div>
              </div>
              <div class="dp-section">
                <div class="dp-section-lbl">Reservation Details</div>
                <div style="display:flex;flex-direction:column;gap:7px;">
                  <div style="display:flex;justify-content:space-between;font-size:13px;">
                    <span style="color:var(--muted);">Guest</span>
                    <strong>${escHtml(t.guest_name || '—')}</strong>
                  </div>
                  <div style="display:flex;justify-content:space-between;font-size:13px;">
                    <span style="color:var(--muted);">Time</span>
                    <strong>${escHtml(t.booking_time || '—')}</strong>
                  </div>
                  <div style="display:flex;justify-content:space-between;font-size:13px;">
                    <span style="color:var(--muted);">Party Size</span>
                    <strong>${t.booking_guests || '—'} guests</strong>
                  </div>
                  <div style="display:flex;justify-content:space-between;font-size:13px;">
                    <span style="color:var(--muted);">Status</span>
                    <span class="pill pill-${escHtml(t.booking_status || '')}">${escHtml(t.booking_status || '')}</span>
                  </div>
                </div>
              </div>`;
            ft = `<a href="create_order.php?table_id=${t.id}&reservation_id=${t.booking_id}&order_type=reservation"
                    class="btn btn-primary" style="flex:1;justify-content:center;">
                    <i class="fa-solid fa-chair"></i> Seat & Start Order
                  </a>
                  <button class="btn btn-ghost" onclick="closePanel()">Close</button>`;
        }

    } else if (hasOrder && isMine) {
        /* ── Occupied — my order ── */
        const isServed = t.order_status === 'served';
        const isReady  = t.order_status === 'ready';
        if (isServed) {
            iconColor = 'rgba(167,139,250,.1)'; iconBorder = 'rgba(167,139,250,.25)'; iconTxt = '#a78bfa';
        } else {
            iconColor = 'rgba(96,165,250,.1)'; iconBorder = 'rgba(96,165,250,.2)'; iconTxt = 'var(--blue)';
        }
        const statusColors = {pending:'var(--orange)', preparing:'#a78bfa', ready:'var(--green)', served:'#a78bfa'};
        const sc = statusColors[t.order_status] || 'var(--muted)';

        const servedBanner = isServed ? `
          <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;margin-bottom:12px;
                      background:rgba(167,139,250,.08);border:1px solid rgba(167,139,250,.25);border-radius:9px;
                      font-size:12.5px;color:#a78bfa;">
            <i class="fa-solid fa-plate-wheat" style="font-size:15px;"></i>
            <span><strong>Food has been served.</strong> Ready for payment.</span>
          </div>` : (isReady ? `
          <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;margin-bottom:12px;
                      background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.2);border-radius:9px;
                      font-size:12.5px;color:var(--green);">
            <i class="fa-solid fa-bell" style="font-size:15px;"></i>
            <span><strong>Order ready!</strong> Collect from kitchen and mark as served.</span>
          </div>` : '');

        body = `${servedBanner}
          <div class="dp-section" style="border-color:${isServed?'rgba(167,139,250,.25)':'rgba(96,165,250,.2)'};">
            <div class="dp-section-lbl">Current Order</div>
            <div style="font-size:18px;font-weight:700;margin-bottom:8px;">${escHtml(t.order_number || '#'+t.order_id)}</div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
              <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:${sc}22;color:${sc};text-transform:capitalize;">${escHtml(t.order_status || '')}</span>
              <span style="font-size:12px;color:var(--gold);font-weight:600;">DKK ${Number(t.total_amount||0).toLocaleString()}</span>
              <span style="font-size:11.5px;color:var(--muted);">${t.item_count||0} items</span>
            </div>
            ${t.order_created ? `<div style="font-size:10.5px;color:var(--muted);margin-top:6px;">Started: ${escHtml(t.order_created)}</div>` : ''}
          </div>
          ${hasBooking ? `
          <div class="dp-section" style="border-color:rgba(201,162,39,.2);">
            <div class="dp-section-lbl" style="color:var(--gold);">Reservation</div>
            <div style="font-size:13px;">${escHtml(t.guest_name||'—')} &bull; ${escHtml(t.booking_time||'')} &bull; ${t.booking_guests||'?'} guests</div>
          </div>` : ''}`;

        if (isServed) {
            ft = `<button class="btn btn-primary" style="flex:1;justify-content:center;" onclick="fetchBill(${t.order_id})">
                    <i class="fa-solid fa-cash-register"></i> Pay Bill
                  </button>
                  <button class="btn btn-ghost" onclick="closePanel()">Close</button>`;
        } else if (isReady) {
            ft = `<button class="btn btn-primary" style="flex:1;justify-content:center;background:#a78bfa;border-color:#a78bfa;color:#000;"
                          id="dpServeBtn" onclick="markServed(${t.order_id}, document.getElementById('dpServeBtn'))">
                    <i class="fa-solid fa-plate-wheat"></i> Mark as Served
                  </button>
                  <button class="btn btn-ghost" onclick="fetchBill(${t.order_id})">
                    <i class="fa-solid fa-eye"></i> Bill
                  </button>`;
        } else {
            ft = `<a href="edit_order.php?order_id=${t.order_id}" class="btn btn-primary" style="flex:1;justify-content:center;">
                    <i class="fa-solid fa-plus"></i> Add Items
                  </a>
                  <button class="btn btn-ghost" onclick="fetchBill(${t.order_id})">
                    <i class="fa-solid fa-eye"></i> Bill
                  </button>`;
        }

    } else if (hasOrder && !isMine) {
        /* ── Occupied — other waiter ── */
        iconColor = 'rgba(239,68,68,.1)'; iconBorder = 'rgba(239,68,68,.2)'; iconTxt = 'var(--red)';
        const statusColors2 = {pending:'var(--orange)', preparing:'#a78bfa', ready:'var(--green)'};
        const sc2 = statusColors2[t.order_status] || 'var(--muted)';
        body = `
          <div class="dp-section" style="border-color:rgba(239,68,68,.18);">
            <div class="dp-section-lbl">Current Order (View Only)</div>
            <div style="font-size:17px;font-weight:700;margin-bottom:8px;">${escHtml(t.order_number || '#'+t.order_id)}</div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
              <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:${sc2}18;color:${sc2};text-transform:capitalize;">${escHtml(t.order_status||'')}</span>
              <span style="font-size:12px;color:var(--gold);font-weight:600;">DKK ${Number(t.total_amount||0).toLocaleString()}</span>
              <span style="font-size:11.5px;color:var(--muted);">${t.item_count||0} items</span>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.18);border-radius:9px;font-size:12px;color:var(--red);">
            <i class="fa-solid fa-lock" style="font-size:13px;"></i>
            This order belongs to another waiter. View only.
          </div>`;
        ft = `<button class="btn btn-ghost" style="flex:1;" onclick="closePanel()">
                <i class="fa-solid fa-xmark"></i> Close
              </button>`;

    } else if (!hasOrder && hasBooking) {
        /* fallthrough for reserved no order */
        body = `<div style="text-align:center;padding:24px 0;color:var(--muted);">Reserved table.</div>`;
        ft   = `<button class="btn btn-ghost" style="flex:1;" onclick="closePanel()">Close</button>`;
    }

    /* Update icon */
    const dpIcon = document.getElementById('dp-icon');
    dpIcon.style.background = iconColor;
    dpIcon.style.borderColor = iconBorder;
    dpIcon.style.color = iconTxt;

    document.getElementById('dp-body').innerHTML = body;
    document.getElementById('dp-ft').innerHTML   = ft;

    /* Open */
    document.getElementById('detailPanel').classList.add('open');
    document.getElementById('dpOverlay').classList.add('open');
}

/* ── Walk-in helpers for "New Walk-in" secondary button ── */
function walkInHTML(tid, cap) {
    return `
      <div style="text-align:center;margin-bottom:18px;">
        <div style="font-size:14px;font-weight:600;margin-bottom:4px;">Start Walk-in Order</div>
      </div>
      <div class="dp-section">
        <div class="dp-section-lbl">Number of Guests</div>
        <div class="guest-sel">
          <button class="guest-btn" onclick="adjustGuests(-1)">−</button>
          <span id="guestCount">2</span>
          <button class="guest-btn" onclick="adjustGuests(1)">+</button>
        </div>
        <div style="font-size:10.5px;color:var(--muted);margin-top:6px;text-align:center;">Max: ${cap} guests</div>
      </div>`;
}
function walkInFt() {
    return `<button class="btn btn-primary" style="flex:1;justify-content:center;" onclick="startWalkIn()">
              <i class="fa-solid fa-plus"></i> Start Order
            </button>
            <button class="btn btn-ghost" onclick="closePanel()">Cancel</button>`;
}

/* ── Guest count ── */
function adjustGuests(delta) {
    const el  = document.getElementById('guestCount');
    if (!el || !currentTable) return;
    let n = parseInt(el.textContent) + delta;
    n = Math.max(1, Math.min(n, parseInt(currentTable.capacity) || 20));
    el.textContent = n;
}

/* ── Start walk-in ── */
function startWalkIn() {
    if (!currentTable) return;
    const guests = parseInt(document.getElementById('guestCount')?.textContent || '2');
    window.location.href = 'create_order.php?table_id=' + currentTable.id
        + '&order_type=walk_in&guests=' + guests;
}

/* ── Close panel ── */
function closePanel() {
    document.getElementById('detailPanel').classList.remove('open');
    document.getElementById('dpOverlay').classList.remove('open');
    currentTable = null;
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeBillPanel(); closePanel(); } });

/* ══ Print Receipt (shared helper) ══ */
function printReceipt(bill, opts) {
    const { paid = false, paymentMethod = '', discount = 0 } = opts || {};
    const total = Math.max(0, bill.subtotal + bill.tax - discount);
    const o = bill.order || {};
    const now = new Date();
    const pad = v => String(v).padStart(2, '0');
    const dateStr = now.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
                  + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());
    const itemRows = (bill.items || []).map(item => {
        const isComp = item.is_recovery || Number(item.price) === 0;
        return `<tr class="it">
          <td style="padding:5px 0;font-size:12.5px;vertical-align:top;line-height:1.4;">
            ${escHtml(item.name)}
            ${isComp ? '<br><span style="font-size:10px;color:#16a34a;font-weight:700;letter-spacing:.3px;">&#10003; COMPLIMENTARY</span>' : ''}
          </td>
          <td style="text-align:center;padding:5px 6px;font-size:12.5px;vertical-align:top;width:26px;">${item.quantity}</td>
          <td style="text-align:right;padding:5px 0;font-size:12.5px;font-weight:700;vertical-align:top;white-space:nowrap;">
            ${isComp ? '<span style="color:#16a34a;">FREE</span>' : 'DKK\u00a0' + Number(item.subtotal).toFixed(2)}
          </td>
        </tr>`;
    }).join('');
    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Receipt \u2014 ${o.order_number||''}</title>
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
             padding:5px 24px;letter-spacing:4px;transform:rotate(-4deg);box-shadow:2px 2px 0 #ccc}
      .foot{text-align:center;font-size:11px;color:#666;margin-top:10px;line-height:1.9}
      @media print{body{width:80mm;padding:8px 6px}}
    </style></head><body>
    <div class="rn">&#x1F37D; Gourmet House</div>
    <div class="rt">Fine Dining &amp; Bar</div>
    <div class="rc">www.gourmethouse.dk &bull; +45 00 00 00 00</div>
    <hr class="s">
    <div style="text-align:center;margin:5px 0 6px;">
      <div style="font-size:14px;font-weight:700;letter-spacing:.5px;">${o.order_number||''}</div>
      <div style="font-size:11px;color:#666;margin-top:1px;">${dateStr}</div>
      <div style="font-size:12.5px;margin-top:3px;">
        <strong>Table&nbsp;${o.table_number||'?'}</strong>
        ${o.waiter ? ' &bull; Served&nbsp;by:&nbsp;' + escHtml(o.waiter) : ''}
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
      ${discount>0?`<tr><td style="color:#16a34a;">Discount</td><td style="text-align:right;color:#16a34a;">&minus;&nbsp;DKK&nbsp;${Number(discount).toFixed(2)}</td></tr>`:''}
      <tr class="ttr"><td>TOTAL</td><td style="text-align:right;">DKK&nbsp;${Number(total).toFixed(2)}</td></tr>
      ${paid?`<tr><td style="font-size:11px;color:#666;padding-top:6px;">Payment</td><td style="text-align:right;font-size:11px;color:#666;padding-top:6px;">${String(paymentMethod).toUpperCase()}</td></tr>`:''}
    </table>
    <div style="text-align:center;margin:12px 0 8px;">
      ${paid?'<span class="stamp">PAID</span>':'<span style="font-size:11px;color:#aaa;font-style:italic;">&mdash;&nbsp;PREVIEW &mdash; NOT YET PAID&nbsp;&mdash;</span>'}
    </div>
    <hr class="d">
    <div class="foot">
      <div style="font-size:12px;font-weight:700;">Thank you for dining with us!</div>
      <div style="font-size:10px;color:#aaa;">We look forward to your next visit &hearts;</div>
      <div style="font-size:10px;color:#bbb;margin-top:6px;border-top:1px solid #eee;padding-top:5px;">
        VAT included &bull; ${o.order_number||''} &bull; Printed ${dateStr}
      </div>
    </div>
    <script>window.onload=function(){window.print();}<\/script>
    </body></html>`;
    const win = window.open('', '_blank', 'width=460,height=640,toolbar=0,scrollbars=1,resizable=1');
    if (!win) { toast('Pop-up blocked — allow pop-ups to print', 'error'); return; }
    win.document.write(html);
    win.document.close();
}

/* ══ Bill & Payment (cashier-style panel) ══ */
let wCurrentOid     = null;
let wCurrentBill    = null;
let wSelectedMethod = 'cash';

async function fetchBill(oid) {
    wCurrentOid  = oid;
    wCurrentBill = null;
    document.getElementById('bp-order-id').value = oid;
    document.getElementById('bp-order-num').textContent = 'Loading…';
    document.getElementById('bp-table').textContent = '—';
    document.getElementById('bpBody').innerHTML =
        '<div style="text-align:center;color:var(--muted);padding:50px 0;"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;"></i>Loading bill…</div>';
    document.getElementById('billPanel').classList.add('open');
    document.getElementById('bpOverlay').classList.add('open');

    try {
        const r = await fetch('dashboard.php?action=preview_bill&order_id=' + oid);
        const d = await r.json();
        if (!d.success) {
            document.getElementById('bpBody').innerHTML =
                '<div style="text-align:center;padding:40px 0;color:var(--red);"><i class="fa-solid fa-circle-xmark" style="font-size:24px;display:block;margin-bottom:8px;"></i>' + escHtml(d.error || 'Unknown error') + '</div>';
            return;
        }
        wCurrentBill = d;
        wRenderBill(d);
    } catch(e) {
        document.getElementById('bpBody').innerHTML =
            '<div style="text-align:center;padding:40px 0;color:var(--red);">Network error loading bill.</div>';
    }
}

function wRenderBill(d) {
    const o        = d.order;
    const discount = Math.max(0, parseFloat(document.getElementById('wDiscount')?.value || 0));
    const total    = Math.max(0, d.subtotal + d.tax - discount);

    document.getElementById('bp-order-num').textContent = o.order_number;
    document.getElementById('bp-table').textContent     = 'Table ' + (o.table_number || '?') +
        ' — ' + (o.status ? o.status.charAt(0).toUpperCase() + o.status.slice(1) : '');

    const dt  = new Date();
    const pad = v => String(v).padStart(2, '0');
    const dateStr = dt.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
                  + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());

    let html = `<div class="receipt-hd">
        <div style="font-size:11px;color:var(--muted);margin-bottom:3px;">${dateStr}</div>
        <div style="font-size:14px;font-weight:700;">${escHtml(o.order_number)}</div>
        <div style="font-size:12px;color:var(--muted);">Table ${escHtml(String(o.table_number||'?'))}</div>
        <div style="margin-top:6px;"><span class="pill pill-${escHtml(o.status)}">${escHtml(o.status)}</span></div>
    </div>`;

    html += `<div style="margin-bottom:14px;">`;
    d.items.forEach(item => {
        const isComp    = item.is_recovery || Number(item.price) === 0;
        const lineTotal = isComp ? '0.00' : Number(item.subtotal ?? (item.price * item.quantity)).toFixed(2);
        html += `<div class="receipt-item">
            <div>
                <div style="font-size:13px;font-weight:500;">${escHtml(item.name)}${isComp ? ' <span style="font-size:10px;color:#16a34a;font-weight:700;margin-left:4px;">COMP</span>' : ''}</div>
                <div style="font-size:11px;color:var(--muted);">&times;${item.quantity} &nbsp;@&nbsp; DKK&nbsp;${Number(item.price).toFixed(2)}</div>
            </div>
            <div style="font-size:13px;font-weight:600;flex-shrink:0;${isComp ? 'color:#16a34a;' : ''}">
                ${isComp ? 'FREE' : 'DKK\u00a0' + lineTotal}
            </div>
        </div>`;
    });
    html += `</div>`;

    html += `<div class="receipt-totals">
        <div class="rt-row"><span style="color:var(--muted);">Subtotal</span><span>DKK&nbsp;${Number(d.subtotal).toFixed(2)}</span></div>
        <div class="rt-row"><span style="color:var(--muted);">Tax (10%)</span><span>DKK&nbsp;${Number(d.tax).toFixed(2)}</span></div>`;
    if (discount > 0) {
        html += `<div class="rt-row"><span style="color:var(--green);">Discount</span><span style="color:var(--green);">&minus; DKK&nbsp;${discount.toFixed(2)}</span></div>`;
    }
    html += `<div class="rt-row grand"><span>Total Due</span><span class="rt-val">DKK&nbsp;${total.toFixed(2)}</span></div>
    </div>`;

    document.getElementById('bpBody').innerHTML = html;
    document.getElementById('wcc-total').textContent = 'DKK ' + total.toFixed(2);
    wSelectMethod(wSelectedMethod);
    wCalcChange();
}

function wApplyDiscount() {
    if (wCurrentBill) wRenderBill(wCurrentBill);
}

function wPrintPreview() {
    if (!wCurrentBill) { toast('Open a bill first', 'error'); return; }
    const discount = Math.max(0, parseFloat(document.getElementById('wDiscount')?.value || 0));
    printReceipt(wCurrentBill, { paid: false, discount });
}

function wSelectMethod(m) {
    wSelectedMethod = m;
    ['cash','card','qr'].forEach(k =>
        document.getElementById('wpm-'+k)?.classList.toggle('active', k===m)
    );
    document.getElementById('wChangeCalc').style.display = (m === 'cash') ? 'block' : 'none';
}

function wCalcChange() {
    if (!wCurrentBill) return;
    const discount = Math.max(0, parseFloat(document.getElementById('wDiscount')?.value || 0));
    const total    = Math.max(0, wCurrentBill.subtotal + wCurrentBill.tax - discount);
    const tInput   = document.getElementById('wcc-tendered').value;
    const el       = document.getElementById('wcc-change');
    if (!tInput) { el.textContent = 'DKK —'; el.className = 'cc-val positive'; return; }
    const change = parseFloat(tInput) - total;
    el.className   = 'cc-val ' + (change >= 0 ? 'positive' : 'negative');
    el.textContent = (change < 0 ? '−' : '') + 'DKK ' + Math.abs(change).toFixed(2);
}

async function wProcessPayment() {
    const oid = document.getElementById('bp-order-id').value;
    if (!oid) return;
    const btn      = document.getElementById('wPayBtn');
    const discount = Math.max(0, parseFloat(document.getElementById('wDiscount')?.value || 0));
    btn.disabled   = true;
    btn.innerHTML  = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

    try {
        const res  = await fetch('dashboard.php?action=pay', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `order_id=${encodeURIComponent(oid)}&payment_method=${encodeURIComponent(wSelectedMethod)}&discount=${encodeURIComponent(discount)}`
        });
        const data = await res.json();
        if (data.success) {
            toast('Payment completed! DKK ' + Number(data.total).toFixed(2), 'success');
            closeBillPanel();
            setTimeout(() => location.reload(), 800);
        } else {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Complete Payment';
            toast(data.error || 'Payment failed', 'error');
        }
    } catch(e) {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Complete Payment';
        toast('Network error. Please try again.', 'error');
    }
}

function closeBillPanel() {
    document.getElementById('billPanel').classList.remove('open');
    document.getElementById('bpOverlay').classList.remove('open');
    document.getElementById('wDiscount').value      = '0';
    document.getElementById('wcc-tendered').value   = '';
    document.getElementById('wcc-change').textContent = 'DKK —';
    document.getElementById('wcc-change').className   = 'cc-val positive';
    wCurrentOid  = null;
    wCurrentBill = null;
    const btn = document.getElementById('wPayBtn');
    btn.disabled  = false;
    btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Complete Payment';
}

/* Init payment method */
wSelectMethod('cash');

/* ══ Utility ══ */
function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

/* ══ Toast ══ */
function toast(msg, type) {
    type = type || 'info';
    const icons = {success:'fa-circle-check', error:'fa-circle-xmark', info:'fa-circle-info', warning:'fa-triangle-exclamation'};
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = '<i class="fa-solid ' + (icons[type] || icons.info) + '"></i><span>' + escHtml(msg) + '</span>';
    document.getElementById('toast-wrap').appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

/* ══ Sound system ══
   Create AudioContext immediately (will be 'suspended' until gesture).
   Any click on the page resumes it — no need to press Sound button first.
═══════════════════════════════════════════ */
let audioCtx = null;
let waiterSoundOn = true;
try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e){}
document.addEventListener('click', function unlockOnce(){
    if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
    document.removeEventListener('click', unlockOnce);
}, {once: true});

function toggleWaiterSound() {
    if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
    waiterSoundOn = !waiterSoundOn;
    const btn = document.getElementById('wSoundBtn');
    if (waiterSoundOn) {
        btn.classList.add('on'); btn.classList.remove('off');
        btn.querySelector('.sb-lbl').textContent = 'Sound';
        btn.querySelector('i').className = 'fa-solid fa-volume-high';
    } else {
        btn.classList.remove('on'); btn.classList.add('off');
        btn.querySelector('.sb-lbl').textContent = 'Muted';
        btn.querySelector('i').className = 'fa-solid fa-volume-xmark';
    }
}

function bellRingSound() {
    if (!waiterSoundOn || !audioCtx) return;
    try {
        if (audioCtx.state === 'suspended') audioCtx.resume();
        /* Three ascending ding tones */
        [[0, 1047], [0.22, 1319], [0.44, 1568]].forEach(([offset, freq]) => {
            const osc  = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain); gain.connect(audioCtx.destination);
            osc.type = 'sine';
            osc.frequency.value = freq;
            const t = audioCtx.currentTime + offset;
            gain.gain.setValueAtTime(0, t);
            gain.gain.linearRampToValueAtTime(0.45, t + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.001, t + 0.55);
            osc.start(t); osc.stop(t + 0.6);
        });
    } catch(e) { /* audio unavailable */ }
}

/* ══ Bell banner ══ */
let bellBannerTimer = null;
function showBellBanner(orderNum, tableNum) {
    const banner = document.getElementById('bell-banner');
    const msg    = document.getElementById('bell-msg');
    const icon   = document.getElementById('bell-icon');
    if (!banner) return;
    msg.textContent = 'Order ' + orderNum + ' — Table ' + (tableNum || '?') + ' is ready to serve!';
    banner.style.display = 'flex';
    icon.classList.remove('ringing');
    void icon.offsetWidth; /* reflow to restart animation */
    icon.classList.add('ringing');
    if (bellBannerTimer) clearTimeout(bellBannerTimer);
    bellBannerTimer = setTimeout(dismissBell, 8000);
}
function dismissBell() {
    const banner = document.getElementById('bell-banner');
    if (banner) banner.style.display = 'none';
    if (bellBannerTimer) { clearTimeout(bellBannerTimer); bellBannerTimer = null; }
}

/* ══ Order status + bell polling (combined, every 2 s) ══
   Polls both order statuses AND kitchen bells in one request.
   Updates the DOM immediately — NO page reload on status change.
══════════════════════════════════════════════════════════════ */
let knownStatuses = {};
let knownBells    = new Set(JSON.parse(localStorage.getItem('waiter_bells') || '[]'));
if (knownBells.size > 50) {
    knownBells = new Set([...knownBells].slice(-50));
    localStorage.setItem('waiter_bells', JSON.stringify([...knownBells]));
}

/* Seed knownStatuses from current DOM so we don't false-alert on load */
document.querySelectorAll('[id^="ao-"]').forEach(row => {
    const oid  = row.id.replace('ao-', '');
    /* Use pill class (pill-ready, pill-pending …) */
    const pill = row.querySelector('.pill');
    if (pill) {
        const cls = [...pill.classList].find(c => c.startsWith('pill-') && c !== 'pill');
        if (cls) { knownStatuses[oid] = cls.replace('pill-', ''); return; }
    }
    /* Fallback: served rows use a badge instead of a pill */
    if (row.querySelector('.b-served')) knownStatuses[oid] = 'served';
});

let _pollRunning = false;
async function doPoll() {
    if (_pollRunning) return;   /* skip if previous poll not finished */
    _pollRunning = true;
    try {
        /* ─ order statuses + bell in parallel ─ */
        const [ro, rb] = await Promise.all([
            fetch('dashboard.php?action=poll_orders'),
            fetch('dashboard.php?action=check_bell')
        ]);
        const [od, bd] = await Promise.all([ro.json(), rb.json()]);

        if (od.orders) {
            let newReady = [];
            od.orders.forEach(o => {
                const prev = knownStatuses[o.id];
                if (prev === undefined) {
                    knownStatuses[o.id] = o.status;   /* first sight — no alert */
                } else if (prev !== o.status) {
                    knownStatuses[o.id] = o.status;
                    updateAoRow(o.id, o.status, o.order_number, o.table_number);
                    if (o.status === 'ready') newReady.push(o);
                }
            });
            if (newReady.length > 0) {
                bellRingSound();
                showBellBanner(newReady[0].order_number, newReady[0].table_number);
                newReady.forEach(o =>
                    toast('Order ' + o.order_number + ' is READY — Table ' + (o.table_number || '?') + '!', 'success')
                );
            }
        }

        if (bd.bells && bd.bells.length > 0) {
            let newBells = [];
            bd.bells.forEach(b => {
                const key = b.id + ':' + b.bell_rung_at;
                if (!knownBells.has(key)) { knownBells.add(key); newBells.push(b); }
            });
            if (newBells.length > 0) {
                localStorage.setItem('waiter_bells', JSON.stringify([...knownBells]));
                bellRingSound();
                showBellBanner(newBells[0].order_number, newBells[0].table_number);
                newBells.forEach(b =>
                    toast('🔔 Kitchen rang bell — Order ' + b.order_number + ' ready!', 'success')
                );
            }
        }
    } catch(e) { /* silent */ }
    finally {
        _pollRunning = false;
        setTimeout(doPoll, 2000);   /* next poll starts only after this one finishes */
    }
}
setTimeout(doPoll, 2000);

/* ══ updateAoRow — surgically update DOM without reloading ══ */
function updateAoRow(oid, status, orderNum, tableNum) {
    const row = document.getElementById('ao-' + oid);
    if (!row) return;

    /* ── Row highlight ── */
    if (status === 'ready') {
        row.style.borderColor = 'rgba(34,197,94,.35)';
        row.style.background  = 'rgba(34,197,94,.03)';
    } else if (status === 'served') {
        row.style.borderColor = 'rgba(167,139,250,.35)';
        row.style.background  = 'rgba(167,139,250,.04)';
    } else {
        row.style.borderColor = '';
        row.style.background  = '';
    }

    /* ── Status pill ── */
    const pill = row.querySelector('.pill');
    if (pill) {
        pill.className   = 'pill pill-' + status;
        pill.textContent = status;
    }

    /* ── Action buttons ── */
    const actions = row.querySelector('.ao-actions');
    if (!actions) return;

    if (status === 'ready') {
        /* Remove "Add items" edit link — kitchen is cooking, don't disrupt */
        const editLink = actions.querySelector('a[href*="edit_order"]');
        if (editLink) editLink.remove();

        /* Inject "Mark Served" button if not already present */
        if (!actions.querySelector('.js-serve-btn')) {
            const btn = document.createElement('button');
            btn.className = 'btn btn-xs js-serve-btn';
            btn.style.cssText = 'background:rgba(167,139,250,.15);color:#a78bfa;border:1px solid rgba(167,139,250,.3);';
            btn.title   = 'Mark as Served';
            btn.innerHTML = '<i class="fa-solid fa-plate-wheat"></i> Served';
            btn.onclick = function() { markServed(oid, this); };
            /* Insert before the bill/eye button */
            const eyeBtn = actions.querySelector('.btn-ghost');
            if (eyeBtn) actions.insertBefore(btn, eyeBtn);
            else actions.appendChild(btn);
        }
    } else if (status === 'served') {
        /* Remove serve button, edit link, and any ghost eye-preview button */
        actions.querySelector('.js-serve-btn')?.remove();
        actions.querySelector('a[href*="edit_order"]')?.remove();
        const eyeGhost = actions.querySelector('button.btn-ghost');
        if (eyeGhost) eyeGhost.remove();

        /* Replace pill with b-served badge */
        const servPill = row.querySelector('.pill');
        if (servPill) {
            const badge = document.createElement('span');
            badge.className   = 'tt-badge b-served';
            badge.style.fontSize = '9px';
            badge.textContent = '\u2713 Served';
            servPill.replaceWith(badge);
        }

        /* Inject Pay button if not already present */
        if (!actions.querySelector('.btn-primary')) {
            const payBtn = document.createElement('button');
            payBtn.className = 'btn btn-primary btn-xs';
            payBtn.title     = 'Pay Bill';
            payBtn.innerHTML = '<i class="fa-solid fa-cash-register"></i> Pay';
            payBtn.onclick   = function() { fetchBill(oid); };
            actions.appendChild(payBtn);
        }
    }
}

/* ══ Quick Walk-In (from bookings pane) ══ */
async function submitQuickWalkIn() {
    const name   = document.getElementById('qwi-name').value.trim();
    const guests = parseInt(document.getElementById('qwi-guests').value) || 1;
    const time   = document.getElementById('qwi-time').value;
    const err    = document.getElementById('qwi-err');
    const btn    = document.getElementById('qwi-btn');
    if (!name) { err.textContent = 'Guest name is required.'; err.style.display = 'block'; return; }
    err.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Seating…';

    const overlay = document.getElementById('quickWiOverlay');
    const tableId = parseInt(overlay.dataset.tableId || '0') || 0;
    overlay.dataset.tableId = '0';  /* reset after use */

    try {
        const res  = await fetch('../admin/reservations.php?action=walkin', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `name=${encodeURIComponent(name)}&guests=${guests}&time=${encodeURIComponent(time)}&date=<?php echo $today; ?>&table_id=${tableId}`
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('quickWiOverlay').style.display = 'none';
            document.getElementById('qwi-name').value = '';
            toast(`${name} seated at Table ${data.booking.assigned_table}`, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            err.textContent = data.error || 'Failed to seat walk-in.';
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Seat Now';
        }
    } catch(e) {
        err.textContent = 'Network error. Please try again.';
        err.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Seat Now';
    }
}

/* ── sqTabData stub ── */

/* ══ Table Elapsed-Time Timers ══
   Shows ⏱ counter on each occupied tile; alerts waiter at 15 min (amber)
   and 25 min (red popup) for their own tables.
══════════════════════════════ */
const _timerShown = {}; /* table_id → order_id when popup was last fired */

function _fmtElapsed(mins) {
    if (mins < 60) return mins + 'm';
    return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm';
}

function updateTableTimers() {
    const now = Date.now();
    TABLES.forEach(t => {
        if (!t.order_created || !t.order_id) return;
        if (!t.order_status || t.order_status === 'completed' || t.order_status === 'cancelled') return;

        const tile = document.getElementById('tt-' + t.id);
        if (!tile) return;

        /* Parse "YYYY-MM-DD HH:MM:SS" safely */
        const ts = t.order_created.replace(' ', 'T');
        const elapsedMin = Math.max(0, Math.floor((now - new Date(ts).getTime()) / 60000));

        /* Inject or update the timer element */
        let timerEl = tile.querySelector('.tt-timer');
        if (!timerEl) {
            timerEl = document.createElement('div');
            tile.appendChild(timerEl);
        }
        timerEl.textContent = '⏱ ' + _fmtElapsed(elapsedMin);

        if (elapsedMin >= 25) {
            timerEl.className = 'tt-timer t-late';
            if (t.waiter_id == MY_UID && !_timerShown[t.id]) {
                _timerShown[t.id] = t.order_id;
                toast('⏱ Long wait — Table ' + t.table_number + ' (' + _fmtElapsed(elapsedMin) + ')', 'warning');
            }
        } else if (elapsedMin >= 15) {
            timerEl.className = 'tt-timer t-warn';
        } else {
            timerEl.className = 'tt-timer';
        }
    });
}

updateTableTimers();
setInterval(updateTableTimers, 60000);
</script>

</body>
</html>
