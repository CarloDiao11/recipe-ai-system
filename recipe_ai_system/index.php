<?php
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'flavor';
$username = 'root'; // Change this to your MySQL username
$password = '';     // Change this to your MySQL password

// Create connection
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Initialize variables
$error = '';
$success = '';

// Retain form values
$firstName = $_POST['firstName'] ?? '';
$middleName = $_POST['middleName'] ?? '';
$lastName = $_POST['lastName'] ?? '';
$contactNumber = $_POST['contactNumber'] ?? '';
$signupUsername = $_POST['signupUsername'] ?? '';
$signupEmail = $_POST['signupEmail'] ?? '';
$signinIdentifier = $_POST['signinIdentifier'] ?? '';

// Handle Sign Up
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    $firstName = trim($_POST['firstName']);
    $middleName = trim($_POST['middleName']);
    $lastName = trim($_POST['lastName']);
    $contactNumber = trim($_POST['contactNumber']);
    $signupUsername = trim($_POST['signupUsername']);
    $signupEmail = trim($_POST['signupEmail']);
    $signupPassword = $_POST['signupPassword'];
    $confirmPassword = $_POST['confirmPassword'];
    $terms = isset($_POST['terms']) ? true : false;
    
    // Validation
    if (empty($firstName) || empty($lastName) || empty($contactNumber) || empty($signupUsername) || empty($signupEmail) || empty($signupPassword)) {
        $error = 'Please fill in all required fields!';
    } elseif (!filter_var($signupEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format!';
    } elseif (strlen($signupPassword) < 8) {
        $error = 'Password must be at least 8 characters!';
    } elseif ($signupPassword !== $confirmPassword) {
        $error = 'Passwords do not match!';
    } elseif (!$terms) {
        $error = 'You must accept the Terms of Use & Privacy Policy!';
    } else {
        // Check if email or username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$signupEmail, $signupUsername]);
        
        if ($stmt->rowCount() > 0) {
            $error = 'Email or Username already exists!';
        } else {
            // Create initials and random avatar color
            $initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
            $colors = ['#ff6b35', '#4a7c4e', '#6f42c1', '#20c997', '#e83e8c', '#fd7e14'];
            $avatarColor = $colors[array_rand($colors)];
            
            // Full name
            $fullName = $firstName . ' ' . ($middleName ? $middleName . ' ' : '') . $lastName;
            
            // Hash password
            $hashedPassword = password_hash($signupPassword, PASSWORD_DEFAULT);
            
            // Insert user with username (default role is 'user')
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, name, initials, avatar_color, role, status) VALUES (?, ?, ?, ?, ?, ?, 'user', 'offline')");
            
            if ($stmt->execute([$signupUsername, $signupEmail, $hashedPassword, $fullName, $initials, $avatarColor])) {
                $success = 'Account created successfully! Please sign in.';
                // Clear form values on success
                $firstName = $middleName = $lastName = $contactNumber = $signupUsername = $signupEmail = '';
            } else {
                $error = 'Registration failed. Please try again!';
            }
        }
    }
}

