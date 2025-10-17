<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Restrict to super_admin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    // Provide an explicit 403 so admins see why the page is not accessible instead of a silent redirect.
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Access denied</title></head><body>';
    echo '<h1>Access denied</h1>';
    echo '<p>You must be a <strong>super_admin</strong> to view this page. Please login with a super_admin account.</p>';
    echo '<p><a href="../users/login.php">Go to login</a></p>';
    echo '</body></html>';
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$csrf_token = generate_csrf_token();

function h($s)
{
    return htmlentities((string) $s);
}

$errors = [];
$success = '';

// helper: load setting
function get_setting($conn, $k, $default = null)
{
    try {
        $stmt = $conn->prepare('SELECT v FROM app_settings WHERE `k` = ? LIMIT 1');
        $stmt->execute([$k]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : $v;
    } catch (PDOException $e) {
        // Table may not exist yet; return default to avoid fatal error
        return $default;
    }
}

// helper: upsert setting
function set_setting($conn, $k, $v)
{
    try {
        $stmt = $conn->prepare('INSERT INTO app_settings (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`), updated_at = CURRENT_TIMESTAMP');
        return $stmt->execute([$k, $v]);
    } catch (PDOException $e) {
        // Table missing or other DB error; fail gracefully
        return false;
    }
}

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validate
    $tokenOk = verify_csrf_token($_POST['csrf_token'] ?? '');
    if (!$tokenOk) {
        $errors[] = 'Invalid CSRF token.';
    }

    $scheduleAdmin = trim((string) ($_POST['schedule_admin_id'] ?? ''));
    $retentionDays = trim((string) ($_POST['audit_retention_days'] ?? ''));

    // validate retention days
    if ($retentionDays === '') {
        $errors[] = 'Retention days is required.';
    } elseif (!ctype_digit($retentionDays) || intval($retentionDays) < 1 || intval($retentionDays) > 3650) {
        $errors[] = 'Retention days must be a whole number between 1 and 3650.';
    }

    // validate schedule admin if provided
    if ($scheduleAdmin !== '') {
        if (!ctype_digit($scheduleAdmin)) {
            $errors[] = 'SCHEDULE_ADMIN_ID must be a numeric user id.';
        } else {
            $stmt = $conn->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $scheduleAdmin]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                $errors[] = 'Configured SCHEDULE_ADMIN_ID not found in users table.';
            }
        }
    }

    if (empty($errors)) {
        // persist
        set_setting($conn, 'SCHEDULE_ADMIN_ID', $scheduleAdmin === '' ? null : $scheduleAdmin);
        set_setting($conn, 'AUDIT_RETENTION_DAYS', $retentionDays);
        $success = 'Settings saved.';
        // regenerate token to avoid double-post
        $csrf_token = generate_csrf_token();
    }
}

// load current values
$currentScheduleAdmin = get_setting($conn, 'SCHEDULE_ADMIN_ID', '');
$currentRetention = get_setting($conn, 'AUDIT_RETENTION_DAYS', (string) (365 * 3));
// scheduler trigger stored in DB (optional)
$currentTrigger = get_setting($conn, 'SCHEDULE_TRIGGER', '');

?>
<!doctype html>
<html lang="en">

<head>
    <?php $PAGE_TITLE = 'Retention Settings - Admin';
    require_once __DIR__ . '/admin_head.php'; ?>
    <link rel="stylesheet" href="../css/admin_dashboard.css">
    <style>
        .panel {
            background: #fff;
            padding: 16px;
            border: 1px solid #e6e6e6;
            border-radius: 6px;
            max-width: 720px;
        }

        label {
            display: block;
            margin-top: 8px;
        }

        input[type=text],
        input[type=number] {
            padding: 8px;
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .muted {
            color: #666;
            font-size: 12px;
        }

        .btn {
            display: inline-block;
            padding: 8px 12px;
            background: #2d6cdf;
            color: #fff;
            border-radius: 4px;
            text-decoration: none;
            border: 0;
        }

        .danger {
            background: #c0392b;
        }

        .note {
            margin-top: 8px;
            font-size: 13px;
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <h1>Retention Settings</h1>
        <p class="muted">Configure the scheduled admin id used by automated purge jobs and the audit retention period
            (days).</p>

        <div class="panel">
            <?php if (!empty($errors)): ?>
                <div style="color:#c0392b;"><strong>Errors:</strong>
                    <ul><?php foreach ($errors as $e)
                        echo '<li>' . h($e) . '</li>'; ?></ul>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div style="color:#0a0;"><strong><?php echo h($success); ?></strong></div>
            <?php endif; ?>

            <form method="post" action="retention_settings.php">
                <label for="schedule_admin_id">SCHEDULE_ADMIN_ID (user id)</label>
                <input id="schedule_admin_id" name="schedule_admin_id" type="text"
                    value="<?php echo h($currentScheduleAdmin); ?>" placeholder="Leave empty to disable">
                <div class="muted">This user id will be used to record automated archive/delete admin actions. Must be a
                    valid admin user id.</div>

                <label for="audit_retention_days">Audit retention (days)</label>
                <input id="audit_retention_days" name="audit_retention_days" type="number" min="1" max="3650"
                    value="<?php echo h($currentRetention); ?>">
                <div class="muted">Number of days to keep audit rows before automated archival. Limits: 1 - 3650 (10
                    years).</div>

                <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                <div style="margin-top:12px; display:flex; gap:8px; align-items:center;">
                    <button class="btn" type="submit">Save</button>
                    <a class="btn" href="admin_dashboard.php">Back</a>
                </div>
            </form>

            <hr style="margin:18px 0;">
            <h3>Scheduler (read-only)</h3>
            <p class="muted">This shows the optional scheduler trigger stored in `app_settings` under the key
                <code>SCHEDULE_TRIGGER</code>. Editing the OS scheduled task must be done via Task Scheduler or
                PowerShell (instructions below).</p>
            <div style="background:#f7f7f7;padding:10px;border:1px solid #eee;border-radius:4px;">
                <strong>Stored trigger:</strong>
                <div style="margin-top:8px;font-family:monospace;">
                    <?php echo h($currentTrigger === '' ? '(not set)' : $currentTrigger); ?></div>
            </div>

            <h4 style="margin-top:12px;">PowerShell commands (Windows)</h4>
            <p class="muted">Use these from an elevated PowerShell prompt to inspect or register the scheduled task that
                runs the purge script. Adjust paths and task name as needed.</p>
            <pre style="background:#111;color:#dcdcdc;padding:10px;border-radius:6px;overflow:auto;"># View a named task
Get-ScheduledTask -TaskName "rota-weekly-archive" | Format-List *
Get-ScheduledTaskInfo -TaskName "rota-weekly-archive" | Format-List *

# List tasks that look relevant
Get-ScheduledTask | Where-Object {$_.TaskName -match 'rota' -or $_.TaskName -match 'archive'} | Select TaskName, TaskPath

# Register/replace a weekly Sunday 03:00 task (run as SYSTEM)
$php = 'C:\xampp\php\php.exe'
$script = 'C:\xampp\htdocs\rota-app-main\tools\weekly_archive_purge_db.php'
$action = New-ScheduledTaskAction -Execute $php -Argument $script
$trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Sunday -At 3:00AM
Register-ScheduledTask -TaskName 'rota-weekly-archive' -Action $action -Trigger $trigger -User 'SYSTEM' -RunLevel Highest -Force

# To remove the task
Unregister-ScheduledTask -TaskName 'rota-weekly-archive' -Confirm:$false</pre>
        </div>
    </div>
</body>

</html>