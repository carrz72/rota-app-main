document.addEventListener('DOMContentLoaded', function () {
    // Get the menu toggle button and navigation links
    const menuToggle = document.getElementById('menu-toggle');
    const navLinks = document.getElementById('nav-links');

    // Check if elements exist
    if (menuToggle && navLinks) {
        // Detect Safari
        const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

        // Add Safari-specific handling
        if (isSafari) {
            // Improve touch response for Safari
            menuToggle.style.cursor = 'pointer';

            // Use touchend for better responsiveness in Safari
            menuToggle.addEventListener('touchend', function (e) {
                e.preventDefault();
                e.stopPropagation();
                navLinks.classList.toggle('show');
            }, false);
        }

        // Add click event listener to the toggle button
        menuToggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            navLinks.classList.toggle('show');
            console.log('Menu toggled');
        });

        // Close menu when clicking outside
        document.addEventListener('click', function (event) {
            if (!menuToggle.contains(event.target) &&
                !navLinks.contains(event.target) &&
                navLinks.classList.contains('show')) {
                navLinks.classList.remove('show');
            }
        });

        // Additional Safari-specific handling for iOS devices
        if (isSafari && /iPad|iPhone|iPod/.test(navigator.userAgent)) {
            // Fix for menu positioning in iOS Safari
            window.addEventListener('scroll', function () {
                if (navLinks.classList.contains('show')) {
                    const headerRect = document.querySelector('header').getBoundingClientRect();
                    navLinks.style.top = (headerRect.bottom) + 'px';
                }
            });
        }
    }
});