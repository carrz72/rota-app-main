/**
 * Enhanced Session Timeout Manager
 * Provides client-side session timeout warnings and prevents 404 errors
 */

class SessionTimeoutManager {
    constructor(options = {}) {
        this.warningTime = options.warningTime || 10 * 60 * 1000; // 10 minutes before timeout
        this.checkInterval = options.checkInterval || 60 * 1000; // Check every minute
        this.sessionTimeout = options.sessionTimeout || 120 * 60 * 1000; // 2 hours total
        this.loginUrl = options.loginUrl || '../functions/login.php';
        this.warningShown = false;
        this.lastActivity = Date.now();
        this.sessionValid = true;
        this.checkingSession = false;

        this.init();
    }

    init() {
        // Track user activity
        this.trackActivity();

        // Start checking session timeout
        this.startTimeoutCheck();

        // Create warning modal
        this.createWarningModal();

        // Handle page visibility changes (when user returns to tab)
        this.handleVisibilityChange();

        // Check session on page load
        this.checkServerSession();

        // Handle browser navigation
        this.handleNavigation();
    }

    trackActivity() {
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];

        events.forEach(event => {
            document.addEventListener(event, () => {
                this.lastActivity = Date.now();
                this.hideWarning();

                // Extend session on server side (with debouncing)
                this.extendSession();
            }, { passive: true });
        });

