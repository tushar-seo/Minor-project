<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Return Management';
$dash_title = 'Return Management';
$sidebar_role = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $return_id = (int)$_POST['return_id'];
    $decision = $_POST['decision']; // approved | rejected
    $admin_note = trim($_POST['admin_note'] ?? '');
    $restock = isset($_POST['restock']) ? 1 : 0;

    $stmt = mysqli_prepare($conn, "SELECT rt.*, oi.product_id, oi.line_total, oi.quantity AS ordered_qty, oi.order_id, oi.seller_id, o.buyer_id
        FROM returns rt JOIN order_items oi ON oi.id = rt.order_item_id JOIN orders o ON o.id = oi.order_id
        WHERE rt.id = ? AND rt.status = 'pending'");
    mysqli_stmt_bind_param($stmt, 'i', $return_id);
    mysqli_stmt_execute($stmt);
    $ret = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($ret && in_array($decision, ['approved','rejected'], true)) {
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "UPDATE returns SET status = ?, admin_note = ?, restock = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'ssii', $decision, $admin_note, $restock, $return_id);
            mysqli_stmt_execute($stmt);

            if ($decision === 'approved') {
                // Restock proportionally to returned quantity if condition allows
                if ($restock) {
                    $stmt = mysqli_prepare($conn, "UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, 'ii', $ret['quantity'], $ret['product_id']);
                    mysqli_stmt_execute($stmt);
                    mysqli_query($conn, "UPDATE products SET status='approved' WHERE id={$ret['product_id']} AND status='out_of_stock'");
                }

                // Refund = proportional amount based on returned quantity
                $unit_amount = $ret['line_total'] / max(1,$ret['ordered_qty']);
                $refund_amount = round($unit_amount * $ret['quantity'], 2);
                $stmt = mysqli_prepare($conn, "INSERT INTO refunds (return_id, amount, status, processed_at) VALUES (?,?,'processed',NOW())");
                mysqli_stmt_bind_param($stmt, 'id', $return_id, $refund_amount);
                mysqli_stmt_execute($stmt);

                mysqli_query($conn, "UPDATE returns SET status='refunded' WHERE id=$return_id");
                mysqli_query($conn, "UPDATE order_items SET item_status='refunded' WHERE id={$ret['order_item_id']}");
                mysqli_query($conn, "UPDATE orders SET status='refunded' WHERE id={$ret['order_id']}");

                $stmt = mysqli_prepare($conn, "SELECT u.id FROM users u JOIN buyers b ON b.user_id=u.id WHERE b.id=?");
                mysqli_stmt_bind_param($stmt, 'i', $ret['buyer_id']);
                mysqli_stmt_execute($stmt);
                $bu = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                if ($bu) notify($bu['id'], 'Return Approved & Refunded', "Your return has been approved and " . money($refund_amount) . " has been refunded.");
            } else {
                mysqli_query($conn, "UPDATE order_items SET item_status='delivered' WHERE id={$ret['order_item_id']}");
                mysqli_query($conn, "UPDATE orders SET status='delivered' WHERE id={$ret['order_id']}");

                $stmt = mysqli_prepare($conn, "SELECT u.id FROM users u JOIN buyers b ON b.user_id=u.id WHERE b.id=?");
                mysqli_stmt_bind_param($stmt, 'i', $ret['buyer_id']);
                mysqli_stmt_execute($stmt);
                $bu = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                if ($bu) notify($bu['id'], 'Return Rejected', "Your return request was rejected. Reason: " . ($admin_note ?: 'Not specified'));
            }

            mysqli_commit($conn);
            set_flash('success', 'Return request ' . $decision . '.');
        } catch (Exception $e) {
            mysqli_rollback($conn);
            set_flash('error', 'Failed to process return.');
        }
    }
    redirect(BASE_URL . '/admin/returns.php');
}

$status_filter = $_GET['status'] ?? '';
$sql = "SELECT rt.*, p.name AS product_name, o.order_code, u.full_name AS buyer_name, s.business_name
        FROM returns rt
        JOIN order_items oi ON oi.id = rt.order_item_id
        JOIN products p ON p.id = oi.product_id
        JOIN orders o ON o.id = oi.order_id
        JOIN buyers b ON b.id = rt.buyer_id JOIN users u ON u.id = b.user_id
        JOIN sellers s ON s.id = oi.seller_id
        WHERE 1=1";
if ($status_filter) $sql .= " AND rt.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
$sql .= " ORDER BY rt.created_at DESC";
$returns = mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC);

include __DIR__ . '/../includes/dash-start.php';
?>
<div class="d-flex gap-2 mb-3 flex-wrap">
  <a href="?" class="btn btn-sm <?= $status_filter==''?'btn-primary':'btn-outline-secondary' ?> rounded-pill">All</a>
  <?php foreach (['pending','approved','rejected','refunded'] as $s): ?>
    <a href="?status=<?= $s ?>" class="btn btn-sm <?= $status_filter==$s?'btn-primary':'btn-outline-secondary' ?> rounded-pill"><?= ucfirst($s) ?></a>
  <?php endforeach; ?>
</div>

<?php if (empty($returns)): ?>
  <div class="empty-state"><i class="bi bi-arrow-return-left"></i>No return requests found.</div>
<?php else: foreach ($returns as $r): ?>
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between flex-wrap">
      <div>
        <h6 class="mb-1"><?= clean($r['product_name']) ?> <span class="text-secondary small">(<?= clean($r['order_code']) ?>)</span></h6>
        <div class="small text-secondary mb-1">Buyer: <?= clean($r['buyer_name']) ?> · Seller: <?= clean($r['business_name']) ?> · Qty: <?= $r['quantity'] ?></div>
        <div class="mb-1"><span class="badge bg-secondary"><?= clean($r['reason']) ?></span></div>
        <p class="mb-1 small"><?= clean($r['description']) ?></p>
        <?php if ($r['image_path']): ?><img src="<?= product_img_url($r['image_path']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:8px;" class="mt-1"><?php endif; ?>
        <?php if ($r['admin_note']): ?><div class="small text-secondary mt-1">Admin note: <?= clean($r['admin_note']) ?></div><?php endif; ?>
      </div>
      <div class="text-end"><?= status_badge($r['status']) ?><div class="small text-secondary mt-1"><?= date('d M Y', strtotime($r['created_at'])) ?></div></div>
    </div>
    <?php if ($r['status'] === 'pending'): ?>
    <form method="POST" class="d-flex gap-2 align-items-center flex-wrap mt-3 pt-3 border-top">
      <?= csrf_field() ?>
      <input type="hidden" name="return_id" value="<?= $r['id'] ?>">
      <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="Admin note (optional)" style="max-width:260px;">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="restock" id="restock<?= $r['id'] ?>" checked>
        <label class="form-check-label small" for="restock<?= $r['id'] ?>">Restock item</label>
      </div>
      <button name="decision" value="approved" class="btn btn-sm btn-success">Approve & Refund</button>
      <button name="decision" value="rejected" class="btn btn-sm btn-outline-danger">Reject</button>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
