'use strict';

const express = require('express');
const router = express.Router();
const { pool } = require('../db');

const CLASSES = ['economy', 'business', 'first'];
const toArr = (v) => (v === undefined ? [] : Array.isArray(v) ? v : [v]);

// GET / — home page
router.get('/', async (req, res, next) => {
    try {
        const [cityRows] = await pool.query(
            'SELECT departure_city AS c FROM flights UNION SELECT arrival_city FROM flights ORDER BY c'
        );
        res.render('index', { title: 'Home', active: 'home', cities: cityRows.map((r) => r.c) });
    } catch (err) {
        next(err);
    }
});

// GET /flights — search results (port of flights.php)
router.get('/flights', async (req, res, next) => {
    try {
        const from = req.query.from || '';
        const to = req.query.to || '';
        const departure_date = req.query.departure_date || '';
        const return_date = req.query.return_date || '';
        const trip_type = req.query.trip_type || 'round';
        let cabin = req.query.class || 'economy';
        if (!CLASSES.includes(cabin)) cabin = 'economy';
        let passengers = parseInt(req.query.passengers, 10) || 1;
        passengers = Math.max(1, Math.min(9, passengers));

        const min_price = parseFloat(req.query.min_price) || 0;
        const max_price = parseFloat(req.query.max_price) || 500000;
        const airlines = toArr(req.query.airline);
        const departure_times = toArr(req.query.departure_time);

        let outbound_flights = [];
        let return_flights = [];

        if (from && to && departure_date) {
            let sql = `SELECT *,
                    CASE
                        WHEN ? = 'economy' THEN economy_price
                        WHEN ? = 'business' THEN business_price
                        WHEN ? = 'first' THEN first_price
                        ELSE economy_price
                    END AS selected_price
                    FROM flights
                    WHERE departure_city LIKE ?
                    AND arrival_city LIKE ?
                    AND DATE(departure_time) = ?
                    AND available_seats >= ?`;
            sql += ` AND (
                        CASE
                            WHEN ? = 'economy' THEN economy_price
                            WHEN ? = 'business' THEN business_price
                            WHEN ? = 'first' THEN first_price
                            ELSE economy_price
                        END
                    ) BETWEEN ? AND ?`;

            const params = [cabin, cabin, cabin, `%${from}%`, `%${to}%`, departure_date, passengers,
                cabin, cabin, cabin, min_price, max_price];

            if (airlines.length) {
                sql += ` AND airline IN (${airlines.map(() => '?').join(',')})`;
                params.push(...airlines);
            }

            if (departure_times.length) {
                const ranges = {
                    morning: "(TIME(departure_time) BETWEEN '06:00:00' AND '11:59:59')",
                    afternoon: "(TIME(departure_time) BETWEEN '12:00:00' AND '17:59:59')",
                    evening: "(TIME(departure_time) BETWEEN '18:00:00' AND '23:59:59')",
                    night: "(TIME(departure_time) BETWEEN '00:00:00' AND '05:59:59')",
                };
                const conds = departure_times.map((t) => ranges[t]).filter(Boolean);
                if (conds.length) sql += ` AND (${conds.join(' OR ')})`;
            }

            sql += ' ORDER BY departure_time';

            const [rows] = await pool.query(sql, params);
            outbound_flights = rows;

            if (trip_type === 'round' && return_date) {
                const returnParams = params.slice();
                returnParams[3] = `%${to}%`;
                returnParams[4] = `%${from}%`;
                returnParams[5] = return_date;
                const [rrows] = await pool.query(sql, returnParams);
                return_flights = rrows;
            }
        }

        // Flag the cheapest bookable flight on each leg for a "Best Value" ribbon.
        const markBest = (list) => {
            const eligible = list.filter((f) => f.status !== 'cancelled');
            if (!eligible.length) return;
            let best = eligible[0];
            for (const f of eligible) if (Number(f.selected_price) < Number(best.selected_price)) best = f;
            best.is_best = true;
        };
        markBest(outbound_flights);
        markBest(return_flights);

        const [airlineRows] = await pool.query('SELECT DISTINCT airline FROM flights ORDER BY airline');
        const all_airlines = airlineRows.map((r) => r.airline);

        // Distinct cities for the From/To autocomplete on the search bar.
        const [cityRows] = await pool.query(
            'SELECT departure_city AS c FROM flights UNION SELECT arrival_city FROM flights ORDER BY c'
        );
        const cities = cityRows.map((r) => r.c);

        res.render('flights', {
            title: 'Search Flights', active: 'flights',
            from, to, departure_date, return_date, trip_type, cabin, passengers,
            min_price, max_price, airlines, departure_times,
            all_airlines, outbound_flights, return_flights, cities,
        });
    } catch (err) {
        next(err);
    }
});

module.exports = router;
