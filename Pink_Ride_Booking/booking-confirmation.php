<?php

session_start();

if (
    !isset($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true
) {
    header("Location: login.php");
    exit;
}

require_once "database.php";

$userId = $_SESSION["user_id"] ?? 0;


/* =========================================================
   GET POST DATA FROM BOOKING.PHP
========================================================= */

$scheduleId = isset($_POST["schedule_id"])
    ? (int) $_POST["schedule_id"]
    : 0;

$journeyDate = isset($_POST["journey_date"])
    ? trim($_POST["journey_date"])
    : "";

$baseFare = isset($_POST["base_fare"])
    ? (float) $_POST["base_fare"]
    : 0;

$discount = isset($_POST["discount"])
    ? (float) $_POST["discount"]
    : 0;

$finalFare = isset($_POST["final_fare"])
    ? (float) $_POST["final_fare"]
    : 0;

$offerName = isset($_POST["offer_name"])
    ? trim($_POST["offer_name"])
    : "";


/* =========================================================
   VALIDATE
========================================================= */

if (
    $scheduleId <= 0 ||
    $journeyDate === "" ||
    $finalFare <= 0
) {
    header("Location: dashboard.php");
    exit;
}


/* =========================================================
   GET SCHEDULE + BUS + ROUTE
========================================================= */

$sql = "
    SELECT

        s.id AS schedule_id,
        s.bus_id,
        s.route_id,

        s.departure_time,
        s.arrival_time,
        s.boarding_time,
        s.base_fare,
        s.status AS schedule_status,

        r.route_name,
        r.source_city,
        r.source_address,
        r.destination_city,
        r.destination_address,

        b.bus_number,
        b.operator_name,
        b.bus_type,
        b.total_seats,
        b.registration_number,
        b.amenities,
        b.status AS bus_status

    FROM schedules s

    INNER JOIN routes r
        ON r.id = s.route_id

    INNER JOIN buses b
        ON b.id = s.bus_id

    WHERE s.id = ?

    LIMIT 1
";


$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database error while loading booking details.");
}

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $scheduleId
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$bus = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);


/* =========================================================
   CHECK BUS
========================================================= */

