<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/check_role.php';
require_once __DIR__ . '/../includes/signing.php';
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!is_admin() && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin')) {
    http_response_code(403); echo 'Forbidden'; exit;
}
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Invalid id'; exit; }
$stmt = $conn->prepare('SELECT * FROM audit_admin_actions WHERE id = ?');
$stmt->execute([$id]);
$act = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$act) { http_response_code(404); echo 'Not found'; exit; }
// If this admin action references archive_ids, build download URLs for each archive
$archiveFiles = [];
if (!empty($act['archive_ids'])) {
    $ids = json_decode($act['archive_ids'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($ids)) {
        foreach ($ids as $aid) {
            $aid = intval($aid);
            if ($aid <= 0) continue;
            $archiveFiles[] = [ 'id' => $aid, 'download_url' => '/functions/download_archive_file.php?id=' . $aid ];
        }
    }
}

// Build proof payload
$payload = [
    'audit_admin_action' => $act,
    'archive_files' => $archiveFiles,
    'generated_at' => date('c'),
];
// determine receipt key
// compute asymmetric signature over payload
$serialized = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
try {
    $sig = sign_payload($serialized);
    $payload['signature'] = $sig;
    $payload['signature_algo'] = 'RSA-SHA256';
    $payload['signing_pub_fingerprint'] = public_key_fingerprint();
} catch (Exception $e) {
    http_response_code(500);
    echo 'Signing failed: ' . $e->getMessage();
    exit;
}
header('Content-Type: application/json; charset=utf-8');
echo $serialized ? $serialized : json_encode($payload);
