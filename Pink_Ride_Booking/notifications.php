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
   GET USER BOOKINGS
========================================================= */

$sql = "
    SELECT
        booking_id,
        booking_code,
        schedule_id,
        journey_date,
        total_seats,
        seat_numbers,
        final_amount,
        booking_status
    FROM bookings
    WHERE user_id = ?
    ORDER BY booking_id DESC
    LIMIT 20
";


$stmt = $conn->prepare($sql);

if (!$stmt) {
    die(
        "Notification query error: "
        . htmlspecialchars(
            $conn->error,
            ENT_QUOTES,
            'UTF-8'
        )
    );
}


$stmt->bind_param(
    "i",
    $user_id
);


$stmt->execute();

$result = $stmt->get_result();

$bookings = [];


while ($row = $result->fetch_assoc()) {

    $bookings[] = $row;
}


$stmt->close();


/* =========================================================
   CREATE NOTIFICATIONS
========================================================= */

$notifications = [];


foreach ($bookings as $booking) {

    $status = strtolower(
        trim(
            (string)$booking['booking_status']
        )
    );


    /* =====================================================
       JOURNEY DATE
    ===================================================== */

    $journey_date = "N/A";


    if (!empty($booking['journey_date'])) {

        $timestamp = strtotime(
            $booking['journey_date']
        );


        if ($timestamp !== false) {

            $journey_date = date(
                'd M Y',
                $timestamp
            );
        }
    }


    /* =====================================================
       SEAT COUNT
    ===================================================== */

    $seat_count = (int)$booking['total_seats'];


    /* =====================================================
       CONFIRMED BOOKING
    ===================================================== */

    if (
        $status === 'confirmed' ||
        $status === 'confirm'
    ) {

        $notifications[] = [

            'type' => 'success',

            'icon' => '✓',

            'title' =>
                'Booking Confirmed',

            'message' =>
                'Your booking '
                . $booking['booking_code']
                . ' has been confirmed for '
                . $journey_date
                . '. You have '
                . $seat_count
                . ' seat(s).',

            'booking_id' =>
                (int)$booking['booking_id'],

            'date' =>
                $journey_date
        ];
    }


    /* =====================================================
       CANCELLED BOOKING
    ===================================================== */

    elseif (
        $status === 'cancelled' ||
        $status === 'canceled'
    ) {

        $notifications[] = [

            'type' => 'cancelled',

            'icon' => '✕',

            'title' =>
                'Booking Cancelled',

            'message' =>
                'Your booking '
                . $booking['booking_code']
                . ' has been cancelled successfully.',

            'booking_id' =>
                (int)$booking['booking_id'],

            'date' =>
                $journey_date
        ];
    }


    /* =====================================================
       OTHER STATUS
    ===================================================== */

    else {

        $notifications[] = [

            'type' => 'info',

            'icon' => 'ℹ',

            'title' =>
                'Booking Update',

            'message' =>
                'Booking '
                . $booking['booking_code']
                . ' currently has status: '
                . $booking['booking_status']
                . '.',

            'booking_id' =>
                (int)$booking['booking_id'],

            'date' =>
                $journey_date
        ];
    }
}


/* =========================================================
   ADD GENERAL NOTIFICATION IF NO BOOKINGS
========================================================= */

if (count($notifications) === 0) {

    $notifications[] = [

        'type' => 'info',

        'icon' => '🔔',

        'title' =>
            'No Notifications',

        'message' =>
            'You do not have any booking notifications yet.',

        'booking_id' =>
            0,

        'date' =>
            ''
    ];
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
    Notifications | Pink Ride
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

    max-width: 900px;

    margin: auto;

    padding:
        35px 18px 60px;
}


h1 {

    margin:
        0 0 8px;

    font-size: 29px;
}


.subtitle {

    color: #777;

    font-size: 14px;

    margin-bottom: 25px;
}


/* =========================================================
   NOTIFICATION CARD
========================================================= */

