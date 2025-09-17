<?php
// Reusable maintenance helpers for audit archiving and deletion
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/logger.php';
// optional S3 uploader
if (file_exists(__DIR__ . '/s3.php')) require_once __DIR__ . '/s3.php';

function archive_and_delete_by_criteria(PDO $conn, array $criteria, ?string $note, int $adminId, int $ack_backup = 0, int $ack_irrev = 0, bool $dry_run = true, bool $allow_all = false) {
    // Build where clause same as erase_audit.php (limited subset: older_than, user_id, ids)
    // If caller explicitly sets allow_all=true we permit operating on all rows (where 1=1)
    $where = '1=1'; $params = [];
    if (!empty($criteria['user_id'])) { $where = 'user_id = ?'; $params = [intval($criteria['user_id'])]; }
    elseif (!empty($criteria['ids']) && is_array($criteria['ids'])) { $ids = array_values(array_filter(array_map('intval',$criteria['ids']), function($v){return $v>0;})); if (empty($ids)) return ['ok'=>false,'error'=>'No valid ids']; $placeholders = implode(',', array_fill(0,count($ids),'?')); $where = "id IN ($placeholders)"; $params = $ids; }
    elseif (!empty($criteria['older_than'])) { $d = $criteria['older_than']; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) return ['ok'=>false,'error'=>'Invalid date']; $where = 'created_at < ?'; $params = [$d . ' 00:00:00']; }
    else {
        if (!$allow_all) {
            return ['ok'=>false,'error'=>'No criteria'];
        }
        // allow_all true -> keep $where = '1=1' and empty params to match all rows
    }

    // count
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM audit_log WHERE $where");
    $countStmt->execute($params);
    $count = (int)$countStmt->fetchColumn();
    if ($dry_run) return ['ok'=>true,'dry_run'=>true,'affected'=>$count];

    // begin transaction
    $conn->beginTransaction();
    try {
        // check encryption key
        try { $testKey = get_encryption_key(); } catch (Exception $e) { $testKey = null; }
        if (empty($testKey)) { $conn->rollBack(); return ['ok'=>false,'error'=>'Encryption key not configured']; }

        $sel = $conn->prepare("SELECT * FROM audit_log WHERE $where"); $sel->execute($params); $toArchive = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (empty($toArchive)) { $conn->commit(); return ['ok'=>true,'affected'=>0]; }

        $archiveIns = $conn->prepare("INSERT INTO audit_archive (original_id, admin_user_id, algorithm, iv, mac, data, note, archive_path, archive_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $archiveIds = []; $archiveHashes = [];
        $archiveDir = __DIR__ . '/../secure/archives'; if (!is_dir($archiveDir)) @mkdir($archiveDir, 0700, true);
        foreach ($toArchive as $row) {
            $plain = json_encode($row);
            $enc = encrypt_blob($plain);
            $bin = base64_decode($enc['data']); if ($bin === false) $bin = $enc['data'];
            $filename = 'archive_' . time() . '_' . bin2hex(random_bytes(8)) . '.enc';
            $filepath = $archiveDir . '/' . $filename;
            if (file_put_contents($filepath, $bin) === false) { $conn->rollBack(); rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', "Failed to write archive file: $filepath"); return ['ok'=>false,'error'=>'Failed to write archive file']; }
            $hash = hash_file('sha256', $filepath);
            // If AWS credentials present, upload to S3 and set archive_path to s3://bucket/key
            $awsBucket = getenv('AWS_S3_BUCKET') ?: ($_ENV['AWS_S3_BUCKET'] ?? null);
            if ($awsBucket && function_exists('s3_upload_file')) {
                try {
                    $s3Key = 'archives/' . $filename;
                    s3_upload_file($awsBucket, $s3Key, $filepath);
                    $archivePath = 's3://' . $awsBucket . '/' . $s3Key;
                    // optional: delete local file after successful upload
                    @unlink($filepath);
                } catch (Exception $e) {
                    // log but continue using local file path
                    rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'S3 upload failed: ' . $e->getMessage());
                    $archivePath = $filepath;
                }
            } else {
                $archivePath = $filepath;
            }
            $archiveIns->execute([$row['id'],$adminId,$enc['algorithm'],$enc['iv'],$enc['mac'],$bin,$note,$archivePath,$hash]);
            $aid = (int)$conn->lastInsertId(); $archiveIds[] = $aid; $archiveHashes[] = $hash;
            rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'Archived id ' . $row['id'] . ' to ' . $archivePath . ' hash ' . $hash);
        }
        // delete originals
        $delSql = "DELETE FROM audit_log WHERE $where"; $delStmt = $conn->prepare($delSql); $delStmt->execute($params); $affected = $delStmt->rowCount();
        // record admin action
        $ins = $conn->prepare("INSERT INTO audit_admin_actions (admin_user_id, action, criteria, affected_count, note, archive_ids, archive_hashes, acknowledged_backup, acknowledged_irrevocable) VALUES (?, 'archive_delete', ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$adminId, json_encode($criteria), $affected, $note, json_encode($archiveIds), json_encode($archiveHashes), $ack_backup, $ack_irrev]);
        $adminActionId = (int)$conn->lastInsertId();
        $conn->commit();
        try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $adminId, 'audit_archive_delete', ['criteria'=>$criteria,'affected'=>$affected,'note'=>$note,'archive_admin_action_id'=>$adminActionId], null, 'admin_action', session_id()); } catch (Exception $e) {}
        return ['ok'=>true,'affected'=>$affected,'archive_admin_action_id'=>$adminActionId,'archive_ids'=>$archiveIds,'archive_hashes'=>$archiveHashes];
    } catch (Exception $e) {
        try { $conn->rollBack(); } catch (Exception $e2) {}
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}
