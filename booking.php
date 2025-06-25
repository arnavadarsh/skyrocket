<?php
require_once 'includes/functions.php';
requireLogin(); // Require user to be logged in

$page_title = "Book Flight";

// Get flight details
$flight_id = isset($_GET['flight_id']) ? intval($_GET['flight_id']) : 0;
$return_id = isset($_GET['return_id']) ? intval($_GET['return_id']) : 0;
$passengers = isset($_GET['passengers']) ? intval($_GET['passengers']) : 1;
$class = isset($_GET['class']) ? $_GET['class'] : 'economy';

// Validate flight ID
if ($flight_id <= 0) {
    header("Location: index.php");
    exit;
}

// Get flight details with appropriate price based on class
$db = getDB();

// Check if the flights table has the necessary price columns
$tableCheck = $db->query("SHOW COLUMNS FROM flights LIKE 'economy_price'");
if ($tableCheck->rowCount() == 0) {
    // Add the price columns if they don't exist
    $db->exec("ALTER TABLE flights ADD COLUMN economy_price DECIMAL(10,2) NOT NULL DEFAULT price");
    $db->exec("ALTER TABLE flights ADD COLUMN business_price DECIMAL(10,2) NOT NULL DEFAULT (price * 1.8)");
    $db->exec("ALTER TABLE flights ADD COLUMN first_price DECIMAL(10,2) NOT NULL DEFAULT (price * 2.5)");
}

// Check if the bookings table has the class column
$tableCheck = $db->query("SHOW COLUMNS FROM bookings LIKE 'class'");
if ($tableCheck->rowCount() == 0) {
    // Add the class column if it doesn't exist
    $db->exec("ALTER TABLE bookings ADD COLUMN class VARCHAR(20) DEFAULT 'economy'");
}

$sql = "SELECT *, 
        CASE 
            WHEN ? = 'economy' THEN economy_price 
            WHEN ? = 'business' THEN business_price 
            WHEN ? = 'first' THEN first_price 
            ELSE economy_price 
        END AS selected_price
        FROM flights 
        WHERE flight_id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$class, $class, $class, $flight_id]);
$flight = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flight) {
    header("Location: index.php");
    exit;
}

