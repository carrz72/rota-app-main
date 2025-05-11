<?php
session_start();

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
    <title>Rota App - Manage Your Work Schedule</title>
</head>

<body>
    <main class="landing-container">
        <!-- Your landing page content -->
    </main>
</body>

<!-- Keep service worker registration -->
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