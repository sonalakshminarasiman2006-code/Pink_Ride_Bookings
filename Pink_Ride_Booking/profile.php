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
   VARIABLES
========================================================= */

$success_message = '';
$error_message = '';


/* =========================================================
   PROFILE PHOTO FOLDER
========================================================= */

$upload_dir = __DIR__ . "/uploads/profile/";

$upload_url = "uploads/profile/";


/*
   Create folder automatically if it does not exist.
*/

if (!is_dir($upload_dir)) {

    mkdir(
        $upload_dir,
        0755,
        true
    );

}


/* =========================================================
   GET USER
========================================================= */

$sql = "
    SELECT
        user_id,
        full_name,
        email,
        phone
    FROM users
    WHERE user_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $stmt->close();

    die("User profile not found.");

}

$user = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   DEFAULT PROFILE PHOTO
========================================================= */

$profile_photo = $upload_url . "user_" . $user_id . ".jpg";


/*
   Check supported extensions.
*/

$possible_photos = [
    "jpg",
    "jpeg",
    "png",
    "webp"
];

$existing_photo = '';

foreach ($possible_photos as $extension) {

    $possible_file =
        $upload_dir .
        "user_" .
        $user_id .
        "." .
        $extension;

    if (file_exists($possible_file)) {

        $existing_photo =
            $upload_url .
            "user_" .
            $user_id .
            "." .
            $extension;

        break;

    }

}

if ($existing_photo !== '') {

    $profile_photo = $existing_photo;

}


