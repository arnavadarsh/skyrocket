'use strict';

const express = require('express');
const router = express.Router();
const { pool } = require('../db');
const { requireAdmin } = require('../middleware/auth');
const { verifyCsrf } = require('../middleware/csrf');

const FLIGHT_STATUSES = ['scheduled', 'delayed', 'boarding', 'departed', 'arrived', 'cancelled'];
const MAINTENANCE_STATUSES = ['active', 'maintenance', 'retired'];
const EMPLOYEE_ROLES = ['pilot', 'cabin_crew', 'ground', 'security'];
const SHIFTS = ['morning', 'evening', 'night'];
const roleLabel = (r) => String(r).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
const isInt = (v) => /^\d+$/.test(String(v));

// Every page in this area is admin-only.
router.use(requireAdmin);

// ---- Dashboard ----------------------------------------------------------
router.get('/', async (req, res, next) => {
    try {
        const one = async (sql) => (await pool.query(sql))[0][0].c;
        const bookings_count = await one('SELECT COUNT(*) AS c FROM bookings');
        const users_count = await one('SELECT COUNT(*) AS c FROM users');
        const flights_count = await one('SELECT COUNT(*) AS c FROM flights');
        const [[rev]] = await pool.query("SELECT COALESCE(SUM(amount), 0) AS c FROM payments WHERE status = 'completed'");
        const pending_payments = await one("SELECT COUNT(*) AS c FROM bookings WHERE status = 'pending'");

        const [recent_bookings] = await pool.query(`
            SELECT b.booking_id, u.username, f.flight_number, b.booking_date, b.total_price, b.status,
                   CASE WHEN b.class = 'economy' THEN 'Economy'
                        WHEN b.class = 'business' THEN 'Business'
                        WHEN b.class = 'first' THEN 'First' ELSE 'Economy' END as class_type
            FROM bookings b
            JOIN users u ON b.user_id = u.user_id
            JOIN flights f ON b.flight_id = f.flight_id
            ORDER BY b.booking_date DESC LIMIT 5`);

        res.render('admin/index', {
            title: 'Admin Dashboard', active: 'dashboard',
            bookings_count, users_count, flights_count,
            total_revenue: rev.c, pending_payments, recent_bookings,
        });
    } catch (err) { next(err); }
});

// ---- Bookings -----------------------------------------------------------
async function renderBookings(req, res, msg = {}) {
    const [bookings] = await pool.query(`
        SELECT b.*,
               u.username, u.email, u.first_name as user_first_name, u.last_name as user_last_name,
               f1.flight_number as outbound_flight, f1.airline as outbound_airline,
               f1.departure_city as from_city, f1.arrival_city as to_city,
               f1.departure_time, f1.arrival_time,
               f2.flight_number as return_flight, f2.airline as return_airline,
               f2.departure_time as return_departure, f2.arrival_time as return_arrival,
               (SELECT GROUP_CONCAT(p.full_name ORDER BY p.passenger_id SEPARATOR ', ') FROM passengers p WHERE p.booking_id = b.booking_id) as passenger_names,
               (SELECT pay.status FROM payments pay WHERE pay.booking_id = b.booking_id ORDER BY pay.payment_id DESC LIMIT 1) as payment_status
        FROM bookings b
        JOIN users u ON b.user_id = u.user_id
        JOIN flights f1 ON b.flight_id = f1.flight_id
        LEFT JOIN flights f2 ON b.return_flight_id = f2.flight_id
        ORDER BY b.booking_date DESC`);
    res.render('admin/bookings', { title: 'Manage Bookings', active: 'bookings', bookings, ...msg });
}

router.get('/bookings', (req, res, next) => renderBookings(req, res).catch(next));

router.post('/bookings', verifyCsrf, async (req, res, next) => {
    const msg = {};
    try {
        if (req.body.cancel_booking && isInt(req.body.cancel_booking)) {
            const booking_id = req.body.cancel_booking;
            const conn = await pool.getConnection();
            try {
                await conn.beginTransaction();
                const [rows] = await conn.query('SELECT flight_id, status FROM bookings WHERE booking_id = ? FOR UPDATE', [booking_id]);
                const booking = rows[0];
                if (!booking) { await conn.rollback(); msg.error_message = 'Booking not found.'; }
                else if (booking.status === 'cancelled') { await conn.rollback(); msg.error_message = `Booking #${booking_id} is already cancelled.`; }
                else {
                    await conn.query('CALL sp_cancel_booking(?)', [booking_id]);
                    await conn.commit();
                    msg.success_message = `Booking #${booking_id} has been cancelled (tickets released, refund issued where applicable).`;
                }
            } catch (e) {
                await conn.rollback();
                msg.error_message = 'Error cancelling booking: ' + e.message;
            } finally { conn.release(); }
        }
        await renderBookings(req, res, msg);
    } catch (err) { next(err); }
});

