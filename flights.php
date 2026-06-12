<?php
require_once 'includes/functions.php';
$page_title = "Search Flights";

// Process search parameters
$from = isset($_GET['from']) ? $_GET['from'] : '';
$to = isset($_GET['to']) ? $_GET['to'] : '';
$departure_date = isset($_GET['departure_date']) ? $_GET['departure_date'] : '';
$return_date = isset($_GET['return_date']) ? $_GET['return_date'] : '';
$trip_type = isset($_GET['trip_type']) ? $_GET['trip_type'] : 'round';
$class = isset($_GET['class']) ? $_GET['class'] : 'economy';
if (!in_array($class, ['economy', 'business', 'first'], true)) {
    $class = 'economy';
}
$passengers = max(1, min(9, isset($_GET['passengers']) ? intval($_GET['passengers']) : 1));

// Filter parameters
$min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 500000;
$airlines = isset($_GET['airline']) ? $_GET['airline'] : [];
$departure_times = isset($_GET['departure_time']) ? $_GET['departure_time'] : [];

// Search for flights if parameters are provided
$outbound_flights = [];
$return_flights = [];

if (!empty($from) && !empty($to) && !empty($departure_date)) {
    // Build the SQL query with class-specific pricing and filters
    $sql = "SELECT *, 
            CASE 
                WHEN ? = 'economy' THEN economy_price 
                WHEN ? = 'business' THEN business_price 
                WHEN ? = 'first' THEN first_price 
                ELSE economy_price 
            END AS selected_price
            FROM flights 
            WHERE departure_city LIKE ? 
            AND arrival_city LIKE ? 
            AND DATE(departure_time) = ?
            AND available_seats >= ?";
    
    // Add price filter
    $sql .= " AND (
                CASE 
                    WHEN ? = 'economy' THEN economy_price 
                    WHEN ? = 'business' THEN business_price 
                    WHEN ? = 'first' THEN first_price 
                    ELSE economy_price 
                END
            ) BETWEEN ? AND ?";
    
    $params = [$class, $class, $class, "%$from%", "%$to%", $departure_date, $passengers, 
               $class, $class, $class, $min_price, $max_price];
    
    // Add airline filter if selected
    if (!empty($airlines)) {
        $placeholders = implode(',', array_fill(0, count($airlines), '?'));
        $sql .= " AND airline IN ($placeholders)";
        $params = array_merge($params, $airlines);
    }
    
    // Add departure time filter if selected
    if (!empty($departure_times)) {
        $time_conditions = [];
        foreach ($departure_times as $time_range) {
            switch ($time_range) {
                case 'morning':
                    $time_conditions[] = "(TIME(departure_time) BETWEEN '06:00:00' AND '11:59:59')";
                    break;
                case 'afternoon':
                    $time_conditions[] = "(TIME(departure_time) BETWEEN '12:00:00' AND '17:59:59')";
                    break;
                case 'evening':
                    $time_conditions[] = "(TIME(departure_time) BETWEEN '18:00:00' AND '23:59:59')";
                    break;
                case 'night':
                    $time_conditions[] = "(TIME(departure_time) BETWEEN '00:00:00' AND '05:59:59')";
                    break;
            }
        }
        if (!empty($time_conditions)) {
            $sql .= " AND (" . implode(' OR ', $time_conditions) . ")";
        }
    }
    
    $sql .= " ORDER BY departure_time";
    
    // Execute the query
    $db = getDB();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $outbound_flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get return flights if round trip
    if ($trip_type === 'round' && !empty($return_date)) {
        // Use the same SQL but swap from/to cities and use return date
        $stmt = $db->prepare($sql);
        
        // Replace the from/to and date parameters with swapped values
        $return_params = $params;
        $return_params[3] = "%$to%";  // from becomes to
        $return_params[4] = "%$from%"; // to becomes from
        $return_params[5] = $return_date; // departure date becomes return date
        
        $stmt->execute($return_params);
        $return_flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                <li><a href="flights.php" class="active">Flights</a></li>
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
                <?php if (isLoggedIn()): ?>
                <span>Welcome, <?php echo e($_SESSION['first_name']); ?></span>
                <a href="profile.php">My Profile</a>
                <a href="booking_history.php">My Bookings</a>
                <a href="logout.php">Logout</a>
                <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main>
        <div class="search-summary">
            <div class="container">
                <h1>Flight Search Results</h1>
                <?php if (!empty($from) && !empty($to)): ?>
                <div class="search-details">
                    <div class="route">
                        <strong><?php echo htmlspecialchars($from); ?></strong> to 
                        <strong><?php echo htmlspecialchars($to); ?></strong>
                    </div>
                    <div class="trip-details">
                        <span><?php echo e(ucfirst($trip_type)); ?> Trip</span> | 
                        <span><?php echo ucfirst($class); ?> Class</span> | 
                        <span><?php echo $passengers; ?> Passenger<?php echo $passengers > 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="dates">
                        <span>Departure: <?php echo date('M d, Y', strtotime($departure_date)); ?></span>
                        <?php if ($trip_type === 'round' && !empty($return_date)): ?>
                        <span>Return: <?php echo date('M d, Y', strtotime($return_date)); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="container">
            <div class="flight-search-container">
                <div class="search-sidebar">
                    <div class="filter-section">
                        <h3>Filter Results</h3>
                        <form action="" method="get" id="filter-form">
                            <!-- Hidden fields to preserve search parameters -->
                            <input type="hidden" name="from" value="<?php echo htmlspecialchars($from); ?>">
                            <input type="hidden" name="to" value="<?php echo htmlspecialchars($to); ?>">
                            <input type="hidden" name="departure_date" value="<?php echo htmlspecialchars($departure_date); ?>">
                            <input type="hidden" name="return_date" value="<?php echo htmlspecialchars($return_date); ?>">
                            <input type="hidden" name="trip_type" value="<?php echo htmlspecialchars($trip_type); ?>">
                            <input type="hidden" name="class" value="<?php echo htmlspecialchars($class); ?>">
                            <input type="hidden" name="passengers" value="<?php echo $passengers; ?>">
                            
                            <div class="filter-group">
                                <h4>Price Range</h4>
                                <div class="price-slider">
                                    <input type="range" min="0" max="500000" value="<?php echo $max_price; ?>" class="slider" id="price-range" name="max_price">
                                    <div class="price-values">
                                        <span>₹0</span>
                                        <span id="price-value">₹500000</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="filter-group">
                                <h4>Airlines</h4>
                                <div class="checkbox-group">
                                    <?php
                                    $db = getDB();
                                    $stmt = $db->query("SELECT DISTINCT airline FROM flights ORDER BY airline");
                                    $all_airlines = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    
                                    foreach ($all_airlines as $airline): 
                                        $checked = empty($airlines) || in_array($airline, $airlines) ? 'checked' : '';
                                    ?>
                                    <label>
                                        <input type="checkbox" name="airline[]" value="<?php echo e($airline); ?>" <?php echo $checked; ?>> 
                                        <?php echo e($airline); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="filter-group">
                                <h4>Departure Time</h4>
                                <div class="checkbox-group">
                                    <label>
                                        <input type="checkbox" name="departure_time[]" value="morning" 
                                            <?php echo empty($departure_times) || in_array('morning', $departure_times) ? 'checked' : ''; ?>> 
                                        Morning (6AM - 12PM)
                                    </label>
                                    <label>
                                        <input type="checkbox" name="departure_time[]" value="afternoon" 
                                            <?php echo empty($departure_times) || in_array('afternoon', $departure_times) ? 'checked' : ''; ?>> 
                                        Afternoon (12PM - 6PM)
                                    </label>
                                    <label>
                                        <input type="checkbox" name="departure_time[]" value="evening" 
                                            <?php echo empty($departure_times) || in_array('evening', $departure_times) ? 'checked' : ''; ?>> 
                                        Evening (6PM - 12AM)
                                    </label>
                                    <label>
                                        <input type="checkbox" name="departure_time[]" value="night" 
                                            <?php echo empty($departure_times) || in_array('night', $departure_times) ? 'checked' : ''; ?>> 
                                        Night (12AM - 6AM)
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                        </form>
                    </div>
                </div>
                
                <div class="flight-results">
                    <?php if (!empty($outbound_flights)): ?>
                        <div class="flight-section">
                            <h2>Outbound Flights</h2>
                            <p class="flight-date"><?php echo date('l, F j, Y', strtotime($departure_date)); ?></p>
                            
                            <?php foreach ($outbound_flights as $flight): ?>
                                <div class="flight-card" data-flight-id="<?php echo $flight['flight_id']; ?>">
                                    <div class="flight-info">
                                        <div class="airline">
                                            <span><?php echo e($flight['airline']); ?></span>
                                            <?php echo statusBadge($flight['status']); ?>
                                            <span class="flight-number"><?php echo e($flight['flight_number']); ?></span>
                                        </div>
                                        
                                        <div class="flight-times">
                                            <div class="departure">
                                                <div class="time"><?php echo date('H:i', strtotime($flight['departure_time'])); ?></div>
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
                                                <div class="city"><?php echo e($flight['arrival_city']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flight-price">
                                        <div class="price">₹<?php echo number_format($flight['selected_price'], 2); ?></div>
                                        <div class="per-person">per person</div>
                                        <?php if ($flight['status'] === 'cancelled'): ?>
                                            <span class="btn btn-select btn-disabled">Unavailable</span>
                                        <?php else: ?>
                                            <a href="booking.php?flight_id=<?php echo $flight['flight_id']; ?>&return_id=<?php echo isset($selected_return) ? $selected_return : ''; ?>&passengers=<?php echo $passengers; ?>&class=<?php echo $class; ?>" class="btn btn-select">Select</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (!empty($return_flights)): ?>
                            <div class="flight-section">
                                <h2>Return Flights</h2>
                                <p class="flight-date"><?php echo date('l, F j, Y', strtotime($return_date)); ?></p>
                                
                                <?php foreach ($return_flights as $flight): ?>
                                    <div class="flight-card" data-flight-id="<?php echo $flight['flight_id']; ?>">
                                        <div class="flight-info">
                                            <div class="airline">
                                                <span><?php echo e($flight['airline']); ?></span>
                                            <?php echo statusBadge($flight['status']); ?>
                                                <span class="flight-number"><?php echo e($flight['flight_number']); ?></span>
                                            </div>
                                            
                                            <div class="flight-times">
                                                <div class="departure">
                                                    <div class="time"><?php echo date('H:i', strtotime($flight['departure_time'])); ?></div>
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
                                                    <div class="city"><?php echo e($flight['arrival_city']); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="flight-price">
                                            <div class="price">₹<?php echo number_format($flight['selected_price'], 2); ?></div>
                                            <div class="per-person">per person</div>
                                            <?php if ($flight['status'] === 'cancelled'): ?>
                                                <span class="btn btn-select btn-disabled">Unavailable</span>
                                            <?php else: ?>
                                                <a href="booking.php?flight_id=<?php echo isset($selected_outbound) ? $selected_outbound : ''; ?>&return_id=<?php echo $flight['flight_id']; ?>&passengers=<?php echo $passengers; ?>&class=<?php echo $class; ?>" class="btn btn-select">Select</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="no-flights">
                            <h2>No Flights Found</h2>
                            <p>We couldn't find any flights matching your search criteria. Please try different dates or destinations.</p>
                            <a href="index.php" class="btn">Back to Search</a>
                        </div>
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
        
        <!-- Social Media and Copyright -->
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
        // Price range slider
        const priceSlider = document.getElementById('price-range');
        const priceValue = document.getElementById('price-value');
        
        priceSlider.addEventListener('input', function() {
            priceValue.textContent = '₹' + this.value;
        });
        
        // Flight selection
        document.querySelectorAll('.flight-card').forEach(function(card) {
            card.addEventListener('click', function(e) {
                // Don't trigger if clicking on the select button
                if (e.target.classList.contains('btn-select')) {
                    return;
                }
                
                // Toggle selected class
                document.querySelectorAll('.flight-card').forEach(function(c) {
                    c.classList.remove('selected');
                });
                
                this.classList.add('selected');
                
                // Store selected flight IDs
                const flightId = this.getAttribute('data-flight-id');
                
                if (this.closest('.flight-section').querySelector('h2').textContent.includes('Outbound')) {
                    window.selectedOutbound = flightId;
                } else {
                    window.selectedReturn = flightId;
                }
                
                // Update select buttons if both flights are selected (for round trips)
                if (window.selectedOutbound && window.selectedReturn) {
                    document.querySelectorAll('.flight-card .btn-select').forEach(function(btn) {
                        const card = btn.closest('.flight-card');
                        const isOutbound = card.closest('.flight-section').querySelector('h2').textContent.includes('Outbound');
                        
                        if (isOutbound) {
                            btn.href = `booking.php?flight_id=${window.selectedOutbound}&return_id=${window.selectedReturn}&passengers=<?php echo $passengers; ?>&class=<?php echo $class; ?>`;
                        } else {
                            btn.href = `booking.php?flight_id=${window.selectedOutbound}&return_id=${window.selectedReturn}&passengers=<?php echo $passengers; ?>&class=<?php echo $class; ?>`;
                        }
                    });
                }
            });
        });
        
        // Initialize with any pre-selected flights
        <?php if (!empty($outbound_flights) && isset($_GET['selected_outbound'])): ?>
        window.selectedOutbound = '<?php echo intval($_GET['selected_outbound']); ?>';
        document.querySelector(`.flight-card[data-flight-id="${window.selectedOutbound}"]`)?.classList.add('selected');
        <?php endif; ?>
        
        <?php if (!empty($return_flights) && isset($_GET['selected_return'])): ?>
        window.selectedReturn = '<?php echo intval($_GET['selected_return']); ?>';
        document.querySelector(`.flight-card[data-flight-id="${window.selectedReturn}"]`)?.classList.add('selected');
        <?php endif; ?>
        
        // Auto-submit form when checkboxes change (optional)
        /*
        document.querySelectorAll('#filter-form input[type="checkbox"]').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                document.getElementById('filter-form').submit();
            });
        });
        */
    </script>
</body>
</html>
