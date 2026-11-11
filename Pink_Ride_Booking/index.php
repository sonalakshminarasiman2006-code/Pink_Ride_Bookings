<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pink Ride - Bus Reservation</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

<header class="main-header">
    <div class="logo">
        <span>🚌</span> Pink Ride
    </div>

    <div class="header-buttons">
        <a href="login.php" class="btn-outline">Login</a>
        <a href="register.php" class="btn-primary">Register</a>
    </div>
</header>

<main>

<section class="hero">

    <div class="hero-content">

        <span class="small-badge">✨ Travel Made Simple</span>

        <h1>
            Your Journey.<br>
            <span>Our Priority.</span>
        </h1>

        <p>
            Book your bus tickets easily, choose your favourite seats,
            enjoy festival offers and travel comfortably with Pink Ride.
        </p>

        <div class="hero-buttons">
            <a href="login.php" class="btn-primary big-btn">
                Book Your Bus →
            </a>

            <a href="register.php" class="btn-light big-btn">
                Create Account
            </a>
        </div>

    </div>

    <div class="hero-card">

        <div class="bus-icon">🚌</div>

        <h3>Travel with Pink Ride</h3>

        <p>Safe • Comfortable • Affordable</p>

        <div class="mini-route">
            <div>
                <small>From</small>
                <strong>Chennai</strong>
            </div>

            <span>→</span>

            <div>
                <small>To</small>
                <strong>Bangalore</strong>
            </div>
        </div>

    </div>

</section>

<section class="features">

    <div class="feature-card">
        <div>💺</div>
        <h3>Easy Seat Selection</h3>
        <p>Choose your preferred seat before booking.</p>
    </div>

    <div class="feature-card">
        <div>🎁</div>
        <h3>Festival Offers</h3>
        <p>Enjoy special discounts during festivals.</p>
    </div>

    <div class="feature-card">
        <div>🔔</div>
        <h3>Instant Notifications</h3>
        <p>Stay updated about your bookings and buses.</p>
    </div>

</section>

</main>

<footer class="footer">
    © 2026 Pink Ride. All Rights Reserved.
</footer>

</body>
</html>