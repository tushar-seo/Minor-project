<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');
$page_title = 'My Cart';
$dash_title = 'Shopping Cart';
$sidebar_role = 'buyer';

$buyer_id = current_user()['role_id'];

$stmt = mysqli_prepare($conn, "SELECT ci.id AS item_id, ci.quantity, p.id AS product_id, p.name, p.price, p.discount_percentage, p.stock_quantity,
    (SELECT image_path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.id ASC LIMIT 1) AS image
    FROM cart_items ci JOIN cart c ON c.id = ci.cart_id JOIN products p ON p.id = ci.product_id
    WHERE c.buyer_id = ? ORDER BY ci.created_at DESC");
mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
mysqli_stmt_execute($stmt);
$items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

$total = 0;
foreach ($items as $it) {
    $total += ($it['price'] * (1 - $it['discount_percentage'] / 100)) * $it['quantity'];
}

include __DIR__ . '/../includes/dash-start.php';
?>
<?php if (empty($items)): ?>
  <div class="empty-state"><i class="bi bi-cart-x"></i>Your cart is empty. <a href="<?= BASE_URL ?>/products.php">Browse products</a> to get started.</div>
<?php else: ?>
<div class="row g-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="table-responsive">
        <table class="table table-clean mb-0">
          <thead><tr><th>Product</th><th>Price</th><th>Quantity</th><th>Subtotal</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($items as $it):
            $unit = $it['price'] * (1 - $it['discount_percentage']/100);
            $subtotal = $unit * $it['quantity'];
          ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="product-thumb" style="width:50px;height:50px;font-size:1.2rem;border-radius:8px;">
                    <?php if ($it['image']): ?><img src="<?= product_img_url($it['image']) ?>" class="w-100 h-100" style="object-fit:cover;border-radius:8px;"><?php else: ?><i class="bi bi-image"></i><?php endif; ?>
                  </div>
                  <a href="<?= BASE_URL ?>/product-details.php?id=<?= $it['product_id'] ?>" class="text-reset text-decoration-none fw-semibold small"><?= clean($it['name']) ?></a>
                </div>
              </td>
              <td><?= money($unit) ?></td>
              <td>
                <form method="POST" action="<?= BASE_URL ?>/api/cart_update.php" class="qty-stepper d-flex align-items-center border rounded-pill overflow-hidden" style="width:fit-content;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="item_id" value="<?= $it['item_id'] ?>">
                  <button type="button" class="btn btn-sm" data-qty-btn="dec">−</button>
                  <input type="number" name="quantity" value="<?= $it['quantity'] ?>" min="1" max="<?= $it['stock_quantity'] ?>" class="form-control form-control-sm border-0 text-center" style="width:50px;" onchange="this.form.submit()">
                  <button type="button" class="btn btn-sm" data-qty-btn="inc">+</button>
                </form>
              </td>
              <td class="fw-semibold"><?= money($subtotal) ?></td>
              <td>
                <form method="POST" action="<?= BASE_URL ?>/api/cart_remove.php" data-confirm="Remove this item from cart?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="item_id" value="<?= $it['item_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card p-4">
      <h6 class="mb-3">Order Summary</h6>
      <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Items (<?= count($items) ?>)</span><span><?= money($total) ?></span></div>
      <div class="d-flex justify-content-between mb-3"><span class="text-secondary">Platform Charges</span><span>₹0.00</span></div>
      <hr>
      <div class="d-flex justify-content-between mb-3 fw-bold fs-5"><span>Total</span><span class="text-primary"><?= money($total) ?></span></div>
      <a href="<?= BASE_URL ?>/buyer/checkout.php" class="btn btn-primary rounded-pill py-2">Proceed to Checkout</a>
    </div>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
