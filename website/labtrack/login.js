'use strict';

const form      = document.getElementById('loginForm');
const errorMsg  = document.getElementById('errorMsg');
const submitBtn = document.getElementById('submitBtn');
const btnText   = submitBtn.querySelector('.btn-text');
const btnSpinner = submitBtn.querySelector('.btn-spinner');
const passwordInput = document.getElementById('password');
const toggleBtn = document.querySelector('.toggle-password');
const eyeIcon   = document.getElementById('eyeIcon');

// ── Toggle password visibility ──────────────────────────────
toggleBtn.addEventListener('click', () => {
  const isPassword = passwordInput.type === 'password';
  passwordInput.type = isPassword ? 'text' : 'password';

  // Swap icon between "eye" and "eye-off"
  eyeIcon.innerHTML = isPassword
    ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
       <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
       <line x1="1" y1="1" x2="23" y2="23"/>`
    : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
       <circle cx="12" cy="12" r="3"/>`;
});

// ── Show / hide error ───────────────────────────────────────
function showError(message) {
  errorMsg.textContent = message;
  errorMsg.classList.add('visible');
}

function clearError() {
  errorMsg.textContent = '';
  errorMsg.classList.remove('visible');
}

// ── Loading state ────────────────────────────────────────────
function setLoading(loading) {
  submitBtn.disabled = loading;
  btnText.hidden     = loading;
  btnSpinner.hidden  = !loading;
}

// ── Form submission ──────────────────────────────────────────
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearError();

  const username = document.getElementById('username').value.trim();
  const password = passwordInput.value;

  if (!username || !password) {
    showError('Please enter both username and password.');
    return;
  }

  setLoading(true);

  try {
    const formData = new FormData();
    formData.append('username', username);
    formData.append('password', password);

    const response = await fetch('auth.php', {
      method: 'POST',
      body: formData,
    });

    if (!response.ok) {
      throw new Error(`Server error: ${response.status}`);
    }

    const data = await response.json();

    if (data.success) {
      window.location.href = data.redirect || 'dashboard.html';
    } else {
      showError(data.message || 'Login failed. Please try again.');
    }
  } catch (err) {
    console.error(err);
    showError('Could not connect to the server. Please try again.');
  } finally {
    setLoading(false);
  }
});
