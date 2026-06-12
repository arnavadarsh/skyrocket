<?php
require_once '../includes/functions.php';
requireStaff();

$page_title = "Flight Detail";

$db = getDB();

$flight_id = isset($_GET['flight_id']) ? intval($_GET['flight_id']) : 0;
if ($flight_id <= 0) {
    header("Location: index.php");
    exit;
}

function loadFlight($db, $flight_id) {
    $stmt = $db->prepare("
        SELECT f.*, a.model AS aircraft_model, a.capacity, g.terminal, g.gate_number
        FROM flights f
        LEFT JOIN aircraft a ON f.aircraft_id = a.aircraft_id
        LEFT JOIN gates g ON f.gate_id = g.gate_id
        WHERE f.flight_id = ?
    ");
    $stmt->execute([$flight_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$flight = loadFlight($db, $flight_id);
if (!$flight) {
    header("Location: index.php");
    exit;
}

// Crew changes make no sense once the flight is gone or scrapped
$crew_locked = in_array($flight['status'], ['departed', 'arrived', 'cancelled'], true);

$luggage_statuses = ['checked_in', 'loaded', 'arrived', 'lost'];

// --- Crew: assign -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_employee'])) {
    csrf_verify();
    if ($crew_locked) {
        $error_message = "Crew cannot be changed: this flight is " . $flight['status'] . ".";
    } elseif (!is_numeric($_POST['employee_id'] ?? '')) {
        $error_message = "Please choose an employee.";
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO employee_flight_assignment (employee_id, flight_id) VALUES (?, ?)");
            $stmt->execute([$_POST['employee_id'], $flight_id]);
            $success_message = "Employee assigned to this flight.";
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                $error_message = "That employee is already assigned to this flight.";
            } else {
                $error_message = "Error assigning employee: " . $e->getMessage();
            }
        }
    }
}

// --- Crew: remove --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_assignment']) && is_numeric($_POST['remove_assignment'])) {
    csrf_verify();
    if ($crew_locked) {
        $error_message = "Crew cannot be changed: this flight is " . $flight['status'] . ".";
    } else {
        // Scope the delete to this flight so a stale id can't touch others
        $stmt = $db->prepare("DELETE FROM employee_flight_assignment WHERE assignment_id = ? AND flight_id = ?");
        $stmt->execute([$_POST['remove_assignment'], $flight_id]);
        $success_message = $stmt->rowCount() ? "Assignment removed." : "Assignment not found.";
    }
}

// --- Tickets: check-in ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_in']) && is_numeric($_POST['check_in'])) {
    csrf_verify();
    $stmt = $db->prepare("
        SELECT t.ticket_id, t.status AS ticket_status, b.status AS booking_status
        FROM tickets t
        JOIN bookings b ON t.booking_id = b.booking_id
        WHERE t.ticket_id = ? AND t.flight_id = ?
    ");
    $stmt->execute([$_POST['check_in'], $flight_id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$t) {
        $error_message = "Ticket not found on this flight.";
    } elseif ($t['ticket_status'] === 'cancelled' || $t['booking_status'] === 'cancelled') {
        $error_message = "Cancelled tickets cannot be checked in.";
    } elseif ($t['booking_status'] === 'pending') {
        $error_message = "This booking has not been paid yet — it cannot be checked in.";
    } elseif ($t['ticket_status'] === 'checked_in') {
        $error_message = "This ticket is already checked in.";
    } else {
        $stmt = $db->prepare("UPDATE tickets SET status = 'checked_in' WHERE ticket_id = ?");
        $stmt->execute([$t['ticket_id']]);
        $success_message = "Ticket #" . (int)$t['ticket_id'] . " checked in.";
    }
}

// --- Luggage: add ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_luggage']) && is_numeric($_POST['ticket_id'] ?? '')) {
    csrf_verify();
    $weight = trim($_POST['weight'] ?? '');

    $stmt = $db->prepare("SELECT status FROM tickets WHERE ticket_id = ? AND flight_id = ?");
    $stmt->execute([$_POST['ticket_id'], $flight_id]);
    $ticket_status = $stmt->fetchColumn();

    if ($ticket_status === false) {
        $error_message = "Ticket not found on this flight.";
    } elseif ($ticket_status !== 'checked_in') {
        $error_message = "Luggage can only be added to checked-in tickets.";
    } elseif (!is_numeric($weight) || (float)$weight <= 0 || (float)$weight > 32) {
        // Friendly message before the DB CHECK (weight > 0 AND <= 32) fires
        $error_message = "Luggage weight must be between 0 and 32 kg.";
    } else {
        $stmt = $db->prepare("INSERT INTO luggage (ticket_id, weight) VALUES (?, ?)");
        $stmt->execute([$_POST['ticket_id'], round((float)$weight, 2)]);
        $success_message = "Luggage (" . round((float)$weight, 2) . " kg) added.";
    }
}