/* =========================================================
   FORM SUBMISSION
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    /* =====================================================
       UPDATE PROFILE DETAILS
    ===================================================== */

    if (
        isset($_POST['action']) &&
        $_POST['action'] === 'update_profile'
    ) {

        $full_name = isset($_POST['full_name'])
            ? trim($_POST['full_name'])
            : '';

        $email = isset($_POST['email'])
            ? trim($_POST['email'])
            : '';

        $phone = isset($_POST['phone'])
            ? trim($_POST['phone'])
            : '';


        /* ================================================
           VALIDATION
        ================================================ */

        if ($full_name === '') {

            $error_message =
                "Please enter your full name.";

        }

        elseif ($email === '') {

            $error_message =
                "Please enter your email address.";

        }

        elseif (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $error_message =
                "Please enter a valid email address.";

        }

        else {


            /* ============================================
               CHECK EMAIL
            ============================================ */

            $email_sql = "
                SELECT
                    user_id
                FROM users
                WHERE email = ?
                  AND user_id != ?
                LIMIT 1
            ";

            $email_stmt =
                $conn->prepare($email_sql);

            $email_stmt->bind_param(
                "si",
                $email,
                $user_id
            );

            $email_stmt->execute();

            $email_result =
                $email_stmt->get_result();


            if ($email_result->num_rows > 0) {

                $error_message =
                    "This email address is already used by another account.";

            }

            else {


                /* ========================================
                   UPDATE USER
                ======================================== */

                $update_sql = "
                    UPDATE users
                    SET
                        full_name = ?,
                        email = ?,
                        phone = ?
                    WHERE user_id = ?
                ";

                $update_stmt =
                    $conn->prepare(
                        $update_sql
                    );

                $update_stmt->bind_param(
                    "sssi",
                    $full_name,
                    $email,
                    $phone,
                    $user_id
                );

                $update_stmt->execute();

                $update_stmt->close();


                /* ========================================
                   UPDATE DISPLAY VALUES
                ======================================== */

                $user['full_name'] =
                    $full_name;

                $user['email'] =
                    $email;

                $user['phone'] =
                    $phone;


                $success_message =
                    "Profile updated successfully.";

            }


            $email_stmt->close();

        }

    }


    /* =====================================================
       PROFILE PHOTO UPLOAD
    ===================================================== */

    if (
        isset($_POST['action']) &&
        $_POST['action'] === 'update_photo'
    ) {


        if (
            !isset($_FILES['profile_photo']) ||
            $_FILES['profile_photo']['error']
            !== UPLOAD_ERR_OK
        ) {

            $error_message =
                "Please select a profile photo.";

        }

        else {


            $file = $_FILES['profile_photo'];


            /* ============================================
               FILE SIZE
            ============================================ */

            $max_size =
                5 * 1024 * 1024;


            if ($file['size'] > $max_size) {

                $error_message =
                    "Photo size must be less than 5 MB.";

            }

            else {


                /* ========================================
                   CHECK MIME TYPE
                ======================================== */

                $finfo =
                    new finfo(
                        FILEINFO_MIME_TYPE
                    );

                $mime_type =
                    $finfo->file(
                        $file['tmp_name']
                    );


                $allowed_types = [

                    'image/jpeg'
                        => 'jpg',

                    'image/png'
                        => 'png',

                    'image/webp'
                        => 'webp'

                ];


                if (
                    !isset(
                        $allowed_types[$mime_type]
                    )
                ) {

                    $error_message =
                        "Only JPG, PNG and WEBP images are allowed.";

                }

                else {


                    $extension =
                        $allowed_types[
                            $mime_type
                        ];


                    /* ====================================
                       DELETE OLD PHOTOS
                    ==================================== */

                    foreach (
                        $possible_photos
                        as $old_extension
                    ) {

                        $old_file =
                            $upload_dir .
                            "user_" .
                            $user_id .
                            "." .
                            $old_extension;

                        if (
                            file_exists($old_file)
                        ) {

                            unlink($old_file);

                        }

                    }


                    /* ====================================
                       NEW FILE NAME
                    ==================================== */

                    $new_file_name =
                        "user_" .
                        $user_id .
                        "." .
                        $extension;


                    $destination =
                        $upload_dir .
                        $new_file_name;


                    /* ====================================
                       MOVE FILE
                    ==================================== */

                    if (
                        move_uploaded_file(
                            $file['tmp_name'],
                            $destination
                        )
                    ) {

                        $profile_photo =
                            $upload_url .
                            $new_file_name;

                        $success_message =
                            "Profile photo updated successfully.";

                    }

                    else {

                        $error_message =
                            "Unable to upload the profile photo.";

                    }

                }

            }

        }

    }


    /* =====================================================
       DELETE PROFILE PHOTO
    ===================================================== */

    if (
        isset($_POST['action']) &&
        $_POST['action'] === 'delete_photo'
    ) {


        $deleted = false;


        foreach (
            $possible_photos
            as $extension
        ) {

            $file =
                $upload_dir .
                "user_" .
                $user_id .
                "." .
                $extension;


            if (file_exists($file)) {

                unlink($file);

                $deleted = true;

            }

        }


        if ($deleted) {

            $profile_photo =
                $upload_url .
                "user_" .
                $user_id .
                ".jpg";

            $success_message =
                "Profile photo removed successfully.";

        }

        else {

            $error_message =
                "No profile photo was found.";

        }

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
    My Profile | Pink Ride
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

    max-width: 850px;

    margin: auto;

    padding:
        35px 18px 60px;

}


/* =========================================================
   TITLE
========================================================= */

.page-title {

    margin:
        0 0 7px;

    font-size: 30px;

}


.subtitle {

    color: #777;

    font-size: 14px;

    margin:
        0 0 25px;

}


/* =========================================================
   PROFILE CARD
========================================================= */

.profile-card {

    background: #ffffff;

    border:
        1px solid #f0dce5;

    border-radius: 22px;

    padding: 28px;

    box-shadow:
        0 15px 40px
        rgba(232,62,140,.08);

}


/* =========================================================
   PROFILE PHOTO
========================================================= */

.profile-section {

    text-align: center;

    margin-bottom: 28px;

}


.photo-wrapper {

    width: 120px;

    height: 120px;

    margin:
        0 auto 15px;

    border-radius: 50%;

    overflow: hidden;

    background: #fff0f6;

    border:
        3px solid #ffd1e1;

    display: flex;

    align-items: center;

    justify-content: center;

}


.profile-photo {

    width: 100%;

    height: 100%;

    object-fit: cover;

}


