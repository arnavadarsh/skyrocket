'use strict';

const express = require('express');
const crypto = require('crypto');
const router = express.Router();
const { pool } = require('../db');
const { requireLogin } = require('../middleware/auth');
const { verifyCsrf } = require('../middleware/csrf');

const CLASSES = ['economy', 'business', 'first'];
const toArr = (v) => (v === undefined ? [] : Array.isArray(v) ? v : [v]);

// Class-priced flight lookup, mirroring the SQL used across the PHP pages.
const PRICED_FLIGHT_SQL = `SELECT *,
        CASE
            WHEN ? = 'economy' THEN economy_price
            WHEN ? = 'business' THEN business_price
            WHEN ? = 'first' THEN first_price
            ELSE economy_price
        END AS selected_price
        FROM flights
        WHERE flight_id = ?`;

// Port of assignSeats() — must run inside a transaction so the FOR UPDATE
// lock prevents two bookings grabbing the same seat. Prefers a contiguous
// block in one row; falls back to any free seats; pads with NULL if full.
async function assignSeats(conn, flightId, count) {
    const [rows] = await conn.query(
        'SELECT seat_number FROM tickets WHERE flight_id = ? AND seat_number IS NOT NULL FOR UPDATE',
        [flightId]
    );
    const taken = new Set(rows.map((r) => r.seat_number));
    const letters = ['A', 'B', 'C', 'D', 'E', 'F'];

    for (let row = 1; row <= 30; row++) {
        let run = [];
        for (const letter of letters) {
            const seat = row + letter;
            if (taken.has(seat)) run = [];
            else {
                run.push(seat);
                if (run.length === count) return run;
            }
        }
    }

    const seats = [];
    for (let row = 1; row <= 30 && seats.length < count; row++) {
        for (const letter of letters) {
            const seat = row + letter;
            if (!taken.has(seat) && seats.length < count) seats.push(seat);
        }
    }
    while (seats.length < count) seats.push(null);
    return seats;
}

// Shared loader for the booking GET/POST handlers.
async function loadBookingContext(req) {
    const flight_id = parseInt(req.query.flight_id, 10) || 0;
    const return_id = parseInt(req.query.return_id, 10) || 0;
    let passengers = parseInt(req.query.passengers, 10) || 1;
    passengers = Math.max(1, Math.min(9, passengers));
    let cabin = req.query.class || 'economy';
    if (!CLASSES.includes(cabin)) cabin = 'economy';

    if (flight_id <= 0) return { redirect: '/' };

    const [frows] = await pool.query(PRICED_FLIGHT_SQL, [cabin, cabin, cabin, flight_id]);
    const flight = frows[0];
    if (!flight) return { redirect: '/' };

    let return_flight = null;
    if (return_id > 0) {
        const [rrows] = await pool.query(PRICED_FLIGHT_SQL, [cabin, cabin, cabin, return_id]);
        return_flight = rrows[0] || null;
    }

    const outbound_price = Number(flight.selected_price);
    const return_price = return_flight ? Number(return_flight.selected_price) : 0;
    const total_price = (outbound_price + return_price) * passengers;

    // Same server-side block checks as booking.php
    let error_message = null;
    let booking_blocked = false;
    const now = Date.now();
    if (flight.status === 'cancelled') {
        error_message = 'This flight has been cancelled and cannot be booked.';
        booking_blocked = true;
    } else if (return_flight && return_flight.status === 'cancelled') {
        error_message = 'The selected return flight has been cancelled and cannot be booked.';
        booking_blocked = true;
    } else if (new Date(flight.departure_time).getTime() <= now || ['departed', 'arrived'].includes(flight.status)) {
        error_message = 'This flight has already departed and cannot be booked.';
        booking_blocked = true;
    } else if (return_flight && (new Date(return_flight.departure_time).getTime() <= now || ['departed', 'arrived'].includes(return_flight.status))) {
        error_message = 'The selected return flight has already departed and cannot be booked.';
        booking_blocked = true;
    }

    const maxDob = (() => {
        const d = new Date();
        d.setDate(d.getDate() - 1);
        return d.toISOString().slice(0, 10);
    })();

    // Seats already taken on each leg — drives the seat-selection map.
    const takenSeats = async (fid) => {
        if (!fid) return [];
        const [rows] = await pool.query(
            'SELECT seat_number FROM tickets WHERE flight_id = ? AND seat_number IS NOT NULL', [fid]
        );
        return rows.map((r) => r.seat_number);
    };
    const taken_outbound = await takenSeats(flight_id);
    const taken_return = return_flight ? await takenSeats(return_id) : [];

    return {
        flight_id, return_id, passengers, cabin, flight, return_flight,
        outbound_price, return_price, total_price, error_message, booking_blocked, maxDob,
        taken_outbound, taken_return,
    };
}

