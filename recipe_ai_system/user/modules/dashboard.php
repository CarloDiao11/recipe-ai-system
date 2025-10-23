<?php
session_start();
require_once '../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user information - FIXED: Added profile_picture field
$stmt = $conn->prepare("SELECT id, username, name, email, profile_picture, initials, avatar_color, role, status FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Generate initials if not set
if (empty($current_user['initials'])) {
    $names = explode(' ', $current_user['name']);
    $current_user['initials'] = strtoupper(substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
}

// Set avatar color if not set
if (empty($current_user['avatar_color'])) {
    $colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E2'];
    $current_user['avatar_color'] = $colors[array_rand($colors)];
}

// Fetch unread notifications
$stmt = $conn->prepare("
    SELECT 
        n.id, 
        n.type, 
        n.title, 
        n.message, 
        n.is_read, 
        n.related_id,
        n.created_at,
        CASE 
            WHEN TIMESTAMPDIFF(MINUTE, n.created_at, NOW()) < 1 THEN 'Just now'
            WHEN TIMESTAMPDIFF(MINUTE, n.created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, n.created_at, NOW()), ' minutes ago')
            WHEN TIMESTAMPDIFF(HOUR, n.created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, n.created_at, NOW()), ' hours ago')
            WHEN TIMESTAMPDIFF(DAY, n.created_at, NOW()) = 1 THEN 'Yesterday'
            ELSE CONCAT(TIMESTAMPDIFF(DAY, n.created_at, NOW()), ' days ago')
        END as time_ago
    FROM notifications n
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 10
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Count unread notifications
$stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = FALSE");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_count = $stmt->get_result()->fetch_assoc()['unread_count'];
$stmt->close();

// Get notification icon and color based on type
function getNotificationIcon($type) {
    $icons = [
        'recipe' => ['icon' => 'fa-utensils', 'color' => '#FF6B6B'],
        'comment' => ['icon' => 'fa-comment', 'color' => '#4ECDC4'],
        'follower' => ['icon' => 'fa-user-plus', 'color' => '#6f42c1'],
        'like' => ['icon' => 'fa-heart', 'color' => '#20c997'],
        'message' => ['icon' => 'fa-envelope', 'color' => '#45B7D1']
    ];
    return $icons[$type] ?? ['icon' => 'fa-bell', 'color' => '#6c757d'];
}

// Fetch all users for chat - FIXED: Added profile_picture field
$stmt = $conn->prepare("SELECT id, name, profile_picture, initials, avatar_color, status FROM users WHERE id != ? ORDER BY status DESC, name ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$all_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch recent chats
$stmt = $conn->prepare("
    SELECT DISTINCT
        u.id,
        u.name,
        u.profile_picture,
        u.initials,
        u.avatar_color,
        u.status,
        (SELECT message_text FROM chat_messages 
         WHERE (sender_id = ? AND receiver_id = u.id) 
            OR (sender_id = u.id AND receiver_id = ?)
         ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT created_at FROM chat_messages 
         WHERE (sender_id = ? AND receiver_id = u.id) 
            OR (sender_id = u.id AND receiver_id = ?)
         ORDER BY created_at DESC LIMIT 1) as last_message_time
    FROM users u
    WHERE u.id != ?
    AND EXISTS (
        SELECT 1 FROM chat_messages 
        WHERE (sender_id = ? AND receiver_id = u.id) 
           OR (sender_id = u.id AND receiver_id = ?)
    )
    ORDER BY last_message_time DESC
    LIMIT 10
");
$stmt->bind_param("iiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$recent_chats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch posts with comments count and user info
$stmt = $conn->prepare("
    SELECT 
        p.id,
        p.content,
        p.media_type,
        p.media_url,
        p.likes_count,
        p.comments_count,
        p.created_at,
        u.name,
        u.profile_picture,
        u.initials,
        u.avatar_color,
        CASE 
            WHEN TIMESTAMPDIFF(MINUTE, p.created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, p.created_at, NOW()), ' mins ago')
            WHEN TIMESTAMPDIFF(HOUR, p.created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, p.created_at, NOW()), ' hours ago')
            WHEN TIMESTAMPDIFF(DAY, p.created_at, NOW()) = 1 THEN 'Yesterday'
            ELSE CONCAT(TIMESTAMPDIFF(DAY, p.created_at, NOW()), ' days ago')
        END as time_ago,
        (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id AND user_id = ?) as user_liked
    FROM posts p
    JOIN users u ON p.user_id = u.id
    ORDER BY p.created_at DESC
    LIMIT 20
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch all comments for display
$all_comments = [];
if (!empty($posts)) {
    $post_ids = array_column($posts, 'id');
    $placeholders = str_repeat('?,', count($post_ids) - 1) . '?';
    
    $stmt = $conn->prepare("
        SELECT 
            c.id,
            c.post_id,
            c.user_id,
            c.comment_text,
            c.created_at,
            u.name,
            u.profile_picture,
            u.initials,
            u.avatar_color,
            CASE 
                WHEN TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, c.created_at, NOW()), ' mins ago')
                WHEN TIMESTAMPDIFF(HOUR, c.created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, c.created_at, NOW()), ' hours ago')
                WHEN TIMESTAMPDIFF(DAY, c.created_at, NOW()) = 1 THEN 'Yesterday'
                ELSE CONCAT(TIMESTAMPDIFF(DAY, c.created_at, NOW()), ' days ago')
            END as time_ago
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id IN ($placeholders)
        ORDER BY c.created_at ASC
    ");
    $types = str_repeat('i', count($post_ids));
    $stmt->bind_param($types, ...$post_ids);
    $stmt->execute();
    $all_comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Organize comments by post_id
$comments_by_post = [];
foreach ($all_comments as $comment) {
    $comments_by_post[$comment['post_id']][] = $comment;
}

// Fetch user's meal plan for current week
$week_start = date('Y-m-d', strtotime('monday this week'));
$stmt = $conn->prepare("
    SELECT 
        mp.id,
        mp.day_of_week,
        mp.meal_type,
        r.title as recipe_title,
        r.id as recipe_id
    FROM meal_plans mp
    LEFT JOIN recipes r ON mp.recipe_id = r.id
    WHERE mp.user_id = ? AND mp.week_start_date = ?
    ORDER BY 
        FIELD(mp.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
        FIELD(mp.meal_type, 'Breakfast', 'Lunch', 'Dinner')
");
$stmt->bind_param("is", $user_id, $week_start);
$stmt->execute();
$meal_plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Format time ago for recent chats
foreach ($recent_chats as &$chat) {
    if ($chat['last_message_time']) {
        $time_diff = time() - strtotime($chat['last_message_time']);
        if ($time_diff < 60) {
            $chat['time_ago'] = 'Just now';
        } elseif ($time_diff < 3600) {
            $chat['time_ago'] = floor($time_diff / 60) . 'm ago';
        } elseif ($time_diff < 86400) {
            $chat['time_ago'] = floor($time_diff / 3600) . 'h ago';
        } else {
            $chat['time_ago'] = floor($time_diff / 86400) . 'd ago';
        }
    } else {
        $chat['time_ago'] = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Recipe Generator - Flavor Forge</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/header.css">
    <link rel="stylesheet" href="../assets/css/main_content.css">
    <link rel="stylesheet" href="../assets/css/hero.css">
    <link rel="stylesheet" href="../assets/css/chatpopup.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
    .recipe-media, .recipe-media-full {
        margin: 1rem 0;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .recipe-image, .recipe-image-full {
        width: 100%;
        height: auto;
        object-fit: cover;
        max-height: 300px;
        display: block;
    }

    .recipe-video, .recipe-video-full {
        width: 100%;
        height: auto;
        max-height: 300px;
        background: #000;
        display: block;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .container {
            grid-template-columns: 1fr;
        }
        .hero-title {
            font-size: 2rem;
        }
        .hero-subtitle {
            font-size: 1rem;
        }
        .header-nav {
            display: none;
        }
        .mobile-menu-btn {
            display: block;
        }
        .logo {
            font-size: 1.2rem;
        }
        .notification-dropdown {
            right: 0;
            min-width: 300px;
        }
        .header-actions {
            gap: 0.5rem;
        }
        .user-profile-btn span {
            display: none;
        }
        .scroll-top-btn {
            bottom: 20px;
            right: 20px;
            width: 45px;
            height: 45px;
        }
        .meal-planner-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
</head>
<body>
    <!-- HEADER START -->
    <div class="header">
        <div class="logo">
            <i class="fas fa-fire"></i>
            Flavor Forge
        </div>
        <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
        <nav class="header-nav">
            <a href="#ai-generator" class="nav-link active">
                <i class="fas fa-robot"></i>
                AI Generator
            </a>
            <a href="#chat" class="nav-link">
                <i class="fas fa-comments"></i>
                Chat
            </a>
            <a href="#meal-planner" class="nav-link">
                <i class="fas fa-calendar-alt"></i>
                Meal Planner
            </a>
            <a href="#community" class="nav-link">
                <i class="fas fa-users"></i>
                Community Posts
            </a>
        </nav>
        <div class="header-actions">
            <button class="theme-toggle" onclick="toggleTheme()">
                <i class="fas fa-moon"></i>
            </button>
            <button class="notification-btn" onclick="toggleNotifications()">
                <i class="fas fa-bell"></i>
                <?php if ($unread_count > 0): ?>
                <span class="notification-badge"><?= $unread_count ?></span>
                <?php endif; ?>
            </button>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <span>Notifications</span>
                    <button class="mark-all-read" onclick="markAllRead()">Mark all read</button>
                </div>
                <div class="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
                            <i class="fas fa-bell-slash" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                            <p>No notifications yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <?php $icon_data = getNotificationIcon($notif['type']); ?>
                            <div class="notification-item <?= !$notif['is_read'] ? 'unread' : '' ?>" 
                                 data-notification-id="<?= $notif['id'] ?>"
                                 onclick="markAsRead(<?= $notif['id'] ?>)">
                                <div class="notification-icon" style="background: <?= $icon_data['color'] ?>;">
                                    <i class="fas <?= $icon_data['icon'] ?>"></i>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                                    <div class="notification-text"><?= htmlspecialchars($notif['message']) ?></div>
                                    <div class="notification-time"><?= $notif['time_ago'] ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-menu">
                <button class="user-profile-btn" onclick="toggleUserMenu()">
                    <div class="user-avatar-header" style="background: <?= $current_user['avatar_color'] ?>;">
                        <?php if (!empty($current_user['profile_picture']) && $current_user['profile_picture'] !== 'uploads/default_profile.png' && file_exists('../../' . $current_user['profile_picture'])): ?>
                            <img src="../../<?= htmlspecialchars($current_user['profile_picture']) ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <?= htmlspecialchars($current_user['initials']) ?>
                        <?php endif; ?>
                    </div>
                    <span style="color: var(--text-primary); font-weight: 600;">
                        <?= htmlspecialchars($current_user['name']) ?>
                    </span>
                    <i class="fas fa-chevron-down" style="color: var(--text-secondary); font-size: 0.8rem;"></i>
                </button>
                <div class="dropdown-menu" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar" style="background: <?= $current_user['avatar_color'] ?>;">
                            <?php if (!empty($current_user['profile_picture']) && $current_user['profile_picture'] !== 'uploads/default_profile.png' && file_exists('../../' . $current_user['profile_picture'])): ?>
                                <img src="../../<?= htmlspecialchars($current_user['profile_picture']) ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?= htmlspecialchars($current_user['initials']) ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-weight: 600;"><?= htmlspecialchars($current_user['name']) ?></div>
                        <div style="font-size: 0.9rem; opacity: 0.9;"><?= htmlspecialchars($current_user['email']) ?></div>
                    </div>
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user"></i>
                        <span>My Profile</span>
                    </a>
                    <a href="saved-recipes.php" class="dropdown-item">
                        <i class="fas fa-heart"></i>
                        <span>Saved Recipes</span>
                    </a>
                    <div style="border-top: 2px solid var(--border-color); margin: 0.5rem 0;"></div>
                    <button class="dropdown-item logout" onclick="openLogoutModal()" style="background: none; border: none; width: 100%; text-align: left; padding: 0.5rem 1rem; cursor: pointer;">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <a href="#ai-generator" class="nav-link active" onclick="closeMobileMenu()">
            <i class="fas fa-robot"></i>
            AI Generator
        </a>
        <a href="#chat" class="nav-link" onclick="closeMobileMenu()">
            <i class="fas fa-comments"></i>
            Chat
        </a>
        <a href="#meal-planner" class="nav-link" onclick="closeMobileMenu()">
            <i class="fas fa-calendar-alt"></i>
            Meal Planner
        </a>
        <a href="#community" class="nav-link" onclick="closeMobileMenu()">
            <i class="fas fa-users"></i>
            Community Posts
        </a>
    </div>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="modal" style="display: none;">
        <div class="modal-content">
            <i class="fas fa-sign-out-alt"></i>
            <h3>Confirm Logout</h3>
            <p>Are you sure you want to log out of your account?</p>
            <div class="modal-button-group">
                <button class="btn-secondary" onclick="closeLogoutModal()">Cancel</button>
                <button class="btn-primary" onclick="confirmLogout()">Yes, Logout</button>
            </div>
        </div>
    </div>

    <style>
    /* Logout Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 2000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s ease-in-out;
    }

    .modal.active {
        display: flex !important;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    .modal-content {
        background: var(--bg-secondary);
        border-radius: 12px;
        padding: 2rem;
        max-width: 400px;
        width: 90%;
        text-align: center;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        animation: slideUp 0.3s ease-out;
    }

    @keyframes slideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .modal-content i {
        font-size: 3rem;
        color: var(--accent-orange);
        margin-bottom: 1rem;
        display: block;
    }

    .modal-content h3 {
        font-size: 1.5rem;
        color: var(--text-primary);
        margin: 0.5rem 0 1rem 0;
        font-weight: 700;
    }

    .modal-content p {
        margin: 1rem 0;
        color: var(--text-secondary);
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .modal-button-group {
        display: flex;
        gap: 0.75rem;
        justify-content: center;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }

    /* Primary Button */
    .btn-primary {
        background: var(--accent-orange);
        color: white;
        border: none;
        border-radius: 6px;
        padding: 0.6rem 1.2rem;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.95rem;
        transition: all 0.2s ease;
        min-width: 100px;
    }

    .btn-primary:hover {
        background: #e05a2d;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
    }

    .btn-primary:active {
        transform: translateY(0);
    }

    /* Secondary Button */
    .btn-secondary {
        background: var(--bg-primary);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 0.6rem 1.2rem;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.95rem;
        transition: all 0.2s ease;
        min-width: 100px;
    }

    .btn-secondary:hover {
        background: var(--border-color);
        border-color: var(--text-secondary);
        transform: translateY(-2px);
    }

    .btn-secondary:active {
        transform: translateY(0);
    }

    /* Dark mode adjustments */
    [data-theme="dark"] .modal-content {
        background: var(--bg-secondary);
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    }

    [data-theme="dark"] .btn-secondary {
        background: rgba(255, 255, 255, 0.05);
        border-color: rgba(255, 255, 255, 0.1);
    }

    [data-theme="dark"] .btn-secondary:hover {
        background: rgba(255, 255, 255, 0.1);
        border-color: rgba(255, 255, 255, 0.2);
    }

    /* Responsive Design */
    @media (max-width: 480px) {
        .modal-content {
            width: 95%;
            padding: 1.5rem;
        }

        .modal-content i {
            font-size: 2.5rem;
        }

        .modal-content h3 {
            font-size: 1.25rem;
        }

        .modal-button-group {
            flex-direction: column;
            gap: 0.5rem;
        }

        .btn-primary,
        .btn-secondary {
            width: 100%;
        }
    }
    </style>
    <!-- HEADER END -->

    <!--include for hero "Got Ingredients? We've Got Recipes!"-->
    <?php include 'hero.php';?>
    <!--the main content ai generator recipe-->    
    <?php include 'ai_generator.php'?>
    <!--list of user and chat-->
    <?php include 'chat.php'; ?>
    <!--meal planner of user-->
    <?php include 'meal_planner.php'; ?>
    <!--post of other user to share recipe-->
    <?php include 'community_post.php'; ?>
    <!--this is sidebar that you can post your recipe-->
    <?php include 'sidebar_post.php'; ?>
    <!--if user going down scrolltopbtn show on the right side bottom-->
    <?php include '../includes/scroll_top_btn.php'; ?>
    <!--if user click the chat we will popup this in right side bottom-->
    <?php include 'chat_popup_window.php'; ?>

    <!--script for navigation hover-->
    <script src="../assets/js/navigation.js"></script>
    <script src="../assets/js/scroll_top_btn.js"></script>
    <script src="../../assets/js/header.js"></script>

    <script>
        // Pass PHP data to JavaScript
    const currentUser = <?= json_encode($current_user) ?>;
    const allUsersData = <?= json_encode($all_users) ?>;
    const recentChatsData = <?= json_encode($recent_chats) ?>;
    const postsData = <?= json_encode($posts) ?>;
    const mealPlansData = <?= json_encode($meal_plans) ?>;

    let currentTheme = 'light';
    let currentChatUser = null;
    let currentMedia = null;

    // Post data storage - Initialize from PHP data
    const postLikes = {};
    postsData.forEach(post => {
        postLikes[post.id] = { 
            liked: post.user_liked > 0, 
            count: post.likes_count 
        };
    });
    const postComments = {};
    postsData.forEach(post => {
        postComments[post.id] = post.comments_count;
    });

    // === CHAT INITIALIZATION ===
// Initialize users from PHP data
const users = allUsersData.map(user => ({
    id: user.id,
    name: user.name,
    initials: user.initials || user.name.split(' ').map(n => n[0]).join('').toUpperCase(),
    status: user.status || 'offline',
    color: user.avatar_color || '#6f42c1'
}));

// Recent chats from PHP data
const recentChats = recentChatsData.map(chat => ({
    user: {
        id: chat.id,
        name: chat.name,
        initials: chat.initials,
        status: chat.status,
        color: chat.avatar_color
    },
    lastMessage: chat.last_message || 'No messages yet',
    time: chat.time_ago
}));

// Initialize chat users display
function initializeChatUsers() {
    displayAllUsers();
    displayRecentChats();
}

function displayAllUsers() {
    const container = document.getElementById('allUsersGrid');
    if (!container) return;
    
    container.innerHTML = users.map(user => `
        <div class="user-circle" onclick="openChatPopup(
            '${user.name}',
            '${user.initials}',
            '${user.color}',
            '${user.status}',
            ${user.id}
        )">
            <div class="user-circle-avatar ${user.status === 'online' ? 'online' : ''}" 
                 style="background: ${user.color};">
                ${user.initials}
            </div>
            <div class="user-circle-name">${user.name.split(' ')[0]}</div>
        </div>
    `).join('');
}

function displayRecentChats() {
    const container = document.getElementById('recentChatsList');
    if (!container) return;
    
    if (recentChats.length === 0) {
        container.innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--text-secondary);">No recent chats</div>';
        return;
    }
    
    container.innerHTML = recentChats.map(chat => `
        <div class="chat-item" onclick="openChatPopup(
            '${chat.user.name}',
            '${chat.user.initials}',
            '${chat.user.color}',
            '${chat.user.status}',
            ${chat.user.id}
        )">
            <div class="chat-item-avatar ${chat.user.status === 'online' ? 'online' : ''}" 
                 style="background: ${chat.user.color};">
                ${chat.user.initials}
            </div>
            <div class="chat-item-info">
                <div class="chat-item-name">${chat.user.name}</div>
                <div class="chat-item-message">${chat.lastMessage}</div>
            </div>
            <div class="chat-item-time">${chat.time}</div>
        </div>
    `).join('');
}

function filterUsers() {
    const input = document.getElementById('chatSearchInput');
    if (!input) return;
    
    const query = input.value.toLowerCase();
    const filteredUsers = users.filter(user => 
        user.name.toLowerCase().includes(query)
    );
    
    const container = document.getElementById('allUsersGrid');
    container.innerHTML = filteredUsers.map(user => `
        <div class="user-circle" onclick="openChatPopup(
            '${user.name}',
            '${user.initials}',
            '${user.color}',
            '${user.status}',
            ${user.id}
        )">
            <div class="user-circle-avatar ${user.status === 'online' ? 'online' : ''}" 
                 style="background: ${user.color};">
                ${user.initials}
            </div>
            <div class="user-circle-name">${user.name.split(' ')[0]}</div>
        </div>
    `).join('');
}

// NOTE: openChatPopup, closeChatPopup, sendPopupMessage, loadChatMessages, and loadNewMessages
// are now defined in chat_popup_window.php and should NOT be redefined here

    

    // === AI Generator - DATABASE VERSION ===
    function sendAIQuery() {
        const input = document.getElementById('aiChatInput');
        if (!input) return;
        const msg = input.value.trim();
        if (!msg) return;
        addMessageToAI(msg, 'user');
        input.value = '';
        const ingredients = msg.split(/[,，\s]+/).filter(i => i.length > 0);
        fetch('../../api/ai_search.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ ingredients: ingredients })
        })
        .then(response => response.json())
        .then(data => {
            setTimeout(() => {
                if (!data.recipes || data.recipes.length === 0) {
                    addMessageToAI("Sorry, I couldn't find any recipes with those ingredients. 😔<br><br>Try common Filipino ingredients like: <strong>chicken, pork, soy sauce, vinegar, garlic, onion, tomato, fish sauce, rice</strong>", 'ai');
                } else {
                    const recipesMessage = `Great! I found <strong>${data.recipes.length} recipe${data.recipes.length > 1 ? 's' : ''}</strong> you can make! 🍳<br><br>Based on your ingredients: <strong>${ingredients.join(', ')}</strong>`;
                    addMessageToAI(recipesMessage, 'ai');
                    data.recipes.forEach((recipe, index) => {
                        const matchedIngredients = recipe.ingredients.filter(ing =>
                            ingredients.some(userIng =>
                                ing.toLowerCase().includes(userIng.toLowerCase()) ||
                                userIng.toLowerCase().includes(ing.toLowerCase())
                            )
                        );
                        const matchPercentage = Math.round((matchedIngredients.length / recipe.ingredients.length) * 100);
                        setTimeout(() => {
                            addRecipeCard(recipe, matchedIngredients, matchPercentage);
                        }, index * 200);
                    });
                }
            }, 500);
        })
        .catch(error => {
            console.error('AI Search Error:', error);
            addMessageToAI("Oops! Something went wrong. Please try again later. 🛠️", 'ai');
        });
    }
    function addMessageToAI(text, sender) {
        const container = document.getElementById('aiChatMessages');
        if (!container) return;
        const div = document.createElement('div');
        div.className = `message ${sender}`;
        const avatar = sender === 'user' ? 
            `<div class="message-avatar">${currentUser.initials}</div>` :
            '<div class="message-avatar"><i class="fas fa-robot"></i></div>';
        div.innerHTML = `${avatar}<div class="message-content"><p>${text}</p></div>`;
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
    }
    // Fixed addRecipeCard function with proper path handling
function addRecipeCard(recipe, matchedIngredients, matchPercentage) {
    const container = document.getElementById('aiChatMessages');
    if (!container) return;
    
    const div = document.createElement('div');
    div.className = 'message';
    
    const hasIngredients = recipe.ingredients.filter(ing => 
        matchedIngredients.some(matched => 
            matched.toLowerCase() === ing.toLowerCase() || 
            matched.toLowerCase().includes(ing.toLowerCase()) ||
            ing.toLowerCase().includes(matched.toLowerCase())
        )
    );
    const needsIngredients = recipe.ingredients.filter(ing => !hasIngredients.includes(ing));
    
    // Generate media HTML with proper paths - FIXED VERSION
    let mediaHTML = '';
    if (recipe.image_url || recipe.video_url) {
        mediaHTML = '<div class="recipe-media">';
        
        if (recipe.image_url) {
            // Ensure proper path: if already has ../../ don't add it again
            const imagePath = recipe.image_url.startsWith('../../') ? recipe.image_url : '../../' + recipe.image_url;
            mediaHTML += `<img src="${imagePath}" alt="${recipe.title}" class="recipe-image" onerror="console.error('Image not found:', this.src); this.style.display='none'" onload="console.log('Image loaded:', this.src)">`;
        }
        
        if (recipe.video_url) {
            // Ensure proper path: if already has ../../ don't add it again
            const videoPath = recipe.video_url.startsWith('../../') ? recipe.video_url : '../../' + recipe.video_url;
            mediaHTML += `
                <video controls class="recipe-video" preload="metadata" onerror="console.error('Video not found:', this.src)" onloadedmetadata="console.log('Video loaded:', this.src)">
                    <source src="${videoPath}" type="video/mp4">
                    <source src="${videoPath}" type="video/webm">
                    <source src="${videoPath}" type="video/ogg">
                    Your browser does not support the video tag.
                </video>
            `;
        }
        mediaHTML += '</div>';
    }
    
    const recipeHTML = `
        <div class="message-avatar"><i class="fas fa-robot"></i></div>
        <div class="message-content" style="max-width: 90%;">
            <div class="recipe-card">
                <div class="recipe-card-header">
                    <div>
                        <div class="recipe-title">${recipe.title}</div>
                        <div class="recipe-meta">
                            <div class="recipe-meta-item">
                                <i class="fas fa-clock"></i> ${recipe.time}
                            </div>
                            <div class="recipe-meta-item">
                                <i class="fas fa-signal"></i> ${recipe.difficulty}
                            </div>
                            <div class="recipe-meta-item">
                                <i class="fas fa-users"></i> ${recipe.servings}
                            </div>
                        </div>
                    </div>
                </div>
                
                ${mediaHTML}
                
                <div style="margin: 1rem 0; padding: 0.75rem; background: var(--bg-primary); border-radius: 8px;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <div style="flex: 1; height: 8px; background: var(--border-color); border-radius: 10px; overflow: hidden;">
                            <div style="width: ${matchPercentage}%; height: 100%; background: linear-gradient(90deg, var(--accent-green), var(--accent-orange)); border-radius: 10px; transition: width 0.5s;"></div>
                        </div>
                        <span style="font-weight: bold; color: var(--accent-orange); font-size: 0.9rem;">${matchPercentage}% Match</span>
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-secondary);">
                        You have <strong style="color: var(--accent-green);">${hasIngredients.length}</strong> of <strong>${recipe.ingredients.length}</strong> ingredients
                    </div>
                </div>
                
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-weight: 600; color: var(--accent-green); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-check-circle"></i> You Have (${hasIngredients.length}):
                    </div>
                    <div class="recipe-ingredients">
                        ${hasIngredients.map(ing => `<span class="ingredient-tag" style="background: rgba(74, 124, 78, 0.1); border-color: var(--accent-green); color: var(--accent-green);"><i class="fas fa-check" style="font-size: 0.7rem;"></i> ${ing}</span>`).join('')}
                    </div>
                </div>
                
                ${needsIngredients.length > 0 ? `
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-weight: 600; color: var(--accent-orange); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-shopping-cart"></i> You Need (${needsIngredients.length}):
                    </div>
                    <div class="recipe-ingredients">
                        ${needsIngredients.map(ing => `<span class="ingredient-tag" style="background: rgba(255, 107, 53, 0.1); border-color: var(--accent-orange); color: var(--accent-orange);"><i class="fas fa-plus" style="font-size: 0.7rem;"></i> ${ing}</span>`).join('')}
                    </div>
                </div>
                ` : ''}
                
                <button class="recipe-select-btn" onclick="showRecipeDetails(${recipe.id}, this)">
                    <i class="fas fa-book-open"></i> View Full Recipe
                </button>
                
                <div class="recipe-details" id="recipe-details-${recipe.id}">
                    <div class="recipe-instructions">
                        ${recipe.instructions}
                    </div>
                </div>
            </div>
        </div>
    `;
    
    div.innerHTML = recipeHTML;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}
    function showRecipeDetails(recipeId, button) {
        const details = document.getElementById(`recipe-details-${recipeId}`);
        if (!details || !button) return;
        details.classList.toggle('active');
        if (details.classList.contains('active')) {
            button.innerHTML = '<i class="fas fa-times"></i> Hide Recipe';
            button.style.background = 'var(--text-secondary)';
        } else {
            button.innerHTML = '<i class="fas fa-book-open"></i> View Full Recipe';
            button.style.background = 'var(--accent-orange)';
        }
    }

    // === Community Posts Functions ===
    function toggleLike(postId) {
        const likeBtn = document.querySelector(`[data-post-id="${postId}"] .like-btn`);
        if (!likeBtn) return;
        const likeIcon = likeBtn.querySelector('i');
        const likeCount = likeBtn.querySelector('.like-count');
        if (!postLikes[postId]) {
            postLikes[postId] = { liked: false, count: 0 };
        }
        postLikes[postId].liked = !postLikes[postId].liked;
        if (postLikes[postId].liked) {
            postLikes[postId].count++;
            likeBtn.classList.add('liked');
            if (likeIcon) likeIcon.className = 'fas fa-heart';
        } else {
            postLikes[postId].count--;
            likeBtn.classList.remove('liked');
            if (likeIcon) likeIcon.className = 'far fa-heart';
        }
        if (likeCount) likeCount.textContent = postLikes[postId].count;
        fetch('../../api/posts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=toggle_like&post_id=${postId}`
        }).catch(error => console.error('Error:', error));
    }
    function toggleComments(postId) {
        const commentsSection = document.getElementById(`comments-${postId}`);
        if (!commentsSection) return;
        commentsSection.classList.toggle('active');
        if (commentsSection.classList.contains('active')) {
            const input = document.getElementById(`comment-input-${postId}`);
            if (input) setTimeout(() => input.focus(), 100);
        }
    }
    function addComment(postId) {
        const input = document.getElementById(`comment-input-${postId}`);
        if (!input) return;
        const commentText = input.value.trim();
        if (!commentText) return;
        const commentsList = document.getElementById(`comments-list-${postId}`);
        const commentCount = document.querySelector(`[data-post-id="${postId}"] .comment-count`);
        if (!commentsList) return;
        const commentDiv = document.createElement('div');
        commentDiv.className = 'comment-item';
        commentDiv.innerHTML = `
            <div class="comment-avatar" style="background: ${currentUser.avatar_color};">${currentUser.initials}</div>
            <div class="comment-content">
                <div class="comment-author">${currentUser.name}</div>
                <div class="comment-text">${commentText}</div>
                <div class="comment-time">Just now</div>
            </div>
        `;
        commentsList.appendChild(commentDiv);
        if (!postComments[postId]) postComments[postId] = 0;
        postComments[postId]++;
        if (commentCount) commentCount.textContent = postComments[postId];
        input.value = '';
        fetch('../../api/posts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=add_comment&post_id=${postId}&comment_text=${encodeURIComponent(commentText)}`
        }).catch(error => console.error('Error:', error));
    }

    // === Post Creation ===
    function handleImageUpload(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            currentMedia = { type: 'image', data: e.target.result };
            showMediaPreview('image', e.target.result);
        };
        reader.readAsDataURL(file);
    }
    function handleVideoUpload(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            currentMedia = { type: 'video', data: e.target.result };
            showMediaPreview('video', e.target.result);
        };
        reader.readAsDataURL(file);
    }
    function showMediaPreview(type, data) {
        const preview = document.getElementById('mediaPreview');
        const imagePreview = document.getElementById('imagePreview');
        const videoPreview = document.getElementById('videoPreview');
        if (!preview || !imagePreview || !videoPreview) return;
        preview.classList.add('active');
        if (type === 'image') {
            imagePreview.src = data;
            imagePreview.style.display = 'block';
            videoPreview.style.display = 'none';
        } else {
            videoPreview.src = data;
            videoPreview.style.display = 'block';
            imagePreview.style.display = 'none';
        }
    }
    function removeMedia() {
        const preview = document.getElementById('mediaPreview');
        const imagePreview = document.getElementById('imagePreview');
        const videoPreview = document.getElementById('videoPreview');
        if (preview) preview.classList.remove('active');
        if (imagePreview) {
            imagePreview.src = '';
            imagePreview.style.display = 'none';
        }
        if (videoPreview) {
            videoPreview.src = '';
            videoPreview.style.display = 'none';
        }
        currentMedia = null;
        document.querySelectorAll('.media-upload-btn input').forEach(input => {
            input.value = '';
        });
    }
    function createPost() {
        const input = document.getElementById('postInput');
        if (!input) return;
        const text = input.value.trim();
        if (!text && !currentMedia) {
            alert('Please write something or add a photo/video!');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'create_post');
        formData.append('content', text);
        if (currentMedia) {
            formData.append('media_type', currentMedia.type);
            formData.append('media_data', currentMedia.data);
        }
        fetch('../../api/posts.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Post created successfully! 🎉');
                input.value = '';
                removeMedia();
                location.reload();
            } else {
                alert('Failed to create post: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while creating the post.');
        });
    }

    // === UI & Navigation ===
    window.addEventListener('DOMContentLoaded', function() {
        initializeChatUsers();
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) {
            currentTheme = savedTheme;
            document.documentElement.setAttribute('data-theme', currentTheme);
            const icon = document.querySelector('.theme-toggle i');
            if (icon) {
                icon.className = currentTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
            }
        }
    });
    function toggleMobileMenu() {
        const mobileNav = document.getElementById('mobileNav');
        const btn = document.querySelector('.mobile-menu-btn i');
        if (!mobileNav || !btn) return;
        mobileNav.classList.toggle('active');
        btn.className = mobileNav.classList.contains('active') ? 'fas fa-times' : 'fas fa-bars';
    }
    function closeMobileMenu() {
        const mobileNav = document.getElementById('mobileNav');
        const btn = document.querySelector('.mobile-menu-btn i');
        if (!mobileNav || !btn) return;
        mobileNav.classList.remove('active');
        btn.className = 'fas fa-bars';
    }
    function toggleTheme() {
        currentTheme = currentTheme === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', currentTheme);
        const icon = document.querySelector('.theme-toggle i');
        if (icon) {
            icon.className = currentTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }
        localStorage.setItem('theme', currentTheme);
    }
    function toggleUserMenu() {
        const dropdown = document.getElementById('userDropdown');
        const notificationDropdown = document.getElementById('notificationDropdown');
        if (!dropdown) return;
        if (notificationDropdown) notificationDropdown.classList.remove('active');
        dropdown.classList.toggle('active');
    }
    function toggleNotifications() {
        const dropdown = document.getElementById('notificationDropdown');
        const userDropdown = document.getElementById('userDropdown');
        if (!dropdown) return;
        if (userDropdown) userDropdown.classList.remove('active');
        dropdown.classList.toggle('active');
    }
    function markAsRead(notificationId) {
        const formData = new FormData();
        formData.append('notification_id', notificationId);
        formData.append('action', 'mark_read');
        fetch('../../api/notifications.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const notifElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
                if (notifElement) notifElement.classList.remove('unread');
                const badge = document.querySelector('.notification-badge');
                if (data.unread_count > 0) {
                    if (badge) badge.textContent = data.unread_count;
                } else {
                    if (badge) badge.remove();
                }
            }
        })
        .catch(error => console.error('Error:', error));
    }
    function markAllRead() {
        const formData = new FormData();
        formData.append('action', 'mark_all_read');
        fetch('../../api/notifications.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                });
                const badge = document.querySelector('.notification-badge');
                if (badge) badge.remove();
            }
        })
        .catch(error => console.error('Error:', error));
    }
    function logout() {
        if(confirm('Are you sure you want to logout?')) {
            window.location.href = '../../logout.php';
        }
    }
    document.addEventListener('click', function(event) {
        const userMenu = document.querySelector('.user-menu');
        const dropdown = document.getElementById('userDropdown');
        const notificationBtn = document.querySelector('.notification-btn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        if (userMenu && dropdown && !userMenu.contains(event.target)) {
            dropdown.classList.remove('active');
        }
        if (notificationBtn && notificationDropdown && 
            !notificationBtn.contains(event.target) && 
            !notificationDropdown.contains(event.target)) {
            notificationDropdown.classList.remove('active');
        }
    });
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                closeMobileMenu();
                document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));
                this.classList.add('active');
            }
        });
    });

    // Logout Modal Functions
function openLogoutModal() {
    document.getElementById('logoutModal').classList.add('active');
    const dropdown = document.getElementById('userDropdown');
    if (dropdown) dropdown.classList.remove('active');
}

function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('active');
}

function confirmLogout() {
    window.location.href = '../../logout.php';
}

// Auto-request notification permission on first user interaction
let notificationPermissionRequested = false;

function requestNotificationPermission() {
    if (notificationPermissionRequested || !window.Notification) return;
    
    Notification.requestPermission().then(permission => {
        if (permission === 'granted') {
            console.log('Notification permission granted.');
            // Optional: Send a welcome notification
            // new Notification('Notifications enabled!', { body: 'You’ll receive updates from Flavor Forge.' });
        }
        notificationPermissionRequested = true;
    }).catch(err => {
        console.warn('Notification permission request failed:', err);
        notificationPermissionRequested = true;
    });
}

// Attach to common user interaction events
document.addEventListener('click', requestNotificationPermission, { once: true });
document.addEventListener('touchstart', requestNotificationPermission, { once: true });
document.addEventListener('keydown', requestNotificationPermission, { once: true });
    </script>
</body>
</html>