<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Admin Dashboard';
$dash_title = 'Dashboard';
$sidebar_role = 'admin';

$kpi = mysqli_fetch_assoc(mysqli_query($conn, "
  SELECT
    (SELECT COUNT(*) FROM users) AS total_users,
    (SELECT COUNT(*) FROM buyers) AS total_buyers,
    (SELECT COUNT(*) FROM sellers) AS total_sellers,
    (SELECT COUNT(*) FROM products) AS total_products,
    (SELECT COUNT(*) FROM orders) AS total_orders,
    (SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE status != 'cancelled') AS total_sales,
    (SELECT COALESCE(SUM(commission_amount),0) FROM order_items WHERE item_status != 'cancelled') AS total_commission,
    (SELECT COUNT(*) FROM returns) AS total_returns,
    (SELECT COALESCE(SUM(amount),0) FROM refunds WHERE status='processed') AS total_refunds
"));

$recent_orders = mysqli_query($conn, "SELECT o.*, u.full_name AS buyer_name FROM orders o JOIN buyers b ON b.id=o.buyer_id JOIN users u ON u.id=b.user_id ORDER BY o.created_at DESC LIMIT 5");
$recent_sellers = mysqli_query($conn, "SELECT s.*, u.full_name, u.email, u.created_at FROM sellers s JOIN users u ON u.id=s.user_id ORDER BY u.created_at DESC LIMIT 5");
$recent_products = mysqli_query($conn, "SELECT p.*, s.business_name FROM products p JOIN sellers s ON s.id=p.seller_id ORDER BY p.created_at DESC LIMIT 5");
$recent_returns = mysqli_query($conn, "SELECT rt.*, p.name AS product_name FROM returns rt JOIN order_items oi ON oi.id=rt.order_item_id JOIN products p ON p.id=oi.product_id ORDER BY rt.created_at DESC LIMIT 5");
$low_stock = mysqli_query($conn, "SELECT p.name, p.stock_quantity, s.business_name FROM products p JOIN sellers s ON s.id=p.seller_id WHERE p.status IN ('approved','out_of_stock') AND p.stock_quantity <= p.low_stock_threshold ORDER BY p.stock_quantity ASC LIMIT 8");
$recent_reviews = mysqli_query($conn, "SELECT r.rating, r.review_text, p.name AS product_name, u.full_name FROM reviews r JOIN products p ON p.id=r.product_id JOIN buyers b ON b.id=r.buyer_id JOIN users u ON u.id=b.user_id ORDER BY r.created_at DESC LIMIT 5");
$pending_seller_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM sellers WHERE approval_status='pending'"))['c'];
$pending_product_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM products WHERE status='pending'"))['c'];
$pending_return_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM returns WHERE status='pending'"))['c'];

include __DIR__ . '/../includes/dash-start.php';
?>
<?php if ($pending_seller_count || $pending_product_count || $pending_return_count): ?>
<div class="alert alert-info d-flex flex-wrap gap-3 align-items-center">
  <i class="bi bi-bell"></i>
  <?php if ($pending_seller_count): ?><a href="<?= BASE_URL ?>/admin/sellers.php?status=pending"><?= $pending_seller_count ?> seller(s) awaiting approval</a><?php endif; ?>
  <?php if ($pending_product_count): ?><a href="<?= BASE_URL ?>/admin/products.php?status=pending"><?= $pending_product_count ?> product(s) awaiting approval</a><?php endif; ?>
  <?php if ($pending_return_count): ?><a href="<?= BASE_URL ?>/admin/returns.php?status=pending"><?= $pending_return_count ?> return(s) awaiting review</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-icon kpi-1 mb-2"><i class="bi bi-people"></i></div><div class="num"><?= $kpi['total_users'] ?></div><div class="text-secondary small">Total Users</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-icon kpi-2 mb-2"><i class="bi bi-person"></i></div><div class="num"><?= $kpi['total_buyers'] ?></div><div class="text-secondary small">Total Buyers</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-icon kpi-3 mb-2"><i class="bi bi-shop"></i></div><div class="num"><?= $kpi['total_sellers'] ?></div><div class="text-secondary small">Total Sellers</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-icon kpi-4 mb-2"><i class="bi bi-box-seam"></i></div><div class="num"><?= $kpi['total_products'] ?></div><div class="text-secondary small">Total Products</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-icon kpi-1 mb-2"><i class="bi bi-receipt"></i></div><div class="num"><?= $kpi['total_orders'] ?></div><div class="text-secondary small">Total Orders</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-icon kpi-3 mb-2"><i class="bi bi-graph-up"></i></div><div class="num"><?= money($kpi['total_sales']) ?></div><div class="text-secondary small">Total Sales</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-icon kpi-2 mb-2"><i class="bi bi-percent"></i></div><div class="num"><?= money($kpi['total_commission']) ?></div><div class="text-secondary small">Total Commission</div></div></div>
  <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-icon kpi-4 mb-2"><i class="bi bi-arrow-return-left"></i></div><div class="num"><?= $kpi['total_returns'] ?></div><div class="text-secondary small">Total Returns</div></div></div>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between mb-3"><h6 class="mb-0">Recent Orders</h6><a href="<?= BASE_URL ?>/admin/orders.php" class="small">View all</a></div>
      <?php if (mysqli_num_rows($recent_orders)===0): ?><p class="text-secondary small">No orders yet.</p><?php else: ?>
      <table class="table table-clean mb-0 small">
        <tbody>
        <?php while($o = mysqli_fetch_assoc($recent_orders)): ?>
          <tr><td><?= clean($o['order_code']) ?></td><td><?= clean($o['buyer_name']) ?></td><td><?= money($o['grand_total']) ?></td><td><?= status_badge($o['status']) ?></td></tr>
        <?php endwhile; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between mb-3"><h6 class="mb-0">Recent Seller Registrations</h6><a href="<?= BASE_URL ?>/admin/sellers.php" class="small">View all</a></div>
      <?php if (mysqli_num_rows($recent_sellers)===0): ?><p class="text-secondary small">No sellers yet.</p><?php else: ?>
      <table class="table table-clean mb-0 small">
        <tbody>
        <?php while($s = mysqli_fetch_assoc($recent_sellers)): ?>
          <tr><td><?= clean($s['business_name']) ?></td><td><?= clean($s['full_name']) ?></td><td><?= status_badge($s['approval_status']) ?></td></tr>
        <?php endwhile; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between mb-3"><h6 class="mb-0">Recent Product Submissions</h6><a href="<?= BASE_URL ?>/admin/products.php" class="small">View all</a></div>
      <?php if (mysqli_num_rows($recent_products)===0): ?><p class="text-secondary small">No products yet.</p><?php else: ?>
      <table class="table table-clean mb-0 small">
        <tbody>
        <?php while($p = mysqli_fetch_assoc($recent_products)): ?>
          <tr><td><?= clean($p['name']) ?></td><td><?= clean($p['business_name']) ?></td><td><?= status_badge($p['status']) ?></td></tr>
        <?php endwhile; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between mb-3"><h6 class="mb-0">Recent Return Requests</h6><a href="<?= BASE_URL ?>/admin/returns.php" class="small">View all</a></div>
      <?php if (mysqli_num_rows($recent_returns)===0): ?><p class="text-secondary small">No returns yet.</p><?php else: ?>
      <table class="table table-clean mb-0 small">
        <tbody>
        <?php while($r = mysqli_fetch_assoc($recent_returns)): ?>
          <tr><td><?= clean($r['product_name']) ?></td><td><?= clean($r['reason']) ?></td><td><?= status_badge($r['status']) ?></td></tr>
        <?php endwhile; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (mysqli_num_rows($low_stock) > 0): ?>
<div class="alert alert-warning mt-4 mb-0">
  <strong><i class="bi bi-exclamation-triangle"></i> Low / out of stock:</strong>
  <?php $ls = []; while ($l = mysqli_fetch_assoc($low_stock)) $ls[] = $l['name'] . ' (' . $l['stock_quantity'] . ' left, ' . $l['business_name'] . ')'; echo clean(implode(' | ', $ls)); ?>
</div>
<?php endif; ?>

<div class="card p-3 mt-4">
  <h6 class="mb-3">Recent Reviews</h6>
  <?php if (mysqli_num_rows($recent_reviews) === 0): ?><p class="text-secondary small mb-0">No reviews yet.</p><?php else: while ($rv = mysqli_fetch_assoc($recent_reviews)): ?>
    <div class="border-top py-2 small">
      <span class="stars"><?php for ($i = 0; $i < $rv['rating']; $i++) echo '<i class="bi bi-star-fill"></i>'; ?></span>
      <strong><?= clean($rv['product_name']) ?></strong> &mdash; <?= clean($rv['full_name']) ?>: <span class="text-secondary"><?= clean($rv['review_text']) ?></span>
    </div>
  <?php endwhile; endif; ?>
</div>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
