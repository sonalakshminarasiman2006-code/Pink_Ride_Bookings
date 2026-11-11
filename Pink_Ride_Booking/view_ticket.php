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
   GET SCHEDULE + BUS INFORMATION
========================================================= */

$schedule_id = (int)$booking['schedule_id'];

$bus_id = 0;

$bus_name = 'Pink Ride Bus';

$bus_number = 'N/A';


/*
   We first try to get bus_id from schedules.
*/

$schedule_sql = "
    SELECT
        bus_id
    FROM schedules
    WHERE schedule_id = ?
    LIMIT 1
";

$schedule_stmt = $conn->prepare($schedule_sql);

$schedule_stmt->bind_param(
    "i",
    $schedule_id
);

$schedule_stmt->execute();

$schedule_result =
    $schedule_stmt->get_result();

if ($schedule_result->num_rows > 0) {

    $schedule_row =
        $schedule_result->fetch_assoc();

    $bus_id =
        (int)$schedule_row['bus_id'];
}

$schedule_stmt->close();


/* =========================================================
   GET BUS DETAILS
========================================================= */

if ($bus_id > 0) {

    $bus_sql = "
        SELECT
            bus_id,
            bus_name,
            bus_number
        FROM buses
        WHERE bus_id = ?
        LIMIT 1
    ";

    $bus_stmt =
        $conn->prepare($bus_sql);

    $bus_stmt->bind_param(
        "i",
        $bus_id
    );

    $bus_stmt->execute();

    $bus_result =
        $bus_stmt->get_result();

    if ($bus_result->num_rows > 0) {

        $bus_row =
            $bus_result->fetch_assoc();

        if (
            isset($bus_row['bus_name']) &&
            trim($bus_row['bus_name']) !== ''
        ) {

            $bus_name =
                $bus_row['bus_name'];
        }

        if (
            isset($bus_row['bus_number']) &&
            trim($bus_row['bus_number']) !== ''
        ) {

            $bus_number =
                $bus_row['bus_number'];
        }
    }

    $bus_stmt->close();
}


/* =========================================================
   GET SEAT NUMBERS
========================================================= */

/*
   IMPORTANT:

   Your seats table has:

   seat_id
   bus_id
   seat_number
   seat_type
   seat_status

   We use seat_id values stored during booking.

   If your bookings table has a separate booking_seats
   table, this section can be changed accordingly.

   First try booking_seats table.
*/


$clean_seats = [];


/* =========================================================
   METHOD 1
   booking_seats TABLE
========================================================= */

try {

    $seat_sql = "
        SELECT
            s.seat_id,
            s.seat_number
        FROM booking_seats bs
        INNER JOIN seats s
            ON s.seat_id = bs.seat_id
        WHERE bs.booking_id = ?
        ORDER BY s.seat_id ASC
    ";

    $seat_stmt =
        $conn->prepare($seat_sql);

    $seat_stmt->bind_param(
        "i",
        $booking_id
    );

    $seat_stmt->execute();

    $seat_result =
        $seat_stmt->get_result();

    while (
        $seat_row =
        $seat_result->fetch_assoc()
    ) {

        if (
            isset($seat_row['seat_number']) &&
            trim(
                (string)$seat_row['seat_number']
            ) !== ''
        ) {

            $clean_seats[] =
                trim(
                    (string)$seat_row['seat_number']
                );
        }
    }

    $seat_stmt->close();

} catch (Throwable $e) {

    /*
       booking_seats table does not exist.
       We silently continue to the next method.
    */

}


/* =========================================================
   METHOD 2
   IF NO SEATS FOUND
========================================================= */

/*
   If booking_seats does not exist or returned nothing,
   try to read seat IDs from a possible booking seat field.

   This supports cases where seat IDs were saved as:

   1,2,3

   in bookings.seat_ids
*/


