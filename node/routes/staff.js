'use strict';

const express = require('express');
const router = express.Router();
const { pool } = require('../db');
const { requireStaff } = require('../middleware/auth');
const { verifyCsrf } = require('../middleware/csrf');

const FLIGHT_STATUSES = ['scheduled', 'delayed', 'boarding', 'departed', 'arrived', 'cancelled'];
const LUGGAGE_STATUSES = ['checked_in', 'loaded', 'arrived', 'lost'];
const roleLabel = (r) => String(r).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
const isInt = (v) => /^\d+$/.test(String(v));
const isNum = (v) => v !== '' && v !== undefined && v !== null && !isNaN(Number(v));

router.use(requireStaff);

// ---- Departures board ---------------------------------------------------
async function renderBoard(req, res, msg = {}) {
    const [flights] = await pool.query(`
        SELECT f.*, a.model AS aircraft_model, g.terminal, g.gate_number,
               (SELECT COUNT(*) FROM tickets t WHERE t.flight_id = f.flight_id AND t.status IN ('confirmed','checked_in')) AS ticket_count
        FROM flights f
        LEFT JOIN aircraft a ON f.aircraft_id = a.aircraft_id
        LEFT JOIN gates g ON f.gate_id = g.gate_id
        WHERE (f.departure_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR))
           OR (DATE(f.departure_time) = CURDATE() AND f.status NOT IN ('arrived','cancelled'))
        ORDER BY f.departure_time`);
    const [open_gates] = await pool.query("SELECT gate_id, terminal, gate_number FROM gates WHERE status = 'open' ORDER BY terminal, CAST(gate_number AS UNSIGNED)");
    res.render('staff/index', { title: 'Staff Dashboard', active: 'staff', flight_statuses: FLIGHT_STATUSES, flights, open_gates, ...msg });
}

router.get('/', (req, res, next) => renderBoard(req, res).catch(next));

router.post('/', verifyCsrf, async (req, res, next) => {
    const msg = {};
    try {
        const b = req.body;
        if (b.update_status && isInt(b.flight_id || '')) {
            if (!FLIGHT_STATUSES.includes(b.status)) msg.error_message = 'Invalid flight status.';
            else { await pool.query('UPDATE flights SET status = ? WHERE flight_id = ?', [b.status, b.flight_id]); msg.success_message = `Flight #${parseInt(b.flight_id, 10)} status set to ${b.status}.`; }
        } else if (b.assign_gate && isInt(b.flight_id || '')) {
            const gate_id = b.gate_id || '';
            if (gate_id === '') {
                await pool.query('UPDATE flights SET gate_id = NULL WHERE flight_id = ?', [b.flight_id]);
                msg.success_message = `Gate cleared for flight #${parseInt(b.flight_id, 10)}.`;
            } else if (!isNum(gate_id)) {
                msg.error_message = 'Invalid gate.';
            } else {
                const [grows] = await pool.query("SELECT terminal, gate_number FROM gates WHERE gate_id = ? AND status = 'open'", [gate_id]);
                const gate = grows[0];
                if (!gate) msg.error_message = 'That gate is closed or does not exist — choose an open gate.';
                else { await pool.query('UPDATE flights SET gate_id = ? WHERE flight_id = ?', [gate_id, b.flight_id]); msg.success_message = `Flight #${parseInt(b.flight_id, 10)} assigned to gate ${gate.terminal}-${gate.gate_number}.`; }
            }
        }
        await renderBoard(req, res, msg);
    } catch (err) { next(err); }
});

// ---- Flight detail ------------------------------------------------------
async function loadFlight(flight_id) {
    const [rows] = await pool.query(`
        SELECT f.*, a.model AS aircraft_model, a.capacity, g.terminal, g.gate_number
        FROM flights f
        LEFT JOIN aircraft a ON f.aircraft_id = a.aircraft_id
        LEFT JOIN gates g ON f.gate_id = g.gate_id
        WHERE f.flight_id = ?`, [flight_id]);
    return rows[0] || null;
}

async function renderDetail(req, res, flight_id, msg = {}) {
    const flight = await loadFlight(flight_id);
    if (!flight) return res.redirect('/staff');
    const crew_locked = ['departed', 'arrived', 'cancelled'].includes(flight.status);

    const [crew] = await pool.query(`
        SELECT efa.assignment_id, em.full_name, em.role, em.shift
        FROM employee_flight_assignment efa
        JOIN employees em ON efa.employee_id = em.employee_id
        WHERE efa.flight_id = ?
        ORDER BY FIELD(em.role, 'pilot', 'cabin_crew', 'ground', 'security'), em.full_name`, [flight_id]);

    const [unassigned] = await pool.query(`
        SELECT em.employee_id, em.full_name, em.role, em.shift
        FROM employees em
        WHERE em.employee_id NOT IN (SELECT employee_id FROM employee_flight_assignment WHERE flight_id = ?)
        ORDER BY FIELD(em.role, 'pilot', 'cabin_crew', 'ground', 'security'), em.full_name`, [flight_id]);

    const [tickets] = await pool.query(`
        SELECT t.ticket_id, t.seat_number, t.class, t.status AS ticket_status,
               p.full_name, b.booking_id, b.status AS booking_status
        FROM tickets t
        JOIN passengers p ON t.passenger_id = p.passenger_id
        JOIN bookings b ON t.booking_id = b.booking_id
        WHERE t.flight_id = ?
        ORDER BY t.ticket_id`, [flight_id]);

    const [bags] = await pool.query(`
        SELECT l.*
        FROM luggage l
        JOIN tickets t ON l.ticket_id = t.ticket_id
        WHERE t.flight_id = ?
        ORDER BY l.luggage_id`, [flight_id]);
    const luggage_by_ticket = {};
    for (const bag of bags) (luggage_by_ticket[bag.ticket_id] = luggage_by_ticket[bag.ticket_id] || []).push(bag);

    const checked_in_tickets = tickets.filter((t) => t.ticket_status === 'checked_in');

    res.render('staff/flight_detail', {
        title: flight.flight_number + ' - Flight Detail',
        active: 'staff', flight, crew_locked, crew, unassigned, tickets,
        luggage_by_ticket, checked_in_tickets, luggage_statuses: LUGGAGE_STATUSES,
        roleLabel, detailAction: req.originalUrl, ...msg,
    });
}

