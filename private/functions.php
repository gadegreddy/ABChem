<?php
// These settings must run BEFORE anything else
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
error_reporting(E_ALL);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

// ─── Item 5: __Host- session cookie prefix + SameSite=Strict ────────────────
if (session_status() === PHP_SESSION_NONE) {
    $cookieName = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? '__Host-ABCHEM_SESSION'
        : 'ABCHEM_SESSION';
    session_name($cookieName);
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
        'cookie_path'     => '/',
        'cookie_domain'   => '',
    ]);
}

require_once ABSPATH . 'db_config.php';

// =============================================
// PRODUCT TYPE CONSTANTS  (single source of truth)
// =============================================

/** All valid product types in display order */
const PRODUCT_TYPES = [
    'API Impurity',
    'Reference Standard',
    'Metabolite',
    'Intermediate',
    'API',
    'Building Block',
    'Isotope',
];

/** Maps product_type → 3-letter type code used in catalog numbers */
const PRODUCT_TYPE_CODES = [
    'API Impurity'       => 'IMP',
    'Reference Standard' => 'STD',
    'Metabolite'         => 'MET',
    'Intermediate'       => 'INT',
    'API'                => 'API',
    'Building Block'     => 'BLD',
    'Isotope'            => 'ISO',
];

// =============================================
// SECURITY & SESSION
// =============================================

function enforceSessionTimeout(int $timeoutSeconds = 900): void {
    if (PHP_SAPI === 'cli') return;
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
        return;
    }
    if ((time() - $_SESSION['last_activity']) > $timeoutSeconds) {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        header('Location: /signin?expired=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// =============================================
// OUTPUT HELPERS
// =============================================

function e(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sanitize_input(?string $input): string {
    return preg_replace('/[^\w\s\-\.\,\@]/', '', trim($input ?? ''));
}

function checkRole(string $required): bool {
    if (!isset($_SESSION['user'])) return false;
    return $_SESSION['role'] === $required || $_SESSION['role'] === 'Admin';
}

// =============================================
// APCU CACHE HELPERS (Item 9)
// =============================================

function cacheGet(string $key): mixed {
    if (!function_exists('apcu_fetch')) return false;
    $success = false;
    $val = apcu_fetch($key, $success);
    return $success ? $val : false;
}

function cacheSet(string $key, mixed $value, int $ttl = 300): bool {
    if (!function_exists('apcu_store')) return false;
    return apcu_store($key, $value, $ttl);
}

function cacheDelete(string $key): void {
    if (function_exists('apcu_delete')) apcu_delete($key);
}

function cacheClearPrefix(string $prefix): void {
    if (!function_exists('apcu_cache_info')) return;
    $info = apcu_cache_info(false);
    foreach (($info['cache_list'] ?? []) as $entry) {
        $k = $entry['info'] ?? '';
        if (str_starts_with($k, $prefix)) apcu_delete($k);
    }
}

// =============================================
// PRODUCT FUNCTIONS (DATABASE - CACHED)
// =============================================

class ProductCache {
    private static array $products = [];
    private static ?int $totalCount = null;
    private static ?array $uniqueTypes = null;

    public static function clear(): void {
        self::$products = [];
        self::$totalCount = null;
        self::$uniqueTypes = null;
        cacheClearPrefix('abchem:products:');
    }

    public static function setProducts(array $products): void { self::$products = $products; }
    public static function getProducts(): array { return self::$products; }
    public static function hasProducts(): bool { return !empty(self::$products); }
}

function getProducts(array $filters = [], ?int $limit = null, ?int $offset = null): array {
    $cacheKey = 'abchem:products:list:' . md5(serialize($filters) . $limit . $offset);
    $cached = cacheGet($cacheKey);
    if ($cached !== false) return $cached;

    $db  = Database::getInstance();
    $sql = "SELECT
                c.id, c.slug,
                c.compound_name  AS product_name,
                c.cas_number, c.molecular_formula, c.molecular_weight,
                c.product_type,  c.image_url, c.iupac_name,
                c.smiles, c.inchi, c.inchi_key, c.pubchem_cid, c.synonyms, c.status,
                sl.id            AS listing_id,
                sl.purity, sl.availability, sl.stock_status,
                sl.lead_time,    sl.min_order_qty, sl.unit,
                sl.catalog_number, sl.price_on_request,
                s.supplier_name  AS company_make
            FROM compounds c
            LEFT JOIN supplier_listings sl
                ON sl.id = (
                    SELECT MIN(id) FROM supplier_listings
                    WHERE compound_id = c.id AND status = 'Active'
                )
            LEFT JOIN suppliers s ON s.id = sl.supplier_id
            WHERE c.status = 'Active'";
    $params = [];

    if (!empty($filters['product_type'])) {
        $sql .= " AND c.product_type = :type";
        $params['type'] = $filters['product_type'];
    }
    if (!empty($filters['search'])) {
        $sql .= " AND (c.compound_name LIKE :search OR c.cas_number LIKE :search
                       OR c.iupac_name LIKE :search OR c.synonyms LIKE :search
                       OR sl.catalog_number LIKE :search)";
        $params['search'] = "%{$filters['search']}%";
    }
    if (!empty($filters['supplier_id'])) {
        $sql .= " AND sl.supplier_id = :supplier_id";
        $params['supplier_id'] = (int)$filters['supplier_id'];
    }

    $sql .= " ORDER BY c.compound_name ASC";
    if ($limit !== null) {
        $sql .= " LIMIT " . (int)$limit;
        if ($offset !== null) $sql .= " OFFSET " . (int)$offset;
    }

    $result = $db->fetchAll($sql, $params);
    cacheSet($cacheKey, $result, 300);
    return $result;
}

function getProductBySlug(string $slug): ?array {
    $cacheKey = 'abchem:products:slug:' . md5($slug);
    $cached = cacheGet($cacheKey);
    if ($cached !== false) return $cached;

    $db = Database::getInstance();
    $compound = $db->fetchOne(
        "SELECT c.* FROM compounds c WHERE c.slug = :slug AND c.status = 'Active'",
        ['slug' => $slug]
    );
    if (!$compound) return null;

    // Alias for backward-compat with existing PHP templates
    $compound['product_name'] = $compound['compound_name'];

    // All active supplier listings for the public product page
    $compound['listings'] = $db->fetchAll(
        "SELECT sl.*, s.supplier_name AS company_make, s.supplier_code
         FROM supplier_listings sl
         JOIN suppliers s ON s.id = sl.supplier_id
         WHERE sl.compound_id = :id AND sl.status = 'Active'
         ORDER BY sl.supplier_id, sl.purity",
        ['id' => $compound['id']]
    );

    // Expose primary listing fields at top level for legacy templates
    if (!empty($compound['listings'])) {
        $primary = $compound['listings'][0];
        foreach (['purity','availability','stock_status','lead_time','min_order_qty',
                  'unit','catalog_number','company_make','lot_number',
                  'manufacture_date','expiry_date'] as $f) {
            $compound[$f] = $primary[$f] ?? null;
        }
        $compound['listing_id'] = $primary['id'];
    }

    $compound['custom_fields'] = [];
    // Expose canonical product URL so templates don't need to compute it
    $compound['product_url'] = buildProductUrl($compound);
    cacheSet($cacheKey, $compound, 300);
    return $compound;
}

function getProductById(int $id): ?array {
    $cacheKey = 'abchem:products:id:' . $id;
    $cached = cacheGet($cacheKey);
    if ($cached !== false) return $cached;

    $db = Database::getInstance();
    $result = $db->fetchOne(
        "SELECT c.*, c.compound_name AS product_name,
                sl.id AS listing_id, sl.purity, sl.availability, sl.catalog_number,
                sl.lead_time, sl.stock_status, sl.unit, sl.min_order_qty,
                s.supplier_name AS company_make
         FROM compounds c
         LEFT JOIN supplier_listings sl
             ON sl.id = (SELECT MIN(id) FROM supplier_listings WHERE compound_id = c.id AND status='Active')
         LEFT JOIN suppliers s ON s.id = sl.supplier_id
         WHERE c.id = :id AND c.status = 'Active'",
        ['id' => $id]
    );
    if ($result) cacheSet($cacheKey, $result, 300);
    return $result;
}

function getTotalProductCount(array $filters = []): int {
    $cacheKey = 'abchem:products:count:' . md5(serialize($filters));
    $cached = cacheGet($cacheKey);
    if ($cached !== false) return (int)$cached;

    $db  = Database::getInstance();
    $sql = "SELECT COUNT(DISTINCT c.id) FROM compounds c
            LEFT JOIN supplier_listings sl ON sl.compound_id = c.id AND sl.status = 'Active'
            WHERE c.status = 'Active'";
    $params = [];
    if (!empty($filters['product_type'])) {
        $sql .= " AND c.product_type = :type";
        $params['type'] = $filters['product_type'];
    }
    $result = (int)$db->fetchValue($sql, $params);
    cacheSet($cacheKey, $result, 300);
    return $result;
}

function getUniqueProductTypes(): array {
    $cacheKey = 'abchem:products:types';
    $cached = cacheGet($cacheKey);
    if ($cached !== false) return $cached;

    $db = Database::getInstance();
    $results = $db->fetchAll(
        "SELECT DISTINCT product_type FROM compounds
         WHERE product_type IS NOT NULL AND product_type != '' AND status = 'Active'
         ORDER BY product_type"
    );
    $result = array_column($results, 'product_type');
    cacheSet($cacheKey, $result, 300);
    return $result;
}

function getRelatedProducts(int $productId, ?string $productType, int $limit = 4): array {
    if (empty($productType)) return [];
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT c.id, c.slug, c.compound_name AS product_name, c.cas_number, c.image_url,
                sl.purity, sl.availability
         FROM compounds c
         LEFT JOIN supplier_listings sl
             ON sl.id = (SELECT MIN(id) FROM supplier_listings WHERE compound_id = c.id AND status='Active')
         WHERE c.product_type = :type AND c.id != :id AND c.status = 'Active'
           AND c.id >= (SELECT FLOOR(RAND() * (SELECT MAX(id) FROM compounds)))
         ORDER BY c.id LIMIT :limit",
        ['type' => $productType, 'id' => $productId, 'limit' => $limit]
    );
}

function clearProductCache(): bool {
    ProductCache::clear();
    return true;
}

/**
 * FEAT-29 L4: Sign a catalog pagination cursor.
 *
 * Wraps {page, per_page, filters_hash, issued_at} in an HMAC-signed token so
 * scrapers can't enumerate the catalog by incrementing ?page=N. The token is
 * URL-safe base64 of "payload|sha256_hmac(payload, secret)".
 *
 * Tokens expire after 1 hour — long enough for a normal browse session,
 * short enough to defeat replay-based scraping.
 *
 * NOTE: full cutover requires:
 *   1. app.js pagination clicks send `?cursor=TOKEN` instead of `?page=N`
 *   2. catalog.php emits an initial cursor in window.CATALOG_STATE
 *   3. api_data.php REQUIRES a valid cursor for page > 1
 * The first request (page 1) always works without a cursor — that's the
 * entry point for legitimate users and search engines.
 *
 * @param int   $page    1-indexed page number
 * @param int   $perPage Page size
 * @param array $filters Active filter map (used as a fingerprint)
 */
function signPageCursor(int $page, int $perPage, array $filters = []): string {
    $payload = json_encode([
        'p'  => $page,
        's'  => $perPage,
        'f'  => md5(json_encode($filters)),   // filter fingerprint — token only valid for same filter set
        't'  => time(),
    ]);
    $secret = getenv('SITE_HMAC_SECRET') ?: hash('sha256', __DIR__ . '|abchem-pagination');
    $sig    = hash_hmac('sha256', $payload, $secret);
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

/**
 * FEAT-29 L4: Verify and decode a pagination cursor.
 * Returns the {p, s, f, t} payload on success, null on failure (expired,
 * tampered, malformed, or wrong filter fingerprint).
 *
 * @param string     $cursor  Token from URL
 * @param array|null $filters Current filter set — must match the token's fingerprint
 */
function verifyPageCursor(string $cursor, ?array $filters = null): ?array {
    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    if (!$decoded || strpos($decoded, '|') === false) return null;
    $sepPos  = strrpos($decoded, '|');
    $payload = substr($decoded, 0, $sepPos);
    $sig     = substr($decoded, $sepPos + 1);

    $secret   = getenv('SITE_HMAC_SECRET') ?: hash('sha256', __DIR__ . '|abchem-pagination');
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $sig)) return null;

    $data = json_decode($payload, true);
    if (!is_array($data) || !isset($data['p'], $data['t'])) return null;

    // Reject tokens older than 1 hour (3600s) — defeats long-term scraper replay
    if (time() - (int)$data['t'] > 3600) return null;

    // Filter-set fingerprint check — same cursor on a different filter is rejected
    if ($filters !== null && isset($data['f'])) {
        if ($data['f'] !== md5(json_encode($filters))) return null;
    }

    return $data;
}