function renderBooking(req, res, ctx, extra = {}) {
    res.render('booking', {
        title: 'Book Flight',
        flight: ctx.flight,
        return_flight: ctx.return_flight,
        outbound_price: ctx.outbound_price,
        return_price: ctx.return_price,
        total_price: ctx.total_price,
        passengers: ctx.passengers,
        cabin: ctx.cabin,
        booking_blocked: ctx.booking_blocked,
        error_message: ctx.error_message,
        maxDob: ctx.maxDob,
        taken_outbound: ctx.taken_outbound || [],
        taken_return: ctx.taken_return || [],
        formAction: req.originalUrl,
        old: {},
        ...extra,
    });
}

// GET /booking — review & confirm (port of booking.php display)
router.get('/booking', requireLogin, async (req, res, next) => {
    try {
        const ctx = await loadBookingContext(req);
        if (ctx.redirect) return res.redirect(ctx.redirect);
        renderBooking(req, res, ctx);
    } catch (err) {
        next(err);
    }
});

// POST /booking — create booking + passengers + tickets (port of booking.php)
router.post('/booking', requireLogin, verifyCsrf, async (req, res, next) => {
    try {
        const ctx = await loadBookingContext(req);
        if (ctx.redirect) return res.redirect(ctx.redirect);
        if (ctx.booking_blocked) return renderBooking(req, res, ctx);

        const { flight_id, return_id, passengers, cabin, flight, return_flight,
            outbound_price, return_price, total_price } = ctx;

        const names = toArr(req.body.full_name);
        const dobs = toArr(req.body.dob);
        const genders = toArr(req.body.gender);
        const passports = toArr(req.body.passport_no);

        const form_errors = [];
        if (names.length !== passengers || dobs.length !== passengers ||
            genders.length !== passengers || passports.length > passengers) {
            form_errors.push('Passenger details do not match the number of passengers.');
        } else {
            const today = new Date().toISOString().slice(0, 10);
            for (let i = 0; i < passengers; i++) {
                const label = `Passenger ${i + 1}`;
                const name = (names[i] || '').trim();
                const dob = (dobs[i] || '').trim();
                const gender = genders[i] || '';
                const passport = (passports[i] || '').trim();

                if (name === '' || name.length > 100) form_errors.push(`${label}: please enter a full name (max 100 characters).`);
                if (!/^\d{4}-\d{2}-\d{2}$/.test(dob) || isNaN(new Date(dob).getTime()) || dob >= today) {
                    form_errors.push(`${label}: date of birth must be a valid date in the past.`);
                }
                if (!['male', 'female', 'other'].includes(gender)) form_errors.push(`${label}: please select a gender.`);
                if (passport.length > 20) form_errors.push(`${label}: passport number is too long (max 20 characters).`);
            }
        }

        if (form_errors.length) {
            ctx.error_message = form_errors.join(' ');
            return renderBooking(req, res, ctx, { old: req.body });
        }
        if (flight.available_seats < passengers) {
            ctx.error_message = 'Not enough seats available on the outbound flight.';
            return renderBooking(req, res, ctx, { old: req.body });
        }
        if (return_flight && return_flight.available_seats < passengers) {
            ctx.error_message = 'Not enough seats available on the return flight.';
            return renderBooking(req, res, ctx, { old: req.body });
        }

        const conn = await pool.getConnection();
        try {
            await conn.beginTransaction();
            const returnFlightId = return_id > 0 ? return_id : null;

            const [bres] = await conn.query(
                "INSERT INTO bookings (user_id, flight_id, return_flight_id, passenger_count, total_price, class, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')",
                [req.session.user_id, flight_id, returnFlightId, passengers, total_price, cabin]
            );
            const bookingId = bres.insertId;

            const passengerIds = [];
            for (let i = 0; i < passengers; i++) {
                const passport = (passports[i] || '').trim();
                const [pres] = await conn.query(
                    'INSERT INTO passengers (booking_id, full_name, dob, gender, passport_no) VALUES (?, ?, ?, ?, ?)',
                    [bookingId, names[i].trim(), dobs[i].trim(), genders[i], passport === '' ? null : passport]
                );
                passengerIds.push(pres.insertId);
            }

            const legs = [[flight_id, outbound_price]];
            if (return_flight) legs.push([return_id, return_price]);

            // Seats the user picked on the seat map (re-validated under the
            // row lock below; fall back to auto-assign if missing/invalid).
            const parseSeats = (s) => (s || '').split(',').map((x) => x.trim().toUpperCase()).filter(Boolean);
            const chosenByLeg = {};
            chosenByLeg[flight_id] = parseSeats(req.body.seats_outbound);
            if (return_flight) chosenByLeg[return_id] = parseSeats(req.body.seats_return);
            const SEAT_RE = /^([1-9]|[12]\d|30)[A-F]$/;

            for (const [legFlightId, legFare] of legs) {
                let seats;
                const chosen = chosenByLeg[legFlightId];
                if (chosen && chosen.length === passengers && chosen.every((s) => SEAT_RE.test(s)) && new Set(chosen).size === chosen.length) {
                    const [lockRows] = await conn.query(
                        'SELECT seat_number FROM tickets WHERE flight_id = ? AND seat_number IS NOT NULL FOR UPDATE', [legFlightId]
                    );
                    const taken = new Set(lockRows.map((r) => r.seat_number));
                    seats = chosen.every((s) => !taken.has(s)) ? chosen : await assignSeats(conn, legFlightId, passengers);
                } else {
                    seats = await assignSeats(conn, legFlightId, passengers);
                }
                for (let i = 0; i < passengerIds.length; i++) {
                    await conn.query(
                        'INSERT INTO tickets (booking_id, passenger_id, flight_id, seat_number, class, fare) VALUES (?, ?, ?, ?, ?, ?)',
                        [bookingId, passengerIds[i], legFlightId, seats[i], cabin, legFare]
                    );
                }
            }

            await conn.commit();
            res.redirect('/payment?booking_id=' + bookingId);
        } catch (e) {
            await conn.rollback();
            ctx.error_message = (e.message && e.message.includes('Not enough seats'))
                ? 'Not enough seats available on this flight. Please pick another flight or fewer passengers.'
                : 'An error occurred while processing your booking.';
            renderBooking(req, res, ctx, { old: req.body });
        } finally {
            conn.release();
        }
    } catch (err) {
        next(err);
    }
});

