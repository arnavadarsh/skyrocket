<?php
require_once '../includes/functions.php';
requireAdmin();

$page_title = "Manage Employees";

$db = getDB();

$employee_roles = ['pilot', 'cabin_crew', 'ground', 'security'];
$shifts = ['morning', 'evening', 'night'];

function roleLabel($role) {
    return ucwords(str_replace('_', ' ', $role));
}

function validateEmployeeInput(&$error_message) {
    global $employee_roles, $shifts;
    $name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? '';
    $contact = trim($_POST['contact_info'] ?? '');
    $shift = $_POST['shift'] ?? '';

    if ($name === '' || mb_strlen($name) > 100) {
        $error_message = "Please enter a full name (max 100 characters).";
        return null;
    }
    if (!in_array($role, $employee_roles, true)) {
        $error_message = "Invalid employee role.";
        return null;
    }
    if (mb_strlen($contact) > 100) {
        $error_message = "Contact info is too long (max 100 characters).";
        return null;
    }
    if (!in_array($shift, $shifts, true)) {
        $error_message = "Invalid shift.";
        return null;
    }
    return [$name, $role, $contact === '' ? null : $contact, $shift];
}

// Add employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    csrf_verify();
    if ($input = validateEmployeeInput($error_message)) {
        $stmt = $db->prepare("INSERT INTO employees (full_name, role, contact_info, shift) VALUES (?, ?, ?, ?)");
        $stmt->execute($input);
        $success_message = "Employee has been added.";
    }
}

// Update employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_employee']) && is_numeric($_POST['employee_id'] ?? '')) {
    csrf_verify();
    if ($input = validateEmployeeInput($error_message)) {
        $stmt = $db->prepare("UPDATE employees SET full_name = ?, role = ?, contact_info = ?, shift = ? WHERE employee_id = ?");
        $stmt->execute(array_merge($input, [$_POST['employee_id']]));
        $success_message = "Employee #" . (int)$_POST['employee_id'] . " has been updated.";
    }
}

// Delete employee (assignments cascade away with them)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_employee']) && is_numeric($_POST['delete_employee'])) {
    csrf_verify();
    try {
        $stmt = $db->prepare("DELETE FROM employees WHERE employee_id = ?");
        $stmt->execute([$_POST['delete_employee']]);
        $success_message = "Employee has been deleted (their flight assignments were removed).";
    } catch (PDOException $e) {
        $error_message = "Error deleting employee: " . $e->getMessage();
    }
}

// All employees with their current assignment count
$stmt = $db->query("
    SELECT em.*, COUNT(efa.assignment_id) AS assignment_count
    FROM employees em
    LEFT JOIN employee_flight_assignment efa ON efa.employee_id = em.employee_id
    GROUP BY em.employee_id
    ORDER BY em.employee_id
");
$employee_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                <li><a href="flights.php">Flights</a></li>
                <li><a href="aircraft.php">Aircraft</a></li>
                <li><a href="employees.php" class="active">Employees</a></li>
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
            <h1>Manage Employees</h1>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo e($success_message); ?></div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo e($error_message); ?></div>
            <?php endif; ?>

            <div class="admin-card">
                <h2>Add New Employee</h2>
                <form action="" method="post">
                    <?php echo csrf_field(); ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" maxlength="100" required>
                        </div>
                        <div class="form-group">
                            <label for="role">Role</label>
                            <select id="role" name="role" class="form-control">
                                <?php foreach ($employee_roles as $r): ?>
                                    <option value="<?php echo $r; ?>"><?php echo roleLabel($r); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contact_info">Contact Info (optional)</label>
                            <input type="text" id="contact_info" name="contact_info" class="form-control" maxlength="100">
                        </div>
                        <div class="form-group">
                            <label for="shift">Shift</label>
                            <select id="shift" name="shift" class="form-control">
                                <?php foreach ($shifts as $sh): ?>
                                    <option value="<?php echo $sh; ?>"><?php echo ucfirst($sh); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="add_employee" class="btn btn-primary">Add Employee</button>
                </form>
            </div>

            <h2>Roster</h2>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Contact</th>
                            <th>Shift</th>
                            <th>Assigned Flights</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($employee_list) > 0): ?>
                            <?php foreach ($employee_list as $em): ?>
                                <tr>
                                    <td>#<?php echo $em['employee_id']; ?></td>
                                    <form action="" method="post" id="edit-employee-<?php echo $em['employee_id']; ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="employee_id" value="<?php echo $em['employee_id']; ?>">
                                    </form>
                                    <td>
                                        <input type="text" name="full_name" maxlength="100" required class="form-control"
                                               value="<?php echo e($em['full_name']); ?>" form="edit-employee-<?php echo $em['employee_id']; ?>">
                                    </td>
                                    <td>
                                        <select name="role" class="form-control status-select" form="edit-employee-<?php echo $em['employee_id']; ?>">
                                            <?php foreach ($employee_roles as $r): ?>
                                                <option value="<?php echo $r; ?>" <?php echo $em['role'] === $r ? 'selected' : ''; ?>><?php echo roleLabel($r); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="contact_info" maxlength="100" class="form-control"
                                               value="<?php echo e($em['contact_info']); ?>" form="edit-employee-<?php echo $em['employee_id']; ?>">
                                    </td>
                                    <td>
                                        <select name="shift" class="form-control status-select" form="edit-employee-<?php echo $em['employee_id']; ?>">
                                            <?php foreach ($shifts as $sh): ?>
                                                <option value="<?php echo $sh; ?>" <?php echo $em['shift'] === $sh ? 'selected' : ''; ?>><?php echo ucfirst($sh); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><?php echo $em['assignment_count']; ?></td>
                                    <td>
                                        <button type="submit" name="update_employee" value="1" class="btn btn-sm"
                                                form="edit-employee-<?php echo $em['employee_id']; ?>">Update</button>
                                        <form action="" method="post" onsubmit="return confirm('Delete this employee? Their flight assignments will be removed.')" style="display:inline">
                                            <?php echo csrf_field(); ?>
                                            <button type="submit" name="delete_employee" value="<?php echo $em['employee_id']; ?>" class="btn btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">No employees yet — add one above.</td>
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
