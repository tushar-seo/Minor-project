<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('buyer');
$page_title = 'Buyer Dashboard';
$dash_title = 'Dashboard';
$sidebar_role = 'buyer';

$buyer_id = current_user()['role_id'];

function count_orders($conn, $buyer_id, $status = null) {
    if ($status) {
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM orders WHERE buyer_id=? AND status=?");
        mysqli_stmt_bind_param($stmt, 'is', $buyer_id, $status);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM orders WHERE buyer_id=?");
        mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
    }
    mysqli_stmt_execute($stmt);
    return (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
}

$total_orders = count_orders($conn, $buyer_id);
$pending_orders = count_orders($conn, $buyer_id, 'pending');
$completed_orders = count_orders($conn, $buyer_id, 'delivered');

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM returns WHERE buyer_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
mysqli_stmt_execute($stmt);
$return_requests = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];

$stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE buyer_id = ? ORDER BY created_at DESC LIMIT 5");
mysqli_stmt_bind_param($stmt, 'i', $buyer_id);
mysqli_stmt_execute($stmt);
$recent_orders = mysqli_stmt_get_result($stmt);

include __DIR__ . '/../includes/dash-start.php';
?>
<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <div class="stat-card"><div class="stat-icon kpi-1 mb-2"><i class="bi bi-receipt"></i></div>
      <div class="num"><?= $total_orders ?></div><div class="text-secondary small">Total Orders</div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card"><div class="stat-icon kpi-2 mb-2"><i class="bi bi-hourglass-split"></i></div>
      <div class="num"><?= $pending_orders ?></div><div class="text-secondary small">Pending Orders</div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card"><div class="stat-icon kpi-3 mb-2"><i class="bi bi-check-circle"></i></div>
      <div class="num"><?= $completed_orders ?></div><div class="text-secondary small">Completed Orders</div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card"><div class="stat-icon kpi-4 mb-2"><i class="bi bi-arrow-return-left"></i></div>
      <div class="num"><?= $return_requests ?></div><div class="text-secondary small">Return Requests</div></div>
  </div>
</div>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0">Recent Orders</h6>
    <a href="<?= BASE_URL ?>/buyer/orders.php" class="small">View all <i class="bi bi-arrow-right"></i></a>
  </div>
  <?php if (mysqli_num_rows($recent_orders) === 0): ?>
    <div class="empty-state"><i class="bi bi-bag"></i>No orders yet. <a href="<?= BASE_URL ?>/products.php">Start shopping</a></div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-clean">
      <thead><tr><th>Order ID</th><th>Date</th><th>Amount</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php while ($o = mysqli_fetch_assoc($recent_orders)): ?>
        <tr>
          <td><?= clean($o['order_code']) ?></td>
          <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
          <td><?= money($o['grand_total']) ?></td>
          <td><?= status_badge($o['status']) ?></td>
          <td><a href="<?= BASE_URL ?>/buyer/orders.php?view=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
