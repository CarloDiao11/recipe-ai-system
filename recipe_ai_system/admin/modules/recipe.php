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
// Handle recipe deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_recipe'])) {
    $recipe_id = (int)$_POST['recipe_id'];
    // Delete recipe
    $delete_query = "DELETE FROM recipes WHERE id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("i", $recipe_id);
    if ($stmt->execute()) {
        // Also delete associated ingredients
        $conn->query("DELETE FROM recipe_ingredients WHERE recipe_id = $recipe_id");
        $_SESSION['success_message'] = "Recipe deleted successfully!";
    } else {
        $_SESSION['error_message'] = "Failed to delete recipe.";
    }
    $stmt->close();
    header("Location: recipe.php");
    exit();
}
// === RECIPE STATISTICS ===
$stats_query = "
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN difficulty = 'Easy' THEN 1 ELSE 0 END) AS easy,
        SUM(CASE WHEN difficulty = 'Medium' THEN 1 ELSE 0 END) AS medium,
        SUM(CASE WHEN difficulty = 'Hard' THEN 1 ELSE 0 END) AS hard
    FROM recipes";
$stats = $conn->query($stats_query)->fetch_assoc();
// === NEW RECIPES THIS MONTH ===
$new_recipes_query = "SELECT COUNT(*) as count FROM recipes 
                      WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
                      AND YEAR(created_at) = YEAR(CURRENT_DATE())";
$new_recipes_month = $conn->query($new_recipes_query)->fetch_assoc()['count'];
// === PAGINATION SETUP ===
$recipes_per_page = 12;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $recipes_per_page;
// === FILTER & SEARCH SETUP ===
$difficulty_filter = isset($_GET['difficulty']) && $_GET['difficulty'] !== 'all' ? $_GET['difficulty'] : null;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : null;
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
// Build WHERE clause
$where_conditions = [];
$params = [];
$param_types = "";
if ($difficulty_filter) {
    $where_conditions[] = "r.difficulty = ?";
    $params[] = $difficulty_filter;
    $param_types .= "s";
}
if ($search_term) {
    $where_conditions[] = "r.title LIKE ?";
    $params[] = "%$search_term%";
    $param_types .= "s";
}
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
// Build ORDER BY clause
switch ($sort_by) {
    case 'oldest':
        $order_by = "ORDER BY r.created_at ASC";
        break;
    case 'title':
        $order_by = "ORDER BY r.title ASC";
        break;
    case 'newest':
    default:
        $order_by = "ORDER BY r.created_at DESC";
        break;
}
// === COUNT TOTAL FILTERED RECIPES ===
$count_query = "SELECT COUNT(*) as total FROM recipes r $where_clause";
if (!empty($params)) {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $total_recipes = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_recipes = $conn->query($count_query)->fetch_assoc()['total'];
}
$total_pages = ceil($total_recipes / $recipes_per_page);
// === FETCH RECIPES WITH USER INFO ===
$recipes_query = "
    SELECT 
        r.id, r.title, r.image_url, r.time, r.servings, r.difficulty, r.created_at, r.instructions,
        u.name AS creator_name, u.username, u.profile_picture, u.initials, u.avatar_color
    FROM recipes r
    LEFT JOIN users u ON r.created_by = u.id
    $where_clause
    $order_by
    LIMIT ? OFFSET ?";