// --- Luggage: status update -----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_luggage']) && is_numeric($_POST['luggage_id'] ?? '')) {
    csrf_verify();
    $status = $_POST['luggage_status'] ?? '';

    if (!in_array($status, $luggage_statuses, true)) {
        $error_message = "Invalid luggage status.";
    } else {
        // Scope to this flight's tickets
        $stmt = $db->prepare("
            UPDATE luggage l
            JOIN tickets t ON l.ticket_id = t.ticket_id
            SET l.status = ?
            WHERE l.luggage_id = ? AND t.flight_id = ?
        ");
        $stmt->execute([$status, $_POST['luggage_id'], $flight_id]);
        $success_message = $stmt->rowCount() ? "Luggage status updated." : "Luggage already in that status.";
    }
}

// Re-load the flight in case a handler changed it (status etc.)
$flight = loadFlight($db, $flight_id);
$crew_locked = in_array($flight['status'], ['departed', 'arrived', 'cancelled'], true);

// --- Display data ----------------------------------------------------
$stmt = $db->prepare("
    SELECT efa.assignment_id, em.full_name, em.role, em.shift
    FROM employee_flight_assignment efa
    JOIN employees em ON efa.employee_id = em.employee_id
    WHERE efa.flight_id = ?
    ORDER BY FIELD(em.role, 'pilot', 'cabin_crew', 'ground', 'security'), em.full_name
");
$stmt->execute([$flight_id]);
$crew = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT em.employee_id, em.full_name, em.role, em.shift
    FROM employees em
    WHERE em.employee_id NOT IN (SELECT employee_id FROM employee_flight_assignment WHERE flight_id = ?)
    ORDER BY FIELD(em.role, 'pilot', 'cabin_crew', 'ground', 'security'), em.full_name
");
$stmt->execute([$flight_id]);
$unassigned = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT t.ticket_id, t.seat_number, t.class, t.status AS ticket_status,
           p.full_name, b.booking_id, b.status AS booking_status
    FROM tickets t
    JOIN passengers p ON t.passenger_id = p.passenger_id
    JOIN bookings b ON t.booking_id = b.booking_id
    WHERE t.flight_id = ?
    ORDER BY t.ticket_id
");
$stmt->execute([$flight_id]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Luggage for this flight's tickets, grouped per ticket
$stmt = $db->prepare("
    SELECT l.*
    FROM luggage l
    JOIN tickets t ON l.ticket_id = t.ticket_id
    WHERE t.flight_id = ?
    ORDER BY l.luggage_id
");
$stmt->execute([$flight_id]);
$luggage_by_ticket = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bag) {
    $luggage_by_ticket[$bag['ticket_id']][] = $bag;
}
$checked_in_tickets = array_filter($tickets, function ($t) {
    return $t['ticket_status'] === 'checked_in';
});

function ticketStatusBadge($status) {
    $map = ['confirmed' => 'scheduled', 'checked_in' => 'boarding', 'cancelled' => 'cancelled'];
    $cls = $map[$status] ?? 'scheduled';
    return '<span class="status-badge status-' . $cls . '">' . e(ucfirst(str_replace('_', ' ', $status))) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($flight['flight_number']); ?> - Flight Detail - SkyConnect</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <header>
        <nav>
            <div class="logo">
                <a href="../index.php">
                    <img src="../assets/images/logo.png" alt="SkyConnect Logo">
                    SkyConnect
                </a>
            </div>
            <ul class="nav-links">
                <li><a href="../index.php">Home</a></li>
                <li><a href="index.php" class="active">Staff Dashboard</a></li>
                <?php if (isAdmin()): ?>
                <li><a href="../admin/index.php">Admin</a></li>
                <?php endif; ?>
            </ul>
            <div class="auth-links">
                <span>Welcome, <?php echo e($_SESSION['first_name']); ?></span>
                <a href="../logout.php">Logout</a>
            </div>
        </nav>
    </header>

    <main>
        <div class="admin-container">
            <p><a href="index.php">&larr; Back to dashboard</a></p>
            <h1>
                <?php echo e($flight['flight_number']); ?> &mdash;
                <?php echo e($flight['departure_city'] . ' → ' . $flight['arrival_city']); ?>
                <?php echo statusBadge($flight['status']); ?>
            </h1>
            <p>
                Departs <?php echo date('D, M d, Y H:i', strtotime($flight['departure_time'])); ?> |
                Gate: <?php echo $flight['gate_id'] ? e($flight['terminal'] . '-' . $flight['gate_number']) : 'TBA'; ?> |
                Aircraft: <?php echo $flight['aircraft_model'] ? e($flight['aircraft_model']) . ' (' . $flight['capacity'] . ' seats)' : '—'; ?> |
                Seats left: <?php echo $flight['available_seats']; ?>
            </p>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo e($success_message); ?></div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo e($error_message); ?></div>
            <?php endif; ?>

            <!-- ============ Crew ============ -->
            <div class="admin-card">
                <h2>Crew</h2>
                <?php if (count($crew) > 0): ?>
                    <table class="admin-table">
                        <thead>
                            <tr><th>Name</th><th>Role</th><th>Shift</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($crew as $member): ?>
                                <tr>
                                    <td><?php echo e($member['full_name']); ?></td>
                                    <td><?php echo e(ucwords(str_replace('_', ' ', $member['role']))); ?></td>
                                    <td><?php echo e(ucfirst($member['shift'])); ?></td>
                                    <td>
                                        <?php if (!$crew_locked): ?>
                                            <form action="" method="post" style="display:inline" onsubmit="return confirm('Remove this crew member from the flight?')">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" name="remove_assignment" value="<?php echo $member['assignment_id']; ?>" class="btn btn-danger btn-sm">Remove</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No crew assigned to this flight yet.</p>
                <?php endif; ?>

                <?php if (!$crew_locked && count($unassigned) > 0): ?>
                    <form action="" method="post" class="status-form" style="margin-top: 15px">
                        <?php echo csrf_field(); ?>
                        <select name="employee_id" class="form-control" style="max-width: 350px">
                            <?php foreach ($unassigned as $em): ?>
                                <option value="<?php echo $em['employee_id']; ?>">
                                    <?php echo e($em['full_name'] . ' — ' . ucwords(str_replace('_', ' ', $em['role'])) . ' (' . $em['shift'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="assign_employee" value="1" class="btn btn-primary btn-sm">Assign</button>
                    </form>
                <?php elseif ($crew_locked): ?>
                    <p><em>Crew changes are locked: this flight is <?php echo e($flight['status']); ?>.</em></p>
                <?php endif; ?>
            </div>

            <!-- ============ Passengers / tickets ============ -->
            <div class="admin-card">
                <h2>Passengers &amp; Tickets</h2>
                <?php if (count($tickets) > 0): ?>
                    <table class="admin-table">
                        <thead>
                            <tr><th>Passenger</th><th>Booking</th><th>Seat</th><th>Class</th><th>Ticket Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $t): ?>
                                <tr>
                                    <td><?php echo e($t['full_name']); ?></td>
                                    <td>
                                        #SKY<?php echo str_pad($t['booking_id'], 6, '0', STR_PAD_LEFT); ?>
                                        <?php if ($t['booking_status'] === 'pending'): ?>
                                            <span class="status-badge status-delayed">Unpaid</span>
                                        <?php elseif ($t['booking_status'] === 'cancelled'): ?>
                                            <span class="status-badge status-cancelled">Cancelled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo e($t['seat_number'] ?? '—'); ?></td>
                                    <td><?php echo e(ucfirst($t['class'])); ?></td>
                                    <td><?php echo ticketStatusBadge($t['ticket_status']); ?></td>
                                    <td>
                                        <?php if ($t['ticket_status'] === 'confirmed' && $t['booking_status'] === 'confirmed'): ?>
                                            <form action="" method="post" style="display:inline">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" name="check_in" value="<?php echo $t['ticket_id']; ?>" class="btn btn-sm">Check in</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No tickets on this flight yet.</p>
                <?php endif; ?>
            </div>

            <!-- ============ Luggage ============ -->
            <div class="admin-card">
                <h2>Luggage</h2>
                <?php if (count($checked_in_tickets) > 0): ?>
                    <?php foreach ($checked_in_tickets as $t): ?>
                        <div class="luggage-block">
                            <h3><?php echo e($t['full_name']); ?> — seat <?php echo e($t['seat_number'] ?? '—'); ?></h3>

                            <?php if (!empty($luggage_by_ticket[$t['ticket_id']])): ?>
                                <table class="admin-table">
                                    <thead>
                                        <tr><th>Bag</th><th>Weight</th><th>Status</th><th></th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($luggage_by_ticket[$t['ticket_id']] as $bag): ?>
                                            <tr>
                                                <td>#<?php echo $bag['luggage_id']; ?></td>
                                                <td><?php echo number_format($bag['weight'], 2); ?> kg</td>
                                                <td>
                                                    <form action="" method="post" class="status-form">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="luggage_id" value="<?php echo $bag['luggage_id']; ?>">
                                                        <select name="luggage_status" class="form-control status-select">
                                                            <?php foreach ($luggage_statuses as $ls): ?>
                                                                <option value="<?php echo $ls; ?>" <?php echo $bag['status'] === $ls ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $ls)); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" name="update_luggage" value="1" class="btn btn-sm">Update</button>
                                                    </form>
                                                </td>
                                                <td></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p>No luggage yet.</p>
                            <?php endif; ?>

                            <form action="" method="post" class="status-form">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="ticket_id" value="<?php echo $t['ticket_id']; ?>">
                                <input type="number" name="weight" class="form-control" style="width: 130px"
                                       step="0.1" min="0.1" max="32" placeholder="kg" required>
                                <button type="submit" name="add_luggage" value="1" class="btn btn-primary btn-sm">Add luggage</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No checked-in tickets yet — check passengers in above to add luggage.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <footer>
        <div class="footer-content">
            <p>&copy; 2025 SkyConnect. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
