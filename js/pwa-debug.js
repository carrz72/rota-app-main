(function () {
    // Only show debug in standalone mode
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone;

    if (!isStandalone) return;

    // Create debug panel
    const panel = document.createElement('div');
    panel.style.cssText = `
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0,0,0,0.8);
        color: white;
        font-family: monospace;
        font-size: 12px;
        padding: 8px;
        max-height: 150px;
        overflow-y: auto;
        z-index: 10000;
    `;

    // Add toggle button
    const toggle = document.createElement('button');
    toggle.textContent = 'PWA Debug';
    toggle.style.cssText = `
        position: fixed;
        bottom: 10px;
        right: 10px;
        background: #fd2b2b;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 5px 10px;
        z-index: 10001;
        font-size: 12px;
    `;

    // Event log
    const log = document.createElement('div');

    // Add info
    log.innerHTML = `
        <div>Standalone Mode: ${isStandalone ? 'YES' : 'NO'}</div>
        <div>iOS Device: ${/iPad|iPhone|iPod/.test(navigator.userAgent) ? 'YES' : 'NO'}</div>
        <div>User Agent: ${navigator.userAgent}</div>
        <div>Current URL: ${window.location.href}</div>
        <hr>
        <div id="pwa-event-log"></div>
    `;

    panel.appendChild(log);

    // Initially hide panel
    panel.style.display = 'none';

    // Log function for panel
    window.logPWADebug = function (message) {
        const eventLog = document.getElementById('pwa-event-log');
        if (eventLog) {
            const entry = document.createElement('div');
            entry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
            eventLog.insertBefore(entry, eventLog.firstChild);
        }
        console.log(`[PWA Debug] ${message}`);
    };

    // Toggle panel display
    toggle.addEventListener('click', function () {
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    });

    // Add to document when ready
    document.addEventListener('DOMContentLoaded', function () {
        document.body.appendChild(panel);
        document.body.appendChild(toggle);

        // Monitor navigation events
        document.addEventListener('click', function (e) {
            if (e.target.tagName === 'A') {
                logPWADebug(`Link clicked: ${e.target.href}`);
            }
        }, true);

        document.addEventListener('submit', function (e) {
            logPWADebug(`Form submitted: ${e.target.action}`);
        }, true);

        logPWADebug('PWA Debug initialized');
    });
})();