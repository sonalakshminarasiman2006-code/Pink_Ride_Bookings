<?php

session_start();

require_once "db.php";

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);


/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];


/* =========================================================
   GET BOOKING ID
========================================================= */

$booking_id = isset($_GET['booking_id'])
    ? (int) $_GET['booking_id']
    : 0;

if ($booking_id <= 0) {
    die("Invalid booking.");
}


/* =========================================================
   GET USER BOOKING
========================================================= */

$sql = "
    SELECT
        booking_id,
        booking_code,
        user_id,
        schedule_id,
        journey_date,
        total_seats,
        seat_numbers,
        original_amount,
        discount_amount,
        final_amount,
        booking_status

    FROM bookings

    WHERE booking_id = ?
      AND user_id = ?

    LIMIT 1
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ii",
    $booking_id,
    $user_id
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $stmt->close();

    die("Booking not found.");
}

$booking = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   CHECK CURRENT STATUS
========================================================= */

$current_status = strtolower(
    trim(
        (string)$booking['booking_status']
    )
);

if (
    $current_status === 'cancelled' ||
    $current_status === 'canceled'
) {
    header(
        "Location: cancel_success.php?booking_id="
        . $booking_id
        . "&already=1"
    );

    exit();
}


/* =========================================================
   DISPLAY DATE
========================================================= */

$display_date = "N/A";

if (!empty($booking['journey_date'])) {

    $timestamp = strtotime(
        $booking['journey_date']
    );

    if ($timestamp !== false) {

        $display_date = date(
            'd M Y',
            $timestamp
        );
    }
}


/* =========================================================
   SEAT NUMBERS
========================================================= */

$seat_numbers = [];

if (
    isset($booking['seat_numbers']) &&
    trim(
        (string)$booking['seat_numbers']
    ) !== ''
) {

    $seat_numbers = explode(
        ',',
        $booking['seat_numbers']
    );
}


/* =========================================================
   CLEAN SEAT NUMBERS
========================================================= */

$clean_seats = [];