if (count($clean_seats) === 0) {

    try {

        $seat_id_sql = "
            SELECT seat_ids
            FROM bookings
            WHERE booking_id = ?
              AND user_id = ?
            LIMIT 1
        ";

        $seat_id_stmt =
            $conn->prepare(
                $seat_id_sql
            );

        $seat_id_stmt->bind_param(
            "ii",
            $booking_id,
            $user_id
        );

        $seat_id_stmt->execute();

        $seat_id_result =
            $seat_id_stmt->get_result();

        if (
            $seat_id_result->num_rows > 0
        ) {

            $seat_id_row =
                $seat_id_result->fetch_assoc();

            $seat_ids_text =
                trim(
                    (string)
                    $seat_id_row['seat_ids']
                );

            if ($seat_ids_text !== '') {

                $seat_ids =
                    explode(
                        ',',
                        $seat_ids_text
                    );

                $seat_ids = array_map(
                    'intval',
                    $seat_ids
                );

                $seat_ids =
                    array_values(
                        array_unique(
                            array_filter(
                                $seat_ids,
                                function ($id) {
                                    return $id > 0;
                                }
                            )
                        )
                    );


                /* =================================================
                   GET ACTUAL SEAT NUMBERS FROM seats TABLE
                ================================================= */

                if (
                    count($seat_ids) > 0 &&
                    $bus_id > 0
                ) {

                    $placeholders =
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($seat_ids),
                                '?'
                            )
                        );

                    $types =
                        str_repeat(
                            'i',
                            count($seat_ids)
                        );

                    $seat_number_sql = "
                        SELECT
                            seat_id,
                            seat_number
                        FROM seats
                        WHERE bus_id = ?
                          AND seat_id IN (
                              $placeholders
                          )
                        ORDER BY seat_id ASC
                    ";

                    $seat_number_stmt =
                        $conn->prepare(
                            $seat_number_sql
                        );


                    $params = [];

                    $params[] = $bus_id;

                    foreach (
                        $seat_ids as $id
                    ) {

                        $params[] = $id;
                    }


                    $bind_types =
                        'i' . $types;


                    $bind_values = [];

                    $bind_values[] =
                        $bind_types;

                    foreach (
                        $params as $key => $value
                    ) {

                        $bind_values[] =
                            &$params[$key];
                    }


                    call_user_func_array(
                        [
                            $seat_number_stmt,
                            'bind_param'
                        ],
                        $bind_values
                    );


                    $seat_number_stmt->execute();

                    $seat_number_result =
                        $seat_number_stmt->get_result();


                    while (
                        $seat_row =
                        $seat_number_result->fetch_assoc()
                    ) {

                        if (
                            isset(
                                $seat_row['seat_number']
                            )
                        ) {

                            $seat_number =
                                trim(
                                    (string)
                                    $seat_row['seat_number']
                                );

                            if (
                                $seat_number !== ''
                            ) {

                                $clean_seats[] =
                                    $seat_number;
                            }
                        }
                    }


                    $seat_number_stmt->close();
                }
            }
        }

        $seat_id_stmt->close();

    } catch (Throwable $e) {

        /*
           seat_ids column is not available.
           Continue without stopping the ticket.
        */

    }
}


/* =========================================================
   METHOD 3
   OLD seat_numbers COLUMN
========================================================= */

if (count($clean_seats) === 0) {

    try {

        $old_seat_sql = "
            SELECT seat_numbers
            FROM bookings
            WHERE booking_id = ?
              AND user_id = ?
            LIMIT 1
        ";

        $old_seat_stmt =
            $conn->prepare(
                $old_seat_sql
            );

        $old_seat_stmt->bind_param(
            "ii",
            $booking_id,
            $user_id
        );

        $old_seat_stmt->execute();

        $old_seat_result =
            $old_seat_stmt->get_result();

        if (
            $old_seat_result->num_rows > 0
        ) {

            $old_row =
                $old_seat_result->fetch_assoc();

            $seat_text =
                trim(
                    (string)
                    $old_row['seat_numbers']
                );

            if ($seat_text !== '') {

                $old_seats =
                    explode(
                        ',',
                        $seat_text
                    );

                foreach (
                    $old_seats as $seat
                ) {

                    $seat =
                        trim(
                            (string)$seat
                        );

                    if ($seat !== '') {

                        $clean_seats[] =
                            $seat;
                    }
                }
            }
        }

        $old_seat_stmt->close();

    } catch (Throwable $e) {

        /*
           seat_numbers column does not exist.
        */

    }
}


/* =========================================================
   REMOVE DUPLICATES
========================================================= */

$clean_seats =
    array_values(
        array_unique(
            $clean_seats
        )
    );


/* =========================================================
   JOURNEY DATE
========================================================= */

$display_date = 'N/A';

if (
    !empty(
        $booking['journey_date']
    )
) {

    $timestamp =
        strtotime(
            $booking['journey_date']
        );

    if (
        $timestamp !== false
    ) {

        $display_date =
            date(
                'd M Y',
                $timestamp
            );
    }
}


/* =========================================================
   STATUS
========================================================= */

$booking_status =
    trim(
        (string)(
            $booking['booking_status']
            ?? 'Confirmed'
        )
    );

if (
    $booking_status === ''
) {

    $booking_status =
        'Confirmed';
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
    View Ticket | Pink Ride
</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #fff7fb;
    color: #252525;
}

.navbar {
    min-height: 70px;
    background: #ffffff;
    border-bottom: 1px solid #f1dce6;

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
    padding: 9px 10px;
    border-radius: 9px;
    font-size: 13px;
}

.nav a:hover {
    background: #fff0f6;
    color: #e83e8c;
}

.container {
    max-width: 850px;
    margin: auto;
    padding: 30px 18px 60px;
}

.back {
    text-decoration: none;
    color: #e83e8c;
    font-weight: 700;
    font-size: 14px;
}

h1 {
    margin: 20px 0 6px;
    font-size: 29px;
}

.subtitle {
    color: #777;
    font-size: 14px;
    margin-bottom: 25px;
}

