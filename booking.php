<?php
require_once 'includes/functions.php';
requireLogin(); // Require user to be logged in

$page_title = "Book Flight";

// Get flight details
$flight_id = isset($_GET['flight_id']) ? intval($_GET['flight_id']) : 0;
$return_id = isset($_GET['return_id']) ? intval($_GET['return_id']) : 0;
$passengers = max(1, min(9, isset($_GET['passengers']) ? intval($_GET['passengers']) : 1));
$class = isset($_GET['class']) ? $_GET['class'] : 'economy';
if (!in_array($class, ['economy', 'business', 'first'], true)) {
    $class = 'economy';
}

// Validate flight ID
if ($flight_id <= 0) {
    header("Location: index.php");
    exit;
}

// Get flight details with appropriate price based on class
$db = getDB();

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

// Cancelled or already-departed flights cannot be booked
// (server-side check, not just a hidden button)
$booking_blocked = false;
if ($flight['status'] === 'cancelled') {
    $error_message = "This flight has been cancelled and cannot be booked.";
    $booking_blocked = true;
} elseif ($return_flight && $return_flight['status'] === 'cancelled') {
    $error_message = "The selected return flight has been cancelled and cannot be booked.";
    $booking_blocked = true;
} elseif (strtotime($flight['departure_time']) <= time()
    || in_array($flight['status'], ['departed', 'arrived'], true)) {
    $error_message = "This flight has already departed and cannot be booked.";
    $booking_blocked = true;
} elseif ($return_flight && (strtotime($return_flight['departure_time']) <= time()
    || in_array($return_flight['status'], ['departed', 'arrived'], true))) {
    $error_message = "The selected return flight has already departed and cannot be booked.";
    $booking_blocked = true;
}

