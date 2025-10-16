# 🔔 Push Notifications Implementation Guide for Open Rota

## Overview
Push notifications allow your PWA to send real-time alerts to users even when the app is closed. This is perfect for:
- New shift assignments
- Shift swap requests
- Schedule changes
- Shift reminders (24h before)
- Coverage request alerts

---

## 📋 What You Need

### 1. **Backend Requirements**
- PHP server with database
- Web Push library (`web-push-php`)
- VAPID keys (Voluntary Application Server Identification)
- Database table for push subscriptions

### 2. **Frontend Requirements**
- Service Worker (already have ✅)
- Push notification permission request
- Subscription management
- Notification click handling

### 3. **Browser Support**
✅ Chrome 50+ (Desktop & Android)
✅ Firefox 44+
✅ Edge 17+
✅ Safari 16+ (iOS 16.4+, macOS 13+)
✅ Opera 39+

---

## 🚀 Implementation Steps

### Step 1: Install Web Push Library

```bash
composer require minishlink/web-push
```

### Step 2: Generate VAPID Keys

Create a file: `generate_vapid_keys.php`

```php
<?php
require_once 'vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "Public Key (add to your JavaScript):\n";
echo $keys['publicKey'] . "\n\n";
echo "Private Key (keep secret on server):\n";
echo $keys['privateKey'] . "\n\n";
echo "Store these keys in a secure config file!";
```

Run once: `php generate_vapid_keys.php`

### Step 3: Create Database Table

```sql
CREATE TABLE push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_subscription (user_id, endpoint(255))
);
```

### Step 4: Create Config File

Create: `includes/push_config.php`

```php
<?php
// NEVER commit these keys to version control!
// Store in environment variables or secure config

define('VAPID_PUBLIC_KEY', 'YOUR_PUBLIC_KEY_HERE');
define('VAPID_PRIVATE_KEY', 'YOUR_PRIVATE_KEY_HERE');
define('VAPID_SUBJECT', 'mailto:your-email@example.com'); // or your website URL
```

### Step 5: Frontend - Request Permission

Create: `js/push-notifications.js`

