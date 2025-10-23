<?php
session_start();
require_once '../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user data
$user_query = "SELECT id, username, email, name, profile_picture, initials, avatar_color, role 
               FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user = $user_result->fetch_assoc();
$stmt->close();

// Generate initials if not set
if (empty($user['initials'])) {
    $name_parts = explode(' ', $user['name']);
    $user['initials'] = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));
}

// Generate avatar color if not set
if (empty($user['avatar_color'])) {
    $colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E2'];
    $user['avatar_color'] = $colors[array_rand($colors)];
}

// Get statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM posts) as total_posts,
    (SELECT SUM(likes_count) FROM posts) as total_likes,
    (SELECT SUM(comments_count) FROM posts) as total_comments,
    (SELECT COUNT(*) FROM posts) * 50 as total_views";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Fetch all posts with user info
$posts_query = "SELECT p.*, u.name, u.username, u.initials, u.avatar_color, u.profile_picture,
                TIMESTAMPDIFF(HOUR, p.created_at, NOW()) as hours_ago,
                TIMESTAMPDIFF(MINUTE, p.created_at, NOW()) as minutes_ago
                FROM posts p
                JOIN users u ON p.user_id = u.id
                ORDER BY p.created_at DESC";
$posts_result = $conn->query($posts_query);

