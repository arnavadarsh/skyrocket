'use strict';

// MySQL connection pool — talks to the SAME flight_booking database the
// PHP app uses, so all triggers (seat accounting) and the
// sp_cancel_booking stored procedure keep working unchanged.
const mysql = require('mysql2/promise');

const pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'flight_booking',
    charset: 'utf8mb4',
    waitForConnections: true,
    connectionLimit: 10,
    namedPlaceholders: false,
});

module.exports = { pool };
