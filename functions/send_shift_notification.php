<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/push_config.php';
require_once __DIR__ . '/../includes/db.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Send push notification to a user
 * 
 * @param int $user_id User ID to send notification to
 * @param string $title Notification title
 * @param string $body Notification body
 * @param array $data Additional data (url, etc.)
 * @param string $notification_type Type of notification (for preference checking)
 * @return bool Success status
 */
function sendPushNotification($user_id, $title, $body, $data = [], $notification_type = null)
{
    global $conn;

    try {
        // Check if user has push notifications enabled and this specific type enabled
        $prefStmt = $conn->prepare("SELECT push_notifications_enabled, 
                                            notify_shift_assigned, notify_shift_updated, notify_shift_deleted,
                                            notify_shift_invitation, notify_shift_swap 
                                     FROM users WHERE id = ?");
        $prefStmt->execute([$user_id]);
        $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);

        if (!$prefs || !$prefs['push_notifications_enabled']) {
            error_log("Push notifications disabled for user $user_id");
            return false;
        }

        // Check specific notification type preference
        if ($notification_type) {
            $pref_key = "notify_" . $notification_type;
            if (isset($prefs[$pref_key]) && !$prefs[$pref_key]) {
                error_log("Notification type $notification_type disabled for user $user_id");
                return false;
            }
        }

        // Fetch all active subscriptions for this user
        $stmt = $conn->prepare("SELECT * FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subscriptions)) {
            error_log("No push subscriptions found for user $user_id");
            return false;
        }

        // Create WebPush instance with optimized settings
        $auth = [
            'VAPID' => [
                'subject' => VAPID_SUBJECT,
                'publicKey' => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ]
        ];

        // Set shorter timeout to prevent blocking
        $defaultOptions = [
            'TTL' => 300, // 5 minutes
            'urgency' => 'normal',
            'topic' => 'shift-update'
        ];

        $webPush = new WebPush($auth, $defaultOptions, 2); // 2 second timeout

        // Prepare notification payload
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/images/icon.png',
            'badge' => '/images/icon.png',
            'tag' => 'shift-notification-' . time(),
            'data' => array_merge([
                'url' => '/users/dashboard.php',
                'timestamp' => time()
            ], $data)
        ]);

        $success_count = 0;
        $expired_ids = [];

        // Send to all subscriptions
        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh_key'],
                    'auth' => $sub['auth_token']
                ]
            ]);

            // Queue the notification (non-blocking when using flush)
            $webPush->queueNotification($subscription, $payload);
        }

        // Flush all notifications at once (more efficient)
        $reports = $webPush->flush();

        // Process results
        foreach ($reports as $report) {
            if ($report->isSuccess()) {
                $success_count++;
            } elseif ($report->isSubscriptionExpired()) {
                // Find the subscription ID to mark for deletion
                $endpoint = $report->getRequest()->getUri()->__toString();
                foreach ($subscriptions as $sub) {
                    if ($sub['endpoint'] === $endpoint) {
                        $expired_ids[] = $sub['id'];
                        break;
                    }
                }
            }
        }

        // Clean up expired subscriptions
        if (!empty($expired_ids)) {
            $placeholders = implode(',', array_fill(0, count($expired_ids), '?'));
            $deleteStmt = $conn->prepare("DELETE FROM push_subscriptions WHERE id IN ($placeholders)");
            $deleteStmt->execute($expired_ids);
            error_log("Removed " . count($expired_ids) . " expired push subscriptions");
        }

        return $success_count > 0;

    } catch (Exception $e) {
        error_log("Error sending push notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Send notification when a new shift is assigned
 */
function notifyShiftAssigned($user_id, $shift_details)
{
    $title = "New Shift Assigned";
    $body = sprintf(
        "%s on %s at %s",
        $shift_details['role_name'] ?? 'Shift',
        date('D, M j', strtotime($shift_details['shift_date'])),
        date('g:i A', strtotime($shift_details['start_time']))
    );

    $data = [
        'url' => '/users/shifts.php',
        'shift_id' => $shift_details['shift_id'] ?? null
    ];

    return sendPushNotification($user_id, $title, $body, $data, 'shift_assigned');
}

/**
 * Send notification for shift invitation
 */
function notifyShiftInvitation($user_id, $shift_details)
{
    $title = "Shift Invitation";
    $body = sprintf(
        "You've been invited to work %s on %s",
        $shift_details['role_name'] ?? 'a shift',
        date('D, M j', strtotime($shift_details['shift_date']))
    );

    $data = [
        'url' => '/functions/pending_shift_invitations.php',
        'invitation_id' => $shift_details['invitation_id'] ?? null
    ];

    return sendPushNotification($user_id, $title, $body, $data, 'shift_invitation');
}

/**
 * Send notification for shift swap request
 */
function notifyShiftSwapRequest($user_id, $shift_details)
{
    $title = "Shift Swap Request";
    $body = sprintf(
        "%s wants to swap their %s shift on %s",
        $shift_details['requester_name'] ?? 'A colleague',
        $shift_details['role_name'] ?? '',
        date('D, M j', strtotime($shift_details['shift_date']))
    );

    $data = [
        'url' => '/users/coverage_requests.php',
        'swap_id' => $shift_details['swap_id'] ?? null
    ];

    return sendPushNotification($user_id, $title, $body, $data, 'shift_swap');
}

/**
 * Send notification for approved coverage request
 */
function notifyCoverageApproved($user_id, $shift_details)
{
    $title = "Coverage Approved";
    $body = sprintf(
        "Your coverage request for %s on %s has been approved",
        $shift_details['role_name'] ?? 'shift',
        date('D, M j', strtotime($shift_details['shift_date']))
    );

    $data = [
        'url' => '/users/shifts.php'
    ];

    return sendPushNotification($user_id, $title, $body, $data, 'shift_swap');
}

/**
 * Send notification for shift update
 */
function notifyShiftUpdated($user_id, $title, $body, $data = [])
{
    return sendPushNotification($user_id, $title, $body, $data, 'shift_updated');
}

/**
 * Send notification for shift deletion
 */
function notifyShiftDeleted($user_id, $title, $body, $data = [])
{
    return sendPushNotification($user_id, $title, $body, $data, 'shift_deleted');
}
