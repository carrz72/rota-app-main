<?php
$host = 'localhost';
$dbname = 'rota_app';
$username = 'admin';
$password = 'd4f57d3693e34f12f5e1aaffcf0f3a7a0bb33ea9ff60845f';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
