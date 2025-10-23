<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// Helper to sanitize
function sanitize($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

if ($action === 'send_message') {
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $message = sanitize($_POST['message'] ?? '');

    if ($receiver_id <= 0 || empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit();
    }

    // Prevent self-message
    if ($receiver_id === $user_id) {
        echo json_encode(['success' => false, 'error' => 'Cannot message yourself']);
        exit();
    }

    // Insert message
    $stmt = $conn->prepare("
        INSERT INTO chat_messages (sender_id, receiver_id, message_text, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iis", $user_id, $receiver_id, $message);
    $success = $stmt->execute();
    $message_id = $conn->insert_id;
    $stmt->close();

    if ($success) {
        // Return the new message data
        $stmt = $conn->prepare("
            SELECT 
                cm.id,
                cm.sender_id,
                cm.message_text,
                cm.created_at,
                u.initials,
                u.avatar_color,
                u.name
            FROM chat_messages cm
            JOIN users u ON cm.sender_id = u.id
            WHERE cm.id = ?
        ");
        $stmt->bind_param("i", $message_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        echo json_encode([
            'success' => true,
            'message' => [
                'id' => $row['id'],
                'sender_id' => $row['sender_id'],
                'message' => $row['message_text'],
                'time' => date('h:i A', strtotime($row['created_at'])),
                'is_user' => $row['sender_id'] == $user_id,
                'initials' => $row['initials'] ?: strtoupper(substr($row['name'], 0, 1)),
                'color' => $row['avatar_color'] ?: '#6f42c1'
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to send message']);
    }
    exit();
}

if ($action === 'get_messages') {
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $last_id = (int)($_POST['last_id'] ?? 0);

    if ($receiver_id <= 0) {
        echo json_encode(['messages' => []]);
        exit();
    }

    // If last_id is 0, get all messages, otherwise get only new messages
    if ($last_id === 0) {
        // Get ALL messages for initial load
        $stmt = $conn->prepare("
            SELECT 
                cm.id,
                cm.sender_id,
                cm.message_text,
                cm.created_at,
                u.initials,
                u.avatar_color,
                u.name
            FROM chat_messages cm
            JOIN users u ON cm.sender_id = u.id
            WHERE 
                (cm.sender_id = ? AND cm.receiver_id = ?) 
                OR (cm.sender_id = ? AND cm.receiver_id = ?)
            ORDER BY cm.created_at ASC
        ");
        $stmt->bind_param("iiii", $user_id, $receiver_id, $receiver_id, $user_id);
    } else {
        // Get only NEW messages after last_id
        $stmt = $conn->prepare("
            SELECT 
                cm.id,
                cm.sender_id,
                cm.message_text,
                cm.created_at,
                u.initials,
                u.avatar_color,
                u.name
            FROM chat_messages cm
            JOIN users u ON cm.sender_id = u.id
            WHERE 
                ((cm.sender_id = ? AND cm.receiver_id = ?) 
                OR (cm.sender_id = ? AND cm.receiver_id = ?))
                AND cm.id > ?
            ORDER BY cm.created_at ASC
        ");
        $stmt->bind_param("iiiii", $user_id, $receiver_id, $receiver_id, $user_id, $last_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'id' => $row['id'],
            'sender_id' => $row['sender_id'],
            'message' => $row['message_text'],
            'time' => date('h:i A', strtotime($row['created_at'])),
            'is_user' => $row['sender_id'] == $user_id,
            'initials' => $row['initials'] ?: strtoupper(substr($row['name'], 0, 1)),
            'color' => $row['avatar_color'] ?: '#6f42c1'
        ];
    }
    $stmt->close();

    echo json_encode(['messages' => $messages]);
    exit();
}

if ($action === 'mark_read') {
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    if ($receiver_id > 0) {
        $stmt = $conn->prepare("
            UPDATE chat_messages 
            SET is_read = TRUE 
            WHERE sender_id = ? AND receiver_id = ? AND is_read = FALSE
        ");
        $stmt->bind_param("ii", $receiver_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    exit();
}

echo json_encode(['error' => 'Invalid action']);
?>