// ---- Users --------------------------------------------------------------
async function renderUsers(req, res, msg = {}) {
    const [users] = await pool.query('SELECT * FROM users ORDER BY created_at DESC');
    res.render('admin/users', { title: 'Manage Users', active: 'users', users, ...msg });
}

router.get('/users', (req, res, next) => renderUsers(req, res).catch(next));

router.post('/users', verifyCsrf, async (req, res, next) => {
    const msg = {};
    try {
        if (req.body.delete_user && isInt(req.body.delete_user)) {
            const user_id = req.body.delete_user;
            if (user_id == req.session.user_id) {
                msg.error_message = 'You cannot delete your own account.';
            } else {
                const [[cnt]] = await pool.query('SELECT COUNT(*) AS c FROM bookings WHERE user_id = ?', [user_id]);
                if (cnt.c > 0) msg.error_message = 'Cannot delete user with existing bookings. Delete their bookings first.';
                else {
                    await pool.query('DELETE FROM users WHERE user_id = ?', [user_id]);
                    msg.success_message = 'User has been deleted successfully.';
                }
            }
        } else if (req.body.update_role && isInt(req.body.user_id || '')) {
            const user_id = req.body.user_id;
            const new_role = req.body.role || '';
            if (user_id == req.session.user_id) msg.error_message = 'You cannot change your own role while logged in.';
            else if (!['user', 'staff', 'admin'].includes(new_role)) msg.error_message = 'Invalid role.';
            else {
                await pool.query('UPDATE users SET role = ? WHERE user_id = ?', [new_role, user_id]);
                msg.success_message = 'User role updated to ' + new_role + '.';
            }
        }
        await renderUsers(req, res, msg);
    } catch (err) { next(err); }
});

// ---- Flights ------------------------------------------------------------
async function renderFlights(req, res, msg = {}) {
    const [active_aircraft] = await pool.query("SELECT aircraft_id, model, capacity FROM aircraft WHERE maintenance_status = 'active' ORDER BY model, aircraft_id");
    const [open_gates] = await pool.query("SELECT gate_id, terminal, gate_number FROM gates WHERE status = 'open' ORDER BY terminal, CAST(gate_number AS UNSIGNED)");
    const [flights] = await pool.query(`
        SELECT f.*, a.model AS aircraft_model, g.terminal, g.gate_number
        FROM flights f
        LEFT JOIN aircraft a ON f.aircraft_id = a.aircraft_id
        LEFT JOIN gates g ON f.gate_id = g.gate_id
        ORDER BY f.departure_time`);
    res.render('admin/flights', {
        title: 'Manage Flights', active: 'flights',
        flight_statuses: FLIGHT_STATUSES, active_aircraft, open_gates, flights, ...msg,
    });
}

router.get('/flights', (req, res, next) => renderFlights(req, res).catch(next));

