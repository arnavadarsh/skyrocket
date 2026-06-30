# SkyConnect — Airport Management System

A flight booking and airport-operations web app built on **PHP + MySQL 8**
(vanilla, no framework) with PDO and prepared statements throughout. It
covers the full journey from a passenger searching for a flight to staff
checking bags onto it: booking → passengers → tickets → payment, plus a
staff operations layer (gates, crew, check-in, luggage) and an admin
back office with live SQL reports.

Built as a DBMS course project to demonstrate relational modelling,
constraints, transactions, triggers, a stored procedure, views, window
functions, and indexing on a realistic schema.

---

## Features by role

### Passenger (`user`)
- Register, log in, manage profile
- Search flights by route and date, filter by price/airline/time, one-way or round trip
- Book with per-passenger details (name, DOB, gender, passport); seats auto-assigned (adjacent where possible)
- Mock payment (card / UPI / netbanking), refresh-safe
- Booking confirmation with per-ticket seats, fares, **gate**, and **luggage**
- Booking history; cancel a booking (seats released, payment refunded)

### Staff (`staff`)
- Staff dashboard: departures board for the next 48 hours
- Update flight status; assign an **open** gate
- Per-flight detail: assign/remove **crew**, **check passengers in**, add and track **luggage**

### Admin (`admin`)
- Dashboard stats (bookings, users, flights, revenue, pending payments)
- Manage flights (CRUD + status + aircraft/gate assignment with capacity rule)
- Manage aircraft, employees, users (role assignment), bookings (cancel)
- **Reports** page: 8 live reports (occupancy, top routes, revenue, disruptions, crew workload, …)
- Read-only SQL console

---

## Tech stack

| Layer | Choice |
|-------|--------|
| Language | PHP 8 (procedural, no framework) |
| Database | MySQL 8, InnoDB, utf8mb4 |
| DB access | PDO with prepared statements |
| Frontend | Server-rendered HTML, CSS, a little vanilla JS |
| Auth | Session-based, bcrypt password hashing, CSRF tokens on every state change |

---

## Setup

Requires PHP 8+ and MySQL 8+. Runs under Apache/XAMPP at
`http://localhost/flight`, or standalone with PHP's built-in server.

```bash
# 1. Clone into your web root (or anywhere, if using the built-in server)
git clone <repo-url> skyrocket
cd skyrocket

# 2. Create your local DB config from the template
cp includes/config.example.php includes/config.php
#    then edit includes/config.php with your MySQL host/user/password
#    (config.php is gitignored — credentials never get committed)

# 3. (Recommended) create a dedicated MySQL user instead of root
#    mysql -u root -p
#    CREATE USER 'flightapp'@'localhost' IDENTIFIED BY 'a-strong-password';
#    GRANT ALL PRIVILEGES ON flight_booking.* TO 'flightapp'@'localhost';

# 4. Import the schema (creates the DB, all tables, triggers, procedure,
#    views, and demo data — safe to re-run)
mysql -u <your_user> -p < schema.sql

# 5. Run it
#    a) Apache/XAMPP: place the folder in htdocs and open http://localhost/flight
#    b) Built-in server:
php -S localhost:8000
#    then open http://localhost:8000
```

### Demo accounts

| Username | Password   | Role  | Lands on |
|----------|------------|-------|----------|
| `admin`  | `Admin@123`| admin | Admin dashboard |
| `staff`  | `Staff@123`| staff | Staff dashboard |
| `demo`   | `Demo@123` | user  | Home |

Flight seed dates are relative to today, so searches always return
upcoming flights. A short demo walkthrough is in
[docs/DEMO_SCRIPT.md](docs/DEMO_SCRIPT.md).

---

## Schema overview (11 tables)

| Table | Purpose |
|-------|---------|
| `users` | Accounts and role (`user`/`staff`/`admin`) |
| `flights` | Scheduled flights, status, price tiers, aircraft + gate |
| `aircraft` | Fleet and capacity |
| `gates` | Terminal gates (open/closed) |
| `bookings` | A reservation (one-way or round trip), status |
| `passengers` | One traveler per booking |
| `tickets` | One per passenger per leg; seat + fare snapshot |
| `payments` | Mock gateway records per booking |
| `employees` | Staff roster (pilots, cabin crew, ground, security) |
| `employee_flight_assignment` | Crew ↔ flight junction (M:N) |
| `luggage` | Bags per ticket, weight + status |

ER diagram sources: [docs/schema.dbml](docs/schema.dbml) (dbdiagram.io)
and [docs/er.mmd](docs/er.mmd) (Mermaid).

---

## DBMS highlights

- **Constraints** — ENUMs, `UNIQUE` (seat per flight, gate per terminal, crew per flight), `CHECK` (seat ≥ 0, luggage 0–32 kg, passengers 1–9, prices > 0), FKs with deliberate `RESTRICT` / `CASCADE` / `SET NULL`. See `schema.sql`.
- **Transactions + row locks** — booking, payment, and cancellation run in transactions; `SELECT … FOR UPDATE` prevents double-spend/double-refund races ([booking.php](booking.php), [payment.php](payment.php), [cancel_booking.php](cancel_booking.php)).
- **Triggers** — three triggers on `tickets` own all seat accounting: a guarded decrement that `SIGNAL`s on overbooking, plus restore-on-cancel and restore-on-delete. The database, not the app, guarantees seat consistency. See `schema.sql` and [docs/INDEXING.md](docs/INDEXING.md) for the rationale.
- **Stored procedure** — `sp_cancel_booking` holds the one cancel cascade (tickets cancelled + seats freed + payment refunded), called by both the user and admin cancel paths.
- **Views** — `vw_flight_occupancy` and `vw_revenue_by_airline` back the reports page.
- **Window function** — `RANK() OVER (…)` for top routes in [admin/reports.php](admin/reports.php).
- **Indexing** — composite `idx_flights_route_time` with a real before/after `EXPLAIN` writeup in [docs/INDEXING.md](docs/INDEXING.md).

---

## Project structure

```
skyrocket/
├── schema.sql                  # Single source of truth: tables, triggers, procedure, views, seeds
├── index.php                   # Landing + search form
├── flights.php                 # Search results
├── booking.php                 # Passenger details + booking transaction
├── payment.php                 # Mock payment gateway
├── booking_confirmation.php    # Tickets, gate, luggage, payment
├── booking_history.php         # User's bookings + cancel
├── cancel_booking.php          # POST-only cancel (CALL sp_cancel_booking)
├── login.php / register.php / logout.php / profile.php
├── includes/
│   ├── config.example.php      # Copy to config.php (gitignored)
│   ├── db.php                  # PDO singleton
│   └── functions.php           # auth, e(), CSRF, statusBadge(), assignSeats()
├── admin/                      # index, flights, aircraft, employees, users, bookings, reports, custom_query
├── staff/                      # index (departures board), flight_detail (crew/check-in/luggage)
├── assets/                     # css, js, images
└── docs/                       # schema.dbml, er.mmd, INDEXING.md, DEMO_SCRIPT.md
```

---

## Screenshots

_Add screenshots here for submission:_

- `docs/img/search.png` — flight search results
- `docs/img/booking.png` — passenger details + payment
- `docs/img/staff.png` — staff departures board
- `docs/img/reports.png` — admin reports page
