<?php
// Export selected audit_admin_actions as a ZIP containing JSON proofs
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/check_role.php';
require_once __DIR__ . '/../includes/signing.php';
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!is_admin() && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin')) { http_response_code(403); echo 'Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method'; exit; }
$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) { http_response_code(400); echo 'Invalid CSRF token'; exit; }
$ids = $_POST['ids'] ?? [];
$includeArchiv = !empty($_POST['include_archives']);
$ids = array_map('intval', (array)$ids);
if (empty($ids)) { http_response_code(400); echo 'No ids'; exit; }
// Prepare ZIP in memory
$zipname = 'audit_admin_actions_export_' . date('Ymd_His') . '.zip';
$zip = new ZipArchive();
$tmp = tempnam(sys_get_temp_dir(), 'export');
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); echo 'ZIP open failed'; exit; }
foreach ($ids as $id) {
    $stmt = $conn->prepare('SELECT * FROM audit_admin_actions WHERE id = ?'); $stmt->execute([$id]); $act = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$act) continue;
    $payload = ['audit_admin_action' => $act, 'generated_at' => date('c')];
    $serialized = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        $sig = sign_payload($serialized);
    } catch (Exception $e) {
        // skip signing failures
        $sig = null;
    }
    $entry = [ 'payload' => $payload, 'signature' => $sig, 'signature_algo' => 'RSA-SHA256', 'pub_fingerprint' => public_key_fingerprint() ];
    // Optionally include archives data
    if ($includeArchiv && !empty($act['archive_ids'])) {
        $aids = json_decode($act['archive_ids'], true);
        if (is_array($aids)) {
            $archiveBlobs = [];
            $sel = $conn->prepare('SELECT id, data FROM audit_archive WHERE id = ?');
            foreach ($aids as $aid) {
                $sel->execute([$aid]); $r = $sel->fetch(PDO::FETCH_ASSOC);
                if ($r) $archiveBlobs[$aid] = base64_encode($r['data']);
            }
            $entry['archives'] = $archiveBlobs;
        }
    }
    $zip->addFromString('action_' . $id . '.json', json_encode($entry, JSON_PRETTY_PRINT));
}
$zip->close();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipname . '"');
readfile($tmp);
unlink($tmp);
exit;
