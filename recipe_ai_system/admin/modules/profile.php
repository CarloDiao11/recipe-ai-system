<?php
session_start();
require_once '../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Fetch user data with profile picture
$user_query = "SELECT id, username, email, name, profile_picture, initials, avatar_color, role, created_at 
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

// Get admin statistics
$statsQuery = "
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM recipes) as total_recipes,
        (SELECT COUNT(*) FROM posts) as total_posts,
        (SELECT COUNT(*) FROM comments) as total_comments
";
$stats = $conn->query($statsQuery)->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    
    if (empty($name) || empty($email) || empty($username)) {
        $error = "All fields are required!";
    } else {
        // Check if email/username already exists for other users
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ?");
        $checkStmt->bind_param("ssi", $email, $username, $user_id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            $error = "Email or username already exists!";
        } else {
            // Handle profile picture upload
            $profilePicture = $user['profile_picture'];
            if (!empty($_FILES['profile_picture']['name'])) {
                $file = $_FILES['profile_picture'];
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed) && $file['error'] === 0 && $file['size'] <= 5 * 1024 * 1024) {
                    // Create profile_picture directory if not exists
                    $uploadDir = '../../uploads/profile_picture/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $newName = 'profile_' . $user_id . '_' . uniqid() . '.' . $ext;
                    $uploadPath = $uploadDir . $newName;
                    
                    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                        // Delete old profile picture if exists and not default
                        if (!empty($user['profile_picture']) && 
                            $user['profile_picture'] !== 'uploads/default_profile.png' && 
                            file_exists('../../' . $user['profile_picture'])) {
                            unlink('../../' . $user['profile_picture']);
                        }
                        $profilePicture = 'uploads/profile_picture/' . $newName;
                    }
                } else {
                    $error = "Invalid file. Please upload JPG, JPEG, PNG, or WEBP (max 5MB)";
                }
            }
            
            // Only update if no error occurred
            if (empty($error)) {
                // Update user info
                $updateStmt = $conn->prepare("UPDATE users SET name = ?, email = ?, username = ?, profile_picture = ? WHERE id = ?");
                $updateStmt->bind_param("ssssi", $name, $email, $username, $profilePicture, $user_id);
                
                if ($updateStmt->execute()) {
                    $success = "Profile updated successfully!";
                    // Refresh user data
                    $user['name'] = $name;
                    $user['email'] = $email;
                    $user['username'] = $username;
                    $user['profile_picture'] = $profilePicture;
                    
                    // Refresh the page to update header profile picture
                    echo "<script>
                        setTimeout(function() {
                            window.location.href = 'profile.php';
                        }, 1500);
                    </script>";
                } else {
                    $error = "Failed to update profile!";
                }
                $updateStmt->close();
            }
        }
        $checkStmt->close();
    }
}

