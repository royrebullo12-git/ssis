'use strict';
// Progressive enhancement; every action also works server-side without JS.
document.addEventListener('DOMContentLoaded', () => {
  // Confirm destructive/irreversible actions: <button data-confirm="Void this payment?">
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (e) => {
      if (!window.confirm(el.getAttribute('data-confirm'))) e.preventDefault();
    });
  });
  // Live row filter: <input data-filter="#tableId"> hides rows that do not match.
  document.querySelectorAll('input[data-filter]').forEach((input) => {
    const rows = document.querySelectorAll(input.getAttribute('data-filter') + ' tbody tr');
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      rows.forEach((r) => { r.hidden = q !== '' && !r.textContent.toLowerCase().includes(q); });
    });
  });
  // Prevent double-submits on forms.
  document.querySelectorAll('form').forEach((f) => {
    f.addEventListener('submit', () => {
      f.querySelectorAll('button[type=submit]').forEach((b) => setTimeout(() => { b.disabled = true; }, 0));
    });
  });
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = false; });
  });
  // Fade out success notices.
  document.querySelectorAll('.msg.success').forEach((m) => setTimeout(() => { m.hidden = true; }, 6000));
});
