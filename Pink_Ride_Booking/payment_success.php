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
    die("User login information is missing.");
}

$user_id = (int)$_SESSION['user_id'];


/* =========================================================
   GET DATA
========================================================= */

$schedule_id = (int)($_POST['schedule_id'] ?? 0);

$journey_date = trim(
    $_POST['journey_date'] ?? ''
);

$selected_seats = $_POST['seats'] ?? [];

$payment_method = trim(
    $_POST['payment_method'] ?? ''
);

$original_amount = (float)(
    $_POST['original_amount'] ?? 0
);

$discount_amount = (float)(
    $_POST['discount_amount'] ?? 0
);

$final_amount = (float)(
    $_POST['final_amount'] ?? 0
);

$source = trim(
    $_POST['source'] ?? ''
);

$destination = trim(
    $_POST['destination'] ?? ''
);

$bus_name = trim(
    $_POST['bus_name'] ?? 'Pink Ride Bus'
);

$bus_number = trim(
    $_POST['bus_number'] ?? 'N/A'
);

$departure_time = trim(
    $_POST['departure_time'] ?? ''
);

$arrival_time = trim(
    $_POST['arrival_time'] ?? ''
);

$offer_name = trim(
    $_POST['offer_name'] ?? 'No Festival Offer'
);


/* =========================================================
   VALIDATION
========================================================= */

if ($schedule_id <= 0) {
    die("Invalid schedule information.");
}

if ($journey_date === '') {
    die("Journey date is missing.");
}

if (!is_array($selected_seats)) {
    die("Seat information is invalid.");
}

if ($payment_method === '') {
    die("Please select a payment method.");
}


/* =========================================================
   DATE VALIDATION
========================================================= */

$date_object = DateTime::createFromFormat(
    'Y-m-d',
    $journey_date
);

if (
    !$date_object ||
    $date_object->format('Y-m-d') !== $journey_date
) {
    die("Invalid journey date.");
}


/* =========================================================
   EXACT SEAT NUMBERS
========================================================= */

/*
   IMPORTANT

   Your project uses simple seat numbers:

   1
   2
   3
   ...
   47
   48
   ...
   50

   We DO NOT create:

   1A
   1B
   24A
   24B

   The selected value itself is the seat number.
*/

$seat_numbers = [];

foreach ($selected_seats as $seat) {

    $seat = trim((string)$seat);

    if ($seat === '') {
        continue;
    }

    /*
       Only numeric seat numbers are allowed.
    */

    if (!ctype_digit($seat)) {
        continue;
    }

    $seat_number = (int)$seat;

    /*
       Your bus contains seats 1 to 50.
    */

    if (
        $seat_number >= 1 &&
        $seat_number <= 50
    ) {
        $seat_numbers[] = $seat_number;
    }
}


/* =========================================================
   REMOVE DUPLICATES
========================================================= */

$seat_numbers = array_values(
    array_unique($seat_numbers)
);

sort($seat_numbers, SORT_NUMERIC);


if (count($seat_numbers) === 0) {
    die("No valid seats selected.");
}


$total_seats = count($seat_numbers);


/* =========================================================
   GET BUS ID
========================================================= */

$bus_id = 0;

$schedule_sql = "
    SELECT
        schedule_id,
        bus_id
    FROM schedules
    WHERE schedule_id = ?
    LIMIT 1
";

$schedule_stmt =
    $conn->prepare($schedule_sql);

$schedule_stmt->bind_param(
    "i",
    $schedule_id
);

$schedule_stmt->execute();

$schedule_result =
    $schedule_stmt->get_result();

if ($schedule_result->num_rows === 0) {

    $schedule_stmt->close();

    die(
        "Schedule was not found."
    );
}

$schedule_row =
    $schedule_result->fetch_assoc();

$bus_id =
    (int)$schedule_row['bus_id'];

$schedule_stmt->close();


if ($bus_id <= 0) {
    die("Bus information is missing.");
}


/* =========================================================
   CHECK SAME-DATE BOOKED SEATS
========================================================= */

