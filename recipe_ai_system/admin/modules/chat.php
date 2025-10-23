<?php
session_start();
require_once '../../config/database.php';
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}
$user_id = $_SESSION['user_id'];
// Fetch user data with profile picture
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
// Fetch all users with their last message
$users_query = "SELECT u.id, u.username, u.name, u.profile_picture, u.initials, u.avatar_color, u.status,
                (SELECT message_text FROM chat_messages 
                 WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id)
                 ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM chat_messages 
                 WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id)
                 ORDER BY created_at DESC LIMIT 1) as last_message_time,
                (SELECT COUNT(*) FROM chat_messages 
                 WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0) as unread_count
                FROM users u
                WHERE u.id != ?
                ORDER BY CASE WHEN last_message_time IS NULL THEN 1 ELSE 0 END, last_message_time DESC";
$stmt = $conn->prepare($users_query);
$stmt->bind_param("iiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$users_result = $stmt->get_result();
$stmt->close();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flavor Forge - Chat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/header.css">
    <style>
        /* Content Area */
        .content {
            padding: 40px;
            height: calc(100vh - 80px);
        }
        /* Chat Container */
        .chat-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 20px;
            height: 100%;
            max-height: calc(100vh - 160px);
            position: relative;
        }
        /* Users List Section */
        .users-section {
            background: var(--bg-primary);
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        .users-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
        }
        .users-header h2 {
            color: var(--text-primary);
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .back-to-chat {
            display: none;
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 20px;
            padding: 5px;
        }
        .search-box {
            position: relative;
        }
        .search-box input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }
        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }
        .user-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 5px;
            position: relative;
        }
        .user-item:hover {
            background: var(--bg-secondary);
        }
        .user-item.active {
            background: var(--primary);
        }
        .user-item.active .user-name,
        .user-item.active .last-message,
        .user-item.active .message-time {
            color: white;
        }
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 600;
            flex-shrink: 0;
            position: relative;
            object-fit: cover;
        }
        .user-item.active .user-avatar {
            background: white;
            color: var(--primary);
        }
        .status-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid var(--bg-primary);
        }
        .status-indicator.online {
            background: #22c55e;
        }
        .status-indicator.away {
            background: #f59e0b;
        }
        .status-indicator.offline {
            background: #94a3b8;
        }
        .user-info {
            flex: 1;
            min-width: 0;
        }
        .user-name {
            color: var(--text-primary);
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .last-message {
            color: var(--text-secondary);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .message-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
        }
        .message-time {
            color: var(--text-secondary);
            font-size: 12px;
        }
        .unread-badge {
            background: var(--primary);
            color: white;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
        }
        .user-item.active .unread-badge {
            background: white;
            color: var(--primary);
        }
        /* Chat Section */
        .chat-section {
            background: var(--bg-primary);
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .mobile-back-btn {
            display: none;
            background: none;
            border: none;
            color: var(--primary);
            font-size: 20px;
            cursor: pointer;
            padding: 5px;
        }
        .chat-user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 600;
            position: relative;
            object-fit: cover;
        }
        .chat-user-info {
            flex: 1;
        }
        .chat-user-name {
            color: var(--text-primary);
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .chat-user-status {
            color: var(--text-secondary);
            font-size: 13px;
        }
        .chat-user-status.online {
            color: #22c55e;
        }
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .message {
            display: flex;
            gap: 10px;
            max-width: 70%;
            animation: messageSlide 0.3s ease;
        }
        @keyframes messageSlide {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .message.sent {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        .message-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            flex-shrink: 0;
            object-fit: cover;
        }
        .message-content {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .message-bubble {
            background: var(--bg-secondary);
            padding: 12px 16px;
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 14px;
            line-height: 1.5;
            word-wrap: break-word;
        }
        .message.sent .message-bubble {
            background: var(--primary);
            color: white;
            border-bottom-right-radius: 4px;
        }
        .message.received .message-bubble {
            border-bottom-left-radius: 4px;
        }
        .message-timestamp {
            font-size: 11px;
            color: var(--text-secondary);
            padding: 0 5px;
        }
        .message.sent .message-timestamp {
            text-align: right;
        }
        .date-divider {
            text-align: center;
            margin: 20px 0;
            position: relative;
        }
        .date-divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 100%;
            height: 1px;
            background: var(--border);
        }
        .date-divider span {
            background: var(--bg-primary);
            padding: 5px 15px;
            border-radius: 15px;
            font-size: 12px;
            color: var(--text-secondary);
            position: relative;
            z-index: 1;
        }
        /* Message Input */
        .message-input-container {
            padding: 20px;
            border-top: 1px solid var(--border);
            position: relative;
        }
        .message-input-wrapper {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        .message-input {
            flex: 1;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 25px;
            padding: 12px 20px;
            color: var(--text-primary);
            font-size: 14px;
            resize: none;
            max-height: 120px;
            min-height: 45px;
            transition: all 0.3s ease;
            font-family: inherit;
            line-height: 1.5;
        }
        .message-input:focus {
            outline: none;
            border-color: var(--primary);
        }
        /* Hide textarea resize handle */
        .message-input::-webkit-resizer {
            display: none;
        }
        .send-btn {
            width: 45px;
            height: 45px;
            border: none;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .send-btn:hover {
            background: var(--primary-hover);
            transform: scale(1.05);
        }
        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: scale(1);
        }
        .send-btn i {
            font-size: 16px;
        }
        /* Empty State */
        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            padding: 40px;
        }
        .empty-chat i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        .empty-chat h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: var(--text-primary);
        }
        
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

        /* Responsive Design */
        @media (max-width: 1024px) {
            .chat-container {
                grid-template-columns: 300px 1fr;
            }
            
            .content {
                padding: 30px 20px;
            }
        }

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
                height: calc(100vh - 70px);
            }
            
            .chat-container {
                grid-template-columns: 1fr;
                max-height: calc(100vh - 110px);
            }
            
            /* Mobile: Show users list by default, hide chat */
            .users-section {
                display: flex;
                position: relative;
                z-index: 2;
            }
            
            .chat-section {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 3;
                transform: translateX(100%);
                transition: transform 0.3s ease;
            }
            
            .chat-section.active {
                transform: translateX(0);
            }
            
            .mobile-back-btn {
                display: block;
            }
            
            .message {
                max-width: 85%;
            }
            
            .user-avatar {
                width: 45px;
                height: 45px;
                font-size: 16px;
            }
            
            .message-avatar {
                width: 32px;
                height: 32px;
                font-size: 13px;
            }
            
            .chat-user-avatar {
                width: 40px;
                height: 40px;
                font-size: 15px;
            }
            
            .users-header h2 {
                font-size: 18px;
            }
            
            .chat-user-name {
                font-size: 16px;
            }
            
            .messages-container {
                padding: 15px;
            }
            
            .message-input-container {
                padding: 15px;
            }
        }

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
            
            .users-header {
                padding: 15px;
            }
            
            .users-list {
                padding: 5px;
            }
            
            .user-item {
                padding: 10px;
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
                font-size: 15px;
            }
            
            .user-name {
                font-size: 14px;
            }
            
            .last-message {
                font-size: 12px;
            }
            
            .chat-header {
                padding: 15px;
            }
            
            .messages-container {
                padding: 10px;
                gap: 10px;
            }
            
            .message {
                max-width: 90%;
            }
            
            .message-bubble {
                padding: 10px 14px;
                font-size: 13px;
            }
            
            .message-input-container {
                padding: 12px;
            }
            
            .message-input {
                padding: 10px 16px;
                font-size: 13px;
            }
            
            .send-btn {
                width: 40px;
                height: 40px;
            }
            
            .empty-chat i {
                font-size: 48px;
            }
            
            .empty-chat h3 {
                font-size: 18px;
            }
            
            .empty-chat p {
                font-size: 14px;
            }
        }

        @media (max-width: 360px) {
            .header-left h1 {
                font-size: 16px;
            }

            .message {
                max-width: 95%;
            }
            
            .user-avatar {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }
            
            .message-avatar {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="overlay" id="overlay"></div>
    <div class="container">
        <?php include '../partials/sidebar.php'; ?>
        <div class="main-content">
            <header class="header">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1>Chat</h1>
                </div>
                <div class="header-right">
                    <!-- Dark Mode Toggle -->
                    <div class="dark-mode-toggle" id="darkModeToggle">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </div>
                    <!-- Notification -->
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
                    <!-- Profile Dropdown -->
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
                <div class="chat-container">
                    <!-- Users List -->
                    <div class="users-section" id="usersSection">
                        <div class="users-header">
                            <h2>Messages</h2>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="userSearch" placeholder="Search conversations...">
                            </div>
                        </div>
                        <div class="users-list" id="usersList">
                            <?php 
                            $first_user = true;
                            while ($chat_user = $users_result->fetch_assoc()): 
                                if (empty($chat_user['initials'])) {
                                    $name_parts = explode(' ', $chat_user['name']);
                                    $chat_user['initials'] = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));
                                }
                            ?>
                            <div class="user-item <?php echo $first_user ? 'active' : ''; ?>" 
                                 data-user-id="<?php echo $chat_user['id']; ?>" 
                                 data-user-name="<?php echo htmlspecialchars($chat_user['name']); ?>"
                                 data-user-initials="<?php echo htmlspecialchars($chat_user['initials']); ?>"
                                 data-user-color="<?php echo htmlspecialchars($chat_user['avatar_color'] ?? '#667eea'); ?>"
                                 data-user-status="<?php echo $chat_user['status']; ?>"
                                 data-profile-picture="<?php echo !empty($chat_user['profile_picture']) ? htmlspecialchars($chat_user['profile_picture']) : ''; ?>">
                                <?php if (!empty($chat_user['profile_picture']) && file_exists('../../' . $chat_user['profile_picture'])): ?>
                                    <img src="../../<?php echo htmlspecialchars($chat_user['profile_picture']); ?>" alt="User" class="user-avatar">
                                <?php else: ?>
                                    <div class="user-avatar" style="background-color: <?php echo $chat_user['avatar_color'] ?? '#667eea'; ?>">
                                        <?php echo htmlspecialchars($chat_user['initials']); ?>
                                    </div>
                                <?php endif; ?>
                                <span class="status-indicator <?php echo $chat_user['status']; ?>"></span>
                                <div class="user-info">
                                    <div class="user-name"><?php echo htmlspecialchars($chat_user['name']); ?></div>
                                    <div class="last-message"><?php echo $chat_user['last_message'] ? htmlspecialchars(substr($chat_user['last_message'], 0, 30)) . '...' : 'No messages yet'; ?></div>
                                </div>
                                <div class="message-meta">
                                    <?php if ($chat_user['last_message_time']): ?>
                                        <div class="message-time"><?php echo date('g:i A', strtotime($chat_user['last_message_time'])); ?></div>
                                    <?php endif; ?>
                                    <?php if ($chat_user['unread_count'] > 0): ?>
                                        <span class="unread-badge"><?php echo $chat_user['unread_count']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php 
                                $first_user = false;
                            endwhile; 
                            ?>
                        </div>
                    </div>
                    <!-- Chat Section -->
                    <div class="chat-section" id="chatSection">
                        <div class="chat-header" id="chatHeader">
                            <div class="empty-chat">
                                <i class="fas fa-comments"></i>
                                <h3>Select a conversation</h3>
                                <p>Choose a user from the list to start chatting</p>
                            </div>
                        </div>
                        <div class="messages-container" id="messagesContainer" style="display: none;">
                            <!-- Messages will be loaded here -->
                        </div>
                        <div class="message-input-container" id="messageInputContainer" style="display: none;">
                            <div class="message-input-wrapper">
                                <textarea class="message-input" id="messageInput" placeholder="Type a message..." rows="1"></textarea>
                                <button class="send-btn" id="sendBtn" title="Send Message">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
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
        });
    </script>
    <script>
