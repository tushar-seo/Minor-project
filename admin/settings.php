<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Settings';
$dash_title = 'Settings';
$sidebar_role = 'admin';
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['save_general'])) {
        set_setting('site_name', trim($_POST['site_name']));
        set_setting('currency_symbol', trim($_POST['currency_symbol']));
        set_flash('success', 'General settings updated. (Note: BASE_URL/CURRENCY constants require a config file edit to fully propagate.)');
    } elseif (isset($_POST['save_smtp'])) {
        set_setting('smtp_host', trim($_POST['smtp_host'] ?? ''));
        set_setting('smtp_port', (string)(int)($_POST['smtp_port'] ?? 587));
        set_setting('smtp_user', trim($_POST['smtp_user'] ?? ''));
        $pw = preg_replace('/\s+/', '', $_POST['smtp_pass'] ?? '');   // Google shows app passwords with spaces
        if ($pw !== '') set_setting('smtp_pass', $pw);
        set_setting('smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
        set_flash('success', 'Email settings saved. Use "Send test email" to check them.');
    } elseif (isset($_POST['test_smtp'])) {
        $to = trim($_POST['test_to'] ?? '');
        $res = send_mail($to, 'MarketHub test email', mail_template('Test email', '<p>If you can read this, your email settings work.</p>'), $user['id'], true);
        set_flash($res['status'] === 'sent' ? 'success' : 'error', $res['message']);
    } elseif (isset($_POST['change_password'])) {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        $stmt = mysqli_prepare($conn, "SELECT password_hash FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $user['id']);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!password_verify($current, $row['password_hash'])) {
            set_flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 6) {
            set_flash('error', 'New password must be at least 6 characters.');
        } elseif ($new !== $confirm) {
            set_flash('error', 'New passwords do not match.');
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE users SET password_hash = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'si', $hash, $user['id']);
            mysqli_stmt_execute($stmt);
            set_flash('success', 'Password updated successfully.');
        }
    }
    redirect(BASE_URL . '/admin/settings.php');
}

$site_name = get_setting('site_name', 'MarketHub');
$currency_symbol = get_setting('currency_symbol', '₹');

$smtp_host = get_setting('smtp_host', '');
$smtp_port = get_setting('smtp_port', '587');
$smtp_user = get_setting('smtp_user', '');
$smtp_from = get_setting('smtp_from_email', '');
$smtp_has_pass = get_setting('smtp_pass', '') !== '';

include __DIR__ . '/../includes/dash-start.php';
?>
<div class="row g-4">
  <div class="col-lg-6">
    <div class="card p-4">
      <h6 class="mb-3">General Settings</h6>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="save_general" value="1">
        <div class="mb-3"><label class="form-label">Site Name</label><input class="form-control" name="site_name" value="<?= clean($site_name) ?>"></div>
        <div class="mb-3"><label class="form-label">Currency Symbol</label><input class="form-control" name="currency_symbol" value="<?= clean($currency_symbol) ?>"></div>
        <button class="btn btn-primary rounded-pill px-4">Save Settings</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-4">
      <h6 class="mb-3">Change Admin Password</h6>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="change_password" value="1">
        <div class="mb-3"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">New Password</label><input type="password" id="password" name="new_password" class="form-control" required minlength="6"></div>
        <div class="mb-3"><label class="form-label">Confirm New Password</label><input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6"></div>
        <button class="btn btn-primary rounded-pill px-4">Update Password</button>
      </form>
    </div>
  </div>
  <div class="col-12">
    <div class="card p-4">
      <h6 class="mb-1"><i class="bi bi-envelope-at text-primary"></i> Email (SMTP) settings</h6>
      <p class="small text-secondary mb-3">Needed to deliver real emails. For Gmail: host <code>smtp.gmail.com</code>, port <code>587</code>, your Gmail address, and a 16-character
        <strong>App Password</strong> (Google Account &rarr; Security &rarr; 2-Step Verification &rarr; App passwords). Leave the host empty to only log emails.</p>
      <form method="POST" class="row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="save_smtp" value="1">
        <div class="col-md-4"><label class="form-label">SMTP host</label><input class="form-control" name="smtp_host" placeholder="smtp.gmail.com" value="<?= clean($smtp_host) ?>"></div>
        <div class="col-md-2"><label class="form-label">Port</label><input class="form-control" name="smtp_port" value="<?= clean($smtp_port) ?>"></div>
        <div class="col-md-3"><label class="form-label">Username (email)</label><input class="form-control" name="smtp_user" placeholder="yourname@gmail.com" value="<?= clean($smtp_user) ?>"></div>
        <div class="col-md-3"><label class="form-label">App password</label><input type="password" class="form-control" name="smtp_pass" autocomplete="new-password" placeholder="<?= $smtp_has_pass ? '(saved - leave blank to keep)' : '16-character app password' ?>"></div>
        <div class="col-md-4"><label class="form-label">From address (optional)</label><input class="form-control" name="smtp_from_email" placeholder="same as username" value="<?= clean($smtp_from) ?>"></div>
        <div class="col-12"><button class="btn btn-primary rounded-pill px-4">Save email settings</button></div>
      </form>
      <hr>
      <form method="POST" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="test_smtp" value="1">
        <div class="col-md-5"><label class="form-label">Send a test email to</label><input type="email" name="test_to" class="form-control" required value="<?= clean($user['email']) ?>"></div>
        <div class="col-auto"><button class="btn btn-outline-primary rounded-pill px-4"><i class="bi bi-send"></i> Send test email</button></div>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/dash-footer.php'; ?>