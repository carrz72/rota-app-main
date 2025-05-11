/**
 * Links handler for Progressive Web App (PWA) standalone mode
 * Ensures links stay within the app context instead of opening in browser
 */
function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone;
}

document.addEventListener('DOMContentLoaded', () => {
    if (isStandalone()) {
        document.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', (event) => {
                // Skip links that should open in new windows/tabs
                if (link.target === '_blank') return;

                event.preventDefault();
                location.href = link.href;
            });
        });
    }
});