.default-avatar {

    color: #e83e8c;

    font-size: 52px;

    font-weight: 800;

}


.profile-section h2 {

    margin: 0;

    font-size: 22px;

}


.profile-section p {

    color: #888;

    margin:
        6px 0 18px;

    font-size: 13px;

}


/* =========================================================
   PHOTO BUTTONS
========================================================= */

.photo-actions {

    display: flex;

    justify-content: center;

    gap: 9px;

    flex-wrap: wrap;

}


.photo-button {

    display: inline-block;

    padding:
        10px 15px;

    border-radius: 10px;

    font-size: 12px;

    font-weight: 800;

    cursor: pointer;

}


.change-photo {

    background: #e83e8c;

    color: #ffffff;

}


.change-photo:hover {

    background: #d72f76;

}


.remove-photo {

    background: #ffffff;

    color: #d33b3b;

    border:
        1px solid #efbcbc;

}


.file-input {

    display: none;

}


/* =========================================================
   MESSAGES
========================================================= */

.success-message {

    background: #f0fbf5;

    border:
        1px solid #c8ecd8;

    color: #168652;

    border-radius: 12px;

    padding:
        13px 15px;

    margin-bottom: 20px;

    font-size: 13px;

}


.error-message {

    background: #fff3f3;

    border:
        1px solid #f1c5c5;

    color: #c53a3a;

    border-radius: 12px;

    padding:
        13px 15px;

    margin-bottom: 20px;

    font-size: 13px;

}


/* =========================================================
   FORM
========================================================= */

.form-group {

    margin-bottom: 19px;

}


.form-group label {

    display: block;

    margin-bottom: 7px;

    color: #555;

    font-size: 13px;

    font-weight: 700;

}


.form-group input {

    width: 100%;

    padding:
        13px 14px;

    border:
        1px solid #e5d5dc;

    border-radius: 11px;

    outline: none;

    font-size: 14px;

    background: #ffffff;

    color: #333;

}


.form-group input:focus {

    border-color: #e83e8c;

    box-shadow:
        0 0 0 3px
        rgba(232,62,140,.08);

}


/* =========================================================
   BUTTONS
========================================================= */

.actions {

    display: flex;

    gap: 12px;

    margin-top: 25px;

}


.btn {

    flex: 1;

    text-align: center;

    text-decoration: none;

    padding:
        13px 18px;

    border-radius: 11px;

    font-size: 13px;

    font-weight: 800;

    cursor: pointer;

}


