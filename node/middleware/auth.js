'use strict';

// Auth helpers mirroring includes/functions.php. The session object plays
// the role of PHP's $_SESSION; role checks are identical.

function isLoggedIn(req) {
    return Boolean(req.session && req.session.user_id);
}

function isAdmin(req) {
    return Boolean(req.session && req.session.role === 'admin');
}

function isStaff(req) {
    return Boolean(req.session && ['staff', 'admin'].includes(req.session.role));
}

function requireLogin(req, res, next) {
    if (!isLoggedIn(req)) return res.redirect('/login');
    next();
}

function requireAdmin(req, res, next) {
    if (!isLoggedIn(req) || !isAdmin(req)) return res.redirect('/');
    next();
}

function requireStaff(req, res, next) {
    if (!isLoggedIn(req) || !isStaff(req)) return res.redirect('/');
    next();
}

module.exports = { isLoggedIn, isAdmin, isStaff, requireLogin, requireAdmin, requireStaff };
