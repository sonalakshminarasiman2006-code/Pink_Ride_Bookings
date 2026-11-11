<?php
session_start();

require_once "db.php";

/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}


/* =========================================================
   GET DATA FROM SELECT SEAT PAGE
========================================================= */

$schedule_id = isset($_POST['schedule_id'])
    ? (int)$_POST['schedule_id']
    : 0;

$journey_date = isset($_POST['journey_date'])
    ? trim($_POST['journey_date'])
    : '';

$selected_seats = $_POST['seats'] ?? [];


/* =========================================================
   VALIDATION
========================================================= */

if ($schedule_id <= 0 || empty($journey_date)) {
    header("Location: search_bus.php");
    exit();
}


/* =========================================================
   CLEAN SEAT IDS
========================================================= */

$seat_ids = [];

if (is_array($selected_seats)) {

    foreach ($selected_seats as $seat_id) {

        $seat_id = (int)$seat_id;

        if ($seat_id > 0) {
            $seat_ids[] = $seat_id;
        }
    }
}

$seat_ids = array_values(array_unique($seat_ids));


if (count($seat_ids) == 0) {
    header("Location: search_bus.php");
    exit();
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
   GET SCHEDULE + BUS + ROUTE DETAILS
=========================================================

   IMPORTANT:
   If your table names are different, only change
   the table names here.
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

        r.source,
        r.destination

    FROM schedules s

    INNER JOIN buses b
        ON s.bus_id = b.bus_id

    INNER JOIN routes r
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

if ($result->num_rows == 0) {
    die("Bus schedule not found.");
}

$bus = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   BUS VALUES
========================================================= */

$bus_name = $bus['bus_name'] ?? 'Pink Ride Bus';

$bus_number = $bus['bus_number'] ?? 'N/A';

$bus_type = $bus['bus_type'] ?? 'Bus';

$total_bus_seats = (int)($bus['total_seats'] ?? 50);

$source = $bus['source'] ?? '';

$destination = $bus['destination'] ?? '';

$departure_time = $bus['departure_time'] ?? '';

$arrival_time = $bus['arrival_time'] ?? '';

$base_fare = (float)($bus['fare'] ?? 0);


/* =========================================================
   CHECK SELECTED SEATS
   FOR THIS EXACT SCHEDULE + DATE
=========================================================

   IMPORTANT:

   Seat blocking is DATE based.

   Same bus can run on:

   22 Aug
   23 Aug
   24 Aug
   25 Aug

   A seat booked on 22 Aug does NOT block
   that seat on 23 Aug.
========================================================= */


/*
   booking_seats table assumed:

   booking_id
   seat_id
   seat_status

   bookings table assumed:

   booking_id
   schedule_id
   journey_date
   booking_status
*/

$placeholders = implode(
    ',',
    array_fill(0, count($seat_ids), '?')
);


$sql = "
    SELECT bs.seat_id

    FROM booking_seats bs

    INNER JOIN bookings bk
        ON bs.booking_id = bk.booking_id

    WHERE bk.schedule_id = ?

    AND bk.journey_date = ?

    AND bk.booking_status IN ('confirmed', 'paid')

    AND bs.seat_status = 'booked'

    AND bs.seat_id IN ($placeholders)
";


$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Seat checking error: " . $conn->error);
}


/*
   Bind parameters dynamically
*/

$types = "is" . str_repeat("i", count($seat_ids));

$params = [];

$params[] = $schedule_id;
$params[] = $journey_date;

foreach ($seat_ids as $seat) {
    $params[] = $seat;
}

$bind = [];

$bind[] = $types;

foreach ($params as $key => $value) {
    $bind[] = &$params[$key];
}

call_user_func_array(
    [$stmt, 'bind_param'],
    $bind
);

$stmt->execute();

$seat_result = $stmt->get_result();

$already_booked = [];

while ($row = $seat_result->fetch_assoc()) {

    $already_booked[] = (int)$row['seat_id'];

}

$stmt->close();


