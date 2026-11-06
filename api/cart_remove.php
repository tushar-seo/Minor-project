<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/buyer/cart.php');
verify_csrf();

$buyer_id = current_user()['role_id'];
$item_id = (int)($_POST['item_id'] ?? 0);

$stmt = mysqli_prepare($conn, "DELETE ci FROM cart_items ci JOIN cart c ON c.id = ci.cart_id
    WHERE ci.id = ? AND c.buyer_id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $item_id, $buyer_id);
mysqli_stmt_execute($stmt);

set_flash('success', 'Item removed from cart.');
redirect(BASE_URL . '/buyer/cart.php');