let currentChatUser = null;
let currentUserId = <?php echo $user_id; ?>;
let currentUserInitials = '<?php echo $user['initials']; ?>';
let currentUserColor = '<?php echo $user['avatar_color']; ?>';
let currentUserProfilePic = '<?php echo !empty($user['profile_picture']) ? addslashes($user['profile_picture']) : ''; ?>';
let messagePollingInterval = null;
let lastMessageId = 0;
let isMobile = window.innerWidth <= 768;

// Update isMobile on resize
window.addEventListener('resize', function() {
    isMobile = window.innerWidth <= 768;
});

document.addEventListener('DOMContentLoaded', function() {
    setupEventListeners();
    // Auto-select first user if exists (only on desktop)
    if (!isMobile) {
        const firstUser = document.querySelector('.user-item.active');
        if (firstUser) {
            selectUser(firstUser);
        }
    }
});

function setupEventListeners() {
    // User selection
    const userItems = document.querySelectorAll('.user-item');
    userItems.forEach(item => {
        item.addEventListener('click', function() {
            selectUser(this);
        });
    });
    
    // Search users
    const userSearch = document.getElementById('userSearch');
    if (userSearch) {
        userSearch.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            userItems.forEach(item => {
                const userName = item.getAttribute('data-user-name').toLowerCase();
                item.style.display = userName.includes(searchTerm) ? '' : 'none';
            });
        });
    }
    
    // Send message
    const sendBtn = document.getElementById('sendBtn');
    const messageInput = document.getElementById('messageInput');
    if (sendBtn) {
        sendBtn.addEventListener('click', sendMessage);
    }
    if (messageInput) {
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    }
}