// GET /payment + POST /payment (port of payment.php)
router.get('/payment', requireLogin, async (req, res, next) => {
    try {
        const booking_id = parseInt(req.query.booking_id, 10) || 0;
        if (booking_id <= 0) return res.redirect('/booking_history');

        const booking = await loadPaymentBooking(booking_id, req.session.user_id);
        if (!booking) return res.redirect('/booking_history');
        if (booking.status === 'confirmed') return res.redirect('/booking_confirmation?booking_id=' + booking_id);
        if (booking.status !== 'pending') return res.redirect('/booking_history');

        const tickets = await loadTickets(booking_id, booking.flight_id);
        res.render('payment', { title: 'Payment', booking, tickets, error_message: null });
    } catch (err) {
        next(err);
    }
});

router.post('/payment', requireLogin, verifyCsrf, async (req, res, next) => {
    try {
        const booking_id = parseInt(req.query.booking_id, 10) || 0;
        if (booking_id <= 0) return res.redirect('/booking_history');

        const booking = await loadPaymentBooking(booking_id, req.session.user_id);
        if (!booking) return res.redirect('/booking_history');
        if (booking.status === 'confirmed') return res.redirect('/booking_confirmation?booking_id=' + booking_id);
        if (booking.status !== 'pending') return res.redirect('/booking_history');

        const method = req.body.method || '';
        const renderError = async (msg) => {
            const tickets = await loadTickets(booking_id, booking.flight_id);
            res.render('payment', { title: 'Payment', booking, tickets, error_message: msg });
        };
        if (!['card', 'upi', 'netbanking'].includes(method)) {
            return renderError('Please choose a payment method.');
        }

        const conn = await pool.getConnection();
        try {
            await conn.beginTransaction();
            const [lrows] = await conn.query(
                'SELECT status, total_price FROM bookings WHERE booking_id = ? AND user_id = ? FOR UPDATE',
                [booking_id, req.session.user_id]
            );
            const locked = lrows[0];
            if (!locked || locked.status === 'cancelled') {
                await conn.commit();
                return res.redirect('/booking_history');
            }
            if (locked.status === 'confirmed') {
                await conn.commit();
                return res.redirect('/booking_confirmation?booking_id=' + booking_id);
            }

            const txnRef = 'TXN' + crypto.randomBytes(6).toString('hex').toUpperCase();
            await conn.query(
                "INSERT INTO payments (booking_id, amount, method, status, txn_ref, paid_at) VALUES (?, ?, ?, 'completed', ?, NOW())",
                [booking_id, locked.total_price, method, txnRef]
            );
            await conn.query("UPDATE bookings SET status = 'confirmed' WHERE booking_id = ?", [booking_id]);
            await conn.commit();
            res.redirect('/booking_confirmation?booking_id=' + booking_id);
        } catch (e) {
            await conn.rollback();
            await renderError('An error occurred while processing the payment. You have not been charged.');
        } finally {
            conn.release();
        }
    } catch (err) {
        next(err);
    }
});