.ticket {
    background: #ffffff;
    border: 1px solid #f0dce5;
    border-radius: 22px;
    overflow: hidden;

    box-shadow:
        0 15px 40px
        rgba(232,62,140,.08);
}

.ticket-header {
    background: #e83e8c;
    color: white;
    padding: 25px;

    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}

.ticket-title {
    font-size: 23px;
    font-weight: 800;
}

.ticket-code {
    margin-top: 6px;
    font-size: 13px;
}

.confirmed {
    background: white;
    color: #168652;
    padding: 8px 13px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
}

.route {
    padding: 25px;
    background: #fff8fb;
    border-bottom: 1px dashed #e7cdd8;

    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.route-title {
    color: #888;
    font-size: 11px;
}

.route-value {
    font-size: 20px;
    font-weight: 800;
    margin-top: 5px;
}

.arrow {
    color: #e83e8c;
    font-size: 28px;
    font-weight: 900;
}

.details {
    padding: 22px;
}

.detail {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;

    padding: 14px 0;

    border-bottom: 1px solid #f4e7ed;

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

.seat-container {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 7px;
    max-width: 65%;
}

.seat {
    background: #fff0f6;
    color: #d42f73;

    border: 1px solid #ffd1e1;

    padding: 7px 11px;

    border-radius: 8px;

    font-size: 12px;
    font-weight: 800;
}

.no-seat {
    color: #888;
    font-weight: 600;
}

.amount {
    color: #e83e8c;
    font-size: 19px;
}

.ticket-footer {
    padding: 20px 22px;

    background: #fafafa;

    border-top: 1px dashed #dfccd5;

    color: #777;

    font-size: 11px;

    line-height: 1.6;
}

.actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.button {
    flex: 1;
    text-align: center;
    text-decoration: none;

    padding: 13px;

    border-radius: 11px;

    font-size: 13px;
    font-weight: 800;
}

.back-button {
    background: #ffffff;
    border: 1px solid #e83e8c;
    color: #e83e8c;
}

.bookings-button {
    background: #e83e8c;
    color: white;
}

@media(max-width: 650px) {

    .navbar {
        flex-direction: column;
        gap: 10px;
        padding: 13px;
    }

    .ticket-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .route {
        flex-direction: column;
        align-items: flex-start;
    }

    .arrow {
        transform: rotate(90deg);
    }

    .detail {
        align-items: flex-start;
    }

    .seat-container {
        max-width: 60%;
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


<a
    href="my_bookings.php"
    class="back"
>
    ← Back to My Bookings
</a>


<h1>
    Your Ticket
</h1>


<p class="subtitle">
    Your confirmed booking details.
</p>


<div class="ticket">


<!-- =====================================================
     HEADER
===================================================== -->

<div class="ticket-header">

    <div>

        <div class="ticket-title">
            🎫 Pink Ride Ticket
        </div>

        <div class="ticket-code">

            Booking Reference:

            <?= htmlspecialchars(
                $booking['booking_code'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>

    </div>


    <div class="confirmed">

        <?= htmlspecialchars(
            $booking_status,
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </div>

</div>


<!-- =====================================================
     ROUTE
===================================================== -->

<div class="route">

    <div>

        <div class="route-title">
            Booking
        </div>

        <div class="route-value">
            Pink Ride
        </div>

    </div>


    <div class="arrow">
        →
    </div>


    <div>

        <div class="route-title">
            Schedule
        </div>

        <div class="route-value">

            #<?= (int)
                $booking['schedule_id'] ?>

        </div>

    </div>

</div>


<!-- =====================================================
     DETAILS
===================================================== -->

<div class="details">


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
        Bus
    </span>

    <strong>

        <?= htmlspecialchars(
            $bus_name,
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </strong>

</div>


<div class="detail">

    <span>
        Bus Number
    </span>

    <strong>

        <?= htmlspecialchars(
            $bus_number,
            ENT_QUOTES,
            'UTF-8'
        ) ?>

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


<!-- =====================================================
     ACTUAL SEAT NUMBERS
===================================================== -->

<div class="detail">

    <span>
        Seat Numbers
    </span>


    <?php if (
        count($clean_seats) > 0
    ): ?>

        <div class="seat-container">

            <?php foreach (
                $clean_seats as $seat
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

        <strong class="no-seat">
            Seat number not available
        </strong>

    <?php endif; ?>

</div>


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


<div class="detail">

    <span>
        Booking Status
    </span>

    <strong>

        <?= htmlspecialchars(
            $booking_status,
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </strong>

</div>


</div>


<div class="ticket-footer">

    🔒 Keep your booking reference safely.
    Please show this ticket when required.

</div>


</div>


<div class="actions">

    <a
        href="my_bookings.php"
        class="button back-button"
    >
        ← My Bookings
    </a>


    <a
        href="search_bus.php"
        class="button bookings-button"
    >
        🚌 Book Another Ticket
    </a>

</div>


</main>

</body>

</html>