/**
 * FEAT-29 L4: Soft honeypot — log requests for deep pages without a cursor
 * AND without a Referer header. Returns true if the request looks bot-y so
 * callers can choose to deny / serve fake results.
 *
 * Designed to be non-breaking: legitimate users hitting page 2 from the
 * catalog will always have a Referer set by the browser. Search-engine
 * crawlers respect robots.txt so /catalog?page=N shouldn't be hit anyway
 * (sitemap.xml drives them to individual product URLs).
 */
function detectDeepPageScraping(int $requestedPage, bool $hasValidCursor): bool {
    if ($hasValidCursor || $requestedPage <= 5) return false;
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer === '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '?';
        error_log("[antiscrape:L4] page=$requestedPage no-cursor no-referer ip=$ip ua=" . substr($ua, 0, 80));
        return true;
    }
    return false;
}

/**
 * Q4 (DECIDED 2026-05-23): Cache-to-disk strategy for RDKit structure images.
 *
 * Renders SMILES → PNG via rdkit_search.py once, caches under
 * /public_html/compound_images/{slug}.png, and returns the public URL.
 *
 * Idempotent — re-calling for an already-cached compound is an O(1) file_exists
 * check. Safe to call at import time (eager) or from product.php (lazy).
 * Returns empty string if RDKit fails or SMILES is invalid (caller falls back
 * to the logo placeholder).
 *
 * Scale: at 100K compounds × ~50KB ≈ 5GB on disk — well within Hostinger quota.
 * Per-request latency drops from ~500ms (RDKit subprocess) to ~5ms (Apache
 * serving a static file).
 *
 * @param string $smiles  Canonical or stereo SMILES (RDKit-parseable)
 * @param string $slug    URL-safe identifier; becomes the filename stem
 * @param bool   $force   Re-render even if cache file exists (admin re-gen)
 * @return string Public URL (e.g. "/compound_images/aspirin.png") or "" on failure
 */
function getOrGenerateCompoundImage(string $smiles, string $slug, bool $force = false): string {
    $smiles = trim($smiles);
    $slug   = trim($slug);
    if ($smiles === '' || $smiles === 'NA' || $slug === '') return '';

    // Sanitize slug to a filesystem-safe filename
    $safeSlug = preg_replace('/[^a-z0-9_-]/i', '-', $slug);
    $safeSlug = preg_replace('/-+/', '-', trim($safeSlug, '-'));
    if ($safeSlug === '') return '';

    $cacheDir  = realpath(__DIR__ . '/../public_html') . '/compound_images';
    $cachePath = $cacheDir . '/' . $safeSlug . '.png';
    $publicUrl = '/compound_images/' . $safeSlug . '.png';

    // Fast path: cache hit
    if (!$force && file_exists($cachePath) && filesize($cachePath) > 500) {
        return $publicUrl;
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    // Invoke rdkit_search.py — same proc_open pattern used by api_stereo.php
    $script = realpath(__DIR__ . '/../public_html/rdkit_search.py');
    if (!$script || !file_exists($script)) {
        error_log("getOrGenerateCompoundImage: rdkit_search.py not found");
        return '';
    }

    $payload = [
        'action'     => 'draw',
        'smiles'     => $smiles,
        'format'     => 'png',
        'width'      => 400,
        'height'     => 300,
        'cache_path' => $cachePath,  // rdkit_search.py writes the PNG here directly
    ];

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open(['python3', $script], $desc, $pipes);
    if (!is_resource($proc)) {
        error_log("getOrGenerateCompoundImage: proc_open failed");
        return '';
    }
    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // rdkit_search.py returns base64 PNG when no cache_path is set; with
    // cache_path it writes the file directly. Decode the JSON only to check
    // for errors — the actual bytes are already on disk.
    $result = json_decode($stdout, true);
    if (!is_array($result) || empty($result['valid'])) {
        error_log("getOrGenerateCompoundImage: rdkit failed for $slug: " . substr($stdout ?: '(empty)', 0, 200));
        return '';
    }

    // rdkit_search.py "draw" only auto-writes when format=svg + cache_path.
    // For PNG we need to decode the base64 ourselves and write the file.
    if (!file_exists($cachePath) && !empty($result['png_base64'])) {
        $png = base64_decode($result['png_base64']);
        if ($png !== false && strlen($png) > 500) {
            file_put_contents($cachePath, $png);
        }
    }

    return file_exists($cachePath) && filesize($cachePath) > 500 ? $publicUrl : '';
}

/**
 * Look up pharmacopeia monographs that reference this compound.
 *
 * Match priority:
 *   1. Direct CAS match on pharmacopeia_monographs.cas_number
 *   2. Parent-drug slug (for impurities) — slugified parent_drug → compound_slug
 *
 * Returns rows joined with the standard (code, name) so callers can render
 * official-link buttons. Empty array when nothing matches.
 *
 * @param string|null $cas        Compound's CAS number (primary)
 * @param string|null $parentDrug Compound's parent_drug field (for impurities)
 */
function getPharmacopeiaLinks(?string $cas, ?string $parentDrug = null): array {
    $cas = trim((string)$cas);
    $cas = ($cas === '' || $cas === 'NA') ? null : $cas;

    $parentSlug = null;
    if (!empty($parentDrug) && $parentDrug !== 'NA') {
        $parentSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $parentDrug), '-'));
        if ($parentSlug === '') $parentSlug = null;
    }

    if (!$cas && !$parentSlug) return [];

    $where = [];
    $params = [];
    if ($cas)        { $where[] = 'pm.cas_number = :cas';            $params['cas']   = $cas; }
    if ($parentSlug) { $where[] = 'pm.compound_slug = :slug';        $params['slug']  = $parentSlug; }

    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT pm.id, pm.compound_slug, pm.monograph_code, pm.official_url,
                ps.code AS standard_code, ps.name AS standard_name, ps.base_url
         FROM pharmacopeia_monographs pm
         JOIN pharmacopeia_standards ps ON ps.id = pm.standard_id
         WHERE " . implode(' OR ', $where) . "
         ORDER BY FIELD(ps.code, 'EP','USP','JP','IP','WHO'), ps.code",
        $params
    );
}

// =============================================
// INDEXNOW — instant re-crawl ping (FEAT-32)
// Notifies Bing/Yandex immediately when a compound
// URL changes; Google follows within hours.
// Key file must exist at: /public_html/<KEY>.txt
// =============================================
define('INDEXNOW_KEY', '96f50ef1d97756cecbce640a4f59bcfe');

/**
 * Ping IndexNow for one or more product URLs so search engines
 * re-crawl the pages immediately after data changes.
 *
 * @param string|string[] $urls  Absolute URL(s) — e.g. "https://www.abchem.co.in/product/..."
 */
function indexNowPing(string|array $urls): void {
    if (PHP_SAPI === 'cli') return; // skip during CLI imports
    $urls = array_values(array_filter((array)$urls));
    if (empty($urls)) return;

    $payload = json_encode([
        'host'    => 'www.abchem.co.in',
        'key'     => INDEXNOW_KEY,
        'keyLocation' => 'https://www.abchem.co.in/' . INDEXNOW_KEY . '.txt',
        'urlList' => $urls,
    ]);

    // Fire-and-forget via non-blocking socket so it never slows a page request
    $endpoint = 'api.indexnow.org';
    $path     = '/indexnow';
    $header   = "POST {$path} HTTP/1.1\r\n"
              . "Host: {$endpoint}\r\n"
              . "Content-Type: application/json; charset=utf-8\r\n"
              . "Content-Length: " . strlen($payload) . "\r\n"
              . "Connection: close\r\n\r\n";
    try {
        $sock = @fsockopen("ssl://{$endpoint}", 443, $errno, $errstr, 2);
        if ($sock) {
            fwrite($sock, $header . $payload);
            fclose($sock);
        }
    } catch (\Throwable $e) {
        error_log('IndexNow ping failed: ' . $e->getMessage());
    }
}

/**
 * Build the canonical public URL for a compound row (array with id, ab_catalog_number,
 * url_token, slug).  Returns null if no usable identifier is available.
 */
function compoundPublicUrl(array $compound): ?string {
    $base = 'https://www.abchem.co.in';
    if (!empty($compound['ab_catalog_number']) && !empty($compound['url_token'])) {
        $token = rawurlencode($compound['ab_catalog_number'] . '-' . $compound['url_token']);
        if (!empty($compound['url_slug'])) {
            return $base . '/product/' . rawurlencode($compound['url_slug']) . '/' . $token;
        }
        return $base . '/product/' . $token;
    }
    if (!empty($compound['slug'])) {
        return $base . '/product/' . rawurlencode($compound['slug']);
    }
    return null;
}

