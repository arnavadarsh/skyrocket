<?php
require_once '../includes/functions.php';
requireAdmin();

$page_title = "Manage Bookings";

// Cancel booking (POST + CSRF; GET must never mutate).
// Same logic as user-side cancellation: tickets cancelled with seats
// freed, completed payment refunded, flight seats restored. Bookings
// are never hard-deleted anymore — history must survive.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking']) && is_numeric($_POST['cancel_booking'])) {
    csrf_verify();
    $booking_id = $_POST['cancel_booking'];
    $db = getDB();

    try {
        $db->beginTransaction();

        // Lock the booking row so a double-submit cannot restore seats twice
        $stmt = $db->prepare("SELECT flight_id, return_flight_id, passenger_count, status FROM bookings WHERE booking_id = ? FOR UPDATE");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $db->rollBack();
            $error_message = "Booking not found.";
        } elseif ($booking['status'] === 'cancelled') {
            $db->rollBack();
            $error_message = "Booking #$booking_id is already cancelled.";
        } else {
            $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = ?");
            $stmt->execute([$booking_id]);

            // Cancel tickets; NULL frees each seat under UNIQUE(flight_id, seat_number)
            $stmt = $db->prepare("UPDATE tickets SET status = 'cancelled', seat_number = NULL WHERE booking_id = ?");
            $stmt->execute([$booking_id]);

            // Refund if paid (writes nothing for unpaid pending bookings)
            $stmt = $db->prepare("UPDATE payments SET status = 'refunded' WHERE booking_id = ? AND status = 'completed'");
            $stmt->execute([$booking_id]);

            $stmt = $db->prepare("UPDATE flights SET available_seats = available_seats + ? WHERE flight_id = ?");
            $stmt->execute([$booking['passenger_count'], $booking['flight_id']]);
            if ($booking['return_flight_id']) {
                $stmt->execute([$booking['passenger_count'], $booking['return_flight_id']]);
            }

            $db->commit();
            $success_message = "Booking #$booking_id has been cancelled (tickets released, refund issued where applicable).";
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = "Error cancelling booking: " . $e->getMessage();
    }
}

