<!-- Sidebar -->
<div class="sidebar">
    <!-- Create Post Section -->
    <div class="sidebar-section">
        <div class="sidebar-title">
            <i class="fas fa-newspaper"></i>
            Create Post
        </div>
        <div class="post-form">
            <textarea class="post-input" id="postInput" placeholder="Share your cooking experience..."></textarea>
            
            <div class="media-preview" id="mediaPreview">
                <img id="imagePreview" class="media-preview-content" style="display: none;">
                <video id="videoPreview" class="media-preview-content" controls style="display: none;"></video>
                <button class="remove-media-btn" onclick="removeMedia()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="post-media-controls">
                <label class="media-upload-btn">
                    <i class="fas fa-image"></i>
                    <span>Add Photo</span>
                    <input type="file" accept="image/*" onchange="handleImageUpload(event)">
                </label>
                <label class="media-upload-btn">
                    <i class="fas fa-video"></i>
                    <span>Add Video</span>
                    <input type="file" accept="video/*" onchange="handleVideoUpload(event)">
                </label>
            </div>
            
            <button class="post-btn" onclick="createPost()">
                <i class="fas fa-share"></i> Post to Community
            </button>
        </div>
    </div>

    <!-- Trending Recipes Section -->
    <div class="sidebar-section">
        <div class="sidebar-title">
            <i class="fas fa-fire"></i>
            Trending Recipes
        </div>
        <?php
        // Fetch top 5 trending recipes based on view count in recipe_history
        $stmt = $conn->prepare("
            SELECT 
                r.id,
                r.title,
                COUNT(rh.id) as view_count
            FROM recipe_history rh
            JOIN recipes r ON rh.recipe_id = r.id
            GROUP BY r.id, r.title
            ORDER BY view_count DESC
            LIMIT 5
        ");
        $stmt->execute();
        $trending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($trending)): ?>
            <div style="color: var(--text-secondary); font-size: 0.9rem; padding: 0.5rem 0;">
                No trending recipes yet.
            </div>
        <?php else: ?>
            <div style="color: var(--text-secondary); font-size: 0.9rem;">
                <?php foreach ($trending as $index => $recipe): ?>
                    <div style="padding: 0.5rem 0; <?= $index < count($trending) - 1 ? 'border-bottom: 1px solid var(--border-color);' : '' ?>">
                        🔥 <a href="view_recipe.php?id=<?= (int)$recipe['id'] ?>" 
                              style="color: var(--text-primary); text-decoration: none; font-weight: 500;">
                            <?= htmlspecialchars($recipe['title']) ?>
                        </a> - <?= (int)$recipe['view_count'] ?> views
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>