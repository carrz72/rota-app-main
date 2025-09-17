(function(){
  function setConsent(val){
    try{ document.cookie = 'cookie_consent=' + val + '; path=/; max-age=' + 60*60*24*365; }catch(e){}
    var banner = document.getElementById('cookie-banner');
    if(banner) banner.style.display='none';
    // Optionally fire an event or load analytics if accepted
    if(val === 'yes'){
      var ev = new Event('cookieConsentGiven');
      window.dispatchEvent(ev);
    }
  }
  document.addEventListener('DOMContentLoaded', function(){
    var accept = document.getElementById('cookie-accept');
    var decline = document.getElementById('cookie-decline');
    if(accept) accept.addEventListener('click', function(){ setConsent('yes'); });
    if(decline) decline.addEventListener('click', function(){ setConsent('no'); });
  });
})();
