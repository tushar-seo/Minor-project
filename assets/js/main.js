// ---------------------------------------------------------------------
// Dark / Light mode toggle (persisted in localStorage for UI preference
// only — no business data is ever stored client-side)
// ---------------------------------------------------------------------
(function () {
  const root = document.documentElement;
  const toggleBtn = document.getElementById('themeToggle');
  const saved = localStorage.getItem('mh-theme');
  if (saved) root.setAttribute('data-bs-theme', saved);

  function updateIcon() {
    if (!toggleBtn) return;
    const icon = toggleBtn.querySelector('i');
    const dark = root.getAttribute('data-bs-theme') === 'dark';
    icon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
  }
  updateIcon();

  if (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
      const current = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-bs-theme', current);
      localStorage.setItem('mh-theme', current);
      updateIcon();
    });
  }
})();

// ---------------------------------------------------------------------
// Sidebar toggle for dashboards on mobile
// ---------------------------------------------------------------------
(function () {
  const btn = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('dashSidebar');
  if (btn && sidebar) {
    btn.addEventListener('click', () => sidebar.classList.toggle('show'));
  }
})();

// ---------------------------------------------------------------------
// Generic confirm-before-submit for destructive actions
// ---------------------------------------------------------------------
document.addEventListener('submit', function (e) {
  const form = e.target;
  if (form.matches('[data-confirm]')) {
    const msg = form.getAttribute('data-confirm') || 'Are you sure?';
    if (!confirm(msg)) e.preventDefault();
  }
});

// ---------------------------------------------------------------------
// Password confirm live check (registration / reset password forms)
// ---------------------------------------------------------------------
(function () {
  const pw = document.getElementById('password');
  const cpw = document.getElementById('confirm_password');
  if (pw && cpw) {
    function check() {
      cpw.setCustomValidity(pw.value !== cpw.value ? 'Passwords do not match' : '');
    }
    pw.addEventListener('input', check);
    cpw.addEventListener('input', check);
  }
})();

// ---------------------------------------------------------------------
// Quantity stepper widget (product details / cart)
// ---------------------------------------------------------------------
document.addEventListener('click', function (e) {
  const btn = e.target.closest('[data-qty-btn]');
  if (!btn) return;
  const wrap = btn.closest('.qty-stepper');
  const input = wrap.querySelector('input[type="number"]');
  let val = parseInt(input.value || '1', 10);
  const max = parseInt(input.max || '999', 10);
  const min = parseInt(input.min || '1', 10);
  val = btn.dataset.qtyBtn === 'inc' ? Math.min(max, val + 1) : Math.max(min, val - 1);
  input.value = val;
  input.dispatchEvent(new Event('change'));
});
