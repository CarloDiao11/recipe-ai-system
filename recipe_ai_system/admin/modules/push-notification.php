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

// Fetch all users for sending notifications
$users_query = "SELECT id, username, name, profile_picture, initials, avatar_color FROM users ORDER BY name ASC";
$users_result = $conn->query($users_query);

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

// Fetch sent notifications history (with created_by field)
$history_query = "SELECT n.*, u.name as recipient_name, u.username as recipient_username 
                  FROM notifications n 
                  JOIN users u ON n.user_id = u.id 
                  ORDER BY n.created_at DESC 
                  LIMIT 20";
$notification_history = $conn->query($history_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flavor Forge - Push Notifications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/header.css">

    <style>
        .content {
            padding: 40px;
            background: var(--bg-secondary);
        }

        .notification-form-card {
            background: var(--bg-primary);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
            margin-bottom: 30px;
        }

        .notification-form-card h2 {
            color: var(--text-primary);
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notification-form-card h2 i {
            color: var(--primary);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: var(--text-primary);
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .notification-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }

        .type-option {
            padding: 15px;
            border: 2px solid var(--border);
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .type-option:hover {
            border-color: var(--primary);
            background: rgba(102, 126, 234, 0.05);
        }

        .type-option.active {
            border-color: var(--primary);
            background: rgba(102, 126, 234, 0.1);
        }

        .type-option i {
            font-size: 24px;
            margin-bottom: 8px;
            display: block;
        }

        .type-option label {
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
            margin: 0;
        }

        .recipient-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            max-height: 300px;
            overflow-y: auto;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-secondary);
        }

        .recipient-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .recipient-option:hover {
            background: var(--bg-primary);
        }

        .recipient-option input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .recipient-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
        }

        .recipient-initials {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 12px;
        }

        .recipient-info {
            flex: 1;
        }

        .recipient-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 13px;
        }

        .recipient-username {
            font-size: 11px;
            color: var(--text-secondary);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 12px 30px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary:hover {
            background: var(--bg-primary);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .history-card {
            background: var(--bg-primary);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
        }

        .history-card h3 {
            color: var(--text-primary);
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .history-item {
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .history-item:hover {
            box-shadow: 0 2 10px var(--shadow);
        }

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .history-title {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 15px;
        }

        .history-type {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .history-type.recipe {
            background: #fef3c7;
            color: #92400e;
        }

        .history-type.comment {
            background: #dbeafe;
            color: #1e40af;
        }

        .history-type.like {
            background: #fce7f3;
            color: #9f1239;
        }

        .history-type.follower {
            background: #dcfce7;
            color: #166534;
        }

        .history-type.message {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .history-message {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 8px;
        }

        .history-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .history-recipient {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .alert i {
            font-size: 18px;
        }

        .permission-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .permission-text {
            flex: 1;
        }

        .permission-text h4 {
            margin: 0 0 5px 0;
            font-size: 16px;
        }

        .permission-text p {
            margin: 0;
            opacity: 0.9;
            font-size: 13px;
        }

        .btn-permission {
            background: white;
            color: #667eea;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-permission:hover {
            transform: scale(1.05);
        }

        .select-all-container {
            padding: 10px;
            background: var(--bg-primary);
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .select-all-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .select-all-container label {
            font-weight: 600;
            color: var(--text-primary);
            cursor: pointer;
            margin: 0;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 24px;
            cursor: pointer;
            padding: 8px;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
            }
            .profile-info {
                display: none;
            }
            .content {
                padding: 20px;
            }
            .form-actions {
                flex-direction: column;
            }
            .btn-primary, .btn-secondary {
                width: 100%;
                justify-content: center;
            }
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
                    <h1>Push Notifications</h1>
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
                                <?php 
                                $notifications->data_seek(0); // Reset pointer
                                while ($notif = $notifications->fetch_assoc()): 
                                ?>
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
                <div class="permission-card" id="permissionCard" style="display: none;">
                    <div class="permission-text">
                        <h4><i class="fas fa-bell"></i> Enable Browser Push Notifications</h4>
                        <p>Get real-time notifications even when you're not on this page!</p>
                    </div>
                    <button class="btn-permission" onclick="requestNotificationPermission()">
                        Enable Now
                    </button>
                </div>

                <div id="alertContainer"></div>

                <div class="notification-form-card">
                    <h2><i class="fas fa-paper-plane"></i> Send Push Notification</h2>
                    
                    <form id="notificationForm">
                        <div class="form-group">
                            <label for="notificationTitle">Notification Title *</label>
                            <input type="text" id="notificationTitle" name="title" required placeholder="e.g., New Recipe Alert!">
                        </div>

                        <div class="form-group">
                            <label for="notificationMessage">Message *</label>
                            <textarea id="notificationMessage" name="message" required placeholder="Enter your notification message..."></textarea>
                        </div>

                        <div class="form-group">
                            <label>Notification Type *</label>
                            <div class="notification-type-grid">
                                <div class="type-option" onclick="selectType('recipe', this)">
                                    <i class="fas fa-utensils" style="color: #f59e0b;"></i>
                                    <label>Recipe</label>
                                </div>
                                <div class="type-option" onclick="selectType('comment', this)">
                                    <i class="fas fa-comment" style="color: #3b82f6;"></i>
                                    <label>Comment</label>
                                </div>
                                <div class="type-option" onclick="selectType('like', this)">
                                    <i class="fas fa-heart" style="color: #ef4444;"></i>
                                    <label>Like</label>
                                </div>
                                <div class="type-option" onclick="selectType('follower', this)">
                                    <i class="fas fa-user-plus" style="color: #10b981;"></i>
                                    <label>Follower</label>
                                </div>
                                <div class="type-option active" onclick="selectType('message', this)">
                                    <i class="fas fa-envelope" style="color: #8b5cf6;"></i>
                                    <label>Message</label>
                                </div>
                            </div>
                            <input type="hidden" id="notificationType" name="type" value="message">
                        </div>

                        <div class="form-group">
                            <label>Select Recipients *</label>
                            <div class="select-all-container">
                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                <label for="selectAll">Select All Users</label>
                            </div>
                            <div class="recipient-grid">
                                <?php 
                                $users_result->data_seek(0); // Reset pointer
                                while ($recipient = $users_result->fetch_assoc()): 
                                ?>
                                    <?php if ($recipient['id'] != $user_id): ?>
                                    <div class="recipient-option">
                                        <input type="checkbox" name="recipients[]" value="<?php echo $recipient['id']; ?>" id="user_<?php echo $recipient['id']; ?>">
                                        <label for="user_<?php echo $recipient['id']; ?>" style="display: flex; align-items: center; gap: 10px; cursor: pointer; flex: 1;">
                                            <?php if (!empty($recipient['profile_picture']) && file_exists('../../' . $recipient['profile_picture'])): ?>
                                                <img src="../../<?php echo htmlspecialchars($recipient['profile_picture']); ?>" alt="User" class="recipient-avatar">
                                            <?php else: ?>
                                                <div class="recipient-initials" style="background-color: <?php echo $recipient['avatar_color'] ?? '#667eea'; ?>">
                                                    <?php echo htmlspecialchars($recipient['initials'] ?? 'U'); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="recipient-info">
                                                <div class="recipient-name"><?php echo htmlspecialchars($recipient['name']); ?></div>
                                                <div class="recipient-username">@<?php echo htmlspecialchars($recipient['username']); ?></div>
                                            </div>
                                        </label>
                                    </div>
                                    <?php endif; ?>
                                <?php endwhile; ?>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-paper-plane"></i>
                                Send Push Notification
                            </button>
                            <button type="button" class="btn-secondary" onclick="testBrowserNotification()">
                                <i class="fas fa-vial"></i>
                                Test Browser Notification
                            </button>
                            <button type="reset" class="btn-secondary" onclick="resetForm()">
                                <i class="fas fa-redo"></i>
                                Reset Form
                            </button>
                        </div>
                    </form>
                </div>

                <div class="history-card">
                    <h3><i class="fas fa-history"></i> Recent Notifications Sent</h3>
                    <div>
                        <?php if ($notification_history->num_rows > 0): ?>
                            <?php while ($history = $notification_history->fetch_assoc()): ?>
                            <div class="history-item">
                                <div class="history-header">
                                    <div class="history-title"><?php echo htmlspecialchars($history['title']); ?></div>
                                    <span class="history-type <?php echo $history['type']; ?>">
                                        <?php echo strtoupper($history['type']); ?>
                                    </span>
                                </div>
                                <div class="history-message"><?php echo htmlspecialchars($history['message']); ?></div>
                                <div class="history-footer">
                                    <div class="history-recipient">
                                        <i class="fas fa-user"></i>
                                        <span><?php echo htmlspecialchars($history['recipient_name']); ?> (@<?php echo htmlspecialchars($history['recipient_username']); ?>)</span>
                                    </div>
                                    <div>
                                        <i class="fas fa-clock"></i>
                                        <?php echo date('M d, Y h:i A', strtotime($history['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                                <p>No notification history yet. Send your first push notification!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Check notification permission on load
            checkNotificationPermission();
            
            // Dark Mode Toggle
            const darkModeToggle = document.getElementById('darkModeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const html = document.documentElement;
            
            const currentTheme = localStorage.getItem('theme') || 'light';
            html.setAttribute('data-theme', currentTheme);
            if (currentTheme === 'dark') {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }
            
            if (darkModeToggle) {
                darkModeToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const isDark = html.getAttribute('data-theme') === 'dark';
                    const newTheme = isDark ? 'light' : 'dark';
                    
                    html.setAttribute('data-theme', newTheme);
                    localStorage.setItem('theme', newTheme);
                    
                    if (newTheme === 'dark') {
                        themeIcon.classList.remove('fa-sun');
                        themeIcon.classList.add('fa-moon');
                    } else {
                        themeIcon.classList.remove('fa-moon');
                        themeIcon.classList.add('fa-sun');
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

            // Notification Dropdown
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
                    
                    if (dropdownMenu) {
                        dropdownMenu.classList.remove('show');
                        if (dropdownIcon) dropdownIcon.classList.remove('open');
                    }
                });
            }

            // Profile Dropdown
            if (profileButton && dropdownMenu && dropdownIcon) {
                profileButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropdownMenu.classList.toggle('show');
                    dropdownIcon.classList.toggle('open');
                    
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

            // Form Submission
            document.getElementById('notificationForm').addEventListener('submit', function(e) {
                e.preventDefault();
                sendNotification();
            });
        });

        // Check Notification Permission
        function checkNotificationPermission() {
            if (!("Notification" in window)) {
                showAlert('❌ Browser notifications are not supported on this browser', 'error');
                return;
            }

            if (Notification.permission === 'default') {
                document.getElementById('permissionCard').style.display = 'flex';
            } else if (Notification.permission === 'denied') {
                showAlert('⚠️ Browser notifications are blocked. Please enable them in your browser settings.', 'error');
            } else if (Notification.permission === 'granted') {
                console.log('✅ Notifications are enabled');
            }
        }

        // Request Notification Permission
        function requestNotificationPermission() {
            if (!("Notification" in window)) {
                showAlert('❌ Browser notifications are not supported', 'error');
                return;
            }

            Notification.requestPermission().then(function(permission) {
                if (permission === 'granted') {
                    document.getElementById('permissionCard').style.display = 'none';
                    showAlert('✅ Browser notifications enabled successfully!', 'success');
                    
                    // Show a welcome notification
                    new Notification('🎉 Flavor Forge', {
                        body: 'Push notifications are now enabled! You will receive real-time alerts.',
                        icon: '/flavor/uploads/logo.png',
                        badge: '/flavor/uploads/logo.png',
                        tag: 'welcome-notification',
                        requireInteraction: false,
                        vibrate: [200, 100, 200]
                    });
                } else {
                    showAlert('❌ Notification permission denied', 'error');
                }
            });
        }

        // Select Notification Type
        function selectType(type, element) {
            document.querySelectorAll('.type-option').forEach(opt => opt.classList.remove('active'));
            element.classList.add('active');
            document.getElementById('notificationType').value = type;
        }

        // Toggle Select All Recipients
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('input[name="recipients[]"]');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        // Test Browser Notification
        function testBrowserNotification() {
            if (Notification.permission !== 'granted') {
                showAlert('⚠️ Please enable browser notifications first', 'error');
                requestNotificationPermission();
                return;
            }

            const title = document.getElementById('notificationTitle').value || '🧪 Test Notification';
            const message = document.getElementById('notificationMessage').value || 'This is a test push notification from Flavor Forge!';
            const type = document.getElementById('notificationType').value;

            // Icon based on type
            const icons = {
                'recipe': '🍳',
                'comment': '💬',
                'like': '❤️',
                'follower': '👤',
                'message': '✉️'
            };

            // Show browser notification
            const notification = new Notification(icons[type] + ' ' + title, {
                body: message,
                icon: '/flavor/uploads/logo.png',
                badge: '/flavor/uploads/logo.png',
                tag: 'test-notification-' + Date.now(),
                requireInteraction: false,
                vibrate: [200, 100, 200],
                timestamp: Date.now()
            });

            notification.onclick = function() {
                window.focus();
                notification.close();
            };

            // Auto close after 10 seconds
            setTimeout(() => {
                notification.close();
            }, 10000);

            showAlert('✅ Test notification sent to your browser!', 'success');
        }

        // Send Notification
        function sendNotification() {
            const title = document.getElementById('notificationTitle').value;
            const message = document.getElementById('notificationMessage').value;
            const type = document.getElementById('notificationType').value;
            const recipients = Array.from(document.querySelectorAll('input[name="recipients[]"]:checked')).map(cb => cb.value);

            if (recipients.length === 0) {
                showAlert('⚠️ Please select at least one recipient', 'error');
                return;
            }

            // Show loading state
            const submitBtn = document.querySelector('.btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            submitBtn.disabled = true;

            // Send via AJAX
            fetch('send_notification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    title: title,
                    message: message,
                    type: type,
                    recipients: recipients
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(`✅ Notification sent successfully to ${recipients.length} user(s)!`, 'success');
                    
                    // Show browser notification if permitted
                    if (Notification.permission === 'granted') {
                        const icons = {
                            'recipe': '🍳',
                            'comment': '💬',
                            'like': '❤️',
                            'follower': '👤',
                            'message': '✉️'
                        };
                        
                        new Notification(icons[type] + ' Notification Sent Successfully!', {
                            body: `Your notification has been sent to ${recipients.length} user(s). They will receive it in their browser!`,
                            icon: '/flavor/uploads/logo.png',
                            badge: '/flavor/uploads/logo.png',
                            tag: 'notification-sent-' + Date.now(),
                            vibrate: [200, 100, 200]
                        });
                    }
                    
                    // Reset form
                    resetForm();
                    
                    // Reload page after 2 seconds to show new history
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showAlert('❌ ' + (data.message || 'Failed to send notification'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('❌ An error occurred while sending notification', 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        // Reset Form
        function resetForm() {
            document.getElementById('notificationForm').reset();
            document.getElementById('notificationType').value = 'message';
            document.querySelectorAll('.type-option').forEach(opt => opt.classList.remove('active'));
            document.querySelector('.type-option:nth-child(5)').classList.add('active');
            document.getElementById('selectAll').checked = false;
        }

        // Show Alert
        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
            const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            
            const alert = document.createElement('div');
            alert.className = `alert ${alertClass}`;
            alert.innerHTML = `
                <i class="fas ${icon}"></i>
                <span>${message}</span>
            `;
            
            alertContainer.innerHTML = '';
            alertContainer.appendChild(alert);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        }

        // Check for new notifications every 30 seconds (for receiving notifications)
        setInterval(function() {
            if (Notification.permission === 'granted') {
                fetch('check_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.notifications && data.notifications.length > 0) {
                            data.notifications.forEach(notif => {
                                const icons = {
                                    'recipe': '🍳',
                                    'comment': '💬',
                                    'like': '❤️',
                                    'follower': '👤',
                                    'message': '✉️'
                                };
                                
                                const notification = new Notification(icons[notif.type] + ' ' + notif.title, {
                                    body: notif.message,
                                    icon: '/flavor/uploads/logo.png',
                                    badge: '/flavor/uploads/logo.png',
                                    tag: 'notif-' + notif.id,
                                    requireInteraction: true,
                                    vibrate: [200, 100, 200],
                                    timestamp: Date.now()
                                });

                                notification.onclick = function() {
                                    window.focus();
                                    // Mark as read
                                    fetch('mark_notification_read.php', {
                                        method: 'POST',
                                        headers: {'Content-Type': 'application/json'},
                                        body: JSON.stringify({notification_id: notif.id})
                                    });
                                    notification.close();
                                };
                            });
                        }
                    })
                    .catch(error => console.error('Error checking notifications:', error));
            }
        }, 30000); // Check every 30 seconds
    </script>
</body>
</html>