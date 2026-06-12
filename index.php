<?php
require_once 'includes/functions.php';
$page_title = "Home";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SkyConnect - Book Flights Online</title>
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
            <li><a href="index.php" class="active">Home</a></li>
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
<div class="hero-container">
    <div class="hero">
        <div class="hero-content">
            <h1>Travel the world with SkyConnect</h1>
            <p>Book flights to hundreds of destinations worldwide at the best prices. Your journey begins with us.</p>
            <div class="hero-buttons">
                <a href="flights.php" class="btn">Search Flights</a>
                <a href="#" class="btn btn-outline">View Deals</a>
            </div>
        </div>
    </div>
    <div class="hero-image">
        <img src="assets/images/plane-wing.jpg" alt="Airplane wing view with clouds and sunset">
    </div>
</div>

<div class="search-container">
  <form action="flights.php" method="get">
    <div class="trip-type">
      <label><input type="radio" name="trip_type" value="round" checked> Round Trip</label>
      <label><input type="radio" name="trip_type" value="oneway"> One Way</label>
      <label><input type="radio" name="trip_type" value="multi"> Multi-City</label>
      
      <div class="trip-options">
        <select name="class" class="form-control">
          <option value="economy">Economy</option>
          <option value="business">Business</option>
          <option value="first">First</option>
        </select>
        
        <select name="passengers" class="form-control">
          <option value="1">1 Passenger</option>
          <option value="2">2 Passengers</option>
          <option value="3">3 Passengers</option>
          <option value="4">4 Passengers</option>
          <option value="5">5 Passengers</option>
        </select>
      </div>
    </div>
    
    <div class="search-fields">
      <div class="form-group">
        <label>From</label>
        <input type="text" name="from" placeholder="City or Airport" class="form-control" required>
      </div>
      
      <div class="form-group">
        <label>To</label>
        <input type="text" name="to" placeholder="City or Airport" class="form-control" required>
      </div>
      
      <div class="form-group">
        <label>Departure</label>
        <input type="date" name="departure_date" class="form-control" required>
      </div>
      
      <div class="form-group return-date">
        <label>Return</label>
        <input type="date" name="return_date" class="form-control">
      </div>
    </div>
    
    <button type="submit" class="btn btn-block">Search Flights</button>
  </form>
</div>


    <section class="features">
        <div class="container">
            <div class="feature-card">
                <div class="feature-icon">✈️</div>
                <h3>Global Destinations</h3>
                <p>Fly to over 500 destinations worldwide with our extensive network of airline partners.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">💰</div>
                <h3>Best Price Guarantee</h3>
                <p>Find a lower price elsewhere? We'll match it and give you extra discount.</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">🛡️</div>
                <h3>Secure Booking</h3>
                <p>Book with confidence knowing your personal and payment information is secure.</p>
            </div>
        </div>
    </section>

    <footer class="site-footer">
  <div class="footer-top">
    <div class="container">
      <div class="footer-grid">
        <div class="footer-brand">
          <div class="footer-logo">
            <img src="assets/images/logo.png" alt="SkyConnect Logo">
            <h3>SkyConnect</h3>
          </div>
          <p class="footer-tagline">Your journey begins with us</p>
          <div class="social-links">
            <a href="#" aria-label="Facebook"><i class="fa fa-facebook"></i></a>
            <a href="#" aria-label="Twitter"><i class="fa fa-twitter"></i></a>
            <a href="#" aria-label="Instagram"><i class="fa fa-instagram"></i></a>
            <a href="#" aria-label="LinkedIn"><i class="fa fa-linkedin"></i></a>
          </div>
        </div>
        
        <div class="footer-links">
          <h4>Quick Links</h4>
          <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="flights.php">Search Flights</a></li>
            <li><a href="booking_history.php">My Bookings</a></li>
            <li><a href="#">Travel Deals</a></li>
          </ul>
        </div>
        
        <div class="footer-links">
          <h4>Support</h4>
          <ul>
            <li><a href="#">Help Center</a></li>
            <li><a href="#">Contact Us</a></li>
            <li><a href="#">FAQs</a></li>
            <li><a href="#">Baggage Info</a></li>
          </ul>
        </div>
        
        <div class="footer-links">
          <h4>Company</h4>
          <ul>
            <li><a href="#">About Us</a></li>
            <li><a href="#">Careers</a></li>
            <li><a href="#">Partners</a></li>
            <li><a href="#">News</a></li>
          </ul>
        </div>
        
        <div class="footer-newsletter">
          <h4>Subscribe to Our Newsletter</h4>
          <p>Get the latest deals and travel updates</p>
          <form class="newsletter-form">
            <input type="email" placeholder="Your email address" required>
            <button type="submit" class="btn-subscribe">Subscribe</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  
  <div class="footer-bottom">
    <div class="container">
      <div class="footer-bottom-content">
        <p class="copyright">&copy; 2025 SkyConnect. All rights reserved.</p>
        <div class="footer-legal">
          <a href="#">Privacy Policy</a>
          <a href="#">Terms of Service</a>
          <a href="#">Cookie Policy</a>
        </div>
      </div>
    </div>
  </div>
</footer>

    <script>
        // Show/hide return date based on trip type
        document.querySelectorAll('input[name="trip_type"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.querySelector('.return-date').style.display = 
                    (this.value === 'round') ? 'block' : 'none';
            });
        });
    </script>
</body>
</html>