// =============================================
// SEO META
// =============================================

function get_seo_meta(?array $product = null): array {
    $site = 'AB Chem India';
    $baseUrl = 'https://www.abchem.co.in';
    if ($product) {
        $desc = sprintf('Buy %s (CAS: %s) - %s purity. %s. GMP-grade from Hyderabad, India.',
            $product['product_name'] ?? 'Chemical Compound',
            $product['cas_number'] ?? 'N/A',
            $product['purity'] ?? 'High',
            $product['product_type'] ?? 'Reference Standard'
        );
        return [
            'title'       => sprintf('%s | CAS %s | %s', e($product['product_name']), e($product['cas_number']), $site),
            'description' => $desc,
            'url'         => compoundPublicUrl($product) ?? $baseUrl . '/product/' . e($product['slug']),
            'image'       => $product['image_url'] ? $baseUrl . e($product['image_url']) : $baseUrl . '/logo.png'
        ];
    }
    return [
        'title'       => 'Pharmaceutical Standards & APIs | ' . $site,
        'description' => 'High-purity APIs, impurities, and reference standards with CoA. GMP-compliant manufacturing in Hyderabad, India.',
        'url'         => $baseUrl,
        'image'       => $baseUrl . '/logo.png'
    ];
}

// =============================================
// USER FUNCTIONS
// =============================================

function getUsers(): array {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT id, email, role, user_type, company_name, contact_name,
                phone, status, approved_by, approved_at, created_at, last_login_at
         FROM users ORDER BY created_at DESC"
    );
}

function getUserByEmail(string $email): ?array {
    $db = Database::getInstance();
    return $db->fetchOne("SELECT * FROM users WHERE email = :email", ['email' => strtolower($email)]);
}

function getUserById(int $id): ?array {
    $db = Database::getInstance();
    return $db->fetchOne(
        "SELECT id, email, role, user_type, company_name, contact_name,
                phone, status, created_at, last_login_at
         FROM users WHERE id = :id",
        ['id' => $id]
    );
}

function saveUsers(array $users): bool {
    error_log("saveUsers() called - database version doesn't need this");
    return true;
}

// =============================================
// INQUIRY FUNCTIONS
// =============================================

function getQueries(): array {
    $db = Database::getInstance();
    return $db->fetchAll("SELECT * FROM inquiries ORDER BY created_at DESC");
}

function saveQuery(array $data): int {
    $db = Database::getInstance();
    return $db->insert('inquiries', [
        'name'         => sanitize_input($data['name'] ?? ''),
        'email'        => filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL),
        'inquiry_type' => sanitize_input($data['type'] ?? 'general'),
        'subject'      => sanitize_input($data['subject'] ?? ''),
        'message'      => trim($data['message'] ?? ''),
        'status'       => 'New',
        'created_at'   => date('Y-m-d H:i:s'),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
}

function updateQueryStatus(int $id, string $status, string $note = ''): int {
    $db = Database::getInstance();
    $data = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
    if ($note) $data['admin_note'] = $note;
    if (isset($_SESSION['user'])) $data['updated_by'] = $_SESSION['user'];
    return $db->update('inquiries', $data, 'id = :id', ['id' => $id]);
}

// =============================================
// AUDIT LOG — STRUCTURED (Item 16)
// =============================================

function logAudit(string $action, string $detail, string $old = '', string $new = ''): int {
    $db = Database::getInstance();
    $resourceType = explode('_', $action)[0] ?? 'general';
    $resourceId   = null;
    if (preg_match('/#(\d+)/', $detail, $m)) $resourceId = (int)$m[1];

    return $db->insert('audit_log', [
        'user_id'       => $_SESSION['user_id'] ?? null,
        'user_email'    => $_SESSION['user'] ?? 'system',
        'user_role'     => $_SESSION['role'] ?? '',
        'action'        => $action,
        'detail'        => $detail,
        'resource_type' => $resourceType,
        'resource_id'   => $resourceId,
        'old_value'     => $old ? (json_validate($old) ? $old : json_encode($old)) : null,
        'new_value'     => $new ? (json_validate($new) ? $new : json_encode($new)) : null,
        'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        'created_at'    => date('Y-m-d H:i:s')
    ]);
}

function getAuditLog(int $limit = 300): array {
    $db = Database::getInstance();
    return $db->fetchAll("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT :limit", ['limit' => $limit]);
}

function downloadAuditCSV(): never {
    $logs = getAuditLog(10000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Timestamp','User ID','User Email','Role','Action','Detail','Resource Type','Resource ID','Old Value','New Value','IP','User Agent'], ',', '"', '\\');
    foreach ($logs as $r) {
        fputcsv($out, [
            $r['created_at'], $r['user_id'] ?? '', $r['user_email'], $r['user_role'],
            $r['action'], $r['detail'], $r['resource_type'] ?? '', $r['resource_id'] ?? '',
            $r['old_value'] ?? '', $r['new_value'] ?? '', $r['ip_address'], $r['user_agent'] ?? ''
        ], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

// =============================================
// REPORTS
// =============================================

function getMonthlyReport(): array {
    $db = Database::getInstance();
    $total = (int)$db->fetchValue("SELECT COUNT(*) FROM compounds WHERE status = 'Active'");
    $criticalFields = ['cas_number','molecular_formula','molecular_weight','smiles','inchi_key'];
    $fieldMissing = [];
    foreach ($criticalFields as $field) {
        $fieldMissing[$field] = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM compounds WHERE status = 'Active' AND ($field IS NULL OR $field = '' OR $field = 'NA')"
        );
    }
    $complete = (int)$db->fetchValue(
        "SELECT COUNT(*) FROM compounds WHERE status = 'Active'
         AND cas_number IS NOT NULL AND cas_number != '' AND cas_number != 'NA'
         AND molecular_formula IS NOT NULL AND molecular_formula != '' AND molecular_formula != 'NA'
         AND smiles IS NOT NULL AND smiles != '' AND smiles != 'NA'"
    );
    $incompleteList = $db->fetchAll(
        "SELECT compound_name AS product_name, cas_number FROM compounds WHERE status = 'Active'
           AND (cas_number IS NULL OR cas_number = '' OR cas_number = 'NA'
             OR molecular_formula IS NULL OR molecular_formula = '' OR molecular_formula = 'NA'
             OR smiles IS NULL OR smiles = '' OR smiles = 'NA') LIMIT 50"
    );
    return [
        'month' => date('F Y'), 'total' => $total, 'complete' => $complete,
        'incomplete' => $total - $complete,
        'score' => $total ? round(($complete / $total) * 100) : 100,
        'field_missing' => $fieldMissing, 'items' => $incompleteList,
        'generated_at' => date('Y-m-d H:i:s'), 'generated_by' => $_SESSION['user'] ?? 'system'
    ];
}

function downloadMonthlyReportCSV(): never {
    $r = getMonthlyReport();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="data_report_' . date('Y_m') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Report Month', $r['month']], ',', '"', '\\');
    fputcsv($out, ['Total Products', $r['total']], ',', '"', '\\');
    fputcsv($out, ['Complete Records', $r['complete']], ',', '"', '\\');
    fputcsv($out, ['Incomplete Records', $r['incomplete']], ',', '"', '\\');
    fputcsv($out, ['Completeness Score', $r['score'] . '%'], ',', '"', '\\');
    fputcsv($out, [], ',', '"', '\\');
    fputcsv($out, ['Product Name', 'CAS Number'], ',', '"', '\\');
    foreach ($r['items'] as $i) fputcsv($out, [$i['product_name'], $i['cas_number']], ',', '"', '\\');
    fclose($out);
    exit;
}

function trackProductView($userId, $productId) {
    if (empty($userId) || empty($productId)) return;
    $db = Database::getInstance();
    try {
        // Guard against stale product_id (merged/deleted) — FK would otherwise throw 23000.
        $db->query(
            "INSERT INTO recently_viewed (user_id, product_id, viewed_at)
             SELECT :uid, :pid, NOW() FROM DUAL
             WHERE EXISTS (SELECT 1 FROM products WHERE id = :pid_check)
             ON DUPLICATE KEY UPDATE viewed_at = NOW()",
            ['uid' => $userId, 'pid' => $productId, 'pid_check' => $productId]
        );
    } catch (Exception $e) { /* silent */ }
}

function createNotification($userId, $type, $title, $message, $link = null) {
    $db = Database::getInstance();
    return $db->insert('notifications', [
        'user_id' => $userId, 'type' => $type, 'title' => $title,
        'message' => $message, 'link' => $link, 'created_at' => date('Y-m-d H:i:s')
    ]);
}

function generateOrderNumber($prefix = 'ORD') {
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

// =============================================
// ZOHO INTEGRATION
// =============================================

function triggerZohoInvoice(array $query): array {
    $helperPath = ABSPATH . 'zoho_helper.php';
    if (!file_exists($helperPath)) return ['error' => 'Zoho helper not configured'];
    require_once $helperPath;
    return ZohoBooks::createInvoiceFromInquiry($query);
}

// =============================================
// CUSTOM FIELDS
// =============================================

function getCustomFields(?int $productId = null): array { return []; }

function saveProductCustomData(int $productId, array $customFields): bool {
    return false; // compounds table has no custom_data — legacy stub
}

function getProductCustomData(int $productId): array {
    return []; // compounds table has no custom_data — legacy stub
}

function getActiveCustomFields(): array { return []; }

function generateCatalogNumber(string $companyMake, string $productType): string {
    $typeCode = PRODUCT_TYPE_CODES[$productType] ?? 'GEN';

    $db  = Database::getInstance();
    $row = $db->fetchOne(
        "SELECT supplier_code FROM suppliers WHERE supplier_name = :n OR supplier_code = :c LIMIT 1",
        ['n' => $companyMake, 'c' => strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $companyMake), 0, 3))]
    );
    if ($row && !empty($row['supplier_code'])) {
        $supplierCode = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $row['supplier_code']));
    } else {
        $supplierCode = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $companyMake), 0, 3));
        $supplierCode = str_pad($supplierCode, 3, 'X');
    }

    $prefix = "{$supplierCode}-{$typeCode}-";
    // BUG-05: ORDER BY numeric suffix, not insert order — prevents duplicate generation
    // DISCUSS-16: 6-digit zero-padded sequence (uniform across all suppliers,
    // capacity 999,999 per supplier × type). Migration 005 retrofits older
    // 4-digit and 5-digit values to this format.
    $last = $db->fetchValue(
        "SELECT catalog_number FROM supplier_listings
         WHERE catalog_number LIKE :p
         ORDER BY CAST(SUBSTRING(catalog_number, :offset) AS UNSIGNED) DESC
         LIMIT 1",
        ['p' => $prefix . '%', 'offset' => strlen($prefix) + 1]
    );
    // Use SUBSTRING_INDEX so we tolerate any suffix length (4, 5, or 6 digits)
    // during the rollover window. Going forward all new numbers are 6 digits.
    $lastSuffix = $last ? (int) preg_replace('/^.*-/', '', $last) : 0;
    $nextNum    = $lastSuffix + 1;
    return sprintf('%s%06d', $prefix, $nextNum);
}

