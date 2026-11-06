<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'User Management';
$dash_title = 'User Management';
$sidebar_role = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid = (int)$_POST['user_id'];
    $new_status = $_POST['new_status'];
    if (in_array($new_status, ['active','inactive','suspended'], true) && $uid !== current_user()['id']) {
        $stmt = mysqli_prepare($conn, "UPDATE users SET status = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $new_status, $uid);
        mysqli_stmt_execute($stmt);
        notify($uid, 'Account Status Updated', "Your account status was changed to \"$new_status\" by admin.");
        set_flash('success', 'User status updated.');
    }
    redirect(BASE_URL . '/admin/users.php?role=' . ($_POST['role_tab'] ?? 'buyer'));
}

$role_tab = $_GET['role'] ?? 'buyer';
if (!in_array($role_tab, ['buyer','seller'], true)) $role_tab = 'buyer';
$search = trim($_GET['search'] ?? '');

$sql = "SELECT u.*" . ($role_tab==='seller' ? ", s.business_name, s.approval_status, s.id AS seller_id" : ", b.id AS buyer_id") . "
        FROM users u " . ($role_tab==='seller' ? "JOIN sellers s ON s.user_id=u.id" : "JOIN buyers b ON b.user_id=u.id") . "
        WHERE u.role = '" . $role_tab . "'";
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (u.full_name LIKE '%$s%' OR u.email LIKE '%$s%')";
}
$sql .= " ORDER BY u.created_at DESC";
$users = mysqli_query($conn, $sql);

include __DIR__ . '/../includes/dash-start.php';
?>
<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= $role_tab=='buyer'?'active':'' ?>" href="?role=buyer">Buyers</a></li>
  <li class="nav-item"><a class="nav-link <?= $role_tab=='seller'?'active':'' ?>" href="?role=seller">Sellers</a></li>
</ul>

<form method="GET" class="mb-3 d-flex gap-2" style="max-width:360px;">
  <input type="hidden" name="role" value="<?= clean($role_tab) ?>">
  <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name or email" value="<?= clean($search) ?>">
  <button class="btn btn-sm btn-primary">Search</button>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-clean mb-0">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><?php if ($role_tab==='seller'): ?><th>Business</th><th>Approval</th><?php endif; ?><th>Status</th><th>Joined</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (mysqli_num_rows($users) === 0): ?>
        <tr><td colspan="8" class="text-center text-secondary py-4">No users found.</td></tr>
      <?php else: while ($u = mysqli_fetch_assoc($users)): ?>
        <tr>
          <td><?= clean($u['full_name']) ?></td>
          <td><?= clean($u['email']) ?></td>
          <td><?= clean($u['phone']) ?></td>
          <?php if ($role_tab==='seller'): ?>
            <td><?= clean($u['business_name']) ?></td>
            <td><?= status_badge($u['approval_status']) ?></td>
          <?php endif; ?>
          <td><?= status_badge($u['status']) ?></td>
          <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
          <td>
            <form method="POST" class="d-flex gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <input type="hidden" name="role_tab" value="<?= clean($role_tab) ?>">
              <?php if ($u['status'] === 'active'): ?>
                <button name="new_status" value="inactive" class="btn btn-sm btn-outline-secondary">Deactivate</button>
                <button name="new_status" value="suspended" class="btn btn-sm btn-outline-danger">Suspend</button>
              <?php else: ?>
                <button name="new_status" value="active" class="btn btn-sm btn-outline-success">Activate</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endwhile; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
