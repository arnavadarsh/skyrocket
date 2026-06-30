/* SkyConnect — UI interactions & motion (progressive enhancement) */
(function () {
    'use strict';

    document.body.classList.add('js-ready');

    var reduceMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---- Sticky header shadow on scroll + scroll progress bar ---- */
    var header = document.querySelector('header');

    var progress = document.createElement('div');
    progress.className = 'scroll-progress';
    document.body.appendChild(progress);

    var ticking = false;
    function updateScroll() {
        var y = window.scrollY || window.pageYOffset;
        if (header) header.classList.toggle('scrolled', y > 8);
        var doc = document.documentElement;
        var max = (doc.scrollHeight - doc.clientHeight) || 1;
        progress.style.width = Math.min(100, (y / max) * 100) + '%';
        ticking = false;
    }
    function onScroll() {
        if (!ticking) { window.requestAnimationFrame(updateScroll); ticking = true; }
    }
    updateScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });

    /* ---- Scroll reveal via IntersectionObserver ---- */
    var revealTargets = document.querySelectorAll(
        '.feature-card, .flight-card, .booking-card, .info-group, ' +
        '.passenger-fieldset, .search-container, .confirmation-container, .booking-container, ' +
        '.stat-card, .admin-link-card, .admin-card, .boarding-pass, .profile-card, ' +
        '.payment-method, .error-page'
    );

    if ('IntersectionObserver' in window && revealTargets.length) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var siblings = entry.target.parentNode
                        ? Array.prototype.indexOf.call(entry.target.parentNode.children, entry.target)
                        : 0;
                    entry.target.style.transitionDelay = Math.min(Math.max(siblings, 0) * 70, 350) + 'ms';
                    entry.target.classList.add('in-view');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        revealTargets.forEach(function (el) {
            el.classList.add('reveal');
            observer.observe(el);
        });
    }

    /* ---- Spotlight glow that follows the cursor across cards ---- */
    if (!reduceMotion && window.matchMedia('(pointer: fine)').matches) {
        var glowCards = document.querySelectorAll(
            '.feature-card, .flight-card, .stat-card, .admin-link-card, .booking-card, .payment-method'
        );
        glowCards.forEach(function (card) {
            card.addEventListener('mousemove', function (e) {
                var r = card.getBoundingClientRect();
                card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
                card.style.setProperty('--my', (e.clientY - r.top) + 'px');
            });
        });
    }

    /* ---- Count-up animation for dashboard stat numbers ---- */
    function countUp(el) {
        var raw = el.textContent.trim();
        var prefix = raw.match(/^[^\d]*/)[0];
        var numeric = raw.replace(/[^\d.]/g, '');
        if (!numeric) return;
        var hasDecimals = /\.\d/.test(numeric);
        var target = parseFloat(numeric);
        if (!isFinite(target)) return;
        var start = null, dur = 1100;
        function step(ts) {
            if (start === null) start = ts;
            var p = Math.min((ts - start) / dur, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            var val = target * eased;
            var shown = hasDecimals
                ? val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : Math.round(val).toLocaleString('en-US');
            el.textContent = prefix + shown;
            if (p < 1) window.requestAnimationFrame(step);
        }
        window.requestAnimationFrame(step);
    }

    var statNumbers = document.querySelectorAll('.stat-number');
    if (statNumbers.length && !reduceMotion && 'IntersectionObserver' in window) {
        var statObs = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { countUp(entry.target); statObs.unobserve(entry.target); }
            });
        }, { threshold: 0.4 });
        statNumbers.forEach(function (el) { statObs.observe(el); });
    }

    /* ---- Material-style ripple on buttons ---- */
    if (!reduceMotion) {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.btn');
            if (!btn || btn.classList.contains('btn-disabled')) return;
            var r = btn.getBoundingClientRect();
            var span = document.createElement('span');
            span.className = 'ripple';
            span.style.left = (e.clientX - r.left) + 'px';
            span.style.top = (e.clientY - r.top) + 'px';
            btn.appendChild(span);
            setTimeout(function () { span.remove(); }, 650);
        });
    }

    /* ---- Confetti burst on a confirmed booking ---- */
    var successBadge = document.querySelector('.result-badge.success');
    if (successBadge && !reduceMotion) {
        var layer = document.createElement('div');
        layer.className = 'confetti-layer';
        document.body.appendChild(layer);
        var colors = ['#2563eb', '#22d3ee', '#38bdf8', '#16a34a', '#fbbf24', '#f87171', '#a78bfa'];
        for (var i = 0; i < 110; i++) {
            var piece = document.createElement('i');
            piece.className = 'confetti-piece';
            piece.style.left = (35 + Math.random() * 30) + 'vw';
            piece.style.background = colors[i % colors.length];
            piece.style.setProperty('--dx', (Math.random() * 60 - 30) + 'vw');
            piece.style.setProperty('--rot', (Math.random() * 900 - 450) + 'deg');
            piece.style.setProperty('--dur', (2.6 + Math.random() * 2) + 's');
            piece.style.setProperty('--delay', (Math.random() * 0.6) + 's');
            if (Math.random() > 0.6) piece.style.borderRadius = '50%';
            layer.appendChild(piece);
        }
        setTimeout(function () { layer.remove(); }, 5600);
    }

    /* ---- Trip-type toggle: show/hide the return date field ---- */
    var tripRadios = document.querySelectorAll('input[name="trip_type"]');
    var returnField = document.querySelector('.return-date');
    if (tripRadios.length && returnField) {
        tripRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                returnField.style.display = (this.value === 'round') ? 'block' : 'none';
            });
        });
    }
})();
