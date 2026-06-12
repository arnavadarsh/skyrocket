<?php
require_once '../includes/functions.php';
requireAdmin();
$page_title = "Manage Users";

$db = getDB();

// Handle user deletion (POST + CSRF; GET must never mutate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && is_numeric($_POST['delete_user'])) {
    csrf_verify();
    $user_id = $_POST['delete_user'];
    
    // Don't allow deleting yourself
    if ($user_id == $_SESSION['user_id']) {
        $error_message = "You cannot delete your own account.";
    } else {
        try {
            // Check if user has bookings
            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $has_bookings = $stmt->fetchColumn() > 0;
            
            if ($has_bookings) {
                $error_message = "Cannot delete user with existing bookings. Delete their bookings first.";
            } else {
                $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $success_message = "User has been deleted successfully.";
            }
        } catch (Exception $e) {
            $error_message = "Error deleting user: " . $e->getMessage();
        }
    }
}

// Handle role change (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role']) && is_numeric($_POST['user_id'] ?? '')) {
    csrf_verify();
    $user_id = $_POST['user_id'];
    $new_role = $_POST['role'] ?? '';

    // Changing your own role could lock you out of this page mid-session
    if ($user_id == $_SESSION['user_id']) {
        $error_message = "You cannot change your own role while logged in.";
    } elseif (!in_array($new_role, ['user', 'staff', 'admin'], true)) {
        $error_message = "Invalid role.";
    } else {
        try {
            $stmt = $db->prepare("UPDATE users SET role = ? WHERE user_id = ?");
            $stmt->execute([$new_role, $user_id]);
            $success_message = "User role updated to " . $new_role . ".";
        } catch (Exception $e) {
            $error_message = "Error updating user: " . $e->getMessage();
        }
    }
}

// Get all users
$stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                <li><a href="index.php">Admin Dashboard</a></li>
                <li><a href="bookings.php">Bookings</a></li>
                <li><a href="users.php" class="active">Users</a></li>
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
            <h1>Manage Users</h1>
            
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo e($success_message); ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo e($error_message); ?></div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($users) > 0): ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>#<?php echo $user['user_id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php if ($user['role'] === 'admin'): ?>
                                            <span class="badge badge-admin">Admin</span>
                                        <?php elseif ($user['role'] === 'staff'): ?>
                                            <span class="badge badge-admin">Staff</span>
                                        <?php else: ?>
                                            <span class="badge badge-user">User</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <form action="" method="post" class="status-form" style="display:inline-flex">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                <select name="role" class="form-control status-select">
                                                    <?php foreach (['user', 'staff', 'admin'] as $r): ?>
                                                        <option value="<?php echo $r; ?>" <?php echo $user['role'] === $r ? 'selected' : ''; ?>><?php echo ucfirst($r); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="update_role" value="1" class="btn btn-sm">Update</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <form action="" method="post" onsubmit="return confirm('Are you sure you want to delete this user?')" style="display:inline">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" name="delete_user" value="<?php echo $user['user_id']; ?>" class="btn btn-danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">No users found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    
    <footer>
        <div class="footer-content">
            <p>&copy; 2025 SkyConnect. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
