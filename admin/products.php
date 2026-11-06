<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Product Management';
$dash_title = 'Product Management';
$sidebar_role = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['add_category'])) {
        $name = trim($_POST['category_name'] ?? '');
        $desc = trim($_POST['category_description'] ?? '');
        $pct  = $_POST['category_commission'] !== '' ? (float)$_POST['category_commission'] : null;
        if ($name !== '') {
            $stmt = mysqli_prepare($conn, "INSERT INTO categories (name, description, commission_percentage) VALUES (?,?,?)");
            mysqli_stmt_bind_param($stmt, 'ssd', $name, $desc, $pct);
            mysqli_stmt_execute($stmt);
            set_flash('success', 'Category added.');
        }
    } elseif (isset($_POST['delete_category_id'])) {
        $cid = (int)$_POST['delete_category_id'];
        mysqli_query($conn, "DELETE FROM categories WHERE id = $cid");
        set_flash('success', 'Category deleted (if it had no products).');
    } elseif (isset($_POST['product_id'])) {
        $pid = (int)$_POST['product_id'];
        $new_status = $_POST['new_status'];
        if (in_array($new_status, ['approved','rejected'], true)) {
            $stmt = mysqli_prepare($conn, "UPDATE products SET status = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'si', $new_status, $pid);
            mysqli_stmt_execute($stmt);

            $stmt = mysqli_prepare($conn, "SELECT u.id, p.name FROM users u JOIN sellers s ON s.user_id=u.id JOIN products p ON p.seller_id=s.id WHERE p.id=?");
            mysqli_stmt_bind_param($stmt, 'i', $pid);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if ($row) notify($row['id'], "Product " . ucfirst($new_status), "Your product \"{$row['name']}\" has been $new_status.");
            set_flash('success', 'Product status updated.');
        }
    } elseif (isset($_POST['delete_product_id'])) {
        mysqli_query($conn, "DELETE FROM products WHERE id = " . (int)$_POST['delete_product_id']);
        set_flash('success', 'Product deleted.');
    }
    redirect(BASE_URL . '/admin/products.php' . (isset($_GET['status']) ? '?status='.clean($_GET['status']) : ''));
}

$status_filter = $_GET['status'] ?? '';
$seller_filter = (int)($_GET['seller'] ?? 0);

$sql = "SELECT p.*, s.business_name, c.name AS category_name FROM products p
        JOIN sellers s ON s.id = p.seller_id JOIN categories c ON c.id = p.category_id WHERE 1=1";
if ($status_filter) $sql .= " AND p.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
if ($seller_filter) $sql .= " AND p.seller_id = $seller_filter";
$sql .= " ORDER BY p.created_at DESC";
$products = mysqli_query($conn, $sql);

$categories = mysqli_query($conn, "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) AS product_count FROM categories c ORDER BY c.name");

include __DIR__ . '/../includes/dash-start.php';
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-products">Products</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-categories">Categories</a></li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="tab-products">
  <div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="?" class="btn btn-sm <?= $status_filter==''?'btn-primary':'btn-outline-secondary' ?> rounded-pill">All</a>
    <?php foreach (['pending','approved','rejected','out_of_stock'] as $s): ?>
      <a href="?status=<?= $s ?>" class="btn btn-sm <?= $status_filter==$s?'btn-primary':'btn-outline-secondary' ?> rounded-pill"><?= ucwords(str_replace('_',' ',$s)) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-clean mb-0">
        <thead><tr><th>Product</th><th>Seller</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (mysqli_num_rows($products) === 0): ?>
          <tr><td colspan="7" class="text-center text-secondary py-4">No products found.</td></tr>
        <?php else: while ($p = mysqli_fetch_assoc($products)): ?>
          <tr>
            <td><?= clean($p['name']) ?></td>
            <td><?= clean($p['business_name']) ?></td>
            <td><?= clean($p['category_name']) ?></td>
            <td><?= money($p['price']) ?></td>
            <td><?= $p['stock_quantity'] ?></td>
            <td><?= status_badge($p['status']) ?></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <form method="POST"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                  <?php if ($p['status'] !== 'approved'): ?><button name="new_status" value="approved" class="btn btn-sm btn-outline-success">Approve</button><?php endif; ?>
                  <?php if ($p['status'] !== 'rejected'): ?><button name="new_status" value="rejected" class="btn btn-sm btn-outline-danger">Reject</button><?php endif; ?>
                </form>
                <form method="POST" data-confirm="Delete this product permanently?"><?= csrf_field() ?><input type="hidden" name="delete_product_id" value="<?= $p['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-trash"></i></button></form>
              </div>
            </td>
          </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="tab-pane fade" id="tab-categories">
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card p-3">
        <h6 class="mb-3">Add Category</h6>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="add_category" value="1">
          <div class="mb-2"><label class="form-label small">Name</label><input class="form-control form-control-sm" name="category_name" required></div>
          <div class="mb-2"><label class="form-label small">Description</label><input class="form-control form-control-sm" name="category_description"></div>
          <div class="mb-3"><label class="form-label small">Commission % (optional override)</label><input type="number" step="0.01" class="form-control form-control-sm" name="category_commission" placeholder="Uses global default if blank"></div>
          <button class="btn btn-primary btn-sm rounded-pill px-3">Add Category</button>
        </form>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card">
        <div class="table-responsive">
          <table class="table table-clean mb-0">
            <thead><tr><th>Category</th><th>Products</th><th>Commission %</th><th></th></tr></thead>
            <tbody>
            <?php mysqli_data_seek($categories, 0); while ($c = mysqli_fetch_assoc($categories)): ?>
              <tr>
                <td><?= clean($c['name']) ?></td>
                <td><?= $c['product_count'] ?></td>
                <td><?= $c['commission_percentage'] !== null ? number_format($c['commission_percentage'],2).'%' : '<span class="text-secondary">Global default</span>' ?></td>
                <td>
                  <form method="POST" data-confirm="Delete this category? (fails if products exist)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="delete_category_id" value="<?= $c['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" <?= $c['product_count']>0?'disabled':'' ?>><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
</div>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>
