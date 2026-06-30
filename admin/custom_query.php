<?php
require_once '../includes/functions.php';
requireAdmin();
$page_title = "Custom SQL Query";

$db = getDB();
$result = null;
$query = '';
$error = '';
$success = '';
$affected_rows = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_query'])) {
    $query = trim($_POST['sql_query']);
    
    if (empty($query)) {
        $error = "Please enter a SQL query.";
    } elseif (!preg_match('/^\s*(SELECT|EXPLAIN|SHOW|DESCRIBE)\b/i', $query)) {
        $error = "This is a read-only console: only SELECT, EXPLAIN, SHOW and DESCRIBE queries are allowed.";
    } elseif (strpos(rtrim($query, "; \t\n\r"), ';') !== false) {
        $error = "This is a read-only console: only a single statement is allowed (no semicolons).";
    } else {
        try {
            $stmt = $db->query($query);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $success = "Query executed successfully. " . count($result) . " rows returned.";
        } catch (PDOException $e) {
            $error = "Error executing query: " . $e->getMessage();
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .query-editor {
            width: 100%;
            min-height: 150px;
            font-family: monospace;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .result-container {
            margin-top: 20px;
            overflow-x: auto;
        }
        .result-table {
            width: 100%;
            border-collapse: collapse;
        }
        .result-table th, .result-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .result-table th {
            background-color: #f2f2f2;
        }
        .query-actions {
            margin-bottom: 20px;
        }
        .query-examples {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .query-example {
            margin-bottom: 10px;
            cursor: pointer;
            color: #2c7be5;
        }
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
                <li><a href="index.php">Admin Dashboard</a></li>
                <li><a href="bookings.php">Bookings</a></li>
                <li><a href="users.php">Users</a></li>
                <li><a href="flights.php">Flights</a></li>
                <li><a href="aircraft.php">Aircraft</a></li>
                <li><a href="employees.php">Employees</a></li>
                <li><a href="reports.php">Reports</a></li>
                <li><a href="custom_query.php" class="active">Custom Query</a></li>
            </ul>
            <div class="auth-links">
                <span>Welcome, <?php echo e($_SESSION['first_name']); ?></span>
                <a href="../logout.php">Logout</a>
            </div>
        </nav>
    </header>
    
    <main>
        <div class="admin-container">
            <h1>Custom SQL Query</h1>
            <p>Read-only SQL console: SELECT, EXPLAIN, SHOW and DESCRIBE queries only.</p>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>
            
            <div class="admin-card">
                <form action="" method="post">
                    <?php echo csrf_field(); ?>
                    <div class="form-group">
                        <label for="sql_query">SQL Query:</label>
                        <textarea id="sql_query" name="sql_query" class="query-editor"><?php echo htmlspecialchars($query); ?></textarea>
                    </div>
                    
                    <div class="query-actions">
                        <button type="submit" name="execute_query" class="btn btn-primary">Execute Query</button>
                        <button type="button" id="clear-query" class="btn btn-secondary">Clear</button>
                    </div>
                </form>
                
                <div class="query-examples">
                    <h3>Example Queries:</h3>
                    <div class="query-example" data-query="SELECT * FROM users">SELECT * FROM users</div>
                    <div class="query-example" data-query="SELECT * FROM flights WHERE departure_city = 'New York'">SELECT * FROM flights WHERE departure_city = 'New York'</div>
                    <div class="query-example" data-query="SELECT b.booking_id, u.username, f.flight_number FROM bookings b JOIN users u ON b.user_id = u.user_id JOIN flights f ON b.flight_id = f.flight_id">SELECT bookings with user and flight info</div>
                    <div class="query-example" data-query="SELECT departure_city, arrival_city, COUNT(*) as flight_count FROM flights GROUP BY departure_city, arrival_city">Count flights by route</div>
                </div>
            </div>
            
            <?php if ($result !== null): ?>
                <div class="result-container">
                    <h2>Query Results</h2>
                    <?php if (empty($result)): ?>
                        <p>No results returned.</p>
                    <?php else: ?>
                        <table class="result-table">
                            <thead>
                                <tr>
                                    <?php foreach (array_keys($result[0]) as $column): ?>
                                        <th><?php echo htmlspecialchars($column); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($result as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $value): ?>
                                            <td><?php echo htmlspecialchars($value !== null ? $value : 'NULL'); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php elseif ($affected_rows > 0): ?>
                <div class="result-container">
                    <h2>Query Results</h2>
                    <p><?php echo $affected_rows; ?> rows affected.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <footer>
        <div class="footer-content">
            <p>&copy; 2025 SkyConnect. All rights reserved.</p>
        </div>
    </footer>
    
    <script>
        // Clear query button
        document.getElementById('clear-query').addEventListener('click', function() {
            document.getElementById('sql_query').value = '';
        });
        
        // Example queries
        document.querySelectorAll('.query-example').forEach(function(example) {
            example.addEventListener('click', function() {
                document.getElementById('sql_query').value = this.getAttribute('data-query');
            });
        });
    </script>
</body>
</html>
