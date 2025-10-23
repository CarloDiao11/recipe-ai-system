<?php
// No need for getFullMediaUrl() — we'll use relative paths from the current location
?>

<div class="container">
    <div class="content-section ai-chat-section active" id="aiSection">
        <div class="section-header">
            <i class="fas fa-robot"></i>
            <div>
                <h2>AI Recipe Generator</h2>
                <p style="font-size: 0.9rem; opacity: 0.9;">Enter your ingredients and get instant recipe suggestions!</p>
            </div>
        </div>

        <!-- AI Chat Messages -->
        <div class="chat-messages" id="aiChatMessages">
            <?php if (empty($_POST['ingredients'])): ?>
                <div class="message">
                    <div class="message-avatar"><i class="fas fa-robot"></i></div>
                    <div class="message-content">
                        <p>Hello! 👋 I'm your Recipe AI. Type ingredients like <strong>"chicken, garlic, soy sauce, vinegar, onion"</strong> to get started!</p>
                        <p style="margin-top: 0.5rem; font-size: 0.9rem; opacity: 0.8;">💡 Tip: The more ingredients you provide, the better matches you'll get!</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($_POST['ingredients'])): ?>
                <?php
                $input = trim($_POST['ingredients']);
                $userIngredients = array_filter(array_map('trim', explode(',', strtolower($input))));

                $recipes = [];
                if (!empty($userIngredients)) {
                    $userIngredients = array_map('strtolower', $userIngredients);
                    $placeholders = str_repeat('?,', count($userIngredients) - 1) . '?';

                    $sql = "
                        SELECT 
                            r.id,
                            r.title,
                            r.instructions,
                            r.time,
                            r.difficulty,
                            r.servings,
                            r.image_url,
                            r.video_url,
                            GROUP_CONCAT(ri.ingredient_name ORDER BY ri.id) as ingredient_list
                        FROM recipes r
                        INNER JOIN recipe_ingredients ri ON r.id = ri.recipe_id
                        WHERE LOWER(ri.ingredient_name) IN ($placeholders)
                        GROUP BY r.id, r.title, r.instructions, r.time, r.difficulty, r.servings, r.image_url, r.video_url
                        HAVING COUNT(DISTINCT ri.ingredient_name) >= 3
                           OR (COUNT(DISTINCT ri.ingredient_name) / (
                                SELECT COUNT(*) FROM recipe_ingredients WHERE recipe_id = r.id
                              )) >= 0.5
                        ORDER BY COUNT(DISTINCT ri.ingredient_name) DESC
                        LIMIT 5
                    ";

                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $types = str_repeat('s', count($userIngredients));
                        $stmt->bind_param($types, ...$userIngredients);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $recipes = $result->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                    }
                }

                // User message - FIXED: Added profile picture support
                ?>
                <div class="message user">
                    <div class="message-avatar" style="background: <?= htmlspecialchars($current_user['avatar_color']) ?>;">
                        <?php if (!empty($current_user['profile_picture']) && $current_user['profile_picture'] !== 'uploads/default_profile.png' && file_exists('../../' . $current_user['profile_picture'])): ?>
                            <img src="../../<?= htmlspecialchars($current_user['profile_picture']) ?>" 
                                 alt="<?= htmlspecialchars($current_user['name']) ?>" 
                                 style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <?= htmlspecialchars($current_user['initials']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="message-content">
                        <p><?= htmlspecialchars($_POST['ingredients']) ?></p>
                    </div>
                </div>

                <?php if (empty($recipes)): ?>
                    <div class="message">
                        <div class="message-avatar"><i class="fas fa-robot"></i></div>
                        <div class="message-content">
                            <p>Sorry, I couldn't find any recipes with those ingredients. 😔<br><br>Try common Filipino ingredients like: <strong>chicken, pork, soy sauce, vinegar, garlic, onion, tomato, fish sauce, rice</strong></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="message">
                        <div class="message-avatar"><i class="fas fa-robot"></i></div>
                        <div class="message-content">
                            <p>Great! I found <strong><?= count($recipes) ?> recipe<?= count($recipes) > 1 ? 's' : '' ?></strong> you can make! 🍳<br><br>Based on your ingredients: <strong><?= htmlspecialchars($_POST['ingredients']) ?></strong></p>
                        </div>
                    </div>

                    <?php foreach ($recipes as $recipe): 
                        $recipeIngredients = array_map('trim', explode(',', $recipe['ingredient_list']));
                        $matched = array_filter($recipeIngredients, function($ing) use ($userIngredients) {
                            foreach ($userIngredients as $userIng) {
                                if (strpos(strtolower($ing), $userIng) !== false || strpos($userIng, strtolower($ing)) !== false) {
                                    return true;
                                }
                            }
                            return false;
                        });
                        $matched = array_values($matched);
                        $needs = array_values(array_diff($recipeIngredients, $matched));
                        $matchPercentage = count($recipeIngredients) > 0 ? round((count($matched) / count($recipeIngredients)) * 100) : 0;
                        
                        // Check if recipe is already saved
                        $checkSaved = $conn->prepare("SELECT id FROM saved_recipes WHERE user_id = ? AND recipe_id = ?");
                        $checkSaved->bind_param("ii", $user_id, $recipe['id']);
                        $checkSaved->execute();
                        $isSaved = $checkSaved->get_result()->num_rows > 0;
                        $checkSaved->close();
                    ?>

                    <div class="message">
                        <div class="message-avatar"><i class="fas fa-robot"></i></div>
                        <div class="message-content" style="max-width: 90%;">
                            <div class="recipe-card">
                                <div class="recipe-card-header">
                                    <div>
                                        <div class="recipe-title"><?= htmlspecialchars($recipe['title']) ?></div>
                                        <div class="recipe-meta">
                                            <div class="recipe-meta-item"><i class="fas fa-clock"></i> <?= htmlspecialchars($recipe['time']) ?></div>
                                            <div class="recipe-meta-item"><i class="fas fa-signal"></i> <?= htmlspecialchars($recipe['difficulty']) ?></div>
                                            <div class="recipe-meta-item"><i class="fas fa-users"></i> <?= htmlspecialchars($recipe['servings']) ?></div>
                                        </div>
                                    </div>
                                </div>

                                <?php 
                                // Show only image in preview - FIXED: Proper path handling
                                $imagePath = '';
                                if (!empty($recipe['image_url']) && $recipe['image_url'] !== 'uploads/default.jpg') {
                                    $imagePath = $recipe['image_url'];
                                    if (!str_starts_with($imagePath, 'http') && !str_starts_with($imagePath, '../../')) {
                                        $imagePath = '../../' . $imagePath;
                                    }
                                }
                                $hasImage = $imagePath && file_exists($imagePath);
                                ?>

                                <?php if ($hasImage): ?>
                                <div class="recipe-media">
                                    <img src="<?= htmlspecialchars($imagePath) ?>" 
                                         alt="<?= htmlspecialchars($recipe['title']) ?>" 
                                         class="recipe-image" 
                                         onerror="this.style.display='none'">
                                </div>
                                <?php endif; ?>

                                <div style="margin: 1rem 0; padding: 0.75rem; background: var(--bg-primary); border-radius: 8px;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                        <div style="flex: 1; height: 8px; background: var(--border-color); border-radius: 10px; overflow: hidden;">
                                            <div style="width: <?= $matchPercentage ?>%; height: 100%; background: linear-gradient(90deg, var(--accent-green), var(--accent-orange)); border-radius: 10px;"></div>
                                        </div>
                                        <span style="font-weight: bold; color: var(--accent-orange); font-size: 0.9rem;"><?= $matchPercentage ?>% Match</span>
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                        You have <strong style="color: var(--accent-green);"><?= count($matched) ?></strong> of <strong><?= count($recipeIngredients) ?></strong> ingredients
                                    </div>
                                </div>

                                <?php if (!empty($matched)): ?>
                                <div style="margin-bottom: 0.75rem;">
                                    <div style="font-weight: 600; color: var(--accent-green); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fas fa-check-circle"></i> You Have (<?= count($matched) ?>):
                                    </div>
                                    <div class="recipe-ingredients">
                                        <?php foreach ($matched as $ing): ?>
                                            <span class="ingredient-tag" style="background: rgba(74, 124, 78, 0.1); border-color: var(--accent-green); color: var(--accent-green);">
                                                <i class="fas fa-check" style="font-size: 0.7rem;"></i> <?= htmlspecialchars($ing) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($needs)): ?>
                                <div style="margin-bottom: 0.75rem;">
                                    <div style="font-weight: 600; color: var(--accent-orange); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fas fa-shopping-cart"></i> You Need (<?= count($needs) ?>):
                                    </div>
                                    <div class="recipe-ingredients">
                                        <?php foreach ($needs as $ing): ?>
                                            <span class="ingredient-tag" style="background: rgba(255, 107, 53, 0.1); border-color: var(--accent-orange); color: var(--accent-orange);">
                                                <i class="fas fa-plus" style="font-size: 0.7rem;"></i> <?= htmlspecialchars($ing) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div style="display: flex; gap: 0.5rem; margin-bottom: 0.75rem;">
                                    <button class="recipe-select-btn" onclick="toggleRecipeDetails(this, <?= (int)$recipe['id'] ?>)" style="flex: 1;">
                                        <i class="fas fa-book-open"></i> View Full Recipe
                                    </button>
                                    <button class="recipe-save-btn <?= $isSaved ? 'saved' : '' ?>" 
                                            onclick="toggleSaveRecipe(<?= (int)$recipe['id'] ?>, this)" 
                                            title="<?= $isSaved ? 'Remove from saved' : 'Save recipe' ?>"
                                            data-recipe-id="<?= (int)$recipe['id'] ?>">
                                        <i class="<?= $isSaved ? 'fas' : 'far' ?> fa-heart"></i>
                                    </button>
                                </div>

                                <div class="recipe-details" id="recipe-details-<?= (int)$recipe['id'] ?>">
                                    <?php 
                                    // Video is shown only in full recipe view - FIXED: Proper path handling
                                    $videoPath = '';
                                    if (!empty($recipe['video_url']) && $recipe['video_url'] !== 'uploads/default.mp4') {
                                        $videoPath = $recipe['video_url'];
                                        if (!str_starts_with($videoPath, 'http') && !str_starts_with($videoPath, '../../')) {
                                            $videoPath = '../../' . $videoPath;
                                        }
                                    }
                                    $hasVideo = $videoPath && file_exists($videoPath);
                                    ?>

                                    <?php if ($hasImage): ?>
                                    <div class="recipe-media-full">
                                        <img src="<?= htmlspecialchars($imagePath) ?>" 
                                             alt="<?= htmlspecialchars($recipe['title']) ?>" 
                                             class="recipe-image-full">
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($hasVideo): ?>
                                    <div class="recipe-media-full">
                                        <video controls class="recipe-video-full" preload="metadata">
                                            <source src="<?= htmlspecialchars($videoPath) ?>" type="video/mp4">
                                            <source src="<?= htmlspecialchars($videoPath) ?>" type="video/webm">
                                            <source src="<?= htmlspecialchars($videoPath) ?>" type="video/ogg">
                                            Your browser does not support the video tag.
                                        </video>
                                    </div>
                                    <?php endif; ?>

                                    <div class="recipe-instructions">
                                        <h4 style="color: var(--text-primary); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                                            <i class="fas fa-list-ol"></i> Instructions
                                        </h4>
                                        <?= nl2br(htmlspecialchars($recipe['instructions'])) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Input Form -->
        <form method="POST" action="#aiSection" class="chat-input-area">
            <input type="text" name="ingredients" class="chat-input" placeholder="e.g., chicken, soy sauce, vinegar, garlic, onion..." required>
            <button type="submit" class="send-btn">
                <i class="fas fa-paper-plane"></i> Generate
            </button>
        </form>
    </div>
