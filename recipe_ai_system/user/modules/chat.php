<!-- Chat Section -->
<div class="content-section chat-section" id="chatSection">
    <div class="section-header">
        <i class="fas fa-comments"></i>
        <div>
            <h2>Community Chat</h2>
            <p style="font-size: 0.9rem; opacity: 0.9;">Connect with other food lovers!</p>
        </div>
    </div>
    <div class="chat-users-section">
        <div class="chat-search-wrapper">
            <i class="fas fa-search"></i>
            <input type="text" class="chat-search-input" id="chatSearchInput" placeholder="Search users..." oninput="filterUsers()">
        </div>

        <?php
        // Fetch all users (excluding current user) - FIXED: Added profile_picture
        $stmt = $conn->prepare("SELECT id, name, profile_picture, initials, avatar_color, status FROM users WHERE id != ? ORDER BY status DESC, name ASC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $all_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Fetch recent chats - FIXED: Added profile_picture
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

        // Helper: Format time ago
        function formatTimeAgo($timestamp) {
            if (!$timestamp) return '';
            $time_diff = time() - strtotime($timestamp);
            if ($time_diff < 60) {
                return 'Just now';
            } elseif ($time_diff < 3600) {
                return floor($time_diff / 60) . 'm ago';
            } elseif ($time_diff < 86400) {
                return floor($time_diff / 3600) . 'h ago';
            } else {
                return floor($time_diff / 86400) . 'd ago';
            }
        }
        ?>

        <!-- All Users -->
        <div class="all-users-section">
            <div class="section-label">All Users</div>
            <div class="users-grid" id="allUsersGrid">
                <?php if (empty($all_users)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 1rem; color: var(--text-secondary);">
                        No other users found.
                    </div>
                <?php else: ?>
                    <?php foreach ($all_users as $user): ?>
                        <div class="user-circle" onclick="openChatPopup(
                            <?= json_encode($user['name']) ?>,
                            <?= json_encode($user['initials']) ?>,
                            <?= json_encode($user['avatar_color']) ?>,
                            <?= json_encode($user['status']) ?>,
                            <?= (int)$user['id'] ?>
                        )">
                            <div class="user-circle-avatar <?= $user['status'] === 'online' ? 'online' : '' ?>" 
                                 style="background: <?= htmlspecialchars($user['avatar_color']) ?>;">
                                <?php if (!empty($user['profile_picture']) && $user['profile_picture'] !== 'uploads/default_profile.png' && file_exists('../../' . $user['profile_picture'])): ?>
                                    <img src="../../<?= htmlspecialchars($user['profile_picture']) ?>" 
                                         alt="<?= htmlspecialchars($user['name']) ?>" 
                                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                <?php else: ?>
                                    <?= htmlspecialchars($user['initials']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="user-circle-name"><?= htmlspecialchars(explode(' ', $user['name'])[0]) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Chats -->
        <div class="recent-chats-section">
            <div class="section-label">Recent Chats</div>
            <div id="recentChatsList">
                <?php if (empty($recent_chats)): ?>
                    <div style="padding: 1.5rem; text-align: center; color: var(--text-secondary);">
                        No recent chats yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_chats as $chat): ?>
                        <div class="chat-item" onclick="openChatPopup(
                            <?= json_encode($chat['name']) ?>,
                            <?= json_encode($chat['initials']) ?>,
                            <?= json_encode($chat['avatar_color']) ?>,
                            <?= json_encode($chat['status']) ?>,
                            <?= (int)$chat['id'] ?>
                        )">
                            <div class="chat-item-avatar <?= $chat['status'] === 'online' ? 'online' : '' ?>" 
                                 style="background: <?= htmlspecialchars($chat['avatar_color']) ?>;">
                                <?php if (!empty($chat['profile_picture']) && $chat['profile_picture'] !== 'uploads/default_profile.png' && file_exists('../../' . $chat['profile_picture'])): ?>
                                    <img src="../../<?= htmlspecialchars($chat['profile_picture']) ?>" 
                                         alt="<?= htmlspecialchars($chat['name']) ?>" 
                                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                <?php else: ?>
                                    <?= htmlspecialchars($chat['initials']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="chat-item-info">
                                <div class="chat-item-name"><?= htmlspecialchars($chat['name']) ?></div>
                                <div class="chat-item-message">
                                    <?= !empty($chat['last_message']) ? htmlspecialchars(substr($chat['last_message'], 0, 40)) . (strlen($chat['last_message']) > 40 ? '...' : '') : 'No messages yet' ?>
                                </div>
                            </div>
                            <div class="chat-item-time">
                                <?= formatTimeAgo($chat['last_message_time']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>