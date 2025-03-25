<?php
session_start();
require 'db.php';

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /rota-app/functions/login.php");
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: /rota-app/users/dashboard.php"); // Redirect non-admins
        exit;
    }
}
?>


