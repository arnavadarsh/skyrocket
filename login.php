<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';
$page_title = "Login";

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: index.php");
    exit;
}

$error = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Hard-coded admin check
    if ($username === 'admin' && $password === 'admin1') {
        // Set admin session variables
        $_SESSION['user_id'] = 1; // Or any ID you want to assign
        $_SESSION['username'] = 'admin';
        $_SESSION['first_name'] = 'Admin';
        $_SESSION['last_name'] = 'User';
        $_SESSION['is_admin'] = 1;
        // Redirect to admin dashboard or home page
        header("Location: index.php");
        exit;
    }
    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['is_admin'] = $user['is_admin'];
            
            // Redirect to home page
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid username or password.";
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
    <style>
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header img {
            height: 40px;
            margin-bottom: 15px;
        }
        .login-form .form-group {
            margin-bottom: 20px;
        }
        .login-form .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .login-form .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }
        .login-form .btn {
            width: 100%;
            padding: 12px;
            background-color: #3478f6;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
        }
        .login-form .btn:hover {
            background-color: #2a62c7;
        }
        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }
        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
                <?php if (isLoggedIn()): ?>
                <li><a href="booking_history.php">Bookings</a></li>
                <?php endif; ?>
                <?php if (isLoggedIn() && isAdmin()): ?>
                <li><a href="admin/index.php">Employee Portal</a></li>
                <?php endif; ?>
            </ul>
            <div class="auth-links">
                <a href="login.php" class="active">Login</a>
                <a href="register.php">Register</a>
            </div>
        </nav>
    </header>
    
    <main>
        <div class="login-container">
            <div class="login-header">
                <img src="assets/images/logo.png" alt="SkyConnect Logo">
                <h2>Login to SkyConnect</h2>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="post" class="login-form">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" name="username" id="username" class="form-control" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>
                <button type="submit" class="btn">Login</button>
            </form>
            
            <div class="login-footer">
                <p>Don't have an account? <a href="register.php">Register here</a></p>
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
</body>
</html>
