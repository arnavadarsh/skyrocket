<?php
require_once 'db.php';

// Authentication functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function requireAdmin() {
    if (!isLoggedIn() || !isAdmin()) {
        header("Location: index.php");
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
        $_SESSION['is_admin'] = $user['is_admin'];
        
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

// Booking functions
function createBooking($user_id, $flight_id, $passenger_count, $total_price, $is_round_trip = 0, $return_flight_id = null) {
    $db = getDB();
    
    try {
        $db->beginTransaction();
        
        // Create booking
        $stmt = $db->prepare("INSERT INTO bookings (user_id, flight_id, return_flight_id, passenger_count, total_price, is_round_trip) 
                             VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $flight_id, $return_flight_id, $passenger_count, $total_price, $is_round_trip]);
        $booking_id = $db->lastInsertId();
        
        // Update available seats for outbound flight
        $stmt = $db->prepare("UPDATE flights SET available_seats = available_seats - ? WHERE flight_id = ?");
        $stmt->execute([$passenger_count, $flight_id]);
        
        // Update available seats for return flight if applicable
        if ($is_round_trip && $return_flight_id) {
            $stmt->execute([$passenger_count, $return_flight_id]);
        }
        
        $db->commit();
        return $booking_id;
    } catch (Exception $e) {
        $db->rollBack();
        return false;
    }
}

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

function executeCustomQuery($sql) {
    $db = getDB();
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute();
        
        // Check if it's a SELECT query
        if (stripos($sql, 'SELECT') === 0) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            return $stmt->rowCount() . " rows affected";
        }
    } catch (PDOException $e) {
        return "Error: " . $e->getMessage();
    }
}
?>
