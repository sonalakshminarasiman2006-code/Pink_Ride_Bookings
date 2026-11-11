<?php

session_start();

require_once "db.php";


/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION["user_id"])) {

    header("Location: login.php");

    exit();
}


/* =========================================================
   GET SEARCH VALUES
========================================================= */

$source = trim($_GET["source"] ?? "");

$destination = trim($_GET["destination"] ?? "");

$journey_date = $_GET["journey_date"] ?? "";


$buses = [];

$error = "";


/* =========================================================
   VALIDATE SEARCH
========================================================= */

if (
    $source === "" ||
    $destination === "" ||
    $journey_date === ""
) {

    $error = "Search details are missing.";

}


/* =========================================================
   DATE CHECK
========================================================= */

elseif ($journey_date < date("Y-m-d")) {

    $error = "Please select a valid journey date.";

}


/* =========================================================
   BUS SEARCH
========================================================= */

else {


    /*
     * DAILY schedule means:
     *
     * The same bus schedule can operate
     * on different dates.
     *
     * Actual seat availability is checked
     * separately for the selected date.
     */


    $sql = "

        SELECT

            s.schedule_id,

            s.bus_id,

            s.route_id,

            s.schedule_type,

            s.departure_time,

            s.arrival_time,

            s.fare,


            b.bus_number,

            b.bus_name,

            b.bus_type,

            b.total_seats,

            b.operator_name,


            r.source,

            r.destination,

            r.boarding_point,

            r.dropping_point,

            r.distance_km


        FROM schedules s


        INNER JOIN buses b

            ON s.bus_id = b.bus_id


        INNER JOIN routes r

            ON s.route_id = r.route_id


        WHERE

            LOWER(TRIM(r.source))
            =
            LOWER(TRIM(?))


        AND

            LOWER(TRIM(r.destination))
            =
            LOWER(TRIM(?))


        AND

            s.status = 'scheduled'


        AND

            b.status = 'active'


        AND

            UPPER(s.schedule_type) = 'DAILY'


        ORDER BY

            s.departure_time ASC

    ";


    $stmt = $conn->prepare($sql);


    if (!$stmt) {

        $error =
            "Unable to search buses.";

    }

    else {


        $stmt->bind_param(
            "ss",
            $source,
            $destination
        );


        $stmt->execute();


        $result =
            $stmt->get_result();



        /* =====================================================
           FIND OFFER FOR SELECTED DATE
        ===================================================== */

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


            WHERE

                status = 'active'


            AND

                start_date <= ?


            AND

                end_date >= ?


            ORDER BY

                discount_value DESC


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


            if (
                $offer_result->num_rows > 0
            ) {

                $offer =
                    $offer_result->fetch_assoc();

            }


            $offer_stmt->close();

        }



        /* =====================================================
           PROCESS EACH BUS
        ===================================================== */

        while (
            $bus =
            $result->fetch_assoc()
        ) {


            $schedule_id =
                (int)$bus["schedule_id"];



            /* =================================================
               BOOKED SEATS FOR SELECTED DATE
            ================================================= */

            $seat_sql = "

                SELECT

                    COUNT(
                        bs.booking_seat_id
                    ) AS booked_seats


                FROM booking_seats bs


                INNER JOIN bookings bk

                    ON bs.booking_id =
                       bk.booking_id


                WHERE

                    bk.schedule_id = ?


                AND

                    bk.journey_date = ?


                AND

                    bk.booking_status =
                    'confirmed'


                AND

                    bs.seat_status =
                    'booked'

            ";


            $seat_stmt =
                $conn->prepare($seat_sql);


            $booked_seats = 0;


            if ($seat_stmt) {


                $seat_stmt->bind_param(
                    "is",
                    $schedule_id,
                    $journey_date
                );


                $seat_stmt->execute();


                $seat_result =
                    $seat_stmt->get_result();


                if (
                    $seat_row =
                    $seat_result->fetch_assoc()
                ) {

                    $booked_seats =
                        (int)
                        $seat_row["booked_seats"];

                }


                $seat_stmt->close();

            }



            /* =================================================
               AVAILABLE SEATS
            ================================================= */

            $total_seats =
                (int)$bus["total_seats"];


            $available_seats =
                $total_seats -
                $booked_seats;


            if (
                $available_seats < 0
            ) {

                $available_seats = 0;

            }



            /* =================================================
               ORIGINAL PRICE
            ================================================= */

            $original_price =
                (float)$bus["fare"];


            $final_price =
                $original_price;


            $discount_amount = 0;


            $offer_applied = false;



            /* =================================================
               APPLY OFFER ONLY FOR OFFER DATE
            ================================================= */

            if ($offer !== null) {


                $discount_type =
                    strtolower(
                        trim(
                            $offer[
                                "discount_type"
                            ]
                        )
                    );


                $discount_value =
                    (float)
                    $offer[
                        "discount_value"
                    ];


                $min_booking_amount =
                    (float)
                    (
                        $offer[
                            "min_booking_amount"
                        ] ?? 0
                    );


                $max_discount =
                    (float)
                    (
                        $offer[
                            "max_discount"
                        ] ?? 0
                    );



                /* =============================================
                   MINIMUM BOOKING AMOUNT
                ============================================= */

                if (
                    $original_price >=
                    $min_booking_amount
                ) {


                    /* =========================================
                       PERCENTAGE DISCOUNT
                    ========================================= */

                    if (

                        $discount_type ===
                        "percentage"

                        ||

                        $discount_type ===
                        "percent"

                        ||

                        $discount_type ===
                        "%"

                    ) {


                        $discount_amount =

                            (
                                $original_price
                                *
                                $discount_value
                            )
                            / 100;



                        /* Maximum discount */

                        if (

                            $max_discount > 0

                            &&

                            $discount_amount >
                            $max_discount

                        ) {

                            $discount_amount =
                                $max_discount;

                        }

                    }



                    /* =========================================
                       FIXED DISCOUNT
                    ========================================= */

                    elseif (

                        $discount_type ===
                        "fixed"

                        ||

                        $discount_type ===
                        "amount"

                    ) {

                        $discount_amount =
                            $discount_value;

                    }



                    /* =========================================
                       FINAL PRICE
                    ========================================= */

                    $final_price =
                        $original_price -
                        $discount_amount;


                    if (
                        $final_price < 0
                    ) {

                        $final_price = 0;

                    }


                    if (
                        $discount_amount > 0
                    ) {

                        $offer_applied = true;

                    }

                }

            }



            /* =================================================
               STORE BUS DATA
            ================================================= */

            $bus["booked_seats"] =
                $booked_seats;


            $bus["available_seats"] =
                $available_seats;


            $bus["original_price"] =
                $original_price;


            $bus["final_price"] =
                $final_price;


            $bus["discount_amount"] =
                $discount_amount;


            $bus["offer_applied"] =
                $offer_applied;


            $bus["offer"] =
                $offer;


            $buses[] =
                $bus;

        }


        $stmt->close();

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
    Available Buses | Pink Ride
</title>


<link
    rel="stylesheet"
    href="style.css"
>


<style>

/* =========================================================
   PAGE
========================================================= */

body {

    background:
        #fff8fb;

}


/* =========================================================
   HEADER
========================================================= */

.dashboard-header {

    background: #ffffff;

    border-bottom:
        1px solid
        #f4dce7;

    padding:
        15px
        30px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    position: sticky;

    top: 0;

    z-index: 100;

}


.dashboard-logo {

    display: flex;

    align-items: center;

    gap: 9px;

    font-size: 22px;

    font-weight: 800;

    color: #e83e8c;

}


.dashboard-nav {

    display: flex;

    align-items: center;

    gap: 8px;

}


.dashboard-nav a {

    text-decoration: none;

    color: #555;

    padding:
        10px
        13px;

    border-radius: 10px;

    font-size: 14px;

    transition: .2s;

}


.dashboard-nav a:hover {

    background:
        #fff0f6;

    color:
        #e83e8c;

}


.dashboard-nav a.active {

    background:
        #e83e8c;

    color:
        #ffffff;

}


.logout-link {

    color:
        #d9366e !important;

}


/* =========================================================
   MAIN
========================================================= */

.search-page {

    max-width: 1200px;

    margin: auto;

    padding:
        35px
        20px
        80px;

}


/* =========================================================
   BACK BUTTON
========================================================= */

.back-arrow {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    text-decoration: none;

    color:
        #e83e8c;

    font-weight: 700;

    font-size: 14px;

    margin-bottom:
        25px;

}


.back-arrow:hover {

    color:
        #c72e70;

}


/* =========================================================
   AVAILABLE BUSES TITLE
========================================================= */

.results-header {

    margin-bottom:
        22px;

}


.results-header h1 {

    margin: 0;

    font-size: 30px;

    color:
        #222;

}


.results-header p {

    margin-top:
        7px;

    color:
        #777;

    font-size: 14px;

}


/* =========================================================
   BUS CARD
========================================================= */

.bus-card {

    background:
        #ffffff;

    border:
        1px solid
        #f1dce6;

    border-radius:
        22px;

    padding:
        25px;

    margin-bottom:
        20px;

    box-shadow:
        0 10px 30px
        rgba(
            232,
            62,
            140,
            .07
        );

    transition:
        .2s;

}


.bus-card:hover {

    transform:
        translateY(-2px);

    box-shadow:
        0 14px 35px
        rgba(
            232,
            62,
            140,
            .11
        );

}


/* =========================================================
   BUS HEADER
========================================================= */

.bus-header {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    gap: 20px;

}


.bus-name-area {

    display: flex;

    align-items:
        center;

    gap: 14px;

}


.bus-icon {

    width:
        58px;

    height:
        58px;

    border-radius:
        17px;

    background:
        #fff0f6;

    display: flex;

    justify-content:
        center;

    align-items:
        center;

    font-size:
        28px;

}


.bus-name-area h2 {

    margin:
        0
        0
        5px;

    font-size:
        20px;

}


.bus-name-area p {

    margin: 0;

    color:
        #777;

    font-size:
        13px;

}


/* =========================================================
   PRICE
========================================================= */

.price-area {

    text-align:
        right;

}


.price-label {

    display: block;

    color:
        #888;

    font-size:
        12px;

    margin-bottom:
        3px;

}


.old-price {

    color:
        #999;

    text-decoration:
        line-through;

    font-size:
        15px;

    margin-right:
        6px;

}


.final-price {

    color:
        #e83e8c;

    font-size:
        28px;

    font-weight:
        800;

}


.price-per-seat {

    display: block;

    color:
        #888;

    font-size:
        11px;

}


/* =========================================================
   OFFER
========================================================= */

.offer-box {

    margin-top:
        18px;

    padding:
        16px
        18px;

    border-radius:
        15px;

    background:
        linear-gradient(
            135deg,
            #fff0f6,
            #fffafd
        );

    border:
        1px solid
        #ffd4e5;

}


.offer-title {

    color:
        #d62e74;

    font-size:
        15px;

    font-weight:
        800;

    margin-bottom:
        6px;

}


.offer-description {

    color:
        #666;

    font-size:
        13px;

    line-height:
        1.6;

}


.offer-save {

    display:
        inline-block;

    margin-top:
        8px;

    background:
        #e83e8c;

    color:
        #ffffff;

    padding:
        5px
        11px;

    border-radius:
        20px;

    font-size:
        11px;

    font-weight:
        700;

}


/* =========================================================
   ROUTE
========================================================= */

.route-box {

    margin:
        22px
        0;

    padding:
        20px;

    background:
        #fff8fb;

    border-radius:
        16px;

    display: grid;

    grid-template-columns:
        1fr
        auto
        1fr;

    align-items:
        center;

}


.time-box strong {

    display:
        block;

    font-size:
        21px;

}


.time-box small {

    color:
        #777;

    font-size:
        13px;

}


.time-box.right {

    text-align:
        right;

}


.route-arrow {

    color:
        #e83e8c;

    font-size:
        20px;

    padding:
        0
        30px;

}


/* =========================================================
   INFO TAGS
========================================================= */

.bus-info {

    display:
        flex;

    flex-wrap:
        wrap;

    gap:
        8px;

    margin-bottom:
        20px;

}


.info-tag {

    padding:
        8px
        12px;

    border-radius:
        10px;

    background:
        #fff8fb;

    border:
        1px solid
        #f0dce5;

    color:
        #666;

    font-size:
        12px;

}


.available-tag {

    color:
        #138a54;

    background:
        #effcf6;

    border-color:
        #c9efdc;

    font-weight:
        700;

}


.sold-tag {

    color:
        #d83265;

    background:
        #fff0f3;

    border-color:
        #ffd0dc;

    font-weight:
        700;

}


/* =========================================================
   BUS FOOTER
========================================================= */

.bus-footer {

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        center;

    gap:
        20px;

}


.boarding {

    color:
        #777;

    font-size:
        13px;

    line-height:
        1.7;

}


/* =========================================================
   SELECT SEAT BUTTON
========================================================= */

.select-seat-btn {

    border:
        none;

    background:
        #e83e8c;

    color:
        #ffffff;

    padding:
        13px
        22px;

    border-radius:
        12px;

    font-size:
        14px;

    font-weight:
        700;

    cursor:
        pointer;

    transition:
        .2s;

}


.select-seat-btn:hover {

    background:
        #d92f77;

    transform:
        translateY(-1px);

}


.sold-btn {

    border:
        none;

    background:
        #eeeeee;

    color:
        #999;

    padding:
        13px
        22px;

    border-radius:
        12px;

    font-size:
        14px;

    font-weight:
        700;

}


/* =========================================================
   ERROR
========================================================= */

.error-box {

    background:
        #fff0f3;

    color:
        #c52f59;

    border:
        1px solid
        #ffd0dc;

    padding:
        18px;

    border-radius:
        15px;

    text-align:
        center;

}


/* =========================================================
   NO BUS
========================================================= */

.no-results {

    background:
        #ffffff;

    border:
        1px solid
        #f1dce6;

    border-radius:
        22px;

    padding:
        60px
        20px;

    text-align:
        center;

}


.no-results-icon {

    font-size:
        55px;

    margin-bottom:
        12px;

}


.no-results h2 {

    margin:
        0
        0
        8px;

}


.no-results p {

    color:
        #777;

}


/* =========================================================
   FOOTER
========================================================= */

.footer {

    text-align:
        center;

    padding:
        25px;

    color:
        #999;

    font-size:
        13px;

}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:850px) {

    .dashboard-header {

        flex-direction:
            column;

        gap:
            12px;

    }


    .dashboard-nav {

        flex-wrap:
            wrap;

        justify-content:
            center;

    }


    .bus-header {

        flex-direction:
            column;

        align-items:
            flex-start;

    }


    .price-area {

        text-align:
            left;

    }

}


