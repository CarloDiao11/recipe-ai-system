<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    case 'get_recipes':
        getRecipes($conn, $user_id);
        break;
    
    case 'add_meal':
        addMeal($conn, $user_id);
        break;
    
    case 'remove_meal':
        removeMeal($conn, $user_id);
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}

/**
 * Get all recipes with optional search
 */
function getRecipes($conn, $user_id) {
    $search = $_POST['search'] ?? '';
    
    if (!empty($search)) {
        // Search recipes by title or ingredients
        $searchTerm = '%' . $search . '%';
        $stmt = $conn->prepare("
            SELECT DISTINCT r.id, r.title, r.time, r.difficulty, r.servings, r.image_url
            FROM recipes r
            LEFT JOIN recipe_ingredients ri ON r.id = ri.recipe_id
            WHERE r.title LIKE ? OR ri.ingredient_name LIKE ?
            ORDER BY r.created_at DESC
            LIMIT 20
        ");
        $stmt->bind_param("ss", $searchTerm, $searchTerm);
    } else {
        // Get all recipes
        $stmt = $conn->prepare("
            SELECT id, title, time, difficulty, servings, image_url
            FROM recipes
            ORDER BY created_at DESC
            LIMIT 20
        ");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $recipes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'recipes' => $recipes
    ]);
}

/**
 * Add a meal to the meal plan
 */
function addMeal($conn, $user_id) {
    $recipe_id = intval($_POST['recipe_id'] ?? 0);
    $day_of_week = $_POST['day_of_week'] ?? '';
    $meal_type = $_POST['meal_type'] ?? '';
    
    // Validate inputs
    $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $valid_meals = ['Breakfast', 'Lunch', 'Dinner'];
    
    if (!in_array($day_of_week, $valid_days)) {
        echo json_encode(['success' => false, 'error' => 'Invalid day of week']);
        return;
    }
    
    if (!in_array($meal_type, $valid_meals)) {
        echo json_encode(['success' => false, 'error' => 'Invalid meal type']);
        return;
    }
    
    if ($recipe_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid recipe ID']);
        return;
    }
    
    // Check if recipe exists
    $stmt = $conn->prepare("SELECT id FROM recipes WHERE id = ?");
    $stmt->bind_param("i", $recipe_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Recipe not found']);
        return;
    }
    $stmt->close();
    
    // Get current week start date
    $week_start = date('Y-m-d', strtotime('monday this week'));
    
    // Check if meal slot already exists
    $stmt = $conn->prepare("
        SELECT id FROM meal_plans 
        WHERE user_id = ? AND day_of_week = ? AND meal_type = ? AND week_start_date = ?
    ");
    $stmt->bind_param("isss", $user_id, $day_of_week, $meal_type, $week_start);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        // Update existing meal plan
        $stmt = $conn->prepare("
            UPDATE meal_plans 
            SET recipe_id = ? 
            WHERE id = ?
        ");
        $stmt->bind_param("ii", $recipe_id, $existing['id']);
    } else {
        // Insert new meal plan
        $stmt = $conn->prepare("
            INSERT INTO meal_plans (user_id, recipe_id, day_of_week, meal_type, week_start_date)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iisss", $user_id, $recipe_id, $day_of_week, $meal_type, $week_start);
    }
    
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Meal added successfully']);
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Failed to add meal']);
    }
}

/**
 * Remove a meal from the meal plan
 */
function removeMeal($conn, $user_id) {
    $meal_plan_id = intval($_POST['meal_plan_id'] ?? 0);
    
    if ($meal_plan_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid meal plan ID']);
        return;
    }
    
    // Verify ownership and delete
    $stmt = $conn->prepare("DELETE FROM meal_plans WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $meal_plan_id, $user_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Meal removed successfully']);
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Failed to remove meal']);
    }
}
?>