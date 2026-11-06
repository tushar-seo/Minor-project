<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');
$page_title = 'My Reviews';
$dash_title = 'My Reviews';
$sidebar_role = 'buyer';

$buyer_id = current_user()['role_id'];
$item_id = (int)($_GET['item'] ?? 0);

if ($item_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = mysqli_prepare($conn, "SELECT oi.*, p.name AS product_name FROM order_items oi
        JOIN products p ON p.id = oi.product_id JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = ? AND o.buyer_id = ? AND oi.item_status = 'delivered'
        AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_item_id = oi.id)");
    mysqli_stmt_bind_param($stmt, 'ii', $item_id, $buyer_id);
    mysqli_stmt_execute($stmt);
    $order_item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$order_item) { set_flash('error', 'This item cannot be reviewed.'); redirect(BASE_URL . '/buyer/orders.php'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $item_id = (int)$_POST['order_item_id'];
    $rating = max(1, min(5, (int)$_POST['rating']));
    $review_text = trim($_POST['review_text'] ?? '');

    $stmt = mysqli_prepare($conn, "SELECT oi.*, o.buyer_id FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = ? AND o.buyer_id = ? AND oi.item_status = 'delivered'
        AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_item_id = oi.id)");
    mysqli_stmt_bind_param($stmt, 'ii', $item_id, $buyer_id);
    mysqli_stmt_execute($stmt);
    $oi = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$oi) {
        set_flash('error', 'Unable to submit review for this item.');
    } else {
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "INSERT INTO reviews (product_id, buyer_id, order_item_id, rating, review_text) VALUES (?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, 'iiiis', $oi['product_id'], $buyer_id, $item_id, $rating, $review_text);
            mysqli_stmt_execute($stmt);

            // Recalculate product rating average/count
            $agg = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(rating) avg_r, COUNT(*) cnt FROM reviews WHERE product_id = {$oi['product_id']}"));
            $stmt = mysqli_prepare($conn, "UPDATE products SET rating_avg = ?, rating_count = ? WHERE id = ?");
            $avg = round($agg['avg_r'], 2);
            mysqli_stmt_bind_param($stmt, 'dii', $avg, $agg['cnt'], $oi['product_id']);
            mysqli_stmt_execute($stmt);

            mysqli_commit($conn);
            set_flash('success', 'Thank you for your review!');
            redirect(BASE_URL . '/buyer/reviews.php');
        } catch (Exception $e) {
            mysqli_rollback($conn);
            set_flash('error', 'Something went wrong. Please try again.');
        }
    }
}

$stmt = mysqli_prepare($conn, "SELECT r.*, p.name AS product_name FROM reviews r
    JOIN products p ON p.id = r.product_id WHERE r.buyer_id = ? ORDER BY r.created_at DESC");
mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
mysqli_stmt_execute($stmt);
$my_reviews = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

include __DIR__ . '/../includes/dash-start.php';
?>

<?php if (isset($order_item)): ?>
  <div class="card p-4 mb-4" style="max-width:560px;">
    <h6 class="mb-3">Review — <?= clean($order_item['product_name']) ?></h6>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="order_item_id" value="<?= $order_item['id'] ?>">
      <div class="mb-3">
        <label class="form-label">Rating</label>
        <select name="rating" class="form-select" required>
          <option value="">Select rating</option>
          <?php for ($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> Star<?= $i>1?'s':'' ?></option><?php endfor; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Your Review</label>
        <textarea name="review_text" class="form-control" rows="4" placeholder="Share your experience with this product" required></textarea>
      </div>
      <button class="btn btn-primary rounded-pill px-4">Submit Review</button>
      <a href="<?= BASE_URL ?>/buyer/orders.php" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <?php if (empty($my_reviews)): ?>
    <div class="empty-state"><i class="bi bi-star"></i>You haven't written any reviews yet.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-clean mb-0">
        <thead><tr><th>Product</th><th>Rating</th><th>Review</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($my_reviews as $r): ?>
          <tr>
            <td><?= clean($r['product_name']) ?></td>
            <td class="stars"><?php for($i=0;$i<$r['rating'];$i++) echo '<i class="bi bi-star-fill"></i>'; ?></td>
            <td class="small"><?= clean($r['review_text']) ?></td>
            <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