// Get return flight details if applicable
$return_flight = null;
if ($return_id > 0) {
    $stmt = $db->prepare($sql);
    $stmt->execute([$class, $class, $class, $return_id]);
    $return_flight = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Calculate total price
$outbound_price = $flight['selected_price'];
$return_price = $return_flight ? $return_flight['selected_price'] : 0;
$total_price = ($outbound_price + $return_price) * $passengers;

// Process booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking'])) {
    // Check if enough seats are available
    if ($flight['available_seats'] < $passengers) {
        $error_message = "Not enough seats available on the outbound flight.";
    } elseif ($return_flight && $return_flight['available_seats'] < $passengers) {
        $error_message = "Not enough seats available on the return flight.";
    } else {
        try {
            $db->beginTransaction();
            
            // Create booking
            $is_round_trip = $return_flight ? 1 : 0;
            
            // Handle return_flight_id properly for one-way trips
            $return_flight_id = ($return_id > 0) ? $return_id : null;
            
            // Insert booking record
            $stmt = $db->prepare("INSERT INTO bookings (user_id, flight_id, return_flight_id, passenger_count, total_price, is_round_trip, class) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                $flight_id,
                $return_flight_id, // Will be NULL for one-way trips
                $passengers,
                $total_price,
                $is_round_trip,
                $class
            ]);
            
            $booking_id = $db->lastInsertId();
            
            // Update available seats for outbound flight
            $stmt = $db->prepare("UPDATE flights SET available_seats = available_seats - ? WHERE flight_id = ?");
            $stmt->execute([$passengers, $flight_id]);
            
            // Update available seats for return flight if applicable
            if ($return_flight) {
                $stmt->execute([$passengers, $return_id]);
            }
            
            $db->commit();
            
            // Redirect to booking confirmation
            header("Location: booking_confirmation.php?booking_id=" . $booking_id);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "An error occurred while processing your booking: " . $e->getMessage();
        }
    }
}
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
                <?php if (isLoggedIn()): ?>
                <li><a href="booking_history.php">Bookings</a></li>
                <?php endif; ?>
                <?php if (isLoggedIn() && isAdmin()): ?>
                <li><a href="admin/index.php">Employee Portal</a></li>
                <?php endif; ?>
            </ul>
            <div class="auth-links">
                <span>Welcome, <?php echo $_SESSION['first_name']; ?></span>
                <a href="profile.php">My Profile</a>
                <a href="booking_history.php">My Bookings</a>
                <a href="logout.php">Logout</a>
            </div>
        </nav>
    </header>

    <main>
        <div class="container">
            <div class="booking-container">
                <h1>Review and Confirm Your Booking</h1>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error"><?php echo $error_message; ?></div>
                <?php endif; ?>
                
                <div class="booking-summary">
                    <h2>Flight Details</h2>
                    
                    <div class="flight-details">
                        <h3>Outbound Flight</h3>
                        <div class="flight-card">
                            <div class="flight-info">
                                <div class="airline">
                                    <span><?php echo $flight['airline']; ?></span>
                                    <span class="flight-number"><?php echo $flight['flight_number']; ?></span>
                                </div>
                                
                                <div class="flight-times">
                                    <div class="departure">
                                        <div class="time"><?php echo date('H:i', strtotime($flight['departure_time'])); ?></div>
                                        <div class="date"><?php echo date('D, M d', strtotime($flight['departure_time'])); ?></div>
                                        <div class="city"><?php echo $flight['departure_city']; ?></div>
                                    </div>
                                    
                                    <div class="flight-duration">
                                        <div class="duration-line"></div>
                                        <div class="duration-time">
                                            <?php 
                                            $departure = new DateTime($flight['departure_time']);
                                            $arrival = new DateTime($flight['arrival_time']);
                                            $duration = $departure->diff($arrival);
                                            echo $duration->format('%h') . 'h ' . $duration->format('%i') . 'm';
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="arrival">
                                        <div class="time"><?php echo date('H:i', strtotime($flight['arrival_time'])); ?></div>
                                        <div class="date"><?php echo date('D, M d', strtotime($flight['arrival_time'])); ?></div>
                                        <div class="city"><?php echo $flight['arrival_city']; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flight-price">
                                <div class="price">₹<?php echo number_format($outbound_price, 2); ?></div>
                                <div class="per-person">per person</div>
                            </div>
                        </div>
                        
                        <?php if ($return_flight): ?>
                            <h3>Return Flight</h3>
                            <div class="flight-card">
                                <div class="flight-info">
                                    <div class="airline">
                                        <span><?php echo $return_flight['airline']; ?></span>
                                        <span class="flight-number"><?php echo $return_flight['flight_number']; ?></span>
                                    </div>
                                    
                                    <div class="flight-times">
                                        <div class="departure">
                                            <div class="time"><?php echo date('H:i', strtotime($return_flight['departure_time'])); ?></div>
                                            <div class="date"><?php echo date('D, M d', strtotime($return_flight['departure_time'])); ?></div>
                                            <div class="city"><?php echo $return_flight['departure_city']; ?></div>
                                        </div>
                                        
                                        <div class="flight-duration">
                                            <div class="duration-line"></div>
                                            <div class="duration-time">
                                                <?php 
                                                $departure = new DateTime($return_flight['departure_time']);
                                                $arrival = new DateTime($return_flight['arrival_time']);
                                                $duration = $departure->diff($arrival);
                                                echo $duration->format('%h') . 'h ' . $duration->format('%i') . 'm';
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="arrival">
                                            <div class="time"><?php echo date('H:i', strtotime($return_flight['arrival_time'])); ?></div>
                                            <div class="date"><?php echo date('D, M d', strtotime($return_flight['arrival_time'])); ?></div>
                                            <div class="city"><?php echo $return_flight['arrival_city']; ?></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="flight-price">
                                    <div class="price">₹<?php echo number_format($return_price, 2); ?></div>
                                    <div class="per-person">per person</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="booking-details">
                        <h3>Booking Summary</h3>
                        <div class="booking-info">
                            <div class="booking-row">
                                <span>Class:</span>
                                <span><?php echo ucfirst($class); ?></span>
                            </div>
                            <div class="booking-row">
                                <span>Passengers:</span>
                                <span><?php echo $passengers; ?></span>
                            </div>
                            <div class="booking-row">
                                <span>Outbound Flight:</span>
                                <span>₹<?php echo number_format($outbound_price, 2); ?> × <?php echo $passengers; ?></span>
                            </div>
                            <?php if ($return_flight): ?>
                                <div class="booking-row">
                                    <span>Return Flight:</span>
                                    <span>₹<?php echo number_format($return_price, 2); ?> × <?php echo $passengers; ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="booking-row total">
                                <span>Total Price:</span>
                                <span>₹<?php echo number_format($total_price, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <form action="" method="post">
                        <h3>Passenger Information</h3>
                        <p>Your booking will be made for <?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?>.</p>
                        
                        <div class="form-group">
                            <label for="special_requests">Special Requests (Optional)</label>
                            <textarea id="special_requests" name="special_requests" class="form-control" rows="3"></textarea>
                        </div>
                        
                        <h3>Payment Information</h3>
                        <div class="payment-methods">
                            <div class="payment-method">
                                <input type="radio" id="credit_card" name="payment_method" value="credit_card" checked>
                                <label for="credit_card">Credit Card</label>
                            </div>
                            <div class="payment-method">
                                <input type="radio" id="paypal" name="payment_method" value="paypal">
                                <label for="paypal">PayPal</label>
                            </div>
                        </div>
                        
                        <div id="credit_card_details">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="card_number">Card Number</label>
                                    <input type="text" id="card_number" name="card_number" class="form-control" placeholder="1234 5678 9012 3456">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="card_name">Name on Card</label>
                                    <input type="text" id="card_name" name="card_name" class="form-control">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="expiry_date">Expiry Date</label>
                                    <input type="text" id="expiry_date" name="expiry_date" class="form-control" placeholder="MM/YY">
                                </div>
                                
                                <div class="form-group">
                                    <label for="cvv">CVV</label>
                                    <input type="text" id="cvv" name="cvv" class="form-control" placeholder="123">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <a href="flights.php" class="btn btn-secondary">Back to Flights</a>
                            <button type="submit" name="confirm_booking" class="btn btn-primary">Confirm Booking</button>
                        </div>
                    </form>
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

    <script>
        // Toggle payment method details
        document.querySelectorAll('input[name="payment_method"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.getElementById('credit_card_details').style.display = 
                    (this.value === 'credit_card') ? 'block' : 'none';
            });
        });
        
        // Form submission handling
        document.querySelector('button[name="confirm_booking"]').addEventListener('click', function(e) {
            // You can add form validation here if needed
            document.querySelector('form').submit();
        });
    </script>
</body>
</html>