</div>

<style>
/* Message Avatar Styles */
.message-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
    overflow: hidden;
}

.message-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

/* Save Recipe Button Styles */
.recipe-save-btn {
    padding: 10px 15px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 50px;
}

.recipe-save-btn i {
    font-size: 1.2rem;
    color: var(--text-secondary);
    transition: all 0.3s ease;
}

.recipe-save-btn:hover {
    border-color: #e74c3c;
    background: rgba(231, 76, 60, 0.1);
}

.recipe-save-btn:hover i {
    color: #e74c3c;
}

.recipe-save-btn.saved {
    border-color: #e74c3c;
    background: rgba(231, 76, 60, 0.1);
}

.recipe-save-btn.saved i {
    color: #e74c3c;
}

/* Recipe Details Full View */
.recipe-details {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.recipe-details.active {
    max-height: 2000px;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 2px solid var(--border-color);
}

.recipe-media-full {
    margin: 1rem 0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.recipe-image-full {
    width: 100%;
    height: auto;
    max-height: 400px;
    object-fit: cover;
    display: block;
}

.recipe-video-full {
    width: 100%;
    height: auto;
    max-height: 400px;
    background: #000;
    display: block;
    border-radius: 8px;
}

.recipe-instructions {
    padding: 1rem;
    background: var(--bg-secondary);
    border-radius: 8px;
    line-height: 1.8;
    color: var(--text-secondary);
}

/* Animation for save button */
@keyframes heartBeat {
    0%, 100% { transform: scale(1); }
    25% { transform: scale(1.3); }
    50% { transform: scale(1.1); }
}

.recipe-save-btn.saving i {
    animation: heartBeat 0.5s ease;
}
</style>

<script>
function toggleRecipeDetails(button, recipeId) {
    const details = document.getElementById(`recipe-details-${recipeId}`);
    if (!details) return;

    details.classList.toggle('active');
    if (details.classList.contains('active')) {
        button.innerHTML = '<i class="fas fa-times"></i> Hide Recipe';
        button.style.background = 'var(--text-secondary)';
        button.style.color = 'white';
    } else {
        button.innerHTML = '<i class="fas fa-book-open"></i> View Full Recipe';
        button.style.background = 'var(--accent-orange)';
        button.style.color = 'white';
    }
}

function toggleSaveRecipe(recipeId, button) {
    // Add animation
    button.classList.add('saving');
    
    fetch('../../api/save_recipe.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `recipe_id=${recipeId}`
    })
    .then(response => response.json())
    .then(data => {
        setTimeout(() => {
            button.classList.remove('saving');
            
            if (data.success) {
                const icon = button.querySelector('i');
                if (data.action === 'saved') {
                    button.classList.add('saved');
                    icon.className = 'fas fa-heart';
                    button.title = 'Remove from saved';
                } else {
                    button.classList.remove('saved');
                    icon.className = 'far fa-heart';
                    button.title = 'Save recipe';
                }
            } else {
                alert(data.message || 'Failed to save recipe');
            }
        }, 500);
    })
    .catch(error => {
        console.error('Error:', error);
        button.classList.remove('saving');
        alert('An error occurred. Please try again.');
    });
}
</script>