$params[] = $recipes_per_page;
$params[] = $offset;
$param_types .= "ii";
$recipes_stmt = $conn->prepare($recipes_query);
if (!empty($params)) {
    $recipes_stmt->bind_param($param_types, ...$params);
}
$recipes_stmt->execute();
$recipes_result = $recipes_stmt->get_result();
// === FETCH UNREAD NOTIFICATIONS COUNT ===
$notif_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$unread_count = $notif_stmt->get_result()->fetch_assoc()['count'];
$notif_stmt->close();
// === FETCH RECENT NOTIFICATIONS ===
$notifications_query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
$notif_list_stmt = $conn->prepare($notifications_query);
$notif_list_stmt->bind_param("i", $user_id);
$notif_list_stmt->execute();
$notifications = $notif_list_stmt->get_result();
$notif_list_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flavor Forge - Recipe Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/header.css">
    <style>
        /* ... (your existing CSS remains unchanged) ... */
        /* Content Area */
        .content {
            padding: 40px;
            background: var(--bg-secondary);
        }
        /* Stats Cards */
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
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        .stat-icon.total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-icon.easy { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .stat-icon.medium { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .stat-icon.hard { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-info h3 {
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 5px;
        }
        .stat-info .stat-value {
            color: var(--text-primary);
            font-size: 28px;
            font-weight: 700;
        }
        /* Recipe Section */
        .recipe-section {
            background: var(--bg-primary);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px var(--shadow);
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .section-header h2 {
            color: var(--text-primary);
            font-size: 22px;
            font-weight: 600;
        }
        .section-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .filter-select {
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
        }
        .search-box {
            position: relative;
        }
        .search-box input {
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            width: 250px;
            transition: all 0.3s ease;
        }
        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }
        .btn-primary {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        /* Recipe Grid */
        .recipe-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-top: 25px;
        }
        .recipe-card {
            background: var(--bg-secondary);
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 2px 8px var(--shadow);
        }
        .recipe-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px var(--shadow);
        }
        .recipe-image {
            width: 100%;
            height: 200px;
            position: relative;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .recipe-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1;
        }
        .recipe-image i {
            color: white;
            font-size: 48px;
            z-index: 0;
        }
        .recipe-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            backdrop-filter: blur(10px);
            z-index: 2;
        }
        .recipe-badge.easy { background: rgba(67, 233, 123, 0.9); color: white; }
        .recipe-badge.medium { background: rgba(250, 112, 154, 0.9); color: white; }
        .recipe-badge.hard { background: rgba(240, 147, 251, 0.9); color: white; }
        .recipe-content {
            padding: 20px;
        }
        .recipe-title {
            color: var(--text-primary);
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            display: -webkit-box;
            line-clamp: 2;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .recipe-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-secondary);
            font-size: 13px;
        }
        .meta-item i {
            color: var(--primary);
        }
        .recipe-creator {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-top: 15px;
            border-top: 1px solid var(--border);
        }
        .creator-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
        }
        .creator-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .creator-info {
            flex: 1;
        }
        .creator-name {
            color: var(--text-primary);
            font-size: 13px;
            font-weight: 500;
        }
        .creator-date {
            color: var(--text-secondary);
            font-size: 11px;
        }
        .recipe-actions {
            display: flex;
            gap: 8px;
        }
        .btn-icon {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 14px;
        }
        .btn-view { background: #e3f2fd; color: #1976d2; }
        .btn-view:hover { background: #1976d2; color: white; }
        .btn-edit { background: #fff3e0; color: #f57c00; }
        .btn-edit:hover { background: #f57c00; color: white; }
        .btn-delete { background: #ffebee; color: #c62828; }
        .btn-delete:hover { background: #c62828; color: white; }
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: var(--text-primary);
        }
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }
        .pagination a,
        .pagination button {
            padding: 8px 12px;
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            min-width: 38px;
            text-align: center;
        }
        .pagination a:hover:not(.active),
        .pagination button:hover:not(:disabled) {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }
        .modal.show {
            display: flex;
        }
        .modal-content {
            background: var(--bg-primary);
            border-radius: 20px;
            padding: 0;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            animation: modalSlideIn 0.3s ease;
        }
        .modal-content.small {
            max-width: 500px;
        }
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px) scale(0.9);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }
        .modal-header {
            padding: 25px 30px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h3 {
            color: var(--text-primary);
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .modal-header i {
            font-size: 24px;
        }
        .modal-close {
            width: 35px;
            height: 35px;
            border: none;
            background: var(--bg-secondary);
            color: var(--text-secondary);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 18px;
        }
        .modal-close:hover {
            background: #e74c3c;
            color: white;
            transform: rotate(90deg);
        }
        .modal-body {
            padding: 30px;
        }
        /* View Modal Specific */
        .recipe-detail-image {
            width: 100%;
            height: 300px;
            object-fit: cover;
            border-radius: 15px;
            margin-bottom: 20px;
        }
        .recipe-detail-header {
            margin-bottom: 20px;
        }
        .recipe-detail-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 15px;
        }
        .recipe-detail-meta {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
        }
        .recipe-detail-section {
            margin-bottom: 25px;
        }
        .recipe-detail-section h4 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .recipe-detail-section p {
            color: var(--text-secondary);
            line-height: 1.8;
            white-space: pre-wrap;
        }
        /* Delete Modal Specific */
        .delete-modal-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #fee;
            color: #e74c3c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin: 0 auto 20px;
        }
        .delete-modal-text {
            text-align: center;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 25px;
        }
        .delete-modal-recipe {
            text-align: center;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 18px;
            margin-bottom: 25px;
        }
        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-cancel {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        .btn-cancel:hover {
            background: var(--border);
        }
        .btn-delete-confirm {
            background: #e74c3c;
            color: white;
        }
        .btn-delete-confirm:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }
        /* Success/Error Messages */
        .alert-message {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            display: none;
            align-items: center;
            gap: 12px;
            z-index: 1001;
            animation: slideInRight 0.3s ease;
            max-width: 400px;
        }
        .alert-message.show {
            display: flex;
        }
        .alert-message.success {
            background: #27ae60;
            color: white;
        }
        .alert-message.error {
            background: #e74c3c;
            color: white;
        }
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
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
        /* Responsive Design for Recipe Management Page */

/* Tablet Adjustments (769px - 1024px) */
@media (max-width: 1024px) {
    .content {
        padding: 30px 20px;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }

    .stat-card {
        padding: 20px;
    }

    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }

    .stat-info .stat-value {
        font-size: 24px;
    }

    .recipe-section {
        padding: 25px;
    }

    .recipe-grid {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
    }

    .search-box input {
        width: 200px;
    }
}

/* Mobile Adjustments (≤768px) */
@media (max-width: 768px) {
    /* Sidebar */
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }

    .sidebar.active {
        transform: translateX(0);
    }

    .main-content {
        margin-left: 0;
        width: 100%;
    }

    .menu-toggle {
        display: block;
    }

    /* Header */
    .header {
        padding: 15px 20px;
    }

    .header-left h1 {
        font-size: 18px;
        margin-left: 10px;
    }

    .profile-info {
        display: none;
    }

    .profile-button {
        min-width: auto;
    }

    /* Content */
    .content {
        padding: 20px 15px;
    }

    /* Stats Grid - 2 columns */
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .stat-card {
        padding: 15px;
        flex-direction: row;
        gap: 12px;
    }

    .stat-icon {
        width: 45px;
        height: 45px;
        font-size: 18px;
        flex-shrink: 0;
    }

    .stat-info {
        flex: 1;
    }

    .stat-info h3 {
        font-size: 11px;
        margin-bottom: 4px;
    }

    .stat-info .stat-value {
        font-size: 20px;
    }

    /* Recipe Section */
    .recipe-section {
        padding: 15px;
    }

    /* Section Header */
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 20px;
    }

    .section-header h2 {
        font-size: 18px;
    }

    /* Section Actions */
    .section-actions {
        width: 100%;
        flex-direction: column;
        gap: 10px;
    }

    .filter-group {
        width: 100%;
        flex-direction: column;
        gap: 10px;
    }

    .filter-select {
        width: 100%;
        padding: 12px 15px;
    }

    .search-box {
        width: 100%;
    }

    .search-box input {
        width: 100%;
        padding: 12px 15px 12px 40px;
    }

    .btn-primary {
        width: 100%;
        justify-content: center;
        padding: 12px 20px;
    }

    /* Recipe Grid - Single Column */
    .recipe-grid {
        grid-template-columns: 1fr;
        gap: 15px;
        margin-top: 20px;
    }

    /* Recipe Card */
    .recipe-card {
        border-radius: 12px;
    }

    .recipe-image {
        height: 180px;
    }

    .recipe-content {
        padding: 15px;
    }

    .recipe-title {
        font-size: 16px;
        margin-bottom: 8px;
    }

    .recipe-meta {
        gap: 12px;
        margin-bottom: 12px;
    }

    .meta-item {
        font-size: 12px;
    }

    .recipe-creator {
        padding-top: 12px;
    }

    .creator-avatar {
        width: 32px;
        height: 32px;
        font-size: 13px;
    }

    .creator-name {
        font-size: 13px;
    }

    .creator-date {
        font-size: 11px;
    }

    .recipe-actions {
        gap: 6px;
    }

    .btn-icon {
        width: 36px;
        height: 36px;
        font-size: 14px;
    }

    /* Empty State */
    .empty-state {
        padding: 40px 20px;
    }

    .empty-state i {
        font-size: 48px;
    }

    .empty-state h3 {
        font-size: 18px;
    }

    .empty-state p {
        font-size: 14px;
    }

    /* Pagination */
    .pagination {
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 20px;
    }

    .pagination a,
    .pagination button {
        padding: 8px 12px;
        font-size: 13px;
        min-width: 36px;
    }

    .pagination span {
        display: none;
    }

    /* Modal */
    .modal-content {
        width: 95%;
        max-width: 95%;
        max-height: 90vh;
        margin: 20px 10px;
    }

    .modal-header {
        padding: 20px;
    }

    .modal-header h3 {
        font-size: 18px;
    }

    .modal-header i {
        font-size: 20px;
    }

    .modal-close {
        width: 32px;
        height: 32px;
        font-size: 16px;
    }

    .modal-body {
        padding: 20px;
    }

    /* View Modal */
    .recipe-detail-image {
        height: 200px;
        border-radius: 12px;
        margin-bottom: 15px;
    }

    .recipe-detail-title {
        font-size: 22px;
        margin-bottom: 12px;
    }

    .recipe-detail-meta {
        gap: 15px;
    }

    .recipe-detail-meta .meta-item {
        font-size: 13px;
    }

    .recipe-detail-section {
        margin-bottom: 20px;
    }

    .recipe-detail-section h4 {
        font-size: 16px;
        margin-bottom: 10px;
    }

    .recipe-detail-section p,
    .recipe-detail-section ul {
        font-size: 14px;
        line-height: 1.6;
    }

    /* Delete Modal */
    .delete-modal-icon {
        width: 70px;
        height: 70px;
        font-size: 32px;
        margin-bottom: 15px;
    }

    .delete-modal-text {
        font-size: 14px;
        margin-bottom: 20px;
    }

    .delete-modal-recipe {
        font-size: 16px;
        margin-bottom: 20px;
    }

    .modal-footer {
        flex-direction: column-reverse;
        gap: 10px;
    }

    .btn {
        width: 100%;
        justify-content: center;
        padding: 12px 20px;
    }

    /* Alert Messages */
    .alert-message {
        top: 10px;
        right: 10px;
        left: 10px;
        max-width: none;
        padding: 12px 15px;
        font-size: 14px;
    }

    /* Overlay */
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
}