        // Track AJAX requests and form submissions
        this.trackAjaxActivity();
    }

    trackAjaxActivity() {
        // Override XMLHttpRequest to track AJAX activity
        const originalOpen = XMLHttpRequest.prototype.open;
        const self = this;

        XMLHttpRequest.prototype.open = function () {
            this.addEventListener('load', function () {
                if (this.status === 401 || this.status === 403) {
                    self.handleSessionExpired();
                } else {
                    self.lastActivity = Date.now();
                }
            });

            this.addEventListener('error', function () {
                // Check if error is due to session expiration
                self.checkServerSession();
            });

            return originalOpen.apply(this, arguments);
        };

        // Override fetch if available
        if (window.fetch) {
            const originalFetch = window.fetch;
            window.fetch = function () {
                return originalFetch.apply(this, arguments)
                    .then(response => {
                        if (response.status === 401 || response.status === 403) {
                            self.handleSessionExpired();
                        } else {
                            self.lastActivity = Date.now();
                        }
                        return response;
                    })
                    .catch(error => {
                        self.checkServerSession();
                        throw error;
                    });
            };
        }
    }

    extendSession() {
        // Debounce session extension calls
        if (this.extendTimer) {
            clearTimeout(this.extendTimer);
        }

        this.extendTimer = setTimeout(() => {
            fetch('../functions/extend_session.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                }
            }).catch(error => {
                console.log('Session extension failed:', error);
            });
        }, 5000); // Wait 5 seconds before extending
    }

    startTimeoutCheck() {
        setInterval(() => {
            const timeElapsed = Date.now() - this.lastActivity;
            const timeRemaining = this.sessionTimeout - timeElapsed;

            if (timeRemaining <= 0) {
                // Session has timed out
                this.handleTimeout();
            } else if (timeRemaining <= this.warningTime && !this.warningShown) {
                // Show warning
                this.showWarning(Math.floor(timeRemaining / 1000 / 60)); // Minutes remaining
            }
        }, this.checkInterval);
    }

    createWarningModal() {
        const modalHTML = `
            <div id="session-timeout-modal" class="session-modal" style="display: none;">
                <div class="session-modal-content">
                    <div class="session-modal-header">
                        <i class="fas fa-clock"></i>
                        <h3>Session Timeout Warning</h3>
                    </div>
                    <div class="session-modal-body">
                        <p>Your session will expire in <span id="timeout-countdown"></span> minutes due to inactivity.</p>
                        <p>Would you like to extend your session?</p>
                    </div>
                    <div class="session-modal-footer">
                        <button id="extend-session-btn" class="btn btn-primary">Stay Logged In</button>
                        <button id="logout-btn" class="btn btn-secondary">Logout Now</button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Add event listeners
        document.getElementById('extend-session-btn').addEventListener('click', () => {
            this.extendSessionManually();
        });

        document.getElementById('logout-btn').addEventListener('click', () => {
            this.logout();
        });

        // Add styles if not already present
        if (!document.getElementById('session-timeout-styles')) {
            this.addStyles();
        }
    }

    addStyles() {
        const styles = `
            <style id="session-timeout-styles">
                .session-modal {
                    position: fixed;
                    z-index: 10000;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0, 0, 0, 0.5);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                }
                
                .session-modal-content {
                    background-color: white;
                    border-radius: 10px;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
                    max-width: 400px;
                    width: 90%;
                    animation: modalFadeIn 0.3s ease;
                }
                
                @keyframes modalFadeIn {
                    from { opacity: 0; transform: scale(0.8); }
                    to { opacity: 1; transform: scale(1); }
                }
                
                .session-modal-header {
                    background-color: #ff8c00;
                    color: white;
                    padding: 20px;
                    border-radius: 10px 10px 0 0;
                    text-align: center;
                }
                
                .session-modal-header i {
                    font-size: 24px;
                    margin-bottom: 10px;
                    display: block;
                }
                
                .session-modal-header h3 {
                    margin: 0;
                    font-size: 18px;
                }
                
                .session-modal-body {
                    padding: 20px;
                    text-align: center;
                    line-height: 1.5;
                }
                
                .session-modal-footer {
                    padding: 20px;
                    display: flex;
                    justify-content: center;
                    gap: 10px;
                }
                
                .session-modal .btn {
                    padding: 10px 20px;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 14px;
                    transition: background-color 0.3s;
                }
                
                .session-modal .btn-primary {
                    background-color: #fd2b2b;
                    color: white;
                }
                
                .session-modal .btn-primary:hover {
                    background-color: #e61919;
                }
                
                .session-modal .btn-secondary {
                    background-color: #6c757d;
                    color: white;
                }
                
                .session-modal .btn-secondary:hover {
                    background-color: #5a6268;
                }
                
                #timeout-countdown {
                    font-weight: bold;
                    color: #fd2b2b;
                }
            </style>
        `;

        document.head.insertAdjacentHTML('beforeend', styles);
    }

    showWarning(minutesRemaining) {
        this.warningShown = true;
        const modal = document.getElementById('session-timeout-modal');
        const countdown = document.getElementById('timeout-countdown');

        countdown.textContent = minutesRemaining;
        modal.style.display = 'flex';

        // Update countdown every minute
        this.countdownTimer = setInterval(() => {
            minutesRemaining--;
            countdown.textContent = minutesRemaining;

            if (minutesRemaining <= 0) {
                clearInterval(this.countdownTimer);
                this.handleTimeout();
            }
        }, 60000);
    }

    hideWarning() {
        if (this.warningShown) {
            this.warningShown = false;
            const modal = document.getElementById('session-timeout-modal');
            modal.style.display = 'none';

            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
            }
        }
    }

    extendSessionManually() {
        this.lastActivity = Date.now();
        this.hideWarning();
        this.extendSession();

        // Show brief confirmation
        this.showNotification('Session extended successfully!', 'success');
    }

    handleTimeout() {
        // Clear any timers
        if (this.countdownTimer) {
            clearInterval(this.countdownTimer);
        }

        // Show timeout message and redirect
        this.showNotification('Session expired. Redirecting to login...', 'warning');

        setTimeout(() => {
            this.redirectToLogin();
        }, 2000);
    }

    handleSessionExpired() {
        this.sessionValid = false;
        this.hideWarning();
        this.showSessionExpiredModal();
    }

    showSessionExpiredModal() {
        const modal = document.getElementById('session-expired-modal');
        if (modal) {
            modal.style.display = 'flex';
            return;
        }

        const modalHTML = `
            <div id="session-expired-modal" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10001;
                font-family: Arial, sans-serif;
            ">
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
                    max-width: 400px;
                    text-align: center;
                    animation: fadeIn 0.3s ease-out;
                ">
                    <div style="margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle" style="
                            font-size: 48px;
                            color: #f39c12;
                            margin-bottom: 15px;
                        "></i>
                        <h2 style="margin: 0 0 10px 0; color: #333;">Session Expired</h2>
                        <p style="margin: 0; color: #666; line-height: 1.5;">
                            Your session has expired due to inactivity. You will be redirected to the login page 
                            to prevent any errors.
                        </p>
                    </div>
                    <button onclick="sessionManager.redirectToLogin()" style="
                        background: #007bff;
                        color: white;
                        border: none;
                        padding: 12px 24px;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 16px;
                        transition: background 0.2s;
                    " onmouseover="this.style.background='#0056b3'" onmouseout="this.style.background='#007bff'">
                        Go to Login
                    </button>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Auto-redirect after 10 seconds
        setTimeout(() => {
            this.redirectToLogin();
        }, 10000);
    }

    redirectToLogin() {
        const currentPath = window.location.pathname + window.location.search;
        const returnUrl = encodeURIComponent(currentPath);
        window.location.href = `${this.loginUrl}?expired=1&return=${returnUrl}`;
    }

    checkServerSession() {
        if (this.checkingSession) return;

        this.checkingSession = true;

        fetch('../functions/check_session.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        })
            .then(response => {
                if (response.status === 401 || response.status === 403) {
                    this.handleSessionExpired();
                    return { valid: false };
                }
                return response.json();
            })
            .then(data => {
                if (data && !data.valid) {
                    this.handleSessionExpired();
                } else if (data && data.valid) {
                    this.sessionValid = true;
                    this.lastActivity = Date.now();
                }
            })
            .catch(error => {
                console.warn('Session check failed:', error);
                // On network error, assume session might be invalid and warn user
                if (!this.warningShown) {
                    this.showNotification('Unable to verify session. Please refresh the page if you experience issues.', 'warning');
                }
            })
            .finally(() => {
                this.checkingSession = false;
            });
    }

    handleVisibilityChange() {
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.sessionValid) {
                // Page became visible again, check if session is still valid
                this.checkServerSession();
            }
        });
    }

    handleNavigation() {
        // Intercept navigation to prevent landing on 404 pages with expired sessions
        const originalPushState = history.pushState;
        const originalReplaceState = history.replaceState;
        const self = this;

        history.pushState = function () {
            if (!self.sessionValid) {
                self.redirectToLogin();
                return;
            }
            return originalPushState.apply(this, arguments);
        };

        history.replaceState = function () {
            if (!self.sessionValid) {
                self.redirectToLogin();
                return;
            }
            return originalReplaceState.apply(this, arguments);
        };

        // Handle back/forward navigation
        window.addEventListener('popstate', () => {
            if (!self.sessionValid) {
                self.redirectToLogin();
            }
        });

        // Intercept link clicks
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a');
            if (link && link.href && !link.href.startsWith('javascript:') && !link.href.startsWith('#')) {
                if (!self.sessionValid) {
                    e.preventDefault();
                    self.redirectToLogin();
                }
            }
        });

        // Intercept form submissions
        document.addEventListener('submit', (e) => {
            if (!self.sessionValid) {
                e.preventDefault();
                self.redirectToLogin();
            }
        });
    }

    logout() {
        window.location.href = '../functions/logout.php';
    }

    showNotification(message, type = 'info') {
        // Create or update notification
        let notification = document.getElementById('session-notification');
        if (!notification) {
            notification = document.createElement('div');
            notification.id = 'session-notification';
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 5px;
                color: white;
                font-weight: bold;
                z-index: 10001;
                opacity: 0;
                transition: opacity 0.3s ease;
            `;
            document.body.appendChild(notification);
        }

        // Set color based on type
        const colors = {
            success: '#28a745',
            warning: '#ffc107',
            error: '#dc3545',
            info: '#17a2b8'
        };

        notification.style.backgroundColor = colors[type] || colors.info;
        notification.textContent = message;
        notification.style.opacity = '1';

        // Auto-hide after 3 seconds
        setTimeout(() => {
            notification.style.opacity = '0';
        }, 3000);
    }
}

// Auto-initialize for logged-in users
document.addEventListener('DOMContentLoaded', function () {
    // Only initialize if user appears to be logged in (check for common elements)
    if (document.querySelector('.nav-links') || document.querySelector('header')) {
        // Get configuration from server if available
        let config = {
            warningTime: 10 * 60 * 1000, // Default: 10 minutes before timeout
            sessionTimeout: 120 * 60 * 1000, // Default: 2 hours total session time
            checkInterval: 60 * 1000, // Default: Check every minute
            loginUrl: '../functions/login.php'
        };

        // Try to get server configuration
        if (window.sessionConfig) {
            config = Object.assign(config, window.sessionConfig);
        }

        // Initialize session timeout manager
        const sessionManager = new SessionTimeoutManager(config);

        // Make it globally accessible for debugging
        window.sessionManager = sessionManager;

        console.log('Session timeout manager initialized with config:', config);
    }
});
