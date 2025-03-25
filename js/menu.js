function initMenu() {
    console.log("initMenu called");
    const menuToggle = document.getElementById('menu-toggle');
    const navLinks = document.getElementById('nav-links');
    if (!menuToggle || !navLinks) {
        console.log("Menu elements not found");
        return; 
    }

    menuToggle.addEventListener('click', function(e) {
        console.log("menu-toggle clicked");
        navLinks.classList.toggle('show');
        e.stopPropagation();
    });

    document.addEventListener('click', function(e) {
        if (!navLinks.contains(e.target) && !menuToggle.contains(e.target)) {
            console.log("Hiding nav-links");
            navLinks.classList.remove('show');
        }
    });
}

window.addEventListener('load', initMenu);