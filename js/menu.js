ocument.addEventListener('DOMContentLoaded', function () {
    const menuToggle = document.getElementById('menu-toggle');
    const navLinks = document.getElementById('nav-links');

    if (menuToggle && navLinks) {
        menuToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            navLinks.classList.toggle('show');
        });

        // Close menu when clicking outside
        document.addEventListener('click', function (e) {
            if (navLinks.classList.contains('show') &&
                !navLinks.contains(e.target) &&
                !menuToggle.contains(e.target)) {
                navLinks.classList.remove('show');
            }
        });

        // Safari-specific - ensure links have correct styling
        const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent) ||
            /iPad|iPhone|iPod/.test(navigator.userAgent);

        if (isSafari) {
            const links = navLinks.querySelectorAll('a');
            links.forEach(link => {
                link.style.backgroundColor = '#fd2b2b';
                link.style.color = '#ffffff';

                // Safari sometimes needs help with click events
                link.addEventListener('touchend', function (e) {
                    const href = this.getAttribute('href');
                    if (href) {
                        e.preventDefault();
                        window.location.href = href;
                    }
                });
            });
        }
    }
});