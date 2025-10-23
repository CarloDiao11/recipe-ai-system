<?php
session_start();

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];

    // Include database config
    require_once 'config/database.php';

    // Update user status to 'offline'
    if (isset($conn)) {
        $stmt = $conn->prepare("UPDATE users SET status = 'offline' WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }

    // Destroy all session data
    $_SESSION = array();
    session_destroy();
}

// Redirect to login page
header('Location: index.php');
exit();
?>