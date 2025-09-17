<?php
// Purge old audit archives and admin actions according to config/retention.php
require_once __DIR__ . '/../includes/db.php';
$config = require __DIR__ . '/../config/retention.php';
$archiveDays = intval($config['audit_archive_retention_days'] ?? 365*3);
$adminDays = intval($config['audit_admin_actions_retention_days'] ?? 365*5);

// CLI options: --dry-run[=1] --log=path --email=addr
$opts = getopt('', ['dry-run::','log::','email::']);
$dry = isset($opts['dry-run']);
$logFile = $opts['log'] ?? (__DIR__ . '/purge_log_' . date('Ymd') . '.log');
$emailTo = $opts['email'] ?? null;

$now = new DateTime();
$cutArchive = (clone $now)->sub(new DateInterval('P' . $archiveDays . 'D'))->format('Y-m-d H:i:s');
$cutAdmin = (clone $now)->sub(new DateInterval('P' . $adminDays . 'D'))->format('Y-m-d H:i:s');

$report = [];
$report[] = "Purge run: " . date('c');
$report[] = "Archive retention days: $archiveDays, Admin action retention days: $adminDays";
$report[] = "Cut archive before: $cutArchive";
$report[] = "Cut admin actions before: $cutAdmin";

// Count what would be deleted
$countA = $conn->prepare('SELECT COUNT(*) FROM audit_archive WHERE archived_at < ?');
$countA->execute([$cutArchive]); $willDeleteA = (int)$countA->fetchColumn();
$countB = $conn->prepare('SELECT COUNT(*) FROM audit_admin_actions WHERE created_at < ?');
try { $countB->execute([$cutAdmin]); $willDeleteB = (int)$countB->fetchColumn(); } catch (Exception $e) { $willDeleteB = 0; }

$report[] = "Will delete archive rows: $willDeleteA";
$report[] = "Will delete admin action rows: $willDeleteB";

if ($dry) {
    $report[] = "Dry-run enabled: no rows will be deleted.";
    file_put_contents($logFile, implode("\n", $report) . "\n", FILE_APPEND);
    if ($emailTo) { @mail($emailTo, 'Purge dry-run report', implode("\n", $report)); }
    echo implode("\n", $report) . "\n";
    exit;
}

// proceed to delete with transaction
$conn->beginTransaction();
try {
        // Delete archive files first, then remove DB rows
        $selectOld = $conn->prepare('SELECT id, archive_path FROM audit_archive WHERE archived_at < ?');
        $selectOld->execute([$cutArchive]);
        $oldRows = $selectOld->fetchAll(PDO::FETCH_ASSOC);
        $deletedArchive = 0;
        foreach ($oldRows as $r) {
            $id = $r['id'];
            $path = $r['archive_path'];
            $fileDeleted = false;
            if (!empty($path) && file_exists($path)) {
                try { $fileDeleted = @unlink($path); } catch (Exception $e) { $fileDeleted = false; }
            } else {
                // file missing already
                $fileDeleted = true;
            }
            if ($fileDeleted) {
                // now delete DB row
                $del = $conn->prepare('DELETE FROM audit_archive WHERE id = ?');
                $del->execute([$id]);
                $deletedArchive += $del->rowCount();
                $report[] = "Deleted archive id $id and file $path";
            } else {
                $report[] = "Failed to delete file for archive id $id: $path";
            }
        }

    $stmt2 = $conn->prepare('DELETE FROM audit_admin_actions WHERE created_at < ?');
    $stmt2->execute([$cutAdmin]);
    $deletedAdmin = $stmt2->rowCount();

    $conn->commit();
    $report[] = "Deleted archive rows: $deletedArchive";
    $report[] = "Deleted admin action rows: $deletedAdmin";
    file_put_contents($logFile, implode("\n", $report) . "\n", FILE_APPEND);
    if ($emailTo) { @mail($emailTo, 'Purge completed', implode("\n", $report)); }
    echo implode("\n", $report) . "\n";
} catch (Exception $e) {
    try { $conn->rollBack(); } catch (Exception $e2) {}
    $report[] = "Purge failed: " . $e->getMessage();
    file_put_contents($logFile, implode("\n", $report) . "\n", FILE_APPEND);
    if ($emailTo) { @mail($emailTo, 'Purge failed', implode("\n", $report)); }
    echo implode("\n", $report) . "\n";
}
