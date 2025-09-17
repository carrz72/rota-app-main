<?php
// Simple SEO helper: render title, description, canonical, OG and Twitter meta and JSON-LD
function seo_defaults(): array {
    $siteName = 'Open Rota';
    $defaults = [
        'title' => $siteName,
        'description' => 'Open Rota helps small teams manage shifts, track earnings and communicate schedule changes. Secure, simple rota management.',
        'site_name' => $siteName,
        'image' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/images/icon.png',
        'type' => 'website',
        'twitter_site' => null,
        'canonical' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
    ];
    return $defaults;
}

function seo_render_head(array $overrides = []): void {
    $d = array_merge(seo_defaults(), $overrides);
    // sanitize
    $title = htmlspecialchars((string)$d['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $description = htmlspecialchars((string)$d['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $canonical = htmlspecialchars((string)$d['canonical'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $image = htmlspecialchars((string)$d['image'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $siteName = htmlspecialchars((string)$d['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $type = htmlspecialchars((string)$d['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo "<title>$title</title>\n";
    echo "<meta name=\"description\" content=\"$description\">\n";
    echo "<link rel=\"canonical\" href=\"$canonical\">\n";

    // Open Graph
    echo "<meta property=\"og:locale\" content=\"en_US\">\n";
    echo "<meta property=\"og:site_name\" content=\"$siteName\">\n";
    echo "<meta property=\"og:title\" content=\"$title\">\n";
    echo "<meta property=\"og:description\" content=\"$description\">\n";
    echo "<meta property=\"og:type\" content=\"$type\">\n";
    echo "<meta property=\"og:url\" content=\"$canonical\">\n";
    echo "<meta property=\"og:image\" content=\"$image\">\n";

    // Twitter Card
    echo "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
    if (!empty($d['twitter_site'])) {
        $ts = htmlspecialchars((string)$d['twitter_site'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<meta name=\"twitter:site\" content=\"$ts\">\n";
    }
    echo "<meta name=\"twitter:title\" content=\"$title\">\n";
    echo "<meta name=\"twitter:description\" content=\"$description\">\n";
    echo "<meta name=\"twitter:image\" content=\"$image\">\n";

    // Basic structured data: Organization + website
    $org = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $siteName,
        'url' => $canonical,
        'logo' => $image,
    ];
    echo "<script type=\"application/ld+json\">" . json_encode($org, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "</script>\n";

    // Robots fallback: allow index by default
    if (!isset($d['robots'])) echo "<meta name=\"robots\" content=\"index,follow\">\n";
}

// convenience wrapper to build full page title
function seo_full_title(string $titlePart): string {
    $defaults = seo_defaults();
    if (trim($titlePart) === '') return $defaults['title'];
    return $titlePart . ' — ' . $defaults['site_name'];
}
