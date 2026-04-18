<?php
session_start();

// --- DB CONNECTION ---
$conn = new mysqli("localhost", "root", "", "rfid_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- ORIGINAL HARDCODED ADMIN SETTINGS ---
$valid_user = "admin";
$valid_pass_hash = password_hash("admin123", PASSWORD_DEFAULT);

// Redirect kung naka-login na
if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = htmlspecialchars(trim($_POST['username']));
    $password = trim($_POST['password']);

    // 1. I-check ang hardcoded admin
    if ($username === $valid_user && password_verify($password, $valid_pass_hash)) {
        $_SESSION['user'] = $username;
        $_SESSION['role'] = 'Admin'; 
        $_SESSION['grade_level'] = 'All'; // Admin can see all
        $_SESSION['section'] = 'All';     // Admin can see all
        header("Location: dashboard.php");
        exit();
    } 
    
    // 2. I-check sa Database (Kasama na ang Grade at Section)
    else {
        // DINAGDAG NATIN ANG grade_level AT section SA SELECT QUERY
        $stmt = $conn->prepare("SELECT password, role, grade_level, section FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password'])) {
                $_SESSION['user'] = $username;
                $_SESSION['role'] = $row['role']; 
                
                // DITO NA-SESEAVE ANG DATA PARA SA DASHBOARD
                $_SESSION['grade_level'] = $row['grade_level']; 
                $_SESSION['section'] = $row['section'];

                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RFID Attendance System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">

    <div class="login-overlay"></div>

    <div class="login-container">
        <div class="login-box">
            <img src="bg3.jpg" alt="System Logo" class="login-logo">

            <h2>System Login</h2>
            <p class="login-subtitle">Please sign in to continue</p>

            <?php if (!empty($error)): ?>
                <div class="error" style="color: #ff4d4d; margin-bottom: 15px; font-weight: bold; text-align: center; background: rgba(255,0,0,0.1); padding: 10px; border-radius: 5px;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" onsubmit="return validateForm()">
                <div class="input-group" style="position: relative;">
                    <i class=" style="position: absolute; margin: 15px; color: #666;"></i>
                    <input type="text" name="username" id="username" placeholder="Username">
                </div>

                <div class="input-group password-group" style="position: relative;">
                    <i class=" style="position: absolute; margin: 15px; color: #666;"></i>
                    <input type="password" name="password" id="password" placeholder="Password">
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <label class="show-pass" style="font-size: 0.9rem; cursor: pointer;">
                        <input type="checkbox" onclick="togglePassword()"> Show Password
                    </label>
                </div>

                <button type="submit" class="login-button">Login</button>
            </form>
        </div>
    </div>

    <script>
        function togglePassword() {
            let pass = document.getElementById("password");
            pass.type = pass.type === "password" ? "text" : "password";
        }

        function validateForm() {
            let user = document.getElementById("username").value.trim();
            let pass = document.getElementById("password").value.trim();

            if (user.length < 3) {
                alert("Username is too short.");
                return false;
            }
            if (pass.length < 5) {
                alert("Password is too short.");
                return false;
            }
            return true;
        }
    </script>
</body>
</html>