<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Analytics';
$dash_title = 'Sales & Return Analytics';
$sidebar_role = 'admin';

// A. Return Rate
$rr = mysqli_fetch_assoc(mysqli_query($conn, "
  SELECT (SELECT COUNT(*) FROM returns) AS returned, (SELECT COUNT(*) FROM order_items) AS total_items"));
$return_rate = $rr['total_items'] > 0 ? round(($rr['returned'] / $rr['total_items']) * 100, 2) : 0;

// B. Return trend - monthly (last 6 months)
$trend = mysqli_query($conn, "SELECT DATE_FORMAT(created_at,'%b %Y') m, COUNT(*) c FROM returns
  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY DATE_FORMAT(created_at,'%Y-%m')");
$trend_labels = []; $trend_data = [];
while ($r = mysqli_fetch_assoc($trend)) { $trend_labels[] = $r['m']; $trend_data[] = (int)$r['c']; }

// C. Return by reason
$reasons = mysqli_query($conn, "SELECT reason, COUNT(*) c FROM returns GROUP BY reason ORDER BY c DESC");
$reason_labels = []; $reason_data = [];
while ($r = mysqli_fetch_assoc($reasons)) { $reason_labels[] = $r['reason']; $reason_data[] = (int)$r['c']; }

// D. Return by category
$by_cat = mysqli_query($conn, "
  SELECT c.name, COUNT(rt.id) cnt FROM categories c
  LEFT JOIN products p ON p.category_id=c.id LEFT JOIN order_items oi ON oi.product_id=p.id LEFT JOIN returns rt ON rt.order_item_id=oi.id
  GROUP BY c.id ORDER BY cnt DESC");
$catr_labels = []; $catr_data = [];
while ($r = mysqli_fetch_assoc($by_cat)) { $catr_labels[] = $r['name']; $catr_data[] = (int)$r['cnt']; }

// E. Most returned products (top 10)
$most_returned = mysqli_query($conn, "
  SELECT p.name, COUNT(rt.id) c FROM returns rt JOIN order_items oi ON oi.id=rt.order_item_id JOIN products p ON p.id=oi.product_id
  GROUP BY p.id ORDER BY c DESC LIMIT 10");

// F. Seller return performance
$seller_perf = mysqli_query($conn, "
  SELECT s.business_name,
    (SELECT COUNT(*) FROM order_items WHERE seller_id=s.id) total_orders,
    (SELECT COUNT(*) FROM returns rt JOIN order_items oi ON oi.id=rt.order_item_id WHERE oi.seller_id=s.id) total_returns
  FROM sellers s WHERE s.approval_status='approved'");
$seller_perf_rows = mysqli_fetch_all($seller_perf, MYSQLI_ASSOC);
foreach ($seller_perf_rows as &$sp) { $sp['rate'] = $sp['total_orders'] > 0 ? round(($sp['total_returns']/$sp['total_orders'])*100,1) : 0; }
unset($sp);
usort($seller_perf_rows, fn($a,$b) => $b['rate'] <=> $a['rate']);

// G. Refund analytics
$refund_kpi = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) total FROM refunds WHERE status='processed'"));
$refund_monthly = mysqli_query($conn, "SELECT DATE_FORMAT(processed_at,'%b %Y') m, SUM(amount) total FROM refunds WHERE status='processed' AND processed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(processed_at,'%Y-%m') ORDER BY DATE_FORMAT(processed_at,'%Y-%m')");
$refund_labels = []; $refund_data = [];
while ($r = mysqli_fetch_assoc($refund_monthly)) { $refund_labels[] = $r['m']; $refund_data[] = (float)$r['total']; }

// H. Sales vs Returns comparison (monthly)
$sales_vs_returns = mysqli_query($conn, "
  SELECT DATE_FORMAT(oi.created_at,'%b %Y') m,
    SUM(CASE WHEN oi.item_status NOT IN ('cancelled') THEN oi.line_total ELSE 0 END) sales,
    SUM(CASE WHEN oi.item_status IN ('returned','refunded') THEN oi.line_total ELSE 0 END) returned
  FROM order_items oi WHERE oi.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
  GROUP BY DATE_FORMAT(oi.created_at,'%Y-%m') ORDER BY DATE_FORMAT(oi.created_at,'%Y-%m')");
$svr_labels = []; $svr_sales = []; $svr_returns = [];
while ($r = mysqli_fetch_assoc($sales_vs_returns)) { $svr_labels[] = $r['m']; $svr_sales[] = (float)$r['sales']; $svr_returns[] = (float)$r['returned']; }

// I. Key Insights (computed, not hard-coded)
$insight_top_reason = mysqli_fetch_assoc(mysqli_query($conn, "SELECT reason, COUNT(*) c FROM returns GROUP BY reason ORDER BY c DESC LIMIT 1"));
$insight_top_category = mysqli_fetch_assoc(mysqli_query($conn, "
  SELECT c.name, COUNT(rt.id) cnt FROM categories c JOIN products p ON p.category_id=c.id JOIN order_items oi ON oi.product_id=p.id JOIN returns rt ON rt.order_item_id=oi.id
  GROUP BY c.id ORDER BY cnt DESC LIMIT 1"));
$insight_top_product = mysqli_fetch_assoc(mysqli_query($conn, "
  SELECT p.name, COUNT(rt.id) c FROM returns rt JOIN order_items oi ON oi.id=rt.order_item_id JOIN products p ON p.id=oi.product_id GROUP BY p.id ORDER BY c DESC LIMIT 1"));
$insight_top_seller = $seller_perf_rows[0] ?? null;
$insight_top_month = mysqli_fetch_assoc(mysqli_query($conn, "SELECT DATE_FORMAT(created_at,'%b %Y') m, COUNT(*) c FROM returns GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY c DESC LIMIT 1"));

include __DIR__ . '/../includes/dash-start.php';
?>
<div class="alert alert-primary small d-flex justify-content-between align-items-center"><span><i class="bi bi-bar-chart-line"></i> Want the full return story: reasons, cost, risky sellers and advice?</span><a class="btn btn-sm btn-primary rounded-pill" href="<?= BASE_URL ?>/admin/return-analytics.php">Open Sales Return Analytics</a></div>
<div class="row g-3 mb-4">
  <div class="col-md-3 col-6"><div class="stat-card"><div class="num"><?= $return_rate ?>%</div><div class="text-secondary small">Overall Return Rate</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="num"><?= $rr['returned'] ?></div><div class="text-secondary small">Total Returned Items</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="num"><?= money($refund_kpi['total']) ?></div><div class="text-secondary small">Total Refunds Processed</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="num"><?= $rr['total_items'] ?></div><div class="text-secondary small">Total Order Items</div></div></div>
</div>

<!-- Key Insights -->
<div class="card p-3 mb-4">
  <h6 class="mb-3"><i class="bi bi-lightbulb text-warning"></i> Key Insights</h6>
  <div class="row g-3 small">
    <div class="col-md-6 col-lg-4"><i class="bi bi-tag text-primary"></i> Most returned category: <strong><?= clean($insight_top_category['name'] ?? 'N/A') ?></strong></div>
    <div class="col-md-6 col-lg-4"><i class="bi bi-chat-dots text-primary"></i> Most common reason: <strong><?= clean($insight_top_reason['reason'] ?? 'N/A') ?></strong></div>
    <div class="col-md-6 col-lg-4"><i class="bi bi-box text-primary"></i> Highest-return product: <strong><?= clean($insight_top_product['name'] ?? 'N/A') ?></strong></div>
    <div class="col-md-6 col-lg-4"><i class="bi bi-shop text-primary"></i> Highest return-rate seller: <strong><?= clean($insight_top_seller['business_name'] ?? 'N/A') ?> (<?= $insight_top_seller['rate'] ?? 0 ?>%)</strong></div>
    <div class="col-md-6 col-lg-4"><i class="bi bi-calendar text-primary"></i> Month with highest returns: <strong><?= clean($insight_top_month['m'] ?? 'N/A') ?></strong></div>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="small text-secondary mb-2">Return Trend (Monthly)</div>
      <canvas id="trendChart" height="130"></canvas>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="small text-secondary mb-2">Sales vs Returns</div>
      <canvas id="svrChart" height="130"></canvas>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="small text-secondary mb-2">Return by Reason</div>
      <canvas id="reasonChart" height="140"></canvas>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="small text-secondary mb-2">Return by Category</div>
      <canvas id="catReturnChart" height="140"></canvas>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="small text-secondary mb-2">Most Returned Products (Top 10)</div>
      <table class="table table-clean mb-0">
        <thead><tr><th>Product</th><th>Returns</th></tr></thead>
        <tbody>
        <?php if (mysqli_num_rows($most_returned)===0): ?><tr><td colspan="2" class="text-secondary text-center py-3">No data yet.</td></tr>
        <?php else: while ($p = mysqli_fetch_assoc($most_returned)): ?>
          <tr><td><?= clean($p['name']) ?></td><td><?= $p['c'] ?></td></tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="small text-secondary mb-2">Seller Return Performance</div>
      <table class="table table-clean mb-0">
        <thead><tr><th>Seller</th><th>Orders</th><th>Returns</th><th>Rate</th></tr></thead>
        <tbody>
        <?php if (empty($seller_perf_rows)): ?><tr><td colspan="4" class="text-secondary text-center py-3">No data yet.</td></tr>
        <?php else: foreach (array_slice($seller_perf_rows,0,8) as $sp): ?>
          <tr><td><?= clean($sp['business_name']) ?></td><td><?= $sp['total_orders'] ?></td><td><?= $sp['total_returns'] ?></td><td><?= $sp['rate'] ?>%</td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-12">
    <div class="card p-3">
      <div class="small text-secondary mb-2">Monthly Refund Amount</div>
      <canvas id="refundChart" height="70"></canvas>
    </div>
  </div>
</div>

<script>
new Chart(document.getElementById('trendChart'), { type:'line', data:{labels:<?= json_encode($trend_labels) ?>,datasets:[{label:'Returns',data:<?= json_encode($trend_data) ?>,borderColor:'#E5484D',backgroundColor:'rgba(229,72,77,.12)',fill:true,tension:.3}]}, options:{plugins:{legend:{display:false}}} });
new Chart(document.getElementById('svrChart'), { type:'bar', data:{labels:<?= json_encode($svr_labels) ?>,datasets:[{label:'Sales',data:<?= json_encode($svr_sales) ?>,backgroundColor:'#4C3BCF',borderRadius:6},{label:'Returned Value',data:<?= json_encode($svr_returns) ?>,backgroundColor:'#E5484D',borderRadius:6}]}, options:{plugins:{legend:{position:'bottom'}}} });
new Chart(document.getElementById('reasonChart'), { type:'pie', data:{labels:<?= json_encode($reason_labels) ?>,datasets:[{data:<?= json_encode($reason_data) ?>,backgroundColor:['#4C3BCF','#F5A524','#1FAA6D','#E5484D','#7A5FFF','#14162B','#A6A5C4']}]}, options:{plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:10}}}}} });
new Chart(document.getElementById('catReturnChart'), { type:'bar', data:{labels:<?= json_encode($catr_labels) ?>,datasets:[{label:'Returns',data:<?= json_encode($catr_data) ?>,backgroundColor:'#F5A524',borderRadius:6}]}, options:{plugins:{legend:{display:false}}} });
new Chart(document.getElementById('refundChart'), { type:'line', data:{labels:<?= json_encode($refund_labels) ?>,datasets:[{label:'Refunds',data:<?= json_encode($refund_data) ?>,borderColor:'#1FAA6D',backgroundColor:'rgba(31,170,109,.12)',fill:true,tension:.3}]}, options:{plugins:{legend:{display:false}}} });
</script>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
