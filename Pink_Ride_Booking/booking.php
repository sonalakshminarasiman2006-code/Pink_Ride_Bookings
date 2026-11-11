<?php

session_start();

require_once "db.php";

/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| POST ONLY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: search_bus.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET FORM DATA
|--------------------------------------------------------------------------
*/

$schedule_id = (int) ($_POST['schedule_id'] ?? 0);

$travel_date = trim(
    $_POST['travel_date'] ?? ''
);

$seat_ids = $_POST['seat_ids'] ?? [];


/*
|--------------------------------------------------------------------------
| VALIDATE SCHEDULE
|--------------------------------------------------------------------------
*/

if ($schedule_id <= 0) {
    die("Invalid schedule selected.");
}


/*
|--------------------------------------------------------------------------
| VALIDATE DATE
|--------------------------------------------------------------------------
*/

if ($travel_date === '') {
    die("Please select a travel date.");
}

$date_check = DateTime::createFromFormat(
    'Y-m-d',
    $travel_date
);

if (
    !$date_check ||
    $date_check->format('Y-m-d') !== $travel_date
) {
    die("Invalid travel date.");
}


/*
|--------------------------------------------------------------------------
| NO PAST DATE
|--------------------------------------------------------------------------
*/

if ($travel_date < date('Y-m-d')) {
    die("Please select a future date.");
}


/*
|--------------------------------------------------------------------------
| VALIDATE SEATS
|--------------------------------------------------------------------------
*/

if (!is_array($seat_ids)) {
    $seat_ids = [$seat_ids];
}


$selected_seats = [];


foreach ($seat_ids as $seat) {

    $seat = (int) $seat;

    if ($seat >= 1 && $seat <= 50) {
        $selected_seats[] = $seat;
    }
}


$selected_seats = array_values(
    array_unique($selected_seats)
);


if (empty($selected_seats)) {
    die("Please select at least one seat.");
}


/*
|--------------------------------------------------------------------------
| GET SCHEDULE
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        s.schedule_id,
        s.bus_id,
        s.route_id,
        s.departure_time,
        s.arrival_time,
        s.boarding_point,
        s.dropping_point,
        s.base_fare,

        b.bus_number,
        b.bus_name,
        b.bus_type,
        b.total_seats,
        b.operator_name,
        b.amenities,

        r.source,
        r.destination,
        r.distance_km,
        r.estimated_duration

    FROM schedules s

    INNER JOIN buses b
        ON b.bus_id = s.bus_id

    INNER JOIN routes r
        ON r.route_id = s.route_id

    WHERE s.schedule_id = ?

    LIMIT 1
";


$stmt = $conn->prepare($sql);

if (!$stmt) {
    die(
        "Schedule database error: " .
        htmlspecialchars($conn->error)
    );
}


$stmt->bind_param(
    "i",
    $schedule_id
);


if (!$stmt->execute()) {
    die(
        "Schedule error: " .
        htmlspecialchars($stmt->error)
    );
}


$result = $stmt->get_result();


if ($result->num_rows === 0) {

    $stmt->close();

    die("Selected bus schedule was not found.");
}


$bus = $result->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| BASIC DETAILS
|--------------------------------------------------------------------------
*/

$bus_id = (int) $bus['bus_id'];

$route_id = (int) $bus['route_id'];

$base_fare = (float) $bus['base_fare'];

$total_seats = count($selected_seats);


/*
|--------------------------------------------------------------------------
| CHECK SEATS FOR THIS EXACT DATE
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| schedule_id + travel_date + seat_id
|
| means:
|
| 25-08-2026 Seat 10 = blocked
| 26-08-2026 Seat 10 = available
|--------------------------------------------------------------------------
*/

$placeholders = implode(
    ',',
    array_fill(
        0,
        count($selected_seats),
        '?'
    )
);


$check_sql = "
    SELECT seat_id

    FROM booking_seats

    WHERE
        schedule_id = ?
        AND travel_date = ?
        AND seat_id IN ($placeholders)

        AND seat_status IN
        (
            'booked',
            'confirmed',
            'paid'
        )

    FOR UPDATE
";


$conn->begin_transaction();


