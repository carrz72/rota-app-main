/**
 * Global Session Protection
 * Prevents navigation to pages when session has expired
 */

(function () {
    'use strict';

    let sessionProtection = {
        isSessionValid: true,
        checkingSession: false,
        lastCheck: Date.now(),
        checkInterval: 5 * 60 * 1000, // Check every 5 minutes

        init: function () {
            this.interceptNavigation();
            this.periodicSessionCheck();
            this.handleVisibilityChange();
        },

        interceptNavigation: function () {
            const self = this;

            // Intercept all link clicks
            document.addEventListener('click', function (e) {
                const link = e.target.closest('a');
                if (link && link.href && !link.href.startsWith('javascript:') && !link.href.startsWith('#')) {
                    if (!self.isSessionValid) {
                        e.preventDefault();
                        self.redirectToLogin();
                        return false;
                    }
                }
            });

            // Intercept form submissions
            document.addEventListener('submit', function (e) {
                if (!self.isSessionValid) {
                    e.preventDefault();
                    self.redirectToLogin();
                    return false;
                }
            });

            // Intercept programmatic navigation
            const originalPushState = history.pushState;
            const originalReplaceState = history.replaceState;

            history.pushState = function () {
                if (!self.isSessionValid) {
                    self.redirectToLogin();
                    return;
                }
                return originalPushState.apply(this, arguments);
            };

            history.replaceState = function () {
                if (!self.isSessionValid) {
                    self.redirectToLogin();
                    return;
                }
                return originalReplaceState.apply(this, arguments);
            };

            // Handle browser back/forward
            window.addEventListener('popstate', function () {
                if (!self.isSessionValid) {
                    self.redirectToLogin();
                }
            });
        },

        periodicSessionCheck: function () {
            const self = this;

            setInterval(function () {
                const now = Date.now();
                if (now - self.lastCheck > self.checkInterval) {
                    self.checkSession();
                }
            }, 60000); // Check every minute if we need to validate
        },

        checkSession: function () {
            if (this.checkingSession) return;

            const self = this;
            this.checkingSession = true;
            this.lastCheck = Date.now();

            fetch('../functions/check_session.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    if (response.status === 401 || response.status === 403) {
                        self.handleSessionExpired();
                        return { valid: false };
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (data && !data.valid) {
                        self.handleSessionExpired();
                    } else if (data && data.valid) {
                        self.isSessionValid = true;
                    }
                })
                .catch(function (error) {
                    // On network error, don't assume session is invalid
                    console.warn('Session check failed:', error);
                })
                .finally(function () {
                    self.checkingSession = false;
                });
        },

        handleSessionExpired: function () {
            this.isSessionValid = false;

            // Show session expired message
            this.showSessionExpiredMessage();
        },

        showSessionExpiredMessage: function () {
            // Remove any existing message
            const existing = document.getElementById('session-expired-notice');
            if (existing) {
                existing.remove();
            }

            const notice = document.createElement('div');
            notice.id = 'session-expired-notice';
            notice.innerHTML = `
                <div style="
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    background: #e74c3c;
                    color: white;
                    padding: 15px;
                    text-align: center;
                    z-index: 10000;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                    font-family: Arial, sans-serif;
                ">
                    <i class="fas fa-exclamation-triangle" style="margin-right: 10px;"></i>
                    <strong>Session Expired:</strong> Your session has expired. 
                    <a href="../functions/login.php?expired=1&return=${encodeURIComponent(window.location.pathname)}" 
                       style="color: #fff; text-decoration: underline; margin-left: 10px;">
                        Click here to log in again
                    </a>
                    <button onclick="this.parentElement.parentElement.remove()" 
                            style="
                                background: none;
                                border: none;
                                color: white;
                                font-size: 18px;
                                margin-left: 15px;
                                cursor: pointer;
                                padding: 0;
                            ">×</button>
                </div>
            `;

            document.body.appendChild(notice);

            // Auto-remove after 30 seconds
            setTimeout(function () {
                if (notice.parentElement) {
                    notice.remove();
                }
            }, 30000);
        },

        redirectToLogin: function () {
            const currentPath = window.location.pathname + window.location.search;
            const loginUrl = '../functions/login.php?expired=1&return=' + encodeURIComponent(currentPath);
            window.location.href = loginUrl;
        },

        handleVisibilityChange: function () {
            const self = this;

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    // Page became visible, check session if it's been a while
                    const now = Date.now();
                    if (now - self.lastCheck > 2 * 60 * 1000) { // 2 minutes
                        self.checkSession();
                    }
                }
            });
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            sessionProtection.init();
        });
    } else {
        sessionProtection.init();
    }

    // Make available globally for manual checks
    window.sessionProtection = sessionProtection;

})();
