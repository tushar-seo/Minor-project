<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');
$page_title = 'My Orders';
$dash_title = 'My Orders';
$sidebar_role = 'buyer';

$buyer_id = current_user()['role_id'];
$view_id = (int)($_GET['view'] ?? 0);

// ---- Buyer cancels an order that has not shipped yet (stock is restored) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order_id'])) {
    verify_csrf();
    $cid = (int)$_POST['cancel_order_id'];
    $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ? AND buyer_id = ? AND status IN ('pending','confirmed')");
    mysqli_stmt_bind_param($stmt, 'ii', $cid, $buyer_id);
    mysqli_stmt_execute($stmt);
    $co = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($co) {
        mysqli_begin_transaction($conn);
        $items = mysqli_fetch_all(mysqli_query($conn, "SELECT oi.*, u.id AS seller_user_id FROM order_items oi JOIN sellers s ON s.id = oi.seller_id JOIN users u ON u.id = s.user_id WHERE oi.order_id = " . (int)$cid), MYSQLI_ASSOC);
        foreach ($items as $it) {
            if ($it['item_status'] === 'cancelled') continue;
            mysqli_query($conn, "UPDATE order_items SET item_status='cancelled' WHERE id = " . (int)$it['id']);
            mysqli_query($conn, "UPDATE products SET stock_quantity = stock_quantity + " . (int)$it['quantity'] . ", sold_count = GREATEST(sold_count - " . (int)$it['quantity'] . ", 0) WHERE id = " . (int)$it['product_id']);
            mysqli_query($conn, "UPDATE products SET status='approved' WHERE id = " . (int)$it['product_id'] . " AND status='out_of_stock'");
            notify($it['seller_user_id'], 'Order Cancelled', "Order {$co['order_code']} was cancelled by the buyer. Stock has been restored.");
        }
        mysqli_query($conn, "UPDATE orders SET status='cancelled' WHERE id = " . (int)$cid);
        mysqli_query($conn, "UPDATE payments SET status='failed' WHERE order_id = " . (int)$cid . " AND status='pending'");
        mysqli_commit($conn);
        email_order_status(current_user()['email'], current_user()['full_name'], $co['order_code'], 'Cancelled');
        set_flash('success', "Order {$co['order_code']} has been cancelled.");
    } else {
        set_flash('error', 'This order can no longer be cancelled.');
    }
    redirect(BASE_URL . '/buyer/orders.php?view=' . $cid);
}

