<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// === MARK SINGLE NOTIFICATION AS READ ===
if ($action === 'mark_read') {
    $notification_id = (int)$_POST['notification_id'];
    
    // Update is_read to TRUE
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    $stmt->execute();
    $stmt->close();

    // Count remaining unread
    $stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unread_count = $stmt->get_result()->fetch_assoc()['unread_count'];
    $stmt->close();

    echo json_encode(['success' => true, 'unread_count' => (int)$unread_count]);
    exit();
}

// === MARK ALL NOTIFICATIONS AS READ ===
if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
exit();