/* =========================================================
   IF SEAT ALREADY BOOKED
========================================================= */

if (count($already_booked) > 0) {

    $blocked = implode(
        ', ',
        $already_booked
    );

    die(
        "Seat(s) $blocked are already booked for " .
        date('d M Y', strtotime($journey_date)) .
        ". Please go back and select another seat."
    );
}


/* =========================================================
   SEAT COUNT
========================================================= */

$seat_count = count($seat_ids);


/* =========================================================
   ORIGINAL TOTAL
========================================================= */

$original_total =
    $base_fare * $seat_count;


/* =========================================================
   OFFER
=========================================================

   YOUR OFFERS TABLE:

   offer_ID
   offer_name
   festival_name
   discount_type
   discount_value
   start_date
   end_date
   min_booking_amount
   max_discount
   status
   created_at

========================================================= */

$offer = null;

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
        max_discount,
        status

    FROM offers

    WHERE status = 'active'

    AND start_date <= ?

    AND end_date >= ?

    ORDER BY offer_ID DESC

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

    $offer_result =
        $offer_stmt->get_result();

    if ($offer_result->num_rows > 0) {

        $offer =
            $offer_result->fetch_assoc();
    }

    $offer_stmt->close();
}


/* =========================================================
   OFFER CALCULATION
========================================================= */

$discount_amount = 0;

$final_total = $original_total;

$offer_applied = false;


