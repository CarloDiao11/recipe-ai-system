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

// Analytics Queries

// 1. Total Users
$total_users_query = "SELECT COUNT(*) as total FROM users";
$total_users = $conn->query($total_users_query)->fetch_assoc()['total'];

// 2. Total Recipes
$total_recipes_query = "SELECT COUNT(*) as total FROM recipes";
$total_recipes = $conn->query($total_recipes_query)->fetch_assoc()['total'];

// 3. Total Posts
$total_posts_query = "SELECT COUNT(*) as total FROM posts";
$total_posts = $conn->query($total_posts_query)->fetch_assoc()['total'];

// 4. Total Comments
$total_comments_query = "SELECT COUNT(*) as total FROM comments";
$total_comments = $conn->query($total_comments_query)->fetch_assoc()['total'];

// 5. New Users This Month
$new_users_query = "SELECT COUNT(*) as total FROM users 
                    WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
                    AND YEAR(created_at) = YEAR(CURRENT_DATE())";
$new_users_month = $conn->query($new_users_query)->fetch_assoc()['total'];

// 6. New Recipes This Month
$new_recipes_query = "SELECT COUNT(*) as total FROM recipes 
                      WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
                      AND YEAR(created_at) = YEAR(CURRENT_DATE())";
$new_recipes_month = $conn->query($new_recipes_query)->fetch_assoc()['total'];

// 7. Active Users (posted in last 30 days)
$active_users_query = "SELECT COUNT(DISTINCT user_id) as total FROM posts 
                       WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$active_users = $conn->query($active_users_query)->fetch_assoc()['total'];

// 8. Total Likes
$total_likes_query = "SELECT COUNT(*) as total FROM post_likes";
$total_likes = $conn->query($total_likes_query)->fetch_assoc()['total'];

// 9. Recent Users (Last 5)
$recent_users_query = "SELECT id, username, name, profile_picture, initials, avatar_color, created_at 
                       FROM users ORDER BY created_at DESC LIMIT 5";
$recent_users = $conn->query($recent_users_query);

// 10. Popular Recipes (Top 5 by saved count)
$popular_recipes_query = "SELECT r.id, r.title, r.image_url, COUNT(sr.id) as saves_count 
                          FROM recipes r 
                          LEFT JOIN saved_recipes sr ON r.id = sr.recipe_id 
                          GROUP BY r.id 
                          ORDER BY saves_count DESC 
                          LIMIT 5";
$popular_recipes = $conn->query($popular_recipes_query);

// 11. Recent Activities (Last 10)
$activities_query = "
    (SELECT 'recipe' as type, r.id, r.title as content, u.name as user_name, r.created_at 
     FROM recipes r 
     JOIN users u ON r.created_by = u.id 
     ORDER BY r.created_at DESC LIMIT 10)
    UNION ALL
    (SELECT 'post' as type, p.id, SUBSTRING(p.content, 1, 50) as content, u.name as user_name, p.created_at 
     FROM posts p 
     JOIN users u ON p.user_id = u.id 
     ORDER BY p.created_at DESC LIMIT 10)
    ORDER BY created_at DESC 
    LIMIT 10";
$activities = $conn->query($activities_query);

// 12. Chart Data - Users Growth (Last 6 months)
$users_growth_query = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as count
    FROM users
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC";
$users_growth = $conn->query($users_growth_query);

$growth_data = [];
while ($row = $users_growth->fetch_assoc()) {
    $growth_data[] = $row;
}

