<?php
// Weekly archive+delete runner. Intended to be called from Task Scheduler on localhost.
require_once __DIR__ . '/../includes/db.php';
$config = require __DIR__ . '/../config/retention.php';
$days = intval($config['audit_archive_retention_days'] ?? 365*3);
$threshold = (new DateTime())->sub(new DateInterval('P' . $days . 'D'))->format('Y-m-d');
$cliSecret = getenv('SCHEDULE_CLI_SECRET') ?: ($_ENV['SCHEDULE_CLI_SECRET'] ?? null);
if (empty($cliSecret)) {
    echo "SCHEDULE_CLI_SECRET not configured in environment. Exiting.\n";
    exit(1);
}
$payload = json_encode(['criteria'=>['older_than'=>$threshold],'dry_run'=>true,'note'=>'automated weekly purge','mode'=>'archive_delete','allow_all'=>false,'cli_secret'=>$cliSecret]);
$opts = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
        'content' => $payload,
        'timeout' => 30
    ]
];
$ctx = stream_context_create($opts);
$url = 'http://127.0.0.1/rota-app-main/functions/erase_audit.php';
echo "Running dry-run for rows older than $threshold...\n";
$res = @file_get_contents($url, false, $ctx);
if ($res === false) { echo "Request failed\n"; exit(1); }
$j = json_decode($res, true);
if (!$j || empty($j['ok'])) { echo "Dry-run failed: " . ($j['error'] ?? $res) . "\n"; exit(1); }
$affected = intval($j['affected'] ?? 0);
echo "Dry-run affected: $affected\n";
if ($affected <= 0) { echo "Nothing to do.\n"; exit(0); }
// proceed with final run
$payload2 = json_encode(['criteria'=>['older_than'=>$threshold],'dry_run'=>false,'note'=>'automated weekly purge','mode'=>'archive_delete','allow_all'=>false,'cli_secret'=>$cliSecret]);
$opts2 = $opts; $opts2['http']['content'] = $payload2; $ctx2 = stream_context_create($opts2);
$res2 = @file_get_contents($url, false, $ctx2);
if ($res2 === false) { echo "Final request failed\n"; exit(1); }
$j2 = json_decode($res2, true);
if (!$j2 || empty($j2['ok'])) { echo "Final run failed: " . ($j2['error'] ?? $res2) . "\n"; exit(1); }
echo "Final run succeeded. Affected: " . intval($j2['affected']) . " AdminActionID: " . ($j2['archive_admin_action_id'] ?? '') . "\n";
exit(0);
