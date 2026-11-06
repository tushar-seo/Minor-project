<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');
$page_title = 'Return Requests';
$dash_title = 'Return Requests';
$sidebar_role = 'buyer';

$buyer_id = current_user()['role_id'];
$item_id = (int)($_GET['item'] ?? 0);
$reasons = ['Damaged Product','Defective Product','Wrong Product','Product Not as Described','Size/Compatibility Issue','Customer Changed Mind','Other'];

if ($item_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show the request form for this order item
    $stmt = mysqli_prepare($conn, "SELECT oi.*, p.name AS product_name, o.order_code FROM order_items oi
        JOIN products p ON p.id = oi.product_id JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = ? AND o.buyer_id = ? AND oi.item_status = 'delivered'");
    mysqli_stmt_bind_param($stmt, 'ii', $item_id, $buyer_id);
    mysqli_stmt_execute($stmt);
    $order_item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$order_item) { set_flash('error', 'This item is not eligible for return.'); redirect(BASE_URL . '/buyer/orders.php'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $item_id = (int)$_POST['order_item_id'];
    $quantity = max(1, (int)$_POST['quantity']);
    $reason = $_POST['reason'] ?? '';
    $description = trim($_POST['description'] ?? '');

    $stmt = mysqli_prepare($conn, "SELECT oi.*, o.buyer_id FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = ? AND o.buyer_id = ? AND oi.item_status = 'delivered'");
    mysqli_stmt_bind_param($stmt, 'ii', $item_id, $buyer_id);
    mysqli_stmt_execute($stmt);
    $oi = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$oi) {
        set_flash('error', 'Invalid return request.');
    } elseif (!in_array($reason, $reasons, true)) {
        set_flash('error', 'Please select a valid return reason.');
    } elseif ($quantity > $oi['quantity']) {
        set_flash('error', 'Return quantity cannot exceed ordered quantity.');
    } else {
        $image_path = handle_image_upload('evidence_image', 'returns/');
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "INSERT INTO returns (order_item_id, buyer_id, quantity, reason, description, image_path, status) VALUES (?,?,?,?,?,?,'pending')");
            mysqli_stmt_bind_param($stmt, 'iiisss', $item_id, $buyer_id, $quantity, $reason, $description, $image_path);
            mysqli_stmt_execute($stmt);

            mysqli_query($conn, "UPDATE order_items SET item_status='return_requested' WHERE id=" . (int)$item_id);
            mysqli_query($conn, "UPDATE orders SET status='return_requested' WHERE id=" . (int)$oi['order_id']);

            // Notify admin
            $admins = mysqli_query($conn, "SELECT id FROM users WHERE role='admin'");
            while ($a = mysqli_fetch_assoc($admins)) {
                notify($a['id'], 'New Return Request', "A return request has been submitted for order item #$item_id.");
            }

            mysqli_commit($conn);
            set_flash('success', 'Return request submitted successfully. Admin will review it shortly.');
            redirect(BASE_URL . '/buyer/returns.php');
        } catch (Exception $e) {
            mysqli_rollback($conn);
            set_flash('error', 'Something went wrong. Please try again.');
        }
    }
}

// List all return requests for this buyer
$stmt = mysqli_prepare($conn, "SELECT rt.*, p.name AS product_name, o.order_code FROM returns rt
    JOIN order_items oi ON oi.id = rt.order_item_id
    JOIN products p ON p.id = oi.product_id
    JOIN orders o ON o.id = oi.order_id
    WHERE rt.buyer_id = ? ORDER BY rt.created_at DESC");
mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
mysqli_stmt_execute($stmt);
$returns = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

include __DIR__ . '/../includes/dash-start.php';
?>

<?php if (isset($order_item)): ?>
  <div class="card p-4 mb-4" style="max-width:600px;">
    <h6 class="mb-3">Request Return — <?= clean($order_item['product_name']) ?> <span class="text-secondary small">(<?= clean($order_item['order_code']) ?>)</span></h6>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="order_item_id" value="<?= $order_item['id'] ?>">
      <div class="mb-3">
        <label class="form-label">Quantity to Return</label>
        <input type="number" name="quantity" class="form-control" min="1" max="<?= $order_item['quantity'] ?>" value="1" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Return Reason</label>
        <select name="reason" class="form-select" required>
          <option value="">Select a reason</option>
          <?php foreach ($reasons as $r): ?><option value="<?= clean($r) ?>"><?= clean($r) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Describe the issue in detail" required></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Upload Image (optional)</label>
        <input type="file" name="evidence_image" class="form-control" accept="image/*">
      </div>
      <button class="btn btn-primary rounded-pill px-4">Submit Return Request</button>
      <a href="<?= BASE_URL ?>/buyer/orders.php" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <?php if (empty($returns)): ?>
      <div class="empty-state"><i class="bi bi-arrow-return-left"></i>No return requests yet.</div>
    <?php else: ?>
    <table class="table table-clean mb-0">
      <thead><tr><th>Order</th><th>Product</th><th>Qty</th><th>Reason</th><th>Status</th><th>Requested</th></tr></thead>
      <tbody>
      <?php foreach ($returns as $r): ?>
        <tr>
          <td><?= clean($r['order_code']) ?></td>
          <td><?= clean($r['product_name']) ?></td>
          <td><?= $r['quantity'] ?></td>
          <td><?= clean($r['reason']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