// 13. Unread Notifications Count
$notif_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt = $conn->prepare($notif_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_count = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// 14. Fetch Notifications
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
    <title>Flavor Forge - Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/header.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>

    <style>
        /* Content Area */
        .content {
            padding: 40px;
            background: var(--bg-secondary);
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
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-icon.users { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-icon.recipes { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-icon.posts { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-icon.comments { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .stat-icon.likes { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .stat-icon.active { background: linear-gradient(135deg, #30cfd0 0%, #330867 100%); }

        .stat-title {
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .stat-change {
            font-size: 14px;
            color: #10b981;
        }

        .stat-change.negative {
            color: #ef4444;
        }

        /* Chart Section */
        .chart-section {
            background: var(--bg-primary);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
            margin-bottom: 30px;
        }

        .chart-header {
            margin-bottom: 20px;
        }

        .chart-header h3 {
            color: var(--text-primary);
            font-size: 20px;
            font-weight: 600;
        }

        /* Tables Section */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .table-card {
            background: var(--bg-primary);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-header h3 {
            color: var(--text-primary);
            font-size: 18px;
            font-weight: 600;
        }

        .table-header a {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
        }

        .data-table {
            width: 100%;
        }

        .data-table tr {
            border-bottom: 1px solid var(--border);
        }

        .data-table tr:last-child {
            border-bottom: none;
        }

        .data-table td {
            padding: 12px 0;
            color: var(--text-primary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-initials {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .recipe-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .recipe-thumb {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            object-fit: cover;
        }

        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-type {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .activity-type.recipe {
            background: #fef3c7;
            color: #92400e;
        }

        .activity-type.post {
            background: #dbeafe;
            color: #1e40af;
        }

        .activity-text {
            color: var(--text-primary);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .activity-time {
            color: var(--text-secondary);
            font-size: 12px;
        }

        /* Responsive Design */
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
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
            }

            .tables-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 20px;
            }

            .stat-value {
                font-size: 24px;
            }
        }

        /* Overlay for mobile */
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
    <!-- Overlay for mobile -->
    <div class="overlay" id="overlay"></div>
    
    <div class="container">
        <?php include '../partials/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1>Dashboard</h1>
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

            <!-- Content -->
            <div class="content">
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Total Users</div>
                            <div class="stat-icon users">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo number_format($total_users); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-arrow-up"></i> <?php echo $new_users_month; ?> this month
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Total Recipes</div>
                            <div class="stat-icon recipes">
                                <i class="fas fa-utensils"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo number_format($total_recipes); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-arrow-up"></i> <?php echo $new_recipes_month; ?> this month
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Total Posts</div>
                            <div class="stat-icon posts">
                                <i class="fas fa-file-alt"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo number_format($total_posts); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-chart-line"></i> Growing
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Total Comments</div>
                            <div class="stat-icon comments">
                                <i class="fas fa-comments"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo number_format($total_comments); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-check"></i> Active
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Total Likes</div>
                            <div class="stat-icon likes">
                                <i class="fas fa-heart"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo number_format($total_likes); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-thumbs-up"></i> Engaged
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Active Users</div>
                            <div class="stat-icon active">
                                <i class="fas fa-user-check"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo number_format($active_users); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-calendar"></i> Last 30 days
                        </div>
                    </div>
                </div>

                <!-- Chart Section -->
                <div class="chart-section">
                    <div class="chart-header">
                        <h3>User Growth (Last 6 Months)</h3>
                    </div>
                    <canvas id="userGrowthChart" height="80"></canvas>
                </div>

                <!-- Tables Grid -->
                <div class="tables-grid">
                    <!-- Recent Users -->
                    <!-- Recent Users -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3>Recent Users</h3>
                            <a href="user.php">View All</a>
                        </div>
                        <table class="data-table">
                            <?php while ($recent_user = $recent_users->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="user-info">
                                        <?php if (!empty($recent_user['profile_picture']) && file_exists('../../' . $recent_user['profile_picture'])): ?>
                                            <img src="../../<?php echo htmlspecialchars($recent_user['profile_picture']); ?>" alt="User" class="user-avatar">
                                        <?php else: ?>
                                            <div class="user-initials" style="background-color: <?php echo $recent_user['avatar_color'] ?? '#667eea'; ?>">
                                                <?php echo htmlspecialchars($recent_user['initials'] ?? 'U'); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($recent_user['name']); ?></div>
                                            <div style="font-size: 12px; color: var(--text-secondary);">@<?php echo htmlspecialchars($recent_user['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align: right; color: var(--text-secondary); font-size: 12px;">
                                    <?php echo date('M d, Y', strtotime($recent_user['created_at'])); ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </table>
                    </div>

                    <!-- Popular Recipes -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3>Popular Recipes</h3>
                            <a href="recipe.php">View All</a>
                        </div>
                        <table class="data-table">
                            <?php while ($recipe = $popular_recipes->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="recipe-info">
                                        <img src="../../<?php echo htmlspecialchars($recipe['image_url']); ?>" alt="Recipe" class="recipe-thumb">
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($recipe['title']); ?></div>
                                            <div style="font-size: 12px; color: var(--text-secondary);">
                                                <i class="fas fa-bookmark"></i> <?php echo $recipe['saves_count']; ?> saves
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </table>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="table-card">
                    <div class="table-header">
                        <h3>Recent Activities</h3>
                        <a href="#">View All</a>
                    </div>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php while ($activity = $activities->fetch_assoc()): ?>
                        <div class="activity-item">
                            <span class="activity-type <?php echo $activity['type']; ?>">
                                <?php echo strtoupper($activity['type']); ?>
                            </span>
                            <div class="activity-text">
                                <strong><?php echo htmlspecialchars($activity['user_name']); ?></strong>
                                <?php if ($activity['type'] == 'recipe'): ?>
                                    added a new recipe: <?php echo htmlspecialchars($activity['content']); ?>
                                <?php else: ?>
                                    created a new post: <?php echo htmlspecialchars($activity['content']); ?>...
                                <?php endif; ?>
                            </div>
                            <div class="activity-time">
                                <?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                    
                    // Toggle icon
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
                    
                    // Close profile dropdown if open
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
                    
                    // Close notification dropdown if open
                    if (notificationDropdown) {
                        notificationDropdown.classList.remove('show');
                    }
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                // Close profile dropdown
                if (!e.target.closest('.profile-container')) {
                    if (dropdownMenu) dropdownMenu.classList.remove('show');
                    if (dropdownIcon) dropdownIcon.classList.remove('open');
                }
                
                // Close notification dropdown
                if (!e.target.closest('.notification-container')) {
                    if (notificationDropdown) notificationDropdown.classList.remove('show');
                }
            });

            // Chart.js - User Growth Chart
            const ctx = document.getElementById('userGrowthChart');
            if (ctx) {
                const growthData = <?php echo json_encode($growth_data); ?>;
                
                const labels = growthData.map(item => {
                    const [year, month] = item.month.split('-');
                    const date = new Date(year, month - 1);
                    return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                });
                
                const data = growthData.map(item => parseInt(item.count));
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'New Users',
                            data: data,
                            borderColor: 'rgb(102, 126, 234)',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>