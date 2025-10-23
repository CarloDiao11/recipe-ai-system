<!-- Community Posts Section -->
<div class="content-section" id="communitySection">
    <div class="section-header">
        <i class="fas fa-users"></i>
        <div>
            <h2>Community Posts</h2>
            <p style="font-size: 0.9rem; opacity: 0.9;">See what the community is cooking!</p>
        </div>
    </div>
    <div class="posts-container">
        <?php if (empty($posts)): ?>
            <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                <i class="fas fa-utensils" style="font-size: 2rem; opacity: 0.5; margin-bottom: 1rem;"></i>
                <p>No posts yet. Be the first to share your recipe!</p>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <div class="post-card" data-post-id="<?= (int)$post['id'] ?>">
                    <div class="post-header">
                        <?php
                        $initials = !empty($post['initials']) ? $post['initials'] : strtoupper(substr($post['name'], 0, 1));
                        $color = !empty($post['avatar_color']) ? $post['avatar_color'] : '#6f42c1';
                        $profile_pic = !empty($post['profile_picture']) ? $post['profile_picture'] : '';
                        ?>
                        <div class="post-avatar" style="background: <?= htmlspecialchars($color) ?>;">
                            <?php if (!empty($profile_pic) && $profile_pic !== 'uploads/default_profile.png' && file_exists('../../' . $profile_pic)): ?>
                                <img src="../../<?= htmlspecialchars($profile_pic) ?>" 
                                     alt="<?= htmlspecialchars($post['name']) ?>" 
                                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?= htmlspecialchars($initials) ?>
                            <?php endif; ?>
                        </div>
                        <div class="post-author-info">
                            <div class="post-author"><?= htmlspecialchars($post['name']) ?></div>
                            <div class="post-time"><?= htmlspecialchars($post['time_ago']) ?></div>
                        </div>
                    </div>
                    <div class="post-content">
                        <?= nl2br(htmlspecialchars($post['content'])) ?>
                    </div>

                    <?php if ($post['media_type'] !== 'none' && !empty($post['media_url'])): ?>
                        <?php 
                        // Build proper media path
                        $media_path = $post['media_url'];
                        if (!str_starts_with($media_path, 'http') && !str_starts_with($media_path, '../../')) {
                            $media_path = '../../' . $media_path;
                        }
                        ?>
                        <?php if ($post['media_type'] === 'image'): ?>
                            <img src="<?= htmlspecialchars($media_path) ?>" 
                                 alt="Post image" 
                                 class="post-image" 
                                 onerror="console.error('Image not found:', this.src); this.style.display='none'">
                        <?php elseif ($post['media_type'] === 'video'): ?>
                            <video controls class="post-video">
                                <source src="<?= htmlspecialchars($media_path) ?>" type="video/mp4">
                                <source src="<?= htmlspecialchars($media_path) ?>" type="video/webm">
                                <source src="<?= htmlspecialchars($media_path) ?>" type="video/ogg">
                                Your browser does not support the video tag.
                            </video>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="post-actions">
                        <!-- Like Button -->
                        <button class="post-action-btn like-btn <?= $post['user_liked'] ? 'liked' : '' ?>" 
                                onclick="toggleLike(<?= (int)$post['id'] ?>)">
                            <i class="<?= $post['user_liked'] ? 'fas fa-heart' : 'far fa-heart' ?>"></i>
                            <span class="like-count"><?= (int)$post['likes_count'] ?></span> Likes
                        </button>

                        <!-- Comment Button -->
                        <button class="post-action-btn" onclick="toggleComments(<?= (int)$post['id'] ?>)">
                            <i class="fas fa-comment"></i>
                            <span class="comment-count"><?= (int)$post['comments_count'] ?></span> Comments
                        </button>

                        <!-- Share Button (disabled for now) -->
                        <button class="post-action-btn" disabled>
                            <i class="fas fa-share"></i> Share
                        </button>
                    </div>

                    <!-- Comments Section -->
                    <div class="comments-section" id="comments-<?= (int)$post['id'] ?>">
                        <div class="comment-input-area">
                            <?php
                            $current_profile_pic = !empty($current_user['profile_picture']) ? $current_user['profile_picture'] : '';
                            ?>
                            <div class="comment-avatar" style="background: <?= htmlspecialchars($current_user['avatar_color']) ?>;">
                                <?php if (!empty($current_profile_pic) && $current_profile_pic !== 'uploads/default_profile.png' && file_exists('../../' . $current_profile_pic)): ?>
                                    <img src="../../<?= htmlspecialchars($current_profile_pic) ?>" 
                                         alt="<?= htmlspecialchars($current_user['name']) ?>" 
                                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                <?php else: ?>
                                    <?= htmlspecialchars($current_user['initials']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="comment-input-wrapper">
                                <input type="text" class="comment-input" 
                                       placeholder="Write a comment..." 
                                       id="comment-input-<?= (int)$post['id'] ?>" 
                                       onkeypress="if(event.key === 'Enter') addComment(<?= (int)$post['id'] ?>)">
                                <button class="comment-send-btn" onclick="addComment(<?= (int)$post['id'] ?>)">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
                        <div class="comments-list" id="comments-list-<?= (int)$post['id'] ?>">
                            <?php if (isset($comments_by_post[$post['id']]) && !empty($comments_by_post[$post['id']])): ?>
                                <?php foreach ($comments_by_post[$post['id']] as $comment): ?>
                                    <?php
                                    $comment_profile_pic = !empty($comment['profile_picture']) ? $comment['profile_picture'] : '';
                                    ?>
                                    <div class="comment-item">
                                        <div class="comment-avatar" style="background: <?= htmlspecialchars($comment['avatar_color']) ?>;">
                                            <?php if (!empty($comment_profile_pic) && $comment_profile_pic !== 'uploads/default_profile.png' && file_exists('../../' . $comment_profile_pic)): ?>
                                                <img src="../../<?= htmlspecialchars($comment_profile_pic) ?>" 
                                                     alt="<?= htmlspecialchars($comment['name']) ?>" 
                                                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                            <?php else: ?>
                                                <?= htmlspecialchars($comment['initials']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="comment-content">
                                            <div class="comment-author"><?= htmlspecialchars($comment['name']) ?></div>
                                            <div class="comment-text"><?= nl2br(htmlspecialchars($comment['comment_text'])) ?></div>
                                            <div class="comment-time"><?= htmlspecialchars($comment['time_ago']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="padding: 1rem; color: var(--text-secondary); font-size: 0.9rem; text-align: center;">
                                    No comments yet. Be the first to comment!
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.post-image {
    width: 100%;
    max-height: 500px;
    object-fit: cover;
    border-radius: 8px;
    margin: 1rem 0;
}

.post-video {
    max-height: 500px;
    width: 100%;
    border-radius: 8px;
    margin: 1rem 0;
    background: #000;
}

.post-avatar img,
.comment-avatar img {
    border-radius: 50%;
}
</style>