router.post('/flights', verifyCsrf, async (req, res, next) => {
    const msg = {};
    try {
        const b = req.body;
        if (b.delete_flight && isInt(b.delete_flight)) {
            const [[cnt]] = await pool.query('SELECT COUNT(*) AS c FROM bookings WHERE flight_id = ? OR return_flight_id = ?', [b.delete_flight, b.delete_flight]);
            if (cnt.c > 0) msg.error_message = 'Cannot delete flight with existing bookings.';
            else { await pool.query('DELETE FROM flights WHERE flight_id = ?', [b.delete_flight]); msg.success_message = 'Flight has been deleted successfully.'; }
        } else if (b.update_status && isInt(b.flight_id || '')) {
            if (!FLIGHT_STATUSES.includes(b.status)) msg.error_message = 'Invalid flight status.';
            else { await pool.query('UPDATE flights SET status = ? WHERE flight_id = ?', [b.status, b.flight_id]); msg.success_message = `Flight #${parseInt(b.flight_id, 10)} status set to ${b.status}.`; }
        } else if (b.add_flight) {
            const aircraft_id = b.aircraft_id || '';
            const gate_id = b.gate_id || '';
            const available_seats = (b.available_seats || '').trim();

            let aircraft = null;
            if (aircraft_id !== '') {
                const [arows] = await pool.query("SELECT * FROM aircraft WHERE aircraft_id = ? AND maintenance_status = 'active'", [aircraft_id]);
                aircraft = arows[0] || null;
            }
            let gate_ok = true;
            if (gate_id !== '') {
                const [[gc]] = await pool.query("SELECT COUNT(*) AS c FROM gates WHERE gate_id = ? AND status = 'open'", [gate_id]);
                gate_ok = gc.c > 0;
            }

            if (aircraft_id !== '' && !aircraft) msg.error_message = 'Please choose an active aircraft.';
            else if (!gate_ok) msg.error_message = 'Please choose an open gate.';
            else if (available_seats === '' && !aircraft) msg.error_message = 'Enter the available seats or choose an aircraft to default to its capacity.';
            else if (available_seats !== '' && (!isInt(available_seats) || parseInt(available_seats, 10) < 1)) msg.error_message = 'Available seats must be a whole number greater than 0.';
            else if (aircraft && available_seats !== '' && parseInt(available_seats, 10) > aircraft.capacity) msg.error_message = `Available seats (${parseInt(available_seats, 10)}) cannot exceed the ${aircraft.model}'s capacity of ${aircraft.capacity}.`;
            else {
                const seats = available_seats === '' ? aircraft.capacity : parseInt(available_seats, 10);
                try {
                    await pool.query(
                        'INSERT INTO flights (flight_number, airline, departure_city, arrival_city, departure_time, arrival_time, economy_price, business_price, first_price, available_seats, aircraft_id, gate_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [b.flight_number, b.airline, b.departure_city, b.arrival_city, b.departure_time, b.arrival_time, b.economy_price, b.business_price, b.first_price, seats,
                            aircraft_id === '' ? null : aircraft_id, gate_id === '' ? null : gate_id]
                    );
                    msg.success_message = 'Flight has been added successfully.';
                } catch (e) { msg.error_message = 'Error adding flight: ' + e.message; }
            }
        }
        await renderFlights(req, res, msg);
    } catch (err) { next(err); }
});

// ---- Aircraft -----------------------------------------------------------
function validateAircraft(body) {
    const model = (body.model || '').trim();
    const capacity = body.capacity || '';
    const status = body.maintenance_status || '';
    if (model === '' || model.length > 50) return { error: 'Please enter a model name (max 50 characters).' };
    if (!isInt(String(capacity)) || parseInt(capacity, 10) < 1) return { error: 'Capacity must be a whole number greater than 0.' };
    if (!MAINTENANCE_STATUSES.includes(status)) return { error: 'Invalid maintenance status.' };
    return { value: [model, parseInt(capacity, 10), status] };
}

async function renderAircraft(req, res, msg = {}) {
    const [aircraft_list] = await pool.query(`
        SELECT a.*, COUNT(f.flight_id) AS flight_count
        FROM aircraft a
        LEFT JOIN flights f ON f.aircraft_id = a.aircraft_id
        GROUP BY a.aircraft_id ORDER BY a.aircraft_id`);
    res.render('admin/aircraft', { title: 'Manage Aircraft', active: 'aircraft', maintenance_statuses: MAINTENANCE_STATUSES, aircraft_list, ...msg });
}

router.get('/aircraft', (req, res, next) => renderAircraft(req, res).catch(next));

router.post('/aircraft', verifyCsrf, async (req, res, next) => {
    const msg = {};
    try {
        const b = req.body;
        if (b.add_aircraft) {
            const v = validateAircraft(b);
            if (v.error) msg.error_message = v.error;
            else { await pool.query('INSERT INTO aircraft (model, capacity, maintenance_status) VALUES (?, ?, ?)', v.value); msg.success_message = 'Aircraft has been added.'; }
        } else if (b.update_aircraft && isInt(b.aircraft_id || '')) {
            const v = validateAircraft(b);
            if (v.error) msg.error_message = v.error;
            else {
                const [[mx]] = await pool.query('SELECT MAX(available_seats) AS m FROM flights WHERE aircraft_id = ?', [b.aircraft_id]);
                const max_seats = mx.m || 0;
                if (v.value[1] < max_seats) msg.error_message = `Capacity cannot be set below ${max_seats}: a flight using this aircraft has that many seats.`;
                else { await pool.query('UPDATE aircraft SET model = ?, capacity = ?, maintenance_status = ? WHERE aircraft_id = ?', [...v.value, b.aircraft_id]); msg.success_message = `Aircraft #${parseInt(b.aircraft_id, 10)} has been updated.`; }
            }
        } else if (b.delete_aircraft && isInt(b.delete_aircraft)) {
            try { await pool.query('DELETE FROM aircraft WHERE aircraft_id = ?', [b.delete_aircraft]); msg.success_message = 'Aircraft has been deleted.'; }
            catch (e) { msg.error_message = e.code === 'ER_ROW_IS_REFERENCED_2' || e.errno === 1451 ? 'This aircraft is assigned to one or more flights — reassign its flights first.' : 'Error deleting aircraft: ' + e.message; }
        }
        await renderAircraft(req, res, msg);
    } catch (err) { next(err); }
});

