<?php
session_start();

require_once "db.php";

/* =========================================================
   ERROR REPORTING - DEVELOPMENT ONLY
========================================================= */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];


/* =========================================================
   GET DATA FROM BOOKING_DETAILS.PHP
========================================================= */

$schedule_id = isset($_POST['schedule_id'])
    ? (int)$_POST['schedule_id']
    : 0;

$journey_date = isset($_POST['journey_date'])
    ? trim($_POST['journey_date'])
    : '';

$selected_seats = $_POST['seats'] ?? [];

$offer_id = isset($_POST['offer_id'])
    ? (int)$_POST['offer_id']
    : 0;


/* =========================================================
   BASIC VALIDATION
========================================================= */

if ($schedule_id <= 0) {
    die("Payment information missing: Schedule ID is missing.");
}

if ($journey_date === '') {
    die("Payment information missing: Journey date is missing.");
}

if (!is_array($selected_seats)) {
    die("Payment information missing: Seat information is invalid.");
}


/* =========================================================
   VALIDATE JOURNEY DATE
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
   CLEAN SELECTED SEATS
========================================================= */

$seat_ids = [];

foreach ($selected_seats as $seat) {

    $seat = (int)$seat;

    if ($seat > 0) {
        $seat_ids[] = $seat;
    }
}

$seat_ids = array_values(
    array_unique($seat_ids)
);


if (count($seat_ids) === 0) {
    die("Payment information missing: No seats selected.");
}

$seat_count = count($seat_ids);


/* =========================================================
   GET SCHEDULE + BUS + ROUTE
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

        r.source,
        r.destination

    FROM schedules s

    LEFT JOIN buses b
        ON s.bus_id = b.bus_id

    LEFT JOIN routes r
        ON s.route_id = r.route_id

    WHERE s.schedule_id = ?

    LIMIT 1
";


$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Schedule query error: " . $conn->error);
}

$stmt->bind_param(
    "i",
    $schedule_id
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $stmt->close();

    die("Bus schedule not found.");
}

$bus = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   BUS / ROUTE VALUES
========================================================= */

$bus_name = $bus['bus_name'] ?? 'Pink Ride Bus';

$bus_number = $bus['bus_number'] ?? 'N/A';

$bus_type = $bus['bus_type'] ?? 'Bus';

$source = $bus['source'] ?? '';

$destination = $bus['destination'] ?? '';

$departure_time = $bus['departure_time'] ?? '';

$arrival_time = $bus['arrival_time'] ?? '';

$schedule_type = $bus['schedule_type'] ?? 'Daily';

$fare = (float)($bus['fare'] ?? 0);


/* =========================================================
   FARE CALCULATION
========================================================= */

$original_amount =
    $fare * $seat_count;

$discount_amount = 0;

$final_amount =
    $original_amount;

$offer_name =
    'No Festival Offer';

$festival_name =
    '';

$offer_applied =
    false;


/* =========================================================
   GET ACTIVE OFFER FOR JOURNEY DATE
========================================================= */

$offer_sql = "
    SELECT
        offer_ID,
        offer_name,
        festival_name,
        discount_type,
        discount_value,
        start_date,
        end_date,
        min_booking_amount,
        max_discount

    FROM offers

    WHERE status = 'active'

    AND start_date <= ?

    AND end_date >= ?

    ORDER BY offer_ID DESC

    LIMIT 1
";


$offer_stmt =
    $conn->prepare($offer_sql);

