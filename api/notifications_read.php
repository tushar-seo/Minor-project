<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
verify_csrf();

$user_id = current_user()['id'];
$stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);

$ref = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php');
redirect($ref);
