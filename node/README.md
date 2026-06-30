# SkyConnect — Node.js / Express port

A JavaScript rewrite of the PHP SkyConnect app. It talks to the **same**
`flight_booking` MySQL database, so all seat-accounting triggers and the
`sp_cancel_booking` stored procedure keep working unchanged — only the
application layer changed (PHP → Node).

## Stack

| PHP app                     | Node port                          |
| --------------------------- | ---------------------------------- |
| PHP page-per-file           | Express routes + EJS templates     |
| PDO (MySQL)                 | `mysql2/promise` pool (`db.js`)    |
| `$_SESSION`                 | `express-session`                  |
| `password_hash`/`verify`    | `bcryptjs` (`$2y$` hashes normalized to `$2b$`) |
| `csrf_token`/`csrf_verify`  | `middleware/csrf.js`               |
| `e()`, `statusBadge()`, `date()`, `number_format()`, `assignSeats()` | `lib/helpers.js` + `routes/booking.js` |
| `assets/css`, `assets/js`   | served as static files (reused as-is) |

## Run it

```bash
cd node
cp .env.example .env        # then edit DB_PASS etc. to match your MySQL
npm install
npm start                   # http://localhost:3000
```

The schema/seed data come from the repo root: `mysql -u root -p < ../schema.sql`.

Seed logins: `demo` / `Demo@123`, `staff` / `Staff@123`, `admin` / `Admin@123`.

## Ported routes — the whole app

**Customer + auth** (`routes/public.js`, `routes/auth.js`, `routes/booking.js`)

| Route | Replaces |
| --- | --- |
| `GET /` | `index.php` |
| `GET /flights` | `flights.php` (class pricing, price/airline/time filters, round-trip) |
| `GET/POST /booking` | `booking.php` (validation, transaction, seat assignment, seat trigger) |
| `GET/POST /payment` | `payment.php` (row-locked, refresh-safe mock gateway) |
| `GET /booking_confirmation` | `booking_confirmation.php` |
| `GET /booking_history` | `booking_history.php` |
| `POST /cancel_booking` | `cancel_booking.php` (`CALL sp_cancel_booking`) |
| `GET/POST /login`, `GET/POST /register`, `GET /logout`, `GET /profile` | `login`, `register`, `logout`, `profile` |

**Admin** (`routes/admin.js`, mounted at `/admin`, `requireAdmin`)

| Route | Replaces |
| --- | --- |
| `GET /admin` | `admin/index.php` (stats + recent bookings) |
| `GET/POST /admin/bookings` | `admin/bookings.php` (cancel via `sp_cancel_booking`) |
| `GET/POST /admin/users` | `admin/users.php` (role change, delete) |
| `GET/POST /admin/flights` | `admin/flights.php` (add / status / delete, cross-table seat checks) |
| `GET/POST /admin/aircraft` | `admin/aircraft.php` (add / inline edit / delete) |
| `GET/POST /admin/employees` | `admin/employees.php` (add / inline edit / delete) |
| `GET /admin/reports` | `admin/reports.php` (8 reports, views, window funcs) |
| `GET/POST /admin/custom_query` | `admin/custom_query.php` (read-only SQL console) |

**Staff** (`routes/staff.js`, mounted at `/staff`, `requireStaff`)

| Route | Replaces |
| --- | --- |
| `GET/POST /staff` | `staff/index.php` (departures board: status + gate) |
| `GET/POST /staff/flight_detail` | `staff/flight_detail.php` (crew, check-in, luggage) |

All areas were exercised end-to-end against the live database:
customer login → search → book → pay → confirm → cancel/refund; admin login →
every page + cancel/SQL-console/aircraft mutations; staff login → board →
flight detail → status update.
