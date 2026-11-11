<?php
session_start();
require_once "db.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================================================
   LOGIN
========================================================= */
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

/* =========================================================
   INPUT
========================================================= */
$schedule_id = (int)($_GET["schedule_id"] ?? 0);
$journey_date = trim($_GET["journey_date"] ?? "");

if ($schedule_id <= 0 || $journey_date === "") {
    header("Location: search_bus.php");
    exit();
}

/* =========================================================
   DATE VALIDATION
========================================================= */
$date_check = DateTime::createFromFormat("Y-m-d", $journey_date);

if (
    !$date_check ||
    $date_check->format("Y-m-d") !== $journey_date
) {
    die("Invalid journey date.");
}

/* =========================================================
   GET BUS / SCHEDULE / ROUTE
========================================================= */
$sql = "
    SELECT
        s.schedule_id,
        s.bus_id,
        s.route_id,
        s.departure_time,
        s.arrival_time,
        s.fare,
        s.schedule_type,

        b.bus_name,
        b.bus_number,
        b.bus_type,
        b.total_seats,
        b.operator_name,

        r.source,
        r.destination,
        r.boarding_point,
        r.dropping_point

    FROM schedules s

    INNER JOIN buses b
        ON s.bus_id = b.bus_id

    INNER JOIN routes r
        ON s.route_id = r.route_id

    WHERE s.schedule_id = ?
      AND s.status = 'scheduled'
      AND b.status = 'active'

    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $schedule_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    die("Bus schedule not found.");
}

$bus = $result->fetch_assoc();
$stmt->close();

$bus_id = (int)$bus["bus_id"];

/* =========================================================
   TOTAL SEATS
========================================================= */
$total_seats = (int)$bus["total_seats"];

if ($total_seats <= 0 || $total_seats > 100) {
    $total_seats = 50;
}

/* =========================================================
   ORIGINAL PRICE
========================================================= */
$original_price = (float)$bus["fare"];

/* =========================================================
   OFFER
========================================================= */
$offer = null;

$offer_sql = "
    SELECT
        offer_id,
        offer_name,
        festival_name,
        discount_type,
        discount_value,
        start_date,
        end_date,
        min_booking_amount,
        max_discount,
        status
    FROM offers
    WHERE status = 'active'
      AND start_date <= ?
      AND end_date >= ?
    ORDER BY discount_value DESC
    LIMIT 1
";

$offer_stmt = $conn->prepare($offer_sql);

if ($offer_stmt) {
    $offer_stmt->bind_param(
        "ss",
        $journey_date,
        $journey_date
    );

    $offer_stmt->execute();
    $offer_result = $offer_stmt->get_result();

    if ($offer_result->num_rows > 0) {
        $offer = $offer_result->fetch_assoc();
    }

    $offer_stmt->close();
}

/* =========================================================
   PRICE CALCULATION
========================================================= */
$discount_per_seat = 0;
$offer_price_per_seat = $original_price;
$offer_applied = false;

if ($offer !== null) {

    $discount_type = strtolower(
        trim((string)$offer["discount_type"])
    );

    $discount_value = (float)$offer["discount_value"];
    $max_discount = (float)($offer["max_discount"] ?? 0);

    if (
        $discount_type === "percentage" ||
        $discount_type === "percent" ||
        $discount_type === "%"
    ) {

        $discount_per_seat =
            ($original_price * $discount_value) / 100;

        if (
            $max_discount > 0 &&
            $discount_per_seat > $max_discount
        ) {
            $discount_per_seat = $max_discount;
        }

    } elseif (
        $discount_type === "fixed" ||
        $discount_type === "amount"
    ) {

        $discount_per_seat = $discount_value;
    }

    if ($discount_per_seat > $original_price) {
        $discount_per_seat = $original_price;
    }

    if ($discount_per_seat > 0) {

        $offer_price_per_seat =
            $original_price - $discount_per_seat;

        if ($offer_price_per_seat < 0) {
            $offer_price_per_seat = 0;
        }

        $offer_applied = true;
    }
}