function isCatalogNumberUnique(string $catalogNumber, ?int $excludeListingId = null): bool {
    $db = Database::getInstance();
    $sql    = "SELECT id FROM supplier_listings WHERE catalog_number = :cat";
    $params = ['cat' => $catalogNumber];
    if ($excludeListingId) { $sql .= " AND id != :id"; $params['id'] = $excludeListingId; }
    return $db->fetchValue($sql, $params) === null;
}

function saveCustomField(int $productId, int $fieldId, ?string $value): bool {
    return false; // legacy stub — compounds table has no custom_data
}

function sendQuoteStatusEmail(int $quoteId, string $newStatus, string $adminNote = ''): bool {
    $db = Database::getInstance();
    $quote = $db->fetchOne("SELECT * FROM quote_requests WHERE id = :id", ['id' => $quoteId]);
    if (!$quote) return false;
    $user = $db->fetchOne("SELECT email, contact_name, company_name FROM users WHERE id = :uid", ['uid' => $quote['user_id']]);
    if (!$user) return false;
    $subject = "Quote #{$quote['quote_number']} - Status Updated";
    $statusLabels = ['new'=>'Received','quoted'=>'Priced & Ready for Review','accepted'=>'Accepted – Order Created','rejected'=>'Declined'];
    $sanitizedNote = htmlspecialchars($adminNote, ENT_QUOTES, 'UTF-8');
    $message  = "Dear " . ($user['contact_name'] ?? $user['company_name'] ?? 'Customer') . ",\n\n";
    $message .= "Your quote request #{$quote['quote_number']} has been updated:\n";
    $message .= "→ New Status: " . ($statusLabels[$newStatus] ?? $newStatus) . "\n\n";
    if ($sanitizedNote) $message .= "Admin Note:\n{$sanitizedNote}\n\n";
    if ($newStatus === 'quoted' && $quote['quoted_amount']) {
        $message .= "Quoted Amount: ₹" . number_format($quote['quoted_amount'], 2) . "\n";
        if ($quote['quote_valid_until']) $message .= "Valid Until: " . date('d M Y', strtotime($quote['quote_valid_until'])) . "\n";
        $message .= "\n👉 Review and accept your quote: https://www.abchem.co.in/quote-detail?id={$quote['id']}\n";
    }
    $message .= "\n--\nAB Chem India\nHyderabad, India\nwww.abchem.co.in";
    return sendProfessionalEmail($user['email'], $subject, nl2br($message));
}

// =============================================
// CART MANAGEMENT
// =============================================

function initCart(): void {
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
}

function addToCart(int $productId, array $productData, float $quantity = 1, string $unit = 'mg', array $customFields = []): string {
    initCart();
    $customHash = !empty($customFields) ? '_' . substr(md5(json_encode($customFields)), 0, 8) : '';
    $cartKey = "{$productId}{$customHash}";
    if (isset($_SESSION['cart'][$cartKey])) {
        $_SESSION['cart'][$cartKey]['quantity'] += $quantity;
    } else {
        $_SESSION['cart'][$cartKey] = [
            'product_id'   => $productId,
            'product_name' => $productData['product_name'] ?? '',
            'slug'         => $productData['slug'] ?? '',
            'cas_number'   => $productData['cas_number'] ?? null,
            'purity'       => $productData['purity'] ?? null,
            'quantity'     => $quantity,
            'unit'         => $unit,
            'custom_fields'=> $customFields,
            'added_at'     => time()
        ];
    }
    return $cartKey;
}

function getCartCount(): int { initCart(); return array_sum(array_column($_SESSION['cart'], 'quantity')); }

function getCartSummary(): array {
    initCart();
    $totalItems = 0; $uniqueProducts = 0;
    foreach ($_SESSION['cart'] as $item) { $totalItems += $item['quantity']; $uniqueProducts++; }
    return ['unique_products' => $uniqueProducts, 'total_quantity' => $totalItems, 'items' => $_SESSION['cart']];
}

function clearCart(): void { $_SESSION['cart'] = []; }

// =============================================
// CSV IMPORT
// =============================================

// =============================================
// COMPOUND DEDUPLICATION HELPERS  (Phase 3)
// =============================================

/**
 * Normalise a value for deduplication — returns null for blank/NA values.
 */
function nullIfEmpty(mixed $val): mixed {
    if ($val === null || trim((string)$val) === '' || strtoupper(trim((string)$val)) === 'NA') return null;
    return $val;
}

// =============================================
// AB CHEM CATALOG NUMBER & URL TOKEN HELPERS
// =============================================

/**
 * generateAbCatalogNumber()
 * Returns the next unique ABChem catalog number in the format ABC00001PK
 * (prefix ABC + 5-digit zero-padded sequence + 2 random uppercase letters).
 * The 2 random letters make sequential enumeration by scrapers impossible.
 */
