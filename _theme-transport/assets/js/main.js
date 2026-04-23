/**
 * Priority Print — minimal front-end JS.
 * Vanilla only. No jQuery, no frameworks, no build step.
 *
 * Responsibilities:
 *   1. Mobile nav toggle (hamburger opens/closes primary nav, sets aria-expanded).
 *   2. Close mobile nav on outside tap / Esc.
 *
 * Anything that needs to run before DOMContentLoaded should be inlined in
 * header.php; everything else belongs here.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		var toggle = document.querySelector('.nav-toggle');
		var nav    = document.getElementById('primary-nav');
		if (!toggle || !nav) return;

		function closeNav() {
			nav.classList.remove('is-open');
			toggle.setAttribute('aria-expanded', 'false');
		}
		function openNav() {
			nav.classList.add('is-open');
			toggle.setAttribute('aria-expanded', 'true');
		}

		toggle.addEventListener('click', function (e) {
			e.stopPropagation();
			if (nav.classList.contains('is-open')) closeNav();
			else openNav();
		});

		// Close when a nav link is clicked (so mobile users land on the target
		// without a stale open drawer).
		nav.addEventListener('click', function (e) {
			if (e.target && e.target.tagName === 'A') closeNav();
		});

		// Click outside the nav closes it.
		document.addEventListener('click', function (e) {
			if (!nav.classList.contains('is-open')) return;
			if (nav.contains(e.target) || toggle.contains(e.target)) return;
			closeNav();
		});

		// Esc closes it.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && nav.classList.contains('is-open')) closeNav();
		});
	});
})();
