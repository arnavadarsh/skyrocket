<?php
require_once '../includes/functions.php';
requireAdmin();

$page_title = "Manage Bookings";

// Delete booking if requested
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $booking_id = $_GET['delete'];
    $db = getDB();
    
    // Get booking details to update flight seats
    $stmt = $db->prepare("SELECT flight_id, return_flight_id, passenger_count FROM bookings WHERE booking_id = ?");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($booking) {
        // Begin transaction
        $db->beginTransaction();
        
        try {
            // Update available seats for outbound flight
            $stmt = $db->prepare("UPDATE flights SET available_seats = available_seats + ? WHERE flight_id = ?");
            $stmt->execute([$booking['passenger_count'], $booking['flight_id']]);
            
            // Update available seats for return flight if exists
            if ($booking['return_flight_id']) {
                $stmt->execute([$booking['passenger_count'], $booking['return_flight_id']]);
            }
            
            // Delete booking
            $stmt = $db->prepare("DELETE FROM bookings WHERE booking_id = ?");
            $stmt->execute([$booking_id]);
            
            $db->commit();
            $success_message = "Booking #$booking_id has been deleted successfully.";
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error deleting booking: " . $e->getMessage();
        }
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
           f2.departure_time as return_departure, f2.arrival_time as return_arrival
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
                <li><a href="custom_query.php">Custom Query</a></li>
            </ul>
            <div class="auth-links">
                <span>Welcome, <?php echo $_SESSION['first_name']; ?></span>
                <a href="../logout.php">Logout</a>
            </div>
        </nav>
    </header>
    
    <main>
        <div class="admin-container">
            <h1>Manage Bookings</h1>
            
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
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
                                    <td><?php echo $booking['passenger_count']; ?></td>
                                    <td>₹<?php echo number_format($booking['total_price'], 2); ?></td>
                                    <td>
                                        <a href="?delete=<?php echo $booking['booking_id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this booking?')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">No bookings found</td>
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