foreach ($seat_numbers as $seat) {

    $seat = trim($seat);

    if ($seat !== '') {

        $clean_seats[] = $seat;
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Cancel Booking | Pink Ride
</title>


<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #fff7fb;

    color: #252525;
}


/* =========================================================
   NAVBAR
========================================================= */

.navbar {

    min-height: 70px;

    background: #ffffff;

    border-bottom:
        1px solid #f1dce6;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 0 28px;
}

.logo {

    color: #e83e8c;

    font-size: 21px;

    font-weight: 800;
}

.nav {

    display: flex;

    gap: 5px;

    flex-wrap: wrap;
}

.nav a {

    text-decoration: none;

    color: #555;

    padding:
        9px 10px;

    border-radius: 9px;

    font-size: 13px;
}

.nav a:hover {

    background: #fff0f6;

    color: #e83e8c;
}


/* =========================================================
   CONTAINER
========================================================= */

.container {

    max-width: 700px;

    margin: auto;

    padding:
        40px 18px 60px;
}


/* =========================================================
   CARD
========================================================= */

.card {

    background: #ffffff;

    border:
        1px solid #f0dce5;

    border-radius: 22px;

    padding: 30px;

    box-shadow:
        0 12px 35px
        rgba(232,62,140,.08);
}


/* =========================================================
   ICON
========================================================= */

.icon {

    width: 70px;

    height: 70px;

    margin:
        0 auto 18px;

    border-radius: 50%;

    background: #fff1f1;

    color: #d33b3b;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 34px;

    font-weight: 900;
}


/* =========================================================
   TITLE
========================================================= */

.header {

    text-align: center;
}

h1 {

    margin:
        0 0 8px;

    font-size: 27px;

    color: #d33b3b;
}

.subtitle {

    color: #777;

    font-size: 14px;

    line-height: 1.6;

    margin-bottom: 25px;
}


/* =========================================================
   BOOKING BOX
========================================================= */

.booking-box {

    background: #fff8fb;

    border:
        1px solid #f3dce7;

    border-radius: 16px;

    padding: 20px;

    margin-bottom: 20px;
}

.detail {

    display: flex;

    justify-content:
        space-between;

    gap: 15px;

    padding:
        11px 0;

    border-bottom:
        1px solid #f0e1e7;

    font-size: 13px;
}

.detail:last-child {

    border-bottom: 0;
}

.detail span {

    color: #888;
}

.detail strong {

    text-align: right;
}


/* =========================================================
   SEATS
========================================================= */

.seats {

    display: flex;

    flex-wrap: wrap;

    justify-content: flex-end;

    gap: 7px;

    max-width: 60%;
}

.seat {

    background: #fff0f6;

    border:
        1px solid #ffd1e1;

    color: #d42f73;

    padding:
        7px 10px;

    border-radius: 8px;

    font-size: 12px;

    font-weight: 800;
}


/* =========================================================
   WARNING
========================================================= */

.warning {

    background: #fff7e8;

    border:
        1px solid #f5d99c;

    color: #8a6418;

    border-radius: 13px;

    padding: 14px;

    font-size: 13px;

    line-height: 1.6;

    margin-bottom: 22px;
}


/* =========================================================
   BUTTONS
========================================================= */

.actions {

    display: flex;

    gap: 12px;

    margin-top: 20px;
}

.button {

    flex: 1;

    border: 0;

    text-align: center;

    text-decoration: none;

    padding: 13px;

    border-radius: 11px;

    font-size: 13px;

    font-weight: 800;

    cursor: pointer;
}

.cancel-button {

    background: #d33b3b;

    color: white;
}

.cancel-button:hover {

    background: #b92e2e;
}

.back-button {

    background: white;

    color: #e83e8c;

    border:
        1px solid #e83e8c;
}

.back-button:hover {

    background: #fff0f6;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:650px) {

    .navbar {

        flex-direction: column;

        gap: 10px;

        padding: 13px;
    }

    .card {

        padding: 20px;
    }

    .detail {

        align-items:
            flex-start;

        flex-direction: column;

        gap: 5px;
    }

    .detail strong {

        text-align: left;
    }

    .seats {

        max-width: 100%;

        justify-content:
            flex-start;
    }

    .actions {

        flex-direction: column;
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

        <a href="dashboard.php">
            🏠 Home
        </a>

        <a href="search_bus.php">
            🔍 Search Bus
        </a>

        <a href="my_bookings.php">
            🎫 My Bookings
        </a>

        <a href="notifications.php">
            🔔 Notification
        </a>

        <a href="profile.php">
            👤 Profile
        </a>

        <a href="logout.php">
            ↪ Logout
        </a>

    </nav>

</header>


<main class="container">


<div class="card">


<div class="header">

    <div class="icon">
        !
    </div>

    <h1>
        Cancel Booking?
    </h1>

    <p class="subtitle">

        Are you sure you want to cancel
        this booking?

    </p>

</div>


<div class="booking-box">


<div class="detail">

    <span>
        Booking Reference
    </span>

    <strong>

        <?= htmlspecialchars(
            $booking['booking_code'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </strong>

</div>


<div class="detail">

    <span>
        Journey Date
    </span>

    <strong>

        <?= htmlspecialchars(
            $display_date,
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </strong>

</div>


<div class="detail">

    <span>
        Schedule ID
    </span>

    <strong>

        #<?= (int)
            $booking['schedule_id'] ?>

    </strong>

</div>


<div class="detail">

    <span>
        Total Seats
    </span>

    <strong>

        <?= (int)
            $booking['total_seats'] ?>

    </strong>

</div>


<div class="detail">

    <span>
        Seat Numbers
    </span>


    <?php if (
        count($clean_seats) > 0
    ): ?>

        <div class="seats">

            <?php foreach (
                $clean_seats
                as $seat
            ): ?>

                <span class="seat">

                    Seat
                    <?= htmlspecialchars(
                        $seat,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </span>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <strong>
            N/A
        </strong>

    <?php endif; ?>

</div>


<div class="detail">

    <span>
        Final Amount
    </span>

    <strong>

        ₹<?= number_format(
            (float)
            $booking['final_amount'],
            2
        ) ?>

    </strong>

</div>


</div>


<div class="warning">

    ⚠️ Once you cancel this booking,
    the booking status will become
    <strong>Cancelled</strong> and the
    selected seats will be released.

</div>


<form
    action="cancel_success.php"
    method="POST"
    onsubmit="return confirm('Are you sure you want to cancel this booking?');"
>


    <input
        type="hidden"
        name="booking_id"
        value="<?= (int)$booking_id ?>"
    >


    <div class="actions">

        <a
            href="my_bookings.php"
            class="button back-button"
        >
            ← Keep Booking
        </a>


        <button
            type="submit"
            class="button cancel-button"
        >
            ✕ Confirm Cancellation
        </button>

    </div>


</form>


</div>


</main>

</body>

</html>