// Get all bookings with user and flight details
$db = getDB();
$stmt = $db->query("
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
    ORDER BY b.booking_date DESC
");
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - SkyConnect</title>
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
                <li><a href="index.php">Dashboard</a></li>
                <li><a href="bookings.php" class="active">Bookings</a></li>
                <li><a href="users.php">Users</a></li>
                <li><a href="flights.php">Flights</a></li>
                <li><a href="aircraft.php">Aircraft</a></li>
                <li><a href="employees.php">Employees</a></li>
                <li><a href="custom_query.php">Custom Query</a></li>
            </ul>
            <div class="auth-links">
                <span>Welcome, <?php echo e($_SESSION['first_name']); ?></span>
                <a href="../logout.php">Logout</a>
            </div>
        </nav>
    </header>
    
    <main>
        <div class="admin-container">
            <h1>Manage Bookings</h1>
            
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo e($success_message); ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo e($error_message); ?></div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Flight Details</th>
                            <th>Booking Date</th>
                            <th>Passengers</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Total Price</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($bookings) > 0): ?>
                            <?php foreach ($bookings as $booking): ?>
                                <tr>
                                    <td>#<?php echo $booking['booking_id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($booking['username']); ?></strong><br>
                                        <?php echo htmlspecialchars($booking['email']); ?><br>
                                        <?php echo htmlspecialchars($booking['user_first_name'] . ' ' . $booking['user_last_name']); ?>
                                    </td>
                                    <td>
                                        <strong>From:</strong> <?php echo htmlspecialchars($booking['from_city']); ?><br>
                                        <strong>To:</strong> <?php echo htmlspecialchars($booking['to_city']); ?><br>
                                        <strong>Flight:</strong> <?php echo htmlspecialchars($booking['outbound_flight']); ?> (<?php echo htmlspecialchars($booking['outbound_airline']); ?>)<br>
                                        <strong>Departure:</strong> <?php echo date('M d, Y H:i', strtotime($booking['departure_time'])); ?><br>
                                        <?php if ($booking['return_flight']): ?>
                                            <strong>Return:</strong> <?php echo htmlspecialchars($booking['return_flight']); ?> (<?php echo htmlspecialchars($booking['return_airline']); ?>)<br>
                                            <strong>Return Date:</strong> <?php echo date('M d, Y H:i', strtotime($booking['return_departure'])); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($booking['booking_date'])); ?></td>
                                    <td>
                                        <?php echo $booking['passenger_count']; ?>
                                        <?php if ($booking['passenger_names']): ?>
                                            <br><small><?php echo e($booking['passenger_names']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php
                                        if ($booking['status'] === 'cancelled') {
                                            echo '<span class="status-badge status-cancelled">Cancelled</span>';
                                        } elseif ($booking['status'] === 'pending') {
                                            echo '<span class="status-badge status-delayed">Pending</span>';
                                        } else {
                                            echo '<span class="status-badge status-scheduled">Confirmed</span>';
                                        }
                                    ?></td>
                                    <td><?php
                                        if ($booking['payment_status'] === 'completed') {
                                            echo '<span class="status-badge status-scheduled">Paid</span>';
                                        } elseif ($booking['payment_status'] === 'refunded') {
                                            echo '<span class="status-badge status-departed">Refunded</span>';
                                        } else {
                                            echo '<span class="status-badge status-delayed">Unpaid</span>';
                                        }
                                    ?></td>
                                    <td>₹<?php echo number_format($booking['total_price'], 2); ?></td>
                                    <td>
                                        <?php if ($booking['status'] !== 'cancelled'): ?>
                                            <form action="" method="post" onsubmit="return confirm('Cancel this booking? Tickets are released and any payment is refunded.')" style="display:inline">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" name="cancel_booking" value="<?php echo $booking['booking_id']; ?>" class="btn btn-danger">Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center">No bookings found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    
    <footer class="site-footer">
        <div class="footer-container">
            <!-- Logo and Tagline Section -->
            <div class="footer-brand">
                <div class="footer-logo">
                    <img src="../assets/images/logo.png" alt="SkyConnect Logo">
                    <span>SkyConnect</span>
                </div>
                <p class="footer-tagline">
                    Making the world more accessible through affordable and convenient air travel.
                </p>
            </div>
            
            <!-- Company Links -->
            <div class="footer-links">
                <h3>COMPANY</h3>
                <ul>
                    <li><a href="#">About Us</a></li>
                    <li><a href="#">Careers</a></li>
                    <li><a href="#">Press</a></li>
                    <li><a href="#">Partners</a></li>
                </ul>
            </div>
            
            <!-- Support Links -->
            <div class="footer-links">
                <h3>SUPPORT</h3>
                <ul>
                    <li><a href="#">Help Center</a></li>
                    <li><a href="#">Contact Us</a></li>
                    <li><a href="#">FAQs</a></li>
                    <li><a href="#">Travel Alerts</a></li>
                </ul>
            </div>
            
            <!-- Legal Links -->
            <div class="footer-links">
                <h3>LEGAL</h3>
                <ul>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                    <li><a href="#">Cookie Policy</a></li>
                    <li><a href="#">Accessibility</a></li>
                </ul>
            </div>
        </div>
        
        <div class="footer-bottom">
            <div class="social-icons">
                <a href="#" aria-label="Facebook"><i class="fa fa-facebook"></i></a>
                <a href="#" aria-label="Instagram"><i class="fa fa-instagram"></i></a>
                <a href="#" aria-label="Twitter"><i class="fa fa-twitter"></i></a>
            </div>
            <div class="footer-divider"></div>
            <div class="footer-copyright">
                <p>© 2025 SkyConnect Airlines, Inc. All rights reserved.</p>
            </div>
        </div>
    </footer>
</body>
</html>
