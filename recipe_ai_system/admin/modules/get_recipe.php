<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$recipe_id = (int)$_GET['id'];

// Fetch recipe (kasama ang video_url)
$stmt = $conn->prepare("SELECT id, title, instructions, image_url, video_url, time, servings, difficulty FROM recipes WHERE id = ?");
$stmt->bind_param("i", $recipe_id);
$stmt->execute();
$recipe = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$recipe) {
    echo json_encode(['success' => false, 'message' => 'Recipe not found']);
    exit();
}

// Fetch ingredients
$stmt = $conn->prepare("SELECT ingredient_name, quantity FROM recipe_ingredients WHERE recipe_id = ?");
$stmt->bind_param("i", $recipe_id);
$stmt->execute();
$ingredients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode([
    'success' => true,
    'recipe' => $recipe,
    'ingredients' => $ingredients
]);

$conn->close();
?>