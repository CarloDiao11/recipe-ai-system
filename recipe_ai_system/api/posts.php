<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Login required']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// Helper function to get post owner
function getPostOwner($conn, $post_id) {
    $stmt = $conn->prepare("SELECT user_id FROM posts WHERE id = ?");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ? (int)$result['user_id'] : null;
}

// === TOGGLE LIKE ===
if ($action === 'toggle_like') {
    $post_id = (int)$_POST['post_id'];
    
    // Validate post exists
    $owner_id = getPostOwner($conn, $post_id);
    if (!$owner_id) {
        echo json_encode(['success' => false, 'error' => 'Post not found']);
        exit();
    }

    // Check if already liked
    $stmt = $conn->prepare("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $post_id, $user_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
        // Unlike
        $stmt = $conn->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE posts SET likes_count = GREATEST(0, likes_count - 1) WHERE id = ?");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $stmt->close();
    } else {
        // Like
        $stmt = $conn->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE posts SET likes_count = likes_count + 1 WHERE id = ?");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $stmt->close();

        // === SEND NOTIFICATION TO POST OWNER (if not self) ===
        if ($owner_id !== $user_id) {
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, related_id)
                VALUES (?, 'like', 'New Like', 'liked your post.', ?)
            ");
            $stmt->bind_param("ii", $owner_id, $post_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    echo json_encode(['success' => true]);
    exit();
}

// === ADD COMMENT ===
if ($action === 'add_comment') {
    $post_id = (int)$_POST['post_id'];
    $comment_text = trim($_POST['comment_text'] ?? '');
    
    if (empty($comment_text)) {
        echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
        exit();
    }

    $owner_id = getPostOwner($conn, $post_id);
    if (!$owner_id) {
        echo json_encode(['success' => false, 'error' => 'Post not found']);
        exit();
    }

    // Insert comment
    $stmt = $conn->prepare("INSERT INTO comments (post_id, user_id, comment_text) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $post_id, $user_id, $comment_text);
    $stmt->execute();
    $stmt->close();

    // Update comment count
    $stmt = $conn->prepare("UPDATE posts SET comments_count = comments_count + 1 WHERE id = ?");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $stmt->close();

    // === SEND NOTIFICATION TO POST OWNER (if not self) ===
    if ($owner_id !== $user_id) {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_id)
            VALUES (?, 'comment', 'New Comment', 'commented on your post.', ?)
        ");
        $stmt->bind_param("ii", $owner_id, $post_id);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true]);
    exit();
}

// === CREATE POST ===
if ($action === 'create_post') {
    $content = trim($_POST['content'] ?? '');
    $media_type = $_POST['media_type'] ?? 'none';
    $media_data = $_POST['media_data'] ?? '';

    if (empty($content) && empty($media_data)) {
        echo json_encode(['success' => false, 'error' => 'Content or media is required']);
        exit();
    }

    // Validate media type
    if ($media_type !== 'none' && $media_type !== 'image' && $media_type !== 'video') {
        echo json_encode(['success' => false, 'error' => 'Invalid media type']);
        exit();
    }

    $media_url = null;

    if ($media_type !== 'none' && !empty($media_data)) {
        // Ensure uploads directory exists
        $upload_dir = '../uploads/posts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Extract base64 data
        $data_parts = explode(',', $media_data);
        if (count($data_parts) !== 2) {
            echo json_encode(['success' => false, 'error' => 'Invalid media data']);
            exit();
        }

        $header = $data_parts[0];
        $data = $data_parts[1];
        $decoded = base64_decode($data);

        if ($decoded === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to decode media']);
            exit();
        }

        // Determine file extension
        $ext = $media_type === 'image' ? 'jpg' : 'mp4';
        $filename = 'post_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $file_path = $upload_dir . $filename;

        if (file_put_contents($file_path, $decoded) === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to save media file']);
            exit();
        }

        $media_url = 'uploads/posts/' . $filename;
    }

    // Insert post
    $stmt = $conn->prepare("INSERT INTO posts (user_id, content, media_type, media_url, likes_count, comments_count) VALUES (?, ?, ?, ?, 0, 0)");
    $stmt->bind_param("isss", $user_id, $content, $media_type, $media_url);
    $stmt->execute();
    $post_id = $stmt->insert_id;
    $stmt->close();

    echo json_encode(['success' => true, 'post_id' => $post_id]);
    exit();
}

// Invalid action
echo json_encode(['success' => false, 'error' => 'Invalid action']);
exit();