/* Small Mobile (≤480px) */
@media (max-width: 480px) {
    /* Header */
    .header-left h1 {
        font-size: 16px;
    }

    /* Content */
    .content {
        padding: 15px 10px;
    }

    /* Stats Grid - Single Column */
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .stat-card {
        padding: 12px;
    }

    .stat-icon {
        width: 42px;
        height: 42px;
        font-size: 16px;
    }

    .stat-info h3 {
        font-size: 10px;
    }

    .stat-info .stat-value {
        font-size: 18px;
    }

    /* Recipe Section */
    .recipe-section {
        padding: 12px;
        border-radius: 12px;
    }

    .section-header h2 {
        font-size: 16px;
    }

    /* Recipe Image */
    .recipe-image {
        height: 160px;
    }

    .recipe-image i {
        font-size: 36px;
    }

    .recipe-badge {
        font-size: 10px;
        padding: 4px 10px;
    }

    /* Recipe Content */
    .recipe-content {
        padding: 12px;
    }

    .recipe-title {
        font-size: 15px;
    }

    .recipe-meta {
        gap: 10px;
    }

    .meta-item {
        font-size: 11px;
    }

    .meta-item i {
        font-size: 11px;
    }

    .creator-avatar {
        width: 28px;
        height: 28px;
        font-size: 11px;
    }

    .creator-name {
        font-size: 12px;
    }

    .creator-date {
        font-size: 10px;
    }

    .btn-icon {
        width: 34px;
        height: 34px;
        font-size: 13px;
    }

    /* Pagination */
    .pagination a,
    .pagination button {
        padding: 6px 10px;
        font-size: 12px;
        min-width: 32px;
    }

    /* Modal */
    .modal-content {
        width: 100%;
        height: 100%;
        max-width: 100%;
        max-height: 100%;
        border-radius: 0;
        margin: 0;
    }

    .modal-header {
        padding: 15px;
    }

    .modal-header h3 {
        font-size: 16px;
    }

    .modal-body {
        padding: 15px;
    }

    .recipe-detail-image {
        height: 180px;
    }

    .recipe-detail-title {
        font-size: 20px;
    }

    .recipe-detail-meta {
        flex-direction: column;
        gap: 8px;
    }

    .recipe-detail-section h4 {
        font-size: 15px;
    }

    .recipe-detail-section p,
    .recipe-detail-section ul {
        font-size: 13px;
    }

    .delete-modal-icon {
        width: 60px;
        height: 60px;
        font-size: 28px;
    }

    .delete-modal-text {
        font-size: 13px;
    }

    .delete-modal-recipe {
        font-size: 15px;
    }
}

