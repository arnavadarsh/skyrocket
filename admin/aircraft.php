<?php
require_once '../includes/functions.php';
requireAdmin();

$page_title = "Manage Aircraft";

$db = getDB();

$maintenance_statuses = ['active', 'maintenance', 'retired'];

function validateAircraftInput(&$error_message) {
    global $maintenance_statuses;
    $model = trim($_POST['model'] ?? '');
    $capacity = $_POST['capacity'] ?? '';
    $status = $_POST['maintenance_status'] ?? '';

    if ($model === '' || mb_strlen($model) > 50) {
        $error_message = "Please enter a model name (max 50 characters).";
        return null;
    }
    if (!ctype_digit((string)$capacity) || (int)$capacity < 1) {
        $error_message = "Capacity must be a whole number greater than 0.";
        return null;
    }
    if (!in_array($status, $maintenance_statuses, true)) {
        $error_message = "Invalid maintenance status.";
        return null;
    }
    return [$model, (int)$capacity, $status];
}

// Add aircraft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_aircraft'])) {
    csrf_verify();
    if ($input = validateAircraftInput($error_message)) {
        $stmt = $db->prepare("INSERT INTO aircraft (model, capacity, maintenance_status) VALUES (?, ?, ?)");
        $stmt->execute($input);
        $success_message = "Aircraft has been added.";
    }
}

// Update aircraft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_aircraft']) && is_numeric($_POST['aircraft_id'] ?? '')) {
    csrf_verify();
    if ($input = validateAircraftInput($error_message)) {
        // Cross-table rule (PHP-validated, CHECKs can't span tables):
        // capacity must still cover every flight already using this aircraft
        $stmt = $db->prepare("SELECT MAX(available_seats) FROM flights WHERE aircraft_id = ?");
        $stmt->execute([$_POST['aircraft_id']]);
        $max_seats = (int)$stmt->fetchColumn();

        if ($input[1] < $max_seats) {
            $error_message = "Capacity cannot be set below $max_seats: a flight using this aircraft has that many seats.";
        } else {
            $stmt = $db->prepare("UPDATE aircraft SET model = ?, capacity = ?, maintenance_status = ? WHERE aircraft_id = ?");
            $stmt->execute(array_merge($input, [$_POST['aircraft_id']]));
            $success_message = "Aircraft #" . (int)$_POST['aircraft_id'] . " has been updated.";
        }
    }
}

// Delete aircraft (FK RESTRICT on flights.aircraft_id backs this up)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_aircraft']) && is_numeric($_POST['delete_aircraft'])) {
    csrf_verify();
    try {
        $stmt = $db->prepare("DELETE FROM aircraft WHERE aircraft_id = ?");
        $stmt->execute([$_POST['delete_aircraft']]);
        $success_message = "Aircraft has been deleted.";
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
            $error_message = "This aircraft is assigned to one or more flights — reassign its flights first.";
        } else {
            $error_message = "Error deleting aircraft: " . $e->getMessage();
        }
    }
}

// All aircraft with the number of flights using each
$stmt = $db->query("
    SELECT a.*, COUNT(f.flight_id) AS flight_count
    FROM aircraft a
    LEFT JOIN flights f ON f.aircraft_id = a.aircraft_id
    GROUP BY a.aircraft_id
    ORDER BY a.aircraft_id
");
$aircraft_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                <li><a href="aircraft.php" class="active">Aircraft</a></li>
                <li><a href="employees.php">Employees</a></li>
                <li><a href="reports.php">Reports</a></li>
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
            <h1>Manage Aircraft</h1>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo e($success_message); ?></div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo e($error_message); ?></div>
            <?php endif; ?>

            <div class="admin-card">
                <h2>Add New Aircraft</h2>
                <form action="" method="post">
                    <?php echo csrf_field(); ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="model">Model</label>
                            <input type="text" id="model" name="model" class="form-control" maxlength="50" required>
                        </div>
                        <div class="form-group">
                            <label for="capacity">Capacity</label>
                            <input type="number" id="capacity" name="capacity" min="1" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="maintenance_status">Status</label>
                            <select id="maintenance_status" name="maintenance_status" class="form-control">
                                <?php foreach ($maintenance_statuses as $st): ?>
                                    <option value="<?php echo $st; ?>"><?php echo ucfirst($st); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="add_aircraft" class="btn btn-primary">Add Aircraft</button>
                </form>
            </div>

            <h2>Fleet</h2>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Model</th>
                            <th>Capacity</th>
                            <th>Status</th>
                            <th>Flights</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($aircraft_list) > 0): ?>
                            <?php foreach ($aircraft_list as $a): ?>
                                <tr>
                                    <td>#<?php echo $a['aircraft_id']; ?></td>
                                    <form action="" method="post" id="edit-aircraft-<?php echo $a['aircraft_id']; ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="aircraft_id" value="<?php echo $a['aircraft_id']; ?>">
                                    </form>
                                    <td>
                                        <input type="text" name="model" maxlength="50" required class="form-control"
                                               value="<?php echo e($a['model']); ?>" form="edit-aircraft-<?php echo $a['aircraft_id']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" name="capacity" min="1" required class="form-control" style="width: 90px"
                                               value="<?php echo $a['capacity']; ?>" form="edit-aircraft-<?php echo $a['aircraft_id']; ?>">
                                    </td>
                                    <td>
                                        <select name="maintenance_status" class="form-control status-select" form="edit-aircraft-<?php echo $a['aircraft_id']; ?>">
                                            <?php foreach ($maintenance_statuses as $st): ?>
                                                <option value="<?php echo $st; ?>" <?php echo $a['maintenance_status'] === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><?php echo $a['flight_count']; ?></td>
                                    <td>
                                        <button type="submit" name="update_aircraft" value="1" class="btn btn-sm"
                                                form="edit-aircraft-<?php echo $a['aircraft_id']; ?>">Update</button>
                                        <form action="" method="post" onsubmit="return confirm('Delete this aircraft?')" style="display:inline">
                                            <?php echo csrf_field(); ?>
                                            <button type="submit" name="delete_aircraft" value="<?php echo $a['aircraft_id']; ?>" class="btn btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">No aircraft in the fleet yet — add one above.</td>
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
