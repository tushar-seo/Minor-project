<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Reports';
$dash_title = 'Reports';
$sidebar_role = 'admin';

$report = $_GET['report'] ?? 'sales';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$seller_id = (int)($_GET['seller'] ?? 0);
$category_id = (int)($_GET['category'] ?? 0);
$order_status = $_GET['order_status'] ?? '';
$return_reason = $_GET['return_reason'] ?? '';

function date_clause($conn, $col, $from, $to) {
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
        $sql .= date_clause($conn,'oi.created_at',$date_from,$date_to);
        if ($seller_id) $sql .= " AND oi.seller_id=$seller_id";
        if ($category_id) $sql .= " AND p.category_id=$category_id";
        if ($order_status) $sql .= " AND oi.item_status='".mysqli_real_escape_string($conn,$order_status)."'";
        $sql .= " ORDER BY oi.created_at DESC";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['order_code'], date('d M Y',strtotime($r['created_at'])), $r['buyer'], $r['product'], $r['seller'], $r['quantity'], money($r['line_total']), ucwords(str_replace('_',' ',$r['item_status']))];
        break;

    case 'returns':
        $columns = ['Order ID','Date','Product','Seller','Reason','Qty','Status'];
        $sql = "SELECT o.order_code, rt.created_at, p.name product, s.business_name seller, rt.reason, rt.quantity, rt.status
                FROM returns rt JOIN order_items oi ON oi.id=rt.order_item_id JOIN orders o ON o.id=oi.order_id
                JOIN products p ON p.id=oi.product_id JOIN sellers s ON s.id=oi.seller_id WHERE 1=1";
        $sql .= date_clause($conn,'rt.created_at',$date_from,$date_to);
        if ($seller_id) $sql .= " AND oi.seller_id=$seller_id";
        if ($return_reason) $sql .= " AND rt.reason='".mysqli_real_escape_string($conn,$return_reason)."'";
        $sql .= " ORDER BY rt.created_at DESC";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['order_code'], date('d M Y',strtotime($r['created_at'])), $r['product'], $r['seller'], $r['reason'], $r['quantity'], ucfirst($r['status'])];
        break;

    case 'commission':
        $columns = ['Order ID','Date','Seller','Product','Commission %','Commission Amount'];
        $sql = "SELECT o.order_code, c.created_at, s.business_name seller, p.name product, c.commission_percentage, c.commission_amount
                FROM commissions c JOIN order_items oi ON oi.id=c.order_item_id JOIN orders o ON o.id=oi.order_id
                JOIN sellers s ON s.id=c.seller_id JOIN products p ON p.id=oi.product_id WHERE 1=1";
        $sql .= date_clause($conn,'c.created_at',$date_from,$date_to);
        if ($seller_id) $sql .= " AND c.seller_id=$seller_id";
        $sql .= " ORDER BY c.created_at DESC";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['order_code'], date('d M Y',strtotime($r['created_at'])), $r['seller'], $r['product'], number_format($r['commission_percentage'],2).'%', money($r['commission_amount'])];
        break;

    case 'seller':
        $columns = ['Seller','Products','Orders','Total Sales','Total Commission','Net Earnings','Approval Status'];
        $sql = "SELECT s.business_name,
                (SELECT COUNT(*) FROM products p WHERE p.seller_id=s.id) products,
                (SELECT COUNT(*) FROM order_items oi WHERE oi.seller_id=s.id) orders,
                (SELECT COALESCE(SUM(line_total),0) FROM order_items oi WHERE oi.seller_id=s.id AND oi.item_status!='cancelled') sales,
                (SELECT COALESCE(SUM(commission_amount),0) FROM order_items oi WHERE oi.seller_id=s.id AND oi.item_status!='cancelled') commission,
                (SELECT COALESCE(SUM(seller_earning),0) FROM order_items oi WHERE oi.seller_id=s.id AND oi.item_status!='cancelled') earnings,
                s.approval_status
                FROM sellers s ORDER BY sales DESC";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['business_name'], $r['products'], $r['orders'], money($r['sales']), money($r['commission']), money($r['earnings']), ucfirst($r['approval_status'])];
        break;

    case 'product':
        $columns = ['Product','Seller','Category','Price','Stock','Sold','Rating','Status'];
        $sql = "SELECT p.name, s.business_name seller, c.name category, p.price, p.stock_quantity, p.sold_count, p.rating_avg, p.status
                FROM products p JOIN sellers s ON s.id=p.seller_id JOIN categories c ON c.id=p.category_id WHERE 1=1";
        if ($seller_id) $sql .= " AND p.seller_id=$seller_id";
        if ($category_id) $sql .= " AND p.category_id=$category_id";
        $sql .= " ORDER BY p.sold_count DESC";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['name'], $r['seller'], $r['category'], money($r['price']), $r['stock_quantity'], $r['sold_count'], number_format($r['rating_avg'],1), ucwords(str_replace('_',' ',$r['status']))];
        break;

    case 'customer':
        $columns = ['Buyer','Email','Total Orders','Total Spent','Joined'];
        $sql = "SELECT u.full_name, u.email, u.created_at,
                (SELECT COUNT(*) FROM orders o WHERE o.buyer_id=b.id) orders,
                (SELECT COALESCE(SUM(grand_total),0) FROM orders o WHERE o.buyer_id=b.id AND o.status!='cancelled') spent
                FROM buyers b JOIN users u ON u.id=b.user_id ORDER BY spent DESC";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['full_name'], $r['email'], $r['orders'], money($r['spent']), date('d M Y', strtotime($r['created_at']))];
        break;

    case 'refund':
        $columns = ['Order ID','Product','Buyer','Refund Amount','Processed On'];
        $sql = "SELECT o.order_code, p.name product, u.full_name buyer, rf.amount, rf.processed_at
                FROM refunds rf JOIN returns rt ON rt.id=rf.return_id JOIN order_items oi ON oi.id=rt.order_item_id
                JOIN orders o ON o.id=oi.order_id JOIN products p ON p.id=oi.product_id
                JOIN buyers b ON b.id=rt.buyer_id JOIN users u ON u.id=b.user_id WHERE rf.status='processed'";
        $sql .= date_clause($conn,'rf.processed_at',$date_from,$date_to);
        $sql .= " ORDER BY rf.processed_at DESC";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = [$r['order_code'], $r['product'], $r['buyer'], money($r['amount']), date('d M Y', strtotime($r['processed_at']))];
        break;
}

