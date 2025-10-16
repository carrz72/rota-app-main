// Dark mode toggle
(function(){
  const storageKey = 'rota_theme';
  const toggle = document.getElementById('dark_mode_toggle');

  function applyTheme(theme){
    if(theme === 'dark'){
      document.documentElement.setAttribute('data-theme','dark');
      if(toggle) toggle.checked = true;
    } else {
      document.documentElement.removeAttribute('data-theme');
      if(toggle) toggle.checked = false;
    }
  }

  try {
    // If server inlined a theme on the documentElement, respect it and sync localStorage.
    const serverTheme = document.documentElement.getAttribute('data-theme');
    if (serverTheme) {
      applyTheme(serverTheme === 'dark' ? 'dark' : 'light');
      try { localStorage.setItem(storageKey, serverTheme === 'dark' ? 'dark' : 'light'); } catch (e) {}
    } else {
      // Fall back to localStorage only - do NOT use prefers-color-scheme
      // User must explicitly select dark mode
      const saved = (function(){ try { return localStorage.getItem(storageKey); } catch(e){ return null; } })();
      if (saved === 'dark') {
        applyTheme('dark');
      } else {
        // Default to light mode
        applyTheme('light');
      }
    }
  } catch (e) {
    // If anything goes wrong, default to light mode
    applyTheme('light');
  }

  if(toggle){
    toggle.addEventListener('change', function(){
      const theme = this.checked ? 'dark' : 'light';
      try { localStorage.setItem(storageKey, theme); } catch(e){}
      applyTheme(theme);

      // Try to persist to server if user is logged in
      try{
        fetch('../functions/save_theme.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ theme })
        }).catch(()=>{/* ignore network errors */});
      }catch(e){/* ignore */}
    });
  }
})();
