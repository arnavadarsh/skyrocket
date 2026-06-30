'use strict';

require('dotenv').config();

const path = require('path');
const express = require('express');
const session = require('express-session');

const helpers = require('./lib/helpers');
const auth = require('./middleware/auth');
const { csrfToken, csrfField } = require('./middleware/csrf');

const app = express();

// Views: EJS, mirroring the PHP page-per-template structure.
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Body parsing — extended:true so `name[]` fields arrive as arrays,
// exactly like PHP's $_POST['full_name'][].
app.use(express.urlencoded({ extended: true }));

// Reuse the existing front-end assets (redesigned CSS/JS + images).
app.use('/assets', express.static(path.join(__dirname, '..', 'assets')));

app.use(session({
    secret: process.env.SESSION_SECRET || 'skyconnect-dev-secret',
    resave: false,
    saveUninitialized: false,
    cookie: { httpOnly: true, sameSite: 'lax', maxAge: 1000 * 60 * 60 * 8 },
}));

// Expose to every template the things PHP read from globals/helpers.
app.use((req, res, next) => {
    res.locals.SITE_NAME = process.env.SITE_NAME || 'SkyConnect';
    res.locals.title = res.locals.SITE_NAME;
    res.locals.session = req.session;
    res.locals.isLoggedIn = auth.isLoggedIn(req);
    res.locals.isStaff = auth.isStaff(req);
    res.locals.isAdmin = auth.isAdmin(req);
    res.locals.active = '';
    res.locals.h = helpers;
    res.locals.csrf_token = csrfToken(req);
    res.locals.csrf_field = () => csrfField(req);
    next();
});

// Routes (grouped by area, like the PHP top-level / admin / staff folders)
app.use('/', require('./routes/public'));
app.use('/', require('./routes/auth'));
app.use('/', require('./routes/booking'));
app.use('/admin', require('./routes/admin'));
app.use('/staff', require('./routes/staff'));

// 404
app.use((req, res) => {
    res.status(404).render('error', { title: 'Not Found', message: 'Page not found.' });
});

// Errors
app.use((err, req, res, next) => {
    console.error(err);
    res.status(500).render('error', { title: 'Error', message: 'An unexpected error occurred.' });
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`SkyConnect (Node) running at http://localhost:${PORT}`);
});