async function loadPaymentBooking(booking_id, user_id) {
    const [rows] = await pool.query(`
        SELECT b.*,
               f1.flight_number AS outbound_flight, f1.departure_city AS from_city, f1.arrival_city AS to_city,
               f1.departure_time,
               f2.flight_number AS return_flight, f2.departure_time AS return_departure
        FROM bookings b
        JOIN flights f1 ON b.flight_id = f1.flight_id
        LEFT JOIN flights f2 ON b.return_flight_id = f2.flight_id
        WHERE b.booking_id = ? AND b.user_id = ?`, [booking_id, user_id]);
    return rows[0] || null;
}

async function loadTickets(booking_id, flight_id) {
    const [rows] = await pool.query(`
        SELECT t.ticket_id, t.flight_id, t.seat_number, t.class, t.fare, t.status,
               p.full_name, f.flight_number
        FROM tickets t
        JOIN passengers p ON t.passenger_id = p.passenger_id
        JOIN flights f ON t.flight_id = f.flight_id
        WHERE t.booking_id = ?
        ORDER BY (t.flight_id = ?) DESC, t.ticket_id`, [booking_id, flight_id]);
    return rows;
}

// GET /booking_confirmation (port of booking_confirmation.php)
router.get('/booking_confirmation', requireLogin, async (req, res, next) => {
    try {
        const booking_id = parseInt(req.query.booking_id, 10) || 0;
        if (booking_id <= 0) return res.redirect('/');

        const [brows] = await pool.query(`
            SELECT b.*,
                   f1.flight_number as outbound_flight, f1.airline as outbound_airline,
                   f1.departure_city as from_city, f1.arrival_city as to_city,
                   f1.departure_time, f1.arrival_time,
                   g1.terminal as outbound_terminal, g1.gate_number as outbound_gate,
                   g2.terminal as return_terminal, g2.gate_number as return_gate,
                   CASE WHEN b.class = 'economy' THEN f1.economy_price
                        WHEN b.class = 'business' THEN f1.business_price
                        WHEN b.class = 'first' THEN f1.first_price
                        ELSE f1.economy_price END as outbound_price,
                   f2.flight_number as return_flight, f2.airline as return_airline,
                   f2.departure_time as return_departure, f2.arrival_time as return_arrival,
                   CASE WHEN b.class = 'economy' THEN f2.economy_price
                        WHEN b.class = 'business' THEN f2.business_price
                        WHEN b.class = 'first' THEN f2.first_price
                        ELSE f2.economy_price END as return_price
            FROM bookings b
            JOIN flights f1 ON b.flight_id = f1.flight_id
            LEFT JOIN flights f2 ON b.return_flight_id = f2.flight_id
            LEFT JOIN gates g1 ON f1.gate_id = g1.gate_id
            LEFT JOIN gates g2 ON f2.gate_id = g2.gate_id
            WHERE b.booking_id = ? AND b.user_id = ?`, [booking_id, req.session.user_id]);
        const booking = brows[0];
        if (!booking) return res.redirect('/');

        const tickets = await loadTickets(booking_id, booking.flight_id);

        const [lrows] = await pool.query(`
            SELECT l.ticket_id, l.weight, l.status
            FROM luggage l
            JOIN tickets t ON l.ticket_id = t.ticket_id
            WHERE t.booking_id = ?
            ORDER BY l.luggage_id`, [booking_id]);
        const luggage_by_ticket = {};
        for (const bag of lrows) {
            (luggage_by_ticket[bag.ticket_id] = luggage_by_ticket[bag.ticket_id] || []).push(bag);
        }

        const [prows] = await pool.query('SELECT * FROM payments WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1', [booking_id]);
        const payment = prows[0] || null;

        res.render('booking_confirmation', {
            title: 'Booking Confirmation',
            booking, tickets, luggage_by_ticket, payment,
            class_display: (booking.class ? booking.class[0].toUpperCase() + booking.class.slice(1) : 'Economy'),
            method_labels: { card: 'Card', upi: 'UPI', netbanking: 'Netbanking' },
        });
    } catch (err) {
        next(err);
    }
});