if ($offer_stmt) {

    $offer_stmt->bind_param(
        "ss",
        $journey_date,
        $journey_date
    );

    $offer_stmt->execute();

    $offer_result =
        $offer_stmt->get_result();

    if ($offer_result->num_rows > 0) {

        $offer =
            $offer_result->fetch_assoc();


        $offer_name =
            $offer['offer_name']
            ?? 'Festival Offer';


        $festival_name =
            $offer['festival_name']
            ?? '';


        $discount_type =
            strtolower(
                trim(
                    $offer['discount_type']
                    ?? ''
                )
            );


        $discount_value =
            (float)(
                $offer['discount_value']
                ?? 0
            );


        $min_booking_amount =
            (float)(
                $offer['min_booking_amount']
                ?? 0
            );


        $max_discount =
            (float)(
                $offer['max_discount']
                ?? 0
            );


        /* =================================================
           MINIMUM BOOKING AMOUNT
        ================================================= */

        if (
            $original_amount >=
            $min_booking_amount
        ) {


            /* =============================================
               PERCENTAGE
            ============================================= */

            if (
                $discount_type === 'percentage' ||
                $discount_type === 'percent' ||
                $discount_type === '%'
            ) {

                $discount_amount =
                    (
                        $original_amount *
                        $discount_value
                    ) / 100;
            }


            /* =============================================
               FIXED
            ============================================= */

            elseif (
                $discount_type === 'fixed' ||
                $discount_type === 'amount'
            ) {

                $discount_amount =
                    $discount_value;
            }


            /* =============================================
               MAX DISCOUNT
            ============================================= */

            if (
                $max_discount > 0 &&
                $discount_amount > $max_discount
            ) {

                $discount_amount =
                    $max_discount;
            }


            /* =============================================
               DISCOUNT CANNOT EXCEED ORIGINAL
            ============================================= */

            if (
                $discount_amount >
                $original_amount
            ) {

                $discount_amount =
                    $original_amount;
            }


            /* =============================================
               FINAL AMOUNT
            ============================================= */

            $final_amount =
                $original_amount -
                $discount_amount;


            if ($final_amount < 0) {
                $final_amount = 0;
            }


            if ($discount_amount > 0) {
                $offer_applied = true;
            }
        }
    }

    $offer_stmt->close();
}


/* =========================================================
   FORMAT DATE
========================================================= */

$display_date =
    date(
        'd M Y',
        strtotime($journey_date)
    );


/* =========================================================
   FORMAT TIME
========================================================= */

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


/* =========================================================
   HTML
========================================================= */

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Payment | Pink Ride</title>


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


/* NAVBAR */

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


/* CONTAINER */