```javascript
// Push Notifications Manager
(function() {
    'use strict';

    const VAPID_PUBLIC_KEY = 'YOUR_PUBLIC_KEY_HERE'; // Same as in push_config.php

    // Convert VAPID key from base64 to Uint8Array
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/\-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    // Check if push notifications are supported
    function isPushSupported() {
        return 'serviceWorker' in navigator && 
               'PushManager' in window && 
               'Notification' in window;
    }

    // Request notification permission
    async function requestPermission() {
        if (!isPushSupported()) {
            console.log('Push notifications not supported');
            return false;
        }

        try {
            const permission = await Notification.requestPermission();
            return permission === 'granted';
        } catch (error) {
            console.error('Error requesting permission:', error);
            return false;
        }
    }

    // Subscribe to push notifications
    async function subscribeToPush() {
        try {
            const registration = await navigator.serviceWorker.ready;
            
            // Check if already subscribed
            let subscription = await registration.pushManager.getSubscription();
            
            if (!subscription) {
                // Create new subscription
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
                });
            }

            // Send subscription to server
            await saveSubscription(subscription);
            
            return subscription;
        } catch (error) {
            console.error('Error subscribing to push:', error);
            throw error;
        }
    }

    // Save subscription to server
    async function saveSubscription(subscription) {
        const response = await fetch('../functions/save_push_subscription.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(subscription)
        });

        if (!response.ok) {
            throw new Error('Failed to save subscription');
        }

        return response.json();
    }

    // Unsubscribe from push notifications
    async function unsubscribeFromPush() {
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            
            if (subscription) {
                await subscription.unsubscribe();
                await deleteSubscription(subscription);
            }
        } catch (error) {
            console.error('Error unsubscribing:', error);
        }
    }

    // Delete subscription from server
    async function deleteSubscription(subscription) {
        const response = await fetch('../functions/delete_push_subscription.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(subscription)
        });

        return response.json();
    }

    // Check current subscription status
    async function checkSubscription() {
        if (!isPushSupported()) {
            return { supported: false, subscribed: false };
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            const permission = Notification.permission;

            return {
                supported: true,
                subscribed: !!subscription,
                permission: permission
            };
        } catch (error) {
            console.error('Error checking subscription:', error);
            return { supported: true, subscribed: false, permission: 'default' };
        }
    }

    // Initialize push notifications
    async function init() {
        if (!isPushSupported()) {
            console.log('Push notifications not supported on this device');
            return;
        }

        // Check if user is logged in (check for user_id in session)
        // You might want to add logic here to only enable for logged-in users

        const status = await checkSubscription();
        console.log('Push notification status:', status);

        // Show notification prompt after user has been on site for a bit
        // Don't prompt immediately - wait for user engagement
        if (status.permission === 'default') {
            setTimeout(showNotificationPrompt, 30000); // After 30 seconds
        }
    }

    // Show a custom prompt before requesting permission
    function showNotificationPrompt() {
        // Create a nice modal asking if they want notifications
        const modal = document.createElement('div');
        modal.className = 'notification-prompt-modal';
        modal.innerHTML = `
            <div class="notification-prompt-content">
                <div class="notification-prompt-icon">🔔</div>
                <h3>Stay Updated!</h3>
                <p>Get instant notifications about:</p>
                <ul>
                    <li>📅 New shift assignments</li>
                    <li>🔄 Shift swap requests</li>
                    <li>⏰ Upcoming shift reminders</li>
                    <li>📢 Schedule changes</li>
                </ul>
                <div class="notification-prompt-buttons">
                    <button class="btn-enable-notifications">Enable Notifications</button>
                    <button class="btn-maybe-later">Maybe Later</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Handle button clicks
        modal.querySelector('.btn-enable-notifications').addEventListener('click', async () => {
            modal.remove();
            const granted = await requestPermission();
            if (granted) {
                await subscribeToPush();
                showSuccessMessage('Notifications enabled! You\'ll now receive updates.');
            }
        });

        modal.querySelector('.btn-maybe-later').addEventListener('click', () => {
            modal.remove();
            // Ask again in 7 days
            localStorage.setItem('notification_prompt_dismissed', Date.now());
        });
    }

    function showSuccessMessage(message) {
        // Show a temporary success message
        const toast = document.createElement('div');
        toast.className = 'notification-toast success';
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('show');
        }, 100);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Make functions available globally
    window.PushNotifications = {
        init,
        requestPermission,
        subscribe: subscribeToPush,
        unsubscribe: unsubscribeFromPush,
        checkStatus: checkSubscription,
        isSupported: isPushSupported
    };

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
```

### Step 6: Backend - Save Subscription

Create: `functions/save_push_subscription.php`

```php
<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/push_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['endpoint']) || !isset($input['keys'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid subscription data']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $endpoint = $input['endpoint'];
    $p256dh = $input['keys']['p256dh'];
    $auth = $input['keys']['auth'];

    // Insert or update subscription
    $stmt = $conn->prepare("
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_token)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            p256dh_key = VALUES(p256dh_key),
            auth_token = VALUES(auth_token),
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $stmt->execute([$user_id, $endpoint, $p256dh, $auth]);

    echo json_encode([
        'success' => true,
        'message' => 'Subscription saved successfully'
    ]);

} catch (Exception $e) {
    error_log('Error saving push subscription: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save subscription']);
}
```

### Step 7: Backend - Send Notification

Create: `functions/send_push_notification.php`

```php
<?php
require_once '../includes/db.php';
require_once '../includes/push_config.php';
require_once '../vendor/autoload.php';

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
            'requireInteraction' => false, // Auto-dismiss after a while
            'tag' => 'notification-' . time(), // Group similar notifications
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
                }
            }
        }

        return $successCount > 0;

    } catch (Exception $e) {
        error_log('Error sending push notification: ' . $e->getMessage());
        return false;
    }
}

// Example usage in your existing notification system
function notifyShiftAssignment($user_id, $shift_details) {
    $title = "New Shift Assigned";
    $body = "You have a new shift on " . $shift_details['date'] . " at " . $shift_details['time'];
    $url = "/users/shifts.php";
    
    sendPushNotification($user_id, $title, $body, $url, [
        'type' => 'shift-assigned',
        'shift_id' => $shift_details['id']
    ]);
}

function notifyShiftSwapRequest($user_id, $requester_name) {
    $title = "Shift Swap Request";
    $body = "$requester_name wants to swap shifts with you";
    $url = "/users/coverage_requests.php";
    
    sendPushNotification($user_id, $title, $body, $url, [
        'type' => 'shift-swap'
    ]);
}

