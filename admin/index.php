<?php
require_once '../includes/functions.php';
requireAdmin();

$page_title = "Admin Dashboard";

// Get counts for dashboard
$db = getDB();

$stmt = $db->query("SELECT COUNT(*) FROM bookings");
$bookings_count = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM users");
$users_count = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM flights");
$flights_count = $stmt->fetchColumn();

// Revenue = sum of completed payments (refunded payments don't count)
$stmt = $db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'");
$total_revenue = $stmt->fetchColumn();

// Bookings awaiting payment
$stmt = $db->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'");
$pending_payments = $stmt->fetchColumn();
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
                <li><a href="index.php" class="active">Dashboard</a></li>
                <li><a href="bookings.php">Bookings</a></li>
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
            <h1>Admin Dashboard</h1>
            
            <div class="stats-container">
                <div class="stat-card">
                    <h3>Total Bookings</h3>
                    <div class="stat-number"><?php echo $bookings_count; ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Total Users</h3>
                    <div class="stat-number"><?php echo $users_count; ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Total Flights</h3>
                    <div class="stat-number"><?php echo $flights_count; ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Total Revenue</h3>
                    <div class="stat-number">₹<?php echo number_format($total_revenue, 2); ?></div>
                </div>

                <div class="stat-card">
                    <h3>Pending Payments</h3>
                    <div class="stat-number"><?php echo $pending_payments; ?></div>
                </div>
            </div>
            
            <div class="admin-links">
                <a href="bookings.php" class="admin-link-card">
                    <h3>Manage Bookings</h3>
                    <p>View and manage all flight bookings</p>
                </a>
                
                <a href="users.php" class="admin-link-card">
                    <h3>Manage Users</h3>
                    <p>View and manage user accounts</p>
                </a>
                
                <a href="flights.php" class="admin-link-card">
                    <h3>Manage Flights</h3>
                    <p>View and manage available flights with pricing tiers</p>
                </a>
                
                <a href="custom_query.php" class="admin-link-card">
                    <h3>Custom SQL Query</h3>
                    <p>Execute custom SQL queries on the database</p>
                </a>
            </div>
            
            <div class="admin-card">
                <h2>Recent Bookings</h2>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Flight</th>
                                <th>Class</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Price</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Updated query to include class information from bookings
                            $stmt = $db->query("
                                SELECT b.booking_id, u.username, f.flight_number, b.booking_date, b.total_price, b.status,
                                       CASE
                                           WHEN b.class = 'economy' THEN 'Economy'
                                           WHEN b.class = 'business' THEN 'Business'
                                           WHEN b.class = 'first' THEN 'First'
                                           ELSE 'Economy'
                                       END as class_type
                                FROM bookings b
                                JOIN users u ON b.user_id = u.user_id
                                JOIN flights f ON b.flight_id = f.flight_id
                                ORDER BY b.booking_date DESC
                                LIMIT 5
                            ");
                            $recent_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($recent_bookings) > 0):
                                foreach ($recent_bookings as $booking):
                            ?>
                                <tr>
                                    <td>#<?php echo $booking['booking_id']; ?></td>
                                    <td><?php echo htmlspecialchars($booking['username']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['flight_number']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['class_type'] ?? 'Economy'); ?></td>
                                    <td><?php echo $booking['status'] === 'cancelled' ? '<span class="status-badge status-cancelled">Cancelled</span>' : '<span class="status-badge status-scheduled">Confirmed</span>'; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></td>
                                    <td>₹<?php echo number_format($booking['total_price'], 2); ?></td>
                                    <td><a href="bookings.php?id=<?php echo $booking['booking_id']; ?>" class="btn btn-sm">View</a></td>
                                </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                                <tr>
                                    <td colspan="8" class="text-center">No bookings found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
</body>
</html>
