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

    // Cron interval in minutes - adjust if your cron runs more/less frequently
    $CRON_INTERVAL_MINUTES = 15;

    // ===== 24 HOUR REMINDERS =====
    $in24hours = clone $now;
    $in24hours->modify('+24 hours');

    // Find shifts starting in approximately 24 hours.
    // Use a forward-looking window [target, target + cron interval] so we don't send earlier than 24h before a shift.
    $windowStart = clone $in24hours;
    $windowEnd = clone $in24hours;
    $windowEnd->modify('+' . $CRON_INTERVAL_MINUTES . ' minutes');

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
            // Mark as sent (don't let a DB insert error stop the whole run)
            try {
                $markSent = $conn->prepare("
                    INSERT INTO shift_reminders_sent (user_id, shift_id, reminder_type)
                    VALUES (?, ?, '24h')
                ");
                $markSent->execute([$shift['user_id'], $shift['id']]);
                echo "  ✓ Sent 24h reminder to {$shift['username']} for shift #{$shift['id']}\n";
            } catch (Exception $e) {
                echo "  ⚠ Could not mark 24h reminder as sent for {$shift['username']} (shift #{$shift['id']}): " . $e->getMessage() . "\n";
                error_log("Failed to insert shift_reminders_sent for 24h: " . $e->getMessage());
            }
        } else {
            echo "  ✗ Failed to send 24h reminder to {$shift['username']}\n";
        }
    }

    // ===== 1 HOUR REMINDERS =====
    $in1hour = clone $now;
    $in1hour->modify('+1 hour');

    // Find shifts starting in approximately 1 hour.
    // Use a forward-looking window [target, target + cron interval] so we don't send earlier than 1 hour before a shift.
    $windowStart1h = clone $in1hour;
    $windowEnd1h = clone $in1hour;
    $windowEnd1h->modify('+' . $CRON_INTERVAL_MINUTES . ' minutes');

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
            // Mark as sent (don't let a DB insert error stop the whole run)
            try {
                $markSent = $conn->prepare("
                    INSERT INTO shift_reminders_sent (user_id, shift_id, reminder_type)
                    VALUES (?, ?, '1h')
                ");
                $markSent->execute([$shift['user_id'], $shift['id']]);
                echo "  ✓ Sent 1h reminder to {$shift['username']} for shift #{$shift['id']}\n";
            } catch (Exception $e) {
                echo "  ⚠ Could not mark 1h reminder as sent for {$shift['username']} (shift #{$shift['id']}): " . $e->getMessage() . "\n";
                error_log("Failed to insert shift_reminders_sent for 1h: " . $e->getMessage());
            }
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
                break;
            case 'hours':
                $targetTime->modify('+' . $pref['reminder_value'] . ' hours');
                break;
            case 'days':
                $targetTime->modify('+' . $pref['reminder_value'] . ' days');
                break;
        }

        // Use a forward-looking window [targetTime, targetTime + cron interval].
        // This guarantees we won't send the custom reminder earlier than the configured time.
        $windowStartCustom = clone $targetTime;
        $windowEndCustom = clone $targetTime;
        $windowEndCustom->modify('+' . $CRON_INTERVAL_MINUTES . ' minutes');

        // Debug output: show calculation for this preference so we can trace why reminders aren't matching
        echo "Custom pref #{$pref['id']} for user {$pref['user_id']}: type={$pref['reminder_type']} value={$pref['reminder_value']} -> looking for shifts between " . $windowStartCustom->format('Y-m-d H:i:s') . " and " . $windowEndCustom->format('Y-m-d H:i:s') . "\n";

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

        // Debug: report how many shifts matched for this pref
        echo "  Found " . count($customShifts) . " matching shifts for pref #{$pref['id']} (user {$pref['user_id']})\n";

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