if (!$bus) {

    die("
        <div style='
            font-family:Arial;
            text-align:center;
            padding:60px;
        '>

            <h2>
                Booking Details Not Found
            </h2>

            <p>
                The selected bus is no longer available.
            </p>

            <a href='dashboard.php'>
                Back to Dashboard
            </a>

        </div>
    ");

}


/* =========================================================
   USER DETAILS
========================================================= */

$userName = $_SESSION["user_name"] ?? "";

$userEmail = $_SESSION["user_email"] ?? "";


/* =========================================================
   BOOKING REFERENCE
========================================================= */

$bookingReference =
    "PR" .
    date("Ymd") .
    strtoupper(
        substr(
            md5(
                $userId .
                $scheduleId .
                $journeyDate .
                microtime(true)
            ),
            0,
            6
        )
    );

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
        Booking Confirmation | PinkRide
    </title>

    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            background: #fff8fb;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

        }


        .confirmation-page {

            width: min(1050px, 92%);

            margin: auto;

            padding: 45px 0 70px;

        }


        /* =====================================================
           HEADER
        ===================================================== */

        .confirmation-header {

            margin-bottom: 28px;

        }


        .confirmation-header span {

            color: #e91e63;

            font-size: 11px;

            font-weight: 900;

            letter-spacing: 1.7px;

        }


        .confirmation-header h1 {

            margin: 7px 0;

            font-size: 32px;

            color: #222;

        }


        .confirmation-header p {

            margin: 0;

            color: #777;

        }


        /* =====================================================
           SUCCESS TOP
        ===================================================== */

        .confirmation-status {

            background:
                linear-gradient(
                    135deg,
                    #e91e63,
                    #ff5b92
                );

            color: white;

            border-radius: 26px;

            padding: 30px;

            display: flex;

            align-items: center;

            gap: 20px;

            margin-bottom: 24px;

            box-shadow:
                0 18px 40px
                rgba(233,30,99,.17);

        }


        .status-icon {

            width: 68px;

            height: 68px;

            min-width: 68px;

            border-radius: 20px;

            background:
                rgba(255,255,255,.18);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 34px;

        }


        .confirmation-status h2 {

            margin: 0 0 6px;

            font-size: 24px;

        }


        .confirmation-status p {

            margin: 0;

            opacity: .9;

            font-size: 14px;

        }


        /* =====================================================
           CARD
        ===================================================== */

        .confirmation-card {

            background: white;

            border: 1px solid #f0dce7;

            border-radius: 24px;

            padding: 28px;

            margin-bottom: 20px;

            box-shadow:
                0 10px 28px
                rgba(0,0,0,.045);

        }


        .card-title {

            margin: 0 0 20px;

            font-size: 20px;

            color: #222;

        }


        /* =====================================================
           REFERENCE
        ===================================================== */

        .reference-box {

            background: #fff1f6;

            border: 1px solid #f3d2df;

            border-radius: 15px;

            padding: 17px 20px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            margin-bottom: 22px;

        }


        .reference-box span {

            color: #888;

            font-size: 11px;

            font-weight: 800;

            letter-spacing: 1px;

        }


        .reference-box strong {

            color: #e91e63;

            font-size: 18px;

            letter-spacing: 1px;

        }


        /* =====================================================
           BUS
        ===================================================== */

        .bus-info {

            display: flex;

            align-items: center;

            gap: 16px;

            padding-bottom: 22px;

            border-bottom: 1px solid #eee;

        }


        .bus-icon {

            width: 62px;

            height: 62px;

            min-width: 62px;

            border-radius: 17px;

            background: #ffe5ef;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 31px;

        }


        .bus-info h3 {

            margin: 0 0 5px;

            font-size: 20px;

        }


        .bus-info p {

            margin: 0;

            color: #777;

            font-size: 13px;

        }


        /* =====================================================
           JOURNEY
        ===================================================== */

        .journey {

            display: grid;

            grid-template-columns:
                1fr
                auto
                1fr;

            align-items: center;

            text-align: center;

            gap: 20px;

            padding: 28px 0;

        }


        .city {

            font-size: 20px;

            font-weight: 900;

            color: #222;

        }


        .time {

            color: #e91e63;

            font-weight: 800;

            margin-top: 6px;

        }


        .journey-date {

            color: #777;

            font-size: 13px;

            margin-top: 6px;

        }


        .arrow {

            font-size: 26px;

            color: #e91e63;

        }


        /* =====================================================
           DETAILS
        ===================================================== */

        .details-grid {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 14px;

        }


        .detail {

            background: #fff8fb;

            padding: 16px;

            border-radius: 14px;

        }


        .detail span {

            display: block;

            color: #999;

            font-size: 10px;

            font-weight: 900;

            margin-bottom: 7px;

        }


        .detail strong {

            color: #333;

            font-size: 14px;

        }


        /* =====================================================
           USER
        ===================================================== */

        .user-grid {

            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 15px;

        }


        .user-detail {

            background: #fff8fb;

            padding: 17px;

            border-radius: 14px;

        }


        .user-detail span {

            display: block;

            color: #999;

            font-size: 10px;

            font-weight: 900;

            margin-bottom: 7px;

        }


        .user-detail strong {

            color: #333;

            font-size: 14px;

            word-break: break-word;

        }


        /* =====================================================
           FARE
        ===================================================== */

        .fare-row {

            display: flex;

            justify-content: space-between;

            align-items: center;

            padding: 11px 0;

        }


        .fare-row span {

            color: #777;

        }


        .fare-row strong {

            color: #333;

        }


        .discount-row strong {

            color: #198754;

        }


        .total-row {

            border-top: 1px solid #eadfe4;

            margin-top: 10px;

            padding-top: 18px;

        }


        .total-row span {

            color: #222;

            font-weight: 900;

        }


        .total-row strong {

            color: #e91e63;

            font-size: 28px;

        }


        .offer-message {

            margin-top: 15px;

            padding: 14px;

            border-radius: 13px;

            background: #fff1f6;

            color: #e91e63;

            font-size: 13px;

            font-weight: 800;

        }


        /* =====================================================
           ACTIONS
        ===================================================== */

        .actions {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            margin-top: 25px;

        }


        .back-button {

            text-decoration: none;

            color: #555;

            background: #f4edf1;

            padding: 14px 20px;

            border-radius: 12px;

            font-weight: 800;

        }


        .payment-button {

            border: 0;

            cursor: pointer;

            color: white;

            background: #e91e63;

            padding: 15px 28px;

            border-radius: 12px;

            font-size: 15px;

            font-weight: 900;

        }


        .payment-button:hover {

            background: #d81757;

        }


        /* =====================================================
           MOBILE
        ===================================================== */

        @media(max-width:700px) {

            .confirmation-page {

                width: 94%;

                padding-top: 25px;

            }


            .confirmation-status {

                align-items: flex-start;

                padding: 24px;

            }


            .journey {

                grid-template-columns: 1fr;

            }


            .arrow {

                transform: rotate(90deg);

            }


            .details-grid {

                grid-template-columns: 1fr;

            }


            .user-grid {

                grid-template-columns: 1fr;

            }


            .reference-box {

                align-items: flex-start;

                flex-direction: column;

            }


            .actions {

                flex-direction: column;

            }


            .back-button,
            .payment-button {

                width: 100%;

                text-align: center;

            }

        }

    </style>

