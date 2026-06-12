<?php
require_once 'includes/functions.php';
requireLogin();

$page_title = "My Bookings";

// Get user's bookings
$db = getDB();
$stmt = $db->prepare("
    SELECT b.*, 
           f1.flight_number as outbound_flight, f1.airline as outbound_airline,
           f1.departure_city as from_city, f1.arrival_city as to_city, 
           f1.departure_time, f1.arrival_time, f1.status as outbound_status,
           g1.terminal as outbound_terminal, g1.gate_number as outbound_gate,
           CASE 
               WHEN b.class = 'economy' THEN f1.economy_price
               WHEN b.class = 'business' THEN f1.business_price
               WHEN b.class = 'first' THEN f1.first_price
               ELSE f1.economy_price
           END as outbound_price,
           f2.flight_number as return_flight, f2.airline as return_airline,
           f2.departure_time as return_departure, f2.arrival_time as return_arrival, f2.status as return_status,
           CASE 
               WHEN b.class = 'economy' THEN f2.economy_price
               WHEN b.class = 'business' THEN f2.business_price
               WHEN b.class = 'first' THEN f2.first_price
               ELSE f2.economy_price
           END as return_price,
           (SELECT pay.status FROM payments pay WHERE pay.booking_id = b.booking_id ORDER BY pay.payment_id DESC LIMIT 1) as payment_status
    FROM bookings b
    JOIN flights f1 ON b.flight_id = f1.flight_id
    LEFT JOIN flights f2 ON b.return_flight_id = f2.flight_id
    LEFT JOIN gates g1 ON f1.gate_id = g1.gate_id
    WHERE b.user_id = ?
    ORDER BY b.booking_date DESC
");
$stmt->execute([$_SESSION['user_id']]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                <li><a href="booking_history.php" class="active">Bookings</a></li>
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
                <a href="booking_history.php" class="active">My Bookings</a>
                <a href="logout.php">Logout</a>
            </div>
        </nav>
    </header>

    <main>
        <div class="container">
            <h1>My Bookings</h1>

            <?php if (isset($_GET['cancelled'])): ?>
                <div class="alert alert-success">Your booking has been cancelled and the seats have been released.</div>
            <?php endif; ?>
            <?php if (isset($_GET['cancel_error'])): ?>
                <div class="alert alert-error"><?php echo e($_GET['cancel_error']); ?></div>
            <?php endif; ?>

            <?php if (count($bookings) > 0): ?>
                <div class="booking-history">
                    <?php foreach ($bookings as $booking): ?>
                        <div class="booking-card">
                            <div class="booking-header">
                                <div class="booking-id">Booking #SKY<?php echo str_pad($booking['booking_id'], 6, '0', STR_PAD_LEFT); ?></div>
                                <div class="booking-date">Booked on <?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></div>
                                <?php if ($booking['status'] === 'cancelled'): ?>
                                    <span class="status-badge status-cancelled">Cancelled</span>
                                    <?php if ($booking['payment_status'] === 'refunded'): ?>
                                        <span class="status-badge status-departed">Refunded</span>
                                    <?php endif; ?>
                                <?php elseif ($booking['status'] === 'pending'): ?>
                                    <span class="status-badge status-delayed">Payment pending</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flight-details">
                                <div class="flight-info-row">
                                    <div class="flight-label">Outbound Flight:</div>
                                    <div class="flight-value"><?php echo e($booking['outbound_flight']); ?> (<?php echo e($booking['outbound_airline']); ?>) <?php echo statusBadge($booking['outbound_status']); ?></div>
                                </div>
                                <div class="flight-route">
                                    <div class="route-from"><?php echo e($booking['from_city']); ?></div>
                                    <div class="route-arrow">→</div>
                                    <div class="route-to"><?php echo e($booking['to_city']); ?></div>
                                </div>
                                <div class="flight-time">
                                    <div class="time-label">Departure:</div>
                                    <div class="time-value"><?php echo date('D, M d, Y H:i', strtotime($booking['departure_time'])); ?></div>
                                </div>
                                <div class="flight-time">
                                    <div class="time-label">Arrival:</div>
                                    <div class="time-value"><?php echo date('D, M d, Y H:i', strtotime($booking['arrival_time'])); ?></div>
                                </div>
                                <?php if ($booking['outbound_gate']): ?>
                                <div class="flight-time">
                                    <div class="time-label">Gate:</div>
                                    <div class="time-value"><strong><?php echo e($booking['outbound_terminal'] . '-' . $booking['outbound_gate']); ?></strong></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($booking['return_flight']): ?>
                                    <div class="flight-info-row return">
                                        <div class="flight-label">Return Flight:</div>
                                        <div class="flight-value"><?php echo e($booking['return_flight']); ?> (<?php echo e($booking['return_airline']); ?>) <?php echo statusBadge($booking['return_status']); ?></div>
                                    </div>
                                    <div class="flight-route">
                                        <div class="route-from"><?php echo e($booking['to_city']); ?></div>
                                        <div class="route-arrow">→</div>
                                        <div class="route-to"><?php echo e($booking['from_city']); ?></div>
                                    </div>
                                    <div class="flight-time">
                                        <div class="time-label">Departure:</div>
                                        <div class="time-value"><?php echo date('D, M d, Y H:i', strtotime($booking['return_departure'])); ?></div>
                                    </div>
                                    <div class="flight-time">
                                        <div class="time-label">Arrival:</div>
                                        <div class="time-value"><?php echo date('D, M d, Y H:i', strtotime($booking['return_arrival'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="booking-footer">
                                <div class="booking-summary">
                                    <div class="summary-item">
                                        <span class="summary-label">Class:</span>
                                        <span class="summary-value"><?php echo ucfirst($booking['class'] ?? 'Economy'); ?></span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Passengers:</span>
                                        <span class="summary-value"><?php echo $booking['passenger_count']; ?></span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Total Price:</span>
                                        <span class="summary-value">₹<?php echo number_format($booking['total_price'], 2); ?></span>
                                    </div>
                                </div>
                                <div class="booking-actions">
                                    <?php if ($booking['status'] === 'pending'): ?>
                                        <a href="payment.php?booking_id=<?php echo $booking['booking_id']; ?>" class="btn btn-primary">Complete Payment</a>
                                    <?php else: ?>
                                        <a href="booking_confirmation.php?booking_id=<?php echo $booking['booking_id']; ?>" class="btn">View Details</a>
                                    <?php endif; ?>
                                    <?php
                                    $can_cancel = in_array($booking['status'], ['pending', 'confirmed'], true)
                                        && strtotime($booking['departure_time']) > time()
                                        && !in_array($booking['outbound_status'], ['departed', 'arrived'], true);
                                    ?>
                                    <?php if ($can_cancel): ?>
                                        <form action="cancel_booking.php" method="post" onsubmit="return confirm('Cancel this booking? The seats will be released.')" style="display:inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="booking_id" value="<?php echo $booking['booking_id']; ?>">
                                            <button type="submit" class="btn btn-danger">Cancel Booking</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-bookings">
                    <h2>You don't have any bookings yet.</h2>
                    <p>Search for flights and book your next trip!</p>
                    <a href="index.php" class="btn">Search Flights</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="site-footer">
        <div class="footer-container">
            <!-- Logo and Tagline Section -->
            <div class="footer-brand">
                <div class="footer-logo">
                    <img src="assets/images/logo.png" alt="SkyConnect Logo">
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
