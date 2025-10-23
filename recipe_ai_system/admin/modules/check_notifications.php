<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['notifications' => []]);
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch unread notifications for the current user
$query = "SELECT id, title, message, type, created_at 
          FROM notifications 
          WHERE user_id = ? AND is_read = 0 
          ORDER BY created_at DESC 
          LIMIT 5";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode(['notifications' => $notifications]);
?>