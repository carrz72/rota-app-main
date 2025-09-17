<?php
// CLI runner that invokes archive_and_delete_by_criteria directly (no HTTP).
require_once __DIR__ . '/../includes/db.php';
$loggerPath = __DIR__ . '/../includes/logger.php'; if (file_exists($loggerPath)) require_once $loggerPath;
require_once __DIR__ . '/../includes/audit_maintenance.php';
$config = require __DIR__ . '/../config/retention.php';
$days = intval($config['audit_archive_retention_days'] ?? 365*3);
$threshold = (new DateTime())->sub(new DateInterval('P' . $days . 'D'))->format('Y-m-d');
$adminId = getenv('SCHEDULE_ADMIN_ID') ?: ($_ENV['SCHEDULE_ADMIN_ID'] ?? null);
// If not set in env, try app_settings table
if (!$adminId) {
	try {
		$s = $conn->prepare('SELECT v FROM app_settings WHERE `k` = ? LIMIT 1');
		$s->execute(['SCHEDULE_ADMIN_ID']);
		$val = $s->fetchColumn();
		if ($val !== false && $val !== null && $val !== '') {
			$adminId = $val;
		}
	} catch (Exception $e) {
		rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'Failed to read app_settings for SCHEDULE_ADMIN_ID: ' . $e->getMessage());
	}
}

if (!$adminId) { rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'SCHEDULE_ADMIN_ID not set (env or db). Exiting.'); exit(1); }
// validate admin exists
$stmt = $conn->prepare('SELECT id FROM users WHERE id = ?'); $stmt->execute([(int)$adminId]); if (!$stmt->fetchColumn()) { rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'Configured SCHEDULE_ADMIN_ID not found in users table (id=' . intval($adminId) . ').'); exit(1); }
$criteria = ['older_than' => $threshold];
rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', "Dry-run for rows older than $threshold");
$res = archive_and_delete_by_criteria($conn, $criteria, 'automated weekly purge', (int)$adminId, 1, 1, true);
if (empty($res['ok'])) { rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'Dry-run failed: ' . ($res['error'] ?? 'unknown')); exit(1); }
rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'Dry-run affected: ' . intval($res['affected']));
if (intval($res['affected']) <= 0) { rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'Nothing to do.'); exit(0); }
// run final
$res2 = archive_and_delete_by_criteria($conn, $criteria, 'automated weekly purge', (int)$adminId, 1, 1, false);
if (empty($res2['ok'])) { rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'Final run failed: ' . ($res2['error'] ?? 'unknown')); exit(1); }
rota_log_rotate(__DIR__ . '/../secure/logs', 'weekly_archive', 'Final run succeeded. Affected: ' . intval($res2['affected']) . ' AdminActionID: ' . ($res2['archive_admin_action_id'] ?? ''));
exit(0);