/* =========================================================
   EXACT DATE BOOKED SEATS
========================================================= */

/*
   IMPORTANT:

   We intentionally use booking_seats.seat_id as the seat
   number only if your booking_seats table stores the actual
   seat number there.

   Your previous error showed that the value coming from the
   seat-selection page did not match the seats.seat_id values.

   Therefore this page sends the actual seat number as
   seats[].

   The booked-seat query below reads seat_number from
   booking_seats when available.

   If booking_seats does not contain seat_number, it falls
   back to bookings.seat_numbers.
*/

$booked_seats = [];

/* ---------------------------------------------------------
   METHOD 1:
   booking_seats.seat_number
--------------------------------------------------------- */
try {

    $booked_sql = "
        SELECT DISTINCT
            bs.seat_number
        FROM booking_seats bs

        INNER JOIN bookings bk
            ON bs.booking_id = bk.booking_id

        WHERE bk.schedule_id = ?
          AND bk.journey_date = ?

          AND LOWER(
              REPLACE(
                  TRIM(bk.booking_status),
                  ' ',
                  ''
              )
          ) IN (
              'confirmed',
              'paid'
          )

          AND LOWER(
              REPLACE(
                  TRIM(bs.seat_status),
                  ' ',
                  ''
              )
          ) = 'booked'
    ";

    $booked_stmt = $conn->prepare($booked_sql);

    $booked_stmt->bind_param(
        "is",
        $schedule_id,
        $journey_date
    );

    $booked_stmt->execute();

    $booked_result = $booked_stmt->get_result();

    while ($row = $booked_result->fetch_assoc()) {

        if (
            isset($row["seat_number"]) &&
            trim((string)$row["seat_number"]) !== ""
        ) {

            $seat_number =
                (int)$row["seat_number"];

            if ($seat_number > 0) {
                $booked_seats[] = $seat_number;
            }
        }
    }

    $booked_stmt->close();

} catch (Throwable $e) {

    /*
       If booking_seats does not have seat_number,
       we use the bookings.seat_numbers fallback below.
    */
}

/* ---------------------------------------------------------
   METHOD 2:
   bookings.seat_numbers fallback
--------------------------------------------------------- */
if (count($booked_seats) === 0) {

    try {

        $booking_sql = "
            SELECT
                bk.seat_numbers
            FROM bookings bk
            WHERE bk.schedule_id = ?
              AND bk.journey_date = ?

              AND LOWER(
                  REPLACE(
                      TRIM(bk.booking_status),
                      ' ',
                      ''
                  )
              ) IN (
                  'confirmed',
                  'paid'
              )
        ";

        $booking_stmt =
            $conn->prepare($booking_sql);

        $booking_stmt->bind_param(
            "is",
            $schedule_id,
            $journey_date
        );

        $booking_stmt->execute();

        $booking_result =
            $booking_stmt->get_result();

        while (
            $booking_row =
            $booking_result->fetch_assoc()
        ) {

            $seat_text =
                (string)($booking_row["seat_numbers"] ?? "");

            if ($seat_text === "") {
                continue;
            }

            /*
               Handles:

               1
               1, 2, 3
               Seat 1, Seat 2
               1,2,3
            */

            preg_match_all(
                '/\d+/',
                $seat_text,
                $matches
            );

            foreach (
                ($matches[0] ?? []) as $number
            ) {

                $seat_number =
                    (int)$number;

                if (
                    $seat_number > 0 &&
                    $seat_number <= $total_seats
                ) {
                    $booked_seats[] =
                        $seat_number;
                }
            }
        }

        $booking_stmt->close();

    } catch (Throwable $e) {
        /*
           No fallback available.
           The page will continue with no seats blocked.
        */
    }
}

/* =========================================================
   UNIQUE BOOKED SEATS
========================================================= */
$booked_seats = array_values(
    array_unique(
        array_map(
            "intval",
            $booked_seats
        )
    )
);

