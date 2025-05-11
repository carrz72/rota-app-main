/**
 * Enhanced navigation handler for Progressive Web App (PWA) standalone mode
 * Ensures the app stays in standalone mode during page navigation
 */
function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone;
}

// Function to set up all navigation handlers
function setupStandaloneNavigation() {
    if (!isStandalone()) return;

    console.log('[PWA] Running in standalone mode - applying navigation handlers');

    // 1. Handle all link clicks
    document.querySelectorAll('a').forEach(link => {
        if (!link.hasAttribute('data-standalone-handled')) {
            link.setAttribute('data-standalone-handled', 'true');
            link.addEventListener('click', handleLinkClick);
        }
    });

    // 2. Handle all form submissions
    document.querySelectorAll('form').forEach(form => {
        if (!form.hasAttribute('data-standalone-handled')) {
            form.setAttribute('data-standalone-handled', 'true');
            form.addEventListener('submit', handleFormSubmit);
        }
    });
}

// Handle link clicks
function handleLinkClick(event) {
    // Skip links that should open in new windows/tabs
    if (this.target === '_blank') return;

    // Skip links with special protocols or download attribute
    const href = this.getAttribute('href');
    if (!href ||
        href.startsWith('javascript:') ||
        href.startsWith('#') ||
        href.startsWith('tel:') ||
        href.startsWith('mailto:') ||
        this.hasAttribute('download')) {
        return;
    }

    event.preventDefault();
    console.log('[PWA] Navigating to:', href);
    location.href = href;
}

// Handle form submissions
function handleFormSubmit(event) {
    const form = event.target;
    const method = (form.getAttribute('method') || 'get').toLowerCase();

    // Only intercept standard GET/POST forms
    if (form.hasAttribute('target') && form.target !== '_self') return;

    // Let the form submit normally if it has file inputs
    if (form.querySelector('input[type="file"]')) return;

    event.preventDefault();

    if (method === 'get') {
        const action = form.getAttribute('action') || '';
        const formData = new FormData(form);
        const queryString = new URLSearchParams(formData).toString();
        location.href = action + (action.includes('?') ? '&' : '?') + queryString;
    } else {
        // Use fetch for POST forms
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
        })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text();
                }
            })
            .then(html => {
                if (html) {
                    document.open();
                    document.write(html);
                    document.close();
                }
            })
            .catch(error => {
                console.error('[PWA] Form submission error:', error);
                // Fall back to normal form submission
                form.submit();
            });
    }
}

// Run on page load
document.addEventListener('DOMContentLoaded', setupStandaloneNavigation);

// Set up a mutation observer to handle dynamically added links and forms
const observer = new MutationObserver(mutations => {
    if (!isStandalone()) return;

    let shouldSetupNavigation = false;

    mutations.forEach(mutation => {
        if (mutation.type === 'childList' && mutation.addedNodes.length) {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) { // Element node
                    if (node.tagName === 'A' || node.tagName === 'FORM' ||
                        node.querySelector('a, form')) {
                        shouldSetupNavigation = true;
                    }
                }
            });
        }
    });

    if (shouldSetupNavigation) {
        setupStandaloneNavigation();
    }
});

// Start observing the document with the configured parameters

observer.observe(document.body, { childList: true, subtree: true }); observer.observe(document.body, { childList: true, subtree: true });