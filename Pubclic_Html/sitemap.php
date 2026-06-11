<?php
/**
 * sitemap.php — Dynamic XML Sitemap (FEAT-32)
 *
 * Generates a valid sitemap.xml from all Active compounds plus static pages.
 * Accessible at: https://www.abchem.co.in/sitemap.xml  (via .htaccess rewrite
 *   or directly as /sitemap.php)
 *
 * Cache: served with Cache-Control: public, max-age=3600 (1 hour).
 * IndexNow ping: send a ping after new compound insert/update (manual task).
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
error_reporting(E_ALL);

require_once __DIR__ . '/../private/functions.php';

$BASE = 'https://www.abchem.co.in';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$db = Database::getInstance();

// ── Static pages ──────────────────────────────────────────────────────────────
$staticPages = [
    ['loc' => '',          'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => '/catalog',  'priority' => '0.9', 'changefreq' => 'daily'],
    ['loc' => '/about',    'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => '/contact',  'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => '/custom-synthesis', 'priority' => '0.4', 'changefreq' => 'monthly'],
    ['loc' => '/purification',     'priority' => '0.4', 'changefreq' => 'monthly'],
];

// ── Dynamic product pages ─────────────────────────────────────────────────────
$compounds = $db->fetchAll("
    SELECT ab_catalog_number, url_token, url_slug, slug, updated_at
    FROM compounds
    WHERE status = 'Active'
      AND (
            (ab_catalog_number IS NOT NULL AND ab_catalog_number != '' AND url_token IS NOT NULL AND url_token != '')
            OR (slug IS NOT NULL AND slug != '')
          )
    ORDER BY id ASC
");

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Static pages
foreach ($staticPages as $p) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($BASE . $p['loc'], ENT_XML1) . "</loc>\n";
    echo "    <changefreq>{$p['changefreq']}</changefreq>\n";
    echo "    <priority>{$p['priority']}</priority>\n";
    echo "  </url>\n";
}

// Product pages
foreach ($compounds as $c) {
    if (!empty($c['ab_catalog_number']) && !empty($c['url_token'])) {
        $token = rawurlencode($c['ab_catalog_number'] . '-' . $c['url_token']);
        $path  = !empty($c['url_slug'])
               ? '/product/' . rawurlencode($c['url_slug']) . '/' . $token
               : '/product/' . $token;
    } elseif (!empty($c['slug'])) {
        $path = '/product/' . rawurlencode($c['slug']);
    } else {
        continue;
    }

    $lastmod = !empty($c['updated_at'])
        ? date('Y-m-d', strtotime($c['updated_at']))
        : date('Y-m-d');

    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($BASE . $path, ENT_XML1) . "</loc>\n";
    echo "    <lastmod>{$lastmod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
