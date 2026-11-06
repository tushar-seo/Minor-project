<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalog.php';
require_role(['admin', 'seller']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/index.php');
verify_csrf();

$user = current_user();
$format = $_POST['format'] ?? 'zip';
$ids = array_map('intval', $_POST['ids'] ?? []);
$scope = $_POST['scope'] ?? 'selected';
$seller_filter = $user['role'] === 'seller' ? (int)$user['role_id'] : null; // sellers can only export their own products

if ($scope === 'selected' && empty($ids)) {
    set_flash('error', 'Please tick at least one product, or choose "Download everything".');
    redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/index.php');
}
$rows = catalog_fetch($seller_filter, $scope === 'selected' ? $ids : [], trim($_POST['search'] ?? ''), (int)($_POST['category'] ?? 0));
if (!$rows) {
    set_flash('error', 'No products found to export.');
    redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/index.php');
}

$stamp = date('Ymd_His');
if ($format === 'csv') catalog_stream_csv($rows, "products_$stamp.csv");
catalog_stream_zip($rows, "product-catalog_$stamp.zip");
