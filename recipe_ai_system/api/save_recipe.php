<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized', 'message' => 'Please login to save recipes']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get recipe ID
$recipe_id = isset($_POST['recipe_id']) ? intval($_POST['recipe_id']) : 0;

if ($recipe_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid recipe ID', 'message' => 'Invalid recipe']);
    exit();
}

header('Content-Type: application/json');

try {
    // Check if recipe exists
    $stmt = $conn->prepare("SELECT id, title FROM recipes WHERE id = ?");
    $stmt->bind_param("i", $recipe_id);
    $stmt->execute();
    $recipe = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$recipe) {
        echo json_encode(['success' => false, 'error' => 'Recipe not found', 'message' => 'Recipe does not exist']);
        exit();
    }
    
    // Check if recipe is already saved
    $stmt = $conn->prepare("SELECT id FROM saved_recipes WHERE user_id = ? AND recipe_id = ?");
    $stmt->bind_param("ii", $user_id, $recipe_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $isSaved = $result->num_rows > 0;
    $stmt->close();
    
    if ($isSaved) {
        // Remove from saved recipes
        $stmt = $conn->prepare("DELETE FROM saved_recipes WHERE user_id = ? AND recipe_id = ?");
        $stmt->bind_param("ii", $user_id, $recipe_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            
            // Optional: Create notification for unsave (you can remove this if not needed)
            // No notification for unsaving to avoid spam
            
            echo json_encode([
                'success' => true, 
                'action' => 'unsaved',
                'message' => 'Recipe removed from saved recipes',
                'recipe_id' => $recipe_id
            ]);
        } else {
            $stmt->close();
            echo json_encode(['success' => false, 'error' => 'Failed to remove recipe', 'message' => 'Failed to unsave recipe']);
        }
    } else {
        // Add to saved recipes
        $stmt = $conn->prepare("INSERT INTO saved_recipes (user_id, recipe_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $recipe_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            
            // Create notification for the user
            $notif_title = "Recipe Saved!";
            $notif_message = "You saved: " . $recipe['title'];
            
            $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, related_id) VALUES (?, 'recipe', ?, ?, ?)");
            $notifStmt->bind_param("issi", $user_id, $notif_title, $notif_message, $recipe_id);
            $notifStmt->execute();
            $notifStmt->close();
            
            echo json_encode([
                'success' => true, 
                'action' => 'saved',
                'message' => 'Recipe saved successfully!',
                'recipe_id' => $recipe_id
            ]);
        } else {
            $stmt->close();
            echo json_encode(['success' => false, 'error' => 'Failed to save recipe', 'message' => 'Failed to save recipe']);
        }
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'message' => 'An error occurred']);
}
?>