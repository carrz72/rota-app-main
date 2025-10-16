<?php
/**
 * Shift Reminder Cron Job
 * Run this script every 15 minutes via cron:
 * Example: * /15 * * * * php /var/www/rota-app/cron_shift_reminders.php
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/functions/send_shift_notification.php';

// Prevent running from web browser
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

echo "[" . date('Y-m-d H:i:s') . "] Starting shift reminder check...\n";

try {
    // Get current time
    $now = new DateTime();

    // ===== 24 HOUR REMINDERS =====
    $in24hours = clone $now;
    $in24hours->modify('+24 hours');

    // Find shifts starting in approximately 24 hours (give 15 min window)
    $windowStart = clone $in24hours;
    $windowStart->modify('-15 minutes');
    $windowEnd = clone $in24hours;
    $windowEnd->modify('+15 minutes');

    echo "Checking for 24h reminders between " . $windowStart->format('Y-m-d H:i:s') . " and " . $windowEnd->format('Y-m-d H:i:s') . "\n";

    $stmt24h = $conn->prepare("
        SELECT s.*, u.id as user_id, u.username, r.name as role_name
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        JOIN roles r ON s.role_id = r.id
        WHERE u.push_notifications_enabled = 1
        AND u.shift_reminder_24h = 1
        AND CONCAT(s.shift_date, ' ', s.start_time) BETWEEN ? AND ?
        AND NOT EXISTS (
            SELECT 1 FROM shift_reminders_sent srs
            WHERE srs.user_id = s.user_id
            AND srs.shift_id = s.id
            AND srs.reminder_type = '24h'
        )
    ");

    $stmt24h->execute([
        $windowStart->format('Y-m-d H:i:s'),
        $windowEnd->format('Y-m-d H:i:s')
    ]);

    $shifts24h = $stmt24h->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($shifts24h) . " shifts for 24h reminders\n";

    foreach ($shifts24h as $shift) {
        $title = "Shift Reminder - Tomorrow";
        $body = sprintf(
            "You have a %s shift tomorrow at %s",
            $shift['role_name'],
            date('g:i A', strtotime($shift['start_time']))
        );

        $data = [
            'url' => '/users/shifts.php',
            'shift_id' => $shift['id']
        ];

        if (sendPushNotification($shift['user_id'], $title, $body, $data)) {
            // Mark as sent
            $markSent = $conn->prepare("
                INSERT INTO shift_reminders_sent (user_id, shift_id, reminder_type)
                VALUES (?, ?, '24h')
            ");
            $markSent->execute([$shift['user_id'], $shift['id']]);
            echo "  ✓ Sent 24h reminder to {$shift['username']} for shift #{$shift['id']}\n";
        } else {
            echo "  ✗ Failed to send 24h reminder to {$shift['username']}\n";
        }
    }

    // ===== 1 HOUR REMINDERS =====
    $in1hour = clone $now;
    $in1hour->modify('+1 hour');

    // Find shifts starting in approximately 1 hour (give 10 min window)
    $windowStart1h = clone $in1hour;
    $windowStart1h->modify('-10 minutes');
    $windowEnd1h = clone $in1hour;
    $windowEnd1h->modify('+10 minutes');

    echo "\nChecking for 1h reminders between " . $windowStart1h->format('Y-m-d H:i:s') . " and " . $windowEnd1h->format('Y-m-d H:i:s') . "\n";

    $stmt1h = $conn->prepare("
        SELECT s.*, u.id as user_id, u.username, r.name as role_name
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        JOIN roles r ON s.role_id = r.id
        WHERE u.push_notifications_enabled = 1
        AND u.shift_reminder_1h = 1
        AND CONCAT(s.shift_date, ' ', s.start_time) BETWEEN ? AND ?
        AND NOT EXISTS (
            SELECT 1 FROM shift_reminders_sent srs
            WHERE srs.user_id = s.user_id
            AND srs.shift_id = s.id
            AND srs.reminder_type = '1h'
        )
    ");

    $stmt1h->execute([
        $windowStart1h->format('Y-m-d H:i:s'),
        $windowEnd1h->format('Y-m-d H:i:s')
    ]);

    $shifts1h = $stmt1h->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($shifts1h) . " shifts for 1h reminders\n";

    foreach ($shifts1h as $shift) {
        $title = "Shift Starting Soon!";
        $body = sprintf(
            "Your %s shift starts in 1 hour (%s at %s)",
            $shift['role_name'],
            $shift['location'] ?? '',
            date('g:i A', strtotime($shift['start_time']))
        );

        $data = [
            'url' => '/users/shifts.php',
            'shift_id' => $shift['id']
        ];

        if (sendPushNotification($shift['user_id'], $title, $body, $data)) {
            // Mark as sent
            $markSent = $conn->prepare("
                INSERT INTO shift_reminders_sent (user_id, shift_id, reminder_type)
                VALUES (?, ?, '1h')
            ");
            $markSent->execute([$shift['user_id'], $shift['id']]);
            echo "  ✓ Sent 1h reminder to {$shift['username']} for shift #{$shift['id']}\n";
        } else {
            echo "  ✗ Failed to send 1h reminder to {$shift['username']}\n";
        }
    }

    // ===== CUSTOM REMINDERS =====
    echo "\nChecking for custom reminders...\n";

    // Get all enabled custom reminder preferences
    $customPrefsStmt = $conn->prepare("
        SELECT * FROM shift_reminder_preferences 
        WHERE enabled = 1
    ");
    $customPrefsStmt->execute();
    $customPrefs = $customPrefsStmt->fetchAll(PDO::FETCH_ASSOC);

    $customRemindersSent = 0;

    foreach ($customPrefs as $pref) {
        // Calculate the time range for this reminder
        $targetTime = clone $now;

        switch ($pref['reminder_type']) {
            case 'minutes':
                $targetTime->modify('+' . $pref['reminder_value'] . ' minutes');
                $windowMinutes = 5; // 5 minute window for minute-based reminders
                break;
            case 'hours':
                $targetTime->modify('+' . $pref['reminder_value'] . ' hours');
                $windowMinutes = 10; // 10 minute window for hour-based reminders
                break;
            case 'days':
                $targetTime->modify('+' . $pref['reminder_value'] . ' days');
                $windowMinutes = 15; // 15 minute window for day-based reminders
                break;
        }

        $windowStartCustom = clone $targetTime;
        $windowStartCustom->modify('-' . $windowMinutes . ' minutes');
        $windowEndCustom = clone $targetTime;
        $windowEndCustom->modify('+' . $windowMinutes . ' minutes');

        // Create a unique reminder type identifier
        $reminderTypeId = 'custom_' . $pref['id'];

        // Find shifts for this user in this time window
        $customShiftsStmt = $conn->prepare("
            SELECT s.*, u.username, r.name as role_name
            FROM shifts s
            JOIN users u ON s.user_id = u.id
            JOIN roles r ON s.role_id = r.id
            WHERE u.id = ?
            AND u.push_notifications_enabled = 1
            AND CONCAT(s.shift_date, ' ', s.start_time) BETWEEN ? AND ?
            AND NOT EXISTS (
                SELECT 1 FROM shift_reminders_sent srs
                WHERE srs.user_id = s.user_id
                AND srs.shift_id = s.id
                AND srs.reminder_type = ?
            )
        ");

        $customShiftsStmt->execute([
            $pref['user_id'],
            $windowStartCustom->format('Y-m-d H:i:s'),
            $windowEndCustom->format('Y-m-d H:i:s'),
            $reminderTypeId
        ]);

        $customShifts = $customShiftsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($customShifts as $shift) {
            // Format the reminder text
            $timeText = $pref['reminder_value'] . ' ' . $pref['reminder_type'];
            if ($pref['reminder_value'] == 1) {
                // Singular form
                $timeText = rtrim($timeText, 's');
            }

            $title = "Shift Reminder";
            $body = sprintf(
                "Your %s shift starts in %s (%s at %s)",
                $shift['role_name'],
                $timeText,
                $shift['location'] ?? '',
                date('g:i A', strtotime($shift['start_time']))
            );

            $data = [
                'url' => '/users/shifts.php',
                'shift_id' => $shift['id']
            ];

            if (sendPushNotification($pref['user_id'], $title, $body, $data)) {
                // Mark as sent
                $markSent = $conn->prepare("
                    INSERT INTO shift_reminders_sent (user_id, shift_id, reminder_type)
                    VALUES (?, ?, ?)
                ");
                $markSent->execute([$pref['user_id'], $shift['id'], $reminderTypeId]);
                echo "  ✓ Sent custom reminder ({$timeText}) to {$shift['username']} for shift #{$shift['id']}\n";
                $customRemindersSent++;
            } else {
                echo "  ✗ Failed to send custom reminder to {$shift['username']}\n";
            }
        }
    }

    if ($customRemindersSent > 0) {
        echo "Sent $customRemindersSent custom reminders\n";
    }

    // Clean up old reminder records (older than 7 days)
    $cleanupStmt = $conn->prepare("DELETE FROM shift_reminders_sent WHERE sent_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $deletedRows = $cleanupStmt->execute() ? $cleanupStmt->rowCount() : 0;
    if ($deletedRows > 0) {
        echo "\nCleaned up $deletedRows old reminder records\n";
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Shift reminder check completed\n";
    echo "Summary: Sent " . count($shifts24h) . " x 24h, " . count($shifts1h) . " x 1h, $customRemindersSent x custom reminders\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Shift reminder cron error: " . $e->getMessage());
    exit(1);
}
