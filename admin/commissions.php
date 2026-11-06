<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Commission Management';
$dash_title = 'Commission Management';
$sidebar_role = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['global_commission'])) {
        set_setting('global_commission_percentage', (float)$_POST['global_commission']);
        set_flash('success', 'Global commission percentage updated.');
    } elseif (isset($_POST['category_id'])) {
        $cid = (int)$_POST['category_id'];
        $pct = $_POST['commission_percentage'] === '' ? null : (float)$_POST['commission_percentage'];
        $stmt = mysqli_prepare($conn, "UPDATE categories SET commission_percentage = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'di', $pct, $cid);
        mysqli_stmt_execute($stmt);
        set_flash('success', 'Category commission updated.');
    } elseif (isset($_POST['seller_id'])) {
        $sid = (int)$_POST['seller_id'];
        $pct = $_POST['seller_commission'] === '' ? null : (float)$_POST['seller_commission'];
        $stmt = mysqli_prepare($conn, "UPDATE sellers SET commission_override = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'di', $pct, $sid);
        mysqli_stmt_execute($stmt);
        set_flash('success', 'Seller commission override updated.');
    }
    redirect(BASE_URL . '/admin/commissions.php');
}

$global_pct = get_setting('global_commission_percentage', 5.00);
$categories = mysqli_query($conn, "SELECT * FROM categories ORDER BY name");
$sellers = mysqli_query($conn, "SELECT s.*, u.full_name FROM sellers s JOIN users u ON u.id=s.user_id WHERE s.approval_status='approved' ORDER BY s.business_name");

$total_commission = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(commission_amount),0) c FROM order_items WHERE item_status != 'cancelled'"))['c'];

$by_seller = mysqli_query($conn, "
  SELECT s.business_name, COALESCE(SUM(oi.commission_amount),0) total FROM sellers s
  LEFT JOIN order_items oi ON oi.seller_id = s.id AND oi.item_status != 'cancelled'
  GROUP BY s.id ORDER BY total DESC LIMIT 8");
$seller_labels = []; $seller_data = [];
while ($r = mysqli_fetch_assoc($by_seller)) { $seller_labels[] = $r['business_name']; $seller_data[] = (float)$r['total']; }

$by_month = mysqli_query($conn, "
  SELECT DATE_FORMAT(created_at,'%b %Y') m, SUM(commission_amount) total FROM order_items
  WHERE item_status != 'cancelled' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
  GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY DATE_FORMAT(created_at,'%Y-%m')");
$month_labels = []; $month_data = [];
while ($r = mysqli_fetch_assoc($by_month)) { $month_labels[] = $r['m']; $month_data[] = (float)$r['total']; }

$by_category = mysqli_query($conn, "
  SELECT c.name, COALESCE(SUM(oi.commission_amount),0) total FROM categories c
  LEFT JOIN products p ON p.category_id = c.id
  LEFT JOIN order_items oi ON oi.product_id = p.id AND oi.item_status != 'cancelled'
  GROUP BY c.id ORDER BY total DESC");
$cat_labels = []; $cat_data = [];
while ($r = mysqli_fetch_assoc($by_category)) { $cat_labels[] = $r['name']; $cat_data[] = (float)$r['total']; }

include __DIR__ . '/../includes/dash-start.php';
?>
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card"><div class="num"><?= money($total_commission) ?></div><div class="text-secondary small">Total Platform Commission</div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="num"><?= number_format($global_pct,2) ?>%</div><div class="text-secondary small">Current Global Rate</div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="num"><?= mysqli_num_rows($categories) ?></div><div class="text-secondary small">Configured Categories</div></div></div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-4">
    <div class="card p-3">
      <h6 class="mb-3">Global Commission Rate</h6>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="input-group mb-2">
          <input type="number" step="0.01" min="0" max="90" name="global_commission" class="form-control" value="<?= clean($global_pct) ?>">
          <span class="input-group-text">%</span>
        </div>
        <button class="btn btn-primary btn-sm rounded-pill px-3">Update Global Rate</button>
      </form>
      <p class="small text-secondary mt-2 mb-0">Applies to all orders unless a category or seller-specific rate is set.</p>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card p-3">
      <h6 class="mb-3">Category-Specific Commission</h6>
      <div class="table-responsive">
        <table class="table table-clean mb-0">
          <thead><tr><th>Category</th><th>Rate</th><th></th></tr></thead>
          <tbody>
          <?php mysqli_data_seek($categories, 0); while ($c = mysqli_fetch_assoc($categories)): ?>
            <tr>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                <td class="align-middle"><?= clean($c['name']) ?></td>
                <td><div class="input-group input-group-sm" style="max-width:140px;"><input type="number" step="0.01" name="commission_percentage" class="form-control" value="<?= clean($c['commission_percentage'] ?? '') ?>" placeholder="Global"><span class="input-group-text">%</span></div></td>
                <td><button class="btn btn-sm btn-outline-primary">Save</button></td>
              </form>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card p-3 mb-4">
  <h6 class="mb-3">Seller-Specific Commission Override</h6>
  <div class="table-responsive">
    <table class="table table-clean mb-0">
      <thead><tr><th>Seller</th><th>Rate</th><th></th></tr></thead>
      <tbody>
      <?php mysqli_data_seek($sellers, 0); while ($s = mysqli_fetch_assoc($sellers)): ?>
        <tr>
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="seller_id" value="<?= $s['id'] ?>">
            <td class="align-middle"><?= clean($s['business_name']) ?> <span class="text-secondary small">(<?= clean($s['full_name']) ?>)</span></td>
            <td><div class="input-group input-group-sm" style="max-width:140px;"><input type="number" step="0.01" name="seller_commission" class="form-control" value="<?= clean($s['commission_override'] ?? '') ?>" placeholder="Default"><span class="input-group-text">%</span></div></td>
            <td><button class="btn btn-sm btn-outline-primary">Save</button></td>
          </form>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="small text-secondary mb-2">Commission by Seller</div>
      <canvas id="sellerChart" height="140"></canvas>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="small text-secondary mb-2">Commission by Category</div>
      <canvas id="categoryChart" height="140"></canvas>
    </div>
  </div>
  <div class="col-lg-12">
    <div class="card p-3">
      <div class="small text-secondary mb-2">Commission by Month (Last 6 Months)</div>
      <canvas id="monthChart" height="80"></canvas>
    </div>
  </div>
</div>

<script>
new Chart(document.getElementById('sellerChart'), { type:'bar', data:{ labels: <?= json_encode($seller_labels) ?>, datasets:[{label:'Commission',data:<?= json_encode($seller_data) ?>,backgroundColor:'#4C3BCF',borderRadius:6}]}, options:{indexAxis:'y',plugins:{legend:{display:false}}} });
new Chart(document.getElementById('categoryChart'), { type:'doughnut', data:{ labels: <?= json_encode($cat_labels) ?>, datasets:[{data:<?= json_encode($cat_data) ?>,backgroundColor:['#4C3BCF','#F5A524','#1FAA6D','#E5484D','#7A5FFF']}]}, options:{plugins:{legend:{position:'bottom'}}} });
new Chart(document.getElementById('monthChart'), { type:'line', data:{ labels: <?= json_encode($month_labels) ?>, datasets:[{label:'Commission',data:<?= json_encode($month_data) ?>,borderColor:'#F5A524',backgroundColor:'rgba(245,165,36,.15)',fill:true,tension:.3}]}, options:{plugins:{legend:{display:false}}} });
</script>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
