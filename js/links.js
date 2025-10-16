/**
 * Enhanced navigation handler for iOS PWA standalone mode
 * Focused on iOS compatibility and form handling
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
    // If debug logging function exists, use it
    if (typeof window.logPWADebug === 'function') {
        window.logPWADebug(message);
    }
}

// Main navigation handler setup
function setupStandaloneNavigation() {
    // Only apply to standalone mode
    if (!isStandalone()) return;

    logPWA(`Running in standalone mode on ${isIOS() ? 'iOS' : 'other'} device - applying navigation handlers`);

    // KEY FIX #1: For iOS, use direct JS element access instead of querySelectorAll when possible
    // This is critical for iOS WebKit to properly handle touch events on dynamically added elements
    const allLinks = isIOS() ?
        Array.from(document.getElementsByTagName('a')) :
        document.querySelectorAll('a');

    // Handle all links
    allLinks.forEach(link => {
        if (link.hasAttribute('data-standalone-handled')) return;

        link.setAttribute('data-standalone-handled', 'true');

        // KEY FIX #2: Use both click and touchend events for iOS
        // iOS sometimes misses click events but catches touchend
        if (isIOS()) {
            link.addEventListener('touchend', handleLinkNavigation);
        }
        link.addEventListener('click', handleLinkNavigation);
    });

    // Handle all forms
    const allForms = document.querySelectorAll('form');
    allForms.forEach(form => {
        if (form.hasAttribute('data-standalone-handled')) return;

        form.setAttribute('data-standalone-handled', 'true');
        form.addEventListener('submit', handleFormSubmit);
    });
}

// Shared handler for link navigation
function handleLinkNavigation(event) {
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

    // Skip external links - let them open normally
    if (href.startsWith('http') && !href.includes(window.location.hostname)) {
        return;
    }

    // Handle the navigation
    event.preventDefault();
    event.stopPropagation(); // KEY FIX #3: Stop propagation to prevent double handling

    logPWA(`Intercepted navigation: ${href}`);

    // Convert relative URLs to absolute, but keep them relative to the app scope
    let navigateUrl = href;
    if (href.startsWith('/')) {
        // Root-relative URL - keep as is
        navigateUrl = href;
    } else if (!href.includes('://')) {
        // Relative URL - convert using current page as base
        const baseUrl = window.location.href.substring(0, window.location.href.lastIndexOf('/') + 1);
        navigateUrl = baseUrl + href;
    } else if (href.startsWith('http') && href.includes(window.location.hostname)) {
        // Same origin absolute URL - use as is
        navigateUrl = href;
    }

    logPWA(`Navigating to: ${navigateUrl}`);

    // For PWA, always use location.href to stay within the app
    window.location.href = navigateUrl;
}

// Handle form submissions
function handleFormSubmit(event) {
    // Skip forms with targets other than _self or with file uploads
    if ((this.target && this.target !== '_self') || this.querySelector('input[type="file"]')) return;

    const method = (this.getAttribute('method') || 'get').toLowerCase();
    const action = this.getAttribute('action') || '';

    logPWA(`Intercepted form submission: ${method.toUpperCase()} ${action}`);

    // Convert relative URLs to absolute, keeping them within app scope
    let formAction = action;
    if (action.startsWith('/')) {
        // Root-relative - keep as is
        formAction = action;
    } else if (!action.includes('://') && action !== '') {
        // Relative URL - convert using current page as base
        const baseUrl = window.location.href.substring(0, window.location.href.lastIndexOf('/') + 1);
        formAction = baseUrl + action;
    } else if (action === '') {
        // Empty action - use current page without query string
        formAction = window.location.href.split('?')[0];
    } else if (action.startsWith('http') && action.includes(window.location.hostname)) {
        // Same origin absolute URL - use as is
        formAction = action;
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
        window.location.href = navigateUrl;
    } else {
        // Enhanced POST handling for PWA
        logPWA(`Performing fetch with method: ${method} to: ${formAction}`);

        fetch(formAction, {
            method: method,
            body: new FormData(this),
            credentials: 'same-origin',
            headers: {
                'X-PWA-Standalone': 'true',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => {
                logPWA(`Server response status: ${response.status}`);

                if (response.redirected) {
                    logPWA(`Server redirected to: ${response.url}`);
                    // Handle redirects by navigating to the new URL
                    if (isIOS()) {
                        window.location.replace(response.url);
                    } else {
                        window.location.href = response.url;
                    }
                    return null;
                } else if (!response.ok) {
                    // Handle error responses
                    logPWA(`Server error: ${response.status} ${response.statusText}`);
                    throw new Error(`Server responded with ${response.status}: ${response.statusText}`);
                } else {
                    return response.text();
                }
            })
            .then(html => {
                if (html) {
                    // Check if the response contains a full HTML document
                    if (html.includes('<!DOCTYPE html>') || html.includes('<html')) {
                        // Replace the entire document
                        document.open();
                        document.write(html);
                        document.close();

                        // Re-apply navigation handlers to the new content
                        setTimeout(setupStandaloneNavigation, 100);
                    } else {
                        // Handle JSON responses or partial HTML
                        logPWA('Response was not a complete HTML document');
                        try {
                            // Check if it's JSON
                            const jsonData = JSON.parse(html);
                            if (jsonData.redirect) {
                                if (isIOS()) {
                                    window.location.replace(jsonData.redirect);
                                } else {
                                    window.location.href = jsonData.redirect;
                                }
                            } else if (jsonData.success) {
                                // Reload the current page to show the updated state
                                window.location.reload();
                            }
                        } catch (e) {
                            // Not JSON, might be a partial HTML response or error message
                            logPWA("Received non-HTML, non-JSON response - reloading page");
                            window.location.reload();
                        }
                    }
                }
            })
            .catch(error => {
                logPWA(`Error during fetch: ${error.message}`);

                // Don't fall back to regular form submission immediately
                // Instead, show an error message and let user retry
                console.error('PWA Navigation Error:', error);

                // For critical errors, fall back to regular form submission
                if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
                    logPWA('Network error detected - attempting regular form submission');
                    // Create a temporary form and submit it normally
                    const tempForm = this.cloneNode(true);
                    tempForm.removeAttribute('data-standalone-handled');
                    document.body.appendChild(tempForm);
                    tempForm.submit();
                    document.body.removeChild(tempForm);
                } else {
                    // For other errors, try to reload the page
                    logPWA('Non-network error - reloading page');
                    window.location.reload();
                }
            });
    }
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
                (node.tagName === 'A' || node.tagName === 'FORM' ||
                    node.querySelector && (node.querySelector('a') || node.querySelector('form')))) {
                shouldSetupNavigation = true;
            }
        });
    });

    if (shouldSetupNavigation) {
        setupStandaloneNavigation();
    }
});

// KEY FIX #6: Run immediately for iOS (crucial on iOS for capturing elements)
if (isIOS() && isStandalone()) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupStandaloneNavigation);
    } else {
        setupStandaloneNavigation();
    }

    // Add critical iOS event to re-run setup after any page change
    // This catches events iOS Safari might miss
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { // Page was restored from back/forward cache
            setupStandaloneNavigation();
        }
    });
}

// Also run on DOMContentLoaded for all devices
document.addEventListener('DOMContentLoaded', () => {
    // Start observing the document with the configured parameters
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Initial setup
    setupStandaloneNavigation();
});