sort($booked_seats);

$booked_count = count($booked_seats);

$available_count =
    $total_seats - $booked_count;

if ($available_count < 0) {
    $available_count = 0;
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

<title>Select Seats | Pink Ride</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #fff8fb;
    color: #242424;
}

/* =========================================================
   NAVBAR
========================================================= */

.navbar {
    background: #ffffff;
    border-bottom: 1px solid #f3d9e5;
    min-height: 70px;
    padding: 0 30px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    position: sticky;
    top: 0;
    z-index: 100;
}

.brand {
    color: #e83e8c;
    font-size: 21px;
    font-weight: 800;
    white-space: nowrap;
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: center;
}

.nav-links a {
    text-decoration: none;
    color: #555;
    font-size: 13px;
    padding: 9px 11px;
    border-radius: 9px;
}

.nav-links a:hover {
    background: #fff0f6;
    color: #e83e8c;
}

.logout {
    color: #d63272 !important;
}

/* =========================================================
   PAGE
========================================================= */

.container {
    max-width: 1120px;
    margin: auto;
    padding: 28px 18px 70px;
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    text-decoration: none;
    color: #e83e8c;

    font-weight: 700;
    font-size: 14px;

    margin-bottom: 22px;
}

.page-title {
    margin-bottom: 22px;
}

.page-title h1 {
    margin: 0;
    font-size: 30px;
}

.page-title p {
    margin: 7px 0 0;
    color: #777;
    font-size: 14px;
}

/* =========================================================
   BUS CARD
========================================================= */

.bus-card {
    background: #ffffff;
    border: 1px solid #f0dce6;
    border-radius: 20px;
    padding: 20px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;
    margin-bottom: 18px;

    box-shadow:
        0 8px 25px
        rgba(232, 62, 140, .06);
}

.bus-left {
    display: flex;
    align-items: center;
    gap: 14px;
}

.bus-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;

    background: #fff0f6;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 27px;
}

.bus-left h2 {
    margin: 0 0 5px;
    font-size: 19px;
}

.bus-left p {
    margin: 0;
    color: #777;
    font-size: 13px;
}

.date-card {
    background: #fff5f9;
    border: 1px solid #ffd8e8;
    border-radius: 13px;
    padding: 10px 15px;
    text-align: right;
}

.date-card small {
    display: block;
    color: #888;
    font-size: 11px;
    margin-bottom: 3px;
}

.date-card strong {
    color: #d92f75;
    font-size: 15px;
}

/* =========================================================
   ROUTE
========================================================= */

.route-card {
    background: #ffffff;
    border: 1px solid #f0dce6;
    border-radius: 18px;
    padding: 18px 22px;

    display: grid;
    grid-template-columns: 1fr auto 1fr;

    align-items: center;

    margin-bottom: 18px;
}

.route-place strong {
    display: block;
    font-size: 20px;
}

.route-place span {
    color: #777;
    font-size: 13px;
}

.route-place.right {
    text-align: right;
}

.route-line {
    color: #e83e8c;
    padding: 0 35px;
    font-size: 20px;
}

/* =========================================================
   SEAT CARD
========================================================= */

.seat-card {
    background: #ffffff;
    border: 1px solid #f0dce6;
    border-radius: 24px;
    padding: 25px;

    box-shadow:
        0 10px 30px
        rgba(232, 62, 140, .07);
}

.seat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;
    margin-bottom: 20px;
}

.seat-top h2 {
    margin: 0;
    font-size: 21px;
}

.seat-top p {
    margin: 5px 0 0;
    color: #777;
    font-size: 13px;
}

.available-number {
    color: #138952;
    font-weight: 800;
}

/* =========================================================
   LEGEND
========================================================= */

.legend {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 7px;

    color: #666;
    font-size: 12px;
}

.legend-box {
    width: 19px;
    height: 19px;
    border-radius: 6px;
}

