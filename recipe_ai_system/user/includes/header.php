<?php
// Start session if not already started (must be before any output)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get the correct path to database config based on current file location
$base_path = dirname(dirname(__FILE__)); // Goes up from includes/ to root
require_once $base_path . '../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $base_path . '../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user information
$stmt = $conn->prepare("SELECT id, username, name, email, initials, avatar_color, role, status FROM users WHERE id = ?");
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

// Get current page for active nav
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>

<div class="header">
    <div class="logo">
        <i class="fas fa-fire"></i>
        Flavor Forge
    </div>
    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
        <i class="fas fa-bars"></i>
    </button>
    <nav class="header-nav">
        <a href="ai-generator.php" class="nav-link <?= $current_page === 'ai-generator' ? 'active' : '' ?>">
            <i class="fas fa-robot"></i>
            AI Generator
        </a>
        <a href="chat.php" class="nav-link <?= $current_page === 'chat' ? 'active' : '' ?>">
            <i class="fas fa-comments"></i>
            Chat
        </a>
        <a href="meal-planner.php" class="nav-link <?= $current_page === 'meal-planner' ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt"></i>
            Meal Planner
        </a>
        <a href="community.php" class="nav-link <?= $current_page === 'community' ? 'active' : '' ?>">
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
                <div class="user-avatar-header" style="background: <?= $user['avatar_color'] ?>;">
                    <?= htmlspecialchars($user['initials']) ?>
                </div>
                <span style="color: var(--text-primary); font-weight: 600;">
                    <?= htmlspecialchars($user['name']) ?>
                </span>
                <i class="fas fa-chevron-down" style="color: var(--text-secondary); font-size: 0.8rem;"></i>
            </button>
            <div class="dropdown-menu" id="userDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-avatar" style="background: <?= $user['avatar_color'] ?>;">
                        <?= htmlspecialchars($user['initials']) ?>
                    </div>
                    <div style="font-weight: 600;"><?= htmlspecialchars($user['name']) ?></div>
                    <div style="font-size: 0.9rem; opacity: 0.9;"><?= htmlspecialchars($user['email']) ?></div>
                </div>
                <a href="profile.php" class="dropdown-item">
                    <i class="fas fa-user"></i>
                    <span>My Profile</span>
                </a>
                <a href="settings.php" class="dropdown-item">
                    <i class="fas fa-cog"></i>
                    <span>Account Settings</span>
                </a>
                <a href="saved-recipes.php" class="dropdown-item">
                    <i class="fas fa-heart"></i>
                    <span>Saved Recipes</span>
                </a>
                <a href="recipe-history.php" class="dropdown-item">
                    <i class="fas fa-history"></i>
                    <span>Recipe History</span>
                </a>
                <div style="border-top: 2px solid var(--border-color); margin: 0.5rem 0;"></div>
                <a href="#" class="dropdown-item logout" onclick="showLogoutModal(event)">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Navigation -->
<div class="mobile-nav" id="mobileNav">
    <a href="ai-generator.php" class="nav-link <?= $current_page === 'ai-generator' ? 'active' : '' ?>" onclick="closeMobileMenu()">
        <i class="fas fa-robot"></i>
        AI Generator
    </a>
    <a href="chat.php" class="nav-link <?= $current_page === 'chat' ? 'active' : '' ?>" onclick="closeMobileMenu()">
        <i class="fas fa-comments"></i>
        Chat
    </a>
    <a href="meal-planner.php" class="nav-link <?= $current_page === 'meal-planner' ? 'active' : '' ?>" onclick="closeMobileMenu()">
        <i class="fas fa-calendar-alt"></i>
        Meal Planner
    </a>
    <a href="community.php" class="nav-link <?= $current_page === 'community' ? 'active' : '' ?>" onclick="closeMobileMenu()">
        <i class="fas fa-users"></i>
        Community Posts
    </a>
</div>

<!-- Logout Confirmation Modal -->
<div class="logout-modal" id="logoutModal">
    <div class="logout-modal-content">
        <div class="logout-modal-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        <h2>Confirm Logout</h2>
        <p>Are you sure you want to logout?</p>
        <div class="logout-modal-actions">
            <button class="btn-cancel" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="btn-logout" onclick="confirmLogout()">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </button>
        </div>
    </div>
</div>

<style>
/* Logout Modal Styles */
.logout-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 10000;
    backdrop-filter: blur(4px);
    animation: fadeIn 0.3s ease;
}

.logout-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.logout-modal-content {
    background: var(--bg-secondary);
    border-radius: 20px;
    padding: 2.5rem;
    max-width: 400px;
    width: 90%;
    text-align: center;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    animation: slideUp 0.3s ease;
    border: 2px solid var(--border-color);
}

@keyframes slideUp {
    from {
        transform: translateY(30px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.logout-modal-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 1.5rem;
    background: linear-gradient(135deg, #dc3545, #c82333);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.logout-modal-icon i {
    font-size: 2.5rem;
    color: white;
}

.logout-modal-content h2 {
    color: var(--text-primary);
    margin-bottom: 1rem;
    font-size: 1.8rem;
    font-weight: 700;
}

.logout-modal-content p {
    color: var(--text-secondary);
    margin-bottom: 2rem;
    font-size: 1rem;
    line-height: 1.6;
}

.logout-modal-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
}

.logout-modal-actions button {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 120px;
    justify-content: center;
}

.btn-cancel {
    background: var(--bg-primary);
    color: var(--text-primary);
    border: 2px solid var(--border-color);
}

.btn-cancel:hover {
    background: var(--bg-secondary);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--shadow);
}

.btn-logout {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: white;
}

.btn-logout:hover {
    background: linear-gradient(135deg, #c82333, #bd2130);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
}

.btn-cancel:active,
.btn-logout:active {
    transform: scale(0.95);
}

@media (max-width: 768px) {
    .logout-modal-content {
        padding: 2rem 1.5rem;
    }
    
    .logout-modal-icon {
        width: 60px;
        height: 60px;
    }
    
    .logout-modal-icon i {
        font-size: 2rem;
    }
    
    .logout-modal-content h2 {
        font-size: 1.5rem;
    }
    
    .logout-modal-actions {
        flex-direction: column;
    }
    
    .logout-modal-actions button {
        width: 100%;
    }
}
</style>

<script>
// Logout Modal Functions
function showLogoutModal(event) {
    if (event) event.preventDefault();
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.classList.add('active');
    }
}

function closeLogoutModal() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

function confirmLogout() {
    // Redirect to logout page
    window.location.href = '../../logout.php';
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('logoutModal');
    if (modal && event.target === modal) {
        closeLogoutModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeLogoutModal();
    }
});
</script>

<script src="../assets/js/header.js"></script>