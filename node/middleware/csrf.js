'use strict';

// Session-based CSRF, same contract as the PHP csrf_token/csrf_field/csrf_verify
// helpers: a per-session token, a hidden form field, and constant-time
// verification on every state-changing POST.
const crypto = require('crypto');

function csrfToken(req) {
    if (!req.session.csrf_token) {
        req.session.csrf_token = crypto.randomBytes(32).toString('hex');
    }
    return req.session.csrf_token;
}

function csrfField(req) {
    return `<input type="hidden" name="csrf_token" value="${csrfToken(req)}">`;
}

// Express middleware: verify the token on unsafe methods.
function verifyCsrf(req, res, next) {
    if (['GET', 'HEAD', 'OPTIONS'].includes(req.method)) return next();
    const sent = req.body && req.body.csrf_token;
    const expected = req.session && req.session.csrf_token;
    const ok = sent && expected && sent.length === expected.length &&
        crypto.timingSafeEqual(Buffer.from(sent), Buffer.from(expected));
    if (!ok) {
        res.status(403);
        return res.send('Invalid or missing CSRF token. Please go back and try again.');
    }
    next();
}

module.exports = { csrfToken, csrfField, verifyCsrf };