.available {
    background: #ffffff;
    border: 2px solid #e83e8c;
}

.selected {
    background: #e83e8c;
    border: 2px solid #e83e8c;
}

.booked {
    background: #bdbdbd;
    border: 2px solid #bdbdbd;
}

/* =========================================================
   BUS
========================================================= */

.bus {
    max-width: 430px;
    margin: auto;

    background: #fff8fb;

    border: 1px solid #f2dce6;
    border-radius: 24px;

    padding: 24px 17px 28px;
}

.driver-area {
    display: flex;
    justify-content: flex-end;
    padding: 0 8px 20px;
}

.driver {
    width: 55px;
    height: 55px;
    border-radius: 14px;

    background: #ffffff;

    border: 1px solid #ead9e2;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 21px;
}

/* =========================================================
   SEAT ROW
========================================================= */

.seat-row {
    display: grid;
    grid-template-columns:
        1fr 1fr 25px 1fr 1fr;

    gap: 8px;
    margin-bottom: 10px;
}

.seat input {
    display: none;
}

.seat label {
    height: 46px;

    border-radius: 10px;

    background: #ffffff;
    border: 2px solid #e83e8c;

    color: #d72f75;

    display: flex;
    align-items: center;
    justify-content: center;

    cursor: pointer;

    font-size: 12px;
    font-weight: 800;

    transition: .15s;
}

.seat label:hover {
    background: #fff0f6;
    transform: translateY(-1px);
}

.seat input:checked + label {
    background: #e83e8c;
    color: #ffffff;

    box-shadow:
        0 5px 15px
        rgba(232, 62, 140, .25);
}

/* =========================================================
   BOOKED / GREY SEAT
========================================================= */

.seat.booked-seat label {
    background: #bdbdbd;
    border-color: #bdbdbd;
    color: #ffffff;

    cursor: not-allowed;

    opacity: 1;
}

.seat.booked-seat label:hover {
    background: #bdbdbd;
    border-color: #bdbdbd;
    transform: none;
}

/* =========================================================
   PRICE
========================================================= */

.price-panel {
    margin-top: 25px;

    border: 1px solid #f0dce6;
    border-radius: 18px;

    overflow: hidden;

    background: #ffffff;
}

.price-header {
    background:
        linear-gradient(
            135deg,
            #fff0f6,
            #fff9fc
        );

    padding: 16px 18px;

    border-bottom:
        1px solid #f3dce6;
}

.price-header strong {
    color: #d52f73;
    font-size: 15px;
}

.price-body {
    padding: 18px;
}

.price-row {
    display: flex;
    justify-content: space-between;
    align-items: center;

    padding: 7px 0;

    color: #666;
    font-size: 14px;
}

.price-row.total {
    margin-top: 10px;
    padding-top: 15px;

    border-top: 1px dashed #ead5df;

    color: #222;
    font-size: 17px;
    font-weight: 800;
}

.original {
    text-decoration: line-through;
    color: #999;
}

.discount {
    color: #15905a;
    font-weight: 700;
}

.final-price {
    color: #e83e8c;
    font-size: 24px;
    font-weight: 900;
}

/* =========================================================
   OFFER
========================================================= */

.offer-box {
    margin-top: 18px;

    background:
        linear-gradient(
            135deg,
            #fff0f6,
            #fffafd
        );

    border: 1px solid #ffd2e3;
    border-radius: 15px;

    padding: 15px 17px;
}

.offer-title {
    color: #d72f75;
    font-weight: 800;
    font-size: 14px;
    margin-bottom: 5px;
}

.offer-text {
    color: #666;
    font-size: 12px;
    line-height: 1.6;
}

/* =========================================================
   NOTICE
========================================================= */

.notice {
    margin-top: 15px;

    padding: 13px 15px;

    background: #fafafa;

    border-radius: 12px;

    color: #777;

    font-size: 12px;
    line-height: 1.6;
}

/* =========================================================
   CONTINUE
========================================================= */

