<?php
session_start();
require_once "database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$from = trim($_GET["from"] ?? "");
$to = trim($_GET["to"] ?? "");
$date = trim($_GET["date"] ?? "");

if ($from === "" || $to === "" || $date === "") {
    header("Location: index.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT *
    FROM buses
    WHERE LOWER(source) = LOWER(?)
    AND LOWER(destination) = LOWER(?)
");

$stmt->bind_param("ss", $from, $to);
$stmt->execute();

$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Available Buses - PinkRide</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<nav class="navbar">
    <div class="navbar-inner">

        <a href="index.php" class="logo">PinkRide</a>

        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="mybooking.php">My Bookings</a>
            <a href="profile.php">Profile</a>
            <a href="logout.php" class="nav-btn">Logout</a>
        </div>

    </div>
</nav>

<section class="section">

<div class="container">

<div class="section-heading">

    <div class="small">BUS SEARCH</div>

    <h2>
        Available Buses
    </h2>

    <p>
        <?= htmlspecialchars($from) ?>
        →
        <?= htmlspecialchars($to) ?>
        |
        <?= htmlspecialchars($date) ?>
    </p>

</div>

<div class="feature-grid">

<?php if ($result->num_rows > 0): ?>

<?php while ($bus = $result->fetch_assoc()): ?>

<div class="feature-card">

    <h3>
        <?= htmlspecialchars($bus["bus_name"]) ?>
    </h3>

    <p>
        Bus Number:
        <?= htmlspecialchars($bus["bus_number"]) ?>
    </p>

    <p>
        Bus Type:
        <?= htmlspecialchars($bus["bus_type"]) ?>
    </p>

    <p>
        Departure:
        <?= htmlspecialchars($bus["departure_time"]) ?>
    </p>

    <p>
        Arrival:
        <?= htmlspecialchars($bus["arrival_time"]) ?>
    </p>

    <p>
        Total Seats:
        <?= htmlspecialchars($bus["total_seats"]) ?>
    </p>

    <p>
        Fare:
        ₹<?= htmlspecialchars($bus["fare"]) ?>
    </p>

    <a
        href="seatselection.php?bus_id=<?= $bus["bus_id"] ?>&date=<?= urlencode($date) ?>"
        class="nav-btn"
    >
        Select Seats
    </a>

</div>

<?php endwhile; ?>

<?php else: ?>

<div class="feature-card">

    <h3>No buses found</h3>

    <p>
        No bus is available for this route.
    </p>

    <a href="index.php" class="nav-btn">
        Search Again
    </a>

</div>

<?php endif; ?>

</div>

</div>

</section>

<script src="assets/js/script.js"></script>

</body>
</html>