'use strict';

// Faithful ports of the PHP helper functions in includes/functions.php.
// EJS auto-escapes <%= %>, which replaces most uses of e(); these helpers
// cover formatting and the bits of markup PHP built by hand.

const SHORT_DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const LONG_DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const LONG_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
    'August', 'September', 'October', 'November', 'December'];

const pad2 = (n) => String(n).padStart(2, '0');

function toDate(value) {
    if (value instanceof Date) return value;
    if (value === null || value === undefined || value === '') return null;
    // MySQL DATETIME strings ("YYYY-MM-DD HH:MM:SS") parse reliably as local time
    return new Date(String(value).replace(' ', 'T'));
}

// Minimal PHP date() formatter supporting the tokens this app uses:
// d j D l M F m Y H i  (everything else is emitted literally)
function phpDate(format, value) {
    const d = toDate(value);
    if (!d || isNaN(d.getTime())) return '';
    const map = {
        d: pad2(d.getDate()),
        j: String(d.getDate()),
        D: SHORT_DAYS[d.getDay()],
        l: LONG_DAYS[d.getDay()],
        M: SHORT_MONTHS[d.getMonth()],
        F: LONG_MONTHS[d.getMonth()],
        m: pad2(d.getMonth() + 1),
        Y: String(d.getFullYear()),
        H: pad2(d.getHours()),
        i: pad2(d.getMinutes()),
    };
    let out = '';
    for (const ch of format) out += Object.prototype.hasOwnProperty.call(map, ch) ? map[ch] : ch;
    return out;
}

// number_format($n, 2) — comma thousands, two decimals: 38700 -> "38,700.00"
function nf2(n) {
    return Number(n || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

const ucfirst = (s) => (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1) : '');
const pad6 = (n) => String(n).padStart(6, '0');

// HTML escape, mirrors PHP e(); used where we build raw markup with <%- %>
function e(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// statusBadge() — colored pill, classes live in assets/css/style.css
function statusBadge(status) {
    const s = status || 'scheduled';
    return `<span class="status-badge status-${e(s)}">${e(ucfirst(s))}</span>`;
}

// Flight duration as "Xh Ym" (PHP used DateTime::diff %h/%i)
function durationHM(departure, arrival) {
    const a = toDate(departure);
    const b = toDate(arrival);
    if (!a || !b) return '';
    const mins = Math.max(0, Math.round((b - a) / 60000));
    return `${Math.floor(mins / 60)}h ${mins % 60}m`;
}

// qrSvg() — a deterministic, decorative QR-style code rendered as inline SVG.
// Not a scannable QR (no real encoding/deps); it gives the e-ticket the
// recognizable look of a boarding-pass code, seeded from the booking ref so it
// stays stable for a given booking.
function qrSvg(seed) {
    const N = 25;                       // modules per side
    const str = String(seed || 'SKY');
    // xorshift PRNG seeded from a simple string hash — stable & dependency-free
    let h = 2166136261;
    for (let i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 16777619); }
    let s = (h >>> 0) || 1;
    const rand = () => { s ^= s << 13; s ^= s >>> 17; s ^= s << 5; return ((s >>> 0) / 4294967296); };

    // 7x7 finder pattern stamped into the top-left, top-right and bottom-left.
    const inFinder = (r, c) => {
        const zones = [[0, 0], [0, N - 7], [N - 7, 0]];
        for (const [zr, zc] of zones) {
            const rr = r - zr, cc = c - zc;
            if (rr < 0 || rr > 6 || cc < 0 || cc > 6) continue;
            const ring = rr === 0 || rr === 6 || cc === 0 || cc === 6;
            const core = rr >= 2 && rr <= 4 && cc >= 2 && cc <= 4;
            return ring || core ? 'on' : 'off';
        }
        return null;
    };

    let cells = '';
    for (let r = 0; r < N; r++) {
        for (let c = 0; c < N; c++) {
            const f = inFinder(r, c);
            const on = f ? f === 'on' : rand() > 0.5;
            if (on) cells += `<rect x="${c}" y="${r}" width="1" height="1"/>`;
        }
    }
    return `<svg class="qr" viewBox="0 0 ${N} ${N}" shape-rendering="crispEdges" role="img" aria-label="Boarding code">`
        + `<rect width="${N}" height="${N}" fill="#fff"/><g fill="#0d1b3e">${cells}</g></svg>`;
}

module.exports = { phpDate, nf2, ucfirst, pad6, e, statusBadge, durationHM, toDate, qrSvg };
