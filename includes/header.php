<?php require_once 'includes/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' . SITE_NAME : SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php if (isset($extra_css)): ?>
        <?php foreach ($extra_css as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
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
        <?php if (isLoggedIn() && isAdmin()): ?>
        <li><a href="admin/index.php">Employee Portal</a></li>
        <?php endif; ?>
    </ul>
    <div class="auth-links">
        <?php if (isLoggedIn()): ?>
        <span>Welcome, <?php echo $_SESSION['first_name']; ?></span>
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
