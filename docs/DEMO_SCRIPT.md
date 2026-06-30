# 5-Minute Demo / Viva Walkthrough

A click-path that touches every layer of the system, the three best
"show the SQL" moments, and likely examiner questions with short answers.

> Before you start: `mysql -u <user> -p < schema.sql` (fresh seed, dates
> relative to today). Have the app open at `http://localhost/flight`
> (or `php -S localhost:8000`).

---

## Click-path (≈5 min)

1. **Search (passenger).** Log in as `demo` / `Demo@123`. From the home
   page search **Delhi → Mumbai**, departure **tomorrow**, 2 passengers,
   one-way. Results show flights with a green *Scheduled* badge.

2. **Book.** Pick a flight → fill **2 passenger rows** (name, DOB,
   gender). Note seats are auto-assigned adjacent (e.g. 1A, 1B). Continue
   to payment.

3. **Pay.** Choose UPI → Pay. Confirmation page shows both tickets with
   seats, fares, the gate (or "TBA"), and a transaction reference. The
   booking is now `confirmed`.

4. **Staff operations.** Log out, log in as `staff` / `Staff@123` → lands
   on the **departures board** (next 48h). On your flight:
   - set status to **Delayed** → Update (passenger search now shows an orange badge),
   - assign an **open gate** (closed gates aren't even listed),
   - click **Detail**: assign a pilot + 2 cabin crew, **check in** a passenger, **add a 23.5 kg bag**, then walk the bag *checked-in → loaded → arrived*.

5. **Cancel + refund.** Back as `demo` → My Bookings → **Cancel**. Seats
   are released, the payment shows **Refunded**. (Admin can do the same
   from admin → Bookings.)

6. **Reports (admin).** Log in as `admin` / `Admin@123` → **Reports**.
   Eight live reports; expand any **"Show SQL"** block to show the exact
   query. The dashboard revenue reflects completed-minus-refunded
   payments.

---

## Three "show the SQL" moments

1. **A trigger enforces seat limits (not the app).**
   In the SQL console or CLI, on a flight with 0 free seats:
   ```sql
   INSERT INTO tickets (booking_id, passenger_id, flight_id, class, fare)
   VALUES (1, 1, <full_flight_id>, 'economy', 100);
   -- ERROR 1644 (45000): Not enough seats available on this flight
   ```
   The guarantee holds for *any* client, because `trg_tickets_seat_decrement`
   does a guarded decrement and `SIGNAL`s when it can't.

2. **One stored procedure owns the cancel cascade.**
   ```sql
   SHOW CREATE PROCEDURE sp_cancel_booking;
   ```
   Both the user page and the admin page `CALL sp_cancel_booking(?)` —
   tickets cancelled (which fires the seat-restore trigger once per
   ticket), payment refunded, booking cancelled — no duplicated SQL.

3. **A window function ranks routes.**
   Reports section 2 → "Show SQL":
   ```sql
   RANK() OVER (ORDER BY COUNT(t.ticket_id) DESC) AS `rank`
   ```
   Tied routes share a rank.

---

## Likely examiner questions (one-line answers)

- **Why snapshot `fare` on the ticket instead of reading `flights.price`?**
  So a later price edit never rewrites the value of tickets already sold —
  the ticket records what the customer actually paid.

- **Why set `seat_number = NULL` on cancel instead of deleting the ticket?**
  It frees the seat under `UNIQUE(flight_id, seat_number)` (MySQL allows
  many NULLs) while keeping the ticket row for history/audit.

- **Why move seat math into a trigger instead of PHP?**
  The rule "you can't sell more seats than exist" is a data-integrity
  invariant; in the database it holds for every client and can't be
  bypassed, and the guarded decrement + `SIGNAL` makes an oversell abort
  the whole transaction atomically.

- **Why can't a `CHECK` enforce "seats ≤ aircraft capacity"?**
  A `CHECK` can only reference columns of its own row, not another table;
  cross-table rules like that are validated in PHP (and capacity-vs-seats
  is re-checked on aircraft edits).

- **Why is `available_seats` denormalized when you could COUNT tickets?**
  It's a performance cache for the hot search path; the triggers keep it
  consistent, and `vw_flight_occupancy` cross-checks sold vs. available.

- **Round trip — how many ticket rows for 2 passengers?**
  Four: one per passenger per leg, each with its own seat and fare snapshot.

- **How do you stop a double payment / double refund on refresh?**
  The row is re-read `FOR UPDATE` inside the transaction; a second submit
  blocks, then sees the already-final status and exits without writing.
