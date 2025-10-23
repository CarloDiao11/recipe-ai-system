<?php
// Prevent any output before headers
ob_start();

session_start();

// Get the correct path to database config
$base_path = dirname(dirname(__FILE__));
require_once $base_path . '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'mark_read':
        // Mark single notification as read
        if (!isset($_POST['notification_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Notification ID required']);
            exit();
        }
        
        $notification_id = intval($_POST['notification_id']);
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notification_id, $user_id);
        
        if ($stmt->execute()) {
            // Get updated unread count
            $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
            $count_stmt->bind_param("i", $user_id);
            $count_stmt->execute();
            $unread_count = $count_stmt->get_result()->fetch_assoc()['count'];
            $count_stmt->close();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Notification marked as read',
                'unread_count' => $unread_count
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update notification']);
        }
        $stmt->close();
        break;
        
    case 'mark_all_read':
        // Mark all notifications as read
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true, 
                'message' => 'All notifications marked as read',
                'unread_count' => 0
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update notifications']);
        }
        $stmt->close();
        break;
        
    case 'get_count':
        // Get unread notification count
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        echo json_encode(['count' => $result['count']]);
        break;
        
    case 'get_all':
        // Get all notifications
        $stmt = $conn->prepare("
            SELECT 
                id, type, title, message, is_read, related_id, created_at,
                CASE 
                    WHEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) < 1 THEN 'Just now'
                    WHEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, created_at, NOW()), ' minutes ago')
                    WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, created_at, NOW()), ' hours ago')
                    WHEN TIMESTAMPDIFF(DAY, created_at, NOW()) = 1 THEN 'Yesterday'
                    ELSE CONCAT(TIMESTAMPDIFF(DAY, created_at, NOW()), ' days ago')
                END as time_ago
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode(['notifications' => $notifications]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}

$conn->close();
ob_end_flush();
?>