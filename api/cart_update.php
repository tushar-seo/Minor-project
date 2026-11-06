<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/buyer/cart.php');
verify_csrf();

$buyer_id = current_user()['role_id'];
$item_id = (int)($_POST['item_id'] ?? 0);
$quantity = max(1, (int)($_POST['quantity'] ?? 1));

// Ensure the cart item belongs to this buyer
$stmt = mysqli_prepare($conn, "SELECT ci.id, ci.product_id, p.stock_quantity FROM cart_items ci
    JOIN cart c ON c.id = ci.cart_id JOIN products p ON p.id = ci.product_id
    WHERE ci.id = ? AND c.buyer_id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $item_id, $buyer_id);
mysqli_stmt_execute($stmt);
$item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($item) {
    $quantity = min($quantity, $item['stock_quantity']);
    $stmt = mysqli_prepare($conn, "UPDATE cart_items SET quantity = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $quantity, $item_id);
    mysqli_stmt_execute($stmt);
}

redirect(BASE_URL . '/buyer/cart.php');
