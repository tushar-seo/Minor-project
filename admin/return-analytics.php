<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Sales Return Analytics';
$dash_title = 'Sales Return Analytics';
$sidebar_role = 'admin';

function qrows($sql) { global $conn; return mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC); }
function q1($sql) { global $conn; return mysqli_fetch_assoc(mysqli_query($conn, $sql)) ?: []; }
function pct($a, $b) { return $b > 0 ? round($a / $b * 100, 1) : 0; }

/* ---------- CSV export of every return (for Excel / the report) ---------- */
if (isset($_GET['export'])) {
    $rows = qrows("SELECT rt.id, o.order_code, p.name AS product, c.name AS category, s.business_name AS seller, rt.quantity, rt.reason, rt.status,
                          oi.line_total AS item_value, COALESCE(rf.amount,0) AS refund_amount, oi.created_at AS ordered_on, rt.created_at AS return_requested_on,
                          DATEDIFF(rt.created_at, oi.created_at) AS days_to_return
                   FROM returns rt JOIN order_items oi ON oi.id = rt.order_item_id JOIN orders o ON o.id = oi.order_id
                   JOIN products p ON p.id = oi.product_id JOIN categories c ON c.id = p.category_id JOIN sellers s ON s.id = oi.seller_id
                   LEFT JOIN refunds rf ON rf.return_id = rt.id ORDER BY rt.created_at DESC");
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sales-returns-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Return ID', 'Order', 'Product', 'Category', 'Seller', 'Qty', 'Reason', 'Status', 'Item value', 'Refund amount', 'Ordered on', 'Return requested on', 'Days to return']);
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit;
}

/* ---------- KPIs ---------- */
$tot = q1("SELECT (SELECT COUNT(*) FROM order_items WHERE item_status <> 'cancelled') items,
                  (SELECT COALESCE(SUM(line_total),0) FROM order_items WHERE item_status <> 'cancelled') sales,
                  (SELECT COUNT(*) FROM returns) returns_total,
                  (SELECT COUNT(*) FROM returns WHERE status='pending') pending,
                  (SELECT COUNT(*) FROM returns WHERE status IN ('approved','refunded')) accepted,
                  (SELECT COUNT(*) FROM returns WHERE status <> 'pending') decided");
$lost = q1("SELECT COALESCE(SUM(oi.line_total),0) value, COALESCE(SUM(oi.commission_amount),0) commission
            FROM returns rt JOIN order_items oi ON oi.id = rt.order_item_id WHERE rt.status IN ('approved','refunded')");
$refunds = q1("SELECT COALESCE(SUM(amount),0) total FROM refunds WHERE status='processed'");
$avg_days = q1("SELECT ROUND(AVG(GREATEST(DATEDIFF(rt.created_at, oi.created_at),0)),1) d FROM returns rt JOIN order_items oi ON oi.id = rt.order_item_id");

$return_rate = pct($tot['returns_total'], $tot['items']);
$value_rate = pct($lost['value'], $tot['sales']);
$approval_rate = pct($tot['accepted'], $tot['decided']);

/* ---------- Chart data ---------- */
// Monthly: items sold, returns, return rate %
$monthly = qrows("SELECT DATE_FORMAT(oi.created_at,'%Y-%m') ym, DATE_FORMAT(MIN(oi.created_at),'%b %Y') m,
        COUNT(DISTINCT oi.id) items, COUNT(DISTINCT rt.id) returns_n
    FROM order_items oi LEFT JOIN returns rt ON rt.order_item_id = oi.id
    WHERE oi.item_status <> 'cancelled' AND oi.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(oi.created_at,'%Y-%m') ORDER BY ym");
$m_labels = array_column($monthly, 'm');
$m_returns = array_map('intval', array_column($monthly, 'returns_n'));
$m_rate = array_map(fn($r) => pct($r['returns_n'], $r['items']), $monthly);

// Status funnel
$status_rows = qrows("SELECT status, COUNT(*) c FROM returns GROUP BY status");
$st = ['pending' => 0, 'approved' => 0, 'refunded' => 0, 'rejected' => 0];
foreach ($status_rows as $r) $st[$r['status']] = (int)$r['c'];

// Reasons with share
$reasons = qrows("SELECT reason, COUNT(*) c FROM returns GROUP BY reason ORDER BY c DESC");
$r_labels = array_column($reasons, 'reason');
$r_data = array_map('intval', array_column($reasons, 'c'));

// Category return RATE (returns / items sold in that category)
$cats = qrows("SELECT c.name,
        COUNT(DISTINCT oi.id) items,
        COUNT(DISTINCT rt.id) returns_n
    FROM categories c JOIN products p ON p.category_id = c.id JOIN order_items oi ON oi.product_id = p.id AND oi.item_status <> 'cancelled'
    LEFT JOIN returns rt ON rt.order_item_id = oi.id GROUP BY c.id ORDER BY returns_n / COUNT(DISTINCT oi.id) DESC");
foreach ($cats as &$c) $c['rate'] = pct($c['returns_n'], $c['items']);
unset($c);

// Days between purchase and return request
$buckets = ['0-2 days' => 0, '3-5 days' => 0, '6-10 days' => 0, '11-20 days' => 0, '21+ days' => 0];
foreach (qrows("SELECT GREATEST(DATEDIFF(rt.created_at, oi.created_at),0) d FROM returns rt JOIN order_items oi ON oi.id = rt.order_item_id") as $r) {
    $d = (int)$r['d'];
    $k = $d <= 2 ? '0-2 days' : ($d <= 5 ? '3-5 days' : ($d <= 10 ? '6-10 days' : ($d <= 20 ? '11-20 days' : '21+ days')));
    $buckets[$k]++;
}

// Products with the highest return rate (min 2 units sold)
$products = qrows("SELECT p.name, s.business_name seller, COUNT(DISTINCT oi.id) items, COUNT(DISTINCT rt.id) returns_n,
        (SELECT reason FROM returns r3 JOIN order_items o3 ON o3.id = r3.order_item_id WHERE o3.product_id = p.id GROUP BY reason ORDER BY COUNT(*) DESC LIMIT 1) top_reason
    FROM products p JOIN sellers s ON s.id = p.seller_id JOIN order_items oi ON oi.product_id = p.id AND oi.item_status <> 'cancelled'
    LEFT JOIN returns rt ON rt.order_item_id = oi.id GROUP BY p.id HAVING returns_n > 0 ORDER BY returns_n / COUNT(DISTINCT oi.id) DESC, returns_n DESC LIMIT 10");
foreach ($products as &$p) $p['rate'] = pct($p['returns_n'], $p['items']);
unset($p);

// Sellers
$sellers = qrows("SELECT s.business_name, COUNT(DISTINCT oi.id) items, COUNT(DISTINCT rt.id) returns_n, COALESCE(SUM(CASE WHEN rt.status IN ('approved','refunded') THEN oi.line_total END),0) lost
    FROM sellers s JOIN order_items oi ON oi.seller_id = s.id AND oi.item_status <> 'cancelled' LEFT JOIN returns rt ON rt.order_item_id = oi.id
    WHERE s.approval_status = 'approved' GROUP BY s.id ORDER BY COUNT(DISTINCT rt.id) / COUNT(DISTINCT oi.id) DESC");
foreach ($sellers as &$s) $s['rate'] = pct($s['returns_n'], $s['items']);
unset($s);

/* ---------- Auto-generated recommendations ---------- */
$advice = [];
$top_reason = $reasons[0]['reason'] ?? null;
$map = [
    'Damaged Product' => 'Damaged items are the top reason: ask sellers to improve packaging and add a pre-dispatch quality check.',
    'Defective Product' => 'Defective items are the top reason: tighten seller quality checks and review the worst sellers below.',
    'Wrong Product' => 'Wrong items are the top reason: ask sellers to double-check the product and variant before dispatch.',
    'Product Not as Described' => 'Items "not as described" lead: require clearer titles, specs and real photos in product listings.',
    'Size/Compatibility Issue' => 'Size or compatibility problems lead: add size guides and compatibility notes to the listings.',
    'Customer Changed Mind' => 'Change-of-mind returns lead: consider a shorter return window or a restocking charge for this reason.',
];
if ($top_reason) $advice[] = $map[$top_reason] ?? 'Review the most common return reason and add a clear note to the product page.';
if (!empty($cats) && $cats[0]['returns_n'] > 0) $advice[] = 'Category to watch: ' . $cats[0]['name'] . ' has the highest return rate (' . $cats[0]['rate'] . '%).';
$avg_rate = $return_rate;
$risky = array_filter($sellers, fn($s) => $s['returns_n'] >= 2 && $s['rate'] > max($avg_rate * 1.5, 5));
if ($risky) $advice[] = 'Sellers with a return rate far above average: ' . implode(', ', array_map(fn($s) => $s['business_name'] . ' (' . $s['rate'] . '%)', array_slice($risky, 0, 3))) . '. Consider a warning or a review.';
if ($tot['pending'] > 0) $advice[] = $tot['pending'] . ' return request(s) are still waiting for a decision. Process them to keep buyers satisfied.';
if ($lost['commission'] > 0) $advice[] = 'Returns have cost the platform ' . money($lost['commission']) . ' in commission that is no longer earned.';

include __DIR__ . '/../includes/dash-start.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <p class="text-secondary mb-0 small">A sales return is a product the buyer sends back after buying it. This page shows how often it happens, why, and what it costs.</p>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/admin/returns.php" class="btn btn-sm btn-outline-primary rounded-pill"><i class="bi bi-arrow-return-left"></i> Manage returns</a>
    <a href="?export=1" class="btn btn-sm btn-primary rounded-pill"><i class="bi bi-download"></i> Download CSV</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-3 col-6"><div class="stat-card"><div class="num"><?= $return_rate ?>%</div><div class="text-secondary small">Return rate (returned items / items sold)</div></div></div>
  <div class="col-lg-3 col-6"><div class="stat-card"><div class="num"><?= money($lost['value']) ?></div><div class="text-secondary small">Sales value returned (<?= $value_rate ?>% of sales)</div></div></div>
  <div class="col-lg-3 col-6"><div class="stat-card"><div class="num"><?= money($refunds['total']) ?></div><div class="text-secondary small">Refunds paid out</div></div></div>
  <div class="col-lg-3 col-6"><div class="stat-card"><div class="num"><?= money($lost['commission']) ?></div><div class="text-secondary small">Commission lost to returns</div></div></div>
  <div class="col-lg-3 col-6"><div class="stat-card"><div class="num"><?= (int)$tot['returns_total'] ?></div><div class="text-secondary small">Total return requests</div></div></div>
  <div class="col-lg-3 col-6"><div class="stat-card"><div class="num"><?= (int)$tot['pending'] ?></div><div class="text-secondary small">Waiting for a decision</div></div></div>
  <div class="col-lg-3 col-6"><div class="stat-card"><div class="num"><?= $approval_rate ?>%</div><div class="text-secondary small">Approval rate (of decided returns)</div></div></div>
  <div class="col-lg-3 col-6"><div class="stat-card"><div class="num"><?= $avg_days['d'] ?? 0 ?></div><div class="text-secondary small">Avg. days from purchase to return</div></div></div>
</div>

<div class="card p-3 mb-4">
  <h6 class="mb-3"><i class="bi bi-lightbulb text-warning"></i> What the data suggests</h6>
  <?php if (empty($advice)): ?><div class="small text-secondary">No returns yet, so there is nothing to analyse.</div>
  <?php else: ?><ul class="small mb-0"><?php foreach ($advice as $a): ?><li class="mb-1"><?= clean($a) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-7"><div class="card p-3 h-100">
    <div class="small text-secondary mb-2">Returns and return rate by month of purchase</div>
    <canvas id="monthChart" height="130"></canvas>
  </div></div>
  <div class="col-lg-5"><div class="card p-3 h-100">
    <div class="small text-secondary mb-2">Where returns stand now</div>
    <canvas id="statusChart" height="170"></canvas>
  </div></div>
  <div class="col-lg-6"><div class="card p-3 h-100">
    <div class="small text-secondary mb-2">Why customers return products</div>
    <canvas id="reasonChart" height="150"></canvas>
  </div></div>
  <div class="col-lg-6"><div class="card p-3 h-100">
    <div class="small text-secondary mb-2">How soon after buying they ask for a return</div>
    <canvas id="daysChart" height="150"></canvas>
  </div></div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-5"><div class="card p-3 h-100">
    <div class="small text-secondary mb-2">Return rate by category</div>
    <table class="table table-clean mb-0">
      <thead><tr><th>Category</th><th>Sold</th><th>Returned</th><th>Rate</th></tr></thead>
      <tbody><?php foreach ($cats as $c): ?>
        <tr><td><?= clean($c['name']) ?></td><td><?= (int)$c['items'] ?></td><td><?= (int)$c['returns_n'] ?></td>
        <td><span class="badge bg-<?= $c['rate'] >= 10 ? 'danger' : ($c['rate'] >= 5 ? 'warning text-dark' : 'success') ?>"><?= $c['rate'] ?>%</span></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  </div></div>
  <div class="col-lg-7"><div class="card p-3 h-100">
    <div class="small text-secondary mb-2">Products with the highest return rate</div>
    <div class="table-responsive"><table class="table table-clean mb-0">
      <thead><tr><th>Product</th><th>Seller</th><th>Sold</th><th>Returned</th><th>Rate</th><th>Main reason</th></tr></thead>
      <tbody>
      <?php if (!$products): ?><tr><td colspan="6" class="text-center text-secondary py-3">No returns yet.</td></tr>
      <?php else: foreach ($products as $p): ?>
        <tr><td><?= clean(mb_strimwidth($p['name'], 0, 34, '...')) ?></td><td class="small"><?= clean($p['seller']) ?></td><td><?= (int)$p['items'] ?></td><td><?= (int)$p['returns_n'] ?></td>
        <td><strong><?= $p['rate'] ?>%</strong></td><td class="small"><?= clean($p['top_reason'] ?? '-') ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div></div>
  <div class="col-12"><div class="card p-3">
    <div class="small text-secondary mb-2">Seller return performance (a high rate may mean quality or listing problems)</div>
    <div class="table-responsive"><table class="table table-clean mb-0">
      <thead><tr><th>Seller</th><th>Items sold</th><th>Returns</th><th>Return rate</th><th>Value lost to returns</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($sellers as $s):
          $flag = $s['returns_n'] >= 2 && $s['rate'] > max($avg_rate * 1.5, 5); ?>
        <tr><td><?= clean($s['business_name']) ?></td><td><?= (int)$s['items'] ?></td><td><?= (int)$s['returns_n'] ?></td><td><?= $s['rate'] ?>%</td><td><?= money($s['lost']) ?></td>
        <td><?= $flag ? '<span class="badge bg-danger">Needs review</span>' : '<span class="badge bg-success">Healthy</span>' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div></div>
</div>

<script>
new Chart(document.getElementById('monthChart'), { data:{ labels:<?= json_encode($m_labels) ?>, datasets:[
  { type:'bar', label:'Returns', data:<?= json_encode($m_returns) ?>, backgroundColor:'#E5484D', borderRadius:6, yAxisID:'y' },
  { type:'line', label:'Return rate %', data:<?= json_encode($m_rate) ?>, borderColor:'#4C3BCF', backgroundColor:'#4C3BCF', tension:.3, yAxisID:'y1' } ] },
  options:{ plugins:{legend:{position:'bottom'}}, scales:{ y:{beginAtZero:true,title:{display:true,text:'Returns'}}, y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},title:{display:true,text:'Rate %'}} } } });
new Chart(document.getElementById('statusChart'), { type:'bar', data:{ labels:['Pending','Approved','Refunded','Rejected'], datasets:[{ data:<?= json_encode(array_values($st)) ?>, backgroundColor:['#F5A524','#4C3BCF','#1FAA6D','#A6A5C4'], borderRadius:6 }] },
  options:{ indexAxis:'y', plugins:{legend:{display:false}} } });
new Chart(document.getElementById('reasonChart'), { type:'bar', data:{ labels:<?= json_encode($r_labels) ?>, datasets:[{ label:'Returns', data:<?= json_encode($r_data) ?>, backgroundColor:'#F5A524', borderRadius:6 }] },
  options:{ indexAxis:'y', plugins:{legend:{display:false}} } });
new Chart(document.getElementById('daysChart'), { type:'bar', data:{ labels:<?= json_encode(array_keys($buckets)) ?>, datasets:[{ label:'Returns', data:<?= json_encode(array_values($buckets)) ?>, backgroundColor:'#7A5FFF', borderRadius:6 }] },
  options:{ plugins:{legend:{display:false}} } });
</script>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
