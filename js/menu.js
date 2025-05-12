document.addEventListener('DOMContentLoaded', function () {
    // Get the menu toggle button and navigation links
    const menuToggle = document.getElementById('menu-toggle');
    const navLinks = document.getElementById('nav-links');

    // Check if elements exist
    if (menuToggle && navLinks) {
        // Add click event listener to the toggle button
        menuToggle.addEventListener('click', function () {
            // Toggle the 'show' class on the navigation links
            navLinks.classList.toggle('show');
            console.log('Menu toggled');
        });

        // Close menu when clicking outside
        document.addEventListener('click', function (event) {
            if (!menuToggle.contains(event.target) && !navLinks.contains(event.target) && navLinks.classList.contains('show')) {
                navLinks.classList.remove('show');
            }
        });
    } else {
        console.error('Menu elements not found:', { menuToggle, navLinks });
    }
});