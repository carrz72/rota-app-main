<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/check_role.php';
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!is_admin() && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin')) {
    http_response_code(403); echo 'Forbidden'; exit;
}
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Invalid id'; exit; }
$stmt = $conn->prepare('SELECT archive_path, archive_hash FROM audit_archive WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo 'Not found'; exit; }
$path = $row['archive_path'];
if (empty($path) || !file_exists($path)) { http_response_code(404); echo 'Archive file not found'; exit; }

// Stream file to admin (force download)
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
