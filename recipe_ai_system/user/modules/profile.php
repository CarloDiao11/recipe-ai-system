<?php
session_start();
require_once '../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Fetch user information
$stmt = $conn->prepare("SELECT id, username, name, email, profile_picture, initials, avatar_color, role, status, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Generate initials if not set
if (empty($user['initials'])) {
    $names = explode(' ', $user['name']);
    $user['initials'] = strtoupper(substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
}

// Set avatar color if not set
if (empty($user['avatar_color'])) {
    $colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E2'];
    $user['avatar_color'] = $colors[array_rand($colors)];
}

// Get user statistics
$statsQuery = "
    SELECT 
        (SELECT COUNT(*) FROM posts WHERE user_id = ?) as total_posts,
        (SELECT COUNT(*) FROM saved_recipes WHERE user_id = ?) as saved_recipes,
        (SELECT COUNT(*) FROM user_followers WHERE following_id = ?) as followers,
        (SELECT COUNT(*) FROM user_followers WHERE follower_id = ?) as following
";
$stmt = $conn->prepare($statsQuery);
$stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get recent posts
$postsQuery = "
    SELECT 
        p.id,
        p.content,
        p.media_type,
        p.media_url,
        p.likes_count,
        p.comments_count,
        p.created_at,
        CASE 
            WHEN TIMESTAMPDIFF(MINUTE, p.created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, p.created_at, NOW()), ' mins ago')
            WHEN TIMESTAMPDIFF(HOUR, p.created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, p.created_at, NOW()), ' hours ago')
            WHEN TIMESTAMPDIFF(DAY, p.created_at, NOW()) = 1 THEN 'Yesterday'
            ELSE CONCAT(TIMESTAMPDIFF(DAY, p.created_at, NOW()), ' days ago')
        END as time_ago
    FROM posts p
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
    LIMIT 6