function notifyShiftReminder($user_id, $hours_until) {
    $title = "Upcoming Shift Reminder";
    $body = "You have a shift starting in $hours_until hours";
    $url = "/users/shifts.php";
    
    sendPushNotification($user_id, $title, $body, $url, [
        'type' => 'shift-reminder'
    ]);
}
```

### Step 8: Service Worker - Handle Notifications

Update `service-worker.js` to add:

```javascript
// Listen for push events
self.addEventListener('push', event => {
    console.log('[ServiceWorker] Push received');

    let data = {
        title: 'Open Rota',
        body: 'You have a new notification',
        icon: '/images/icon.png',
        badge: '/images/icon.png',
        url: '/users/dashboard.php'
    };

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            console.error('Error parsing push data:', e);
        }
    }

    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        tag: data.tag || 'notification',
        requireInteraction: data.requireInteraction || false,
        data: {
            url: data.url
        },
        actions: [
            {
                action: 'view',
                title: 'View',
                icon: '/images/icon.png'
            },
            {
                action: 'close',
                title: 'Close'
            }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// Handle notification clicks
self.addEventListener('notificationclick', event => {
    console.log('[ServiceWorker] Notification clicked');

    event.notification.close();

    if (event.action === 'close') {
        return;
    }

    // Get the URL from notification data
    const urlToOpen = event.notification.data.url || '/users/dashboard.php';

    event.waitUntil(
        clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        })
        .then(windowClients => {
            // Check if there's already a window open
            for (let client of windowClients) {
                if (client.url === urlToOpen && 'focus' in client) {
                    return client.focus();
                }
            }
            // If not, open new window
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});
```

### Step 9: Add CSS for Notification Prompt

Add to `css/navigation.css`:

```css
/* Notification Prompt Modal */
.notification-prompt-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    animation: fadeIn 0.3s ease;
}

.notification-prompt-content {
    background: white;
    border-radius: 16px;
    padding: 30px;
    max-width: 400px;
    width: 90%;
    text-align: center;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
}

.notification-prompt-icon {
    font-size: 64px;
    margin-bottom: 20px;
}

.notification-prompt-content h3 {
    color: #333;
    font-size: 24px;
    margin-bottom: 15px;
}

.notification-prompt-content p {
    color: #666;
    margin-bottom: 15px;
}

.notification-prompt-content ul {
    list-style: none;
    padding: 0;
    margin: 20px 0;
    text-align: left;
}

.notification-prompt-content ul li {
    padding: 8px 0;
    color: #444;
}

.notification-prompt-buttons {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.notification-prompt-buttons button {
    flex: 1;
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-enable-notifications {
    background: #fd2b2b;
    color: white;
}

.btn-enable-notifications:hover {
    background: #c82333;
    transform: translateY(-2px);
}

.btn-maybe-later {
    background: #f0f0f0;
    color: #666;
}

.btn-maybe-later:hover {
    background: #e0e0e0;
}

/* Toast Notification */
.notification-toast {
    position: fixed;
    bottom: -100px;
    left: 50%;
    transform: translateX(-50%);
    background: #333;
    color: white;
    padding: 15px 25px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    transition: bottom 0.3s ease;
    z-index: 10001;
}

.notification-toast.show {
    bottom: 30px;
}

.notification-toast.success {
    background: #28a745;
}
```

### Step 10: Add to Settings Page

Create a notification settings section in `users/settings.php`:

```php
<div class="settings-section">
    <h3>🔔 Push Notifications</h3>
    <div class="notification-settings">
        <div class="setting-item">
            <div>
                <strong>Browser Notifications</strong>
                <p>Get instant alerts for shifts and updates</p>
            </div>
            <button id="toggle-notifications" class="btn-toggle">
                <span id="notification-status">Loading...</span>
            </button>
        </div>
        
        <div class="notification-types" id="notification-types" style="display:none;">
            <label>
                <input type="checkbox" checked> New shift assignments
            </label>
            <label>
                <input type="checkbox" checked> Shift swap requests
            </label>
            <label>
                <input type="checkbox" checked> Schedule changes
            </label>
            <label>
                <input type="checkbox" checked> Shift reminders (24h before)
            </label>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', async function() {
    const toggleBtn = document.getElementById('toggle-notifications');
    const statusText = document.getElementById('notification-status');
    const typesDiv = document.getElementById('notification-types');

    // Check current status
    const status = await window.PushNotifications.checkStatus();
    
    if (!status.supported) {
        statusText.textContent = 'Not Supported';
        toggleBtn.disabled = true;
        return;
    }

    if (status.subscribed) {
        statusText.textContent = 'Enabled';
        typesDiv.style.display = 'block';
    } else {
        statusText.textContent = 'Disabled';
    }

    toggleBtn.addEventListener('click', async function() {
        if (status.subscribed) {
            // Unsubscribe
            await window.PushNotifications.unsubscribe();
            statusText.textContent = 'Disabled';
            typesDiv.style.display = 'none';
        } else {
            // Subscribe
            const granted = await window.PushNotifications.requestPermission();
            if (granted) {
                await window.PushNotifications.subscribe();
                statusText.textContent = 'Enabled';
                typesDiv.style.display = 'block';
            }
        }
    });
});
</script>
```

---

## 🔒 Security Best Practices

1. **VAPID Keys**: Never commit to version control
2. **User Permission**: Always get explicit consent
3. **Rate Limiting**: Don't spam notifications
4. **Validation**: Validate all subscription data
5. **HTTPS Only**: Push notifications require HTTPS

---

## 📊 Testing

1. **Test Notification Button** in settings
2. **Test New Shift Assignment** notification
3. **Test on Different Devices**:
   - Chrome Desktop
   - Chrome Android
   - Safari iOS (16.4+)
   - Firefox

---

## 💡 Best Practices

1. **Timing**: Don't ask for permission immediately
2. **Value**: Explain what notifications they'll get
3. **Frequency**: Don't over-notify (max 2-3 per day)
4. **Relevance**: Only send important updates
5. **Actionable**: Include actions in notifications

---

## 📈 Analytics

Track notification metrics:
- Permission grant rate
- Click-through rate
- Opt-out rate
- Most useful notification types

---

This is a complete, production-ready push notification system! Would you like me to help implement any specific part of this?
