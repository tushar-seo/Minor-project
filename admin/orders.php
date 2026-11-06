<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Order Management';
$dash_title = 'Order Management';
$sidebar_role = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $order_id = (int)$_POST['order_id'];
    $new_status = $_POST['new_status'];
    $allowed = ['confirmed','shipped','delivered','cancelled'];

    if (in_array($new_status, $allowed, true)) {
        mysqli_begin_transaction($conn);
        try {
            $items = mysqli_query($conn, "SELECT * FROM order_items WHERE order_id = $order_id");
            $item_rows = mysqli_fetch_all($items, MYSQLI_ASSOC);

            foreach ($item_rows as $it) {
                if (in_array($it['item_status'], ['delivered','cancelled','returned','refunded'], true)) continue;
                mysqli_query($conn, "UPDATE order_items SET item_status='" . $new_status . "' WHERE id=" . $it['id']);
                if ($new_status === 'cancelled') {
                    mysqli_query($conn, "UPDATE products SET stock_quantity = stock_quantity + {$it['quantity']} WHERE id = {$it['product_id']}");
                    mysqli_query($conn, "UPDATE products SET status='approved' WHERE id={$it['product_id']} AND status='out_of_stock'");
                }
            }
            mysqli_query($conn, "UPDATE orders SET status='" . $new_status . "' WHERE id = $order_id");

            $stmt = mysqli_prepare($conn, "SELECT u.id FROM users u JOIN buyers b ON b.user_id=u.id JOIN orders o ON o.buyer_id=b.id WHERE o.id=?");
            mysqli_stmt_bind_param($stmt, 'i', $order_id);
            mysqli_stmt_execute($stmt);
            $buyer_user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if ($buyer_user) notify($buyer_user['id'], 'Order Update', "Your order status has been updated to " . ucwords(str_replace('_',' ',$new_status)) . ".");

            mysqli_commit($conn);
            $bc = order_buyer_contact($order_id);
            if ($bc) email_order_status($bc['email'], $bc['full_name'], $bc['order_code'], ucwords(str_replace('_', ' ', $new_status)));
            set_flash('success', 'Order status updated and the buyer was notified by email.');
        } catch (Exception $e) {
            mysqli_rollback($conn);
            set_flash('error', 'Failed to update order.');
        }
    }
    redirect(BASE_URL . '/admin/orders.php');
}

$view_id = (int)($_GET['view'] ?? 0);
if ($view_id) {
    $stmt = mysqli_prepare($conn, "SELECT o.*, u.full_name AS buyer_name, u.email AS buyer_email FROM orders o
        JOIN buyers b ON b.id=o.buyer_id JOIN users u ON u.id=b.user_id WHERE o.id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $view_id);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$order) { set_flash('error','Order not found.'); redirect(BASE_URL.'/admin/orders.php'); }

    $stmt = mysqli_prepare($conn, "SELECT oi.*, p.name AS product_name, s.business_name FROM order_items oi
        JOIN products p ON p.id=oi.product_id JOIN sellers s ON s.id=oi.seller_id WHERE oi.order_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $view_id);
    mysqli_stmt_execute($stmt);
    $order_items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
} else {
    $status_filter = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $sql = "SELECT o.*, u.full_name AS buyer_name FROM orders o JOIN buyers b ON b.id=o.buyer_id JOIN users u ON u.id=b.user_id WHERE 1=1";
    if ($status_filter) $sql .= " AND o.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
    if ($search !== '') $sql .= " AND (o.order_code LIKE '%".mysqli_real_escape_string($conn,$search)."%' OR u.full_name LIKE '%".mysqli_real_escape_string($conn,$search)."%')";
    $sql .= " ORDER BY o.created_at DESC";
    $orders = mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC);
}

include __DIR__ . '/../includes/dash-start.php';
?>
<?php if ($view_id): ?>
  <a href="<?= BASE_URL ?>/admin/orders.php" class="small d-inline-block mb-3"><i class="bi bi-arrow-left"></i> Back to all orders</a>
  <div class="card p-4 mb-4">
    <div class="d-flex justify-content-between flex-wrap mb-3">
      <div><h5 class="mb-1"><?= clean($order['order_code']) ?></h5><div class="text-secondary small"><?= clean($order['buyer_name']) ?> · <?= clean($order['buyer_email']) ?></div></div>
      <div><?= status_badge($order['status']) ?></div>
    </div>
    <p class="mb-1"><strong>Shipping:</strong> <?= clean($order['shipping_name']) ?>, <?= clean($order['shipping_address']) ?>, <?= clean($order['shipping_phone']) ?></p>
    <p class="mb-3"><strong>Payment:</strong> <?= $order['payment_method']==='cod'?'Cash on Delivery':'Demo Payment' ?> · <strong>Total:</strong> <?= money($order['grand_total']) ?></p>
    <?php if (!in_array($order['status'], ['delivered','cancelled','returned','refunded'], true)): ?>
    <form method="POST" class="d-flex gap-2 flex-wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button name="new_status" value="confirmed" class="btn btn-sm btn-outline-primary">Mark Confirmed</button>
      <button name="new_status" value="shipped" class="btn btn-sm btn-outline-info">Mark Shipped</button>
      <button name="new_status" value="delivered" class="btn btn-sm btn-outline-success">Mark Delivered</button>
      <button name="new_status" value="cancelled" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel this order and restock items?')">Cancel Order</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-clean mb-0">
        <thead><tr><th>Product</th><th>Seller</th><th>Qty</th><th>Amount</th><th>Commission</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($order_items as $it): ?>
          <tr><td><?= clean($it['product_name']) ?></td><td><?= clean($it['business_name']) ?></td><td><?= $it['quantity'] ?></td><td><?= money($it['line_total']) ?></td><td><?= money($it['commission_amount']) ?></td><td><?= status_badge($it['item_status']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex gap-2 flex-wrap">
      <a href="?" class="btn btn-sm <?= empty($_GET['status'])?'btn-primary':'btn-outline-secondary' ?> rounded-pill">All</a>
      <?php foreach (['pending','confirmed','shipped','delivered','cancelled','return_requested','returned','refunded'] as $s): ?>
        <a href="?status=<?= $s ?>" class="btn btn-sm <?= ($_GET['status']??'')==$s?'btn-primary':'btn-outline-secondary' ?> rounded-pill"><?= ucwords(str_replace('_',' ',$s)) ?></a>
      <?php endforeach; ?>
    </div>
    <form method="GET" class="d-flex gap-2">
      <input type="text" name="search" class="form-control form-control-sm" placeholder="Search order ID or buyer" value="<?= clean($_GET['search'] ?? '') ?>">
      <button class="btn btn-sm btn-primary">Search</button>
    </form>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-clean mb-0">
        <thead><tr><th>Order ID</th><th>Buyer</th><th>Amount</th><th>Payment</th><th>Status</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($orders)): ?>
          <tr><td colspan="7" class="text-center text-secondary py-4">No orders found.</td></tr>
        <?php else: foreach ($orders as $o): ?>
          <tr>
            <td><?= clean($o['order_code']) ?></td>
            <td><?= clean($o['buyer_name']) ?></td>
            <td><?= money($o['grand_total']) ?></td>
            <td><?= $o['payment_method']==='cod'?'COD':'Demo' ?></td>
            <td><?= status_badge($o['status']) ?></td>
            <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
            <td><a href="?view=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
