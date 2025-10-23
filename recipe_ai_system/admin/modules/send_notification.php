<?php
session_start();
require_once '../../config/database.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['title']) || !isset($input['message']) || !isset($input['type']) || !isset($input['recipients'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$title = trim($input['title']);
$message = trim($input['message']);
$type = trim($input['type']);
$recipients = $input['recipients'];

// Validate data
if (empty($title) || empty($message) || empty($type) || empty($recipients)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

// Validate type
$valid_types = ['recipe', 'comment', 'like', 'follower', 'message'];
if (!in_array($type, $valid_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification type']);
    exit();
}

// Begin transaction
$conn->begin_transaction();

try {
    // Prepare insert statement
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }
    
    $success_count = 0;
    
    // Insert notification for each recipient
    foreach ($recipients as $recipient_id) {
        $recipient_id = intval($recipient_id);
        
        // Don't send to self
        if ($recipient_id == $user_id) {
            continue;
        }
        
        // Bind parameters and execute
        $stmt->bind_param("isss", $recipient_id, $title, $message, $type);
        
        if ($stmt->execute()) {
            $success_count++;
        }
    }
    
    $stmt->close();
    
    // Commit transaction
    $conn->commit();
    
    if ($success_count > 0) {
        echo json_encode([
            'success' => true, 
            'message' => "Notification sent successfully to {$success_count} user(s)",
            'count' => $success_count
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No notifications were sent']);
    }
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to send notifications: ' . $e->getMessage()
    ]);
}

$conn->close();
?>