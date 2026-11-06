<?php
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/products.php');
verify_csrf();

if (!is_logged_in() || current_user()['role'] !== 'buyer') {
    set_flash('error', 'Please login as a buyer to add items to your cart.');
    redirect(BASE_URL . '/login.php');
}

$product_id = (int)($_POST['product_id'] ?? 0);
$quantity = max(1, (int)($_POST['quantity'] ?? 1));
$action = $_POST['action'] ?? 'cart';
$buyer_id = current_user()['role_id'];

$stmt = mysqli_prepare($conn, "SELECT * FROM products WHERE id = ? AND status = 'approved'");
mysqli_stmt_bind_param($stmt, 'i', $product_id);
mysqli_stmt_execute($stmt);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$product || $product['stock_quantity'] < 1) {
    set_flash('error', 'This product is unavailable.');
    redirect(BASE_URL . '/products.php');
}
$quantity = min($quantity, $product['stock_quantity']);

// Get or create cart
$stmt = mysqli_prepare($conn, "SELECT id FROM cart WHERE buyer_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
mysqli_stmt_execute($stmt);
$cart = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$cart) {
    $stmt = mysqli_prepare($conn, "INSERT INTO cart (buyer_id) VALUES (?)");
    mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
    mysqli_stmt_execute($stmt);
    $cart_id = mysqli_insert_id($conn);
} else {
    $cart_id = $cart['id'];
}

$stmt = mysqli_prepare($conn, "SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $cart_id, $product_id);
mysqli_stmt_execute($stmt);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($existing) {
    $new_qty = min($existing['quantity'] + $quantity, $product['stock_quantity']);
    $stmt = mysqli_prepare($conn, "UPDATE cart_items SET quantity = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $new_qty, $existing['id']);
    mysqli_stmt_execute($stmt);
} else {
    $stmt = mysqli_prepare($conn, "INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?,?,?)");
    mysqli_stmt_bind_param($stmt, 'iii', $cart_id, $product_id, $quantity);
    mysqli_stmt_execute($stmt);
}

if ($action === 'buy_now') {
    redirect(BASE_URL . '/buyer/checkout.php');
} else {
    set_flash('success', 'Added "' . $product['name'] . '" to your cart.');
    redirect(BASE_URL . '/buyer/cart.php');
}