/*
   IMPORTANT

   A seat is blocked only for:

   SAME schedule
   +
   SAME journey date

   Example:

   User A books seat 47 on 2026-08-22

   User B:
   2026-08-22 -> seat 47 BLOCKED

   User B:
   2026-08-23 -> seat 47 AVAILABLE
*/

$booked_seats = [];


$booked_sql = "
    SELECT
        bs.seat_id,
        bs.seat_status

    FROM booking_seats bs

    INNER JOIN bookings bk
        ON bs.booking_id = bk.booking_id

    WHERE bk.schedule_id = ?
      AND bk.journey_date = ?

      AND bk.booking_status IN (
          'confirmed',
          'paid',
          'Confirmed',
          'Paid'
      )

      AND bs.seat_status IN (
          'booked',
          'Booked'
      )
";


$booked_stmt =
    $conn->prepare($booked_sql);

$booked_stmt->bind_param(
    "is",
    $schedule_id,
    $journey_date
);

$booked_stmt->execute();

$booked_result =
    $booked_stmt->get_result();


while (
    $row =
    $booked_result->fetch_assoc()
) {

    /*
       IMPORTANT:

       booking_seats.seat_id must correspond
       to the seat number used by your project.

       Therefore we treat the value as the
       actual seat number.
    */

    $booked_seat =
        (int)$row['seat_id'];

    if (
        $booked_seat >= 1 &&
        $booked_seat <= 50
    ) {
        $booked_seats[] =
            $booked_seat;
    }
}

$booked_stmt->close();


$booked_seats = array_values(
    array_unique($booked_seats)
);


/* =========================================================
   PREVENT DOUBLE BOOKING
========================================================= */

foreach ($seat_numbers as $selected_seat) {

    if (
        in_array(
            $selected_seat,
            $booked_seats,
            true
        )
    ) {

        die(
            "Seat "
            . $selected_seat
            . " is already booked for "
            . htmlspecialchars(
                $journey_date,
                ENT_QUOTES,
                'UTF-8'
            )
            . ". Please select another seat."
        );
    }
}


/* =========================================================
   CREATE SEAT TEXT
========================================================= */

$seat_numbers_text =
    implode(
        ', ',
        $seat_numbers
    );


/* =========================================================
   CREATE BOOKING CODE
========================================================= */

$booking_code =
    'PR'
    . date('Ymd')
    . strtoupper(
        substr(
            bin2hex(
                random_bytes(5)
            ),
            0,
            7
        )
    );


/* =========================================================
   BOOKING STATUS
========================================================= */

$booking_status = 'Confirmed';


/* =========================================================
   INSERT BOOKING
========================================================= */

$insert_sql = "
    INSERT INTO bookings
    (
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
    )
    VALUES
    (
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?
    )
";


