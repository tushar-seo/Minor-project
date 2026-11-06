<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');
$page_title = 'Checkout';
$dash_title = 'Checkout';
$sidebar_role = 'buyer';

$buyer_id = current_user()['role_id'];
$user = current_user();

// Load buyer address for pre-fill
$stmt = mysqli_prepare($conn, "SELECT address FROM buyers WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
mysqli_stmt_execute($stmt);
$buyer_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Load cart items
$stmt = mysqli_prepare($conn, "SELECT ci.id AS item_id, ci.quantity, p.id AS product_id, p.name, p.price, p.discount_percentage,
    p.stock_quantity, p.category_id, p.seller_id
    FROM cart_items ci JOIN cart c ON c.id = ci.cart_id JOIN products p ON p.id = ci.product_id
    WHERE c.buyer_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
mysqli_stmt_execute($stmt);
$items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

if (empty($items)) {
    set_flash('error', 'Your cart is empty.');
    redirect(BASE_URL . '/buyer/cart.php');
}

$order_total = 0;
foreach ($items as $it) {
    $order_total += ($it['price'] * (1 - $it['discount_percentage']/100)) * $it['quantity'];
}
$platform_charges = 0; // configurable later if needed
$grand_total = $order_total + $platform_charges;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $shipping_name = trim($_POST['shipping_name'] ?? '');
    $shipping_address = trim($_POST['shipping_address'] ?? '');
    $shipping_phone = trim($_POST['shipping_phone'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cod';

    $errors = [];
    if ($shipping_name === '' || $shipping_address === '' || $shipping_phone === '') {
        $errors[] = 'Please fill in all shipping details.';
    }
    if (!preg_match('/^[0-9]{10}$/', $shipping_phone)) {
        $errors[] = 'Phone number must be exactly 10 digits.';
    }
    if (!in_array($payment_method, ['cod', 'demo_payment'], true)) {
        $errors[] = 'Invalid payment method.';
    }

    // Re-validate stock right before placing order
    foreach ($items as $it) {
        $stmt = mysqli_prepare($conn, "SELECT stock_quantity FROM products WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $it['product_id']);
        mysqli_stmt_execute($stmt);
        $live_stock = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['stock_quantity'] ?? 0;
        if ($live_stock < $it['quantity']) {
            $errors[] = 'Insufficient stock for "' . $it['name'] . '". Please update your cart.';
        }
    }

    if (empty($errors)) {
        mysqli_begin_transaction($conn);
        try {
            $order_code = order_code_generate();
            $mail_items = []; $seller_mail = [];
            $stmt = mysqli_prepare($conn, "INSERT INTO orders (order_code, buyer_id, shipping_name, shipping_address, shipping_phone, payment_method, order_total, platform_charges, grand_total, status) VALUES (?,?,?,?,?,?,?,?,?,'pending')");
            mysqli_stmt_bind_param($stmt, 'sissssddd', $order_code, $buyer_id, $shipping_name, $shipping_address, $shipping_phone, $payment_method, $order_total, $platform_charges, $grand_total);
            mysqli_stmt_execute($stmt);
            $order_id = mysqli_insert_id($conn);

            foreach ($items as $it) {
                $unit_price = $it['price'] * (1 - $it['discount_percentage']/100);
                $line_total = $unit_price * $it['quantity'];
                $commission_pct = get_commission_percentage($it['category_id'], $it['seller_id']);
                $commission_amt = round($line_total * $commission_pct / 100, 2);
                $seller_earning = round($line_total - $commission_amt, 2);

                $stmt = mysqli_prepare($conn, "INSERT INTO order_items
                    (order_id, product_id, seller_id, quantity, unit_price, line_total, commission_percentage, commission_amount, seller_earning, item_status)
                    VALUES (?,?,?,?,?,?,?,?,?,'pending')");
                mysqli_stmt_bind_param($stmt, 'iiiiddddd', $order_id, $it['product_id'], $it['seller_id'], $it['quantity'], $unit_price, $line_total, $commission_pct, $commission_amt, $seller_earning);
                mysqli_stmt_execute($stmt);
                $order_item_id = mysqli_insert_id($conn);

                // Commission ledger entry
                $stmt = mysqli_prepare($conn, "INSERT INTO commissions (order_item_id, seller_id, commission_percentage, commission_amount) VALUES (?,?,?,?)");
                mysqli_stmt_bind_param($stmt, 'iidd', $order_item_id, $it['seller_id'], $commission_pct, $commission_amt);
                mysqli_stmt_execute($stmt);

                // Reduce stock, flag out-of-stock automatically
                $stmt = mysqli_prepare($conn, "UPDATE products SET stock_quantity = stock_quantity - ?, sold_count = sold_count + ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, 'iii', $it['quantity'], $it['quantity'], $it['product_id']);
                mysqli_stmt_execute($stmt);
                mysqli_query($conn, "UPDATE products SET status = 'out_of_stock' WHERE id = {$it['product_id']} AND stock_quantity <= 0 AND status = 'approved'");

                // Notify seller
                $stmt = mysqli_prepare($conn, "SELECT u.id, u.email, u.full_name FROM users u JOIN sellers s ON s.user_id = u.id WHERE s.id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $it['seller_id']);
                mysqli_stmt_execute($stmt);
                $seller_user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                $mail_items[] = ['name' => $it['name'], 'qty' => $it['quantity'], 'line' => $line_total];
                if ($seller_user) {
                    $seller_mail[$seller_user['id']]['to'] = $seller_user;
                    $seller_mail[$seller_user['id']]['items'][] = ['name' => $it['name'], 'qty' => $it['quantity'], 'line' => $line_total];
                    notify($seller_user['id'], 'New Order Received', "New order $order_code for \"{$it['name']}\" (Qty: {$it['quantity']}).");
                }
            }

            // Payment record (simulated)
            $pay_status = $payment_method === 'demo_payment' ? 'success' : 'pending';
            $txn_ref = strtoupper(bin2hex(random_bytes(6)));
            $stmt = mysqli_prepare($conn, "INSERT INTO payments (order_id, method, amount, status, transaction_ref) VALUES (?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, 'isdss', $order_id, $payment_method, $grand_total, $pay_status, $txn_ref);
            mysqli_stmt_execute($stmt);

            // Clear the cart
            $stmt = mysqli_prepare($conn, "DELETE ci FROM cart_items ci JOIN cart c ON c.id = ci.cart_id WHERE c.buyer_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
            mysqli_stmt_execute($stmt);

            // Notify buyer
            notify($user['id'], 'Order Placed', "Your order $order_code has been placed successfully.");

            mysqli_commit($conn);

            // Emails (after commit so a mail problem can never undo the order)
            email_order_placed($user['email'], $user['full_name'], $order_code, $mail_items, $grand_total);
            foreach ($seller_mail as $sm) {
                $rows = '';
                foreach ($sm['items'] as $mi) $rows .= '<li>' . htmlspecialchars($mi['name']) . ' &times; ' . (int)$mi['qty'] . ' &mdash; ' . money($mi['line']) . '</li>';
                send_mail($sm['to']['email'], "New order $order_code",
                    mail_template('You have a new order', '<p>Hi ' . htmlspecialchars($sm['to']['full_name']) . ', order <strong>' . htmlspecialchars($order_code) . '</strong> includes:</p><ul>' . $rows . '</ul><p>Please log in to confirm and ship it.</p>'),
                    null, true);
            }
            set_flash('success', "Order $order_code placed successfully! A confirmation email has been sent to {$user['email']}.");
            redirect(BASE_URL . '/buyer/orders.php?view=' . $order_id);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errors[] = 'Something went wrong while placing your order. Please try again.';
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $e) set_flash('error', $e);
    }
}

include __DIR__ . '/../includes/dash-start.php';
?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card p-4">
      <h6 class="mb-3">Shipping Information</h6>
      <form method="POST" id="checkoutForm">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Full Name</label>
          <input type="text" name="shipping_name" class="form-control" required value="<?= clean($user['full_name']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Shipping Address</label>
          <textarea name="shipping_address" class="form-control" rows="3" required><?= clean($buyer_row['address'] ?? '') ?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Phone</label>
          <input type="text" name="shipping_phone" maxlength="10" class="form-control" required>
        </div>
        <div class="mb-4">
          <label class="form-label d-block">Payment Method</label>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="payment_method" id="cod" value="cod" checked>
            <label class="form-check-label" for="cod"><i class="bi bi-cash-stack"></i> Cash on Delivery</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="payment_method" id="demo" value="demo_payment">
            <label class="form-check-label" for="demo"><i class="bi bi-credit-card"></i> Demo Payment (simulated success)</label>
          </div>
        </div>
        <button class="btn btn-primary w-100 rounded-pill py-2">Place Order</button>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card p-4">
      <h6 class="mb-3">Order Summary</h6>
      <?php foreach ($items as $it):
        $unit = $it['price'] * (1 - $it['discount_percentage']/100);
      ?>
        <div class="d-flex justify-content-between small mb-2">
          <span><?= clean($it['name']) ?> × <?= $it['quantity'] ?></span>
          <span><?= money($unit * $it['quantity']) ?></span>
        </div>
      <?php endforeach; ?>
      <hr>
      <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Subtotal</span><span><?= money($order_total) ?></span></div>
      <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Platform Charges</span><span><?= money($platform_charges) ?></span></div>
      <hr>
      <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span class="text-primary"><?= money($grand_total) ?></span></div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
