<?php
// Standard admin head include — outputs SEO, PWA and basic meta tags.
// Usage: set $PAGE_TITLE (string) before including this file. Example:
//   $PAGE_TITLE = 'Admin Dashboard - Open Rota';
//   require_once __DIR__ . '/admin_head.php';

// Locate seo helper
require_once __DIR__ . '/../includes/seo.php';

if (!isset($PAGE_TITLE))
    $PAGE_TITLE = 'Open Rota';

// Render SEO meta (title, description, OG/Twitter, canonical)
seo_render_head(['title' => seo_full_title($PAGE_TITLE), 'description' => 'Administration area for Open Rota.']);

// PWA / icons
// Use absolute-ish paths to be safe when admin is nested
$base = '/rota-app-main';
echo "<link rel=\"manifest\" href=\"{$base}/manifest.json\">\n";
echo "<link rel=\"icon\" type=\"image/png\" href=\"{$base}/images/icon.png\">\n";
echo "<link rel=\"apple-touch-icon\" href=\"{$base}/images/icon.png\">\n";
echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no\">\n";

// Apple webapp hints
echo "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n";
echo "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"black-translucent\">\n";
echo "<meta name=\"apple-mobile-web-app-title\" content=\"Open Rota\">\n";

?>