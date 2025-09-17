<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') { header('Location: ../users/dashboard.php'); exit; }
require_once __DIR__ . '/../includes/db.php';

// Read current
$enabled = true;
try {
    $s = $conn->prepare('SELECT v FROM app_settings WHERE `k` = ? LIMIT 1');
    $s->execute(['AUDIT_LOG_ENABLED']);
    $v = $s->fetchColumn();
    if ($v !== false && $v !== null) {
        $vv = strtolower(trim((string)$v));
        $enabled = in_array($vv, ['1','true','on'], true);
    }
} catch (Exception $e) { $enabled = true; }

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $val = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? '1' : '0';
    try {
        // Ensure app_settings table exists (migration may not have been run)
        $conn->exec("CREATE TABLE IF NOT EXISTS app_settings (
            `k` VARCHAR(128) PRIMARY KEY,
            `v` TEXT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $ins = $conn->prepare('INSERT INTO app_settings (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`), updated_at = CURRENT_TIMESTAMP');
        $ins->execute(['AUDIT_LOG_ENABLED', $val]);
        $message = 'Saved.';
        $enabled = ($val === '1');
    } catch (Exception $e) { $message = 'Failed to save: ' . $e->getMessage(); }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Audit settings</title>
<link rel="stylesheet" href="../css/admin_dashboard.css">
</head>
<body>
<div class="admin-container">
<h1>Audit log settings</h1>
<p class="muted">Toggle runtime audit logging. Disabling will stop new audit rows from being written but will not delete existing logs.</p>
<?php if ($message): ?>
    <div style="padding:8px;background:#eef; border:1px solid #cce;"><?php echo htmlentities($message); ?></div>
<?php endif; ?>
<form method="post">
    <label><input type="radio" name="enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>> Enabled</label><br>
    <label><input type="radio" name="enabled" value="0" <?php echo !$enabled ? 'checked' : ''; ?>> Disabled (stop writing new audit logs)</label>
    <div style="margin-top:12px;"><button type="submit">Save</button> <a href="admin_dashboard.php">Back</a></div>
</form>
</div>
</body>
</html>
