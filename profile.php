<?php
require_once 'includes/functions.php';
requireLogin();

$user = getUser($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - SkyConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header>
        <!-- (reuse your header/nav code here) -->
    </header>
    <main>
        <div class="form-container">
            <h2 class="form-title">My Profile</h2>
            <div class="profile-info">
                <p><strong>First Name:</strong> <?php echo htmlspecialchars($user['first_name']); ?></p>
                <p><strong>Last Name:</strong> <?php echo htmlspecialchars($user['last_name']); ?></p>
                <p><strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                <p><strong>Role:</strong> <?php echo $user['is_admin'] ? 'Admin' : 'User'; ?></p>
                <p><strong>Member Since:</strong> <?php echo date('M d, Y', strtotime($user['created_at'])); ?></p>
            </div>
            <div class="form-footer">
                <a href="booking_history.php" class="btn">My Bookings</a>
                <a href="logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </main>
</body>
</html>