// Process booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking']) && !$booking_blocked) {
    csrf_verify();

    // Validate the passenger detail rows server-side
    $names     = $_POST['full_name']   ?? [];
    $dobs      = $_POST['dob']         ?? [];
    $genders   = $_POST['gender']      ?? [];
    $passports = $_POST['passport_no'] ?? [];

    $form_errors = [];
    if (!is_array($names) || !is_array($dobs) || !is_array($genders) || !is_array($passports)
        || count($names) !== $passengers || count($dobs) !== $passengers
        || count($genders) !== $passengers || count($passports) > $passengers) {
        $form_errors[] = "Passenger details do not match the number of passengers.";
    } else {
        $today = date('Y-m-d');
        for ($i = 0; $i < $passengers; $i++) {
            $label = "Passenger " . ($i + 1);
            $name = trim($names[$i] ?? '');
            $dob = trim($dobs[$i] ?? '');
            $gender = $genders[$i] ?? '';
            $passport = trim($passports[$i] ?? '');

            if ($name === '' || mb_strlen($name) > 100) {
                $form_errors[] = "$label: please enter a full name (max 100 characters).";
            }
            $dt = DateTime::createFromFormat('Y-m-d', $dob);
            if (!$dt || $dt->format('Y-m-d') !== $dob || $dob >= $today) {
                $form_errors[] = "$label: date of birth must be a valid date in the past.";
            }
            if (!in_array($gender, ['male', 'female', 'other'], true)) {
                $form_errors[] = "$label: please select a gender.";
            }
            if (mb_strlen($passport) > 20) {
                $form_errors[] = "$label: passport number is too long (max 20 characters).";
            }
        }
    }

    if ($form_errors) {
        $error_message = implode(' ', $form_errors); // template escapes via e()
    } elseif ($flight['available_seats'] < $passengers) {
        $error_message = "Not enough seats available on the outbound flight.";
    } elseif ($return_flight && $return_flight['available_seats'] < $passengers) {
        $error_message = "Not enough seats available on the return flight.";
    } else {
        try {
            $db->beginTransaction();

            // Handle return_flight_id properly for one-way trips
            $return_flight_id = ($return_id > 0) ? $return_id : null;

            // Insert booking record: pending until payment completes
            $stmt = $db->prepare("INSERT INTO bookings (user_id, flight_id, return_flight_id, passenger_count, total_price, class, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([
                $_SESSION['user_id'],
                $flight_id,
                $return_flight_id, // Will be NULL for one-way trips
                $passengers,
                $total_price,
                $class
            ]);

            $booking_id = $db->lastInsertId();

            // One passengers row per traveler
            $pstmt = $db->prepare("INSERT INTO passengers (booking_id, full_name, dob, gender, passport_no) VALUES (?, ?, ?, ?, ?)");
            $passenger_ids = [];
            for ($i = 0; $i < $passengers; $i++) {
                $passport = trim($passports[$i] ?? '');
                $pstmt->execute([
                    $booking_id,
                    trim($names[$i]),
                    trim($dobs[$i]),
                    $genders[$i],
                    $passport === '' ? null : $passport,
                ]);
                $passenger_ids[] = $db->lastInsertId();
            }

            // One ticket per passenger per leg. The fare is a
            // snapshot of this flight's class price right now:
            // future price edits must not change past tickets.
            // Seat accounting is the trigger's job: every INSERT takes
            // one guarded seat, and overselling raises SQLSTATE 45000,
            // rolling this whole transaction back.
            $tstmt = $db->prepare("INSERT INTO tickets (booking_id, passenger_id, flight_id, seat_number, class, fare) VALUES (?, ?, ?, ?, ?, ?)");
            $legs = [[$flight_id, $outbound_price]];
            if ($return_flight) {
                $legs[] = [$return_id, $return_price];
            }
            foreach ($legs as $leg) {
                list($leg_flight_id, $leg_fare) = $leg;
                $seats = assignSeats($db, $leg_flight_id, $passengers);
                foreach ($passenger_ids as $i => $pid) {
                    $tstmt->execute([$booking_id, $pid, $leg_flight_id, $seats[$i], $class, $leg_fare]);
                }
            }

            $db->commit();
            header("Location: payment.php?booking_id=" . $booking_id);
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // The seat trigger signals 45000 when a flight is oversold
            if ($e instanceof PDOException && strpos($e->getMessage(), 'Not enough seats') !== false) {
                $error_message = "Not enough seats available on this flight. Please pick another flight or fewer passengers.";
            } else {
                $error_message = "An error occurred while processing your booking.";
            }
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
                <h1>Review and Confirm Your Booking</h1>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error"><?php echo e($error_message); ?></div>
                <?php endif; ?>
                
                <div class="booking-summary">
                    <h2>Flight Details</h2>
                    
                    <div class="flight-details">
                        <h3>Outbound Flight</h3>
                        <div class="flight-card">
                            <div class="flight-info">
                                <div class="airline">
                                    <span><?php echo e($flight['airline']); ?></span>
                                    <span class="flight-number"><?php echo e($flight['flight_number']); ?></span>
                                </div>
                                
                                <div class="flight-times">
                                    <div class="departure">
                                        <div class="time"><?php echo date('H:i', strtotime($flight['departure_time'])); ?></div>
                                        <div class="date"><?php echo date('D, M d', strtotime($flight['departure_time'])); ?></div>
                                        <div class="city"><?php echo e($flight['departure_city']); ?></div>
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
                                        <div class="city"><?php echo e($flight['arrival_city']); ?></div>
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
                                        <span><?php echo e($return_flight['airline']); ?></span>
                                        <span class="flight-number"><?php echo e($return_flight['flight_number']); ?></span>
                                    </div>
                                    
                                    <div class="flight-times">
                                        <div class="departure">
                                            <div class="time"><?php echo date('H:i', strtotime($return_flight['departure_time'])); ?></div>
                                            <div class="date"><?php echo date('D, M d', strtotime($return_flight['departure_time'])); ?></div>
                                            <div class="city"><?php echo e($return_flight['departure_city']); ?></div>
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
                                            <div class="city"><?php echo e($return_flight['arrival_city']); ?></div>
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
                    
                    <?php if (!$booking_blocked): ?>
                    <form action="" method="post">
                        <?php echo csrf_field(); ?>
                        <h3>Passenger Details</h3>
                        <p>Booked by <?php echo e($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>. Enter each traveler's details exactly as on their ID.</p>

                        <?php for ($i = 0; $i < $passengers; $i++): ?>
                            <fieldset class="passenger-fieldset">
                                <legend>Passenger <?php echo $i + 1; ?></legend>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="full_name_<?php echo $i; ?>">Full Name</label>
                                        <input type="text" id="full_name_<?php echo $i; ?>" name="full_name[]" class="form-control" maxlength="100" required
                                               value="<?php echo e($_POST['full_name'][$i] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="dob_<?php echo $i; ?>">Date of Birth</label>
                                        <input type="date" id="dob_<?php echo $i; ?>" name="dob[]" class="form-control" required
                                               max="<?php echo date('Y-m-d', strtotime('-1 day')); ?>"
                                               value="<?php echo e($_POST['dob'][$i] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="gender_<?php echo $i; ?>">Gender</label>
                                        <select id="gender_<?php echo $i; ?>" name="gender[]" class="form-control" required>
                                            <option value="">Select&hellip;</option>
                                            <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $gv => $gl): ?>
                                                <option value="<?php echo $gv; ?>" <?php echo (($_POST['gender'][$i] ?? '') === $gv) ? 'selected' : ''; ?>><?php echo $gl; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="passport_<?php echo $i; ?>">Passport No. (optional)</label>
                                        <input type="text" id="passport_<?php echo $i; ?>" name="passport_no[]" class="form-control" maxlength="20"
                                               value="<?php echo e($_POST['passport_no'][$i] ?? ''); ?>">
                                    </div>
                                </div>
                            </fieldset>
                        <?php endfor; ?>

                        <div class="form-actions">
                            <a href="flights.php" class="btn btn-secondary">Back to Flights</a>
                            <button type="submit" name="confirm_booking" class="btn btn-primary">Continue to Payment</button>
                        </div>
                    </form>
                    <?php endif; ?>
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