function generateAbCatalogNumber(): string {
    $db = Database::getInstance();
    $last = $db->fetchValue(
        "SELECT ab_catalog_number FROM compounds
         WHERE ab_catalog_number REGEXP '^ABC[0-9]{5}[A-Z]{2}$'
         ORDER BY CAST(SUBSTRING(ab_catalog_number, 4, 5) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $nextNum = 1;
    if ($last && preg_match('/^ABC(\d{5})/', $last, $m)) {
        $nextNum = (int)$m[1] + 1;
    }
    do {
        $letters = chr(mt_rand(65, 90)) . chr(mt_rand(65, 90));
        $catalog = sprintf('ABC%05d%s', $nextNum, $letters);
        $clash   = $db->fetchValue(
            "SELECT id FROM compounds WHERE ab_catalog_number = :c", ['c' => $catalog]
        );
        if ($clash) $nextNum++;
    } while ($clash);
    return $catalog;
}

/**
 * makeUrlSlug(string $name)
 * Converts a compound name into a clean, URL-safe hyphenated slug.
 * "Aprepitant EP Impurity E" → "aprepitant-ep-impurity-e"
 * "(R)-Ketoprofen"           → "r-ketoprofen"
 */
function makeUrlSlug(string $name): string {
    $s = strtolower($name);
    // Collapse all non-alphanumeric runs into a single hyphen
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 200);
}

/**
 * generateUrlToken()
 * Returns a unique 6-character uppercase hex token (e.g. "7E23F8").
 * Stored in compounds.url_token and used as the second half of the product URL.
 */
function generateUrlToken(): string {
    $db = Database::getInstance();
    do {
        $token = strtoupper(bin2hex(random_bytes(3)));
        $clash = $db->fetchValue(
            "SELECT id FROM compounds WHERE url_token = :t", ['t' => $token]
        );
    } while ($clash);
    return $token;
}

/**
 * buildProductUrl(array $compound)
 * Returns the canonical product URL for a compound.
 * New format: /product/{url_slug}/{ab_catalog_number}-{url_token}
 * Falls back to opaque token-only or legacy slug for older rows.
 */
function buildProductUrl(array $compound): string {
    if (!empty($compound['ab_catalog_number']) && !empty($compound['url_token'])) {
        $token = rawurlencode($compound['ab_catalog_number'] . '-' . $compound['url_token']);
        if (!empty($compound['url_slug'])) {
            return '/product/' . rawurlencode($compound['url_slug']) . '/' . $token;
        }
        return '/product/' . $token;
    }
    return '/product/' . rawurlencode($compound['slug'] ?? '');
}

/**
 * getProductByToken(string $urlKey)
 * Resolves a product page from the opaque URL key "ABC00032PC-7E23F8".
 * Returns the same rich array as getProductBySlug() or null if not found/invalid.
 */
function getProductByToken(string $urlKey): ?array {
    if (!preg_match('/^(ABC\d{5}[A-Z]{2})-([0-9A-F]{6})$/i', $urlKey, $m)) return null;
    $abCatalog = strtoupper($m[1]);
    $token     = strtoupper($m[2]);

    $cacheKey = 'abchem:products:token:' . $abCatalog . '-' . $token;
    $cached   = cacheGet($cacheKey);
    if ($cached !== false) return $cached;

    $db = Database::getInstance();
    $compound = $db->fetchOne(
        "SELECT c.* FROM compounds c
         WHERE c.ab_catalog_number = :cat AND c.url_token = :tok AND c.status = 'Active'",
        ['cat' => $abCatalog, 'tok' => $token]
    );
    if (!$compound) return null;

    $compound['product_name'] = $compound['compound_name'];

    $compound['listings'] = $db->fetchAll(
        "SELECT sl.*, s.supplier_name AS company_make, s.supplier_code
         FROM supplier_listings sl
         JOIN suppliers s ON s.id = sl.supplier_id
         WHERE sl.compound_id = :id AND sl.status = 'Active'
         ORDER BY sl.supplier_id, sl.purity",
        ['id' => $compound['id']]
    );

    if (!empty($compound['listings'])) {
        $primary = $compound['listings'][0];
        foreach (['purity','availability','stock_status','lead_time','min_order_qty',
                  'unit','catalog_number','company_make','lot_number',
                  'manufacture_date','expiry_date'] as $f) {
            $compound[$f] = $primary[$f] ?? null;
        }
        $compound['listing_id'] = $primary['id'];
    }
    $compound['custom_fields'] = [];

    cacheSet($cacheKey, $compound, 300);
    return $compound;
}

/**
 * findExistingCompound()
 * Checks the compounds table using a priority chain:
 *   1. CAS number  — universally used, on every CoA
 *   2. InChIKey    — encodes exact stereochemistry, disambiguates isomers
 *   3. IUPAC name  — normalised lowercase match
 *   4. Compound name — last resort, prone to typos
 *
 * Returns ['id', 'name', 'matched_by'] or null if not found.
 */
function findExistingCompound(array $data): ?array {
    $db = Database::getInstance();

    // 1. CAS number
    $cas = nullIfEmpty($data['cas_number'] ?? null);
    if ($cas) {
        $row = $db->fetchOne(
            "SELECT id, compound_name FROM compounds WHERE cas_number = :v LIMIT 1",
            ['v' => trim($cas)]
        );
        if ($row) return ['id' => (int)$row['id'], 'name' => $row['compound_name'], 'matched_by' => 'CAS number'];
    }

    // 2. InChIKey
    $ik = nullIfEmpty($data['inchi_key'] ?? null);
    if ($ik) {
        $row = $db->fetchOne(
            "SELECT id, compound_name FROM compounds WHERE inchi_key = :v LIMIT 1",
            ['v' => trim($ik)]
        );
        if ($row) return ['id' => (int)$row['id'], 'name' => $row['compound_name'], 'matched_by' => 'InChIKey'];
    }

    // 3. IUPAC name (case-insensitive, trimmed)
    $iupac = nullIfEmpty($data['iupac_name'] ?? null);
    if ($iupac) {
        $row = $db->fetchOne(
            "SELECT id, compound_name FROM compounds WHERE LOWER(TRIM(iupac_name)) = LOWER(TRIM(:v)) LIMIT 1",
            ['v' => $iupac]
        );
        if ($row) return ['id' => (int)$row['id'], 'name' => $row['compound_name'], 'matched_by' => 'IUPAC name'];
    }

    // 4. Compound / product name (last resort)
    $cname = nullIfEmpty($data['compound_name'] ?? $data['product_name'] ?? null);
    if ($cname) {
        $row = $db->fetchOne(
            "SELECT id, compound_name FROM compounds WHERE LOWER(TRIM(compound_name)) = LOWER(TRIM(:v)) LIMIT 1",
            ['v' => $cname]
        );
        if ($row) return ['id' => (int)$row['id'], 'name' => $row['compound_name'], 'matched_by' => 'compound name'];
    }

    return null;
}

/**
 * saveCompound()
 * Insert a new compound or update an existing one.
 * Only fills fields that are currently empty on an update —
 * existing data is never silently overwritten.
 *
 * @param array    $data        Form/CSV data
 * @param int|null $compoundId  Pass existing id to UPDATE, null to INSERT
 * @param bool     $forceUpdate If true, overwrites all chemical fields even if already set
 * @return int  compound id
 */
function saveCompound(array $data, ?int $compoundId = null, bool $forceUpdate = false): int {
    $db = Database::getInstance();

    // Build slug
    $slug = nullIfEmpty($data['slug'] ?? null)
         ?? strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-',
                $data['compound_name'] ?? $data['product_name'] ?? 'compound')));
    $slug = trim(preg_replace('/-+/', '-', $slug), '-');

    // Ensure slug is unique
    $baseSlug = $slug; $counter = 1;
    while (true) {
        $params = ['s' => $slug];
        $sql    = "SELECT id FROM compounds WHERE slug = :s";
        if ($compoundId) { $sql .= " AND id != :id"; $params['id'] = $compoundId; }
        if (!$db->fetchValue($sql, $params)) break;
        $slug = $baseSlug . '-' . $counter++;
    }

    $compoundData = [
        'slug'                => substr($slug, 0, 150),
        'compound_name'       => substr($data['compound_name'] ?? $data['product_name'] ?? '', 0, 255),
        'cas_number'          => nullIfEmpty($data['cas_number']        ?? null),
        'parent_drug'         => nullIfEmpty($data['parent_drug']       ?? null),
        'smiles'              => nullIfEmpty($data['smiles']            ?? null),
        'smiles_canonical'    => nullIfEmpty($data['smiles_canonical']  ?? null),
        'inchi'               => nullIfEmpty($data['inchi']             ?? null),
        'inchi_key'           => nullIfEmpty(substr($data['inchi_key'] ?? '', 0, 50)),
        'iupac_name'          => nullIfEmpty($data['iupac_name']       ?? null),
        'pubchem_cid'         => is_numeric($data['pubchem_cid'] ?? '') ? (int)$data['pubchem_cid'] : null,
        'synonyms'            => nullIfEmpty($data['synonyms']          ?? null),
        'molecular_formula'   => nullIfEmpty($data['molecular_formula'] ?? null),
        'molecular_weight'    => is_numeric($data['molecular_weight'] ?? '') ? (float)$data['molecular_weight'] : null,
        'product_type'        => nullIfEmpty($data['product_type']      ?? null),
        'storage_condition'   => nullIfEmpty($data['storage_condition'] ?? null),
        'image_url'           => nullIfEmpty($data['image_url']         ?? null),
        'therapeutic_category'=> nullIfEmpty($data['therapeutic_category'] ?? null),
        'regulatory_ref'      => nullIfEmpty($data['regulatory_ref']   ?? null),
        'hazard_class'        => nullIfEmpty($data['hazard_class']      ?? null),
        'meta_title'          => nullIfEmpty(substr($data['meta_title'] ?? '', 0, 200)),
        'meta_description'    => nullIfEmpty($data['meta_description']  ?? null),
        'keywords'            => nullIfEmpty($data['keywords']          ?? null),
        'status'              => in_array($data['status'] ?? '', ['Active','Inactive','Draft'])
                                 ? $data['status'] : 'Active',
        'created_by'          => nullIfEmpty($data['created_by']       ?? null),
        // ── Stereochemistry fields (FEAT-37) ──────────────────────────────
        'stereo_status'       => in_array($data['stereo_status'] ?? '', ['achiral','verified','unverified','manual_review'])
                                 ? $data['stereo_status'] : null,
        'stereo_source'       => nullIfEmpty($data['stereo_source']    ?? null),
        'smiles_stereo'       => nullIfEmpty($data['smiles_stereo']    ?? null),
    ];

    // Auto-assign ABChem catalog number, URL token, and URL slug on INSERT only
    if (!$compoundId) {
        $compoundData['ab_catalog_number'] = generateAbCatalogNumber();
        $compoundData['url_token']         = generateUrlToken();
        $compoundData['url_slug']          = makeUrlSlug(
            $data['compound_name'] ?? $data['product_name'] ?? ''
        );
    }

    if ($compoundId) {
        if (!$forceUpdate) {
            // Only fill currently-empty fields — never silently overwrite
            $current = $db->fetchOne("SELECT * FROM compounds WHERE id = :id", ['id' => $compoundId]);
            foreach ($compoundData as $field => $val) {
                if (in_array($field, ['slug','status'])) continue; // always update these; compound_name is fill-only
                if (!empty($current[$field]) && $current[$field] !== 'NA') {
                    unset($compoundData[$field]); // already has a value — skip
                }
            }
        }
        // Strip fields that would violate UNIQUE constraints (uq_inchi_key, idx_cas)
        // and stash the conflict in session so admin sees a warning + dedup link.
        _stripUniqueConflicts($db, $compoundData, $compoundId);
        $db->update('compounds', $compoundData, 'id = :id', ['id' => $compoundId]);
        // Ping IndexNow so search engines re-crawl the updated product page immediately
        _indexNowForCompound($compoundId, $db);
        return $compoundId;
    }

    // Same guard on INSERT — covers bulk-import paths that skip findExistingCompound
    _stripUniqueConflicts($db, $compoundData, null);
    $newId = $db->insert('compounds', $compoundData);
    // Ping IndexNow for new compound using the new slug/token URL
    $pingUrl = compoundPublicUrl($compoundData);
    if ($pingUrl) indexNowPing($pingUrl);
    return $newId;
}

/**
 * Pre-write guard for UNIQUE constraint columns on `compounds`.
 *
 * inchi_key (uq_inchi_key) and cas_number (idx_cas — UNIQUE despite the name)
 * will throw SQLSTATE 23000 / 1062 if the value being written already belongs
 * to a DIFFERENT compound row. This helper checks each constrained field
 * against other rows; if a conflict is found:
 *   1. The field is removed from $data (so the INSERT/UPDATE still succeeds
 *      for the non-conflicting fields)
 *   2. The conflict details are appended to $_SESSION['compound_conflicts'],
 *      which admin_products.php renders as a yellow warning panel with a
 *      direct link to admin_dedup.php
 *
 * NOTE: this prevents the 1062 crash but does NOT merge anything. The admin
 * must run admin_dedup.php to consolidate the duplicate compound rows.
 */
function _stripUniqueConflicts($db, array &$data, ?int $excludeId): void {
    // Map: $data field name => column to query
    $uniqueFields = [
        'inchi_key'  => 'inchi_key',
        'cas_number' => 'cas_number',
    ];

    foreach ($uniqueFields as $field => $column) {
        if (empty($data[$field]) || $data[$field] === 'NA') continue;

        $params = ['v' => $data[$field]];
        $sql    = "SELECT id, compound_name, ab_catalog_number
                   FROM compounds
                   WHERE `$column` = :v";
        if ($excludeId !== null) {
            $sql .= " AND id != :id";
            $params['id'] = $excludeId;
        }
        $sql .= " LIMIT 1";

        $other = $db->fetchOne($sql, $params);
        if (!$other) continue;

        // Conflict — strip the field and record the warning
        error_log("[saveCompound] UNIQUE conflict: $field='{$data[$field]}' already in compound #{$other['id']} ({$other['compound_name']}). Skipping that field on compound " . ($excludeId ?? '(new)') . ".");
        unset($data[$field]);

        if (!isset($_SESSION['compound_conflicts'])) $_SESSION['compound_conflicts'] = [];
        $_SESSION['compound_conflicts'][] = [
            'this_id'       => $excludeId,
            'field'         => $field,
            'value'         => $other[$column] ?? '',
            'other_id'      => (int)$other['id'],
            'other_name'    => $other['compound_name'] ?? '',
            'other_catalog' => $other['ab_catalog_number'] ?? '',
        ];
    }
}

/** Helper: fetch compound URL from DB and ping IndexNow. Internal use only. */
function _indexNowForCompound(int $compoundId, $db): void {
    $row = $db->fetchOne(
        "SELECT ab_catalog_number, url_token, url_slug, slug FROM compounds WHERE id = :id",
        ['id' => $compoundId]
    );
    if (!$row) return;
    $url = compoundPublicUrl($row);
    if ($url) indexNowPing($url);
}

/**
 * saveSupplierListing()
 * Insert or update a supplier listing for a compound.
 * Deduplicates on (compound_id, supplier_id, purity, purity_by_method).
 * Auto-generates catalog number if missing.
 *
 * @return int  listing id
 */