if ($offer) {

    $discount_type =
        strtolower(
            trim(
                $offer['discount_type'] ?? ''
            )
        );

    $discount_value =
        (float)(
            $offer['discount_value'] ?? 0
        );

    $min_booking_amount =
        (float)(
            $offer['min_booking_amount'] ?? 0
        );

    $max_discount =
        (float)(
            $offer['max_discount'] ?? 0
        );


    /*
       Minimum booking amount check
    */

    if (
        $original_total >=
        $min_booking_amount
    ) {


        /* =============================================
           PERCENTAGE DISCOUNT
        ============================================= */

        if (
            $discount_type === 'percentage' ||
            $discount_type === 'percent' ||
            $discount_type === '%'
        ) {

            $discount_amount =
                ($original_total * $discount_value)
                / 100;
        }


        /* =============================================
           FIXED DISCOUNT
        ============================================= */

        elseif (
            $discount_type === 'fixed' ||
            $discount_type === 'amount'
        ) {

            $discount_amount =
                $discount_value;
        }


        /*
           Maximum discount
        */

        if (
            $max_discount > 0 &&
            $discount_amount > $max_discount
        ) {

            $discount_amount =
                $max_discount;
        }


        /*
           Final price
        */

        $final_total =
            $original_total -
            $discount_amount;


        if ($final_total < 0) {
            $final_total = 0;
        }


        if ($discount_amount > 0) {
            $offer_applied = true;
        }
    }
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
    Booking Details | Pink Ride
</title>


<style>

/* =====================================================
   RESET
===================================================== */

* {
    box-sizing: border-box;
}


body {

    margin: 0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background:
        #fff7fb;

    color:
        #262626;
}


/* =====================================================
   NAVBAR
===================================================== */

.navbar {

    min-height: 70px;

    background: #ffffff;

    border-bottom:
        1px solid #f3dce7;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding:
        0 28px;

    position:
        sticky;

    top: 0;

    z-index: 100;
}


.logo {

    font-size: 21px;

    font-weight: 800;

    color: #e83e8c;
}


.nav {

    display: flex;

    align-items: center;

    gap: 4px;

    flex-wrap: wrap;
}


.nav a {

    text-decoration: none;

    color: #555;

    font-size: 13px;

    padding:
        9px 10px;

    border-radius: 9px;
}


.nav a:hover {

    color: #e83e8c;

    background:
        #fff0f6;
}


.logout {

    color:
        #d42d70 !important;
}


/* =====================================================
   PAGE
===================================================== */

.container {

    max-width:
        1100px;

    margin:
        auto;

    padding:
        28px 18px 60px;
}


/* =====================================================
   BACK BUTTON
===================================================== */

.back {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    text-decoration: none;

    color:
        #e83e8c;

    font-weight: 700;

    font-size: 14px;

    margin-bottom:
        20px;
}


/* =====================================================
   HEADING
===================================================== */

.heading {

    margin-bottom:
        22px;
}


.heading h1 {

    margin: 0;

    font-size:
        29px;
}


.heading p {

    margin:
        7px 0 0;

    color:
        #777;

    font-size:
        14px;
}


/* =====================================================
   GRID
===================================================== */

.main-grid {

    display: grid;

    grid-template-columns:
        1.3fr .8fr;

    gap:
        20px;

    align-items:
        start;
}


/* =====================================================
   CARD
===================================================== */

.card {

    background:
        #ffffff;

    border:
        1px solid #f0dce5;

    border-radius:
        20px;

    padding:
        22px;

    box-shadow:
        0 10px 30px
        rgba(232, 62, 140, .06);

    margin-bottom:
        20px;
}


.card:last-child {

    margin-bottom: 0;
}


.card h2 {

    margin:
        0 0 18px;

    font-size:
        19px;
}


/* =====================================================
   BUS HEADER
===================================================== */

.bus-header {

    display:
        flex;

    align-items:
        center;

    gap:
        14px;

    padding-bottom:
        18px;

    border-bottom:
        1px solid #f4e6ec;

    margin-bottom:
        10px;
}


.bus-icon {

    width:
        54px;

    height:
        54px;

    border-radius:
        15px;

    background:
        #fff0f6;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    font-size:
        26px;
}


.bus-name {

    margin:
        0 0 5px;

    font-size:
        18px;
}


.bus-number {

    margin: 0;

    color:
        #777;

    font-size:
        12px;
}


/* =====================================================
   DETAILS
===================================================== */

.detail {

    display:
        flex;

    justify-content:
        space-between;

    gap:
        15px;

    padding:
        12px 0;

    border-bottom:
        1px solid #f5e9ee;

    font-size:
        13px;
}


.detail:last-child {

    border-bottom:
        0;
}


.detail span:first-child {

    color:
        #888;
}


.detail strong {

    text-align:
        right;
}


/* =====================================================
   ROUTE
===================================================== */

.route-box {

    background:
        #fff8fb;

    border:
        1px solid #f4dce7;

    border-radius:
        14px;

    padding:
        16px;

    margin-bottom:
        12px;
}


.route {

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    gap:
        15px;
}


.city {

    font-size:
        17px;

    font-weight:
        800;
}


.arrow {

    color:
        #e83e8c;

    font-size:
        21px;

    font-weight:
        900;
}


/* =====================================================
   SEATS
===================================================== */

.seats {

    display:
        flex;

    flex-wrap:
        wrap;

    gap:
        8px;
}


.seat {

    min-width:
        55px;

    text-align:
        center;

    padding:
        8px 10px;

    background:
        #fff0f6;

    border:
        1px solid #ffd0e1;

    border-radius:
        9px;

    color:
        #d42f73;

    font-size:
        12px;

    font-weight:
        800;
}


/* =====================================================
   OFFER
===================================================== */

.offer {

    margin-top:
        18px;

    padding:
        15px;

    border-radius:
        15px;

    border:
        1px solid #ffd1e2;

    background:
        linear-gradient(
            135deg,
            #fff0f6,
            #fffafd
        );
}


.offer-name {

    font-size:
        15px;

    font-weight:
        800;

    color:
        #d62f75;

    margin-bottom:
        6px;
}


.offer-festival {

    font-size:
        12px;

    color:
        #777;

    line-height:
        1.6;
}


/* =====================================================
   PRICE
===================================================== */

.price {

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        center;

    padding:
        11px 0;

    font-size:
        14px;

    color:
        #666;
}


.old-price {

    text-decoration:
        line-through;

    color:
        #999;
}


.discount {

    color:
        #168652;

    font-weight:
        800;
}


.total {

    border-top:
        1px dashed #e6d4dd;

    margin-top:
        8px;

    padding-top:
        17px;

    font-size:
        16px;

    font-weight:
        800;

    color:
        #222;
}


.total-amount {

    color:
        #e83e8c;

    font-size:
        25px;

    font-weight:
        900;
}


/* =====================================================
   BUTTON
===================================================== */

.button {

    display:
        block;

    width:
        100%;

    border:
        0;

    border-radius:
        12px;

    padding:
        15px;

    background:
        #e83e8c;

    color:
        #ffffff;

    font-size:
        14px;

    font-weight:
        800;

    cursor:
        pointer;

    margin-top:
        18px;
}


.button:hover {

    background:
        #d72f76;
}


/* =====================================================
   NOTE
===================================================== */

.note {

    margin-top:
        13px;

    padding:
        12px;

    border-radius:
        11px;

    background:
        #fafafa;

    color:
        #777;

    font-size:
        11px;

    line-height:
        1.6;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media(max-width: 800px) {

    .navbar {

        flex-direction:
            column;

        gap:
            10px;

        padding:
            13px;
    }


    .nav {

        justify-content:
            center;
    }


    .main-grid {

        grid-template-columns:
            1fr;
    }


    .route {

        flex-direction:
            column;

        align-items:
            flex-start;
    }


    .arrow {

        transform:
            rotate(90deg);
    }
}

</style>

</head>


<body>


<!-- =====================================================
     NAVIGATION
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

        <a
            href="logout.php"
            class="logout"
        >
            ↪ Logout
        </a>

    </nav>

</header>



<!-- =====================================================
     CONTENT
===================================================== -->

<main class="container">


    <a
        href="javascript:history.back()"
        class="back"
    >
        ← Back
    </a>



    <div class="heading">

        <h1>
            Booking Details
        </h1>

        <p>
            Check your complete journey details before continuing to payment.
        </p>

    </div>



    <div class="main-grid">


        <!-- =================================================
             LEFT SIDE
        ================================================= -->

        <div>


            <!-- BUS DETAILS -->

            <section class="card">

                <h2>
                    🚌 Bus Details
                </h2>


                <div class="bus-header">

                    <div class="bus-icon">
                        🚌
                    </div>


                    <div>

                        <h3 class="bus-name">

                            <?= htmlspecialchars(
                                $bus_name
                            ) ?>

                        </h3>


                        <p class="bus-number">

                            Bus No:
                            <?= htmlspecialchars(
                                $bus_number
                            ) ?>

                            &nbsp; • &nbsp;

                            <?= htmlspecialchars(
                                $bus_type
                            ) ?>

                        </p>

                    </div>

                </div>



                <!-- ROUTE -->

                <div class="route-box">

                    <div class="route">

                        <div>

                            <div class="city">

                                <?= htmlspecialchars(
                                    $source
                                ) ?>

                            </div>

                            <small>
                                From
                            </small>

                        </div>


                        <div class="arrow">
                            →
                        </div>


                        <div>

                            <div class="city">

                                <?= htmlspecialchars(
                                    $destination
                                ) ?>

                            </div>

                            <small>
                                To
                            </small>

                        </div>

                    </div>

                </div>



                <!-- DATE -->

                <div class="detail">

                    <span>
                        Journey Date
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $display_date
                        ) ?>

                    </strong>

                </div>



                <!-- DEPARTURE -->

                <div class="detail">

                    <span>
                        Departure Time
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $display_departure
                        ) ?>

                    </strong>

                </div>



                <!-- ARRIVAL -->

                <div class="detail">

                    <span>
                        Arrival Time
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $display_arrival
                        ) ?>

                    </strong>

                </div>



                <!-- SCHEDULE -->

                <div class="detail">

                    <span>
                        Schedule Type
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $bus['schedule_type'] ?? 'Daily'
                        ) ?>

                    </strong>

                </div>



                <!-- SCHEDULE ID -->

                <div class="detail">

                    <span>
                        Schedule ID
                    </span>

                    <strong>

                        #<?= (int)$schedule_id ?>

                    </strong>

                </div>

            </section>



            <!-- SELECTED SEATS -->

            <section class="card">

                <h2>
                    💺 Selected Seats
                </h2>


                <div class="detail">

                    <span>
                        Number of Seats
                    </span>

                    <strong>
                        <?= $seat_count ?>
                    </strong>

                </div>


                <div class="seats">

                    <?php foreach (
                        $seat_ids as $seat
                    ): ?>

                        <div class="seat">

                            Seat <?= (int)$seat ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            </section>


        </div>



        <!-- =================================================
             RIGHT SIDE
        ================================================= -->

        <div>


            <section class="card">

                <h2>
                    💰 Fare Summary
                </h2>


                <!-- BASE FARE -->

                <div class="price">

                    <span>
                        Fare / Seat
                    </span>

                    <strong>

                        ₹<?= number_format(
                            $base_fare,
                            2
                        ) ?>

                    </strong>

                </div>



                <!-- SEAT COUNT -->

                <div class="price">

                    <span>
                        Seats
                    </span>

                    <strong>
                        <?= $seat_count ?>
                    </strong>

                </div>



                <!-- ORIGINAL -->

                <div class="price">

                    <span>
                        Original Total
                    </span>

                    <strong class="old-price">

                        ₹<?= number_format(
                            $original_total,
                            2
                        ) ?>

                    </strong>

                </div>



                <!-- OFFER -->

                <?php if ($offer_applied): ?>

                    <div class="price">

                        <span>
                            Festival Discount
                        </span>

                        <strong class="discount">

                            -₹<?= number_format(
                                $discount_amount,
                                2
                            ) ?>

                        </strong>

                    </div>


                    <div class="offer">

                        <div class="offer-name">

                            🎉
                            <?= htmlspecialchars(
                                $offer['offer_name']
                            ) ?>

                        </div>


                        <div class="offer-festival">

                            <?= htmlspecialchars(
                                $offer['festival_name']
                            ) ?>

                            offer applied for journey date

                            <strong>

                                <?= htmlspecialchars(
                                    $display_date
                                ) ?>

                            </strong>

                            <br><br>

                            You save

                            <strong>

                                ₹<?= number_format(
                                    $discount_amount,
                                    2
                                ) ?>

                            </strong>

                            on this booking.

                        </div>

                    </div>

                <?php else: ?>

                    <div class="offer">

                        <div class="offer-name">

                            No Festival Offer

                        </div>


                        <div class="offer-festival">

                            No active offer is available
                            for

                            <strong>
                                <?= htmlspecialchars(
                                    $display_date
                                ) ?>
                            </strong>.

                            Original fare is applied.

                        </div>

                    </div>

                <?php endif; ?>



                <!-- FINAL TOTAL -->

                <div class="price total">

                    <span>
                        Total Payable
                    </span>

                    <strong class="total-amount">

                        ₹<?= number_format(
                            $final_total,
                            2
                        ) ?>

                    </strong>

                </div>



                <!-- PAYMENT FORM -->

                <form
                    method="POST"
                    action="payment.php"
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
                            $journey_date
                        ) ?>"
                    >


                    <?php foreach (
                        $seat_ids as $seat
                    ): ?>

                        <input
                            type="hidden"
                            name="seats[]"
                            value="<?= (int)$seat ?>"
                        >

                    <?php endforeach; ?>


                    <input
                        type="hidden"
                        name="offer_id"
                        value="<?= $offer_applied
                            ? (int)$offer['offer_ID']
                            : 0 ?>"
                    >


                    <button
                        type="submit"
                        class="button"
                    >

                        Continue to Payment →

                    </button>

                </form>



                <div class="note">

                    🔒 Your selected seats are checked
                    again for this exact journey date.

                    The festival offer is applied only
                    when the selected journey date falls
                    between the offer start date and end date.

                </div>

            </section>


        </div>


    </div>


</main>

</body>

</html>