function selectUser(userElement) {
    // Remove active class from all users
    document.querySelectorAll('.user-item').forEach(item => {
        item.classList.remove('active');
    });
    
    // Add active class to selected user
    userElement.classList.add('active');
    
    // Remove unread badge
    const badge = userElement.querySelector('.unread-badge');
    if (badge) {
        badge.remove();
    }
    
    // Get user data
    currentChatUser = {
        id: userElement.getAttribute('data-user-id'),
        name: userElement.getAttribute('data-user-name'),
        initials: userElement.getAttribute('data-user-initials'),
        color: userElement.getAttribute('data-user-color'),
        status: userElement.getAttribute('data-user-status'),
        profilePicture: userElement.getAttribute('data-profile-picture')
    };
    
    // On mobile, show chat section
    if (isMobile) {
        document.getElementById('chatSection').classList.add('active');
    }
    
    // Update chat header
    updateChatHeader();
    
    // Show chat interface
    const emptyChat = document.querySelector('.empty-chat');
    if (emptyChat) {
        emptyChat.style.display = 'none';
    }
    document.getElementById('messagesContainer').style.display = 'flex';
    document.getElementById('messageInputContainer').style.display = 'block';
    
    // Load messages
    loadMessages();
    
    // Start polling for new messages
    if (messagePollingInterval) {
        clearInterval(messagePollingInterval);
    }
    messagePollingInterval = setInterval(loadNewMessages, 2000);
    
    // Mark messages as read
    markMessagesAsRead();
}