// ---- Employees ----------------------------------------------------------
function validateEmployee(body) {
    const name = (body.full_name || '').trim();
    const role = body.role || '';
    const contact = (body.contact_info || '').trim();
    const shift = body.shift || '';
    if (name === '' || name.length > 100) return { error: 'Please enter a full name (max 100 characters).' };
    if (!EMPLOYEE_ROLES.includes(role)) return { error: 'Invalid employee role.' };
    if (contact.length > 100) return { error: 'Contact info is too long (max 100 characters).' };
    if (!SHIFTS.includes(shift)) return { error: 'Invalid shift.' };
    return { value: [name, role, contact === '' ? null : contact, shift] };
}

async function renderEmployees(req, res, msg = {}) {
    const [employee_list] = await pool.query(`
        SELECT em.*, COUNT(efa.assignment_id) AS assignment_count
        FROM employees em
        LEFT JOIN employee_flight_assignment efa ON efa.employee_id = em.employee_id
        GROUP BY em.employee_id ORDER BY em.employee_id`);
    res.render('admin/employees', { title: 'Manage Employees', active: 'employees', employee_roles: EMPLOYEE_ROLES, shifts: SHIFTS, roleLabel, employee_list, ...msg });
}

router.get('/employees', (req, res, next) => renderEmployees(req, res).catch(next));

router.post('/employees', verifyCsrf, async (req, res, next) => {
    const msg = {};
    try {
        const b = req.body;
        if (b.add_employee) {
            const v = validateEmployee(b);
            if (v.error) msg.error_message = v.error;
            else { await pool.query('INSERT INTO employees (full_name, role, contact_info, shift) VALUES (?, ?, ?, ?)', v.value); msg.success_message = 'Employee has been added.'; }
        } else if (b.update_employee && isInt(b.employee_id || '')) {
            const v = validateEmployee(b);
            if (v.error) msg.error_message = v.error;
            else { await pool.query('UPDATE employees SET full_name = ?, role = ?, contact_info = ?, shift = ? WHERE employee_id = ?', [...v.value, b.employee_id]); msg.success_message = `Employee #${parseInt(b.employee_id, 10)} has been updated.`; }
        } else if (b.delete_employee && isInt(b.delete_employee)) {
            try { await pool.query('DELETE FROM employees WHERE employee_id = ?', [b.delete_employee]); msg.success_message = 'Employee has been deleted (their flight assignments were removed).'; }
            catch (e) { msg.error_message = 'Error deleting employee: ' + e.message; }
        }
        await renderEmployees(req, res, msg);
    } catch (err) { next(err); }
});

