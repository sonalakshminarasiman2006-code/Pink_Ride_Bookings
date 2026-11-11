<?php

session_start();

require_once "db.php";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = trim($_POST["full_name"]);
    $email = trim($_POST["email"]);
    $phone = trim($_POST["phone"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];

    if (
        empty($name) ||
        empty($email) ||
        empty($phone) ||
        empty($password)
    ) {

        $error = "Please fill all required fields.";

    } elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

    } elseif (strlen($password) < 6) {

        $error = "Password must contain at least 6 characters.";

    } else {

        $check = $conn->prepare(
            "SELECT user_id FROM users WHERE email = ? OR phone = ? LIMIT 1"
        );

        $check->bind_param("ss", $email, $phone);
        $check->execute();

        $result = $check->get_result();

        if ($result->num_rows > 0) {

            $error = "Email or phone number already registered.";

        } else {

            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare(
                "INSERT INTO users
                (full_name, email, phone, password)
                VALUES (?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "ssss",
                $name,
                $email,
                $phone,
                $hashed_password
            );

            if ($stmt->execute()) {

                $new_user_id = $stmt->insert_id;

                $_SESSION["user_id"] = $new_user_id;
                $_SESSION["full_name"] = $name;
                $_SESSION["email"] = $email;

                header("Location: dashboard.php");
                exit();

            } else {

                $error = "Registration failed. Please try again.";
            }

            $stmt->close();
        }

        $check->close();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Create Account - Pink Ride</title>

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

    <div class="auth-card register-card">

        <div class="auth-icon">✨</div>

        <h1>Create Account</h1>

        <p class="auth-subtitle">
            Start your journey with Pink Ride
        </p>

        <?php if ($error != ""): ?>

            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <label>Full Name</label>

            <input
                type="text"
                name="full_name"
                placeholder="Enter your full name"
                required
            >

            <label>Email Address</label>

            <input
                type="email"
                name="email"
                placeholder="Enter your email"
                required
            >

            <label>Phone Number</label>

            <input
                type="tel"
                name="phone"
                placeholder="Enter your phone number"
                required
            >

            <label>Password</label>

            <input
                type="password"
                name="password"
                placeholder="Create a password"
                required
            >

            <label>Confirm Password</label>

            <input
                type="password"
                name="confirm_password"
                placeholder="Confirm your password"
                required
            >

            <button type="submit" class="btn-primary full-btn">
                Create Account →
            </button>

        </form>

        <p class="auth-footer">
            Already have an account?
            <a href="login.php">Login</a>
        </p>

    </div>

</div>

</body>
</html>