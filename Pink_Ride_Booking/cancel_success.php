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

$booking_id = isset($_POST['booking_id'])
    ? (int)$_POST['booking_id']
    : 0;


/*
   Also allow redirect from cancel.php
   when booking was already cancelled.
*/

$already_cancelled =
    isset($_GET['already']) &&
    $_GET['already'] == '1';

if ($booking_id <= 0) {

    $booking_id = isset($_GET['booking_id'])
        ? (int)$_GET['booking_id']
        : 0;
}

if ($booking_id <= 0) {

    die("Invalid booking.");
}


/* =========================================================
   GET BOOKING
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
   CHECK STATUS
========================================================= */

$current_status = strtolower(
    trim(
        (string)$booking['booking_status']
    )
);


/* =========================================================
   ALREADY CANCELLED
========================================================= */

if (
    $current_status === 'cancelled' ||
    $current_status === 'canceled' ||
    $already_cancelled
) {

    $cancelled = true;

} else {

    $cancelled = false;
}


/* =========================================================
   CANCEL BOOKING
========================================================= */

if (!$cancelled) {

    try {

        /*
           Start transaction so booking and seats
           are updated together.
        */

        $conn->begin_transaction();


        /* =================================================
           UPDATE BOOKING STATUS
        ================================================= */

        $update_sql = "
            UPDATE bookings
            SET booking_status = 'Cancelled'
            WHERE booking_id = ?
              AND user_id = ?
              AND booking_status NOT IN
                  ('Cancelled', 'Canceled')
        ";

        $update_stmt =
            $conn->prepare(
                $update_sql
            );

        $update_stmt->bind_param(
            "ii",
            $booking_id,
            $user_id
        );

        $update_stmt->execute();

        $updated_rows =
            $update_stmt->affected_rows;

        $update_stmt->close();


        /*
           If another request already cancelled it,
           don't perform seat changes twice.
        */

        if ($updated_rows === 0) {

            $conn->commit();

            header(
                "Location: cancel_success.php"
                . "?booking_id="
                . $booking_id
                . "&already=1"
            );

            exit();
        }


        /* =================================================
           GET BUS ID FROM SCHEDULE
        ================================================= */

        $schedule_sql = "
            SELECT bus_id
            FROM schedules
            WHERE schedule_id = ?
            LIMIT 1
        ";

        $schedule_stmt =
            $conn->prepare(
                $schedule_sql
            );

        $schedule_stmt->bind_param(
            "i",
            $booking['schedule_id']
        );

        $schedule_stmt->execute();

        $schedule_result =
            $schedule_stmt->get_result();

        $schedule_row =
            $schedule_result->fetch_assoc();

        $schedule_stmt->close();


        $bus_id = 0;

        if ($schedule_row) {

            $bus_id =
                (int)$schedule_row['bus_id'];
        }


        /* =================================================
           RELEASE SEATS
        ================================================= */

        if (
            $bus_id > 0 &&
            !empty($booking['seat_numbers'])
        ) {

            $seat_numbers =
                explode(
                    ',',
                    $booking['seat_numbers']
                );


            foreach (
                $seat_numbers
                as $seat_number
            ) {

                $seat_number =
                    trim($seat_number);


                if ($seat_number === '') {
                    continue;
                }


                /*
                   Change the matching seat to Available.
                */

                $seat_sql = "
                    UPDATE seats
                    SET seat_status = 'Available'
                    WHERE bus_id = ?
                      AND seat_number = ?
                ";

                $seat_stmt =
                    $conn->prepare(
                        $seat_sql
                    );

                $seat_stmt->bind_param(
                    "is",
                    $bus_id,
                    $seat_number
                );

                $seat_stmt->execute();

                $seat_stmt->close();
            }
        }


        /* =================================================
           COMMIT
        ================================================= */

        $conn->commit();

        $cancelled = true;


    } catch (Throwable $e) {

        /*
           Roll back if anything failed.
        */

        if ($conn->in_transaction) {

            $conn->rollback();
        }

        die(
            "Cancellation failed.<br><br>"
            . "Database error: "
            . htmlspecialchars(
                $e->getMessage(),
                ENT_QUOTES,
                'UTF-8'
            )
        );
    }
}


/* =========================================================
   DISPLAY DATE
========================================================= */

$display_date = "N/A";

if (!empty($booking['journey_date'])) {

    $timestamp =
        strtotime(
            $booking['journey_date']
        );

    if ($timestamp !== false) {

        $display_date =
            date(
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

    $seat_numbers =
        explode(
            ',',
            $booking['seat_numbers']
        );
}


$clean_seats = [];

foreach (
    $seat_numbers
    as $seat
) {

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
    Cancellation Successful | Pink Ride
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
        45px 18px 60px;
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

    text-align: center;
}


/* =========================================================
   SUCCESS ICON
========================================================= */

.success-icon {

    width: 75px;

    height: 75px;

    margin:
        0 auto 20px;

    border-radius: 50%;

    background: #e9f9f0;

    color: #168652;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 38px;

    font-weight: 900;
}


/* =========================================================
   TITLE
========================================================= */

h1 {

    margin:
        0 0 8px;

    color: #168652;

    font-size: 28px;
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

    text-align: left;

    margin-bottom: 22px;
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

    justify-content: flex-end;

    flex-wrap: wrap;

    gap: 7px;
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
   CANCELLED STATUS
========================================================= */

.cancelled-status {

    display: inline-block;

    margin-bottom: 20px;

    padding:
        8px 15px;

    border-radius: 20px;

    background: #fff1f1;

    color: #d33b3b;

    font-size: 12px;

    font-weight: 800;
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

    text-decoration: none;

    text-align: center;

    padding: 13px;

    border-radius: 11px;

    font-size: 13px;

    font-weight: 800;
}

.primary {

    background: #e83e8c;

    color: white;
}

.secondary {

    background: white;

    color: #e83e8c;

    border:
        1px solid #e83e8c;
}

.primary:hover {

    background: #d72f76;
}

.secondary:hover {

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

        flex-direction: column;

        align-items:
            flex-start;

        gap: 5px;
    }

    .detail strong {

        text-align: left;
    }

    .seats {

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


<div class="success-icon">
    ✓
</div>


<div class="cancelled-status">
    BOOKING CANCELLED
</div>


<h1>
    Cancellation Successful
</h1>


<p class="subtitle">

    Your booking has been cancelled successfully.

    The selected seats have been released.

</p>


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
        Booking Status
    </span>

    <strong>

        Cancelled

    </strong>

</div>


<div class="detail">

    <span>
        Amount
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


<div class="actions">

    <a
        href="my_bookings.php"
        class="button primary"
    >
        🎫 My Bookings
    </a>


    <a
        href="search_bus.php"
        class="button secondary"
    >
        🚌 Book Another Ticket
    </a>

</div>


</div>


</main>

</body>

</html>