.container {
    max-width: 1100px;
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


/* GRID */

.grid {
    display: grid;
    grid-template-columns: 1.15fr .85fr;
    gap: 20px;
}


/* CARD */

.card {
    background: #ffffff;
    border: 1px solid #f0dce5;
    border-radius: 20px;
    padding: 22px;

    box-shadow:
        0 10px 30px
        rgba(232,62,140,.06);

    margin-bottom: 20px;
}

.card h2 {
    margin: 0 0 18px;
    font-size: 19px;
}


/* ROUTE */

.route {
    background: #fff8fb;
    border: 1px solid #f3dce7;
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


/* DETAILS */

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


/* SEATS */

.seats {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.seat {
    background: #fff0f6;
    color: #d42f73;

    border: 1px solid #ffd1e1;

    padding: 8px 12px;

    border-radius: 9px;

    font-size: 12px;
    font-weight: 800;
}


/* OFFER */

.offer {
    background: #fff4f8;
    border: 1px solid #ffd3e3;

    border-radius: 14px;

    padding: 14px;

    margin: 15px 0;
}

.offer-title {
    color: #d42f73;
    font-weight: 800;
    font-size: 14px;
}

.offer-text {
    color: #777;
    font-size: 12px;
    line-height: 1.6;
    margin-top: 5px;
}


/* PRICE */

.price {
    display: flex;
    justify-content: space-between;

    padding: 11px 0;

    font-size: 14px;
}

.discount {
    color: #168652;
    font-weight: 800;
}

.total {
    border-top:
        1px dashed #dfccd5;

    margin-top: 8px;

    padding-top: 17px;

    font-weight: 800;
}

.total strong {
    color: #e83e8c;
    font-size: 25px;
}


/* PAYMENT OPTIONS */

.payment-option {
    display: block;
    position: relative;
    margin-bottom: 12px;
}

.payment-option input {
    position: absolute;
    opacity: 0;
}

.payment-box {
    display: flex;
    align-items: center;
    gap: 13px;

    padding: 15px;

    border: 1px solid #eadbe2;

    border-radius: 14px;

    cursor: pointer;

    transition: .2s;
}

.payment-option input:checked
+ .payment-box {
    border-color: #e83e8c;

    background: #fff0f6;

    box-shadow:
        0 0 0 2px
        rgba(232,62,140,.08);
}

.payment-icon {
    width: 43px;
    height: 43px;

    border-radius: 11px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: #ffffff;

    font-size: 21px;
}

.payment-title {
    font-size: 14px;
    font-weight: 800;
}

.payment-description {
    color: #888;
    font-size: 11px;
    margin-top: 3px;
}


/* BUTTON */

.pay-button {
    width: 100%;

    border: 0;

    border-radius: 13px;

    padding: 15px;

    background: #e83e8c;

    color: white;

    font-size: 14px;

    font-weight: 800;

    cursor: pointer;

    margin-top: 15px;
}

.pay-button:hover {
    background: #d72f76;
}


/* SECURITY */

.security {
    margin-top: 13px;

    padding: 12px;

    border-radius: 10px;

    background: #fafafa;

    color: #777;

    font-size: 11px;

    line-height: 1.6;
}


/* MOBILE */

@media(max-width: 800px) {

    .navbar {
        flex-direction: column;
        gap: 10px;
        padding: 13px;
    }

    .grid {
        grid-template-columns: 1fr;
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


<a
    href="javascript:history.back()"
    class="back"
>
    ← Back
</a>


<h1>
    Payment
</h1>


<p class="subtitle">
    Review your complete booking details and choose a payment method.
</p>


<div class="grid">


<!-- =====================================================
     LEFT SIDE
===================================================== -->

<div>


<section class="card">

<h2>
    🚌 Booking Information
</h2>


<div class="route">

    <div>

        <div class="city">
            <?= htmlspecialchars($source) ?>
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
            <?= htmlspecialchars($destination) ?>
        </div>

        <small>
            To
        </small>

    </div>

</div>


<div class="detail">

    <span>
        Journey Date
    </span>

    <strong>
        <?= htmlspecialchars($display_date) ?>
    </strong>

</div>


<div class="detail">

    <span>
        Journey Time
    </span>

    <strong>
        <?= htmlspecialchars($display_departure) ?>

        -

        <?= htmlspecialchars($display_arrival) ?>
    </strong>

</div>


<div class="detail">

    <span>
        Bus
    </span>

    <strong>
        <?= htmlspecialchars($bus_name) ?>
    </strong>

</div>


<div class="detail">

    <span>
        Bus Number
    </span>

    <strong>
        <?= htmlspecialchars($bus_number) ?>
    </strong>

</div>


<div class="detail">

    <span>
        Bus Type
    </span>

    <strong>
        <?= htmlspecialchars($bus_type) ?>
    </strong>

</div>


<div class="detail">

    <span>
        Schedule Type
    </span>

    <strong>
        <?= htmlspecialchars($schedule_type) ?>
    </strong>

</div>


<div class="detail">

    <span>
        Schedule ID
    </span>

    <strong>
        #<?= $schedule_id ?>
    </strong>

</div>


<div class="detail">

    <span>
        Total Seats
    </span>

    <strong>
        <?= $seat_count ?>
    </strong>

</div>


<div class="seats">

<?php foreach ($seat_ids as $seat): ?>

    <div class="seat">
        Seat <?= (int)$seat ?>
    </div>

<?php endforeach; ?>

</div>


</section>


<!-- =====================================================
     FARE DETAILS
===================================================== -->

<section class="card">

<h2>
    💰 Fare Details
</h2>


<div class="price">

    <span>
        Fare per Seat
    </span>

    <strong>
        ₹<?= number_format($fare, 2) ?>
    </strong>

</div>


<div class="price">

    <span>
        Number of Seats
    </span>

    <strong>
        <?= $seat_count ?>
    </strong>

</div>


<div class="price">

    <span>
        Original Fare
    </span>

    <strong>
        ₹<?= number_format(
            $original_amount,
            2
        ) ?>
    </strong>

</div>


<?php if ($offer_applied): ?>

<div class="price">

    <span>
        Offer
    </span>

    <strong>
        <?= htmlspecialchars($offer_name) ?>
    </strong>

</div>


<div class="price">

    <span>
        Discount
    </span>

    <strong class="discount">

        -₹<?= number_format(
            $discount_amount,
            2
        ) ?>

    </strong>

</div>


<div class="offer">

    <div class="offer-title">

        🎉
        <?= htmlspecialchars($offer_name) ?>

    </div>


    <div class="offer-text">

        <?= htmlspecialchars($festival_name) ?>

        offer has been applied.

    </div>

</div>


<?php else: ?>


<div class="offer">

    <div class="offer-title">
        No Festival Offer
    </div>

    <div class="offer-text">

        No active festival offer is available
        for this journey date.

    </div>

</div>


<?php endif; ?>


<div class="price total">

    <span>
        Total Payable
    </span>

    <strong>

        ₹<?= number_format(
            $final_amount,
            2
        ) ?>

    </strong>

</div>


</section>

</div>


<!-- =====================================================
     RIGHT SIDE - PAYMENT
===================================================== -->

<div>

<section class="card">

<h2>
    💳 Select Payment Method
</h2>


<form
    method="POST"
    action="payment_success.php"
>


<!-- USER -->

<input
    type="hidden"
    name="user_id"
    value="<?= $user_id ?>"
>


<!-- SCHEDULE -->

<input
    type="hidden"
    name="schedule_id"
    value="<?= $schedule_id ?>"
>


<!-- JOURNEY DATE -->

<input
    type="hidden"
    name="journey_date"
    value="<?= htmlspecialchars(
        $journey_date,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- ORIGINAL AMOUNT -->

<input
    type="hidden"
    name="original_amount"
    value="<?= htmlspecialchars(
        (string)$original_amount,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- DISCOUNT -->

<input
    type="hidden"
    name="discount_amount"
    value="<?= htmlspecialchars(
        (string)$discount_amount,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- FINAL AMOUNT -->

<input
    type="hidden"
    name="final_amount"
    value="<?= htmlspecialchars(
        (string)$final_amount,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- OFFER ID -->

<input
    type="hidden"
    name="offer_id"
    value="<?= $offer_applied
        ? (int)$offer_id
        : 0 ?>"
>


<!-- OFFER NAME -->

<input
    type="hidden"
    name="offer_name"
    value="<?= htmlspecialchars(
        $offer_name,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- SOURCE -->

<input
    type="hidden"
    name="source"
    value="<?= htmlspecialchars(
        $source,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- DESTINATION -->

<input
    type="hidden"
    name="destination"
    value="<?= htmlspecialchars(
        $destination,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- BUS NAME -->

<input
    type="hidden"
    name="bus_name"
    value="<?= htmlspecialchars(
        $bus_name,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- BUS NUMBER -->

<input
    type="hidden"
    name="bus_number"
    value="<?= htmlspecialchars(
        $bus_number,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- DEPARTURE -->

<input
    type="hidden"
    name="departure_time"
    value="<?= htmlspecialchars(
        $departure_time,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- ARRIVAL -->

<input
    type="hidden"
    name="arrival_time"
    value="<?= htmlspecialchars(
        $arrival_time,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<!-- =====================================================
     SEATS
===================================================== -->

<?php foreach ($seat_ids as $seat): ?>

<input
    type="hidden"
    name="seats[]"
    value="<?= (int)$seat ?>"
>

<?php endforeach; ?>


<!-- =====================================================
     UPI
===================================================== -->

<label class="payment-option">

    <input
        type="radio"
        name="payment_method"
        value="UPI"
        required
    >

    <div class="payment-box">

        <div class="payment-icon">
            📱
        </div>

        <div>

            <div class="payment-title">
                UPI
            </div>

            <div class="payment-description">
                Pay using UPI
            </div>

        </div>

    </div>

</label>


<!-- =====================================================
     DEBIT CARD
===================================================== -->

<label class="payment-option">

    <input
        type="radio"
        name="payment_method"
        value="Debit Card"
    >

    <div class="payment-box">

        <div class="payment-icon">
            💳
        </div>

        <div>

            <div class="payment-title">
                Debit Card
            </div>

            <div class="payment-description">
                Pay using your debit card
            </div>

        </div>

    </div>

</label>


<!-- =====================================================
     CREDIT CARD
===================================================== -->

<label class="payment-option">

    <input
        type="radio"
        name="payment_method"
        value="Credit Card"
    >

    <div class="payment-box">

        <div class="payment-icon">
            💳
        </div>

        <div>

            <div class="payment-title">
                Credit Card
            </div>

            <div class="payment-description">
                Pay using your credit card
            </div>

        </div>

    </div>

</label>


<!-- =====================================================
     CASH
===================================================== -->

<label class="payment-option">

    <input
        type="radio"
        name="payment_method"
        value="Cash"
    >

    <div class="payment-box">

        <div class="payment-icon">
            💵
        </div>

        <div>

            <div class="payment-title">
                Cash
            </div>

            <div class="payment-description">
                Pay with cash
            </div>

        </div>

    </div>

</label>


<!-- =====================================================
     CONTINUE
===================================================== -->

<button
    type="submit"
    class="pay-button"
>

    Continue to Payment →

</button>


<div class="security">

    🔒 Your selected journey date, seats,
    bus details and fare are carried forward
    to the payment success page.

</div>


</form>

</section>

</div>


</div>

</main>

</body>

</html>