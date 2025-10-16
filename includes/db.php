<?php
// If a test or caller already provided a PDO connection, reuse it EARLY to avoid loading vendor in test envs.
if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
    $conn = $GLOBALS['conn'];
    return;
}

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables (.env by default, .env.test if APP_ENV=test)
$envFiles = ['.env'];
$appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? null);
if ($appEnv === 'test') {
    $envFiles = ['.env.test', '.env'];
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../', $envFiles);
$dotenv->load();

$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? '';
$username = $_ENV['DB_USER'] ?? '';
$password = $_ENV['DB_PASS'] ?? '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>