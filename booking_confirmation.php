<?php
require_once 'includes/functions.php';
requireLogin();

$page_title = "Booking Confirmation";

// Get booking ID from URL
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if ($booking_id <= 0) {
    header("Location: index.php");
    exit;
}

// Get booking details
$db = getDB();
$stmt = $db->prepare("
    SELECT b.*, 
           f1.flight_number as outbound_flight, f1.airline as outbound_airline, 
           f1.departure_city as from_city, f1.arrival_city as to_city, 
           f1.departure_time, f1.arrival_time,
           g1.terminal as outbound_terminal, g1.gate_number as outbound_gate,
           g2.terminal as return_terminal, g2.gate_number as return_gate,
           CASE 
               WHEN b.class = 'economy' THEN f1.economy_price
               WHEN b.class = 'business' THEN f1.business_price
               WHEN b.class = 'first' THEN f1.first_price
               ELSE f1.economy_price
           END as outbound_price,
           f2.flight_number as return_flight, f2.airline as return_airline,
           f2.departure_time as return_departure, f2.arrival_time as return_arrival,
           CASE 
               WHEN b.class = 'economy' THEN f2.economy_price
               WHEN b.class = 'business' THEN f2.business_price
               WHEN b.class = 'first' THEN f2.first_price
               ELSE f2.economy_price
           END as return_price
    FROM bookings b
    JOIN flights f1 ON b.flight_id = f1.flight_id
    LEFT JOIN flights f2 ON b.return_flight_id = f2.flight_id
    LEFT JOIN gates g1 ON f1.gate_id = g1.gate_id
    LEFT JOIN gates g2 ON f2.gate_id = g2.gate_id
    WHERE b.booking_id = ? AND b.user_id = ?
");
$stmt->execute([$booking_id, $_SESSION['user_id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    header("Location: index.php");
    exit;
}

// Get class name for display
$class_display = ucfirst($booking['class'] ?? 'Economy');

// Tickets (passenger + leg + seat + fare snapshot)
$stmt = $db->prepare("
    SELECT t.ticket_id, t.flight_id, t.seat_number, t.class, t.fare, t.status,
           p.full_name, f.flight_number
    FROM tickets t
    JOIN passengers p ON t.passenger_id = p.passenger_id
    JOIN flights f ON t.flight_id = f.flight_id
    WHERE t.booking_id = ?
    ORDER BY t.flight_id = ? DESC, t.ticket_id
");
$stmt->execute([$booking_id, $booking['flight_id']]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Luggage per ticket (added by staff at check-in)
$stmt = $db->prepare("
    SELECT l.ticket_id, l.weight, l.status
    FROM luggage l
    JOIN tickets t ON l.ticket_id = t.ticket_id
    WHERE t.booking_id = ?
    ORDER BY l.luggage_id
");
$stmt->execute([$booking_id]);
$luggage_by_ticket = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bag) {
    $luggage_by_ticket[$bag['ticket_id']][] = $bag;
}

// Latest payment for this booking (NULL if never paid)
$stmt = $db->prepare("SELECT * FROM payments WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1");
$stmt->execute([$booking_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$method_labels = ['card' => 'Card', 'upi' => 'UPI', 'netbanking' => 'Netbanking'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - SkyConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Additional styles for the confirmation page */
        .confirmation-container {
            max-width: 800px;
            margin: 40px auto;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }
        
        .confirmation-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .confirmation-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 70px;
            height: 70px;
            background-color: #e6f3ff;
            color: #3478f6;
            font-size: 36px;
            border-radius: 50%;
            margin-bottom: 15px;
        }
        
        .confirmation-header h1 {
            color: #3478f6;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .confirmation-header p {
            color: #666;
            font-size: 16px;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .booking-details {
            display: flex;
            flex-direction: column;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .booking-info, .flight-info {
            width: 100%;
        }
        
        .booking-info h2, .flight-info h2 {
            margin-bottom: 15px;
            color: #333;
            font-size: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .info-group {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .info-row.total {
            font-weight: bold;
            font-size: 18px;
            margin-top: 10px;
            border-top: 1px solid #ddd;
            padding-top: 12px;
        }
        
        .flight-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .flight-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .airline {
            font-weight: bold;
            color: #333;
        }
        
        .flight-number {
            color: #666;
            margin-left: 10px;
            font-size: 14px;
        }
        
        .flight-type {
            background-color: #3478f6;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .flight-route {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .departure, .arrival {
            text-align: center;
            flex: 1;
        }
        
        .city {
            font-weight: bold;
            font-size: 18px;
            margin-bottom: 5px;
            color: #333;
        }
        
        .time {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }
        
        .date {
            color: #666;
            font-size: 14px;
        }
        
        .flight-duration {
            flex: 2;
            position: relative;
            margin: 0 20px;
            height: 30px;
        }
        
        .line {
            height: 2px;
            background-color: #ddd;
            width: 100%;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .airplane-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 18px;
            color: #3478f6;
            background-color: #f8f9fa;
            padding: 0 5px;
        }
        
        .flight-price {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .price-label {
            color: #666;
            font-size: 14px;
        }
        
        .price-value {
            font-weight: bold;
            color: #3478f6;
            font-size: 18px;
        }
        
        .confirmation-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #3478f6;
            color: white;
            border-radius: 5px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            text-align: center;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover {
            background-color: #2a62c7;
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid #3478f6;
            color: #3478f6;
        }
        
        .btn-outline:hover {
            background-color: #f0f7ff;
        }
        
        /* Responsive adjustments */
        @media (min-width: 768px) {
            .booking-details {
                flex-direction: row;
            }
            
            .booking-info {
                width: 35%;
            }
            
            .flight-info {
                width: 65%;
            }
        }
        
        @media (max-width: 767px) {
            .confirmation-container {
                padding: 20px;
                margin: 20px;
            }
            
            .confirmation-actions {
                flex-direction: column;
            }
            
            .confirmation-actions .btn {
                width: 100%;
            }
        }
    </style>
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
            <div class="confirmation-container">
                <?php if ($booking['status'] === 'cancelled'): ?>
                <div class="confirmation-header">
                    <div class="confirmation-icon" style="background-color:#f8d7da;color:#721c24;">✕</div>
                    <h1>Booking Cancelled</h1>
                    <p>This booking has been cancelled. The details below are kept for your records.</p>
                </div>
                <?php else: ?>
                <div class="confirmation-header">
                    <div class="confirmation-icon">✓</div>
                    <h1>Booking Confirmed!</h1>
                    <p>Your booking has been successfully confirmed. Below are your booking details.</p>
                </div>
                <?php endif; ?>
                
                <div class="booking-details">
                    <div class="booking-info">
                        <h2>Booking Information</h2>
                        <div class="info-group">
                            <div class="info-row">
                                <span>Booking Reference:</span>
                                <strong>SKY<?php echo str_pad($booking['booking_id'], 6, '0', STR_PAD_LEFT); ?></strong>
                            </div>
                            <div class="info-row">
                                <span>Booking Date:</span>
                                <span><?php echo date('M d, Y H:i', strtotime($booking['booking_date'])); ?></span>
                            </div>
                            <div class="info-row">
                                <span>Class:</span>
                                <span><?php echo $class_display; ?></span>
                            </div>
                            <div class="info-row">
                                <span>Passenger(s):</span>
                                <span><?php echo $booking['passenger_count']; ?></span>
                            </div>
                            <?php if ($payment): ?>
                            <div class="info-row">
                                <span>Payment Method:</span>
                                <span><?php echo e($method_labels[$payment['method']] ?? $payment['method']); ?></span>
                            </div>
                            <div class="info-row">
                                <span>Transaction Ref:</span>
                                <span><?php echo e($payment['txn_ref']); ?></span>
                            </div>
                            <div class="info-row">
                                <span>Payment Status:</span>
                                <span><?php echo $payment['status'] === 'refunded'
                                    ? '<span class="status-badge status-cancelled">Refunded</span>'
                                    : '<span class="status-badge status-scheduled">' . e(ucfirst($payment['status'])) . '</span>'; ?></span>
                            </div>
                            <?php if ($payment['paid_at']): ?>
                            <div class="info-row">
                                <span>Paid At:</span>
                                <span><?php echo date('M d, Y H:i', strtotime($payment['paid_at'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <div class="info-row total">
                                <span>Total Price:</span>
                                <span>₹<?php echo number_format($booking['total_price'], 2); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flight-info">
                        <h2>Flight Details</h2>
                        <div class="flight-card">
                            <div class="flight-header">
                                <div>
                                    <span class="airline"><?php echo e($booking['outbound_airline']); ?></span>
                                    <span class="flight-number"><?php echo e($booking['outbound_flight']); ?></span>
                                </div>
                                <div class="flight-type">Outbound Flight</div>
                            </div>
                            <p class="gate-info">
                                Gate: <?php echo $booking['outbound_gate'] ? '<strong>' . e($booking['outbound_terminal'] . '-' . $booking['outbound_gate']) . '</strong>' : 'TBA'; ?>
                            </p>
                            
                            <div class="flight-route">
                                <div class="departure">
                                    <div class="city"><?php echo e($booking['from_city']); ?></div>
                                    <div class="time"><?php echo date('H:i', strtotime($booking['departure_time'])); ?></div>
                                    <div class="date"><?php echo date('M d, Y', strtotime($booking['departure_time'])); ?></div>
                                </div>
                                
                                <div class="flight-duration">
                                    <div class="line"></div>
                                    <div class="airplane-icon">✈</div>
                                </div>
                                
                                <div class="arrival">
                                    <div class="city"><?php echo e($booking['to_city']); ?></div>
                                    <div class="time"><?php echo date('H:i', strtotime($booking['arrival_time'])); ?></div>
                                    <div class="date"><?php echo date('M d, Y', strtotime($booking['arrival_time'])); ?></div>
                                </div>
                            </div>
                            
                            <div class="flight-price">
                                <div class="price-label">Price per passenger:</div>
                                <div class="price-value">₹<?php echo number_format($booking['outbound_price'], 2); ?></div>
                            </div>
                        </div>
                        
                        <?php if ($booking['return_flight']): ?>
                        <div class="flight-card">
                            <div class="flight-header">
                                <div>
                                    <span class="airline"><?php echo e($booking['return_airline']); ?></span>
                                    <span class="flight-number"><?php echo e($booking['return_flight']); ?></span>
                                </div>
                                <div class="flight-type">Return Flight</div>
                            </div>
                            <p class="gate-info">
                                Gate: <?php echo $booking['return_gate'] ? '<strong>' . e($booking['return_terminal'] . '-' . $booking['return_gate']) . '</strong>' : 'TBA'; ?>
                            </p>
                            
                            <div class="flight-route">
                                <div class="departure">
                                    <div class="city"><?php echo e($booking['to_city']); ?></div>
                                    <div class="time"><?php echo date('H:i', strtotime($booking['return_departure'])); ?></div>
                                    <div class="date"><?php echo date('M d, Y', strtotime($booking['return_departure'])); ?></div>
                                </div>
                                
                                <div class="flight-duration">
                                    <div class="line"></div>
                                    <div class="airplane-icon">✈</div>
                                </div>
                                
                                <div class="arrival">
                                    <div class="city"><?php echo e($booking['from_city']); ?></div>
                                    <div class="time"><?php echo date('H:i', strtotime($booking['return_arrival'])); ?></div>
                                    <div class="date"><?php echo date('M d, Y', strtotime($booking['return_arrival'])); ?></div>
                                </div>
                            </div>
                            
                            <div class="flight-price">
                                <div class="price-label">Price per passenger:</div>
                                <div class="price-value">₹<?php echo number_format($booking['return_price'], 2); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="tickets-section">
                    <h2>Tickets</h2>
                    <table class="admin-table tickets-table">
                        <thead>
                            <tr>
                                <th>Passenger</th>
                                <th>Flight</th>
                                <th>Leg</th>
                                <th>Seat</th>
                                <th>Class</th>
                                <th>Fare</th>
                                <th>Status</th>
                                <th>Luggage</th>
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
                                    <td><?php echo $t['status'] === 'cancelled'
                                        ? '<span class="status-badge status-cancelled">Cancelled</span>'
                                        : '<span class="status-badge status-scheduled">' . e(ucfirst(str_replace('_', ' ', $t['status']))) . '</span>'; ?></td>
                                    <td>
                                        <?php if (!empty($luggage_by_ticket[$t['ticket_id']])): ?>
                                            <?php foreach ($luggage_by_ticket[$t['ticket_id']] as $bag): ?>
                                                <div class="luggage-line">
                                                    <?php echo number_format($bag['weight'], 1); ?> kg
                                                    <span class="status-badge <?php echo $bag['status'] === 'lost' ? 'status-cancelled' : ($bag['status'] === 'arrived' ? 'status-scheduled' : 'status-boarding'); ?>">
                                                        <?php echo e(ucfirst(str_replace('_', ' ', $bag['status']))); ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="confirmation-actions">
                    <a href="booking_history.php" class="btn">View All Bookings</a>
                    <a href="index.php" class="btn btn-outline">Back to Home</a>
                </div>
            </div>
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
