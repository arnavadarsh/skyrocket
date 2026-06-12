<?php
require_once '../includes/functions.php';
requireStaff();

$page_title = "Staff Dashboard";

$db = getDB();

$flight_statuses = ['scheduled', 'delayed', 'boarding', 'departed', 'arrived', 'cancelled'];

// Update flight status (same pattern as admin/flights.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && is_numeric($_POST['flight_id'] ?? '')) {
    csrf_verify();
    $status = $_POST['status'] ?? '';

    if (!in_array($status, $flight_statuses, true)) {
        $error_message = "Invalid flight status.";
    } else {
        $stmt = $db->prepare("UPDATE flights SET status = ? WHERE flight_id = ?");
        $stmt->execute([$status, $_POST['flight_id']]);
        $success_message = "Flight #" . (int)$_POST['flight_id'] . " status set to " . $status . ".";
    }
}

// Assign / clear gate. Closed gates are not offered in the select AND
// are rejected here in case someone forces a gate_id via POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_gate']) && is_numeric($_POST['flight_id'] ?? '')) {
    csrf_verify();
    $gate_id = $_POST['gate_id'] ?? '';

    if ($gate_id === '') {
        $stmt = $db->prepare("UPDATE flights SET gate_id = NULL WHERE flight_id = ?");
        $stmt->execute([$_POST['flight_id']]);
        $success_message = "Gate cleared for flight #" . (int)$_POST['flight_id'] . ".";
    } elseif (!is_numeric($gate_id)) {
        $error_message = "Invalid gate.";
    } else {
        $stmt = $db->prepare("SELECT terminal, gate_number FROM gates WHERE gate_id = ? AND status = 'open'");
        $stmt->execute([$gate_id]);
        $gate = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$gate) {
            $error_message = "That gate is closed or does not exist — choose an open gate.";
        } else {
            $stmt = $db->prepare("UPDATE flights SET gate_id = ? WHERE flight_id = ?");
            $stmt->execute([$gate_id, $_POST['flight_id']]);
            $success_message = "Flight #" . (int)$_POST['flight_id'] . " assigned to gate " . $gate['terminal'] . "-" . $gate['gate_number'] . ".";
        }
    }
}

// Departures board: next 48 hours, plus today's flights that are not
// in a final state yet (late status updates still need doing)
$stmt = $db->query("
    SELECT f.*, a.model AS aircraft_model, g.terminal, g.gate_number,
           (SELECT COUNT(*) FROM tickets t WHERE t.flight_id = f.flight_id AND t.status IN ('confirmed','checked_in')) AS ticket_count
    FROM flights f
    LEFT JOIN aircraft a ON f.aircraft_id = a.aircraft_id
    LEFT JOIN gates g ON f.gate_id = g.gate_id
    WHERE (f.departure_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR))
       OR (DATE(f.departure_time) = CURDATE() AND f.status NOT IN ('arrived','cancelled'))
    ORDER BY f.departure_time
");
$flights = $stmt->fetchAll(PDO::FETCH_ASSOC);

$open_gates = $db->query("SELECT gate_id, terminal, gate_number FROM gates WHERE status = 'open' ORDER BY terminal, CAST(gate_number AS UNSIGNED)")->fetchAll(PDO::FETCH_ASSOC);
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
                <li><a href="index.php" class="active">Staff Dashboard</a></li>
                <?php if (isAdmin()): ?>
                <li><a href="../admin/index.php">Admin</a></li>
                <?php endif; ?>
            </ul>
            <div class="auth-links">
                <span>Welcome, <?php echo e($_SESSION['first_name']); ?></span>
                <a href="../logout.php">Logout</a>
            </div>
        </nav>
    </header>

    <main>
        <div class="admin-container">
            <h1>Staff Dashboard &mdash; Departures (next 48h)</h1>

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
                            <th>Flight</th>
                            <th>Route</th>
                            <th>Departure</th>
                            <th>Status</th>
                            <th>Gate</th>
                            <th>Aircraft</th>
                            <th>Tickets</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($flights) > 0): ?>
                            <?php foreach ($flights as $flight): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo e($flight['flight_number']); ?></strong><br>
                                        <small><?php echo e($flight['airline']); ?></small>
                                    </td>
                                    <td><?php echo e($flight['departure_city'] . ' → ' . $flight['arrival_city']); ?></td>
                                    <td><?php echo date('D, M d H:i', strtotime($flight['departure_time'])); ?></td>
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
                                        <form action="" method="post" class="status-form">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="flight_id" value="<?php echo $flight['flight_id']; ?>">
                                            <select name="gate_id" class="form-control status-select">
                                                <option value="">&mdash; none &mdash;</option>
                                                <?php foreach ($open_gates as $g): ?>
                                                    <option value="<?php echo $g['gate_id']; ?>" <?php echo $flight['gate_id'] == $g['gate_id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($g['terminal'] . '-' . $g['gate_number']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="assign_gate" value="1" class="btn btn-sm">Assign</button>
                                        </form>
                                    </td>
                                    <td><?php echo $flight['aircraft_model'] ? e($flight['aircraft_model']) : '&mdash;'; ?></td>
                                    <td><?php echo $flight['ticket_count']; ?></td>
                                    <td><a href="flight_detail.php?flight_id=<?php echo $flight['flight_id']; ?>" class="btn btn-sm">Detail</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center">No departures in the next 48 hours.</td>
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
