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
      // Fall back to localStorage or prefers-color-scheme (for guests)
      const saved = (function(){ try { return localStorage.getItem(storageKey); } catch(e){ return null; } })();
      if (saved) {
        applyTheme(saved);
      } else {
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(prefersDark ? 'dark' : 'light');
      }
    }
  } catch (e) {
    // If anything goes wrong, silently continue — theme will be default.
    try { const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches; applyTheme(prefersDark ? 'dark' : 'light'); } catch (e2){}
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
