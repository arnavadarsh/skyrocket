<?php
require_once '../includes/functions.php';
requireAdmin();

$page_title = "Manage Flights";

$db = getDB();

// Handle flight deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $flight_id = $_GET['delete'];
    
    try {
        // Check if flight has bookings
        $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE flight_id = ? OR return_flight_id = ?");
        $stmt->execute([$flight_id, $flight_id]);
        $has_bookings = $stmt->fetchColumn() > 0;
        
        if ($has_bookings) {
            $error_message = "Cannot delete flight with existing bookings.";
        } else {
            $stmt = $db->prepare("DELETE FROM flights WHERE flight_id = ?");
            $stmt->execute([$flight_id]);
            $success_message = "Flight has been deleted successfully.";
        }
    } catch (Exception $e) {
        $error_message = "Error deleting flight: " . $e->getMessage();
    }
}

// Handle flight addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_flight'])) {
    $flight_number = $_POST['flight_number'];
    $airline = $_POST['airline'];
    $departure_city = $_POST['departure_city'];
    $arrival_city = $_POST['arrival_city'];
    $departure_time = $_POST['departure_time'];
    $arrival_time = $_POST['arrival_time'];
    $economy_price = $_POST['economy_price'];
    $business_price = $_POST['business_price'];
    $first_price = $_POST['first_price'];
    $available_seats = $_POST['available_seats'];
    
    try {
        $stmt = $db->prepare("INSERT INTO flights (flight_number, airline, departure_city, arrival_city, departure_time, arrival_time, price, economy_price, business_price, first_price, available_seats) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$flight_number, $airline, $departure_city, $arrival_city, $departure_time, $arrival_time, $economy_price, $economy_price, $business_price, $first_price, $available_seats]);
        $success_message = "Flight has been added successfully.";
    } catch (Exception $e) {
        $error_message = "Error adding flight: " . $e->getMessage();
    }
}

// Get all flights
$stmt = $db->query("SELECT * FROM flights ORDER BY departure_time");
$flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                <li><a href="bookings.php">Bookings</a></li>
                <li><a href="users.php">Users</a></li>
                <li><a href="flights.php" class="active">Flights</a></li>
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
            <h1>Manage Flights</h1>
            
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>
            
            <div class="admin-card">
                <h2>Add New Flight</h2>
                <form action="" method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="flight_number">Flight Number</label>
                            <input type="text" id="flight_number" name="flight_number" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="airline">Airline</label>
                            <input type="text" id="airline" name="airline" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="departure_city">Departure City</label>
                            <input type="text" id="departure_city" name="departure_city" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="arrival_city">Arrival City</label>
                            <input type="text" id="arrival_city" name="arrival_city" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="departure_time">Departure Time</label>
                            <input type="datetime-local" id="departure_time" name="departure_time" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="arrival_time">Arrival Time</label>
                            <input type="datetime-local" id="arrival_time" name="arrival_time" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="economy_price">Economy Price (₹)</label>
                            <input type="number" id="economy_price" name="economy_price" step="0.01" min="0" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="business_price">Business Price (₹)</label>
                            <input type="number" id="business_price" name="business_price" step="0.01" min="0" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="first_price">First Class Price (₹)</label>
                            <input type="number" id="first_price" name="first_price" step="0.01" min="0" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="available_seats">Available Seats</label>
                            <input type="number" id="available_seats" name="available_seats" min="1" class="form-control" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_flight" class="btn btn-primary">Add Flight</button>
                </form>
            </div>
            
            <h2>All Flights</h2>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Flight #</th>
                            <th>Airline</th>
                            <th>Route</th>
                            <th>Departure</th>
                            <th>Arrival</th>
                            <th>Economy</th>
                            <th>Business</th>
                            <th>First</th>
                            <th>Seats</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($flights) > 0): ?>
                            <?php foreach ($flights as $flight): ?>
                                <tr>
                                    <td>#<?php echo $flight['flight_id']; ?></td>
                                    <td><?php echo htmlspecialchars($flight['flight_number']); ?></td>
                                    <td><?php echo htmlspecialchars($flight['airline']); ?></td>
                                    <td><?php echo htmlspecialchars($flight['departure_city'] . ' → ' . $flight['arrival_city']); ?></td>
                                    <td><?php echo date('M d, Y H:i', strtotime($flight['departure_time'])); ?></td>
                                    <td><?php echo date('M d, Y H:i', strtotime($flight['arrival_time'])); ?></td>
                                    <td>₹<?php echo number_format($flight['economy_price'], 2); ?></td>
                                    <td>₹<?php echo number_format($flight['business_price'], 2); ?></td>
                                    <td>₹<?php echo number_format($flight['first_price'], 2); ?></td>
                                    <td><?php echo $flight['available_seats']; ?></td>
                                    <td>
                                        <a href="?delete=<?php echo $flight['flight_id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this flight?')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center">No flights found</td>
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

            <script>
                // Auto-calculate business and first class prices based on economy price
                document.getElementById('economy_price').addEventListener('input', function() {
                    const economyPrice = parseFloat(this.value) || 0;
                    document.getElementById('business_price').value = (economyPrice * 1.8).toFixed(2);
                    document.getElementById('first_price').value = (economyPrice * 2.5).toFixed(2);
                });
                
                // Price range slider
                const priceSlider = document.getElementById('price-range');
                const priceValue = document.getElementById('price-value');
                
                if (priceSlider && priceValue) {
                    priceSlider.addEventListener('input', function() {
                        priceValue.textContent = '$' + this.value;
                    });
                }
                
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
                window.selectedOutbound = '<?php echo $_GET['selected_outbound']; ?>';
                document.querySelector(`.flight-card[data-flight-id="${window.selectedOutbound}"]`)?.classList.add('selected');
                <?php endif; ?>
                
                <?php if (!empty($return_flights) && isset($_GET['selected_return'])): ?>
                window.selectedReturn = '<?php echo $_GET['selected_return']; ?>';
                document.querySelector(`.flight-card[data-flight-id="${window.selectedReturn}"]`)?.classList.add('selected');
                <?php endif; ?>
            </script>
</body>
</html>
       