if ($view_id) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ? AND buyer_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $view_id, $buyer_id);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$order) { set_flash('error', 'Order not found.'); redirect(BASE_URL . '/buyer/orders.php'); }

    $stmt = mysqli_prepare($conn, "SELECT oi.*, p.name AS product_name,
        (SELECT image_path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.id ASC LIMIT 1) AS image,
        (SELECT COUNT(*) FROM reviews r WHERE r.order_item_id = oi.id) AS reviewed,
        (SELECT COUNT(*) FROM returns rt WHERE rt.order_item_id = oi.id) AS returned_flag
        FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $view_id);
    mysqli_stmt_execute($stmt);
    $order_items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
} else {
    $status_filter = $_GET['status'] ?? '';
    $sql = "SELECT * FROM orders WHERE buyer_id = ?";
    if ($status_filter) $sql .= " AND status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
    $sql .= " ORDER BY created_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
    mysqli_stmt_execute($stmt);
    $orders = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

include __DIR__ . '/../includes/dash-start.php';
?>

<?php if ($view_id): ?>
  <a href="<?= BASE_URL ?>/buyer/orders.php" class="small d-inline-block mb-3"><i class="bi bi-arrow-left"></i> Back to all orders</a>
  <div class="card p-4 mb-4">
    <div class="d-flex justify-content-between flex-wrap mb-3">
      <div>
        <h5 class="mb-1"><?= clean($order['order_code']) ?></h5>
        <div class="text-secondary small">Placed on <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></div>
      </div>
      <div><?= status_badge($order['status']) ?>
        <?php if (in_array($order['status'], ['pending','confirmed'], true)): ?>
          <form method="POST" class="mt-2" data-confirm="Cancel this order? Stock will be restored.">
            <?= csrf_field() ?><input type="hidden" name="cancel_order_id" value="<?= $order['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">Cancel order</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="row">
      <div class="col-md-6">
        <h6 class="small text-secondary text-uppercase">Shipping To</h6>
        <p class="mb-0"><?= clean($order['shipping_name']) ?><br><?= nl2br(clean($order['shipping_address'])) ?><br><?= clean($order['shipping_phone']) ?></p>
      </div>
      <div class="col-md-6">
        <h6 class="small text-secondary text-uppercase">Payment</h6>
        <p class="mb-0"><?= $order['payment_method'] === 'cod' ? 'Cash on Delivery' : 'Demo Payment' ?></p>
        <h6 class="small text-secondary text-uppercase mt-2">Total</h6>
        <p class="mb-0 fw-bold fs-5 text-primary"><?= money($order['grand_total']) ?></p>
      </div>
    </div>
  </div>

  <?php
    $flow = ['pending' => 'Order placed', 'confirmed' => 'Confirmed', 'shipped' => 'Shipped', 'delivered' => 'Delivered'];
    $rank = ['pending' => 0, 'confirmed' => 1, 'shipped' => 2, 'delivered' => 3, 'return_requested' => 3, 'returned' => 3, 'refunded' => 3];
    $cur_rank = $rank[$order['status']] ?? -1;
  ?>
  <div class="card p-4 mb-4">
    <h6 class="mb-0">Order tracking</h6>
    <?php if ($order['status'] === 'cancelled'): ?>
      <div class="alert alert-danger mt-3 mb-0"><i class="bi bi-x-circle"></i> This order was cancelled. Any reserved stock has been released.</div>
    <?php else: ?>
      <div class="track">
        <?php $i = 0; foreach ($flow as $key => $label): ?>
          <div class="track-step <?= $i <= $cur_rank ? 'done' : '' ?>">
            <div class="track-dot"><i class="bi <?= $i <= $cur_rank ? 'bi-check-lg' : 'bi-circle' ?>"></i></div><?= $label ?>
          </div>
        <?php $i++; endforeach; ?>
      </div>
      <?php if (in_array($order['status'], ['return_requested', 'returned', 'refunded'], true)): ?>
        <div class="small text-secondary mt-2">Return status: <?= status_badge($order['status']) ?></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-clean mb-0">
        <thead><tr><th>Product</th><th>Qty</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($order_items as $it): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="product-thumb" style="width:44px;height:44px;font-size:1.1rem;border-radius:8px;">
                  <?php if ($it['image']): ?><img src="<?= product_img_url($it['image']) ?>" class="w-100 h-100" style="object-fit:cover;border-radius:8px;"><?php else: ?><i class="bi bi-image"></i><?php endif; ?>
                </div>
                <?= clean($it['product_name']) ?>
              </div>
            </td>
            <td><?= $it['quantity'] ?></td>
            <td><?= money($it['line_total']) ?></td>
            <td><?= status_badge($it['item_status']) ?></td>
            <td class="d-flex gap-2">
              <?php if ($it['item_status'] === 'delivered' && !$it['returned_flag']): ?>
                <a href="<?= BASE_URL ?>/buyer/returns.php?item=<?= $it['id'] ?>" class="btn btn-sm btn-outline-danger">Request Return</a>
              <?php endif; ?>
              <?php if ($it['item_status'] === 'delivered' && !$it['reviewed']): ?>
                <a href="<?= BASE_URL ?>/buyer/reviews.php?item=<?= $it['id'] ?>" class="btn btn-sm btn-outline-primary">Write Review</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>
  <div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="?status=" class="btn btn-sm <?= empty($_GET['status'])?'btn-primary':'btn-outline-secondary' ?> rounded-pill">All</a>
    <?php foreach (['pending','confirmed','shipped','delivered','cancelled','return_requested','returned','refunded'] as $s): ?>
      <a href="?status=<?= $s ?>" class="btn btn-sm <?= ($_GET['status']??'')==$s?'btn-primary':'btn-outline-secondary' ?> rounded-pill"><?= ucwords(str_replace('_',' ',$s)) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($orders)): ?>
    <div class="empty-state"><i class="bi bi-receipt"></i>No orders found. <a href="<?= BASE_URL ?>/products.php">Start shopping</a></div>
  <?php else: ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-clean mb-0">
        <thead><tr><th>Order ID</th><th>Date</th><th>Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><?= clean($o['order_code']) ?></td>
            <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
            <td><?= money($o['grand_total']) ?></td>
            <td><?= status_badge($o['status']) ?></td>
            <td><a href="?view=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
