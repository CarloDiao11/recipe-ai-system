<?php
session_start();
require_once '../../config/database.php';

// Check login
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

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $instructions = trim($_POST['instructions']);
    $time = trim($_POST['time']);
    $servings = trim($_POST['servings']);
    $difficulty = $_POST['difficulty'];

    if (empty($title) || empty($instructions)) {
        $error = "Title and instructions are required.";
    } else {
        // Handle image upload
        $image_url = '';
        if (!empty($_FILES['image']['name'])) {
            $image = $_FILES['image'];
            $allowed_img = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed_img) && $image['error'] === 0) {
                $new_name = 'recipe_img_' . uniqid() . '.' . $ext;
                $upload_path = '../../uploads/' . $new_name;
                if (move_uploaded_file($image['tmp_name'], $upload_path)) {
                    $image_url = 'uploads/' . $new_name;
                }
            }
        }

        // Handle video upload
        $video_url = '';
        if (!empty($_FILES['video']['name'])) {
            $video = $_FILES['video'];
            $allowed_vid = ['mp4', 'webm', 'ogg'];
            $ext = strtolower(pathinfo($video['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed_vid) && $video['error'] === 0 && $video['size'] <= 50 * 1024 * 1024) {
                $new_name = 'recipe_vid_' . uniqid() . '.' . $ext;
                $upload_path = '../../uploads/' . $new_name;
                if (move_uploaded_file($video['tmp_name'], $upload_path)) {
                    $video_url = 'uploads/' . $new_name;
                }
            }
        }

        // Insert recipe
        $stmt = $conn->prepare("
            INSERT INTO recipes (title, instructions, image_url, video_url, time, servings, difficulty, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssssssi", $title, $instructions, $image_url, $video_url, $time, $servings, $difficulty, $user_id);
        
        if ($stmt->execute()) {
            $recipe_id = $conn->insert_id;

            // Insert ingredients
            if (!empty($_POST['ingredient_name'])) {
                $insert_ing = $conn->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_name, quantity) VALUES (?, ?, ?)");
                foreach ($_POST['ingredient_name'] as $index => $name) {
                    $name = trim($name);
                    $qty = trim($_POST['quantity'][$index] ?? '');
                    if (!empty($name)) {
                        $insert_ing->bind_param("iss", $recipe_id, $name, $qty);
                        $insert_ing->execute();
                    }
                }
                $insert_ing->close();
            }

            $_SESSION['success_message'] = "Recipe added successfully!";
            header("Location: recipe.php");
            exit();
        } else {
            $error = "Failed to save recipe.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Recipe - Flavor Forge</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/header.css">

    <style>
        .content {
            padding: 40px;
            background: var(--bg-secondary);
        }

        .form-card {
            background: var(--bg-primary);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border);
        }

        .form-header h2 {
            color: var(--text-primary);
            font-size: 28px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-header h2 i {
            color: var(--primary);
        }

        .back-btn {
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid var(--border);
        }

        .back-btn:hover {
            background: var(--bg-primary);
            transform: translateY(-2px);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .form-section {
            margin-bottom: 35px;
        }

        .section-title {
            color: var(--text-primary);
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary);
            font-size: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
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

        .form-group label .required {
            color: #ef4444;
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
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .file-upload-wrapper {
            position: relative;
        }

        .file-upload-label {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            background: var(--bg-secondary);
            border: 2px dashed var(--border);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--text-secondary);
        }

        .file-upload-label:hover {
            border-color: var(--primary);
            background: rgba(102, 126, 234, 0.05);
        }

        .file-upload-label i {
            font-size: 20px;
            color: var(--primary);
        }

        input[type="file"] {
            display: none;
        }

        .file-info {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 6px;
        }

        .ingredients-container {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 10px;
            margin-top: 15px;
        }

        .ingredient-row {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 12px;
            margin-bottom: 12px;
            align-items: center;
        }

        .ingredient-row input {
            margin-bottom: 0;
        }

        .remove-ingredient-btn {
            background: #fee2e2;
            color: #991b1b;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .remove-ingredient-btn:hover {
            background: #fca5a5;
            transform: scale(1.05);
        }

        .add-ingredient-btn {
            background: var(--bg-primary);
            color: var(--primary);
            border: 2px dashed var(--primary);
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .add-ingredient-btn:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 35px;
            padding-top: 25px;
            border-top: 2px solid var(--border);
        }

        .btn {
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--bg-primary);
        }

        .difficulty-select {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .difficulty-option {
            position: relative;
        }

        .difficulty-option input[type="radio"] {
            display: none;
        }

        .difficulty-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 15px;
            border: 2px solid var(--border);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .difficulty-option label i {
            font-size: 24px;
        }

        .difficulty-option input[type="radio"]:checked + label {
            border-color: var(--primary);
            background: rgba(102, 126, 234, 0.1);
        }

        .difficulty-option.easy label i {
            color: #10b981;
        }

        .difficulty-option.medium label i {
            color: #f59e0b;
        }

        .difficulty-option.hard label i {
            color: #ef4444;
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

            .form-card {
                padding: 25px;
            }

            .form-header {
                flex-direction: column;
                gap: 15px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .ingredient-row {
                grid-template-columns: 1fr;
            }

            .remove-ingredient-btn {
                width: 100%;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .difficulty-select {
                grid-template-columns: 1fr;
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
                    <h1>Add Recipe</h1>
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
                <div class="form-card">
                    <div class="form-header">
                        <h2><i class="fas fa-plus-circle"></i> Add New Recipe</h2>
                        <a href="recipe.php" class="back-btn">
                            <i class="fas fa-arrow-left"></i>
                            Back to Recipes
                        </a>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?= htmlspecialchars($success) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <!-- Basic Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Basic Information
                            </div>

                            <div class="form-group">
                                <label>Recipe Title <span class="required">*</span></label>
                                <input type="text" name="title" required placeholder="Enter recipe title" value="<?= isset($_POST['title']) ? htmlspecialchars($_POST['title']) : '' ?>">
                            </div>

                            <div class="form-group">
                                <label>Instructions <span class="required">*</span></label>
                                <textarea name="instructions" required placeholder="Enter cooking instructions..."><?= isset($_POST['instructions']) ? htmlspecialchars($_POST['instructions']) : '' ?></textarea>
                            </div>
                        </div>

                        <!-- Recipe Details -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-clock"></i>
                                Recipe Details
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label><i class="fas fa-clock"></i> Cooking Time</label>
                                    <input type="text" name="time" placeholder="e.g., 30 minutes" value="<?= isset($_POST['time']) ? htmlspecialchars($_POST['time']) : '' ?>">
                                </div>

                                <div class="form-group">
                                    <label><i class="fas fa-users"></i> Servings</label>
                                    <input type="text" name="servings" placeholder="e.g., 4 servings" value="<?= isset($_POST['servings']) ? htmlspecialchars($_POST['servings']) : '' ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label><i class="fas fa-chart-line"></i> Difficulty Level</label>
                                <div class="difficulty-select">
                                    <div class="difficulty-option easy">
                                        <input type="radio" name="difficulty" value="Easy" id="easy" <?= (!isset($_POST['difficulty']) || $_POST['difficulty'] == 'Easy') ? 'checked' : '' ?>>
                                        <label for="easy">
                                            <i class="fas fa-smile"></i>
                                            <span>Easy</span>
                                        </label>
                                    </div>
                                    <div class="difficulty-option medium">
                                        <input type="radio" name="difficulty" value="Medium" id="medium" <?= (isset($_POST['difficulty']) && $_POST['difficulty'] == 'Medium') ? 'checked' : '' ?>>
                                        <label for="medium">
                                            <i class="fas fa-meh"></i>
                                            <span>Medium</span>
                                        </label>
                                    </div>
                                    <div class="difficulty-option hard">
                                        <input type="radio" name="difficulty" value="Hard" id="hard" <?= (isset($_POST['difficulty']) && $_POST['difficulty'] == 'Hard') ? 'checked' : '' ?>>
                                        <label for="hard">
                                            <i class="fas fa-fire"></i>
                                            <span>Hard</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Media Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-image"></i>
                                Recipe Media
                            </div>

                            <div class="form-group">
                                <label><i class="fas fa-upload"></i> Recipe Image (Optional)</label>
                                <div class="file-upload-wrapper">
                                    <label for="imageUpload" class="file-upload-label">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span>Choose image (JPG, PNG, WEBP)</span>
                                    </label>
                                    <input type="file" name="image" id="imageUpload" accept="image/*">
                                </div>
                                <div class="file-info">Supported formats: JPG, JPEG, PNG, WEBP</div>
                            </div>

                            <div class="form-group">
                                <label><i class="fas fa-upload"></i> Recipe Video (Optional)</label>
                                <div class="file-upload-wrapper">
                                    <label for="videoUpload" class="file-upload-label">
                                        <i class="fas fa-film"></i>
                                        <span>Choose video (MP4, WebM, OGG - Max 50MB)</span>
                                    </label>
                                    <input type="file" name="video" id="videoUpload" accept="video/*">
                                </div>
                                <div class="file-info">Supported formats: MP4, WebM, OGG | Maximum size: 50MB</div>
                            </div>
                        </div>

                        <!-- Ingredients Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-list"></i>
                                Recipe Ingredients
                            </div>

                            <div class="ingredients-container" id="ingredientsContainer">
                                <div class="ingredient-row">
                                    <input type="text" name="ingredient_name[]" placeholder="Ingredient name" required>
                                    <input type="text" name="quantity[]" placeholder="Quantity (e.g., 2 cups)">
                                    <button type="button" class="remove-ingredient-btn" onclick="removeIngredient(this)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="button" onclick="addIngredient()" class="add-ingredient-btn">
                                <i class="fas fa-plus"></i>
                                Add Another Ingredient
                            </button>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                Save Recipe
                            </button>
                            <a href="recipe.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i>
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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

            // File upload preview
            const imageUpload = document.getElementById('imageUpload');
            const videoUpload = document.getElementById('videoUpload');

            if (imageUpload) {
                imageUpload.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const fileName = file.name;
                        const label = this.previousElementSibling.querySelector('span');
                        if (label) {
                            label.textContent = fileName;
                        }
                        
                        // Validate file size (max 5MB for images)
                        if (file.size > 5 * 1024 * 1024) {
                            alert('Image file is too large! Maximum size is 5MB');
                            e.target.value = '';
                            if (label) label.textContent = 'Choose image (JPG, PNG, WEBP)';
                        }
                    }
                });
            }

            if (videoUpload) {
                videoUpload.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const fileName = file.name;
                        const label = this.previousElementSibling.querySelector('span');
                        if (label) {
                            label.textContent = fileName;
                        }
                        
                        // Validate file size (max 50MB for videos)
                        if (file.size > 50 * 1024 * 1024) {
                            alert('Video file is too large! Maximum size is 50MB');
                            e.target.value = '';
                            if (label) label.textContent = 'Choose video (MP4, WebM, OGG - Max 50MB)';
                        }
                    }
                });
            }

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });

        // Add Ingredient
        function addIngredient() {
            const container = document.getElementById('ingredientsContainer');
            const div = document.createElement('div');
            div.className = 'ingredient-row';
            div.innerHTML = `
                <input type="text" name="ingredient_name[]" placeholder="Ingredient name" required>
                <input type="text" name="quantity[]" placeholder="Quantity (e.g., 2 cups)">
                <button type="button" class="remove-ingredient-btn" onclick="removeIngredient(this)">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(div);
            
            // Smooth scroll to new ingredient
            div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // Remove Ingredient
        function removeIngredient(button) {
            const row = button.closest('.ingredient-row');
            const container = document.getElementById('ingredientsContainer');
            
            // Don't allow removing if it's the last ingredient
            if (container.children.length <= 1) {
                alert('At least one ingredient is required!');
                return;
            }
            
            row.style.transition = 'opacity 0.3s ease';
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
            }, 300);
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const title = document.querySelector('input[name="title"]').value.trim();
            const instructions = document.querySelector('textarea[name="instructions"]').value.trim();
            
            if (!title || !instructions) {
                e.preventDefault();
                alert('Please fill in all required fields (Title and Instructions)');
                return false;
            }
            
            const ingredientNames = document.querySelectorAll('input[name="ingredient_name[]"]');
            let hasIngredient = false;
            
            ingredientNames.forEach(input => {
                if (input.value.trim() !== '') {
                    hasIngredient = true;
                }
            });
            
            if (!hasIngredient) {
                e.preventDefault();
                alert('Please add at least one ingredient!');
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });
    </script>
</body>
</html>