function updateChatHeader() {
    const chatHeader = document.getElementById('chatHeader');
    const statusClass = currentChatUser.status || 'offline';
    const statusText = statusClass.charAt(0).toUpperCase() + statusClass.slice(1);
    
    let avatarHtml = '';
    if (currentChatUser.profilePicture) {
        avatarHtml = `<img src="../../${currentChatUser.profilePicture}" alt="User" class="chat-user-avatar">`;
    } else {
        avatarHtml = `<div class="chat-user-avatar" style="background-color: ${currentChatUser.color}">
            ${currentChatUser.initials}
        </div>`;
    }
    
    chatHeader.innerHTML = `
        <button class="mobile-back-btn" id="mobileBackBtn">
            <i class="fas fa-arrow-left"></i>
        </button>
        ${avatarHtml}
        <span class="status-indicator ${statusClass}"></span>
        <div class="chat-user-info">
            <div class="chat-user-name">${currentChatUser.name}</div>
            <div class="chat-user-status ${statusClass}">${statusText}</div>
        </div>
    `;
    
    // Add back button listener for mobile
    const mobileBackBtn = document.getElementById('mobileBackBtn');
    if (mobileBackBtn) {
        mobileBackBtn.addEventListener('click', function() {
            document.getElementById('chatSection').classList.remove('active');
            // Stop polling when going back
            if (messagePollingInterval) {
                clearInterval(messagePollingInterval);
            }
        });
    }
}

function loadMessages() {
    const formData = new FormData();
    formData.append('action', 'get_messages');
    formData.append('receiver_id', currentChatUser.id);
    formData.append('last_id', '0');
    
    fetch('../../api/chat.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
    })
    .then(response => response.json())
    .then(data => {
        if (data.messages) {
            displayMessages(data.messages);
            // Set lastMessageId to the highest ID in the messages
            if (data.messages.length > 0) {
                lastMessageId = Math.max(...data.messages.map(msg => msg.id));
            } else {
                lastMessageId = 0;
            }
        }
    })
    .catch(error => console.error('Error loading messages:', error));
}