function saveSupplierListing(array $data, int $compoundId): int {
    $db = Database::getInstance();

    // Resolve supplier_id from name or code
    $supplierId = null;
    if (!empty($data['supplier_id']) && is_numeric($data['supplier_id'])) {
        $tentativeId = (int)$data['supplier_id'];
        // Validate the ID actually exists — a missing supplier would cause an FK violation
        $exists = $db->fetchValue("SELECT id FROM suppliers WHERE id = :id", ['id' => $tentativeId]);
        if ($exists) {
            $supplierId = $tentativeId;
        } else {
            error_log("saveSupplierListing: supplier_id {$tentativeId} not found in suppliers table — will use default");
        }
    }
    if (!$supplierId && !empty($data['company_make'])) {
        $supplierId = (int)$db->fetchValue(
            "SELECT id FROM suppliers WHERE supplier_name = :n OR supplier_code = :c LIMIT 1",
            ['n' => $data['company_make'], 'c' => strtoupper(substr($data['company_make'], 0, 20))]
        );
    }
    if (!$supplierId) $supplierId = 1; // Default: Sunveda (id=1)

    // Catalog number: use CSV value if supplied; will auto-generate below only when inserting
    $csvCatalogNumber = nullIfEmpty($data['catalog_number'] ?? null);

    $listingData = [
        'compound_id'              => $compoundId,
        'supplier_id'              => $supplierId,
        'catalog_number'           => $csvCatalogNumber ? substr($csvCatalogNumber, 0, 80) : null,
        'supplier_catalog_number'  => nullIfEmpty(substr($data['supplier_catalog_number'] ?? '', 0, 100)),
        'supplier_product_name'    => nullIfEmpty($data['product_name'] ?? $data['compound_name'] ?? null),
        'purity'                => nullIfEmpty($data['purity']           ?? null),
        'purity_by_method'      => nullIfEmpty($data['purity_by_method'] ?? null),
        'availability'          => in_array($data['availability'] ?? '', ['In Stock','Backorder','Discontinued','On Request'])
                                   ? $data['availability'] : 'On Request',
        'stock_status'          => in_array($data['stock_status'] ?? '', ['in_stock','low_stock','backordered','discontinued'])
                                   ? $data['stock_status'] : 'in_stock',
        'min_order_qty'         => is_numeric($data['min_order_qty'] ?? '') ? (float)$data['min_order_qty'] : 1.0,
        'unit'                  => in_array($data['unit'] ?? '', ['mg','g','kg','ml','L','vial','ampoule','tablet','capsule','lot'])
                                   ? $data['unit'] : 'mg',
        'lead_time'             => nullIfEmpty($data['lead_time']        ?? null),
        'lot_number'            => nullIfEmpty($data['lot_number']       ?? null),
        'manufacture_date'      => !empty($data['manufacture_date']) && $data['manufacture_date'] !== 'NA'
                                   ? date('Y-m-d', strtotime($data['manufacture_date'])) : null,
        'expiry_date'           => !empty($data['expiry_date']) && $data['expiry_date'] !== 'NA'
                                   ? date('Y-m-d', strtotime($data['expiry_date'])) : null,
        'quantity_available'    => is_numeric($data['quantity'] ?? '') ? (float)$data['quantity'] : null,
        'price_on_request'      => 1,
        'certificate_url'       => nullIfEmpty($data['certificate_url']  ?? null),
        'storage_override'      => nullIfEmpty($data['storage_override'] ?? null),
        'supplier_notes'        => nullIfEmpty($data['supplier_notes']   ?? null),
        // DONE-35: low-confidence (name-only) matches stage as Draft; everything
        // else (high/medium/new) goes live as Active. _match_confidence is set
        // by the import paths in importCompoundsFromCSV / importFromSupplierExcel.
        'status'                => ($data['_match_confidence'] ?? null) === 'low' ? 'Draft' : 'Active',
        'match_confidence'      => $data['_match_confidence'] ?? null,
        'created_by'            => nullIfEmpty($data['created_by']       ?? null),
    ];

    // Deduplicate: same compound + supplier + purity + method = update existing
    $existing = $db->fetchOne(
        "SELECT id, catalog_number, status FROM supplier_listings
         WHERE compound_id  = :cid
           AND supplier_id  = :sid
           AND COALESCE(purity,'')            = COALESCE(:p,'')
           AND COALESCE(purity_by_method,'')  = COALESCE(:m,'')
         LIMIT 1",
        ['cid' => $compoundId, 'sid' => $supplierId,
         'p'   => $listingData['purity'], 'm' => $listingData['purity_by_method']]
    );

    if ($existing) {
        // Preserve the existing catalog_number unless the CSV explicitly provided one
        if (!$csvCatalogNumber) {
            $listingData['catalog_number'] = $existing['catalog_number'];
        }
        // DONE-35: never downgrade an existing Active/Inactive listing to Draft
        // on re-import. A listing already promoted/curated stays where it is.
        if (!empty($existing['status']) && $existing['status'] !== 'Draft') {
            $listingData['status'] = $existing['status'];
        }
        $db->update('supplier_listings', $listingData, 'id = :id', ['id' => $existing['id']]);
        return (int)$existing['id'];
    }

    // INSERT path: auto-generate catalog number if the CSV didn't supply one.
    // DISCUSS-16: uniform 6-digit format. Same logic as generateCatalogNumber()
    // — kept inline here to avoid an extra DB lookup in the import hot path.
    if (!$listingData['catalog_number']) {
        $typeCode    = PRODUCT_TYPE_CODES[$data['product_type'] ?? ''] ?? 'GEN';
        $supplierRow = $db->fetchOne("SELECT supplier_code FROM suppliers WHERE id = :id", ['id' => $supplierId]);
        $supplierCode = !empty($supplierRow['supplier_code'])
            ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', $supplierRow['supplier_code']))
            : 'GEN';
        $prefix  = "{$supplierCode}-{$typeCode}-";
        // Order by numeric suffix to always get the true maximum, not just the most recently inserted
        $lastNum = $db->fetchValue(
            "SELECT catalog_number FROM supplier_listings
             WHERE catalog_number LIKE :p
             ORDER BY CAST(SUBSTRING(catalog_number, :offset) AS UNSIGNED) DESC
             LIMIT 1",
            ['p' => $prefix . '%', 'offset' => strlen($prefix) + 1]
        );
        // Suffix-length tolerant — survives the rollover from 4-digit values
        $lastSuffix = $lastNum ? (int) preg_replace('/^.*-/', '', $lastNum) : 0;
        $nextNum    = $lastSuffix + 1;
        $listingData['catalog_number'] = substr(sprintf('%s%06d', $prefix, $nextNum), 0, 80);
    }

    return $db->insert('supplier_listings', $listingData);
}

/**
 * importCompoundsFromCSV()
 * Replaces importProductsFromCSV — writes to compounds + supplier_listings.
 * Full deduplication on every row before insert.
 *
 * Implements:
 *   - BUG-02/03: CAS conflict detection — mismatched CAS warned, not silently applied
 *   - DONE-04 / DISCUSS-35: confidence-tiered matching — name-only matches warned
 *   - DONE-06: full warning/conflict report returned to admin
 *   - Post-import PubChem fetch + RDKit stereo check for new compounds (≤ 20 rows)
 */
