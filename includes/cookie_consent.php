<?php
// Simple cookie-consent include: outputs banner if consent not given
$consent = $_COOKIE['cookie_consent'] ?? null;
if ($consent !== 'yes') : ?>
<div id="cookie-banner" style="position:fixed;bottom:16px;left:16px;right:16px;background:#fff;padding:12px;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,0.12);z-index:9999;display:flex;align-items:center;gap:12px;">
    <div style="flex:1">We use essential cookies for authentication. Optional analytics cookies are disabled until you consent.</div>
    <div>
        <button id="cookie-accept" class="btn btn-primary">Accept</button>
        <button id="cookie-decline" class="btn btn-secondary">Decline</button>
    </div>
</div>
<script src="/rota-app-main/js/cookie-consent.js"></script>
<?php endif; ?>