try {

    $insert_stmt =
        $conn->prepare($insert_sql);

    $insert_stmt->bind_param(
        "siisissdds",
        $booking_code,
        $user_id,
        $schedule_id,
        $journey_date,
        $total_seats,
        $seat_numbers_text,
        $original_amount,
        $discount_amount,
        $final_amount,
        $booking_status
    );

    $insert_stmt->execute();

    $booking_id =
        $conn->insert_id;

    $insert_stmt->close();

} catch (Throwable $e) {

    die(
        "Booking could not be saved.<br><br>"
        . htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}


if ($booking_id <= 0) {
    die(
        "Payment was successful, but booking "
        . "could not be created."
    );
}


/* =========================================================
   DISPLAY VALUES
========================================================= */

$display_date =
    date(
        'd M Y',
        strtotime($journey_date)
    );


$display_departure =
    !empty($departure_time)
    ? date(
        'h:i A',
        strtotime($departure_time)
    )
    : 'N/A';


$display_arrival =
    !empty($arrival_time)
    ? date(
        'h:i A',
        strtotime($arrival_time)
    )
    : 'N/A';

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
    Payment Successful | Pink Ride
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


/* =========================================================
   SUCCESS CARD
========================================================= */

.success-card {

    background: #ffffff;

    border:
        1px solid #f0dce5;

    border-radius: 22px;

    padding: 30px;

    box-shadow:
        0 12px 35px
        rgba(232,62,140,.08);
}


.success-header {

    text-align: center;
}


.success-icon {

    width: 70px;

    height: 70px;

    margin:
        0 auto 18px;

    border-radius: 50%;

    background: #e9f9f0;

    color: #168652;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 38px;

    font-weight: 900;
}


h1 {

    margin:
        0 0 8px;

    color: #168652;

    font-size: 29px;
}


.subtitle {

    color: #777;

    font-size: 14px;

    line-height: 1.6;

    margin-bottom: 25px;
}


/* =========================================================
   BOOKING REFERENCE
========================================================= */

.reference-box {

    background: #fff0f6;

    border:
        1px solid #ffd1e1;

    border-radius: 15px;

    padding: 18px;

    text-align: center;

    margin-bottom: 22px;
}


.reference-label {

    color: #888;

    font-size: 12px;

    margin-bottom: 7px;
}


.reference-code {

    color: #d42f73;

    font-size: 25px;

    font-weight: 900;

    letter-spacing: 1px;
}


/* =========================================================
   DETAILS
========================================================= */

.details-card {

    border:
        1px solid #f0dce5;

    border-radius: 17px;

    padding: 20px;

    margin-top: 20px;
}


.details-card h2 {

    margin:
        0 0 17px;

    font-size: 19px;
}


/* =========================================================
   ROUTE
========================================================= */

.route {

    background: #fff8fb;

    border:
        1px solid #f3dce7;

    border-radius: 15px;

    padding: 16px;

    margin-bottom: 15px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;
}


.city {

    font-size: 17px;

    font-weight: 800;
}


.route-arrow {

    color: #e83e8c;

    font-size: 24px;

    font-weight: 900;
}


/* =========================================================
   DETAILS ROW
========================================================= */

.detail {

    display: flex;

    justify-content: space-between;

    gap: 15px;

    padding: 12px 0;

    border-bottom:
        1px solid #f4e7ed;

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
   SEAT NUMBERS
========================================================= */

.seats {

    display: flex;

    flex-wrap: wrap;

    gap: 8px;

    margin-top: 13px;
}


.seat {

    background: #fff0f6;

    color: #d42f73;

    border:
        1px solid #ffd1e1;

    padding:
        8px 12px;

    border-radius: 9px;

    font-size: 12px;

    font-weight: 800;
}


/* =========================================================
   AMOUNT
========================================================= */

.final-amount {

    color: #e83e8c;

    font-size: 20px;

    font-weight: 900;
}


/* =========================================================
   PAYMENT MESSAGE
========================================================= */

.payment-message {

    margin-top: 20px;

    padding: 14px;

    background: #f2fbf6;

    border:
        1px solid #ccefdc;

    border-radius: 13px;

    color: #168652;

    font-size: 13px;

    line-height: 1.6;
}


/* =========================================================
   BUTTONS
========================================================= */

.buttons {

    display: flex;

    justify-content: center;

    gap: 12px;

    flex-wrap: wrap;

    margin-top: 25px;
}


.btn {

    display: inline-block;

    text-decoration: none;

    padding:
        13px 20px;

    border-radius: 12px;

    font-size: 13px;

    font-weight: 800;
}


.btn-primary {

    background: #e83e8c;

    color: #ffffff;
}


.btn-primary:hover {

    background: #d72f76;
}


.btn-secondary {

    background: #ffffff;

    color: #e83e8c;

    border:
        1px solid #e83e8c;
}


.btn-secondary:hover {

    background: #fff0f6;
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


    .success-card {

        padding: 20px;
    }


    .detail {

        flex-direction: column;

        gap: 5px;
    }


    .detail strong {

        text-align: left;
    }


    .route {

        flex-direction: column;

        align-items: flex-start;
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


<section class="success-card">


    <div class="success-header">


        <div class="success-icon">
            ✓
        </div>


        <h1>
            Payment Successful!
        </h1>


        <p class="subtitle">

            Your payment was successful and
            your booking has been confirmed.

        </p>


    </div>



    <!-- =====================================================
         BOOKING REFERENCE
    ====================================================== -->

    <div class="reference-box">


        <div class="reference-label">
            Booking Reference
        </div>


        <div class="reference-code">

            <?= htmlspecialchars(
                $booking_code,
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>


    </div>



    <!-- =====================================================
         BOOKING DETAILS
    ====================================================== -->

    <section class="details-card">


        <h2>
            🎫 Booking Details
        </h2>



        <!-- ROUTE -->

        <div class="route">


            <div>

                <div class="city">

                    <?= htmlspecialchars(
                        $source,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

                <small>
                    From
                </small>

            </div>


            <div class="route-arrow">
                →
            </div>


            <div>

                <div class="city">

                    <?= htmlspecialchars(
                        $destination,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

                <small>
                    To
                </small>

            </div>


        </div>



        <div class="detail">

            <span>
                Booking ID
            </span>

            <strong>
                #<?= (int)$booking_id ?>
            </strong>

        </div>



        <div class="detail">

            <span>
                Booking Code
            </span>

            <strong>

                <?= htmlspecialchars(
                    $booking_code,
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
                Journey Time
            </span>

            <strong>

                <?= htmlspecialchars(
                    $display_departure,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

                -

                <?= htmlspecialchars(
                    $display_arrival,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

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



        <!-- =================================================
             TOTAL SEATS
        ================================================== -->

        <div class="detail">

            <span>
                Total Seats
            </span>

            <strong>
                <?= (int)$total_seats ?>
            </strong>

        </div>



        <!-- =================================================
             EXACT SEAT NUMBERS
        ================================================== -->

        <div class="detail">

            <span>
                Seat Numbers
            </span>

            <strong>

                <?= htmlspecialchars(
                    $seat_numbers_text,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </strong>

        </div>



        <!-- =================================================
             SEAT BADGES
        ================================================== -->

        <div class="seats">

            <?php foreach (
                $seat_numbers
                as $seat_number
            ): ?>

                <div class="seat">

                    Seat
                    <?= (int)$seat_number ?>

                </div>

            <?php endforeach; ?>

        </div>



        <!-- =================================================
             ORIGINAL AMOUNT
        ================================================== -->

        <div class="detail">

            <span>
                Original Amount
            </span>

            <strong>

                ₹<?= number_format(
                    $original_amount,
                    2
                ) ?>

            </strong>

        </div>



        <!-- =================================================
             DISCOUNT
        ================================================== -->

        <div class="detail">

            <span>
                Discount Amount
            </span>

            <strong>

                -₹<?= number_format(
                    $discount_amount,
                    2
                ) ?>

            </strong>

        </div>



        <!-- =================================================
             FINAL AMOUNT
        ================================================== -->

        <div class="detail">

            <span>
                Final Amount
            </span>

            <strong class="final-amount">

                ₹<?= number_format(
                    $final_amount,
                    2
                ) ?>

            </strong>

        </div>



        <!-- =================================================
             PAYMENT METHOD
        ================================================== -->

        <div class="detail">

            <span>
                Payment Method
            </span>

            <strong>

                <?= htmlspecialchars(
                    $payment_method,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </strong>

        </div>



        <!-- =================================================
             BOOKING STATUS
        ================================================== -->

        <div class="detail">

            <span>
                Booking Status
            </span>

            <strong>
                Confirmed
            </strong>

        </div>


    </section>



    <!-- =====================================================
         SUCCESS MESSAGE
    ====================================================== -->

    <div class="payment-message">

        ✓ Payment successful.

        Your booking has been saved successfully.

        Booking reference:

        <strong>

            <?= htmlspecialchars(
                $booking_code,
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </strong>

    </div>



    <!-- =====================================================
         BUTTONS
    ====================================================== -->

    <div class="buttons">


        <a
            href="my_bookings.php"
            class="btn btn-primary"
        >
            🎫 My Bookings
        </a>


        <a
            href="my_bookings.php?booking_id=<?= (int)$booking_id ?>"
            class="btn btn-secondary"
        >
        </a>


        <a
            href="dashboard.php"
            class="btn btn-secondary"
        >
            🏠 Back to Home
        </a>


    </div>


</section>


</main>


</body>

</html>