function importCompoundsFromCSV(string $csvPath): array {
    $db = Database::getInstance();
    $db->beginTransaction();
    try {
        $handle = fopen($csvPath, 'r');
        if (!$handle) throw new RuntimeException("Cannot open CSV file");

        // Read headers and normalize them so that display labels from the XLSX template
        // ("compound_name *", "synonyms (pipe-separated)", "expiry_date (YYYY-MM-DD)",
        //  "catalog_number (auto if blank)") map cleanly to the field names the rest of
        //  the function expects ("compound_name", "synonyms", "expiry_date", "catalog_number").
        $rawHeaders = fgetcsv($handle, 0, ',', '"', '\\') ?: [];
        $headers = array_map(function (string $h): string {
            $h = ltrim(trim($h), "\xEF\xBB\xBF"); // strip UTF-8 BOM (Excel / our template)
            $h = trim($h);
            $h = rtrim($h, ' *');                  // "compound_name *"  → "compound_name"
            $h = trim($h);
            // Strip trailing parenthetical annotations:
            //   "(pipe-separated)"  "(YYYY-MM-DD)"  "(auto if blank)"  etc.
            $h = preg_replace('/\s*\([^)]*\)\s*$/', '', $h);
            return strtolower(trim($h));
        }, $rawHeaders);

        $inserted         = $updated = $newListings = $updatedListings = $skipped = 0;
        $dedupLog         = [];
        $warnings         = [];  // CAS conflicts, low-confidence matches, SMILES issues
        $pubchemQueue     = [];  // compound IDs queued for PubChem + stereo post-import
        $rowNum           = 0;

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rowNum++;
            if (count($row) < count($headers)) $row = array_pad($row, count($headers), '');
            $data = array_combine($headers, $row);

            // Normalise name key — CSV may use either header
            $name = trim($data['compound_name'] ?? $data['product_name'] ?? '');
            if (empty($name)) { $skipped++; continue; }
            $data['compound_name'] = $name;

            // ── BUG-02/03: Normalize CAS — treat 'NA', '', null as no CAS ──
            $incomingCas = (string)(nullIfEmpty(trim($data['cas_number'] ?? '')) ?? '');
            $data['cas_number'] = $incomingCas; // propagate normalised value

            $supplierCatNum = trim($data['supplier_catalog_number'] ?? '');

            // ── Q2 (DECIDED 2026-05-23): Auto-accept CAS conflicts ─────────
            // When the incoming CAS resolves to a different compound than the
            // name, we used to drop the CAS. Now we keep the name match,
            // protect the existing primary CAS, AND register the conflicting
            // CAS as an alias on the name-matched compound (source='supplier').
            // End users see both via cas_verify + the product page's "Other
            // CAS Numbers" row. Search hits both via compound_cas_aliases.
            if ($incomingCas) {
                $byCas = $db->fetchOne(
                    "SELECT id, compound_name FROM compounds WHERE cas_number = :c LIMIT 1",
                    ['c' => $incomingCas]
                );
                if ($byCas) {
                    $byName = $db->fetchOne(
                        "SELECT id FROM compounds WHERE LOWER(TRIM(compound_name)) = LOWER(TRIM(:n)) LIMIT 1",
                        ['n' => $name]
                    );
                    if ($byName && (int)$byName['id'] !== (int)$byCas['id']) {
                        $warnings[] = [
                            'row'     => $rowNum,
                            'name'    => $name,
                            'cas'     => $incomingCas,
                            'type'    => 'cas_conflict',
                            'message' => "CAS {$incomingCas} also belongs to compound #{$byCas['id']} '{$byCas['compound_name']}' — auto-accepted as alias on #{$byName['id']}",
                        ];
                        // Register conflicting CAS as a supplier-sourced alias
                        try {
                            $db->insert('compound_cas_aliases', [
                                'compound_id' => (int)$byName['id'],
                                'cas_number'  => $incomingCas,
                                'source'      => 'supplier',
                                'position'    => 0,
                            ]);
                        } catch (Exception $e) {
                            // UNIQUE KEY = already an alias; ignore
                        }
                        $data['cas_number'] = ''; // primary CAS stays unchanged on matched compound
                    }
                }
            }

            // ── DONE-35 (DECIDED 2026-05-23): confidence-tiered staging ────
            // High (CAS/InChIKey) → merge + go live. Medium (IUPAC) → merge +
            // go live + warn. Low (name only) → merge BUT stage new listing
            // as Draft so admin reviews before it's visible to customers.
            $existing = findExistingCompound($data);
            $confidence = 'new';

            if ($existing) {
                $confidence = match ($existing['matched_by']) {
                    'CAS number', 'InChIKey' => 'high',
                    'IUPAC name'             => 'medium',
                    default                  => 'low',
                };

                if ($confidence === 'low') {
                    $warnings[] = [
                        'row'     => $rowNum,
                        'name'    => $name,
                        'type'    => 'low_confidence_match',
                        'message' => "Matched #{$existing['id']} '{$existing['name']}' by name only — listing staged as Draft for admin review",
                    ];
                }

                $compoundId = $existing['id'];
                saveCompound($data, $compoundId, false); // fill-only, no overwrite
                $updated++;
                $dedupLog[] = [
                    'row'        => $rowNum,
                    'csv_name'   => $name,
                    'matched_by' => $existing['matched_by'],
                    'matched_to' => $existing['name'],
                    'confidence' => $confidence,
                    'action'     => $confidence === 'low'
                        ? 'merged — supplier listing staged as Draft (low confidence)'
                        : 'merged — supplier listing added/updated',
                ];

                // Queue for PubChem if CAS present but SMILES missing
                if ($incomingCas) {
                    $needsFetch = $db->fetchValue(
                        "SELECT id FROM compounds WHERE id=:id AND (smiles IS NULL OR smiles='') LIMIT 1",
                        ['id' => $compoundId]
                    );
                    if ($needsFetch) $pubchemQueue[] = $compoundId;
                }

            } else {
                $data['status'] = 'Active';
                $compoundId = saveCompound($data);
                $inserted++;
                $pubchemQueue[] = $compoundId; // always queue new compounds
            }

            // ── SUPPLIER LISTING ──────────────────────────────────────────
            $data['supplier_catalog_number'] = $supplierCatNum;
            // Pass confidence so saveSupplierListing can stage low-confidence
            // matches as Draft (only on freshly inserted rows; updates retain status)
            $data['_match_confidence'] = $confidence;
            $listingsBefore = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM supplier_listings WHERE compound_id = :id", ['id' => $compoundId]
            );
            saveSupplierListing($data, $compoundId);
            $listingsAfter = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM supplier_listings WHERE compound_id = :id", ['id' => $compoundId]
            );
            if ($listingsAfter > $listingsBefore) $newListings++; else $updatedListings++;
        }

        fclose($handle);
        $db->commit();
        clearProductCache();

        // ── Post-import: PubChem fetch + stereo check ─────────────────────
        // Only auto-run for small imports (≤ 20 compounds) to avoid HTTP timeout.
        $pubchemQueue   = array_unique($pubchemQueue);
        $pubchemFetched = 0;
        $pubchemErrors  = [];

        if (count($pubchemQueue) <= 20) {
            require_once __DIR__ . '/../public_html/pubchem_fetch.php';
            foreach ($pubchemQueue as $cid) {
                try {
                    $fetcher = new PubChemFetcher();
                    $result  = $fetcher->fetchProductById((int)$cid);
                    if (!isset($result['error'])) {
                        $pubchemFetched++;
                        _stereoCheckAfterImport((int)$cid, $db);
                    } else {
                        $pubchemErrors[] = "ID {$cid}: " . ($result['error'] ?? 'unknown error');
                    }
                } catch (Exception $e) {
                    $pubchemErrors[] = "ID {$cid}: " . $e->getMessage();
                }
            }
        }

        return [
            'success'          => true,
            'inserted'         => $inserted,
            'updated'          => $updated,
            'new_listings'     => $newListings,
            'updated_listings' => $updatedListings,
            'skipped'          => $skipped,
            'total_rows'       => $inserted + $updated + $skipped,
            'dedup_log'        => $dedupLog,
            'warnings'         => $warnings,
            'pubchem_queued'   => count($pubchemQueue),
            'pubchem_fetched'  => $pubchemFetched,
            'pubchem_errors'   => $pubchemErrors,
            'pubchem_note'     => count($pubchemQueue) > 20
                ? 'More than 20 new/updated compounds — use "PubChem Batch Fetch" in the data tools tab to enrich them'
                : null,
        ];
    } catch (Exception $e) {
        $db->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// importProductsFromCSV() removed — superseded by importCompoundsFromCSV()

/**
 * importFromSupplierExcel()
 * Import supplier compound data from a coloured XLSX template (generated by
 * SupplierTemplateGenerator).  Supersedes the CSV path for supplier meetings.
 *
 * Implements:
 *   - BUG-02/03: CAS conflict detection — mis-matched CAS is warned, not silently applied
 *   - BUG-04 / DISCUSS-35: confidence-tiered matching — name-only matches staged as Draft
 *   - FEAT-06: full warning/conflict report returned to admin
 *   - Post-import PubChem fetch + RDKit stereo check for new compounds (≤ 20 rows)
 *
 * @param string $xlsxPath    Absolute path to uploaded .xlsx file
 * @param int    $supplierId  Resolved supplier ID (not taken from spreadsheet)
 * @return array              Import report
 */
function importFromSupplierExcel(string $xlsxPath, int $supplierId): array {
    require_once __DIR__ . '/SimpleXlsxReader.php';

    $reader = new SimpleXlsxReader();
    if (!$reader->open($xlsxPath)) {
        return ['success' => false, 'error' => 'Cannot open XLSX file — ensure it is a valid .xlsx format'];
    }
    $rows = $reader->readAsNamedRows();
    $reader->close();

    if (empty($rows)) {
        return ['success' => false, 'error' => 'No data rows found in the spreadsheet'];
    }

    $db = Database::getInstance();
    $db->beginTransaction();

    try {
        $inserted         = 0;
        $updated          = 0;
        $newListings      = 0;
        $updatedListings  = 0;
        $skipped          = 0;
        $dedupLog         = [];
        $warnings         = [];   // CAS conflicts, low-confidence matches, SMILES issues
        $pubchemQueue     = [];   // compound IDs queued for PubChem + stereo post-import

        foreach ($rows as $rowNum => $data) {
            // Supplier is always from the import call, never from spreadsheet
            $data['supplier_id'] = $supplierId;

            $name = trim($data['compound_name'] ?? '');
            if ($name === '') { $skipped++; continue; }
            $data['compound_name'] = $name;

            // nullIfEmpty normalises 'NA', '', null → '' so downstream CAS checks skip correctly
            $incomingCas    = (string)(nullIfEmpty(trim($data['cas_number'] ?? '')) ?? '');
            $data['cas_number'] = $incomingCas; // propagate normalised value to saveCompound
            $supplierCatNum = trim($data['supplier_catalog_number'] ?? '');

            // ── Q2 (DECIDED 2026-05-23): Auto-accept CAS conflicts ─────────
            // See identical block in importCompoundsFromCSV above for rationale.
            // The conflicting CAS becomes a supplier-sourced alias on the
            // name-matched compound rather than being dropped.
            if ($incomingCas) {
                $byName = null;
                $byCas  = $db->fetchOne(
                    "SELECT id, compound_name FROM compounds WHERE cas_number = :c LIMIT 1",
                    ['c' => $incomingCas]
                );
                if ($byCas) {
                    $byName = $db->fetchOne(
                        "SELECT id, compound_name FROM compounds WHERE LOWER(TRIM(compound_name)) = LOWER(TRIM(:n)) LIMIT 1",
                        ['n' => $name]
                    );
                    if ($byName && (int)$byName['id'] !== (int)$byCas['id']) {
                        $warnings[] = [
                            'row'     => $rowNum + 1,
                            'name'    => $name,
                            'cas'     => $incomingCas,
                            'type'    => 'cas_conflict',
                            'message' => "CAS {$incomingCas} also belongs to compound #{$byCas['id']} '{$byCas['compound_name']}' — auto-accepted as alias on #{$byName['id']} '{$byName['compound_name']}'",
                        ];
                        // Register conflicting CAS as a supplier-sourced alias
                        try {
                            $db->insert('compound_cas_aliases', [
                                'compound_id' => (int)$byName['id'],
                                'cas_number'  => $incomingCas,
                                'source'      => 'supplier',
                                'position'    => 0,
                            ]);
                        } catch (Exception $e) {
                            // UNIQUE KEY = already an alias; ignore
                        }
                        $data['cas_number'] = ''; // primary CAS stays unchanged on matched compound
                    }
                }
            }

            // ── DONE-35 (DECIDED 2026-05-23): confidence-tiered staging ────
            // See identical block in importCompoundsFromCSV above for rationale.
            $existing = findExistingCompound($data);
            $confidence = 'new';

            if ($existing) {
                $confidence = match ($existing['matched_by']) {
                    'CAS number', 'InChIKey' => 'high',
                    'IUPAC name'             => 'medium',
                    default                  => 'low',
                };

                if ($confidence === 'low') {
                    $warnings[] = [
                        'row'     => $rowNum + 1,
                        'name'    => $name,
                        'type'    => 'low_confidence_match',
                        'message' => "Matched #{$existing['id']} '{$existing['name']}' by name only — listing staged as Draft for admin review",
                    ];
                }

                $compoundId = $existing['id'];
                saveCompound($data, $compoundId, false); // fill-only — never overwrite existing
                $updated++;
                $dedupLog[] = [
                    'row'        => $rowNum + 1,
                    'csv_name'   => $name,
                    'matched_by' => $existing['matched_by'],
                    'matched_to' => $existing['name'],
                    'confidence' => $confidence,
                    'action'     => $confidence === 'low'
                        ? 'merged — supplier listing staged as Draft (low confidence)'
                        : 'merged',
                ];

                // Queue for PubChem if CAS present but SMILES missing
                if ($incomingCas) {
                    $needsFetch = $db->fetchValue(
                        "SELECT id FROM compounds WHERE id=:id AND (smiles IS NULL OR smiles='') LIMIT 1",
                        ['id' => $compoundId]
                    );
                    if ($needsFetch) $pubchemQueue[] = $compoundId;
                }

            } else {
                // New compound — set status Active (high-confidence: CAS or name unambiguous)
                $data['status'] = 'Active';
                $compoundId = saveCompound($data);
                $inserted++;

                // Always queue new compounds for PubChem enrichment
                $pubchemQueue[] = $compoundId;
            }

            // ── Supplier listing ───────────────────────────────────────────
            $data['supplier_catalog_number'] = $supplierCatNum;
            $data['_match_confidence'] = $confidence;
            $countBefore = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM supplier_listings WHERE compound_id=:id", ['id' => $compoundId]
            );
            saveSupplierListing($data, $compoundId);
            $countAfter = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM supplier_listings WHERE compound_id=:id", ['id' => $compoundId]
            );
            if ($countAfter > $countBefore) $newListings++; else $updatedListings++;
        }

        $db->commit();
        clearProductCache();

        // ── Post-import: PubChem fetch + stereo check ─────────────────────
        // Only auto-run for small imports (≤ 20 compounds) to avoid HTTP timeout.
        // Larger batches: admin uses the existing PubChem batch + stereo batch buttons.
        $pubchemQueue   = array_unique($pubchemQueue);
        $pubchemFetched = 0;
        $pubchemErrors  = [];

        if (count($pubchemQueue) <= 20) {
            require_once __DIR__ . '/../public_html/pubchem_fetch.php';
            foreach ($pubchemQueue as $cid) {
                try {
                    $fetcher = new PubChemFetcher();
                    $result  = $fetcher->fetchProductById((int)$cid);
                    if (!isset($result['error'])) {
                        $pubchemFetched++;
                        // Trigger RDKit stereo check now that SMILES may be populated
                        _stereoCheckAfterImport((int)$cid, $db);
                    } else {
                        $pubchemErrors[] = "ID {$cid}: " . ($result['error'] ?? 'unknown error');
                    }
                } catch (Exception $e) {
                    $pubchemErrors[] = "ID {$cid}: " . $e->getMessage();
                }
            }
        }

        return [
            'success'           => true,
            'inserted'          => $inserted,
            'updated'           => $updated,
            'new_listings'      => $newListings,
            'updated_listings'  => $updatedListings,
            'skipped'           => $skipped,
            'total_rows'        => $inserted + $updated + $skipped,
            'dedup_log'         => $dedupLog,
            'warnings'          => $warnings,
            'pubchem_queued'    => count($pubchemQueue),
            'pubchem_fetched'   => $pubchemFetched,
            'pubchem_errors'    => $pubchemErrors,
            'pubchem_note'      => count($pubchemQueue) > 20
                ? 'More than 20 new/updated compounds — use "PubChem Batch Fetch" in the data tools tab to enrich them'
                : null,
        ];

    } catch (Exception $e) {
        $db->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * _stereoCheckAfterImport()
 * Run the RDKit stereo_check action on a single compound that was just enriched
 * by PubChem fetch (so SMILES is now populated).  Updates stereo_status in DB.
 * Internal helper — not part of the public API.
 */
function _stereoCheckAfterImport(int $compoundId, $db): void {
    $row = $db->fetchOne(
        "SELECT id, smiles, inchi_key FROM compounds WHERE id=:id", ['id' => $compoundId]
    );
    if (empty($row['smiles']) || $row['smiles'] === 'NA') return;
    if (!empty($row['stereo_status'])) return; // already checked

    $payload    = json_encode([
        'action'    => 'stereo_check',
        'compounds' => [['id' => $compoundId, 'smiles' => $row['smiles'], 'inchi_key' => $row['inchi_key'] ?? '']],
    ]);
    $scriptPath = dirname(__DIR__) . '/public_html/rdkit_search.py';
    if (!file_exists($scriptPath)) return;

    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open(['python3', $scriptPath], $desc, $pipes);
    if (!is_resource($proc)) return;

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $result = json_decode($out, true);
    $res    = $result['results'][0] ?? null;
    if ($res && in_array($res['stereo_status'] ?? '', ['achiral','unverified'], true)) {
        $db->query(
            "UPDATE compounds SET stereo_status=:s, updated_at=NOW() WHERE id=:id",
            [':s' => $res['stereo_status'], ':id' => $compoundId]
        );
    }
}

function clean_output($data) {
    return htmlspecialchars($data ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ─── File Upload Validation (Items 6 + randomised filename) ──────────────────
function validate_image_upload($file): array {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024;

    if ($file['error'] !== UPLOAD_ERR_OK) return ['error' => 'Upload failed with code: ' . $file['error']];
    if ($file['size'] > $maxSize) return ['error' => 'File too large (max 5MB)'];

    // Real MIME via finfo — never trust client-supplied type
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realType = $finfo->file($file['tmp_name']);
    if (!in_array($realType, $allowed)) {
        error_log("SECURITY: Upload rejected - real MIME: {$realType}, claimed: {$file['type']}, IP: {$_SERVER['REMOTE_ADDR']}");
        return ['error' => 'Invalid file type detected'];
    }

    // Structural image check
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        error_log("SECURITY: Upload rejected - not a valid image, IP: {$_SERVER['REMOTE_ADDR']}");
        return ['error' => 'File is not a valid image'];
    }

    // Item 6: Randomise filename — blocks null-byte, path-traversal, double-extension attacks
    $randomFilename = bin2hex(random_bytes(16)) . '.webp';

    return ['success' => true, 'type' => $realType, 'filename' => $randomFilename];
}

function log_security_event($event, $details = '') {
    $logFile = dirname(__DIR__) . '/logs/security.log';
    $entry   = date('Y-m-d H:i:s') . ' | ' . ($_SERVER['REMOTE_ADDR'] ?? 'CLI') . ' | ' . $event . ' | ' . $details . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// FEAT-29: Honeypot handler — logs bot/scraper hit and returns 410 Gone
function logHoneypotHit(): void {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    log_security_event('HONEYPOT_HIT', "URI: $uri | UA: $ua");
    try {
        $db = Database::getInstance();
        $db->insert('audit_log', [
            'user_id'       => null,
            'user_email'    => 'bot-detection',
            'user_role'     => '',
            'action'        => 'HONEYPOT_HIT',
            'detail'        => "Scraper/bot visited honeypot URL: $uri",
            'resource_type' => 'security',
            'resource_id'   => null,
            'ip_address'    => $ip,
            'user_agent'    => substr($ua, 0, 255),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) { /* non-critical */ }
    http_response_code(410);
    exit;
}

// =============================================
// RATE LIMITER WITH PROGRESSIVE DELAY — BUG-18
// DB-backed (login_attempts table) — reliable on shared hosting.
// Schema (auto-created on first use):
//   CREATE TABLE login_attempts (
//     id           INT AUTO_INCREMENT PRIMARY KEY,
//     attempt_key  VARCHAR(255) NOT NULL,
//     attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
//     INDEX idx_key_time (attempt_key, attempted_at)
//   );
// =============================================
class RateLimiter {

    /** Ensure the login_attempts table exists. Safe to call on every request. */
    private static function ensureTable(): void {
        try {
            $db = Database::getInstance();
            $db->query("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id           INT AUTO_INCREMENT PRIMARY KEY,
                    attempt_key  VARCHAR(255) NOT NULL,
                    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX        idx_key_time (attempt_key, attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { /* ignore — table may already exist */ }
    }

    /**
     * Record one attempt and return true if within limit, false if locked out.
     *
     * @param string $key          Unique key, e.g. "{ip}_{email}"
     * @param int    $maxAttempts  Lock-out threshold (default 5)
     * @param int    $decayMinutes Sliding window length (default 15)
     */
    public static function check(string $key, int $maxAttempts = 5, int $decayMinutes = 15): bool {
        self::ensureTable();
        $db     = Database::getInstance();
        $window = date('Y-m-d H:i:s', time() - $decayMinutes * 60);

        // Count attempts already recorded in the sliding window
        $count = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM login_attempts
             WHERE attempt_key = :k AND attempted_at >= :w",
            ['k' => $key, 'w' => $window]
        );

        // Hard lock-out — do NOT record another row, just refuse
        if ($count >= $maxAttempts) return false;

        // Record this attempt
        $db->query(
            "INSERT INTO login_attempts (attempt_key, attempted_at) VALUES (:k, NOW())",
            ['k' => $key]
        );

        $attempt = $count + 1; // 1-based for logic below

        // Progressive delay after 3rd attempt: 1s, 2s, 4s, 8s (capped at 8s)
        if ($attempt >= 3) {
            sleep((int)min(pow(2, $attempt - 3), 8));
        }

        // Email alert on exactly the 3rd failed attempt
        if ($attempt === 3) {
            self::sendAlertEmail($key);
        }

        // Prune old rows to keep the table tidy (~10 % of requests)
        if (mt_rand(1, 10) === 1) {
            $cutoff = date('Y-m-d H:i:s', time() - 86400); // keep last 24 h
            try {
                $db->query("DELETE FROM login_attempts WHERE attempted_at < :c", ['c' => $cutoff]);
            } catch (\Throwable $e) { /* non-critical */ }
        }

        return true;
    }

    private static function sendAlertEmail(string $key): void {
        $parts   = explode('_', $key, 2);
        $email   = filter_var($parts[1] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$email) return;
        $ip      = $parts[0] ?? 'unknown';
        $subject = 'Security Alert: Multiple Failed Login Attempts — AB Chem India';
        $body    = "<p>Dear user,</p>
<p>We detected 3 failed login attempts on your AB Chem India account
(<strong>" . htmlspecialchars($email) . "</strong>) from IP <strong>" . htmlspecialchars($ip) . "</strong>.</p>
<p>If this was not you, your password may be compromised.
Please <a href='https://www.abchem.co.in/forgot-password'>reset your password</a> immediately.</p>
<p>— AB Chem India Security Team</p>";
        if (function_exists('sendProfessionalEmail')) sendProfessionalEmail($email, $subject, $body);
    }

    /** Clear all attempts for a key (call on successful login). */
    public static function clear(string $key): void {
        try {
            $db = Database::getInstance();
            $db->query("DELETE FROM login_attempts WHERE attempt_key = :k", ['k' => $key]);
        } catch (\Throwable $e) { /* non-critical */ }
    }
}

// =============================================
// WHATSAPP INTEGRATION
// =============================================

define('ADMIN_WHATSAPP_PHONE', '+910000000000'); // Update with actual admin number

function sendWhatsAppMessage($phone, $message): bool {
    // Basic validation
    if (empty($phone) || empty($message)) return false;

    $logEntry = date('Y-m-d H:i:s') . " | TO: $phone | MSG: " . str_replace(["\r", "\n"], " ", $message) . PHP_EOL;
    file_put_contents(dirname(__DIR__) . '/private/whatsapp.log', $logEntry, FILE_APPEND);

    // Ensure constants are defined before using them (e.g. in config.php)
    if (defined('TWILIO_SID') && defined('TWILIO_TOKEN') && defined('TWILIO_WHATSAPP_NUMBER')) {
        // Make sure phone is formatted correctly (e.g., starts with whatsapp:+)
        $formatted_phone = 'whatsapp:' . (strpos($phone, '+') === 0 ? $phone : '+' . $phone);

        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Messages.json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, TWILIO_SID . ":" . TWILIO_TOKEN);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'From' => TWILIO_WHATSAPP_NUMBER,
            'To' => $formatted_phone,
            'Body' => $message
        ]));
        $response = curl_exec($ch);
        curl_close($ch);

        // Return true if Twilio accepted the request (status 2xx)
        if ($response) {
            $respData = json_decode($response, true);
            return isset($respData['sid']);
        }
    }

    return true; // Still return true for log-only mode
}