// GET /booking_history (port of booking_history.php)
router.get('/booking_history', requireLogin, async (req, res, next) => {
    try {
        const [bookings] = await pool.query(`
            SELECT b.*,
                   f1.flight_number as outbound_flight, f1.airline as outbound_airline,
                   f1.departure_city as from_city, f1.arrival_city as to_city,
                   f1.departure_time, f1.arrival_time, f1.status as outbound_status,
                   g1.terminal as outbound_terminal, g1.gate_number as outbound_gate,
                   CASE WHEN b.class = 'economy' THEN f1.economy_price
                        WHEN b.class = 'business' THEN f1.business_price
                        WHEN b.class = 'first' THEN f1.first_price
                        ELSE f1.economy_price END as outbound_price,
                   f2.flight_number as return_flight, f2.airline as return_airline,
                   f2.departure_time as return_departure, f2.arrival_time as return_arrival, f2.status as return_status,
                   CASE WHEN b.class = 'economy' THEN f2.economy_price
                        WHEN b.class = 'business' THEN f2.business_price
                        WHEN b.class = 'first' THEN f2.first_price
                        ELSE f2.economy_price END as return_price,
                   (SELECT pay.status FROM payments pay WHERE pay.booking_id = b.booking_id ORDER BY pay.payment_id DESC LIMIT 1) as payment_status
            FROM bookings b
            JOIN flights f1 ON b.flight_id = f1.flight_id
            LEFT JOIN flights f2 ON b.return_flight_id = f2.flight_id
            LEFT JOIN gates g1 ON f1.gate_id = g1.gate_id
            WHERE b.user_id = ?
            ORDER BY b.booking_date DESC`, [req.session.user_id]);

        const now = Date.now();
        for (const b of bookings) {
            b.can_cancel = ['pending', 'confirmed'].includes(b.status)
                && new Date(b.departure_time).getTime() > now
                && !['departed', 'arrived'].includes(b.outbound_status);
        }

        res.render('booking_history', {
            title: 'My Bookings', active: 'bookings', bookings,
            flash: { cancelled: 'cancelled' in req.query, cancel_error: req.query.cancel_error || '' },
        });
    } catch (err) {
        next(err);
    }
});

// POST /cancel_booking (port of cancel_booking.php)
router.post('/cancel_booking', requireLogin, verifyCsrf, async (req, res) => {
    const fail = (msg) => res.redirect('/booking_history?cancel_error=' + encodeURIComponent(msg));
    const booking_id = parseInt(req.body.booking_id, 10) || 0;
    if (booking_id <= 0) return fail('Invalid booking.');

    const conn = await pool.getConnection();
    try {
        await conn.beginTransaction();
        const [rows] = await conn.query(`
            SELECT b.booking_id, b.flight_id, b.return_flight_id, b.passenger_count, b.status,
                   f.departure_time, f.status AS flight_status
            FROM bookings b
            JOIN flights f ON b.flight_id = f.flight_id
            WHERE b.booking_id = ? AND b.user_id = ?
            FOR UPDATE`, [booking_id, req.session.user_id]);
        const booking = rows[0];

        if (!booking) { await conn.rollback(); return fail('Booking not found.'); }
        if (!['pending', 'confirmed'].includes(booking.status)) {
            await conn.rollback();
            return fail('This booking has already been cancelled.');
        }
        if (new Date(booking.departure_time).getTime() <= Date.now() || ['departed', 'arrived'].includes(booking.flight_status)) {
            await conn.rollback();
            return fail('This booking can no longer be cancelled: the outbound flight has already departed.');
        }

        await conn.query('CALL sp_cancel_booking(?)', [booking_id]);
        await conn.commit();
        res.redirect('/booking_history?cancelled=1');
    } catch (e) {
        await conn.rollback();
        fail('An error occurred while cancelling the booking.');
    } finally {
        conn.release();
    }
});

module.exports = router;