// Handle Sign In
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signin'])) {
    $signinIdentifier = trim($_POST['signinIdentifier']); // Username or Email
    $signinPassword = $_POST['signinPassword'];
    
    if (empty($signinIdentifier) || empty($signinPassword)) {
        $error = 'Please enter both username/email and password!';
    } else {
        // Check if user exists by email or username
        $stmt = $conn->prepare("SELECT id, username, email, password, name, initials, avatar_color, role FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$signinIdentifier, $signinIdentifier]);
        
        if ($stmt->rowCount() === 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify password
            if (password_verify($signinPassword, $user['password'])) {
                // Update user status to online
                $updateStmt = $conn->prepare("UPDATE users SET status = 'online' WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_username'] = $user['username'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_initials'] = $user['initials'];
                $_SESSION['user_avatar_color'] = $user['avatar_color'];
                $_SESSION['user_role'] = $user['role'];
                
                // Redirect based on role
                if ($user['role'] === 'admin') {
                    header('Location: admin/modules/dashboard.php');
                } else {
                    header('Location: user/modules/dashboard.php');
                }
                exit();
            } else {
                $error = 'Invalid password!';
            }
        } else {
            $error = 'User not found!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up & Login - Flavor Forge</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --bg-chat: #ffffff;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --accent-orange: #ff6b35;
            --accent-green: #4a7c4e;
            --border-color: #dee2e6;
            --shadow: rgba(0, 0, 0, 0.1);
        }

        [data-theme="dark"] {
            --bg-primary: #1a1a1a;
            --bg-secondary: #2d2d2d;
            --bg-chat: #252525;
            --text-primary: #ffffff;
            --text-secondary: #b0b0b0;
            --border-color: #404040;
            --shadow: rgba(0, 0, 0, 0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.3s ease;
        }

        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--bg-chat);
            border: 2px solid var(--border-color);
            border-radius: 50px;
            padding: 10px 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 6px var(--shadow);
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .theme-toggle:hover {
            transform: scale(1.1);
            background: var(--accent-orange);
            color: white;
        }

        .theme-toggle i {
            font-size: 18px;
            color: var(--text-primary);
        }

        .alert {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 1rem 2rem;
            border-radius: 10px;
            font-weight: 500;
            z-index: 2000;
            animation: slideDown 0.3s ease;
            max-width: 500px;
            box-shadow: 0 4px 15px var(--shadow);
        }

        .alert-error {
            background: #dc3545;
            color: white;
        }

        .alert-success {
            background: #28a745;
            color: white;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }

        .container {
            position: relative;
            width: 100%;
            max-width: 900px;
            min-height: 600px;
            background: var(--bg-primary);
            border-radius: 20px;
            box-shadow: 0 10px 40px var(--shadow);
            overflow: hidden;
            margin: 20px;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 20px;
            margin-top: 30px;
        }

        .logo {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-green));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            overflow: hidden;
        }

        .logo i {
            font-size: 40px;
            color: white;
        }

        .system-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-top: 15px;
            margin-bottom: 0;
        }

        .system-name-gradient {
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .form-container {
            position: absolute;
            top: 0;
            height: 100%;
            transition: all 0.6s ease-in-out;
        }

        .sign-in-container {
            left: 0;
            width: 50%;
            z-index: 2;
        }

        .sign-up-container {
            left: 0;
            width: 50%;
            opacity: 0;
            z-index: 1;
        }

        .container.active .sign-in-container {
            transform: translateX(100%);
        }

        .container.active .sign-up-container {
            transform: translateX(100%);
            opacity: 1;
            z-index: 5;
        }

        .overlay-container {
            position: absolute;
            top: 0;
            left: 50%;
            width: 50%;
            height: 100%;
            overflow: hidden;
            transition: transform 0.6s ease-in-out;
            z-index: 100;
        }

        .container.active .overlay-container {
            transform: translateX(-100%);
        }

        .overlay {
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-green));
            background-size: cover;
            background-position: 0 0;
            color: #ffffff;
            position: relative;
            left: -100%;
            height: 100%;
            width: 200%;
            transform: translateX(0);
            transition: transform 0.6s ease-in-out;
        }

        .container.active .overlay {
            transform: translateX(50%);
        }

        .overlay-panel {
            position: absolute;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 0 40px;
            text-align: center;
            top: 0;
            height: 100%;
            width: 50%;
            transform: translateX(0);
            transition: transform 0.6s ease-in-out;
        }

        .overlay-left {
            transform: translateX(-20%);
        }

        .container.active .overlay-left {
            transform: translateX(0);
        }

        .overlay-right {
            right: 0;
            transform: translateX(0);
        }

        .container.active .overlay-right {
            transform: translateX(20%);
        }

        .overlay-panel h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .overlay-panel p {
            font-size: 1rem;
            font-weight: 300;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        form {
            background-color: var(--bg-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 0 50px;
            height: 100%;
            text-align: center;
        }

        form h1 {
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-primary);
            font-size: 2rem;
        }

        .input-group {
            position: relative;
            width: 100%;
            margin-bottom: 15px;
        }

        .input-group i.fa-user,
        .input-group i.fa-phone,
        .input-group i.fa-user-circle,
        .input-group i.fa-envelope,
        .input-group i.fa-lock {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 16px;
            z-index: 2;
            pointer-events: none;
        }

        .input-group input {
            width: 100%;
            padding: 12px 45px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .input-group.password-group input {
            padding-right: 45px;
        }

        .input-group input:focus {
            outline: none;
            border-color: var(--accent-orange);
            background: var(--bg-chat);
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-secondary);
            font-size: 16px;
            z-index: 3;
            transition: color 0.3s ease;
        }

        .toggle-password:hover {
            color: var(--accent-orange);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
            width: 100%;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--accent-orange);
        }

        .checkbox-group label {
            font-size: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            text-align: left;
        }

        .checkbox-group a {
            color: var(--accent-orange);
            text-decoration: none;
        }

        .checkbox-group a:hover {
            text-decoration: underline;
        }

        button {
            border-radius: 25px;
            border: none;
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-green));
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            padding: 12px 45px;
            letter-spacing: 1px;
            text-transform: uppercase;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-top: 10px;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 53, 0.4);
        }

        button:active {
            transform: scale(0.95);
        }

        .ghost-btn {
            background: transparent;
            border: 2px solid #ffffff;
        }

        .ghost-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .form-scroll {
            max-height: 600px;
            overflow-y: auto;
            width: 100%;
            padding-right: 10px;
        }

        .form-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .form-scroll::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 10px;
        }

        .form-scroll::-webkit-scrollbar-thumb {
            background: var(--accent-orange);
            border-radius: 10px;
        }

        @media (max-width: 768px) {
            .container {
                max-width: 100%;
                min-height: 100vh;
                border-radius: 0;
            }

            .sign-in-container,
            .sign-up-container {
                width: 100%;
            }

            .overlay-container {
                display: none;
            }

            form {
                padding: 20px 30px;
            }

            .overlay-panel h1 {
                font-size: 1.8rem;
            }
            
            .logo {
                width: 60px;
                height: 60px;
            }
            
            .logo i {
                font-size: 30px;
            }
        }
    </style>