@media(max-width:600px) {

    .search-page {

        padding:
            25px
            15px
            60px;

    }


    .route-box {

        grid-template-columns:
            1fr;

        gap:
            12px;

    }


    .time-box.right {

        text-align:
            left;

    }


    .route-arrow {

        padding:
            0;

    }


    .bus-footer {

        flex-direction:
            column;

        align-items:
            stretch;

    }


    .select-seat-btn,
    .sold-btn {

        width:
            100%;

    }

}

</style>

</head>


<body>


<!-- =========================================================
     TOP NAVIGATION
========================================================= -->

<header class="dashboard-header">


    <div class="dashboard-logo">

        🚌

        <span>
            Pink Ride
        </span>

    </div>


    <nav class="dashboard-nav">


        <a href="dashboard.php">

            🏠 Home

        </a>


        <a
            href="search_bus.php"
            class="active"
        >

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
            class="logout-link"
        >

            ↪ Logout

        </a>


    </nav>


</header>



<!-- =========================================================
     MAIN
========================================================= -->

<main class="search-page">


    <!-- BACK -->

    <a
        href="dashboard.php"
        class="back-arrow"
    >

        ← Back

    </a>



    <!-- =====================================================
         ONLY AVAILABLE BUSES TITLE
         
         NO SEARCH FORM
         NO JOURNEY DATE
         NO FROM / TO
    ===================================================== -->

    <div class="results-header">

        <h1>
            Available Buses
        </h1>

        <p>
            Choose a bus and continue to seat selection.
        </p>

    </div>



    <!-- =====================================================
         ERROR
    ===================================================== -->

    <?php if ($error !== ""): ?>


        <div class="error-box">

            <?= htmlspecialchars($error) ?>

        </div>


    <?php endif; ?>



    <!-- =====================================================
         BUS LIST
    ===================================================== -->

    <?php if (
        $error === "" &&
        count($buses) > 0
    ): ?>


        <?php foreach (
            $buses as $bus
        ): ?>


            <div class="bus-card">


                <!-- =================================================
                     BUS NAME + PRICE
                ================================================= -->

                <div class="bus-header">


                    <div class="bus-name-area">


                        <div class="bus-icon">

                            🚌

                        </div>


                        <div>


                            <h2>

                                <?= htmlspecialchars(
                                    $bus["bus_name"]
                                ) ?>

                            </h2>


                            <p>

                                <?= htmlspecialchars(
                                    $bus["bus_number"]
                                ) ?>

                                &nbsp; • &nbsp;

                                <?= htmlspecialchars(
                                    $bus["operator_name"]
                                ) ?>

                            </p>


                        </div>


                    </div>



                    <!-- PRICE -->

                    <div class="price-area">


                        <?php if (
                            $bus["offer_applied"]
                        ): ?>


                            <span class="price-label">

                                Original Fare

                            </span>


                            <span class="old-price">

                                ₹<?= number_format(
                                    $bus[
                                        "original_price"
                                    ],
                                    2
                                ) ?>

                            </span>


                            <span class="final-price">

                                ₹<?= number_format(
                                    $bus[
                                        "final_price"
                                    ],
                                    2
                                ) ?>

                            </span>


                            <span class="price-per-seat">

                                per seat

                            </span>


                        <?php else: ?>


                            <span class="price-label">

                                Fare per seat

                            </span>


                            <span class="final-price">

                                ₹<?= number_format(
                                    $bus[
                                        "original_price"
                                    ],
                                    2
                                ) ?>

                            </span>


                        <?php endif; ?>


                    </div>


                </div>



                <!-- =================================================
                     FESTIVAL OFFER
                     SHOW ONLY WHEN OFFER IS ACTIVE
                     FOR SELECTED DATE
                ================================================= -->

                <?php if (
                    $bus["offer_applied"] &&
                    $bus["offer"] !== null
                ): ?>


                    <div class="offer-box">


                        <div class="offer-title">

                            🎉

                            <?= htmlspecialchars(
                                $bus[
                                    "offer"
                                ][
                                    "offer_name"
                                ]
                            ) ?>


                            -

                            <?= htmlspecialchars(
                                $bus[
                                    "offer"
                                ][
                                    "festival_name"
                                ]
                            ) ?>

                        </div>


                        <div class="offer-description">


                            <?= htmlspecialchars(
                                $bus[
                                    "offer"
                                ][
                                    "discount_value"
                                ]
                            ) ?>


                            <?php

                            $type =
                                strtolower(
                                    trim(
                                        $bus[
                                            "offer"
                                        ][
                                            "discount_type"
                                        ]
                                    )
                                );


                            if (
                                $type ===
                                "percentage"
                                ||
                                $type ===
                                "percent"
                                ||
                                $type ===
                                "%"
                            ) {

                                echo "%";

                            } else {

                                echo " OFF";

                            }

                            ?>


                            <br>


                            You save

                            <strong>

                                ₹<?= number_format(
                                    $bus[
                                        "discount_amount"
                                    ],
                                    2
                                ) ?>

                            </strong>


                            on this seat.


                        </div>


                        <span class="offer-save">

                            🎁 Festival Offer Applied

                        </span>


                    </div>


                <?php endif; ?>



                <!-- =================================================
                     ROUTE
                ================================================= -->

                <div class="route-box">


                    <div class="time-box">


                        <strong>

                            <?= date(
                                "h:i A",
                                strtotime(
                                    $bus[
                                        "departure_time"
                                    ]
                                )
                            ) ?>

                        </strong>


                        <small>

                            <?= htmlspecialchars(
                                $bus["source"]
                            ) ?>

                        </small>


                    </div>



                    <div class="route-arrow">

                        ─────────→

                    </div>



                    <div class="time-box right">


                        <strong>

                            <?= date(
                                "h:i A",
                                strtotime(
                                    $bus[
                                        "arrival_time"
                                    ]
                                )
                            ) ?>

                        </strong>


                        <small>

                            <?= htmlspecialchars(
                                $bus["destination"]
                            ) ?>

                        </small>


                    </div>


                </div>



                <!-- =================================================
                     BUS INFORMATION
                ================================================= -->

                <div class="bus-info">


                    <span class="info-tag">

                        🛋️

                        <?= htmlspecialchars(
                            $bus["bus_type"]
                        ) ?>

                    </span>


                    <span class="info-tag">

                        💺

                        <?= $bus[
                            "total_seats"
                        ] ?>

                        Seats

                    </span>



                    <?php if (
                        $bus[
                            "available_seats"
                        ] > 0
                    ): ?>


                        <span
                            class="
                                info-tag
                                available-tag
                            "
                        >

                            🟢

                            <?= $bus[
                                "available_seats"
                            ] ?>

                            Seats Available

                        </span>


                    <?php else: ?>


                        <span
                            class="
                                info-tag
                                sold-tag
                            "
                        >

                            🔴

                            Sold Out

                        </span>


                    <?php endif; ?>



                    <span class="info-tag">

                        📏

                        <?= htmlspecialchars(
                            $bus[
                                "distance_km"
                            ]
                        ) ?>

                        KM

                    </span>



                    <span class="info-tag">

                        🔄 DAILY

                    </span>


                </div>



                <!-- =================================================
                     FOOTER
                ================================================= -->

                <div class="bus-footer">


                    <div class="boarding">


                        <strong>
                            Boarding:
                        </strong>


                        <?= htmlspecialchars(
                            $bus[
                                "boarding_point"
                            ]
                        ) ?>


                        <br>


                        <strong>
                            Dropping:
                        </strong>


                        <?= htmlspecialchars(
                            $bus[
                                "dropping_point"
                            ]
                        ) ?>


                    </div>



                    <!-- SELECT SEATS -->

                    <?php if (
                        $bus[
                            "available_seats"
                        ] > 0
                    ): ?>


                        <form
                            method="GET"
                            action="seat_selection.php"
                        >


                            <input
                                type="hidden"
                                name="schedule_id"
                                value="<?= $bus[
                                    "schedule_id"
                                ] ?>"
                            >


                            <input
                                type="hidden"
                                name="journey_date"
                                value="<?= htmlspecialchars(
                                    $journey_date
                                ) ?>"
                            >


                            <button
                                type="submit"
                                class="select-seat-btn"
                            >

                                Select Seats →

                            </button>


                        </form>


                    <?php else: ?>


                        <button
                            class="sold-btn"
                            disabled
                        >

                            Sold Out

                        </button>


                    <?php endif; ?>


                </div>


            </div>


        <?php endforeach; ?>


    <?php elseif (
        $error === ""
    ): ?>


        <!-- =====================================================
             NO BUSES
        ===================================================== -->

        <div class="no-results">


            <div class="no-results-icon">

                🚌

            </div>


            <h2>

                No Buses Available

            </h2>


            <p>

                Sorry, no buses are available
                for this search.

            </p>


        </div>


    <?php endif; ?>


</main>



<!-- =========================================================
     FOOTER
========================================================= -->

<footer class="footer">

    © 2026 Pink Ride.
    All Rights Reserved.

</footer>


</body>

</html>