// ---- Reports ------------------------------------------------------------
const REPORTS = [
    { title: '1. Departures per day (next 7 days)', sql: `SELECT DATE(departure_time) AS day,\n       COUNT(*) AS departures\nFROM flights\nWHERE departure_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)\nGROUP BY DATE(departure_time)\nORDER BY day` },
    { title: '2. Top 5 routes by tickets sold', note: 'RANK() is a window function: ties share a rank (1, 1, 3 ...).', sql: "SELECT RANK() OVER (ORDER BY COUNT(t.ticket_id) DESC) AS `rank`,\n       CONCAT(f.departure_city, ' → ', f.arrival_city) AS route,\n       COUNT(t.ticket_id) AS tickets_sold\nFROM tickets t\nJOIN flights f ON t.flight_id = f.flight_id\nWHERE t.status <> 'cancelled'\nGROUP BY f.departure_city, f.arrival_city\nORDER BY tickets_sold DESC, route\nLIMIT 5" },
    { title: '3. Revenue by airline', note: 'From vw_revenue_by_airline — fare snapshots on tickets keep this exact even after price edits.', sql: 'SELECT *\nFROM vw_revenue_by_airline\nORDER BY revenue DESC', money: ['revenue'] },
    { title: '4. Revenue by month', sql: "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month,\n       COUNT(*) AS payments,\n       SUM(amount) AS revenue\nFROM payments\nWHERE status = 'completed'\nGROUP BY DATE_FORMAT(paid_at, '%Y-%m')\nORDER BY month", money: ['revenue'] },
    { title: '5a. Nearly-full upcoming flights (occupancy ≥ 80%)', note: 'Filters on a computed column of vw_flight_occupancy.', sql: 'SELECT flight_number, route, departure_time, status, seats_sold, available_seats, occupancy_pct\nFROM vw_flight_occupancy\nWHERE departure_time > NOW() AND occupancy_pct >= 80\nORDER BY occupancy_pct DESC', percent: ['occupancy_pct'] },
    { title: '5b. Five emptiest upcoming flights', sql: 'SELECT flight_number, route, departure_time, status, seats_sold, available_seats, occupancy_pct\nFROM vw_flight_occupancy\nWHERE departure_time > NOW()\nORDER BY COALESCE(occupancy_pct, 0) ASC, departure_time\nLIMIT 5', percent: ['occupancy_pct'] },
    { title: '6. Disruptions by airline', note: 'Conditional aggregation: SUM over a boolean expression counts matching rows.', sql: "SELECT airline,\n       SUM(status = 'delayed') AS delayed_flights,\n       SUM(status = 'cancelled') AS cancelled_flights,\n       COUNT(*) AS total_flights\nFROM flights\nGROUP BY airline\nORDER BY (SUM(status = 'delayed') + SUM(status = 'cancelled')) DESC, airline" },
    { title: '7. Top 5 customers by spend', sql: "SELECT u.username,\n       CONCAT(u.first_name, ' ', u.last_name) AS name,\n       COUNT(DISTINCT b.booking_id) AS bookings,\n       COALESCE(SUM(p.amount), 0) AS total_spend\nFROM users u\nLEFT JOIN bookings b ON b.user_id = u.user_id\nLEFT JOIN payments p ON p.booking_id = b.booking_id AND p.status = 'completed'\nGROUP BY u.user_id, u.username, u.first_name, u.last_name\nORDER BY total_spend DESC\nLIMIT 5", money: ['total_spend'] },
    { title: '8. Crew workload', note: 'LEFT JOIN keeps employees with zero assignments in the result.', sql: 'SELECT em.full_name,\n       em.role,\n       em.shift,\n       COUNT(efa.assignment_id) AS flights_assigned\nFROM employees em\nLEFT JOIN employee_flight_assignment efa ON efa.employee_id = em.employee_id\nGROUP BY em.employee_id, em.full_name, em.role, em.shift\nORDER BY flights_assigned DESC, em.full_name' },
];

router.get('/reports', async (req, res, next) => {
    try {
        const reports = [];
        for (const r of REPORTS) {
            const [rows] = await pool.query(r.sql);
            reports.push({ ...r, rows });
        }
        res.render('admin/reports', { title: 'Reports', active: 'reports', reports });
    } catch (err) { next(err); }
});

// ---- Custom read-only SQL console ---------------------------------------
function renderQuery(req, res, extra = {}) {
    res.render('admin/custom_query', {
        title: 'Custom SQL Query', active: 'custom_query',
        query: '', error: '', success: '', result: null, ...extra,
    });
}

router.get('/custom_query', (req, res) => renderQuery(req, res));

router.post('/custom_query', verifyCsrf, async (req, res) => {
    const query = (req.body.sql_query || '').trim();
    if (!req.body.execute_query) return renderQuery(req, res, { query });

    if (query === '') return renderQuery(req, res, { query, error: 'Please enter a SQL query.' });
    if (!/^\s*(SELECT|EXPLAIN|SHOW|DESCRIBE)\b/i.test(query)) {
        return renderQuery(req, res, { query, error: 'This is a read-only console: only SELECT, EXPLAIN, SHOW and DESCRIBE queries are allowed.' });
    }
    if (query.replace(/[;\s]+$/, '').includes(';')) {
        return renderQuery(req, res, { query, error: 'This is a read-only console: only a single statement is allowed (no semicolons).' });
    }
    try {
        const [rows] = await pool.query(query);
        const result = Array.isArray(rows) ? rows : [];
        renderQuery(req, res, { query, result, success: `Query executed successfully. ${result.length} rows returned.` });
    } catch (e) {
        renderQuery(req, res, { query, error: 'Error executing query: ' + e.message });
    }
});

module.exports = router;