try {

    /*
    |--------------------------------------------------------------------------
    | CHECK ALREADY BOOKED SEATS
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare($check_sql);

    if (!$stmt) {
        throw new Exception(
            "Seat check error: " .
            $conn->error
        );
    }


    $types = "is";

    $params = [
        $schedule_id,
        $travel_date
    ];


    foreach ($selected_seats as $seat) {

        $types .= "i";

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


    if (!$stmt->execute()) {

        throw new Exception(
            "Seat check failed: " .
            $stmt->error
        );
    }


    $seat_result =
        $stmt->get_result();


    $blocked_seats = [];


    while (
        $row = $seat_result->fetch_assoc()
    ) {

        $blocked_seats[] =
            (int) $row['seat_id'];
    }


    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | ALREADY BOOKED
    |--------------------------------------------------------------------------
    */

    if (!empty($blocked_seats)) {

        $blocked =
            implode(
                ', ',
                $blocked_seats
            );


        throw new Exception(
            "Seat(s) $blocked are already booked for $travel_date."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CALCULATE TOTAL
    |--------------------------------------------------------------------------
    */

    $total_amount =
        $base_fare * $total_seats;


    /*
    |--------------------------------------------------------------------------
    | CREATE UNIQUE BOOKING CODE
    |--------------------------------------------------------------------------
    */

    do {

        $booking_code =
            'PRB-' .
            date('YmdHis') .
            '-' .
            strtoupper(
                substr(
                    bin2hex(
                        random_bytes(4)
                    ),
                    0,
                    8
                )
            );


        $code_sql = "
            SELECT booking_id

            FROM bookings

            WHERE booking_code = ?

            LIMIT 1
        ";


        $code_stmt =
            $conn->prepare($code_sql);


        if (!$code_stmt) {

            throw new Exception(
                "Booking code error: " .
                $conn->error
            );
        }


        $code_stmt->bind_param(
            "s",
            $booking_code
        );


        $code_stmt->execute();


        $code_result =
            $code_stmt->get_result();


        $exists =
            ($code_result->num_rows > 0);


        $code_stmt->close();

    } while ($exists);


    /*
    |--------------------------------------------------------------------------
    | CHECK ACTUAL BOOKINGS COLUMNS
    |--------------------------------------------------------------------------
    */

    $columns_result =
        $conn->query(
            "SHOW COLUMNS FROM bookings"
        );


    if (!$columns_result) {

        throw new Exception(
            "Cannot read bookings table: " .
            $conn->error
        );
    }


    $columns = [];


    while (
        $column =
        $columns_result->fetch_assoc()
    ) {

        $columns[] =
            $column['Field'];
    }


    /*
    |--------------------------------------------------------------------------
    | BUILD INSERT
    |--------------------------------------------------------------------------
    */

    $fields = [];

    $values = [];

    $types = '';

    $params = [];


    /*
    | booking_code
    */

    if (
        in_array(
            'booking_code',
            $columns,
            true
        )
    ) {

        $fields[] =
            'booking_code';

        $values[] =
            '?';

        $types .= 's';

        $params[] =
            $booking_code;
    }


    /*
    | user_id
    */

    if (
        in_array(
            'user_id',
            $columns,
            true
        )
    ) {

        $fields[] =
            'user_id';

        $values[] =
            '?';

        $types .= 'i';

        $params[] =
            $user_id;
    }


    /*
    | schedule_id
    */

    if (
        in_array(
            'schedule_id',
            $columns,
            true
        )
    ) {

        $fields[] =
            'schedule_id';

        $values[] =
            '?';

        $types .= 'i';

        $params[] =
            $schedule_id;
    }


    /*
    | total_seats
    */

    if (
        in_array(
            'total_seats',
            $columns,
            true
        )
    ) {

        $fields[] =
            'total_seats';

        $values[] =
            '?';

        $types .= 'i';

        $params[] =
            $total_seats;
    }


    /*
    | total_amount
    |
    | Only if it exists.
    */

    if (
        in_array(
            'total_amount',
            $columns,
            true
        )
    ) {

        $fields[] =
            'total_amount';

        $values[] =
            '?';

        $types .= 'd';

        $params[] =
            $total_amount;
    }


    /*
    | booking_status
    */

    if (
        in_array(
            'booking_status',
            $columns,
            true
        )
    ) {

        $fields[] =
            'booking_status';

        $values[] =
            '?';

        $types .= 's';

        $params[] =
            'pending';
    }


    /*
    | payment_status
    */

    if (
        in_array(
            'payment_status',
            $columns,
            true
        )
    ) {

        $fields[] =
            'payment_status';

        $values[] =
            '?';

        $types .= 's';

        $params[] =
            'pending';
    }


    /*
    | created_at
    */

    if (
        in_array(
            'created_at',
            $columns,
            true
        )
    ) {

        $fields[] =
            'created_at';

        $values[] =
            'NOW()';
    }


    /*
    | updated_at
    */

    if (
        in_array(
            'updated_at',
            $columns,
            true
        )
    ) {

        $fields[] =
            'updated_at';

        $values[] =
            'NOW()';
    }


    if (empty($fields)) {

        throw new Exception(
            "No valid booking columns found."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INSERT BOOKING
    |--------------------------------------------------------------------------
    */

    $booking_sql = "
        INSERT INTO bookings
        (
            " .
            implode(
                ', ',
                $fields
            )
            . "
        )

        VALUES
        (
            " .
            implode(
                ', ',
                $values
            )
            . "
        )
    ";


    $stmt =
        $conn->prepare(
            $booking_sql
        );


    if (!$stmt) {

        throw new Exception(
            "Booking prepare error: " .
            $conn->error
        );
    }


    if (!empty($params)) {

        $bind_params = [];

        $bind_params[] =
            $types;


        foreach (
            $params
            as $key => $value
        ) {

            $bind_params[] =
                &$params[$key];
        }


        call_user_func_array(
            [$stmt, 'bind_param'],
            $bind_params
        );
    }


    if (!$stmt->execute()) {

        throw new Exception(
            "Booking insert failed: " .
            $stmt->error
        );
    }


    $booking_id =
        $conn->insert_id;


    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | INSERT SELECTED SEATS
    |--------------------------------------------------------------------------
    */

    $seat_sql = "
        INSERT INTO booking_seats
        (
            booking_id,
            travel_date,
            schedule_id,
            seat_id,
            seat_fare,
            seat_status,
            created_at
        )

        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
    ";


    $seat_stmt =
        $conn->prepare($seat_sql);


    if (!$seat_stmt) {

        throw new Exception(
            "Booking seats error: " .
            $conn->error
        );
    }


    $seat_status =
        'booked';


    foreach (
        $selected_seats
        as $seat_id
    ) {

        $seat_fare =
            $base_fare;


        $seat_stmt->bind_param(
            "isiids",
            $booking_id,
            $travel_date,
            $schedule_id,
            $seat_id,
            $seat_fare,
            $seat_status
        );


        if (!$seat_stmt->execute()) {

            /*
            |--------------------------------------------------------------------------
            | DUPLICATE UNIQUE KEY
            |--------------------------------------------------------------------------
            */

            if (
                $seat_stmt->errno == 1062
            ) {

                throw new Exception(
                    "Seat $seat_id was just booked by another user for $travel_date. Please choose another seat."
                );
            }


            throw new Exception(
                "Seat insert failed: " .
                $seat_stmt->error
            );
        }
    }


    $seat_stmt->close();


    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();


    /*
    |--------------------------------------------------------------------------
    | SAVE SESSION
    |--------------------------------------------------------------------------
    */

    $_SESSION['current_booking_id'] =
        $booking_id;

    $_SESSION['booking_schedule_id'] =
        $schedule_id;

    $_SESSION['booking_travel_date'] =
        $travel_date;


    /*
    |--------------------------------------------------------------------------
    | GO TO BOOKING DETAILS
    |--------------------------------------------------------------------------
    */

    header(
        "Location: booking_details.php?booking_id=" .
        urlencode($booking_id)
    );

    exit;


} catch (Exception $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    $conn->rollback();

    ?>

    <!DOCTYPE html>

    <html>

    <head>

        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        >

        <title>Booking Error</title>

        <style>

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;

                display: flex;
                align-items: center;
                justify-content: center;

                padding: 20px;

                font-family:
                    Arial,
                    sans-serif;

                background:
                    linear-gradient(
                        135deg,
                        #fff0f6,
                        #ffffff
                    );
            }

            .card {
                width: 100%;
                max-width: 520px;

                background: white;

                padding: 40px 30px;

                border-radius: 25px;

                text-align: center;

                box-shadow:
                    0 15px 45px
                    rgba(233, 30, 99, 0.14);
            }

            .icon {
                font-size: 55px;
                margin-bottom: 15px;
            }

            h2 {
                color: #e91e63;
                margin: 0 0 15px;
            }

            p {
                color: #555;
                line-height: 1.6;
            }

            .error {
                margin-top: 20px;

                padding: 15px;

                background: #fff4f8;

                border-radius: 12px;

                color: #555;

                word-break: break-word;
            }

            .btn {
                display: inline-block;

                margin-top: 22px;

                padding: 13px 25px;

                background: #e91e63;

                color: white;

                text-decoration: none;

                border-radius: 12px;
            }

        </style>

    </head>

    <body>

        <div class="card">

            <div class="icon">
                ⚠️
            </div>

            <h2>
                Booking Could Not Be Completed
            </h2>

            <p>
                Please go back and select another seat.
            </p>

            <div class="error">

                <?php
                echo htmlspecialchars(
                    $e->getMessage()
                );
                ?>

            </div>

            <a
                class="btn"
                href="javascript:history.back()"
            >
                ← Back to Seat Selection
            </a>

        </div>

    </body>

    </html>

    <?php

    exit;
}

?>