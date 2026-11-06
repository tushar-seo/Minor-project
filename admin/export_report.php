<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$report = $_GET['report'] ?? 'sales';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$seller_id = (int)($_GET['seller'] ?? 0);
$category_id = (int)($_GET['category'] ?? 0);
$order_status = $_GET['order_status'] ?? '';
$return_reason = $_GET['return_reason'] ?? '';

function date_clause2($conn, $col, $from, $to) {
    $c = '';
    if ($from) $c .= " AND $col >= '" . mysqli_real_escape_string($conn, $from) . " 00:00:00'";
    if ($to)   $c .= " AND $col <= '" . mysqli_real_escape_string($conn, $to) . " 23:59:59'";
    return $c;
}

$rows = []; $columns = [];

switch ($report) {
    case 'sales':
        $columns = ['Order ID','Date','Buyer','Product','Seller','Qty','Amount','Status'];
        $sql = "SELECT o.order_code, oi.created_at, u.full_name buyer, p.name product, s.business_name seller, oi.quantity, oi.line_total, oi.item_status
                FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN buyers b ON b.id=o.buyer_id JOIN users u ON u.id=b.user_id
                JOIN products p ON p.id=oi.product_id JOIN sellers s ON s.id=oi.seller_id WHERE 1=1";
        $sql .= date_clause2($conn,'oi.created_at',$date_from,$date_to);
        if ($seller_id) $sql .= " AND oi.seller_id=$seller_id";
        if ($category_id) $sql .= " AND p.category_id=$category_id";
        if ($order_status) $sql .= " AND oi.item_status='".mysqli_real_escape_string($conn,$order_status)."'";
        $res = mysqli_query($conn, $sql . " ORDER BY oi.created_at DESC");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['order_code'], $r['created_at'], $r['buyer'], $r['product'], $r['seller'], $r['quantity'], $r['line_total'], $r['item_status']];
        break;
    case 'returns':
        $columns = ['Order ID','Date','Product','Seller','Reason','Qty','Status'];
        $sql = "SELECT o.order_code, rt.created_at, p.name product, s.business_name seller, rt.reason, rt.quantity, rt.status
                FROM returns rt JOIN order_items oi ON oi.id=rt.order_item_id JOIN orders o ON o.id=oi.order_id
                JOIN products p ON p.id=oi.product_id JOIN sellers s ON s.id=oi.seller_id WHERE 1=1";
        $sql .= date_clause2($conn,'rt.created_at',$date_from,$date_to);
        if ($seller_id) $sql .= " AND oi.seller_id=$seller_id";
        if ($return_reason) $sql .= " AND rt.reason='".mysqli_real_escape_string($conn,$return_reason)."'";
        $res = mysqli_query($conn, $sql . " ORDER BY rt.created_at DESC");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['order_code'], $r['created_at'], $r['product'], $r['seller'], $r['reason'], $r['quantity'], $r['status']];
        break;
    case 'commission':
        $columns = ['Order ID','Date','Seller','Product','Commission %','Commission Amount'];
        $sql = "SELECT o.order_code, c.created_at, s.business_name seller, p.name product, c.commission_percentage, c.commission_amount
                FROM commissions c JOIN order_items oi ON oi.id=c.order_item_id JOIN orders o ON o.id=oi.order_id
                JOIN sellers s ON s.id=c.seller_id JOIN products p ON p.id=oi.product_id WHERE 1=1";
        $sql .= date_clause2($conn,'c.created_at',$date_from,$date_to);
        if ($seller_id) $sql .= " AND c.seller_id=$seller_id";
        $res = mysqli_query($conn, $sql . " ORDER BY c.created_at DESC");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['order_code'], $r['created_at'], $r['seller'], $r['product'], $r['commission_percentage'], $r['commission_amount']];
        break;
    case 'seller':
        $columns = ['Seller','Products','Orders','Total Sales','Total Commission','Net Earnings','Approval Status'];
        $sql = "SELECT s.business_name,
                (SELECT COUNT(*) FROM products p WHERE p.seller_id=s.id) products,
                (SELECT COUNT(*) FROM order_items oi WHERE oi.seller_id=s.id) orders,
                (SELECT COALESCE(SUM(line_total),0) FROM order_items oi WHERE oi.seller_id=s.id AND oi.item_status!='cancelled') sales,
                (SELECT COALESCE(SUM(commission_amount),0) FROM order_items oi WHERE oi.seller_id=s.id AND oi.item_status!='cancelled') commission,
                (SELECT COALESCE(SUM(seller_earning),0) FROM order_items oi WHERE oi.seller_id=s.id AND oi.item_status!='cancelled') earnings,
                s.approval_status FROM sellers s ORDER BY sales DESC";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['business_name'], $r['products'], $r['orders'], $r['sales'], $r['commission'], $r['earnings'], $r['approval_status']];
        break;
    case 'product':
        $columns = ['Product','Seller','Category','Price','Stock','Sold','Rating','Status'];
        $sql = "SELECT p.name, s.business_name seller, c.name category, p.price, p.stock_quantity, p.sold_count, p.rating_avg, p.status
                FROM products p JOIN sellers s ON s.id=p.seller_id JOIN categories c ON c.id=p.category_id WHERE 1=1";
        if ($seller_id) $sql .= " AND p.seller_id=$seller_id";
        if ($category_id) $sql .= " AND p.category_id=$category_id";
        $res = mysqli_query($conn, $sql . " ORDER BY p.sold_count DESC");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['name'], $r['seller'], $r['category'], $r['price'], $r['stock_quantity'], $r['sold_count'], $r['rating_avg'], $r['status']];
        break;
    case 'customer':
        $columns = ['Buyer','Email','Total Orders','Total Spent','Joined'];
        $sql = "SELECT u.full_name, u.email, u.created_at,
                (SELECT COUNT(*) FROM orders o WHERE o.buyer_id=b.id) orders,
                (SELECT COALESCE(SUM(grand_total),0) FROM orders o WHERE o.buyer_id=b.id AND o.status!='cancelled') spent
                FROM buyers b JOIN users u ON u.id=b.user_id ORDER BY spent DESC";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['full_name'], $r['email'], $r['orders'], $r['spent'], $r['created_at']];
        break;
    case 'refund':
        $columns = ['Order ID','Product','Buyer','Refund Amount','Processed On'];
        $sql = "SELECT o.order_code, p.name product, u.full_name buyer, rf.amount, rf.processed_at
                FROM refunds rf JOIN returns rt ON rt.id=rf.return_id JOIN order_items oi ON oi.id=rt.order_item_id
                JOIN orders o ON o.id=oi.order_id JOIN products p ON p.id=oi.product_id
                JOIN buyers b ON b.id=rt.buyer_id JOIN users u ON u.id=b.user_id WHERE rf.status='processed'";
        $sql .= date_clause2($conn,'rf.processed_at',$date_from,$date_to);
        $res = mysqli_query($conn, $sql . " ORDER BY rf.processed_at DESC");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['order_code'], $r['product'], $r['buyer'], $r['amount'], $r['processed_at']];
        break;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $report . '_report_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, $columns);
foreach ($rows as $row) fputcsv($out, $row);
fclose($out);
exit;