.btn-primary {

    background: #e83e8c;

    color: #ffffff;

    border: 0;

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
   ACCOUNT INFORMATION
========================================================= */

.account-info {

    margin-top: 22px;

    padding: 18px;

    background: #fff8fb;

    border:
        1px solid #f3dce7;

    border-radius: 14px;

}


.account-info h3 {

    margin:
        0 0 12px;

    font-size: 16px;

}


.info-row {

    display: flex;

    justify-content:
        space-between;

    gap: 15px;

    padding:
        9px 0;

    border-bottom:
        1px solid #f1e3e9;

    font-size: 13px;

}


.info-row:last-child {

    border-bottom: 0;

}


.info-row span {

    color: #888;

}


.info-row strong {

    text-align: right;

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


    .profile-card {

        padding: 20px;

    }


    .actions {

        flex-direction: column;

    }


    .info-row {

        flex-direction: column;

        gap: 4px;

    }


    .info-row strong {

        text-align: left;

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


<h1 class="page-title">
    My Profile
</h1>


<p class="subtitle">
    View and update your Pink Ride account details.
</p>


<section class="profile-card">


<!-- =====================================================
     PROFILE PHOTO
===================================================== -->

<div class="profile-section">


    <div class="photo-wrapper">


        <?php if ($existing_photo !== ''): ?>

            <img
                src="<?= htmlspecialchars(
                    $profile_photo,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>?v=<?= time() ?>"
                alt="Profile Photo"
                class="profile-photo"
            >

        <?php else: ?>

            <div class="default-avatar">
                👤
            </div>

        <?php endif; ?>


    </div>


    <h2>

        <?= htmlspecialchars(
            $user['full_name'] ?? 'User',
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </h2>


    <p>
        Pink Ride Account
    </p>


    <!-- PHOTO ACTIONS -->

    <div class="photo-actions">


        <!-- CHANGE PHOTO -->

        <form
            method="POST"
            action="profile.php"
            enctype="multipart/form-data"
            id="photoForm"
        >

            <input
                type="hidden"
                name="action"
                value="update_photo"
            >


            <label
                for="profile_photo"
                class="photo-button change-photo"
            >
                📷 Change Photo
            </label>


            <input
                type="file"
                id="profile_photo"
                name="profile_photo"
                class="file-input"
                accept="image/jpeg,image/png,image/webp"
                onchange="document.getElementById('photoForm').submit();"
            >

        </form>


        <!-- REMOVE PHOTO -->

        <?php if ($existing_photo !== ''): ?>

            <form
                method="POST"
                action="profile.php"
                onsubmit="return confirm('Remove your profile photo?');"
            >

                <input
                    type="hidden"
                    name="action"
                    value="delete_photo"
                >

                <button
                    type="submit"
                    class="photo-button remove-photo"
                >
                    ✕ Remove Photo
                </button>

            </form>

        <?php endif; ?>


    </div>


</div>


<!-- =====================================================
     SUCCESS MESSAGE
===================================================== -->

<?php if ($success_message !== ''): ?>

    <div class="success-message">

        ✓

        <?= htmlspecialchars(
            $success_message,
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </div>

<?php endif; ?>


<!-- =====================================================
     ERROR MESSAGE
===================================================== -->

<?php if ($error_message !== ''): ?>

    <div class="error-message">

        ⚠

        <?= htmlspecialchars(
            $error_message,
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </div>

<?php endif; ?>


<!-- =====================================================
     EDIT PROFILE
===================================================== -->

<form
    method="POST"
    action="profile.php"
>


    <input
        type="hidden"
        name="action"
        value="update_profile"
    >


    <!-- FULL NAME -->

    <div class="form-group">

        <label for="full_name">
            Full Name
        </label>

        <input
            type="text"
            id="full_name"
            name="full_name"
            value="<?= htmlspecialchars(
                $user['full_name'] ?? '',
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            maxlength="100"
            required
        >

    </div>


    <!-- EMAIL -->

    <div class="form-group">

        <label for="email">
            Email Address
        </label>

        <input
            type="email"
            id="email"
            name="email"
            value="<?= htmlspecialchars(
                $user['email'] ?? '',
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            maxlength="150"
            required
        >

    </div>


    <!-- PHONE -->

    <div class="form-group">

        <label for="phone">
            Phone Number
        </label>

        <input
            type="text"
            id="phone"
            name="phone"
            value="<?= htmlspecialchars(
                $user['phone'] ?? '',
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            maxlength="20"
        >

    </div>


    <!-- ACTION BUTTONS -->

    <div class="actions">


        <button
            type="submit"
            class="btn btn-primary"
        >
            💾 Save Changes
        </button>


        <a
            href="dashboard.php"
            class="btn btn-secondary"
        >
            ← Back
        </a>


    </div>


</form>


<!-- =====================================================
     ACCOUNT INFORMATION
===================================================== -->

<div class="account-info">


    <h3>
        Account Information
    </h3>


    <div class="info-row">

        <span>
            User ID
        </span>

        <strong>
            #<?= (int)$user['user_id'] ?>
        </strong>

    </div>


    <div class="info-row">

        <span>
            Full Name
        </span>

        <strong>

            <?= htmlspecialchars(
                $user['full_name'] ?? '',
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </strong>

    </div>


    <div class="info-row">

        <span>
            Email
        </span>

        <strong>

            <?= htmlspecialchars(
                $user['email'] ?? '',
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </strong>

    </div>


    <div class="info-row">

        <span>
            Phone
        </span>

        <strong>

            <?= htmlspecialchars(
                $user['phone'] ?? 'Not provided',
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </strong>

    </div>


</div>


</section>


</main>


</body>

</html>