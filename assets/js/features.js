/* SkyConnect — feature layer (theme, toasts, stepper helpers, seat map,
   countdowns, password meter, back-to-top). Progressive enhancement. */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- 1. Dark-mode toggle ---------- */
    var THEME_KEY = 'sky-theme';
    function applyTheme(t) { document.documentElement.setAttribute('data-theme', t); }
    document.querySelectorAll('.theme-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem(THEME_KEY, next); } catch (e) {}
        });
    });

    /* ---------- 2. Toasts ---------- */
    var stack;
    function toastStack() {
        if (!stack) { stack = document.createElement('div'); stack.className = 'toast-stack'; document.body.appendChild(stack); }
        return stack;
    }
    function showToast(message, type, timeout) {
        type = type || 'info';
        var t = document.createElement('div');
        t.className = 'toast toast-' + type;
        var icon = type === 'success' ? '✓' : type === 'error' ? '✕' : 'i';
        t.innerHTML = '<span class="toast-icon">' + icon + '</span>' +
            '<div class="toast-body"></div>' +
            '<button class="toast-close" aria-label="Dismiss">×</button>';
        t.querySelector('.toast-body').textContent = message;
        toastStack().appendChild(t);
        requestAnimationFrame(function () { t.classList.add('show'); });
        var dismiss = function () {
            t.classList.add('hide');
            setTimeout(function () { t.remove(); }, 480);
        };
        t.querySelector('.toast-close').addEventListener('click', dismiss);
        if (timeout !== 0) setTimeout(dismiss, timeout || 5000);
    }
    window.SkyToast = { show: showToast };

    // Promote flash-style alerts to toasts
    document.querySelectorAll('.alert.js-toast').forEach(function (el) {
        var type = el.classList.contains('alert-success') ? 'success'
            : el.classList.contains('alert-error') ? 'error' : 'info';
        showToast(el.textContent.trim(), type, 6000);
        el.remove();
    });

    /* ---------- 3. Seat-selection map ---------- */
    document.querySelectorAll('.seatmap').forEach(function (map) {
        var need = parseInt(map.getAttribute('data-need'), 10) || 1;
        var input = document.getElementById(map.getAttribute('data-input'));
        var counter = map.querySelector('.seat-count');
        function selected() { return Array.prototype.slice.call(map.querySelectorAll('.seat.selected')); }
        function sync() {
            var sel = selected();
            if (input) input.value = sel.map(function (s) { return s.getAttribute('data-seat'); }).join(',');
            if (counter) counter.textContent = sel.length;
            map.querySelectorAll('.seat.free').forEach(function (s) {
                s.style.pointerEvents = (sel.length >= need && !s.classList.contains('selected')) ? 'none' : '';
                s.style.opacity = (sel.length >= need && !s.classList.contains('selected')) ? '.5' : '';
            });
        }
        map.querySelectorAll('.seat.free').forEach(function (seat) {
            seat.addEventListener('click', function () {
                if (seat.classList.contains('selected')) { seat.classList.remove('selected'); }
                else if (selected().length < need) { seat.classList.add('selected'); }
                sync();
            });
        });
        sync();
    });

    /* ---------- 4. Flight countdown ---------- */
    function fmtCountdown(el) {
        var raw = el.getAttribute('data-countdown');
        if (!raw) return;
        var when = new Date(raw.replace(' ', 'T')).getTime();
        if (isNaN(when)) { el.style.display = 'none'; return; }
        var diff = when - Date.now();
        el.classList.remove('soon', 'departed');
        if (diff <= 0) { el.textContent = 'Departed'; el.classList.add('departed'); return; }
        var mins = Math.floor(diff / 60000);
        var d = Math.floor(mins / 1440), h = Math.floor((mins % 1440) / 60), m = mins % 60;
        var txt = d > 0 ? ('Departs in ' + d + 'd ' + h + 'h')
            : h > 0 ? ('Departs in ' + h + 'h ' + m + 'm')
                : ('Departs in ' + m + 'm');
        el.textContent = txt;
        if (diff < 24 * 3600 * 1000) el.classList.add('soon');
    }
    var countdowns = document.querySelectorAll('[data-countdown]');
    if (countdowns.length) {
        countdowns.forEach(fmtCountdown);
        setInterval(function () { countdowns.forEach(fmtCountdown); }, 60000);
    }

    /* ---------- 5. Password strength meter ---------- */
    var pwInput = document.getElementById('password');
    var pwMeter = document.querySelector('.pw-meter');
    if (pwInput && pwMeter) {
        var bar = pwMeter.querySelector('.pw-bar');
        var label = pwMeter.querySelector('.pw-label');
        var LABELS = ['Weak', 'Fair', 'Good', 'Strong'];
        pwInput.addEventListener('input', function () {
            var v = pwInput.value, score = 0;
            if (v.length >= 8) score++;
            if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
            if (/\d/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;
            if (v.length === 0) { pwMeter.style.display = 'none'; return; }
            pwMeter.style.display = '';
            var idx = Math.max(0, Math.min(3, score - 1));
            pwMeter.className = 'pw-meter pw-' + idx;
            if (label) label.textContent = 'Password strength: ' + LABELS[idx];
        });
    }

    /* ---------- 6. Back-to-top ---------- */
    var toTop = document.createElement('button');
    toTop.className = 'to-top';
    toTop.setAttribute('aria-label', 'Back to top');
    toTop.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"></polyline></svg>';
    document.body.appendChild(toTop);
    toTop.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
    var toggleTop = function () { toTop.classList.toggle('show', window.scrollY > 500); };
    toggleTop();
    window.addEventListener('scroll', toggleTop, { passive: true });

    /* ---------- 7. Print boarding pass ---------- */
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.preventDefault(); window.print(); });
    });
})();
