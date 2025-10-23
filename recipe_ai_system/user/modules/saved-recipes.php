<?php
session_start();
require_once '../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user information
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

// Fetch saved recipes
$stmt = $conn->prepare("
    SELECT 
        r.id,
        r.title,
        r.instructions,
        r.time,
        r.difficulty,
        r.servings,
        r.image_url,
        r.video_url,
        sr.created_at as saved_at,
        GROUP_CONCAT(CONCAT(ri.ingredient_name, '|', IFNULL(ri.quantity, '')) ORDER BY ri.id SEPARATOR ';;') as ingredients,
        CASE 
            WHEN TIMESTAMPDIFF(DAY, sr.created_at, NOW()) = 0 THEN 'Today'
            WHEN TIMESTAMPDIFF(DAY, sr.created_at, NOW()) = 1 THEN 'Yesterday'
            WHEN TIMESTAMPDIFF(DAY, sr.created_at, NOW()) < 7 THEN CONCAT(TIMESTAMPDIFF(DAY, sr.created_at, NOW()), ' days ago')
            ELSE DATE_FORMAT(sr.created_at, '%M %d, %Y')
        END as time_saved
    FROM saved_recipes sr
    JOIN recipes r ON sr.recipe_id = r.id
    LEFT JOIN recipe_ingredients ri ON r.id = ri.recipe_id
    WHERE sr.user_id = ?
    GROUP BY r.id, r.title, r.instructions, r.time, r.difficulty, r.servings, r.image_url, r.video_url, sr.created_at
    ORDER BY sr.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$saved_recipes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
    <title>Saved Recipes - Flavor Forge</title>
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

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-title i {
            color: var(--accent-orange);
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        .recipe-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2rem;
        }

        .recipe-card {
            background: var(--bg-primary);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
        }

        .recipe-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
        }

        .recipe-image-container {
            position: relative;
            height: 200px;
            overflow: hidden;
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-green));
        }

        .recipe-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .recipe-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(255, 255, 255, 0.95);
            color: var(--accent-orange);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .recipe-content {
            padding: 1.5rem;
        }

        .recipe-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }

        .recipe-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .recipe-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .recipe-meta-item i {
            color: var(--accent-orange);
        }

        .recipe-ingredients-preview {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .recipe-saved-time {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .recipe-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            flex: 1;
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
            border-color: #e74c3c;
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            opacity: 0.3;
            color: var(--accent-orange);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        .stats-bar {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-around;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        .modal.active {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: var(--bg-primary);
            border-radius: 20px;
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: slideUp 0.3s ease;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            position: relative;
            height: 300px;
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-green));
            border-radius: 20px 20px 0 0;
            overflow: hidden;
        }

        .modal-header img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(255, 255, 255, 0.95);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--text-primary);
            transition: all 0.3s ease;
            z-index: 10;
        }

        .modal-close:hover {
            background: white;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-recipe-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
        }

        .modal-recipe-meta {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--border-color);
            flex-wrap: wrap;
        }

        .modal-meta-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modal-meta-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(255, 107, 53, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent-orange);
            font-size: 1.2rem;
        }

        .modal-meta-text {
            display: flex;
            flex-direction: column;
        }

        .modal-meta-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .modal-meta-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-section {
            margin-bottom: 2rem;
        }

        .modal-section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modal-section-title i {
            color: var(--accent-orange);
        }

        .ingredients-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 0.75rem;
        }

        .ingredient-item {
            background: var(--bg-secondary);
            padding: 0.75rem 1rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.2s ease;
        }

        .ingredient-item:hover {
            transform: translateX(5px);
            background: rgba(255, 107, 53, 0.1);
        }

        .ingredient-item i {
            color: var(--accent-orange);
        }

        .ingredient-text {
            flex: 1;
        }

        .ingredient-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .ingredient-quantity {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .instructions-text {
            background: var(--bg-secondary);
            padding: 1.5rem;
            border-radius: 12px;
            line-height: 1.8;
            color: var(--text-primary);
            white-space: pre-line;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .modal-actions .btn {
            flex: 1;
        }

        @media (max-width: 768px) {
            .recipe-grid {
                grid-template-columns: 1fr;
            }

            .stats-bar {
                flex-direction: column;
                gap: 1rem;
            }

            .page-title {
                font-size: 2rem;
            }

            .modal-content {
                max-height: 95vh;
            }

            .modal-header {
                height: 200px;
            }

            .modal-recipe-meta {
                gap: 1rem;
            }

            .ingredients-list {
                grid-template-columns: 1fr;
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
            <a href="saved-recipes.php" class="nav-link active">
                <i class="fas fa-heart"></i>
                Saved Recipes
            </a>
            <a href="profile.php" class="nav-link">
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
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-heart"></i>
                Saved Recipes
            </h1>
            <p class="page-subtitle">Your collection of favorite recipes</p>
        </div>

        <!-- Stats Bar -->
        <?php if (!empty($saved_recipes)): ?>
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-value"><?= count($saved_recipes) ?></div>
                <div class="stat-label">Saved Recipes</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recipes Grid -->
        <?php if (empty($saved_recipes)): ?>
            <div class="empty-state">
                <i class="fas fa-heart-broken"></i>
                <h3>No Saved Recipes Yet</h3>
                <p>Start exploring recipes and save your favorites!</p>
                <button class="btn btn-primary" onclick="window.location.href='dashboard.php'">
                    <i class="fas fa-search"></i> Explore Recipes
                </button>
            </div>
        <?php else: ?>
            <div class="recipe-grid">
                <?php foreach ($saved_recipes as $recipe): ?>
                    <?php
                    $imagePath = '';
                    if (!empty($recipe['image_url']) && $recipe['image_url'] !== 'uploads/default.jpg') {
                        $imagePath = $recipe['image_url'];
                        if (!str_starts_with($imagePath, 'http') && !str_starts_with($imagePath, '../../')) {
                            $imagePath = '../../' . $imagePath;
                        }
                    }
                    
                    // Parse ingredients for preview
                    $ingredientsPreview = '';
                    if (!empty($recipe['ingredients'])) {
                        $ingredientsList = explode(';;', $recipe['ingredients']);
                        $names = array_map(function($item) {
                            return explode('|', $item)[0];
                        }, array_slice($ingredientsList, 0, 3));
                        $ingredientsPreview = implode(', ', $names);
                        if (count($ingredientsList) > 3) {
                            $ingredientsPreview .= '...';
                        }
                    }
                    ?>
                    <div class="recipe-card" data-recipe-id="<?= (int)$recipe['id'] ?>">
                        <div class="recipe-image-container">
                            <?php if ($imagePath && file_exists($imagePath)): ?>
                                <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars($recipe['title']) ?>" class="recipe-image">
                            <?php endif; ?>
                            <div class="recipe-badge">
                                <i class="fas fa-heart"></i> Saved
                            </div>
                        </div>
                        <div class="recipe-content">
                            <h3 class="recipe-title"><?= htmlspecialchars($recipe['title']) ?></h3>
                            
                            <div class="recipe-meta">
                                <div class="recipe-meta-item">
                                    <i class="fas fa-clock"></i>
                                    <?= htmlspecialchars($recipe['time']) ?>
                                </div>
                                <div class="recipe-meta-item">
                                    <i class="fas fa-signal"></i>
                                    <?= htmlspecialchars($recipe['difficulty']) ?>
                                </div>
                                <div class="recipe-meta-item">
                                    <i class="fas fa-users"></i>
                                    <?= htmlspecialchars($recipe['servings']) ?>
                                </div>
                            </div>

                            <?php if ($ingredientsPreview): ?>
                            <div class="recipe-ingredients-preview">
                                <strong>Ingredients:</strong> <?= htmlspecialchars($ingredientsPreview) ?>
                            </div>
                            <?php endif; ?>

                            <div class="recipe-saved-time">
                                <i class="fas fa-bookmark"></i>
                                Saved <?= htmlspecialchars($recipe['time_saved']) ?>
                            </div>

                            <div class="recipe-actions">
                                <button class="btn btn-primary" onclick="viewRecipe(<?= (int)$recipe['id'] ?>)">
                                    <i class="fas fa-book-open"></i> View Recipe
                                </button>
                                <button class="btn btn-secondary" onclick="unsaveRecipe(<?= (int)$recipe['id'] ?>, this)">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recipe Modal -->
    <div id="recipeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <img id="modalImage" src="" alt="Recipe Image">
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <h2 class="modal-recipe-title" id="modalTitle"></h2>
                
                <div class="modal-recipe-meta">
                    <div class="modal-meta-item">
                        <div class="modal-meta-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="modal-meta-text">
                            <span class="modal-meta-label">Time</span>
                            <span class="modal-meta-value" id="modalTime"></span>
                        </div>
                    </div>
                    <div class="modal-meta-item">
                        <div class="modal-meta-icon">
                            <i class="fas fa-signal"></i>
                        </div>
                        <div class="modal-meta-text">
                            <span class="modal-meta-label">Difficulty</span>
                            <span class="modal-meta-value" id="modalDifficulty"></span>
                        </div>
                    </div>
                    <div class="modal-meta-item">
                        <div class="modal-meta-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="modal-meta-text">
                            <span class="modal-meta-label">Servings</span>
                            <span class="modal-meta-value" id="modalServings"></span>
                        </div>
                    </div>
                </div>

                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-list-ul"></i>
                        Ingredients
                    </h3>
                    <div class="ingredients-list" id="modalIngredients"></div>
                </div>

                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-book"></i>
                        Instructions
                    </h3>
                    <div class="instructions-text" id="modalInstructions"></div>
                </div>

                <div class="modal-actions">
                    <button class="btn btn-primary" onclick="printRecipe()">
                        <i class="fas fa-print"></i> Print Recipe
                    </button>
                    <button class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Store recipe data for modal
        const recipesData = <?= json_encode($saved_recipes) ?>;

        function viewRecipe(recipeId) {
            const recipe = recipesData.find(r => r.id == recipeId);
            if (!recipe) return;

            // Set modal content
            document.getElementById('modalTitle').textContent = recipe.title;
            document.getElementById('modalTime').textContent = recipe.time;
            document.getElementById('modalDifficulty').textContent = recipe.difficulty;
            document.getElementById('modalServings').textContent = recipe.servings;
            document.getElementById('modalInstructions').textContent = recipe.instructions;

            // Set image
            const modalImage = document.getElementById('modalImage');
            if (recipe.image_url && recipe.image_url !== 'uploads/default.jpg') {
                let imagePath = recipe.image_url;
                if (!imagePath.startsWith('http') && !imagePath.startsWith('../../')) {
                    imagePath = '../../' + imagePath;
                }
                modalImage.src = imagePath;
            } else {
                modalImage.src = '';
            }

            // Parse and display ingredients
            const ingredientsContainer = document.getElementById('modalIngredients');
            ingredientsContainer.innerHTML = '';
            
            if (recipe.ingredients) {
                const ingredientsList = recipe.ingredients.split(';;');
                ingredientsList.forEach(item => {
                    const [name, quantity] = item.split('|');
                    const ingredientDiv = document.createElement('div');
                    ingredientDiv.className = 'ingredient-item';
                    ingredientDiv.innerHTML = `
                        <i class="fas fa-check-circle"></i>
                        <div class="ingredient-text">
                            <div class="ingredient-name">${name}</div>
                            ${quantity ? `<div class="ingredient-quantity">${quantity}</div>` : ''}
                        </div>
                    `;
                    ingredientsContainer.appendChild(ingredientDiv);
                });
            }

            // Show modal
            document.getElementById('recipeModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('recipeModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function printRecipe() {
            window.print();
        }

        // Close modal on outside click
        document.getElementById('recipeModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        function unsaveRecipe(recipeId, button) {
            if (!confirm('Remove this recipe from your saved collection?')) {
                return;
            }

            fetch('../../api/save_recipe.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `recipe_id=${recipeId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove card with animation
                    const card = button.closest('.recipe-card');
                    card.style.transform = 'scale(0)';
                    card.style.opacity = '0';
                    setTimeout(() => {
                        card.remove();
                        
                        // Remove from recipesData
                        const index = recipesData.findIndex(r => r.id == recipeId);
                        if (index > -1) {
                            recipesData.splice(index, 1);
                        }
                        
                        // Check if no recipes left
                        const grid = document.querySelector('.recipe-grid');
                        if (grid && grid.children.length === 0) {
                            location.reload();
                        }
                    }, 300);
                } else {
                    alert(data.message || 'Failed to remove recipe');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
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