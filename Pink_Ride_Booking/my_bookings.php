<?php

session_start();

require_once "db.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];


/* =========================================================
   GET BOOKINGS
========================================================= */

$sql = "SELECT
            booking_id,
            booking_code,
            user_id,
            schedule_id,
            journey_date,
            total_seats,
            original_amount,
            discount_amount,
            final_amount,
            booking_status
        FROM bookings
        WHERE user_id = ?
        ORDER BY booking_id DESC";


$stmt = $conn->prepare($sql);

$stmt->bind_param("i", $user_id);

$stmt->execute();

$result = $stmt->get_result();

$bookings = [];

while ($row = $result->fetch_assoc()) {
    $bookings[] = $row;
}

$stmt->close();

?>


<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>My Bookings | Pink Ride</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #fff7fb;
    color: #222;
}

.navbar {
    background: white;
    border-bottom: 1px solid #f0dce5;

    padding: 18px 28px;

    display: flex;
    justify-content: space-between;
    align-items: center;
}

.logo {
    color: #e83e8c;
    font-size: 21px;
    font-weight: 800;
}

.nav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.nav a {
    text-decoration: none;
    color: #555;
    font-size: 13px;
    padding: 8px 10px;
    border-radius: 8px;
}

.nav a:hover {
    background: #fff0f6;
    color: #e83e8c;
}

.container {
    max-width: 1050px;
    margin: auto;
    padding: 35px 18px;
}

h1 {
    margin-bottom: 8px;
}

.subtitle {
    color: #777;
    margin-bottom: 25px;
}

.booking-card {
    background: white;
    border: 1px solid #f0dce5;
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 20px;

    box-shadow: 0 10px 30px rgba(232,62,140,.06);
}

.booking-header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    border-bottom: 1px solid #f3e5eb;

    padding-bottom: 15px;
    margin-bottom: 15px;
}

.booking-code {
    color: #e83e8c;
    font-size: 18px;
    font-weight: 800;
}

.status {
    padding: 7px 12px;
    border-radius: 20px;

    background: #e9f9f0;
    color: #168652;

    font-size: 11px;
    font-weight: 800;
}

.detail {
    display: flex;
    justify-content: space-between;

    gap: 15px;

    padding: 11px 0;

    border-bottom: 1px solid #f4e7ed;

    font-size: 13px;
}

.detail span {
    color: #888;
}

.detail strong {
    text-align: right;
}

.seats {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 6px;
}

.seat {
    background: #fff0f6;
    color: #d42f73;

    border: 1px solid #ffd1e1;

    padding: 6px 9px;

    border-radius: 7px;

    font-size: 11px;
    font-weight: 800;
}

.amount {
    color: #e83e8c;
    font-size: 17px;
}

.actions {
    display: flex;
    gap: 10px;

    margin-top: 18px;
    padding-top: 15px;

    border-top: 1px solid #f3e5eb;
}

.button {
    text-decoration: none;

    padding: 11px 16px;

    border-radius: 10px;

    font-size: 12px;
    font-weight: 800;
}

.view {
    background: #e83e8c;
    color: white;
}

.cancel {
    border: 1px solid #efbcbc;
    color: #d33b3b;
    background: white;
}

.no-bookings {
    background: white;
    border: 1px solid #f0dce5;
    border-radius: 18px;
    padding: 45px;
    text-align: center;
}

.search {
    display: inline-block;

    margin-top: 15px;

    padding: 11px 18px;

    background: #e83e8c;
    color: white;

    text-decoration: none;

    border-radius: 10px;

    font-weight: 800;
}

@media(max-width:700px) {

    .navbar {
        flex-direction: column;
        gap: 12px;
    }

    .booking-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .detail {
        align-items: flex-start;
    }

    .detail strong {
        max-width: 60%;
    }

}

</style>

</head>


<body>


<header class="navbar">

    <div class="logo">
        🚌 Pink Ride
    </div>

    <nav class="nav">

        <a href="dashboard.php">🏠 Home</a>

        <a href="search_bus.php">🔍 Search Bus</a>

        <a href="my_bookings.php">🎫 My Bookings</a>

        <a href="notifications.php">🔔 Notification</a>

        <a href="profile.php">👤 Profile</a>

        <a href="logout.php">↪ Logout</a>

    </nav>

</header>


<main class="container">

<h1>
    My Bookings
</h1>

<p class="subtitle">
    View your booking details and ticket information.
</p>


<?php if (count($bookings) === 0): ?>


<div class="no-bookings">

    <h2>
        🎫 No Bookings Found
    </h2>

    <p>
        You have not made any bookings yet.
    </p>

    <a
        href="search_bus.php"
        class="search"
    >
        Search Bus
    </a>

</div>


<?php else: ?>


<?php foreach ($bookings as $booking): ?>


<div class="booking-card">


    <!-- BOOKING HEADER -->

    <div class="booking-header">

        <div>

            <div class="booking-code">

                Booking #
                <?= htmlspecialchars(
                    $booking['booking_code']
                ) ?>

            </div>

        </div>


        <div class="status">

            <?= htmlspecialchars(
                $booking['booking_status']
            ) ?>

        </div>

    </div>


    <!-- JOURNEY DATE -->

    <div class="detail">

        <span>
            Journey Date
        </span>

        <strong>

            <?php

            if (
                !empty(
                    $booking['journey_date']
                )
            ) {

                echo htmlspecialchars(
                    date(
                        'd M Y',
                        strtotime(
                            $booking['journey_date']
                        )
                    )
                );

            } else {

                echo 'N/A';

            }

            ?>

        </strong>

    </div>


    <!-- SCHEDULE -->

    <div class="detail">

        <span>
            Schedule ID
        </span>

        <strong>

            #<?= (int)
                $booking['schedule_id'] ?>

        </strong>

    </div>


    <!-- TOTAL SEATS -->

    <div class="detail">

        <span>
            Total Seats
        </span>

        <strong>

            <?= (int)
                $booking['total_seats'] ?>

        </strong>

    </div>
   


    <!-- AMOUNT -->

    <div class="detail">

        <span>
            Original Amount
        </span>

        <strong>

            ₹<?= number_format(
                (float)
                $booking['original_amount'],
                2
            ) ?>

        </strong>

    </div>


    <!-- DISCOUNT -->

    <div class="detail">

        <span>
            Discount
        </span>

        <strong>

            -₹<?= number_format(
                (float)
                $booking['discount_amount'],
                2
            ) ?>

        </strong>

    </div>


    <!-- FINAL AMOUNT -->

    <div class="detail">

        <span>
            Final Amount
        </span>

        <strong class="amount">

            ₹<?= number_format(
                (float)
                $booking['final_amount'],
                2
            ) ?>

        </strong>

    </div>


    <!-- ACTIONS -->

    <div class="actions">

        <a
            href="view_ticket.php?booking_id=<?= (int)$booking['booking_id'] ?>"
            class="button view"
        >
            🎫 View Ticket
        </a>


        <?php

        $status = strtolower(
            trim(
                $booking['booking_status']
            )
        );

        ?>

        <?php if (
            $status !== 'cancelled' &&
            $status !== 'canceled'
        ): ?>

            <a
                href="cancel_ticket.php?booking_id=<?= (int)$booking['booking_id'] ?>"
                class="button cancel"
                onclick="return confirm('Are you sure you want to cancel this booking?');"
            >
                ✕ Cancel Ticket
            </a>

        <?php endif; ?>

    </div>


</div>


<?php endforeach; ?>


<?php endif; ?>


</main>

</body>

</html>