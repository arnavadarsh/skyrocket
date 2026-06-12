<?php
require_once '../includes/functions.php';
requireAdmin();

$page_title = "Manage Flights";

$db = getDB();

$flight_statuses = ['scheduled', 'delayed', 'boarding', 'departed', 'arrived', 'cancelled'];

// Handle flight deletion (POST + CSRF; GET must never mutate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_flight']) && is_numeric($_POST['delete_flight'])) {
    csrf_verify();
    $flight_id = $_POST['delete_flight'];

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

// Handle flight status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && is_numeric($_POST['flight_id'] ?? '')) {
    csrf_verify();
    $status = $_POST['status'] ?? '';

    if (!in_array($status, $flight_statuses, true)) {
        $error_message = "Invalid flight status.";
    } else {
        try {
            $stmt = $db->prepare("UPDATE flights SET status = ? WHERE flight_id = ?");
            $stmt->execute([$status, $_POST['flight_id']]);
            $success_message = "Flight #" . (int)$_POST['flight_id'] . " status set to " . $status . ".";
        } catch (Exception $e) {
            $error_message = "Error updating status: " . $e->getMessage();
        }
    }
}

// Handle flight addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_flight'])) {
    csrf_verify();
    $flight_number = $_POST['flight_number'];
    $airline = $_POST['airline'];
    $departure_city = $_POST['departure_city'];
    $arrival_city = $_POST['arrival_city'];
    $departure_time = $_POST['departure_time'];
    $arrival_time = $_POST['arrival_time'];
    $economy_price = $_POST['economy_price'];
    $business_price = $_POST['business_price'];
    $first_price = $_POST['first_price'];
    $available_seats = trim($_POST['available_seats'] ?? '');
    $aircraft_id = $_POST['aircraft_id'] ?? '';
    $gate_id = $_POST['gate_id'] ?? '';

    $aircraft = null;
    if ($aircraft_id !== '') {
        // Only active aircraft can be assigned
        $stmt = $db->prepare("SELECT * FROM aircraft WHERE aircraft_id = ? AND maintenance_status = 'active'");
        $stmt->execute([$aircraft_id]);
        $aircraft = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $gate_ok = true;
    if ($gate_id !== '') {
        // Only open gates can be assigned
        $stmt = $db->prepare("SELECT COUNT(*) FROM gates WHERE gate_id = ? AND status = 'open'");
        $stmt->execute([$gate_id]);
        $gate_ok = $stmt->fetchColumn() > 0;
    }

    if ($aircraft_id !== '' && !$aircraft) {
        $error_message = "Please choose an active aircraft.";
    } elseif (!$gate_ok) {
        $error_message = "Please choose an open gate.";
    } elseif ($available_seats === '' && !$aircraft) {
        $error_message = "Enter the available seats or choose an aircraft to default to its capacity.";
    } elseif ($available_seats !== '' && (!ctype_digit($available_seats) || (int)$available_seats < 1)) {
        $error_message = "Available seats must be a whole number greater than 0.";
    } elseif ($aircraft && $available_seats !== '' && (int)$available_seats > $aircraft['capacity']) {
        $error_message = "Available seats (" . (int)$available_seats . ") cannot exceed the " . $aircraft['model'] . "'s capacity of " . $aircraft['capacity'] . "."; // template escapes via e()
    } else {
        // Seats left blank with an aircraft chosen: default to its capacity
        $seats = ($available_seats === '') ? $aircraft['capacity'] : (int)$available_seats;

        try {
            $stmt = $db->prepare("INSERT INTO flights (flight_number, airline, departure_city, arrival_city, departure_time, arrival_time, economy_price, business_price, first_price, available_seats, aircraft_id, gate_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$flight_number, $airline, $departure_city, $arrival_city, $departure_time, $arrival_time, $economy_price, $business_price, $first_price, $seats,
                            $aircraft_id === '' ? null : $aircraft_id,
                            $gate_id === '' ? null : $gate_id]);
            $success_message = "Flight has been added successfully.";
        } catch (Exception $e) {
            $error_message = "Error adding flight: " . $e->getMessage();
        }
    }
}

// Options for the add-flight form
$active_aircraft = $db->query("SELECT aircraft_id, model, capacity FROM aircraft WHERE maintenance_status = 'active' ORDER BY model, aircraft_id")->fetchAll(PDO::FETCH_ASSOC);
$open_gates = $db->query("SELECT gate_id, terminal, gate_number FROM gates WHERE status = 'open' ORDER BY terminal, CAST(gate_number AS UNSIGNED)")->fetchAll(PDO::FETCH_ASSOC);

// Get all flights
$stmt = $db->query("
    SELECT f.*, a.model AS aircraft_model, g.terminal, g.gate_number
    FROM flights f
    LEFT JOIN aircraft a ON f.aircraft_id = a.aircraft_id
    LEFT JOIN gates g ON f.gate_id = g.gate_id
    ORDER BY f.departure_time
");
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
            <h1>Manage Flights</h1>
            
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo e($success_message); ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo e($error_message); ?></div>
            <?php endif; ?>
            
            <div class="admin-card">
                <h2>Add New Flight</h2>
                <form action="" method="post">
                    <?php echo csrf_field(); ?>
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
                            <label for="aircraft_id">Aircraft</label>
                            <select id="aircraft_id" name="aircraft_id" class="form-control">
                                <option value="">&mdash; none &mdash;</option>
                                <?php foreach ($active_aircraft as $a): ?>
                                    <option value="<?php echo $a['aircraft_id']; ?>">#<?php echo $a['aircraft_id']; ?> <?php echo e($a['model']); ?> (<?php echo $a['capacity']; ?> seats)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="gate_id">Gate</label>
                            <select id="gate_id" name="gate_id" class="form-control">
                                <option value="">&mdash; none &mdash;</option>
                                <?php foreach ($open_gates as $g): ?>
                                    <option value="<?php echo $g['gate_id']; ?>"><?php echo e($g['terminal'] . '-' . $g['gate_number']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="available_seats">Available Seats</label>
                            <input type="number" id="available_seats" name="available_seats" min="1" class="form-control" placeholder="blank = aircraft capacity">
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
                            <th>Aircraft</th>
                            <th>Gate</th>
                            <th>Status</th>
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
                                    <td><?php echo $flight['aircraft_model'] ? e($flight['aircraft_model']) : '&mdash;'; ?></td>
                                    <td><?php echo $flight['gate_id'] ? e($flight['terminal'] . '-' . $flight['gate_number']) : '&mdash;'; ?></td>
                                    <td>
                                        <form action="" method="post" class="status-form">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="flight_id" value="<?php echo $flight['flight_id']; ?>">
                                            <select name="status" class="form-control status-select">
                                                <?php foreach ($flight_statuses as $st): ?>
                                                    <option value="<?php echo $st; ?>" <?php echo $flight['status'] === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="update_status" value="1" class="btn btn-sm">Update</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form action="" method="post" onsubmit="return confirm('Are you sure you want to delete this flight?')" style="display:inline">
                                            <?php echo csrf_field(); ?>
                                            <button type="submit" name="delete_flight" value="<?php echo $flight['flight_id']; ?>" class="btn btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="14" class="text-center">No flights found</td>
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
                
            </script>
</body>
</html>
       