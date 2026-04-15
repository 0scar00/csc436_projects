'use strict';

// ── Populate username from session (via PHP-injected meta or sessionStorage) ──
const storedUser = sessionStorage.getItem('labtrack_user');
if (storedUser) {
  const welcomeEl = document.getElementById('welcomeUser');
  const heroEl    = document.getElementById('heroName');
  if (welcomeEl) welcomeEl.textContent = storedUser;
  if (heroEl)    heroEl.textContent    = ', ' + storedUser;
}

// ── Logout ────────────────────────────────────────────────────────────────────
document.getElementById('logoutBtn').addEventListener('click', async () => {
  try {
    await fetch('logout.php', { method: 'POST' });
  } catch (_) { /* ignore network errors on logout */ }
  sessionStorage.removeItem('labtrack_user');
  window.location.href = 'login.html';
});