.bottom-area {
    margin-top: 20px;

    display: flex;
    justify-content: flex-end;
}

.continue-btn {
    border: 0;

    background: #e83e8c;
    color: #ffffff;

    padding: 14px 27px;

    border-radius: 12px;

    font-size: 14px;
    font-weight: 800;

    cursor: pointer;
}

.continue-btn:hover {
    background: #d52f75;
}

.continue-btn:disabled {
    background: #cfcfcf;
    cursor: not-allowed;
}

/* =========================================================
   MOBILE
========================================================= */

@media(max-width:700px) {

    .navbar {
        padding: 13px 15px;
        flex-direction: column;
        gap: 12px;
    }

    .bus-card {
        flex-direction: column;
        align-items: flex-start;
    }

    .date-card {
        text-align: left;
    }

    .route-card {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .route-place.right {
        text-align: left;
    }

    .route-line {
        padding: 0;
    }

    .seat-card {
        padding: 17px;
    }

    .seat-top {
        align-items: flex-start;
        flex-direction: column;
    }

    .bottom-area {
        justify-content: stretch;
    }

    .continue-btn {
        width: 100%;
    }
}

</style>

</head>

<body>

<!-- =========================================================
     NAVIGATION
========================================================= -->

<header class="navbar">

    <div class="brand">
        🚌 Pink Ride
    </div>

    <nav class="nav-links">

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
            🔔 Notifications
        </a>

        <a href="profile.php">
            👤 Profile
        </a>

        <a
            href="logout.php"
            class="logout"
        >
            ↪ Logout
        </a>

    </nav>

</header>

<!-- =========================================================
     PAGE
========================================================= -->

<main class="container">

    <a
        href="javascript:history.back()"
        class="back-btn"
    >
        ← Back
    </a>

    <div class="page-title">

        <h1>
            Select Your Seats
        </h1>

        <p>
            Choose your seats and review your final fare.
        </p>

    </div>

    <!-- =====================================================
         BUS
    ===================================================== -->

    <section class="bus-card">

        <div class="bus-left">

            <div class="bus-icon">
                🚌
            </div>

            <div>

                <h2>
                    <?= htmlspecialchars(
                        $bus["bus_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </h2>

                <p>

                    <?= htmlspecialchars(
                        $bus["bus_number"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                    &nbsp; • &nbsp;

                    <?= htmlspecialchars(
                        $bus["bus_type"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </p>

            </div>

        </div>

        <div class="date-card">

            <small>
                Journey Date
            </small>

            <strong>

                <?= htmlspecialchars(
                    date(
                        "d M Y",
                        strtotime($journey_date)
                    ),
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </strong>

        </div>

    </section>

    <!-- =====================================================
         ROUTE
    ===================================================== -->

    <section class="route-card">

        <div class="route-place">

            <strong>

                <?= htmlspecialchars(
                    date(
                        "h:i A",
                        strtotime(
                            $bus["departure_time"]
                        )
                    ),
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </strong>

            <span>

                <?= htmlspecialchars(
                    $bus["source"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </span>

        </div>

        <div class="route-line">
            ─────→
        </div>

        <div class="route-place right">

            <strong>

                <?= htmlspecialchars(
                    date(
                        "h:i A",
                        strtotime(
                            $bus["arrival_time"]
                        )
                    ),
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </strong>

            <span>

                <?= htmlspecialchars(
                    $bus["destination"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </span>

        </div>

    </section>

    <!-- =====================================================
         SEATS
    ===================================================== -->

    <section class="seat-card">

        <div class="seat-top">

            <div>

                <h2>
                    Choose Your Seats
                </h2>

                <p>

                    <span class="available-number">
                        <?= $available_count ?>
                    </span>

                    seats available out of
                    <?= $total_seats ?>

                </p>

            </div>

        </div>

        <!-- LEGEND -->

        <div class="legend">

            <div class="legend-item">

                <span class="legend-box available"></span>

                Available

            </div>

            <div class="legend-item">

                <span class="legend-box selected"></span>

                Selected

            </div>

            <div class="legend-item">

                <span class="legend-box booked"></span>

                Booked

            </div>

        </div>

        <!-- =================================================
             FORM
        ================================================= -->

        <form
            method="POST"
            action="booking_details.php"
            id="seatForm"
        >

            <input
                type="hidden"
                name="schedule_id"
                value="<?= $schedule_id ?>"
            >

            <input
                type="hidden"
                name="journey_date"
                value="<?= htmlspecialchars(
                    $journey_date,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
            >

            <!-- =================================================
                 BUS SEAT MAP

                 IMPORTANT:
                 seats[] contains the ACTUAL SEAT NUMBER.

                 Example:
                 Seat 41 -> value="41"

                 booking_details.php should therefore receive:
                 seats[]=41
            ================================================= -->

            <div class="bus">

                <div class="driver-area">

                    <div class="driver">
                        🛞
                    </div>

                </div>

                <?php

                $seat_number = 1;

                while (
                    $seat_number <= $total_seats
                ):

                ?>

                    <div class="seat-row">

                        <?php

                        for (
                            $position = 1;
                            $position <= 4;
                            $position++
                        ):

                        ?>

                            <?php

                            if (
                                $seat_number <=
                                $total_seats
                            ):

                                $is_booked =
                                    in_array(
                                        $seat_number,
                                        $booked_seats,
                                        true
                                    );

                            ?>

                                <?php if ($is_booked): ?>

                                    <!-- =================================
                                         BOOKED SEAT
                                         GREY + DISABLED
                                    ================================== -->

                                    <div class="seat booked-seat">

                                        <input
                                            type="checkbox"
                                            disabled
                                        >

                                        <label
                                            title="Already booked for this journey date"
                                        >
                                            <?= $seat_number ?>
                                        </label>

                                    </div>

                                <?php else: ?>

                                    <!-- =================================
                                         AVAILABLE SEAT

                                         IMPORTANT:
                                         VALUE = SEAT NUMBER
                                         NOT SEAT ID
                                    ================================== -->

                                    <div class="seat">

                                        <input
                                            type="checkbox"
                                            id="seat_<?= $seat_number ?>"
                                            name="seats[]"
                                            value="<?= $seat_number ?>"
                                        >

                                        <label
                                            for="seat_<?= $seat_number ?>"
                                        >
                                            <?= $seat_number ?>
                                        </label>

                                    </div>

                                <?php endif; ?>

                            <?php else: ?>

                                <div></div>

                            <?php endif; ?>

                            <?php
                            $seat_number++;
                            ?>

                        <?php endfor; ?>

                    </div>

                <?php endwhile; ?>

            </div>

            <!-- =================================================
                 PRICE PANEL
            ================================================= -->

            <div class="price-panel">

                <div class="price-header">

                    <?php if ($offer_applied): ?>

                        <strong>

                            🎉

                            <?= htmlspecialchars(
                                $offer["offer_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                            —

                            <?= htmlspecialchars(
                                $offer["festival_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </strong>

                    <?php else: ?>

                        <strong>
                            💳 Fare Summary
                        </strong>

                    <?php endif; ?>

                </div>

                <div class="price-body">

                    <div class="price-row">

                        <span>
                            Selected Seats
                        </span>

                        <strong id="seatCount">
                            0
                        </strong>

                    </div>

                    <div class="price-row">

                        <span>
                            Original Fare
                        </span>

                        <strong
                            class="original"
                            id="originalTotal"
                        >
                            ₹0.00
                        </strong>

                    </div>

                    <?php if ($offer_applied): ?>

                        <div class="price-row">

                            <span>
                                Festival Discount
                            </span>

                            <strong
                                class="discount"
                                id="discountTotal"
                            >
                                - ₹0.00
                            </strong>

                        </div>

                    <?php endif; ?>

                    <div class="price-row total">

                        <span>
                            Total Fare
                        </span>

                        <strong
                            class="final-price"
                            id="finalTotal"
                        >
                            ₹0.00
                        </strong>

                    </div>

                </div>

            </div>

            <!-- =================================================
                 OFFER
            ================================================= -->

            <?php if ($offer_applied): ?>

                <div class="offer-box">

                    <div class="offer-title">
                        🎁 Festival Offer Applied
                    </div>

                    <div class="offer-text">

                        Original fare per seat:

                        <span
                            style="text-decoration:line-through;"
                        >
                            ₹<?= number_format(
                                $original_price,
                                2
                            ) ?>
                        </span>

                        &nbsp; → &nbsp;

                        <strong style="color:#e83e8c;">

                            ₹<?= number_format(
                                $offer_price_per_seat,
                                2
                            ) ?>

                        </strong>

                        per seat.

                        <br>

                        The final amount will be
                        calculated based on the
                        number of seats selected.

                    </div>

                </div>

            <?php endif; ?>

            <!-- =================================================
                 NOTICE
            ================================================= -->

            <div class="notice">

                🔒 Grey seats are already booked for

                <strong>
                    <?= htmlspecialchars(
                        date(
                            "d M Y",
                            strtotime($journey_date)
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </strong>

                for this bus and schedule.

                <br>

                The same seat can be booked on another
                journey date.

            </div>

            <!-- =================================================
                 CONTINUE
            ================================================= -->

            <div class="bottom-area">

                <button
                    type="submit"
                    class="continue-btn"
                    id="continueBtn"
                    disabled
                >
                    Continue to Booking →
                </button>

            </div>

        </form>

    </section>

</main>

<script>

/* =========================================================
   PRICES
========================================================= */

const originalPrice =
    <?= json_encode($original_price) ?>;

const finalPricePerSeat =
    <?= json_encode($offer_price_per_seat) ?>;

const discountPerSeat =
    <?= json_encode($discount_per_seat) ?>;

/* =========================================================
   ELEMENTS
========================================================= */

const seats =
    document.querySelectorAll(
        'input[name="seats[]"]'
    );

const seatCount =
    document.getElementById(
        "seatCount"
    );

const originalTotal =
    document.getElementById(
        "originalTotal"
    );

const discountTotal =
    document.getElementById(
        "discountTotal"
    );

const finalTotal =
    document.getElementById(
        "finalTotal"
    );

const continueBtn =
    document.getElementById(
        "continueBtn"
    );

/* =========================================================
   UPDATE PRICE
========================================================= */

function updatePrice() {

    let count = 0;

    seats.forEach(function(seat) {

        if (seat.checked) {
            count++;
        }

    });

    const originalAmount =
        count * originalPrice;

    const discountAmount =
        count * discountPerSeat;

    const finalAmount =
        count * finalPricePerSeat;

    seatCount.textContent =
        count;

    originalTotal.textContent =
        "₹" +
        originalAmount.toFixed(2);

    if (discountTotal) {

        discountTotal.textContent =
            "- ₹" +
            discountAmount.toFixed(2);

    }

    finalTotal.textContent =
        "₹" +
        finalAmount.toFixed(2);

    continueBtn.disabled =
        count === 0;
}

/* =========================================================
   SEAT CHANGE
========================================================= */

seats.forEach(function(seat) {

    seat.addEventListener(
        "change",
        updatePrice
    );

});

updatePrice();

/* =========================================================
   FINAL FORM VALIDATION
========================================================= */

document
    .getElementById("seatForm")
    .addEventListener(
        "submit",
        function(event) {

            const selected =
                document.querySelectorAll(
                    'input[name="seats[]"]:checked'
                );

            if (
                selected.length === 0
            ) {

                event.preventDefault();

                alert(
                    "Please select at least one seat."
                );

                return;
            }

            /*
             * Prevent accidental double submission.
             */

            continueBtn.disabled = true;

            continueBtn.textContent =
                "Loading...";

        }
    );

</script>

</body>
</html>