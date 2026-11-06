<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Seller Management';
$dash_title = 'Seller Management';
$sidebar_role = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $seller_id = (int)$_POST['seller_id'];
    $new_status = $_POST['new_status'];
    if (in_array($new_status, ['approved','rejected','suspended','pending'], true)) {
        $stmt = mysqli_prepare($conn, "UPDATE sellers SET approval_status = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $new_status, $seller_id);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn, "SELECT u.id, u.full_name FROM users u JOIN sellers s ON s.user_id=u.id WHERE s.id=?");
        mysqli_stmt_bind_param($stmt, 'i', $seller_id);
        mysqli_stmt_execute($stmt);
        $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($u) {
            $label = ucwords($new_status);
            notify($u['id'], "Seller Application $label", "Your seller application has been $new_status by admin.");
        }
        set_flash('success', 'Seller status updated.');
    }
    redirect(BASE_URL . '/admin/sellers.php');
}

$status_filter = $_GET['status'] ?? '';
$sql = "SELECT s.*, u.full_name, u.email, u.phone, u.created_at,
        (SELECT COUNT(*) FROM products p WHERE p.seller_id=s.id) AS product_count,
        (SELECT COALESCE(SUM(oi.line_total),0) FROM order_items oi WHERE oi.seller_id=s.id AND oi.item_status != 'cancelled') AS total_sales
        FROM sellers s JOIN users u ON u.id = s.user_id";
if ($status_filter) $sql .= " WHERE s.approval_status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
$sql .= " ORDER BY u.created_at DESC";
$sellers = mysqli_query($conn, $sql);

include __DIR__ . '/../includes/dash-start.php';
?>
<div class="d-flex gap-2 mb-3 flex-wrap">
  <a href="?" class="btn btn-sm <?= $status_filter==''?'btn-primary':'btn-outline-secondary' ?> rounded-pill">All</a>
  <?php foreach (['pending','approved','rejected','suspended'] as $s): ?>
    <a href="?status=<?= $s ?>" class="btn btn-sm <?= $status_filter==$s?'btn-primary':'btn-outline-secondary' ?> rounded-pill"><?= ucfirst($s) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-clean mb-0">
      <thead><tr><th>Business</th><th>Owner</th><th>Contact</th><th>Products</th><th>Total Sales</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (mysqli_num_rows($sellers) === 0): ?>
        <tr><td colspan="7" class="text-center text-secondary py-4">No sellers found.</td></tr>
      <?php else: while ($s = mysqli_fetch_assoc($sellers)): ?>
        <tr>
          <td><?= clean($s['business_name']) ?><br><span class="small text-secondary"><?= clean(mb_strimwidth($s['business_info'] ?? '', 0, 60, '...')) ?></span></td>
          <td><?= clean($s['full_name']) ?></td>
          <td><?= clean($s['email']) ?><br><span class="small text-secondary"><?= clean($s['phone']) ?></span></td>
          <td><a href="<?= BASE_URL ?>/admin/products.php?seller=<?= $s['id'] ?>"><?= $s['product_count'] ?></a></td>
          <td><?= money($s['total_sales']) ?></td>
          <td><?= status_badge($s['approval_status']) ?></td>
          <td>
            <form method="POST" class="d-flex gap-1 flex-wrap">
              <?= csrf_field() ?>
              <input type="hidden" name="seller_id" value="<?= $s['id'] ?>">
              <?php if ($s['approval_status'] !== 'approved'): ?><button name="new_status" value="approved" class="btn btn-sm btn-outline-success">Approve</button><?php endif; ?>
              <?php if ($s['approval_status'] !== 'rejected'): ?><button name="new_status" value="rejected" class="btn btn-sm btn-outline-danger">Reject</button><?php endif; ?>
              <?php if ($s['approval_status'] === 'approved'): ?><button name="new_status" value="suspended" class="btn btn-sm btn-outline-warning">Suspend</button><?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endwhile; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