// Fetch unread notifications count
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
    <title>Flavor Forge - My Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/header.css">

    <style>
        /* Content Area */
        .content {
            padding: 40px;
            background: var(--bg-secondary);
        }

        /* Profile Header Card */
        .profile-header-card {
            background: var(--bg-primary);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .profile-header-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 100px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            opacity: 0.1;
        }

        .profile-content {
            position: relative;
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .profile-avatar-section {
            position: relative;
            flex-shrink: 0;
        }

        .profile-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
            border: 4px solid var(--bg-secondary);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-info-section {
            flex: 1;
            min-width: 0;
        }

        .profile-name {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .profile-username {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }

        .profile-email {
            font-size: 0.9rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .profile-role-badge {
            display: inline-block;
            padding: 5px 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-stats {
            display: flex;
            gap: 30px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid var(--border);
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .section-card {
            background: var(--bg-primary);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 2px 10px var(--shadow);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }

        .section-header i {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 15px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .file-upload-wrapper {
            position: relative;
        }

        .file-upload-input {
            display: none;
        }

        .file-upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 16px;
            border: 2px dashed var(--border);
            border-radius: 10px;
            background: var(--bg-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--text-secondary);
        }

        .file-upload-label:hover {
            border-color: var(--primary);
            background: rgba(102, 126, 234, 0.05);
            color: var(--primary);
        }

        .file-name-display {
            margin-top: 8px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-submit:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }

        .alert-success {
            background: rgba(74, 222, 128, 0.1);
            border: 2px solid #4ade80;
            color: #16a34a;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid #ef4444;
            color: #dc2626;
        }

        .alert i {
            font-size: 1.2rem;
        }

        /* Info Card */
        .info-card {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .info-value {
            color: var(--text-primary);
            font-size: 14px;
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

            .profile-content {
                flex-direction: column;
                text-align: center;
            }

            .profile-stats {
                justify-content: center;
                gap: 20px;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .profile-header-card {
                padding: 25px;
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
                    <h1>My Profile</h1>
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
                <!-- Profile Header -->
                <div class="profile-header-card">
                    <div class="profile-content">
                        <div class="profile-avatar-section">
                            <div class="profile-avatar-large" style="background: <?php echo htmlspecialchars($user['avatar_color']); ?>;">
                                <?php if (!empty($user['profile_picture']) && file_exists('../../' . $user['profile_picture'])): ?>
                                    <img src="../../<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($user['initials']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="profile-info-section">
                            <h1 class="profile-name"><?php echo htmlspecialchars($user['name']); ?></h1>
                            <p class="profile-username">@<?php echo htmlspecialchars($user['username']); ?></p>
                            <p class="profile-email">
                                <i class="fas fa-envelope"></i>
                                <?php echo htmlspecialchars($user['email']); ?>
                            </p>
                            <span class="profile-role-badge"><?php echo ucfirst($user['role']); ?></span>
                            
                            <div class="profile-stats">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                                    <div class="stat-label">Users</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['total_recipes']); ?></div>
                                    <div class="stat-label">Recipes</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['total_posts']); ?></div>
                                    <div class="stat-label">Posts</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['total_comments']); ?></div>
                                    <div class="stat-label">Comments</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Content Grid -->
                <div class="content-grid">
                    <!-- Edit Profile Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <i class="fas fa-user-edit"></i>
                            <h2 class="section-title">Edit Profile</h2>
                        </div>

                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="form-group">
                                <label class="form-label">Profile Picture</label>
                                <div class="file-upload-wrapper">
                                    <input type="file" name="profile_picture" id="profilePicture" class="file-upload-input" accept="image/*" onchange="displayFileName()">
                                    <label for="profilePicture" class="file-upload-label">
                                        <i class="fas fa-camera"></i>
                                        Choose Profile Picture
                                    </label>
                                    <div class="file-name-display" id="fileNameDisplay"></div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-input" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>

                            <button type="submit" name="update_profile" class="btn-submit">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </form>
                    </div>

                    <!-- Account Information -->
                    <div class="section-card">
                        <div class="section-header">
                            <i class="fas fa-info-circle"></i>
                            <h2 class="section-title">Account Information</h2>
                        </div>

                        <div class="info-card">
                            <div class="info-row">
                                <span class="info-label">User ID</span>
                                <span class="info-value">#<?php echo htmlspecialchars($user['id']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Role</span>
                                <span class="info-value"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Member Since</span>
                                <span class="info-value"><?php echo date('F d, Y', strtotime($user['created_at'])); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Username</span>
                                <span class="info-value">@<?php echo htmlspecialchars($user['username']); ?></span>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <a href="dashboard.php" class="btn-submit" style="text-decoration: none;">
                                <i class="fas fa-tachometer-alt"></i> Back to Dashboard
                            </a>
                        </div>
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
        });

        // Display selected file name
        function displayFileName() {
            const input = document.getElementById('profilePicture');
            const display = document.getElementById('fileNameDisplay');
            if (input.files.length > 0) {
                display.textContent = 'Selected: ' + input.files[0].name;
            } else {
                display.textContent = '';
            }
        }
    </script>
</body>
</html>