function loadNewMessages() {
    if (!currentChatUser) return;
    
    const formData = new FormData();
    formData.append('action', 'get_messages');
    formData.append('receiver_id', currentChatUser.id);
    formData.append('last_id', lastMessageId.toString());
    
    fetch('../../api/chat.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
    })
    .then(response => response.json())
    .then(data => {
        if (data.messages && data.messages.length > 0) {
            appendNewMessages(data.messages);
            // Update lastMessageId to the highest ID in new messages
            const newMaxId = Math.max(...data.messages.map(msg => msg.id));
            if (newMaxId > lastMessageId) {
                lastMessageId = newMaxId;
            }
            markMessagesAsRead();
        }
    })
    .catch(error => console.error('Error loading new messages:', error));
}

function displayMessages(messages) {
    const container = document.getElementById('messagesContainer');
    container.innerHTML = '';
    let lastDate = '';
    messages.forEach(msg => {
        const msgDate = new Date(msg.time);
        const dateStr = msgDate.toDateString();
        if (dateStr !== lastDate) {
            container.innerHTML += `<div class="date-divider"><span>${formatDateForDisplay(msgDate)}</span></div>`;
            lastDate = dateStr;
        }
        const isSent = msg.sender_id == currentUserId;
        const messageClass = isSent ? 'sent' : 'received';
        const avatar = isSent ? currentUserInitials : currentChatUser.initials;
        const avatarColor = isSent ? currentUserColor : currentChatUser.color;
        const profilePic = isSent ? currentUserProfilePic : currentChatUser.profilePicture;
        
        let avatarHtml = '';
        if (profilePic) {
            avatarHtml = `<img src="../../${profilePic}" alt="User" class="message-avatar">`;
        } else {
            avatarHtml = `<div class="message-avatar" style="background-color: ${avatarColor}">${avatar}</div>`;
        }
        
        container.innerHTML += `
            <div class="message ${messageClass}">
                ${avatarHtml}
                <div class="message-content">
                    <div class="message-bubble">${escapeHtml(msg.message)}</div>
                    <div class="message-timestamp">${msg.time}</div>
                </div>
            </div>
        `;
    });
    scrollToBottom();
}

function appendNewMessages(messages) {
    const container = document.getElementById('messagesContainer');
    messages.forEach(msg => {
        const isSent = msg.sender_id == currentUserId;
        const messageClass = isSent ? 'sent' : 'received';
        const avatar = isSent ? currentUserInitials : currentChatUser.initials;
        const avatarColor = isSent ? currentUserColor : currentChatUser.color;
        const profilePic = isSent ? currentUserProfilePic : currentChatUser.profilePicture;
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${messageClass}`;
        
        let avatarHtml = '';
        if (profilePic) {
            avatarHtml = `<img src="../../${profilePic}" alt="User" class="message-avatar">`;
        } else {
            avatarHtml = `<div class="message-avatar" style="background-color: ${avatarColor}">${avatar}</div>`;
        }
        
        messageDiv.innerHTML = `
            ${avatarHtml}
            <div class="message-content">
                <div class="message-bubble">${escapeHtml(msg.message)}</div>
                <div class="message-timestamp">${msg.time}</div>
            </div>
        `;
        container.appendChild(messageDiv);
    });
    scrollToBottom();
}

function sendMessage() {
    const messageInput = document.getElementById('messageInput');
    const messageText = messageInput.value.trim();
    if (messageText === '' || !currentChatUser) return;
    
    const sendBtn = document.getElementById('sendBtn');
    sendBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('receiver_id', currentChatUser.id);
    formData.append('message', messageText);
    
    fetch('../../api/chat.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            messageInput.value = '';
            messageInput.style.height = 'auto';
            // The message will be loaded in the next polling cycle
        } else {
            alert('Failed to send message: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to send message');
    })
    .finally(() => {
        sendBtn.disabled = false;
        messageInput.focus();
    });
}

function markMessagesAsRead() {
    if (!currentChatUser) return;
    
    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('receiver_id', currentChatUser.id);
    
    fetch('../../api/chat.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
    })
    .then(response => response.json())
    .catch(error => console.error('Error marking messages as read:', error));
}

function scrollToBottom() {
    const container = document.getElementById('messagesContainer');
    container.scrollTop = container.scrollHeight;
}

function formatDateForDisplay(date) {
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    
    if (date.toDateString() === today.toDateString()) {
        return 'Today';
    } else if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    } else {
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (messagePollingInterval) {
        clearInterval(messagePollingInterval);
    }
});
    </script>
</body>
</html>