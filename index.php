<?php
session_start();

// Force HTTPS for better PWA compatibility
if (!isset($_SERVER['HTTPS']) && $_SERVER['HTTP_HOST'] !== 'localhost') {
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

// Redirect if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: users/dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.jpg">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open Rota - Manage Your Work Schedule</title>
    <style>
        @font-face {
            font-family: "newFont";
            src: url("fonts/CooperHewitt-Book.otf");
            font-weight: normal;
            font-style: normal;
        }

        body {
            font-family: "newFont", Arial, sans-serif;
            background-image: url("images/backg3.jpg");
            background-size: cover;
            background-position: center;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .landing-container {
            background-color: rgba(255, 255, 255, 0.9);
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 800px;
            width: 90%;
        }

        .hero-section {
            margin-bottom: 2rem;
        }

        h1 {
            color: #fd2b2b;
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        p {
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
            line-height: 1.5;
        }

        .cta-buttons {
            margin: 2rem 0;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #fd2b2b;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 1.1rem;
            margin: 0 10px;
            transition: transform 0.2s, background-color 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            background-color: #c82333;
        }

        .btn-secondary {
            background-color: #333;
        }

        .btn-secondary:hover {
            background-color: #555;
        }

        .features {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .feature-card {
            background-color: #fff;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            flex: 1 1 250px;
            max-width: 300px;
            transition: transform 0.2s;
        }

        .feature-card:hover {
            transform: translateY(-5px);
        }

        .feature-card h3 {
            color: #fd2b2b;
            margin-bottom: 0.5rem;
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #fd2b2b;
            margin-bottom: 1rem;
        }

        .feature-icon {
            font-size: 2rem;
            color: #fd2b2b;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 2rem;
            }

            .feature-card {
                flex: 1 1 100%;
            }
        }
    </style>
</head>

<body>
    <main class="landing-container">
        <div class="logo">Open Rota</div>

        <section class="hero-section">
            <h1>Manage Your Work Schedule</h1>
            <p>The easiest way to track shifts, calculate earnings, and organize your work life in one place.</p>

            <div class="cta-buttons">
                <a href="functions/login.php" class="btn">Log In</a>
                <a href="functions/register.php" class="btn btn-secondary">Sign Up</a>
            </div>
        </section>

        <div class="features">
            <div class="feature-card">
                <div class="feature-icon"><i class="fa fa-calendar"></i></div>
                <h3>Shift Management</h3>
                <p>Easily view and manage your upcoming shifts. Get notified about schedule changes instantly.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fa fa-cog"></i></div>
                <h3>Role Settings</h3>
                <p>Configure your work roles with custom pay rates, night shift settings, and more.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fa fa-money"></i></div>
                <h3>Earnings Tracker</h3>
                <p>Keep track of your earnings with our dashboard that calculates your income based on shifts.</p>
            </div>
        </div>
    </main>
</body>

<script>
    if ("serviceWorker" in navigator) {
        window.addEventListener("load", function () {
            navigator.serviceWorker.register("/rota-app-main/service-worker.js")
                .then(function (registration) {
                    console.log("ServiceWorker registration successful");
                })
                .catch(function (error) {
                    console.log("ServiceWorker registration failed: ", error);
                });
        });
    }
</script>
<script src="/rota-app-main/js/links.js"></script>

</html>