// Unread Notifications Count
$notif_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt = $conn->prepare($notif_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_count = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// Fetch Notifications
$notifications_query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
$stmt = $conn->prepare($notifications_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications = $stmt->get_result();
$stmt->close();

// Function to format time ago
function timeAgo($minutes) {
    if ($minutes < 60) {
        return $minutes . ' minute' . ($minutes != 1 ? 's' : '') . ' ago';
    } elseif ($minutes < 1440) { // Less than 24 hours
        $hours = floor($minutes / 60);
        return $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
    } else {
        $days = floor($minutes / 1440);
        return $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
    }
}

// Handle post deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post'])) {
    $post_id = (int)$_POST['post_id'];
    
    // Verify post belongs to user or user is admin
    $verify_query = "SELECT user_id FROM posts WHERE id = ?";
    $stmt = $conn->prepare($verify_query);
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $post = $result->fetch_assoc();
    $stmt->close();
    
    if ($post && ($post['user_id'] == $user_id || $user['role'] == 'admin')) {
        // Delete post
        $delete_query = "DELETE FROM posts WHERE id = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $stmt->close();
        
        // Also delete associated likes and comments
        $conn->query("DELETE FROM post_likes WHERE post_id = $post_id");
        $conn->query("DELETE FROM comments WHERE post_id = $post_id");
        
        $_SESSION['success_message'] = "Post deleted successfully!";
        header("Location: post.php");
        exit();
    } else {
        $_SESSION['error_message'] = "You don't have permission to delete this post.";
        header("Location: post.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flavor Forge - Posts</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/header.css">

    <style>
        /* Content Area */
        .content {
            padding: 40px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-primary);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-icon.posts {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-icon.likes {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .stat-icon.comments {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .stat-icon.views {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        .stat-info h3 {
            font-size: 28px;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* Posts Container */
        .posts-container {
            background: var(--bg-primary);
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
            overflow: hidden;
        }

        .posts-header {
            padding: 25px 30px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .posts-header h2 {
            color: var(--text-primary);
            font-size: 24px;
        }

        .search-filter {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 40px 10px 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            width: 250px;
        }

        .search-box i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        .filter-select {
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            cursor: pointer;
        }

        /* Posts List */
        .posts-list {
            padding: 20px;
        }

        .post-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .post-card:hover {
            box-shadow: 0 5px 15px var(--shadow);
            transform: translateY(-2px);
        }

        .post-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .post-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
            object-fit: cover;
        }

        .user-info h4 {
            color: var(--text-primary);
            font-size: 16px;
            margin-bottom: 3px;
        }

        .post-time {
            color: var(--text-secondary);
            font-size: 13px;
        }

        .post-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .edit-btn {
            background: #3498db;
            color: white;
        }

        .edit-btn:hover {
            background: #2980b9;
            transform: scale(1.1);
        }

        .delete-btn {
            background: #e74c3c;
            color: white;
        }

        .delete-btn:hover {
            background: #c0392b;
            transform: scale(1.1);
        }

        .post-content {
            margin-bottom: 15px;
        }

        .post-content p {
            color: var(--text-primary);
            line-height: 1.6;
            font-size: 15px;
        }

        .post-media {
            margin: 15px 0;
            border-radius: 10px;
            overflow: hidden;
        }

        .post-media img {
            width: 100%;
            height: auto;
            max-height: 400px;
            object-fit: cover;
        }

        .post-media video {
            width: 100%;
            max-height: 400px;
            border-radius: 10px;
        }

        .media-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .media-badge.image {
            background: #e3f2fd;
            color: #1976d2;
        }

        .media-badge.video {
            background: #fce4ec;
            color: #c2185b;
        }

        .post-stats {
            display: flex;
            gap: 25px;
            padding-top: 15px;
            border-top: 1px solid var(--border);
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .stat-item i {
            font-size: 16px;
        }

        .stat-item.likes i {
            color: #e74c3c;
        }

        .stat-item.comments i {
            color: #3498db;
        }

        /* Delete Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--bg-primary);
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .modal-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #fee;
            color: #e74c3c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .modal-header h3 {
            color: var(--text-primary);
            font-size: 22px;
        }

        .modal-body {
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-cancel {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        .btn-cancel:hover {
            background: var(--border);
        }

        .btn-delete {
            background: #e74c3c;
            color: white;
        }

        .btn-delete:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        /* Success Message */
        .success-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            display: none;
            align-items: center;
            gap: 12px;
            z-index: 1001;
            animation: slideInRight 0.3s ease;
        }

        .success-message.show {
            display: flex;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .success-message i {
            font-size: 20px;
        }

        .error-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #e74c3c;
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            display: none;
            align-items: center;
            gap: 12px;
            z-index: 1001;
            animation: slideInRight 0.3s ease;
        }

        .error-message.show {
            display: flex;
        }

        .no-posts {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .no-posts i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .no-posts h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        /* Responsive Design */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 24px;
            cursor: pointer;
            padding: 8px;
        }

        /* Tablet Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .content {
                padding: 30px 20px;
            }

            .search-box input {
                width: 200px;
            }
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 1000;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .header {
                padding: 15px 20px;
            }

            .header-left h1 {
                font-size: 20px;
            }

            .menu-toggle {
                display: block;
            }

            .profile-info {
                display: none;
            }

            .content {
                padding: 20px 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .stat-card {
                padding: 20px;
            }

            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }

            .stat-info h3 {
                font-size: 24px;
            }

            .stat-info p {
                font-size: 13px;
            }

            .posts-container {
                border-radius: 12px;
            }

            .posts-header {
                padding: 20px;
                flex-direction: column;
                align-items: flex-start;
            }

            .posts-header h2 {
                font-size: 20px;
                margin-bottom: 15px;
            }

            .search-filter {
                width: 100%;
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .search-box input {
                width: 100%;
            }

            .filter-select {
                width: 100%;
            }

            .posts-list {
                padding: 15px;
            }

            .post-card {
                padding: 15px;
            }

            .post-header {
                flex-direction: column;
                gap: 15px;
            }

            .post-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .user-avatar {
                width: 40px;
                height: 40px;
                font-size: 15px;
            }

            .user-info h4 {
                font-size: 15px;
            }

            .post-time {
                font-size: 12px;
            }

            .post-content p {
                font-size: 14px;
            }

            .post-media img,
            .post-media video {
                max-height: 300px;
            }

            .post-stats {
                flex-wrap: wrap;
                gap: 15px;
            }

            .stat-item {
                font-size: 13px;
            }

            .modal-content {
                width: 95%;
                padding: 25px;
            }

            .modal-header h3 {
                font-size: 20px;
            }

            .modal-body {
                font-size: 14px;
            }

            .btn {
                padding: 10px 20px;
                font-size: 13px;
            }

            .success-message,
            .error-message {
                right: 10px;
                left: 10px;
                padding: 12px 20px;
                font-size: 14px;
            }
        }

        /* Small Mobile Devices */
        @media (max-width: 480px) {
            .header {
                padding: 12px 15px;
            }

            .header-left h1 {
                font-size: 18px;
            }

            .content {
                padding: 15px 10px;
            }

            .stats-grid {
                gap: 10px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-icon {
                width: 45px;
                height: 45px;
                font-size: 18px;
            }

            .stat-info h3 {
                font-size: 20px;
            }

            .stat-info p {
                font-size: 12px;
            }

            .posts-header {
                padding: 15px;
            }

            .posts-header h2 {
                font-size: 18px;
            }

            .search-box input {
                padding: 8px 35px 8px 12px;
                font-size: 13px;
            }

            .filter-select {
                padding: 8px 12px;
                font-size: 13px;
            }

            .posts-list {
                padding: 10px;
            }

            .post-card {
                padding: 12px;
                margin-bottom: 10px;
            }

            .user-avatar {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }

            .user-info h4 {
                font-size: 14px;
            }

            .post-time {
                font-size: 11px;
            }

            .action-btn {
                width: 32px;
                height: 32px;
                font-size: 13px;
            }

            .post-content p {
                font-size: 13px;
            }

            .media-badge {
                padding: 4px 10px;
                font-size: 11px;
            }

            .post-media img,
            .post-media video {
                max-height: 250px;
            }

            .post-stats {
                gap: 12px;
                padding-top: 12px;
            }

            .stat-item {
                font-size: 12px;
            }

            .stat-item i {
                font-size: 14px;
            }

            .modal-content {
                padding: 20px;
            }

            .modal-icon {
                width: 45px;
                height: 45px;
                font-size: 20px;
            }

            .modal-header h3 {
                font-size: 18px;
            }

            .modal-body {
                font-size: 13px;
                margin-bottom: 20px;
            }

            .modal-footer {
                flex-direction: column;
                gap: 8px;
            }

            .btn {
                width: 100%;
                padding: 10px;
            }

            .no-posts i {
                font-size: 48px;
            }

            .no-posts h3 {
                font-size: 20px;
            }

            .no-posts p {
                font-size: 14px;
            }
        }

        /* Extra Small Devices */
        @media (max-width: 360px) {
            .header-left h1 {
                font-size: 16px;
            }

            .stat-info h3 {
                font-size: 18px;
            }

            .posts-header h2 {
                font-size: 16px;
            }

            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 13px;
            }

            .action-btn {
                width: 30px;
                height: 30px;
                font-size: 12px;
            }

            .post-content p {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="overlay" id="overlay"></div>
    
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="success-message show" id="successMessage">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($_SESSION['success_message']); ?>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="error-message show" id="errorMessage">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($_SESSION['error_message']); ?>
    </div>
    <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon">
                    <i class="fas fa-trash"></i>
                </div>
                <h3>Delete Post</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this post? This action cannot be undone.</p>
                <p id="deletePostId" style="display: none;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <form id="deleteForm" method="POST" style="display: inline;">
                    <input type="hidden" name="delete_post" value="1">
                    <input type="hidden" name="post_id" id="postIdToDelete" value="">
                    <button type="submit" class="btn btn-delete">Delete</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="container">
        <?php include '../partials/sidebar.php'; ?>

        <div class="main-content">
            <header class="header">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1>Posts</h1>
                </div>
                
                <div class="header-right">
                    <div class="dark-mode-toggle" id="darkModeToggle">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </div>
                    
                    <div class="notification-container">
                        <div class="notification" id="notificationButton">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            <?php if ($unread_count > 0): ?>
                            <span class="notification-badge"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notification-dropdown" id="notificationDropdown">
                            <div class="notification-header">
                                <h3>Notifications</h3>
                                <a href="#" class="mark-all-read">Mark all as read</a>
                            </div>
                            
                            <div class="notification-list">
                                <?php while ($notif = $notifications->fetch_assoc()): ?>
                                <div class="notification-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>">
                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                        <i class="fas fa-<?php 
                                            echo $notif['type'] == 'recipe' ? 'utensils' : 
                                                ($notif['type'] == 'comment' ? 'comment' : 
                                                ($notif['type'] == 'like' ? 'heart' : 
                                                ($notif['type'] == 'follower' ? 'user-plus' : 'envelope'))); 
                                        ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notification-text"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <div class="notification-time"><?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?></div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                            
                            <div class="notification-footer">
                                <a href="#" class="view-all">View all notifications</a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="profile-container">
                        <div class="profile-button" id="profileButton">
                            <?php if (!empty($user['profile_picture']) && file_exists('../../' . $user['profile_picture'])): ?>
                                <img src="../../<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile" class="profile-pic" style="border-radius: 50%; width: 40px; height: 40px; object-fit: cover;">
                            <?php else: ?>
                                <div class="profile-pic" style="background-color: <?php echo $user['avatar_color']; ?>">
                                    <?php echo htmlspecialchars($user['initials']); ?>
                                </div>
                            <?php endif; ?>
                            <div class="profile-info">
                                <div class="profile-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                <div class="profile-role"><?php echo ucfirst($user['role']); ?></div>
                            </div>
                            <svg class="dropdown-icon" id="dropdownIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                        
                        <div class="dropdown-menu" id="dropdownMenu">
                            <a href="profile.php" class="dropdown-item">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                Profile
                            </a>
                            
                            <a href="../../logout.php" class="dropdown-item">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                </svg>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <div class="content">
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon posts">
                            <i class="fas fa-newspaper"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($stats['total_posts']); ?></h3>
                            <p>Total Posts</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon likes">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($stats['total_likes'] ?? 0); ?></h3>
                            <p>Total Likes</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon comments">
                            <i class="fas fa-comment"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($stats['total_comments'] ?? 0); ?></h3>
                            <p>Total Comments</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon views">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($stats['total_views'] ?? 0); ?></h3>
                            <p>Total Views</p>
                        </div>
                    </div>
                </div>

                <!-- Posts Container -->
                <div class="posts-container">
                    <div class="posts-header">
                        <h2>Recent Posts</h2>
                        <div class="search-filter">
                            <div class="search-box">
                                <input type="text" placeholder="Search posts..." id="searchInput">
                                <i class="fas fa-search"></i>
                            </div>
                            <select class="filter-select" id="filterSelect">
                                <option value="all">All Posts</option>
                                <option value="image">With Images</option>
                                <option value="video">With Videos</option>
                                <option value="none">Text Only</option>
                            </select>
                        </div>
                    </div>

                    <div class="posts-list" id="postsList">
                        <?php if ($posts_result->num_rows > 0): ?>
                            <?php while ($post = $posts_result->fetch_assoc()): 
                                // Generate initials if not set
                                if (empty($post['initials'])) {
                                    $name_parts = explode(' ', $post['name']);
                                    $post['initials'] = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));
                                }
                            ?>
                            <div class="post-card" data-post-id="<?php echo $post['id']; ?>" data-type="<?php echo $post['media_type']; ?>">
                                <div class="post-header">
                                    <div class="post-user">
                                        <?php if (!empty($post['profile_picture']) && file_exists('../../' . $post['profile_picture'])): ?>
                                            <img src="../../<?php echo htmlspecialchars($post['profile_picture']); ?>" alt="User" class="user-avatar">
                                        <?php else: ?>
                                            <div class="user-avatar" style="background-color: <?php echo $post['avatar_color'] ?? '#667eea'; ?>">
                                                <?php echo htmlspecialchars($post['initials']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="user-info">
                                            <h4><?php echo htmlspecialchars($post['name']); ?></h4>
                                            <span class="post-time"><?php echo timeAgo($post['minutes_ago']); ?></span>
                                        </div>
                                    </div>
                                    <div class="post-actions">
                                        <?php if ($post['user_id'] == $user_id || $user['role'] == 'admin'): ?>
                                        <button class="action-btn edit-btn" title="Edit Post">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn delete-btn" onclick="openDeleteModal(<?php echo $post['id']; ?>)" title="Delete Post">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="post-content">
                                    <?php if ($post['media_type'] == 'image' && !empty($post['media_url'])): ?>
                                        <span class="media-badge image"><i class="fas fa-image"></i> Image</span>
                                        <div class="post-media">
                                            <img src="../../<?php echo htmlspecialchars($post['media_url']); ?>" alt="Post Image">
                                        </div>
                                    <?php elseif ($post['media_type'] == 'video' && !empty($post['media_url'])): ?>
                                        <span class="media-badge video"><i class="fas fa-video"></i> Video</span>
                                        <div class="post-media">
                                            <video controls>
                                                <source src="../../<?php echo htmlspecialchars($post['media_url']); ?>" type="video/mp4">
                                                Your browser does not support the video tag.
                                            </video>
                                        </div>
                                    <?php endif; ?>
                                    <p><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                                </div>
                                <div class="post-stats">
                                    <div class="stat-item likes">
                                        <i class="fas fa-heart"></i>
                                        <span><?php echo number_format($post['likes_count']); ?></span>
                                    </div>
                                    <div class="stat-item comments">
                                        <i class="fas fa-comment"></i>
                                        <span><?php echo number_format($post['comments_count']); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="no-posts">
                                <i class="fas fa-newspaper"></i>
                                <h3>No Posts Yet</h3>
                                <p>There are no posts to display at the moment.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/header.js"></script>
    <script>
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Dark Mode Toggle
            const darkModeToggle = document.getElementById('darkModeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const html = document.documentElement;
            // Check for saved theme preference
            const currentTheme = localStorage.getItem('theme') || 'light';
            html.setAttribute('data-theme', currentTheme);
            if (themeIcon) {
                if (currentTheme === 'dark') {
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                } else {
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                }
            }
            if (darkModeToggle) {
                darkModeToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const isDark = html.getAttribute('data-theme') === 'dark';
                    const newTheme = isDark ? 'light' : 'dark';
                    html.setAttribute('data-theme', newTheme);
                    localStorage.setItem('theme', newTheme);
                    // Toggle icon
                    if (themeIcon) {
                        if (newTheme === 'dark') {
                            themeIcon.classList.remove('fa-sun');
                            themeIcon.classList.add('fa-moon');
                        } else {
                            themeIcon.classList.remove('fa-moon');
                            themeIcon.classList.add('fa-sun');
                        }
                    }
                });
            }
            // Mobile Menu Toggle
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            if (menuToggle && sidebar && overlay) {
                menuToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    overlay.classList.toggle('active');
                });
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                });
            }
            // Notification Dropdown & Profile Dropdown
            const notificationButton = document.getElementById('notificationButton');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const dropdownMenu = document.getElementById('dropdownMenu');
            const profileButton = document.getElementById('profileButton');
            const dropdownIcon = document.getElementById('dropdownIcon');
            if (notificationButton && notificationDropdown) {
                notificationButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    notificationDropdown.classList.toggle('show');
                    // Close profile dropdown if open
                    if (dropdownMenu) {
                        dropdownMenu.classList.remove('show');
                    }
                    if (dropdownIcon) {
                        dropdownIcon.classList.remove('open');
                    }
                });
            }
            if (profileButton && dropdownMenu && dropdownIcon) {
                profileButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropdownMenu.classList.toggle('show');
                    dropdownIcon.classList.toggle('open');
                    // Close notification dropdown if open
                    if (notificationDropdown) {
                        notificationDropdown.classList.remove('show');
                    }
                });
            }
            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.profile-container')) {
                    if (dropdownMenu) dropdownMenu.classList.remove('show');
                    if (dropdownIcon) dropdownIcon.classList.remove('open');
                }
                if (!e.target.closest('.notification-container')) {
                    if (notificationDropdown) notificationDropdown.classList.remove('show');
                }
            });

            // Search and Filter functionality
            const searchInput = document.getElementById('searchInput');
            const filterSelect = document.getElementById('filterSelect');
            const postCards = document.querySelectorAll('.post-card');

            if (searchInput) {
                searchInput.addEventListener('input', filterPosts);
            }
            if (filterSelect) {
                filterSelect.addEventListener('change', filterPosts);
            }

            function filterPosts() {
                const searchTerm = searchInput.value.toLowerCase();
                const filterType = filterSelect.value;

                postCards.forEach(card => {
                    const postContent = card.textContent.toLowerCase();
                    const postType = card.getAttribute('data-type');

                    const matchesSearch = postContent.includes(searchTerm);
                    const matchesFilter = filterType === 'all' || postType === filterType;

                    card.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
                });
            }

            // Auto-hide success/error messages
            const successMessage = document.getElementById('successMessage');
            const errorMessage = document.getElementById('errorMessage');
            
            if (successMessage) {
                setTimeout(() => {
                    successMessage.classList.remove('show');
                }, 5000);
            }
            
            if (errorMessage) {
                setTimeout(() => {
                    errorMessage.classList.remove('show');
                }, 5000);
            }
        });

        // Delete Modal Functions
        function openDeleteModal(postId) {
            document.getElementById('postIdToDelete').value = postId;
            document.getElementById('deleteModal').classList.add('show');
            document.getElementById('overlay').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            document.getElementById('overlay').classList.remove('active');
        }

        // Close modal when clicking outside
        document.getElementById('overlay').addEventListener('click', function() {
            closeDeleteModal();
        });
    </script>
</body>
</html>