</head>
<body>
    <?php if ($error): ?>
        <div class="alert alert-error" id="alert">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success" id="alert">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <div class="theme-toggle" onclick="toggleTheme()">
        <i class="fas fa-moon" id="theme-icon"></i>
    </div>

    <div class="container <?php echo $success ? 'active' : ''; ?>" id="container">
        <div class="form-container sign-up-container">
            <form method="POST" action="">
                <div class="logo-container">
                    <div class="logo">
                        <i class="fas fa-fire"></i>
                    </div>
                    <div class="system-name">
                        <span class="system-name-gradient">Flavor Forge</span>
                    </div>
                </div>
                <h1>Create Account</h1>
                <div class="form-scroll">
                    <div class="input-group">
                        <i class="fas fa-user"></i>
                        <input type="text" name="firstName" placeholder="First Name" value="<?php echo htmlspecialchars($firstName); ?>" required>
                    </div>
                    <div class="input-group">
                        <i class="fas fa-user"></i>
                        <input type="text" name="middleName" placeholder="Middle Name" value="<?php echo htmlspecialchars($middleName); ?>">
                    </div>
                    <div class="input-group">
                        <i class="fas fa-user"></i>
                        <input type="text" name="lastName" placeholder="Last Name" value="<?php echo htmlspecialchars($lastName); ?>" required>
                    </div>
                    <div class="input-group">
                        <i class="fas fa-phone"></i>
                        <input type="tel" name="contactNumber" placeholder="Contact Number" value="<?php echo htmlspecialchars($contactNumber); ?>" required>
                    </div>
                    <div class="input-group">
                        <i class="fas fa-user-circle"></i>
                        <input type="text" name="signupUsername" placeholder="Username" value="<?php echo htmlspecialchars($signupUsername); ?>" required>
                    </div>
                    <div class="input-group">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="signupEmail" placeholder="Email" value="<?php echo htmlspecialchars($signupEmail); ?>" required>
                    </div>
                    <div class="input-group password-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="signup-password" name="signupPassword" placeholder="Password" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword('signup-password', this)"></i>
                    </div>
                    <div class="input-group password-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="signup-confirm-password" name="confirmPassword" placeholder="Confirm Password" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword('signup-confirm-password', this)"></i>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="terms" name="terms" required>
                        <label for="terms">I accept the <a href="#">Terms of Use</a> & <a href="#">Privacy Policy</a></label>
                    </div>
                </div>
                <button type="submit" name="signup">Sign Up</button><br>
            </form>
        </div>

        <div class="form-container sign-in-container">
            <form method="POST" action="">
                <div class="logo-container">
                    <div class="logo">
                        <i class="fas fa-fire"></i>
                    </div>
                    <div class="system-name">
                        <span class="system-name-gradient">Flavor Forge</span>
                    </div>
                </div>
                <h1>Sign In</h1>
                <div class="input-group">
                    <i class="fas fa-user"></i>
                    <input type="text" name="signinIdentifier" placeholder="Username or Email" value="<?php echo htmlspecialchars($signinIdentifier); ?>" required>
                </div>
                <div class="input-group password-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="signin-password" name="signinPassword" placeholder="Password" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('signin-password', this)"></i>
                </div>
                <button type="submit" name="signin">Sign In</button>
            </form>
        </div>

        <div class="overlay-container">
            <div class="overlay">
                <div class="overlay-panel overlay-left">
                    <h1>Welcome Back!</h1>
                    <p>To keep connected with us please login with your personal info</p>
                    <button class="ghost-btn" id="signIn">Sign In</button>
                </div>
                <div class="overlay-panel overlay-right">
                    <h1>Hello, Friend!</h1>
                    <p>Enter your personal details and start your journey with us</p>
                    <button class="ghost-btn" id="signUp">Sign Up</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const signUpButton = document.getElementById('signUp');
        const signInButton = document.getElementById('signIn');
        const container = document.getElementById('container');

        signUpButton.addEventListener('click', () => {
            container.classList.add('active');
        });

        signInButton.addEventListener('click', () => {
            container.classList.remove('active');
        });

        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function toggleTheme() {
            const html = document.documentElement;
            const icon = document.getElementById('theme-icon');
            
            if (html.getAttribute('data-theme') === 'dark') {
                html.removeAttribute('data-theme');
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            } else {
                html.setAttribute('data-theme', 'dark');
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            }
        }

        // Auto-hide alerts after 5 seconds
        const alert = document.getElementById('alert');
        if (alert) {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateX(-50%) translateY(-20px)';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        }
    </script>
</body>
</html>