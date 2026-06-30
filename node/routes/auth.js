'use strict';

const express = require('express');
const bcrypt = require('bcryptjs');
const router = express.Router();
const { pool } = require('../db');
const { requireLogin } = require('../middleware/auth');
const { verifyCsrf } = require('../middleware/csrf');

// bcryptjs recognises $2a/$2b; PHP's password_hash emits $2y, whose bytes
// are identical — normalise the tag so existing seed hashes verify.
const normalizeHash = (h) => (h && h.startsWith('$2y$') ? '$2b$' + h.slice(4) : h);

// GET /login
router.get('/login', (req, res) => {
    if (req.session.user_id) return res.redirect('/');
    res.render('login', { title: 'Login', active: 'login', error: '' });
});

// POST /login (port of login.php)
router.post('/login', verifyCsrf, async (req, res, next) => {
    try {
        const username = req.body.username || '';
        const password = req.body.password || '';
        if (!username || !password) {
            return res.render('login', { title: 'Login', active: 'login', error: 'Please enter both username and password.' });
        }
        const [rows] = await pool.query('SELECT * FROM users WHERE username = ? OR email = ?', [username, username]);
        const user = rows[0];
        if (user && bcrypt.compareSync(password, normalizeHash(user.password))) {
            req.session.user_id = user.user_id;
            req.session.username = user.username;
            req.session.first_name = user.first_name;
            req.session.last_name = user.last_name;
            req.session.role = user.role;
            if (user.role === 'admin') return res.redirect('/admin');
            if (user.role === 'staff') return res.redirect('/staff');
            return res.redirect('/');
        }
        res.render('login', { title: 'Login', active: 'login', error: 'Invalid username or password.' });
    } catch (err) {
        next(err);
    }
});

// GET /register
router.get('/register', (req, res) => {
    res.render('register', { title: 'Register', active: 'register', error: '', success: '' });
});

// POST /register (port of register.php + registerUser())
router.post('/register', verifyCsrf, async (req, res, next) => {
    try {
        const username = (req.body.username || '').trim();
        const email = (req.body.email || '').trim();
        const password = req.body.password || '';
        const confirm = req.body.confirm || '';
        const first_name = (req.body.first_name || '').trim();
        const last_name = (req.body.last_name || '').trim();

        const view = (extra) => res.render('register', { title: 'Register', active: 'register', error: '', success: '', ...extra });

        if (password !== confirm) return view({ error: 'Passwords do not match.' });

        const [existing] = await pool.query('SELECT user_id FROM users WHERE username = ? OR email = ?', [username, email]);
        if (existing.length) return view({ error: 'Username or email already exists.' });

        const hashed = bcrypt.hashSync(password, 12);
        await pool.query(
            'INSERT INTO users (username, email, password, first_name, last_name) VALUES (?, ?, ?, ?, ?)',
            [username, email, hashed, first_name, last_name]
        );
        view({ success: "Registration successful! You can now <a href='/login'>login</a>." });
    } catch (err) {
        next(err);
    }
});

// GET /logout
router.get('/logout', (req, res) => {
    req.session.destroy(() => res.redirect('/login'));
});

// GET /profile (port of profile.php)
router.get('/profile', requireLogin, async (req, res, next) => {
    try {
        const [rows] = await pool.query('SELECT * FROM users WHERE user_id = ?', [req.session.user_id]);
        if (!rows.length) return res.redirect('/');
        res.render('profile', { title: 'My Profile', user: rows[0] });
    } catch (err) {
        next(err);
    }
});

module.exports = router;
