'use strict';

// Progressive enhancement only: the form works with JavaScript disabled.
// All validation that matters happens on the server.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('login-form');
  const pw = document.getElementById('password');
  const toggle = document.getElementById('toggle-pw');
  const caps = document.getElementById('caps-note');
  const submit = document.getElementById('submit-btn');
  const user = document.getElementById('username');
  if (!form || !pw) return;

  toggle.addEventListener('click', () => {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    toggle.textContent = show ? 'Hide' : 'Show';
    toggle.setAttribute('aria-pressed', String(show));
  });

  const checkCaps = (e) => {
    if (typeof e.getModifierState === 'function') {
      caps.hidden = !e.getModifierState('CapsLock');
    }
  };
  pw.addEventListener('keydown', checkCaps);
  pw.addEventListener('keyup', checkCaps);
  pw.addEventListener('blur', () => { caps.hidden = true; });

  form.addEventListener('submit', (e) => {
    if (!user.value.trim() || !pw.value) {
      e.preventDefault();
      (user.value.trim() ? pw : user).focus();
      return;
    }
    submit.disabled = true;           // prevents double submits (and double lockout counts)
    submit.textContent = 'Signing in...';
  });

  // Back-button after a failed attempt can leave the button disabled.
  window.addEventListener('pageshow', () => {
    submit.disabled = false;
    submit.textContent = 'Sign in';
  });
});
