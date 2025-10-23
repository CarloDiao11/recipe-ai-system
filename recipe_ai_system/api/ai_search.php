<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$userIngredients = $data['ingredients'] ?? [];

if (empty($userIngredients)) {
    echo json_encode(['success' => false, 'recipes' => []]);
    exit();
}

// Clean ingredients
$userIngredients = array_map('strtolower', array_map('trim', $userIngredients));

// Build SQL query
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
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit();
}

$types = str_repeat('s', count($userIngredients));
$stmt->bind_param($types, ...$userIngredients);
$stmt->execute();
$result = $stmt->get_result();
$recipes = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Format recipes for response
$formattedRecipes = [];
foreach ($recipes as $recipe) {
    $ingredients = array_map('trim', explode(',', $recipe['ingredient_list']));
    
    $formattedRecipes[] = [
        'id' => (int)$recipe['id'],
        'title' => $recipe['title'],
        'instructions' => nl2br($recipe['instructions']),
        'time' => $recipe['time'],
        'difficulty' => $recipe['difficulty'],
        'servings' => $recipe['servings'],
        'image_url' => $recipe['image_url'],
        'video_url' => $recipe['video_url'],
        'ingredients' => $ingredients
    ];
}

echo json_encode([
    'success' => true,
    'recipes' => $formattedRecipes
]);
?>