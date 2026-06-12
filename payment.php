<?php
require_once 'includes/functions.php';
requireLogin();

$page_title = "Payment";

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
if ($booking_id <= 0) {
    header("Location: booking_history.php");
    exit;
}

$db = getDB();

// Load the booking — must belong to the logged-in user
$stmt = $db->prepare("
    SELECT b.*,
           f1.flight_number AS outbound_flight, f1.departure_city AS from_city, f1.arrival_city AS to_city,
           f1.departure_time,
           f2.flight_number AS return_flight, f2.departure_time AS return_departure
    FROM bookings b
    JOIN flights f1 ON b.flight_id = f1.flight_id
    LEFT JOIN flights f2 ON b.return_flight_id = f2.flight_id
    WHERE b.booking_id = ? AND b.user_id = ?
");
$stmt->execute([$booking_id, $_SESSION['user_id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    header("Location: booking_history.php");
    exit;
}
// Already paid: go straight to confirmation (refresh-safe, no double payment)
if ($booking['status'] === 'confirmed') {
    header("Location: booking_confirmation.php?booking_id=" . $booking_id);
    exit;
}
// Only pending bookings can be paid
if ($booking['status'] !== 'pending') {
    header("Location: booking_history.php");
    exit;
}

// Process payment (mock gateway: no real card data is collected)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_now'])) {
    csrf_verify();

    $method = $_POST['method'] ?? '';
    if (!in_array($method, ['card', 'upi', 'netbanking'], true)) {
        $error_message = "Please choose a payment method.";
    } else {
        try {
            $db->beginTransaction();

            // Lock the booking row: a double-submit waits here, then sees
            // status 'confirmed' and exits without a second payments row
            $stmt = $db->prepare("SELECT status, total_price FROM bookings WHERE booking_id = ? AND user_id = ? FOR UPDATE");
            $stmt->execute([$booking_id, $_SESSION['user_id']]);
            $locked = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$locked || $locked['status'] === 'cancelled') {
                $db->rollBack();
                header("Location: booking_history.php");
                exit;
            }
            if ($locked['status'] === 'confirmed') {
                $db->rollBack();
                header("Location: booking_confirmation.php?booking_id=" . $booking_id);
                exit;
            }

            $txn_ref = 'TXN' . strtoupper(bin2hex(random_bytes(6)));
            $stmt = $db->prepare("INSERT INTO payments (booking_id, amount, method, status, txn_ref, paid_at) VALUES (?, ?, ?, 'completed', ?, NOW())");
            $stmt->execute([$booking_id, $locked['total_price'], $method, $txn_ref]);

            $stmt = $db->prepare("UPDATE bookings SET status = 'confirmed' WHERE booking_id = ?");
            $stmt->execute([$booking_id]);

            $db->commit();
            header("Location: booking_confirmation.php?booking_id=" . $booking_id);
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error_message = "An error occurred while processing the payment. You have not been charged.";
        }
    }
}

// Tickets summary for display
$stmt = $db->prepare("
    SELECT t.ticket_id, t.flight_id, t.seat_number, t.class, t.fare,
           p.full_name, f.flight_number
    FROM tickets t
    JOIN passengers p ON t.passenger_id = p.passenger_id
    JOIN flights f ON t.flight_id = f.flight_id
    WHERE t.booking_id = ?
    ORDER BY t.flight_id = ? DESC, t.ticket_id
");
$stmt->execute([$booking_id, $booking['flight_id']]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - SkyConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header>
        <nav>
            <div class="logo">
                <a href="index.php">
                    <img src="assets/images/logo.png" alt="SkyConnect Logo">
                    SkyConnect
                </a>
            </div>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="flights.php">Flights</a></li>
                <li><a href="booking_history.php">Bookings</a></li>
                <?php if (isLoggedIn() && isStaff()): ?>
                <li><a href="staff/index.php">Staff Dashboard</a></li>
                <?php endif; ?>
                <?php if (isLoggedIn() && isAdmin()): ?>
                <li><a href="admin/index.php">Admin</a></li>
                <?php endif; ?>
            </ul>
            <div class="auth-links">
                <span>Welcome, <?php echo e($_SESSION['first_name']); ?></span>
                <a href="profile.php">My Profile</a>
                <a href="booking_history.php">My Bookings</a>
                <a href="logout.php">Logout</a>
            </div>
        </nav>
    </header>

    <main>
        <div class="container">
            <div class="booking-container">
                <h1>Complete Your Payment</h1>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error"><?php echo e($error_message); ?></div>
                <?php endif; ?>

                <div class="booking-summary">
                    <h2>Booking #SKY<?php echo str_pad($booking['booking_id'], 6, '0', STR_PAD_LEFT); ?></h2>
                    <p>
                        <strong><?php echo e($booking['from_city']); ?> &rarr; <?php echo e($booking['to_city']); ?></strong>
                        (<?php echo e($booking['outbound_flight']); ?>, departs <?php echo date('D, M d, Y H:i', strtotime($booking['departure_time'])); ?>)
                        <?php if ($booking['return_flight']): ?>
                            <br>Return: <strong><?php echo e($booking['return_flight']); ?></strong>,
                            departs <?php echo date('D, M d, Y H:i', strtotime($booking['return_departure'])); ?>
                        <?php endif; ?>
                    </p>

                    <h3>Tickets</h3>
                    <table class="admin-table tickets-table">
                        <thead>
                            <tr>
                                <th>Passenger</th>
                                <th>Flight</th>
                                <th>Leg</th>
                                <th>Seat</th>
                                <th>Class</th>
                                <th>Fare</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $t): ?>
                                <tr>
                                    <td><?php echo e($t['full_name']); ?></td>
                                    <td><?php echo e($t['flight_number']); ?></td>
                                    <td><?php echo $t['flight_id'] == $booking['flight_id'] ? 'Outbound' : 'Return'; ?></td>
                                    <td><?php echo e($t['seat_number'] ?? '—'); ?></td>
                                    <td><?php echo e(ucfirst($t['class'])); ?></td>
                                    <td>₹<?php echo number_format($t['fare'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="booking-row total" style="margin-top: 15px;">
                        <span><strong>Amount due:</strong></span>
                        <span><strong>₹<?php echo number_format($booking['total_price'], 2); ?></strong></span>
                    </div>

                    <form action="payment.php?booking_id=<?php echo $booking_id; ?>" method="post">
                        <?php echo csrf_field(); ?>
                        <h3>Payment Method</h3>
                        <div class="payment-methods">
                            <div class="payment-method">
                                <input type="radio" id="pm_card" name="method" value="card" checked>
                                <label for="pm_card">Card</label>
                            </div>
                            <div class="payment-method">
                                <input type="radio" id="pm_upi" name="method" value="upi">
                                <label for="pm_upi">UPI</label>
                            </div>
                            <div class="payment-method">
                                <input type="radio" id="pm_netbanking" name="method" value="netbanking">
                                <label for="pm_netbanking">Netbanking</label>
                            </div>
                        </div>
                        <p class="payment-note">This is a demo gateway — no real payment is processed.</p>

                        <div class="form-actions">
                            <a href="booking_history.php" class="btn btn-secondary">Pay Later</a>
                            <button type="submit" name="pay_now" class="btn btn-primary">Pay ₹<?php echo number_format($booking['total_price'], 2); ?></button>
                        </div>
                    </form>
                </div>
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
