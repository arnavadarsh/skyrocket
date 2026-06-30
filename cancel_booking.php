<?php
require_once 'includes/functions.php';
requireLogin();

// POST-only endpoint: cancelling a booking changes state, so GET must never do it
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: booking_history.php");
    exit;
}
csrf_verify();

$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;

function cancel_fail($message) {
    header("Location: booking_history.php?cancel_error=" . urlencode($message));
    exit;
}

if ($booking_id <= 0) {
    cancel_fail("Invalid booking.");
}

$db = getDB();

try {
    $db->beginTransaction();

    // Lock the booking row so a double-submit cannot restore seats twice
    $stmt = $db->prepare("
        SELECT b.booking_id, b.flight_id, b.return_flight_id, b.passenger_count, b.status,
               f.departure_time, f.status AS flight_status
        FROM bookings b
        JOIN flights f ON b.flight_id = f.flight_id
        WHERE b.booking_id = ? AND b.user_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$booking_id, $_SESSION['user_id']]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $db->rollBack();
        cancel_fail("Booking not found.");
    }
    // pending (unpaid) and confirmed bookings can both be cancelled
    if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
        $db->rollBack();
        cancel_fail("This booking has already been cancelled.");
    }
    if (strtotime($booking['departure_time']) <= time()
        || in_array($booking['flight_status'], ['departed', 'arrived'], true)) {
        $db->rollBack();
        cancel_fail("This booking can no longer be cancelled: the outbound flight has already departed.");
    }

    // The whole cancel cascade lives in the stored procedure:
    // tickets cancelled + seats freed (via trigger, once per ticket),
    // completed payment refunded, booking marked cancelled
    $stmt = $db->prepare("CALL sp_cancel_booking(?)");
    $stmt->execute([$booking_id]);
    $stmt->closeCursor();

    $db->commit();
    header("Location: booking_history.php?cancelled=1");
    exit;
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    cancel_fail("An error occurred while cancelling the booking.");
}
