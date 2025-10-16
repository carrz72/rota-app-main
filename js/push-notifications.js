// Push Notifications Manager
(function() {
    'use strict';

    // IMPORTANT: Replace with your actual VAPID public key from push_config.php
    const VAPID_PUBLIC_KEY = 'BMrynh06K7vNvRFfK9WHwJBpXmXSOj08-4T3FXdxGD2S3LrW0HHbxF0XtqOWwp3Vj3XLchLXvKJqS5K6kY6K-fU';

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

        const status = await checkSubscription();
        console.log('Push notification status:', status);

        // Don't show prompt if already granted or denied
        if (status.permission === 'granted' && !status.subscribed) {
            // User granted permission but not subscribed, subscribe them
            try {
                await subscribeToPush();
                console.log('Auto-subscribed user with granted permission');
            } catch (e) {
                console.error('Failed to auto-subscribe:', e);
            }
        } else if (status.permission === 'default') {
            // Check if we should show prompt (not dismissed recently)
            const lastDismissed = localStorage.getItem('notification_prompt_dismissed');
            const sevenDaysAgo = Date.now() - (7 * 24 * 60 * 60 * 1000);
            
            if (!lastDismissed || parseInt(lastDismissed) < sevenDaysAgo) {
                // Show prompt after 30 seconds of browsing
                setTimeout(showNotificationPrompt, 30000);
            }
        }
    }

    // Show a custom prompt before requesting permission
    function showNotificationPrompt() {
        // Don't show if already showing
        if (document.querySelector('.notification-prompt-modal')) {
            return;
        }

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
            } else {
                showErrorMessage('Notification permission denied. You can enable it later in settings.');
            }
        });

        modal.querySelector('.btn-maybe-later').addEventListener('click', () => {
            modal.remove();
            // Remember dismissal for 7 days
            localStorage.setItem('notification_prompt_dismissed', Date.now().toString());
        });
    }

    function showSuccessMessage(message) {
        showToast(message, 'success');
    }

    function showErrorMessage(message) {
        showToast(message, 'error');
    }

    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `notification-toast ${type}`;
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
        isSupported: isPushSupported,
        showPrompt: showNotificationPrompt
    };

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