</head>


<body>


<div class="confirmation-page">


    <!-- =====================================================
         PAGE HEADER
    ===================================================== -->

    <div class="confirmation-header">

        <span>
            PINKRIDE
        </span>

        <h1>
            Booking Confirmation
        </h1>

        <p>
            Please review your booking details before payment.
        </p>

    </div>


    <!-- =====================================================
         STATUS
    ===================================================== -->

    <div class="confirmation-status">

        <div class="status-icon">
            ✓
        </div>

        <div>

            <h2>
                Your booking is ready
            </h2>

            <p>
                Review the details below and continue to payment.
            </p>

        </div>

    </div>


    <!-- =====================================================
         REFERENCE
    ===================================================== -->

    <div class="confirmation-card">

        <div class="reference-box">

            <span>
                BOOKING REFERENCE
            </span>

            <strong>
                <?= htmlspecialchars(
                    $bookingReference
                ) ?>
            </strong>

        </div>


        <!-- =================================================
             BUS INFORMATION
        ================================================= -->

        <div class="bus-info">

            <div class="bus-icon">
                🚌
            </div>

            <div>

                <h3>

                    <?= htmlspecialchars(
                        $bus["operator_name"]
                    ) ?>

                </h3>

                <p>

                    Bus No:
                    <?= htmlspecialchars(
                        $bus["bus_number"]
                    ) ?>

                    &nbsp; • &nbsp;

                    <?= htmlspecialchars(
                        $bus["bus_type"]
                    ) ?>

                </p>

            </div>

        </div>


        <!-- =================================================
             JOURNEY
        ================================================= -->

        <div class="journey">


            <div>

                <div class="city">

                    <?= htmlspecialchars(
                        $bus["source_city"]
                    ) ?>

                </div>

                <div class="time">

                    <?= date(
                        "h:i A",
                        strtotime(
                            $bus["departure_time"]
                        )
                    ) ?>

                </div>

                <div class="journey-date">

                    Departure

                </div>

            </div>


            <div class="arrow">
                →
            </div>


            <div>

                <div class="city">

                    <?= htmlspecialchars(
                        $bus["destination_city"]
                    ) ?>

                </div>

                <div class="time">

                    <?php if (
                        !empty(
                            $bus["arrival_time"]
                        )
                    ): ?>

                        <?= date(
                            "h:i A",
                            strtotime(
                                $bus["arrival_time"]
                            )
                        ) ?>

                    <?php else: ?>

                        --

                    <?php endif; ?>

                </div>

                <div class="journey-date">

                    Arrival

                </div>

            </div>


        </div>


        <!-- =================================================
             JOURNEY DETAILS
        ================================================= -->

        <div class="details-grid">


            <div class="detail">

                <span>
                    JOURNEY DATE
                </span>

                <strong>

                    <?= date(
                        "d M Y",
                        strtotime($journeyDate)
                    ) ?>

                </strong>

            </div>


            <div class="detail">

                <span>
                    BOARDING TIME
                </span>

                <strong>

                    <?php if (
                        !empty(
                            $bus["boarding_time"]
                        )
                    ): ?>

                        <?= date(
                            "h:i A",
                            strtotime(
                                $bus["boarding_time"]
                            )
                        ) ?>

                    <?php else: ?>

                        Not specified

                    <?php endif; ?>

                </strong>

            </div>


            <div class="detail">

                <span>
                    TOTAL SEATS
                </span>

                <strong>

                    <?= (int)
                        $bus["total_seats"] ?>

                    seats

                </strong>

            </div>


        </div>

    </div>


    <!-- =====================================================
         PASSENGER
    ===================================================== -->

    <div class="confirmation-card">

        <h2 class="card-title">
            Passenger Details
        </h2>


        <div class="user-grid">


            <div class="user-detail">

                <span>
                    NAME
                </span>

                <strong>

                    <?= htmlspecialchars(
                        $userName
                    ) ?>

                </strong>

            </div>


            <div class="user-detail">

                <span>
                    EMAIL
                </span>

                <strong>

                    <?= htmlspecialchars(
                        $userEmail
                    ) ?>

                </strong>

            </div>


        </div>

    </div>


    <!-- =====================================================
         FARE
    ===================================================== -->

    <div class="confirmation-card">

        <h2 class="card-title">
            Payment Summary
        </h2>


        <div class="fare-row">

            <span>
                Base Fare
            </span>

            <strong>

                ₹<?= number_format(
                    $baseFare,
                    2
                ) ?>

            </strong>

        </div>


        <?php if ($discount > 0): ?>

            <div class="fare-row discount-row">

                <span>

                    <?= htmlspecialchars(
                        $offerName
                    ) ?>

                </span>

                <strong>

                    - ₹<?= number_format(
                        $discount,
                        2
                    ) ?>

                </strong>

            </div>


            <div class="offer-message">

                🎉

                Festival offer applied!

                You saved

                ₹<?= number_format(
                    $discount,
                    2
                ) ?>.

            </div>

        <?php endif; ?>


        <div class="fare-row total-row">

            <span>
                Amount To Pay
            </span>

            <strong>

                ₹<?= number_format(
                    $finalFare,
                    2
                ) ?>

            </strong>

        </div>


        <!-- =================================================
             PAYMENT FORM
        ================================================= -->

        <form
            action="payment.php"
            method="POST"
        >

            <input
                type="hidden"
                name="booking_reference"
                value="<?= htmlspecialchars(
                    $bookingReference
                ) ?>"
            >

            <input
                type="hidden"
                name="schedule_id"
                value="<?= (int)
                    $scheduleId ?>"
            >

            <input
                type="hidden"
                name="journey_date"
                value="<?= htmlspecialchars(
                    $journeyDate
                ) ?>"
            >

            <input
                type="hidden"
                name="base_fare"
                value="<?= htmlspecialchars(
                    $baseFare
                ) ?>"
            >

            <input
                type="hidden"
                name="discount"
                value="<?= htmlspecialchars(
                    $discount
                ) ?>"
            >

            <input
                type="hidden"
                name="final_fare"
                value="<?= htmlspecialchars(
                    $finalFare
                ) ?>"
            >

            <input
                type="hidden"
                name="offer_name"
                value="<?= htmlspecialchars(
                    $offerName
                ) ?>"
            >


            <div class="actions">

                <a
                    href="booking.php?schedule_id=<?= (int) $scheduleId ?>&journey_date=<?= urlencode($journeyDate) ?>"
                    class="back-button"
                >
                    ← Back to Booking
                </a>


                <button
                    type="submit"
                    class="payment-button"
                >
                    Continue to Payment →
                </button>

            </div>

        </form>

    </div>


</div>


</body>

</html>