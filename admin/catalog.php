<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$page_title = 'Product Catalog Download';
$dash_title = 'Product Catalog & Downloads';
$sidebar_role = 'admin';
$catalog_seller_id = null;
include __DIR__ . '/../includes/dash-start.php';
include __DIR__ . '/../includes/catalog_view.php';
include __DIR__ . '/../includes/dash-footer.php';
