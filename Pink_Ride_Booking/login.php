<?php

session_start();

require_once "db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if (empty($email) || empty($password)) {

        $error = "Please enter email and password.";

    } else {

        $stmt = $conn->prepare(
            "SELECT user_id, full_name, email, password, status 
             FROM users 
             WHERE email = ? 
             LIMIT 1"
        );

        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows == 1) {

            $user = $result->fetch_assoc();

            if ($user["status"] !== "active") {

                $error = "Your account is blocked.";

            } elseif (
                password_verify($password, $user["password"]) ||
                $password === $user["password"]
            ) {

                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["email"] = $user["email"];

                header("Location: dashboard.php");
                exit();

            } else {

                $error = "Invalid email or password.";
            }

        } else {

            $error = "Invalid email or password.";
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

    <title>Login - Pink Ride</title>

    <link rel="stylesheet" href="style.css">
</head>

<body class="auth-page">

<div class="auth-container">

    <a href="index.php" class="back-arrow">
        ← Back
    </a>

    <div class="auth-logo">
        🚌 <span>Pink Ride</span>
    </div>

    <div class="auth-card">

        <div class="auth-icon">👋</div>

        <h1>Welcome Back</h1>

        <p class="auth-subtitle">
            Login to continue your journey
        </p>

        <?php if ($error != ""): ?>

            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <label>Email Address</label>

            <input
                type="email"
                name="email"
                placeholder="Enter your email"
                required
            >

            <label>Password</label>

            <input
                type="password"
                name="password"
                placeholder="Enter your password"
                required
            >

            <button type="submit" class="btn-primary full-btn">
                Login →
            </button>

        </form>

        <p class="auth-footer">
            Don't have an account?
            <a href="register.php">Create Account</a>
        </p>

    </div>

</div>

</body>
</html>