.notification-card {

    background: #ffffff;

    border:
        1px solid #f0dce5;

    border-radius: 17px;

    padding: 20px;

    margin-bottom: 14px;

    display: flex;

    gap: 15px;

    align-items: flex-start;

    box-shadow:
        0 8px 25px
        rgba(232,62,140,.05);
}


/* =========================================================
   ICON
========================================================= */

.notification-icon {

    width: 45px;

    height: 45px;

    min-width: 45px;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 20px;

    font-weight: 900;
}


.notification-icon.success {

    background: #e9f9f0;

    color: #168652;
}


.notification-icon.cancelled {

    background: #fff1f1;

    color: #d33b3b;
}


.notification-icon.info {

    background: #fff0f6;

    color: #e83e8c;
}


/* =========================================================
   CONTENT
========================================================= */

.notification-content {

    flex: 1;
}


.notification-title {

    font-size: 16px;

    font-weight: 800;

    margin-bottom: 6px;
}


.notification-message {

    color: #666;

    font-size: 13px;

    line-height: 1.6;
}


.notification-date {

    color: #999;

    font-size: 11px;

    margin-top: 8px;
}


/* =========================================================
   VIEW BUTTON
========================================================= */

.view-ticket {

    display: inline-block;

    margin-top: 11px;

    padding:
        8px 12px;

    background: #e83e8c;

    color: #ffffff;

    text-decoration: none;

    border-radius: 8px;

    font-size: 11px;

    font-weight: 800;
}


.view-ticket:hover {

    background: #d72f76;
}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty {

    background: #ffffff;

    border:
        1px solid #f0dce5;

    border-radius: 18px;

    padding: 45px 20px;

    text-align: center;
}


.empty-icon {

    font-size: 42px;

    margin-bottom: 12px;
}


.empty h2 {

    margin:
        0 0 8px;
}


.empty p {

    color: #777;

    font-size: 13px;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:700px) {

    .navbar {

        flex-direction: column;

        gap: 10px;

        padding: 13px;
    }


    .notification-card {

        padding: 16px;
    }


    .notification-icon {

        width: 40px;

        height: 40px;

        min-width: 40px;
    }

}

</style>

</head>


<body>


<!-- =====================================================
     NAVBAR
===================================================== -->

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


<!-- =====================================================
     MAIN
===================================================== -->

<main class="container">


<h1>
    🔔 Notifications
</h1>


<p class="subtitle">

    Stay updated with your Pink Ride
    booking information.

</p>


<?php if (
    count($notifications) === 1 &&
    $notifications[0]['booking_id'] === 0
): ?>


<!-- =====================================================
     EMPTY
===================================================== -->

<div class="empty">

    <div class="empty-icon">
        🔔
    </div>

    <h2>
        No Notifications
    </h2>

    <p>
        You do not have any booking
        notifications yet.
    </p>

</div>


<?php else: ?>


<!-- =====================================================
     NOTIFICATIONS
===================================================== -->

<?php foreach (
    $notifications
    as $notification
): ?>


<div class="notification-card">


    <div
        class="notification-icon
        <?= htmlspecialchars(
            $notification['type'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
    >

        <?= htmlspecialchars(
            $notification['icon'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </div>


    <div class="notification-content">


        <div class="notification-title">

            <?= htmlspecialchars(
                $notification['title'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>


        <div class="notification-message">

            <?= htmlspecialchars(
                $notification['message'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>


        <?php if (
            $notification['date'] !== ''
        ): ?>

            <div class="notification-date">

                Journey Date:
                <?= htmlspecialchars(
                    $notification['date'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        <?php endif; ?>


        <?php if (
            $notification['booking_id'] > 0
        ): ?>

            <a
                href="view_ticket.php?booking_id=<?= (int)$notification['booking_id'] ?>"
                class="view-ticket"
            >
                🎫 View Ticket
            </a>

        <?php endif; ?>


    </div>


</div>


<?php endforeach; ?>


<?php endif; ?>


</main>


</body>

</html>