$sellers_list = mysqli_query($conn, "SELECT id, business_name FROM sellers ORDER BY business_name");
$categories_list = mysqli_query($conn, "SELECT id, name FROM categories ORDER BY name");
$reasons_list = ['Damaged Product','Defective Product','Wrong Product','Product Not as Described','Size/Compatibility Issue','Customer Changed Mind','Other'];

include __DIR__ . '/../includes/dash-start.php';
?>
<style>@media print { .no-print { display:none !important; } .dash-sidebar, .dash-topbar { display:none !important; } }</style>

<div class="no-print">
  <ul class="nav nav-pills mb-3 flex-wrap">
    <?php foreach (['sales'=>'Sales','returns'=>'Returns','commission'=>'Commission','seller'=>'Seller','product'=>'Product','customer'=>'Customer','refund'=>'Refund'] as $k=>$label): ?>
      <li class="nav-item"><a class="nav-link <?= $report==$k?'active':'' ?>" href="?report=<?= $k ?>"><?= $label ?> Report</a></li>
    <?php endforeach; ?>
  </ul>

  <form method="GET" class="card p-3 mb-3">
    <input type="hidden" name="report" value="<?= clean($report) ?>">
    <div class="row g-2 align-items-end">
      <div class="col-md-2"><label class="form-label small">From</label><input type="date" name="date_from" class="form-control form-control-sm" value="<?= clean($date_from) ?>"></div>
      <div class="col-md-2"><label class="form-label small">To</label><input type="date" name="date_to" class="form-control form-control-sm" value="<?= clean($date_to) ?>"></div>
      <div class="col-md-2">
        <label class="form-label small">Seller</label>
        <select name="seller" class="form-select form-select-sm"><option value="0">All</option>
          <?php mysqli_data_seek($sellers_list,0); while($s=mysqli_fetch_assoc($sellers_list)): ?><option value="<?= $s['id'] ?>" <?= $seller_id==$s['id']?'selected':'' ?>><?= clean($s['business_name']) ?></option><?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Category</label>
        <select name="category" class="form-select form-select-sm"><option value="0">All</option>
          <?php mysqli_data_seek($categories_list,0); while($c=mysqli_fetch_assoc($categories_list)): ?><option value="<?= $c['id'] ?>" <?= $category_id==$c['id']?'selected':'' ?>><?= clean($c['name']) ?></option><?php endwhile; ?>
        </select>
      </div>
      <?php if ($report==='sales'): ?>
      <div class="col-md-2">
        <label class="form-label small">Order Status</label>
        <select name="order_status" class="form-select form-select-sm"><option value="">All</option>
          <?php foreach(['pending','confirmed','shipped','delivered','cancelled','returned','refunded'] as $s): ?><option value="<?= $s ?>" <?= $order_status==$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($report==='returns'): ?>
      <div class="col-md-2">
        <label class="form-label small">Return Reason</label>
        <select name="return_reason" class="form-select form-select-sm"><option value="">All</option>
          <?php foreach($reasons_list as $rr): ?><option value="<?= clean($rr) ?>" <?= $return_reason==$rr?'selected':'' ?>><?= clean($rr) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filter</button></div>
    </div>
  </form>

  <div class="d-flex gap-2 mb-3">
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer"></i> Print</button>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-pdf"></i> Export PDF</button>
    <a href="<?= BASE_URL ?>/admin/export_report.php?<?= http_build_query($_GET) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-xlsx"></i> Export Excel (CSV)</a>
  </div>
</div>

<div class="card p-3">
  <h5 class="mb-3 text-capitalize"><?= clean($report) ?> Report</h5>
  <div class="table-responsive">
    <table class="table table-clean mb-0">
      <thead><tr><?php foreach ($columns as $c) echo '<th>'.clean($c).'</th>'; ?></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= count($columns) ?>" class="text-center text-secondary py-4">No records found for the selected filters.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr><?php foreach ($row as $cell) echo '<td>'.clean($cell).'</td>'; ?></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