/* Landscape Mobile */
@media (max-width: 768px) and (orientation: landscape) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }

    .stat-card {
        padding: 12px;
        flex-direction: column;
        text-align: center;
    }

    .stat-icon {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }

    .stat-info h3 {
        font-size: 10px;
    }

    .stat-info .stat-value {
        font-size: 18px;
    }

    .recipe-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Better Touch Targets */
@media (max-width: 768px) {
    button,
    a.btn-primary,
    .btn-icon,
    .filter-select,
    input {
        min-height: 44px;
    }

    .btn-icon {
        min-width: 44px;
    }
}

/* Prevent Horizontal Scroll */
@media (max-width: 768px) {
    body {
        overflow-x: hidden;
    }

    .container {
        overflow-x: hidden;
    }

    .recipe-grid {
        overflow-x: hidden;
    }
}

/* Better Dropdown Positioning on Mobile */
@media (max-width: 768px) {
    .notification-dropdown,
    .dropdown-menu {
        position: fixed;
        top: 60px;
        right: 10px;
        left: auto;
        width: calc(100vw - 20px);
        max-width: 350px;
    }
}

/* Modal Scrolling */
@media (max-width: 768px) {
    .modal-content {
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
}

/* Focus States for Accessibility */
@media (max-width: 768px) {
    button:focus,
    input:focus,
    select:focus,
    a:focus {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }
}

/* Print Styles */
@media print {
    .sidebar,
    .header,
    .menu-toggle,
    .section-actions,
    .recipe-actions,
    .pagination,
    .modal,
    .overlay,
    .alert-message {
        display: none !important;
    }

    .main-content {
        margin-left: 0;
        width: 100%;
    }

    .content {
        padding: 0;
    }

    .recipe-section {
        box-shadow: none;
        border: 1px solid #ddd;
    }

    .recipe-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .recipe-card {
        page-break-inside: avoid;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}

/* Optimize Image Loading */
@media (max-width: 768px) {
    .recipe-image img,
    .recipe-detail-image {
        image-rendering: -webkit-optimize-contrast;
        image-rendering: crisp-edges;
    }
}

/* Reduce Motion for Users with Preference */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
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
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert-message success show" id="successMessage">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($_SESSION['success_message']); ?></span>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert-message error show" id="errorMessage">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($_SESSION['error_message']); ?></span>
    </div>
    <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
    <!-- View Recipe Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> View Recipe</h3>
                <button class="modal-close" onclick="closeModal('viewModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>
    <!-- Edit Recipe Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Recipe</h3>
                <button class="modal-close" onclick="closeModal('editModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p style="text-align: center; color: var(--text-secondary); padding: 20px;">
                    Redirecting to edit page...
                </p>
            </div>
        </div>
    </div>
    <!-- Delete Recipe Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content small">
            <div class="modal-header">
                <h3><i class="fas fa-trash"></i> Delete Recipe</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="delete-modal-icon">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <p class="delete-modal-text">
                    Are you sure you want to delete this recipe? This action cannot be undone and will permanently remove all associated data.
                </p>
                <div class="delete-modal-recipe" id="deleteRecipeName"></div>
                <div class="modal-footer">
                    <button class="btn btn-cancel" onclick="closeModal('deleteModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <form id="deleteForm" method="POST" style="display: inline;">
                        <input type="hidden" name="delete_recipe" value="1">
                        <input type="hidden" name="recipe_id" id="deleteRecipeId" value="">
                        <button type="submit" class="btn btn-delete-confirm">
                            <i class="fas fa-trash"></i> Delete Recipe
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="container">
        <?php include '../partials/sidebar.php'; ?>
        <div class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1>Recipe Management</h1>
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
                        <div class="stat-icon total"><i class="fas fa-utensils"></i></div>
                        <div class="stat-info">
                            <h3>Total Recipes</h3>
                            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon easy"><i class="fas fa-smile"></i></div>
                        <div class="stat-info">
                            <h3>Easy Recipes</h3>
                            <div class="stat-value"><?php echo number_format($stats['easy']); ?></div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon medium"><i class="fas fa-meh"></i></div>
                        <div class="stat-info">
                            <h3>Medium Recipes</h3>
                            <div class="stat-value"><?php echo number_format($stats['medium']); ?></div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon hard"><i class="fas fa-fire"></i></div>
                        <div class="stat-info">
                            <h3>Hard Recipes</h3>
                            <div class="stat-value"><?php echo number_format($stats['hard']); ?></div>
                        </div>
                    </div>
                </div>
                <!-- Recipe Section -->
                <div class="recipe-section">
                    <div class="section-header">
                        <h2>All Recipes</h2>
                        <div class="section-actions">
                            <div class="filter-group">
                                <form method="GET" action="" style="display: contents;">
                                    <select class="filter-select" name="difficulty" onchange="this.form.submit()">
                                        <option value="all" <?php echo !$difficulty_filter ? 'selected' : ''; ?>>All Difficulty</option>
                                        <option value="Easy" <?php echo $difficulty_filter === 'Easy' ? 'selected' : ''; ?>>Easy</option>
                                        <option value="Medium" <?php echo $difficulty_filter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="Hard" <?php echo $difficulty_filter === 'Hard' ? 'selected' : ''; ?>>Hard</option>
                                    </select>
                                    <select class="filter-select" name="sort" onchange="this.form.submit()">
                                        <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                        <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="title" <?php echo $sort_by === 'title' ? 'selected' : ''; ?>>Title A-Z</option>
                                    </select>
                                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_term ?? ''); ?>">
                                    <input type="hidden" name="page" value="<?php echo $current_page; ?>">
                                </form>
                            </div>
                            <form method="GET" action="" class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" name="search" placeholder="Search recipes..." value="<?php echo htmlspecialchars($search_term ?? ''); ?>">
                                <input type="hidden" name="difficulty" value="<?php echo htmlspecialchars($difficulty_filter ?? 'all'); ?>">
                                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
                            </form>
                            <a href="add-recipe.php" class="btn-primary">
                                <i class="fas fa-plus"></i> Add New Recipe
                            </a>
                        </div>
                    </div>
                    <!-- Recipe Grid -->
                    <div class="recipe-grid">
                        <?php if ($recipes_result && $recipes_result->num_rows > 0): ?>
                            <?php while ($recipe = $recipes_result->fetch_assoc()): ?>
                            <div class="recipe-card" data-recipe='<?php echo json_encode($recipe); ?>'>
                                <div class="recipe-image">
                                    <?php if (!empty($recipe['image_url']) && file_exists('../../' . $recipe['image_url'])): ?>
                                        <img src="../../<?php echo htmlspecialchars($recipe['image_url']); ?>" alt="<?php echo htmlspecialchars($recipe['title']); ?>">
                                    <?php else: ?>
                                        <i class="fas fa-utensils"></i>
                                    <?php endif; ?>
                                    <span class="recipe-badge <?php echo strtolower($recipe['difficulty']); ?>">
                                        <?php echo htmlspecialchars($recipe['difficulty']); ?>
                                    </span>
                                </div>
                                <div class="recipe-content">
                                    <h3 class="recipe-title"><?php echo htmlspecialchars($recipe['title']); ?></h3>
                                    <div class="recipe-meta">
                                        <?php if (!empty($recipe['time'])): ?>
                                        <div class="meta-item">
                                            <i class="fas fa-clock"></i>
                                            <span><?php echo htmlspecialchars($recipe['time']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($recipe['servings'])): ?>
                                        <div class="meta-item">
                                            <i class="fas fa-users"></i>
                                            <span><?php echo htmlspecialchars($recipe['servings']); ?> servings</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="recipe-creator">
                                        <div class="creator-avatar" style="background-color: <?php echo htmlspecialchars($recipe['avatar_color'] ?? '#667eea'); ?>">
                                            <?php if (!empty($recipe['profile_picture']) && file_exists('../../' . $recipe['profile_picture'])): ?>
                                                <img src="../../<?php echo htmlspecialchars($recipe['profile_picture']); ?>" alt="Creator">
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($recipe['initials'] ?? 'U'); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="creator-info">
                                            <div class="creator-name"><?php echo htmlspecialchars($recipe['creator_name'] ?? 'Unknown'); ?></div>
                                            <div class="creator-date">Created <?php echo date('M d, Y', strtotime($recipe['created_at'])); ?></div>
                                        </div>
                                        <div class="recipe-actions">
                                            <button class="btn-icon btn-view" title="View" onclick="viewRecipe(<?php echo $recipe['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn-icon btn-edit" title="Edit" onclick="editRecipe(<?php echo $recipe['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon btn-delete" title="Delete" onclick="openDeleteModal(<?php echo $recipe['id']; ?>, '<?php echo addslashes($recipe['title']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state" style="grid-column: 1 / -1;">
                                <i class="fas fa-utensils"></i>
                                <h3>No recipes found</h3>
                                <p>
                                    <?php if ($search_term || $difficulty_filter): ?>
                                        Try adjusting your filters or search terms
                                    <?php else: ?>
                                        Start by adding your first recipe!
                                    <?php endif; ?>
                                </p>
                                <a href="add-recipe.php" class="btn-primary" style="display: inline-flex; margin-top: 15px;">
                                    <i class="fas fa-plus"></i> Add Recipe
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php
                        $query_params = [
                            'difficulty' => $difficulty_filter ?? 'all',
                            'search' => $search_term ?? '',
                            'sort' => $sort_by
                        ];
                        ?>
                        <?php if ($current_page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($query_params, ['page' => $current_page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <button disabled><i class="fas fa-chevron-left"></i></button>
                        <?php endif; ?>
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        if ($start_page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($query_params, ['page' => 1])); ?>">1</a>
                            <?php if ($start_page > 2): ?>
                                <span>...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($query_params, ['page' => $i])); ?>" 
                               class="<?php echo $i === $current_page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span>...</span>
                            <?php endif; ?>
                            <a href="?<?php echo http_build_query(array_merge($query_params, ['page' => $total_pages])); ?>"><?php echo $total_pages; ?></a>
                        <?php endif; ?>
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($query_params, ['page' => $current_page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <button disabled><i class="fas fa-chevron-right"></i></button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
                    closeAllModals();
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
            // Auto-hide alert messages
            const successMessage = document.getElementById('successMessage');
            const errorMessage = document.getElementById('errorMessage');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.classList.remove('show');
                }, 5000);
            }
            if (errorMessage) {
                setTimeout(() => {
                    errorMessage.classList.remove('show');
                }, 5000);
            }
        });
        // View Recipe Function — ✅ FIXED PATH HERE
        function viewRecipe(recipeId) {
    fetch(`get_recipe.php?id=${recipeId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const recipe = data.recipe;
                const ingredients = data.ingredients;
                let ingredientsList = '';
                ingredients.forEach(ing => {
                    ingredientsList += `<li>${ing.quantity ? ing.quantity + ' ' : ''}${ing.ingredient_name}</li>`;
                });

                // Image path
                const imageUrl = recipe.image_url ? `../../${recipe.image_url}` : '';
                const hasImage = imageUrl && imageUrl !== '../../';

                // Video path
                const videoUrl = recipe.video_url ? `../../${recipe.video_url}` : '';
                const hasVideo = videoUrl && videoUrl !== '../../';

                let mediaHtml = '';

                // Show image if exists
                if (hasImage) {
                    mediaHtml += `<img src="${imageUrl}" alt="${recipe.title}" class="recipe-detail-image">`;
                }

                // Show video if exists
                if (hasVideo) {
                    mediaHtml += `
                        <video class="recipe-detail-image" controls>
                            <source src="${videoUrl}" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    `;
                }

                // If neither image nor video, show placeholder
                if (!hasImage && !hasVideo) {
                    mediaHtml = `<div style="text-align:center; padding:40px; color:var(--text-secondary);">No media available</div>`;
                }

                document.getElementById('viewModalBody').innerHTML = `
                    ${mediaHtml}
                    <div class="recipe-detail-header">
                        <h2 class="recipe-detail-title">${recipe.title}</h2>
                        <div class="recipe-detail-meta">
                            <div class="meta-item">
                                <i class="fas fa-signal"></i>
                                <span><strong>Difficulty:</strong> ${recipe.difficulty}</span>
                            </div>
                            ${recipe.time ? `
                            <div class="meta-item">
                                <i class="fas fa-clock"></i>
                                <span><strong>Time:</strong> ${recipe.time}</span>
                            </div>` : ''}
                            ${recipe.servings ? `
                            <div class="meta-item">
                                <i class="fas fa-users"></i>
                                <span><strong>Servings:</strong> ${recipe.servings}</span>
                            </div>` : ''}
                        </div>
                    </div>
                    ${ingredientsList ? `
                    <div class="recipe-detail-section">
                        <h4><i class="fas fa-list"></i> Ingredients</h4>
                        <ul style="color: var(--text-secondary); line-height: 2; padding-left: 20px;">
                            ${ingredientsList}
                        </ul>
                    </div>` : ''}
                    <div class="recipe-detail-section">
                        <h4><i class="fas fa-book-open"></i> Instructions</h4>
                        <p>${recipe.instructions.replace(/\n/g, '<br>')}</p>
                    </div>
                `;
                openModal('viewModal');
            } else {
                alert('Failed to load recipe details');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while loading the recipe');
        });
}
        // Edit Recipe Function
        function editRecipe(recipeId) {
            window.location.href = `edit-recipe.php?id=${recipeId}`;
        }
        // Delete Modal Functions
        function openDeleteModal(recipeId, recipeName) {
            document.getElementById('deleteRecipeId').value = recipeId;
            document.getElementById('deleteRecipeName').textContent = `"${recipeName}"`;
            openModal('deleteModal');
        }
        // Modal Control Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.getElementById('overlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.getElementById('overlay').classList.remove('active');
            document.body.style.overflow = '';
        }
        function closeAllModals() {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.classList.remove('show');
            });
            document.body.style.overflow = '';
        }
        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeAllModals();
            }
        });
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllModals();
            }
        });
    </script>
</body>
</html>
<?php
$recipes_stmt->close();
$conn->close();
?>