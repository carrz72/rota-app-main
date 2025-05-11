/**
 * Enhanced navigation handler for Progressive Web App (PWA) standalone mode
 * Complete rewrite with better iOS/Android compatibility
 */

// Check if running in standalone mode (PWA)
function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone;
}

// Check if the device is running iOS
function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}

// Log function for debugging
function logPWA(message) {
    console.log(`[PWA Navigation] ${message}`);
}

// Main navigation handler setup
function setupStandaloneNavigation() {
    // Only apply to standalone mode
    if (!isStandalone()) return;

    logPWA(`Running in standalone mode on ${isIOS() ? 'iOS' : 'other'} device - applying navigation handlers`);

    // Fix all absolute URLs to be root-relative
    const baseUrl = window.location.origin;

    // Handle all links
    document.querySelectorAll('a').forEach(link => {
        if (link.hasAttribute('data-standalone-handled')) return;

        link.setAttribute('data-standalone-handled', 'true');
        link.addEventListener('click', function (event) {
            // Skip links that should open in new tabs or have special attributes
            if (this.target === '_blank' || this.hasAttribute('download')) return;

            const href = this.getAttribute('href');
            if (!href) return;

            // Skip non-http links like javascript:, tel:, mailto:
            if (href.startsWith('#') ||
                href.startsWith('javascript:') ||
                href.startsWith('tel:') ||
                href.startsWith('mailto:')) {
                return;
            }

            // Handle the navigation
            event.preventDefault();
            logPWA(`Intercepted click on link: ${href}`);

            // Convert relative URLs to absolute
            let navigateUrl = href;
            if (href.startsWith('/')) {
                // Already root-relative, keep as is
                navigateUrl = href;
            } else if (!href.includes('://')) {
                // Convert relative to absolute using baseUrl
                navigateUrl = new URL(href, window.location.href).href;
            }

            // Use appropriate navigation method based on device
            if (isIOS()) {
                window.location.replace(navigateUrl);
            } else {
                window.location.href = navigateUrl;
            }
        });
    });

    // Handle form submissions
    document.querySelectorAll('form').forEach(form => {
        if (form.hasAttribute('data-standalone-handled')) return;

        form.setAttribute('data-standalone-handled', 'true');
        form.addEventListener('submit', function (event) {
            // Skip forms with targets other than _self or with file uploads
            if ((form.target && form.target !== '_self') || form.querySelector('input[type="file"]')) return;

            const method = (this.getAttribute('method') || 'get').toLowerCase();
            const action = this.getAttribute('action') || '';

            logPWA(`Intercepted form submission: ${method.toUpperCase()} ${action}`);

            // Convert relative URLs to absolute
            let formAction = action;
            if (action.startsWith('/')) {
                // Already root-relative, keep as is
                formAction = action;
            } else if (!action.includes('://') && action !== '') {
                // Convert relative to absolute
                formAction = new URL(action, window.location.href).href;
            } else if (action === '') {
                // Handle empty action (submits to current URL)
                formAction = window.location.href.split('?')[0];
            }

            event.preventDefault();

            if (method === 'get') {
                // For GET requests, build URL with query parameters
                const formData = new FormData(this);
                const params = new URLSearchParams();

                for (const [key, value] of formData.entries()) {
                    params.append(key, value);
                }

                const queryString = params.toString();
                const navigateUrl = formAction + (formAction.includes('?') ? '&' : '?') + queryString;

                logPWA(`Navigating to: ${navigateUrl}`);

                // Use appropriate navigation method based on device
                if (isIOS()) {
                    window.location.replace(navigateUrl);
                } else {
                    window.location.href = navigateUrl;
                }
            } else {
                // For POST and other methods, use fetch
                logPWA(`Performing fetch with method: ${method}`);

                fetch(formAction, {
                    method: method,
                    body: new FormData(this),
                    credentials: 'same-origin',
                    headers: {
                        'X-PWA-Standalone': 'true'
                    }
                })
                    .then(response => {
                        if (response.redirected) {
                            logPWA(`Server redirected to: ${response.url}`);

                            if (isIOS()) {
                                window.location.replace(response.url);
                            } else {
                                window.location.href = response.url;
                            }
                        } else {
                            return response.text();
                        }
                    })
                    .then(html => {
                        if (html) {
                            document.open();
                            document.write(html);
                            document.close();

                            // Re-apply navigation handlers to the new content
                            setupStandaloneNavigation();
                        }
                    })
                    .catch(error => {
                        console.error(`[PWA Navigation] Error during fetch: ${error.message}`);
                        // Fall back to regular form submission as last resort
                        this.submit();
                    });
            }
        });
    });
}

// Mutation observer to handle dynamically added elements
const observer = new MutationObserver(mutations => {
    if (!isStandalone()) return;

    let shouldSetupNavigation = false;

    mutations.forEach(mutation => {
        if (mutation.type !== 'childList' || !mutation.addedNodes.length) return;

        mutation.addedNodes.forEach(node => {
            // Check if the added node is an element and it's a link, form, or contains links/forms
            if (node.nodeType === Node.ELEMENT_NODE &&
                (node.tagName === 'A' || node.tagName === 'FORM' || node.querySelector && node.querySelector('a, form'))) {
                shouldSetupNavigation = true;
            }
        });
    });

    if (shouldSetupNavigation) {
        setupStandaloneNavigation();
    }
});

// Initialize observer when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Initial setup
    setupStandaloneNavigation();
});

// Critical for iOS: Execute immediately without waiting for DOMContentLoaded
if (isIOS() && isStandalone()) {
    // For iOS, try to handle as early as possible
    if (document.body) {
        setupStandaloneNavigation();
    } else {
        // If body isn't available yet, we use alternative approach
        document.addEventListener('readystatechange', () => {
            if (document.readyState !== 'loading') {
                setupStandaloneNavigation();
            }
        });
    }
}