/* PPS Theme — main.js
 * Progressive enhancement. Works without JS (menu is visible as a footer list via <noscript>).
 * Responsibilities:
 *   - Toggle the fold-out overlay menu
 *   - Trap focus while open, return focus on close
 *   - Escape to close, click-outside to close (the overlay covers full viewport, so clicks on it close)
 *   - Scroll-lock the body
 *   - Add .is-scrolled to the header past a threshold
 */
(function () {
  'use strict';

  var header = document.querySelector('.pps-header');
  var toggle = document.querySelector('[data-pps-menu-toggle]');
  var overlay = document.querySelector('[data-pps-overlay]');
  var closeBtn = document.querySelector('[data-pps-menu-close]');
  if (!header || !toggle || !overlay) return;

  var lastFocused = null;

  function focusables(root) {
    return Array.prototype.slice.call(root.querySelectorAll(
      'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"]), input, select, textarea'
    )).filter(function (el) { return el.offsetParent !== null || el === document.activeElement; });
  }

  function open() {
    if (overlay.getAttribute('data-open') === 'true') return;
    lastFocused = document.activeElement;
    overlay.setAttribute('data-open', 'true');
    toggle.setAttribute('aria-expanded', 'true');
    document.body.classList.add('pps-menu-open');
    // Focus first link after the transition kicks
    window.setTimeout(function () {
      var first = overlay.querySelector('.pps-nav__link');
      if (first) first.focus();
    }, 120);
    document.addEventListener('keydown', onKey);
  }

  function close() {
    if (overlay.getAttribute('data-open') !== 'true') return;
    overlay.setAttribute('data-open', 'false');
    toggle.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('pps-menu-open');
    document.removeEventListener('keydown', onKey);
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'Tab') {
      var f = focusables(overlay);
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  toggle.addEventListener('click', function () {
    if (overlay.getAttribute('data-open') === 'true') close(); else open();
  });
  if (closeBtn) closeBtn.addEventListener('click', close);

  // Click on the overlay background (not on interactive content) closes
  overlay.addEventListener('click', function (e) {
    var inner = overlay.querySelector('.pps-overlay__inner');
    if (inner && !inner.contains(e.target)) close();
  });

  // Scroll state for header
  var scrolled = false;
  function onScroll() {
    var should = window.scrollY > 4;
    if (should === scrolled) return;
    scrolled = should;
    header.classList.toggle('is-scrolled', scrolled);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();