";
$stmt = $conn->prepare($postsQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recentPosts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_count = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Flavor Forge</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/header.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .profile-header {
            background: var(--bg-primary);
            border-radius: 20px;
            padding: 3rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 150px;
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-green));
            opacity: 0.1;
        }

        .profile-content {
            position: relative;
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .profile-avatar-section {
            position: relative;
        }

        .profile-avatar-large {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
            color: white;
            border: 5px solid var(--bg-secondary);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-info {
            flex: 1;
            min-width: 300px;
        }

        .profile-name {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .profile-username {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .profile-email {
            font-size: 1rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .profile-stats {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-orange);
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .profile-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--accent-orange);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 53, 0.3);
        }

        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 2px solid var(--border-color);
        }

        .btn-secondary:hover {
            border-color: var(--accent-orange);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        .section-card {
            background: var(--bg-primary);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }

        .section-header i {
            font-size: 1.5rem;
            color: var(--accent-orange);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent-orange);
        }

        .post-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .post-item {
            aspect-ratio: 1;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .post-item:hover {
            transform: scale(1.05);
        }

        .post-item img,
        .post-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .post-item-text {
            background: var(--bg-secondary);
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            text-align: center;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .post-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
            padding: 1rem;
            color: white;
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
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

        .file-upload-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .file-upload-input {
            display: none;
        }

        .file-upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem;
            border: 2px dashed var(--border-color);
            border-radius: 10px;
            background: var(--bg-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-label:hover {
            border-color: var(--accent-orange);
            background: rgba(255, 107, 53, 0.05);
        }

        .file-name-display {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .profile-content {
                flex-direction: column;
                text-align: center;
            }

            .profile-stats {
                justify-content: center;
            }

            .profile-actions {
                flex-direction: column;
            }

            .post-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <i class="fas fa-fire"></i>
            Flavor Forge
        </div>
        <nav class="header-nav">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-home"></i>
                Dashboard
            </a>
            <a href="saved-recipes.php" class="nav-link">
                <i class="fas fa-heart"></i>
                Saved Recipes
            </a>
            <a href="profile.php" class="nav-link active">
                <i class="fas fa-user"></i>
                Profile
            </a>
        </nav>
        <div class="header-actions">
            <button class="theme-toggle" onclick="toggleTheme()">
                <i class="fas fa-moon"></i>
            </button>
            <button class="notification-btn" onclick="window.location.href='dashboard.php'">
                <i class="fas fa-bell"></i>
                <?php if ($unread_count > 0): ?>
                <span class="notification-badge"><?= $unread_count ?></span>
                <?php endif; ?>
            </button>
            <button class="btn-secondary" onclick="window.location.href='../../logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>
    </div>

    <div class="container">
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-content">
                <div class="profile-avatar-section">
                    <div class="profile-avatar-large" style="background: <?= htmlspecialchars($user['avatar_color']) ?>;">
                        <?php if (!empty($user['profile_picture']) && $user['profile_picture'] !== 'uploads/default_profile.png' && file_exists('../../' . $user['profile_picture'])): ?>
                            <img src="../../<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile">
                        <?php else: ?>
                            <?= htmlspecialchars($user['initials']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="profile-info">
                    <h1 class="profile-name"><?= htmlspecialchars($user['name']) ?></h1>
                    <p class="profile-username">@<?= htmlspecialchars($user['username']) ?></p>
                    <p class="profile-email">
                        <i class="fas fa-envelope"></i>
                        <?= htmlspecialchars($user['email']) ?>
                    </p>
                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?= $stats['total_posts'] ?></div>
                            <div class="stat-label">Posts</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?= $stats['saved_recipes'] ?></div>
                            <div class="stat-label">Saved</div>
                        </div>
                        
                    </div>
                    <div class="profile-actions">
                        <button class="btn btn-primary" onclick="scrollToEdit()">
                            <i class="fas fa-edit"></i> Edit Profile
                        </button>
                        <button class="btn btn-secondary" onclick="window.location.href='saved-recipes.php'">
                            <i class="fas fa-heart"></i> View Saved Recipes
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Edit Profile Section -->
            <div class="section-card" id="editSection">
                <div class="section-header">
                    <i class="fas fa-user-edit"></i>
                    <h2 class="section-title">Edit Profile</h2>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
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
                        <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-input" value="<?= htmlspecialchars($user['username']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>

                    <button type="submit" name="update_profile" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>

            <!-- Recent Posts Section -->
            <div class="section-card">
                <div class="section-header">
                    <i class="fas fa-images"></i>
                    <h2 class="section-title">Recent Posts</h2>
                </div>

                <?php if (empty($recentPosts)): ?>
                    <div class="empty-state">
                        <i class="fas fa-image"></i>
                        <p>No posts yet</p>
                    </div>
                <?php else: ?>
                    <div class="post-grid">
                        <?php foreach ($recentPosts as $post): ?>
                            <div class="post-item">
                                <?php if ($post['media_type'] === 'image' && !empty($post['media_url'])): ?>
                                    <img src="../../<?= htmlspecialchars($post['media_url']) ?>" alt="Post">
                                <?php elseif ($post['media_type'] === 'video' && !empty($post['media_url'])): ?>
                                    <video src="../../<?= htmlspecialchars($post['media_url']) ?>"></video>
                                <?php else: ?>
                                    <div class="post-item-text">
                                        <?= htmlspecialchars(substr($post['content'], 0, 100)) . (strlen($post['content']) > 100 ? '...' : '') ?>
                                    </div>
                                <?php endif; ?>
                                <div class="post-overlay">
                                    <span><i class="fas fa-heart"></i> <?= $post['likes_count'] ?></span>
                                    <span><i class="fas fa-comment"></i> <?= $post['comments_count'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function scrollToEdit() {
            document.getElementById('editSection').scrollIntoView({ behavior: 'smooth' });
        }

        function displayFileName() {
            const input = document.getElementById('profilePicture');
            const display = document.getElementById('fileNameDisplay');
            if (input.files.length > 0) {
                display.textContent = 'Selected: ' + input.files[0].name;
            } else {
                display.textContent = '';
            }
        }

        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            
            const icon = document.querySelector('.theme-toggle i');
            icon.className = newTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }

        // Load saved theme
        window.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            const icon = document.querySelector('.theme-toggle i');
            if (icon) {
                icon.className = savedTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
            }
        });
    </script>
</body>
</html>