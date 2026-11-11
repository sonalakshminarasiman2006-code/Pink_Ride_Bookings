<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Dashboard - Pink Ride</title>

    <link rel="stylesheet" href="style.css">

</head>

<body>

<header class="dashboard-header">

    <div class="dashboard-logo">
        🚌 <span>Pink Ride</span>
    </div>

    <nav class="dashboard-nav">

        <a href="dashboard.php" class="active">
            🏠 Home
        </a>

        <a href="search_bus.php">
            🔍 Search Bus
        </a>

        <a href="my_bookings.php">
            🎫 My Bookings
        </a>

        <a href="notifications.php">
            🔔 Notifications
        </a>

        <a href="profile.php">
            👤 Profile
        </a>

        <a href="logout.php" class="logout-link">
            ↪ Logout
        </a>

    </nav>

</header>


<main class="dashboard-main">

    <div class="page-top">

        <a href="index.php" class="back-arrow dashboard-back">
            ← Back
        </a>

    </div>


    <section class="welcome-section">

        <div>

            <span class="small-badge">
                Welcome back 👋
            </span>

            <h1>
                Hello,
                <?= htmlspecialchars($_SESSION["full_name"]) ?>!
            </h1>

            <p>
                Where would you like to go today?
            </p>

        </div>

        <div class="welcome-icon">
            🚌
        </div>

    </section>


    <section class="search-box">

        <div class="search-title">

            <h2>Find Your Bus</h2>

            <p>
                Search buses and book your journey easily.
            </p>

        </div>

        <form action="search_bus.php" method="GET">

            <div class="search-grid">

                <div class="input-group">

                    <label>From</label>

                    <input
                        type="text"
                        name="source"
                        placeholder="Enter source"
                        required
                    >

                </div>

                <div class="route-arrow">
                    →
                </div>

                <div class="input-group">

                    <label>To</label>

                    <input
                        type="text"
                        name="destination"
                        placeholder="Enter destination"
                        required
                    >

                </div>

                <div class="input-group">

                    <label>Journey Date</label>

                    <input
                        type="date"
                        name="journey_date"
                        required
                    >

                </div>

                <button
                    type="submit"
                    class="btn-primary search-btn"
                >
                    Search Buses
                </button>

            </div>

        </form>

    </section>


    <section class="quick-cards">

        <a href="search_bus.php" class="quick-card">

            <div class="quick-icon">🔍</div>

            <h3>Search Bus</h3>

            <p>Find buses for your journey</p>

        </a>


        <a href="my_bookings.php" class="quick-card">

            <div class="quick-icon">🎫</div>

            <h3>My Bookings</h3>

            <p>View and manage your tickets</p>

        </a>


        <a href="notifications.php" class="quick-card">

            <div class="quick-icon">🔔</div>

            <h3>Notifications</h3>

            <p>Check your latest updates</p>

        </a>


        <a href="profile.php" class="quick-card">

            <div class="quick-icon">👤</div>

            <h3>My Profile</h3>

            <p>Manage your account</p>

        </a>

    </section>


    <section class="offer-banner">

        <div>

            <span class="offer-tag">
                🎉 FESTIVAL OFFER
            </span>

            <h2>
                Travel More. Save More.
            </h2>

            <p>
                Enjoy special Pink Ride offers during festivals.
            </p>

        </div>

        <div class="offer-percent">
            20% OFF
        </div>

    </section>

</main>


<footer class="footer">
    © 2026 Pink Ride. All Rights Reserved.
</footer>

</body>
</html>