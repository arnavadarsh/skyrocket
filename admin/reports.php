<?php
require_once '../includes/functions.php';
requireAdmin();

$page_title = "Reports";

$db = getDB();

// Each report runs exactly the SQL it displays (the <details> block
// shows the same string that is executed — honest for the viva).
// money/percent column lists drive ₹ / % formatting in the renderer.
$reports = [
    [
        'title' => '1. Departures per day (next 7 days)',
        'sql' => "SELECT DATE(departure_time) AS day,
       COUNT(*) AS departures
FROM flights
WHERE departure_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(departure_time)
ORDER BY day",
    ],
    [
        'title' => '2. Top 5 routes by tickets sold',
        'note' => 'RANK() is a window function: ties share a rank (1, 1, 3 ...).',
        'sql' => "SELECT RANK() OVER (ORDER BY COUNT(t.ticket_id) DESC) AS `rank`,
       CONCAT(f.departure_city, ' → ', f.arrival_city) AS route,
       COUNT(t.ticket_id) AS tickets_sold
FROM tickets t
JOIN flights f ON t.flight_id = f.flight_id
WHERE t.status <> 'cancelled'
GROUP BY f.departure_city, f.arrival_city
ORDER BY tickets_sold DESC, route
LIMIT 5",
    ],
    [
        'title' => '3. Revenue by airline',
        'note' => 'From vw_revenue_by_airline — fare snapshots on tickets keep this exact even after price edits.',
        'sql' => "SELECT *
FROM vw_revenue_by_airline
ORDER BY revenue DESC",
        'money' => ['revenue'],
    ],
    [
        'title' => '4. Revenue by month',
        'sql' => "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month,
       COUNT(*) AS payments,
       SUM(amount) AS revenue
FROM payments
WHERE status = 'completed'
GROUP BY DATE_FORMAT(paid_at, '%Y-%m')
ORDER BY month",
        'money' => ['revenue'],
    ],
    [
        'title' => '5a. Nearly-full upcoming flights (occupancy ≥ 80%)',
        'note' => 'Filters on a computed column of vw_flight_occupancy.',
        'sql' => "SELECT flight_number, route, departure_time, status, seats_sold, available_seats, occupancy_pct
FROM vw_flight_occupancy
WHERE departure_time > NOW() AND occupancy_pct >= 80
ORDER BY occupancy_pct DESC",
        'percent' => ['occupancy_pct'],
    ],
    [
        'title' => '5b. Five emptiest upcoming flights',
        'sql' => "SELECT flight_number, route, departure_time, status, seats_sold, available_seats, occupancy_pct
FROM vw_flight_occupancy
WHERE departure_time > NOW()
ORDER BY COALESCE(occupancy_pct, 0) ASC, departure_time
LIMIT 5",
        'percent' => ['occupancy_pct'],
    ],
    [
        'title' => '6. Disruptions by airline',
        'note' => 'Conditional aggregation: SUM over a boolean expression counts matching rows.',
        'sql' => "SELECT airline,
       SUM(status = 'delayed') AS delayed_flights,
       SUM(status = 'cancelled') AS cancelled_flights,
       COUNT(*) AS total_flights
FROM flights
GROUP BY airline
ORDER BY (SUM(status = 'delayed') + SUM(status = 'cancelled')) DESC, airline",
    ],
    [
        'title' => '7. Top 5 customers by spend',
        'sql' => "SELECT u.username,
       CONCAT(u.first_name, ' ', u.last_name) AS name,
       COUNT(DISTINCT b.booking_id) AS bookings,
       COALESCE(SUM(p.amount), 0) AS total_spend
FROM users u
LEFT JOIN bookings b ON b.user_id = u.user_id
LEFT JOIN payments p ON p.booking_id = b.booking_id AND p.status = 'completed'
GROUP BY u.user_id, u.username, u.first_name, u.last_name
ORDER BY total_spend DESC
LIMIT 5",
        'money' => ['total_spend'],
    ],
    [
        'title' => '8. Crew workload',
        'note' => 'LEFT JOIN keeps employees with zero assignments in the result.',
        'sql' => "SELECT em.full_name,
       em.role,
       em.shift,
       COUNT(efa.assignment_id) AS flights_assigned
FROM employees em
LEFT JOIN employee_flight_assignment efa ON efa.employee_id = em.employee_id
GROUP BY em.employee_id, em.full_name, em.role, em.shift
ORDER BY flights_assigned DESC, em.full_name",
    ],
];

foreach ($reports as &$report) {
    $report['rows'] = $db->query($report['sql'])->fetchAll(PDO::FETCH_ASSOC);
}
unset($report);

function reportCell($column, $value, $money, $percent) {
    if ($value === null) {
        return '&mdash;';
    }
    if (in_array($column, $money, true)) {
        return '₹' . number_format((float)$value, 2);
    }
    if (in_array($column, $percent, true)) {
        return number_format((float)$value, 1) . '%';
    }
    return e($value);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - SkyConnect</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .report-section { margin-bottom: 30px; }
        .report-section details {
            margin: 8px 0 12px;
        }
        .report-section summary {
            cursor: pointer;
            color: #2c7be5;
            font-size: 0.9rem;
        }
        .report-section pre {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 12px;
            overflow-x: auto;
            font-size: 0.85rem;
        }
        .report-note { color: #666; font-size: 0.9rem; margin-bottom: 8px; }
    </style>
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
                <li><a href="employees.php">Employees</a></li>
                <li><a href="reports.php" class="active">Reports</a></li>
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
            <h1>Reports</h1>
            <p>Live operational and revenue reports. Each section shows the exact SQL it runs.</p>

            <?php foreach ($reports as $report): ?>
                <div class="admin-card report-section">
                    <h2><?php echo e($report['title']); ?></h2>
                    <?php if (!empty($report['note'])): ?>
                        <p class="report-note"><?php echo e($report['note']); ?></p>
                    <?php endif; ?>
                    <details>
                        <summary>Show SQL</summary>
                        <pre><?php echo e($report['sql']); ?></pre>
                    </details>

                    <?php if (count($report['rows']) > 0): ?>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <?php foreach (array_keys($report['rows'][0]) as $column): ?>
                                            <th><?php echo e(ucwords(str_replace('_', ' ', $column))); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report['rows'] as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $column => $value): ?>
                                                <td><?php echo reportCell($column, $value, $report['money'] ?? [], $report['percent'] ?? []); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p>No data yet.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <footer>
        <div class="footer-content">
            <p>&copy; 2025 SkyConnect. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
