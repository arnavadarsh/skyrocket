<?php
require_once 'db.php';

// Output escaping: wrap ALL echoed user/DB data
function e($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// CSRF helpers (session-based). Every state-changing action must be a POST
// form containing csrf_field() and call csrf_verify() before acting.
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify() {
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die("Invalid or missing CSRF token. Please go back and try again.");
    }
}

// Flight status badge (colors in assets/css/style.css)
function statusBadge($status) {
    $status = $status ?: 'scheduled';
    return '<span class="status-badge status-' . e($status) . '">' . e(ucfirst($status)) . '</span>';
}

// Authentication functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isStaff() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['staff', 'admin'], true);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

// Path to the app root's index.php, valid from any subdirectory.
// A bare "Location: index.php" inside /admin or /staff would resolve
// to the protected page itself and loop.
function appIndexUrl() {
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $dir = preg_replace('#/(admin|staff)$#', '', $dir);
    return ($dir === '' ? '' : $dir) . '/index.php';
}

function requireAdmin() {
    if (!isLoggedIn() || !isAdmin()) {
        header("Location: " . appIndexUrl());
        exit;
    }
}

// Staff pages are open to role 'staff' AND role 'admin'
function requireStaff() {
    if (!isLoggedIn() || !isStaff()) {
        header("Location: " . appIndexUrl());
        exit;
    }
}

// User functions
function registerUser($username, $email, $password, $first_name, $last_name) {
    $db = getDB();
    
    // Check if username or email already exists
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->rowCount() > 0) {
        return false;
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert new user
    $stmt = $db->prepare("INSERT INTO users (username, email, password, first_name, last_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$username, $email, $hashed_password, $first_name, $last_name]);
    
    return true;
}

function loginUser($username, $password) {
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
        $_SESSION['role'] = $user['role'];

        return true;
    }
    
    return false;
}

function getUser($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Flight functions
function getFlights($from, $to, $date) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM flights 
                         WHERE departure_city LIKE ? 
                         AND arrival_city LIKE ? 
                         AND DATE(departure_time) = ?
                         AND available_seats > 0
                         ORDER BY departure_time");
    $stmt->execute(["%$from%", "%$to%", $date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFlight($flight_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM flights WHERE flight_id = ?");
    $stmt->execute([$flight_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Seat auto-assignment: rows 1-30, letters A-F, same grid for every
// class this week. Must be called inside a transaction: the FOR UPDATE
// lock stops two concurrent bookings from picking the same free seat.
// Prefers a contiguous block in one row so passengers on the same
// booking sit together. Returns $count seat labels; entries are NULL
// if the 180-seat grid is exhausted (ticket stays valid, no seat).
function assignSeats($db, $flight_id, $count) {
    $stmt = $db->prepare("SELECT seat_number FROM tickets WHERE flight_id = ? AND seat_number IS NOT NULL FOR UPDATE");
    $stmt->execute([$flight_id]);
    $taken = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $letters = ['A', 'B', 'C', 'D', 'E', 'F'];

    // First pass: a contiguous run of $count free seats within one row
    for ($row = 1; $row <= 30; $row++) {
        $run = [];
        foreach ($letters as $letter) {
            $seat = $row . $letter;
            if (isset($taken[$seat])) {
                $run = [];
            } else {
                $run[] = $seat;
                if (count($run) === $count) {
                    return $run;
                }
            }
        }
    }

    // Fallback: first free seats anywhere
    $seats = [];
    for ($row = 1; $row <= 30 && count($seats) < $count; $row++) {
        foreach ($letters as $letter) {
            $seat = $row . $letter;
            if (!isset($taken[$seat]) && count($seats) < $count) {
                $seats[] = $seat;
            }
        }
    }
    while (count($seats) < $count) {
        $seats[] = null;
    }
    return $seats;
}

// Booking functions
function getUserBookings($user_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT b.*, 
               f1.flight_number as outbound_flight, f1.departure_city as from_city, f1.arrival_city as to_city, 
               f1.departure_time, f1.arrival_time,
               f2.flight_number as return_flight, f2.departure_time as return_departure, f2.arrival_time as return_arrival
        FROM bookings b
        JOIN flights f1 ON b.flight_id = f1.flight_id
        LEFT JOIN flights f2 ON b.return_flight_id = f2.flight_id
        WHERE b.user_id = ?
        ORDER BY b.booking_date DESC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Admin functions
function getAllBookings() {
    $db = getDB();
    $stmt = $db->query("
        SELECT b.*, 
               u.username, u.email, u.first_name as user_first_name, u.last_name as user_last_name,
               f1.flight_number as outbound_flight, f1.departure_city as from_city, f1.arrival_city as to_city, 
               f1.departure_time, f1.arrival_time,
               f2.flight_number as return_flight
        FROM bookings b
        JOIN users u ON b.user_id = u.user_id
        JOIN flights f1 ON b.flight_id = f1.flight_id
        LEFT JOIN flights f2 ON b.return_flight_id = f2.flight_id
        ORDER BY b.booking_date DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllUsers() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllFlights() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM flights ORDER BY departure_time");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
