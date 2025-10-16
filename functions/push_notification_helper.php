<?php
/**
 * Send Push Notification Function
 * Core function to send push notifications to users
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/push_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Send push notification to a user
 * 
 * @param int $user_id User ID to send notification to
 * @param string $title Notification title
 * @param string $body Notification body
 * @param string $url Optional URL to open when clicked
 * @param array $data Optional additional data
 * @return bool Success status
 */
function sendPushNotification($user_id, $title, $body, $url = null, $data = []) {
    global $conn;

    try {
        // Get user's push subscriptions
        $stmt = $conn->prepare("
            SELECT endpoint, p256dh_key, auth_token 
            FROM push_subscriptions 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subscriptions)) {
            error_log("No push subscriptions found for user $user_id");
            return false;
        }

        // Initialize WebPush
        $auth = [
            'VAPID' => [
                'subject' => VAPID_SUBJECT,
                'publicKey' => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ]
        ];

        $webPush = new WebPush($auth);

        // Prepare notification payload
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/images/icon.png',
            'badge' => '/images/icon.png',
            'url' => $url ?? '/users/dashboard.php',
            'data' => $data,
            'timestamp' => time(),
            'requireInteraction' => false,
            'tag' => 'notification-' . time(),
        ]);

        // Send to all user's subscriptions
        $successCount = 0;
        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh_key'],
                    'auth' => $sub['auth_token']
                ]
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        // Send all queued notifications
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $successCount++;
            } else {
                // Handle expired/invalid subscriptions
                if ($report->isSubscriptionExpired()) {
                    error_log("Subscription expired, removing from database");
                    $endpoint = $report->getEndpoint();
                    $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
                    $stmt->execute([$endpoint]);
                } else {
                    error_log("Push notification failed: " . $report->getReason());
                }
            }
        }

        return $successCount > 0;

    } catch (Exception $e) {
        error_log('Error sending push notification: ' . $e->getMessage());
        return false;
    }
}

// Helper functions for specific notification types

function notifyShiftAssignment($user_id, $shift_details) {
    $title = "New Shift Assigned";
    $body = "You have a new shift on " . $shift_details['date'] . " at " . $shift_details['time'];
    $url = "/users/shifts.php";
    
    return sendPushNotification($user_id, $title, $body, $url, [
        'type' => 'shift-assigned',
        'shift_id' => $shift_details['id']
    ]);
}

function notifyShiftSwapRequest($user_id, $requester_name, $request_id) {
    $title = "Shift Swap Request";
    $body = "$requester_name wants to swap shifts with you";
    $url = "/users/coverage_requests.php";
    
    return sendPushNotification($user_id, $title, $body, $url, [
        'type' => 'shift-swap',
        'request_id' => $request_id
    ]);
}

function notifyShiftReminder($user_id, $hours_until, $shift_details) {
    $title = "Upcoming Shift Reminder";
    $body = "You have a shift starting in $hours_until hours";
    $url = "/users/shifts.php";
    
    return sendPushNotification($user_id, $title, $body, $url, [
        'type' => 'shift-reminder',
        'shift_id' => $shift_details['id']
    ]);
}

function notifyScheduleChange($user_id, $change_details) {
    $title = "Schedule Updated";
    $body = $change_details['message'];
    $url = "/users/rota.php";
    
    return sendPushNotification($user_id, $title, $body, $url, [
        'type' => 'schedule-change'
    ]);
}

function notifyShiftInvitation($user_id, $shift_details) {
    $title = "New Shift Invitation";
    $body = "You've been invited to a shift on " . $shift_details['date'];
    $url = "/functions/pending_shift_invitations.php";
    
    return sendPushNotification($user_id, $title, $body, $url, [
        'type' => 'shift-invitation',
        'shift_id' => $shift_details['id']
    ]);
}
?>
