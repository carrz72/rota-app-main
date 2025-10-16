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
            const isOpen = navLinks.classList.toggle('show');
            // Update ARIA attribute for accessibility
            menuToggle.setAttribute('aria-expanded', isOpen);
            console.log('Menu toggled');
        });

        // Close menu when clicking outside
        document.addEventListener('click', function (event) {
            if (!menuToggle.contains(event.target) &&
                !navLinks.contains(event.target) &&
                navLinks.classList.contains('show')) {
                navLinks.classList.remove('show');
                menuToggle.setAttribute('aria-expanded', 'false');
            }
        });

        // Mobile browser detection and positioning fixes
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

        if (isMobile && !isStandalone) {
            // Mobile browser mode - adjust navigation positioning
            function adjustNavPosition() {
                const header = document.querySelector('header');
                const headerRect = header.getBoundingClientRect();
                const viewport = window.visualViewport || { height: window.innerHeight };

                if (navLinks.classList.contains('show')) {
                    navLinks.style.position = 'fixed';
                    navLinks.style.top = headerRect.bottom + 'px';
                    navLinks.style.left = '0px';
                    navLinks.style.right = '0px';
                    navLinks.style.width = '100vw';
                    navLinks.style.maxHeight = (viewport.height - headerRect.bottom - 10) + 'px';
                    navLinks.style.overflowY = 'auto';
                    navLinks.style.zIndex = '1001';
                }
            }

            // Adjust on menu toggle
            menuToggle.addEventListener('click', function () {
                setTimeout(adjustNavPosition, 10);
            });

            // Adjust on viewport changes (keyboard, orientation, etc.)
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', adjustNavPosition);
            }

            window.addEventListener('resize', adjustNavPosition);
            window.addEventListener('orientationchange', function () {
                setTimeout(adjustNavPosition, 100);
            });
        }

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