router.get('/flight_detail', async (req, res, next) => {
    try {
        const flight_id = parseInt(req.query.flight_id, 10) || 0;
        if (flight_id <= 0) return res.redirect('/staff');
        await renderDetail(req, res, flight_id);
    } catch (err) { next(err); }
});

router.post('/flight_detail', verifyCsrf, async (req, res, next) => {
    try {
        const flight_id = parseInt(req.query.flight_id, 10) || 0;
        if (flight_id <= 0) return res.redirect('/staff');
        let flight = await loadFlight(flight_id);
        if (!flight) return res.redirect('/staff');
        const crew_locked = ['departed', 'arrived', 'cancelled'].includes(flight.status);

        const b = req.body;
        const msg = {};

        if (b.assign_employee) {
            if (crew_locked) msg.error_message = `Crew cannot be changed: this flight is ${flight.status}.`;
            else if (!isInt(b.employee_id || '')) msg.error_message = 'Please choose an employee.';
            else {
                try { await pool.query('INSERT INTO employee_flight_assignment (employee_id, flight_id) VALUES (?, ?)', [b.employee_id, flight_id]); msg.success_message = 'Employee assigned to this flight.'; }
                catch (e) { msg.error_message = (e.errno === 1062) ? 'That employee is already assigned to this flight.' : 'Error assigning employee: ' + e.message; }
            }
        } else if (b.remove_assignment && isInt(b.remove_assignment)) {
            if (crew_locked) msg.error_message = `Crew cannot be changed: this flight is ${flight.status}.`;
            else { const [r] = await pool.query('DELETE FROM employee_flight_assignment WHERE assignment_id = ? AND flight_id = ?', [b.remove_assignment, flight_id]); msg.success_message = r.affectedRows ? 'Assignment removed.' : 'Assignment not found.'; }
        } else if (b.check_in && isInt(b.check_in)) {
            const [trows] = await pool.query(`
                SELECT t.ticket_id, t.status AS ticket_status, bk.status AS booking_status
                FROM tickets t JOIN bookings bk ON t.booking_id = bk.booking_id
                WHERE t.ticket_id = ? AND t.flight_id = ?`, [b.check_in, flight_id]);
            const t = trows[0];
            if (!t) msg.error_message = 'Ticket not found on this flight.';
            else if (t.ticket_status === 'cancelled' || t.booking_status === 'cancelled') msg.error_message = 'Cancelled tickets cannot be checked in.';
            else if (t.booking_status === 'pending') msg.error_message = 'This booking has not been paid yet — it cannot be checked in.';
            else if (t.ticket_status === 'checked_in') msg.error_message = 'This ticket is already checked in.';
            else { await pool.query("UPDATE tickets SET status = 'checked_in' WHERE ticket_id = ?", [t.ticket_id]); msg.success_message = `Ticket #${t.ticket_id} checked in.`; }
        } else if (b.add_luggage && isInt(b.ticket_id || '')) {
            const weight = (b.weight || '').trim();
            const [[row]] = await pool.query('SELECT status FROM tickets WHERE ticket_id = ? AND flight_id = ?', [b.ticket_id, flight_id]);
            const ticket_status = row ? row.status : undefined;
            if (ticket_status === undefined) msg.error_message = 'Ticket not found on this flight.';
            else if (ticket_status !== 'checked_in') msg.error_message = 'Luggage can only be added to checked-in tickets.';
            else if (!isNum(weight) || Number(weight) <= 0 || Number(weight) > 32) msg.error_message = 'Luggage weight must be between 0 and 32 kg.';
            else { const w = Math.round(Number(weight) * 100) / 100; await pool.query('INSERT INTO luggage (ticket_id, weight) VALUES (?, ?)', [b.ticket_id, w]); msg.success_message = `Luggage (${w} kg) added.`; }
        } else if (b.update_luggage && isInt(b.luggage_id || '')) {
            if (!LUGGAGE_STATUSES.includes(b.luggage_status)) msg.error_message = 'Invalid luggage status.';
            else {
                const [r] = await pool.query(`
                    UPDATE luggage l JOIN tickets t ON l.ticket_id = t.ticket_id
                    SET l.status = ? WHERE l.luggage_id = ? AND t.flight_id = ?`, [b.luggage_status, b.luggage_id, flight_id]);
                msg.success_message = r.affectedRows ? 'Luggage status updated.' : 'Luggage already in that status.';
            }
        }

        await renderDetail(req, res, flight_id, msg);
    } catch (err) { next(err); }
});

module.exports = router;
