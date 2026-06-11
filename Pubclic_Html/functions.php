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

    $db = Database::getInstance();
    $sql = "SELECT id, slug, product_name, cas_number, molecular_formula,
                   molecular_weight, purity, product_type, availability,
                   image_url, lead_time, pubchem_cid, synonyms, iupac_name,
                   smiles, inchi, inchi_key, status
            FROM products WHERE status = 'Active'";
    $params = [];

    if (!empty($filters['product_type'])) {
        $sql .= " AND product_type = :type";
        $params['type'] = $filters['product_type'];
    }
    if (!empty($filters['search'])) {
        $sql .= " AND (product_name LIKE :search OR cas_number LIKE :search OR iupac_name LIKE :search OR synonyms LIKE :search)";
        $params['search'] = "%{$filters['search']}%";
    }

    $sql .= " ORDER BY product_name ASC";
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
    $product = $db->fetchOne(
        "SELECT id, slug, product_name, cas_number, parent_drug,
                smiles, inchi, inchi_key, iupac_name, molecular_formula,
                molecular_weight, purity, product_type, availability,
                image_url, lead_time, pubchem_cid, synonyms
         FROM products WHERE slug = :slug AND status = 'Active'",
        ['slug' => $slug]
    );
    if ($product) {
        $product['custom_fields'] = [];
        cacheSet($cacheKey, $product, 300);
    }
    return $product;
}

function getProductById(int $id): ?array {
    $cacheKey = 'abchem:products:id:' . $id;
    $cached = cacheGet($cacheKey);
    if ($cached !== false) return $cached;

    $db = Database::getInstance();
    $result = $db->fetchOne("SELECT * FROM products WHERE id = :id AND status = 'Active'", ['id' => $id]);
    if ($result) cacheSet($cacheKey, $result, 300);
    return $result;
}

function getTotalProductCount(array $filters = []): int {
    $cacheKey = 'abchem:products:count:' . md5(serialize($filters));
    $cached = cacheGet($cacheKey);
    if ($cached !== false) return (int)$cached;

    $db = Database::getInstance();
    $sql = "SELECT COUNT(*) FROM products WHERE status = 'Active'";
    $params = [];
    if (!empty($filters['product_type'])) {
        $sql .= " AND product_type = :type";
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
        "SELECT DISTINCT product_type FROM products
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
        "SELECT id, slug, product_name, cas_number, purity, availability, image_url
         FROM products WHERE product_type = :type AND id != :id AND status = 'Active'
         ORDER BY RAND() LIMIT :limit",
        ['type' => $productType, 'id' => $productId, 'limit' => $limit]
    );
}

function clearProductCache(): bool {
    ProductCache::clear();
    return true;
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
            'url'         => $baseUrl . '/product/' . e($product['slug']),
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
    fputcsv($out, ['Timestamp','User ID','User Email','Role','Action','Detail','Resource Type','Resource ID','Old Value','New Value','IP','User Agent']);
    foreach ($logs as $r) {
        fputcsv($out, [
            $r['created_at'], $r['user_id'] ?? '', $r['user_email'], $r['user_role'],
            $r['action'], $r['detail'], $r['resource_type'] ?? '', $r['resource_id'] ?? '',
            $r['old_value'] ?? '', $r['new_value'] ?? '', $r['ip_address'], $r['user_agent'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// =============================================
// REPORTS
// =============================================

function getMonthlyReport(): array {
    $db = Database::getInstance();
    $total = (int)$db->fetchValue("SELECT COUNT(*) FROM products WHERE status = 'Active'");
    $criticalFields = ['cas_number','molecular_formula','molecular_weight','purity','smiles','inchi_key'];
    $fieldMissing = [];
    foreach ($criticalFields as $field) {
        $fieldMissing[$field] = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM products WHERE status = 'Active' AND ($field IS NULL OR $field = '' OR $field = 'NA')"
        );
    }
    $complete = (int)$db->fetchValue(
        "SELECT COUNT(*) FROM products WHERE status = 'Active'
         AND cas_number IS NOT NULL AND cas_number != '' AND cas_number != 'NA'
         AND molecular_formula IS NOT NULL AND molecular_formula != '' AND molecular_formula != 'NA'
         AND smiles IS NOT NULL AND smiles != '' AND smiles != 'NA'"
    );
    $incompleteList = $db->fetchAll(
        "SELECT product_name, cas_number FROM products WHERE status = 'Active'
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
    fputcsv($out, ['Report Month', $r['month']]);
    fputcsv($out, ['Total Products', $r['total']]);
    fputcsv($out, ['Complete Records', $r['complete']]);
    fputcsv($out, ['Incomplete Records', $r['incomplete']]);
    fputcsv($out, ['Completeness Score', $r['score'] . '%']);
    fputcsv($out, []);
    fputcsv($out, ['Product Name', 'CAS Number']);
    foreach ($r['items'] as $i) fputcsv($out, [$i['product_name'], $i['cas_number']]);
    fclose($out);
    exit;
}

function trackProductView($userId, $productId) {
    $db = Database::getInstance();
    try {
        $db->query(
            "INSERT INTO recently_viewed (user_id, product_id, viewed_at)
             VALUES (:uid, :pid, NOW()) ON DUPLICATE KEY UPDATE viewed_at = NOW()",
            ['uid' => $userId, 'pid' => $productId]
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
    $db = Database::getInstance();
    $existing = $db->fetchValue("SELECT custom_data FROM products WHERE id = :id", ['id' => $productId]);
    $data = $existing ? json_decode($existing, true) : [];
    $data = array_merge($data, $customFields);
    $ok = $db->update('products',
        ['custom_data' => json_encode($data, JSON_UNESCAPED_UNICODE)],
        'id = :id', ['id' => $productId]
    ) > 0;
    cacheDelete('abchem:products:id:' . $productId);
    return $ok;
}

function getProductCustomData(int $productId): array {
    $db = Database::getInstance();
    $json = $db->fetchValue("SELECT custom_data FROM products WHERE id = :id", ['id' => $productId]);
    return $json ? json_decode($json, true) : [];
}

function getActiveCustomFields(): array { return []; }

function generateCatalogNumber(string $companyMake, string $productType): string {
    $db = Database::getInstance();
    $companyCode = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $companyMake), 0, 3));
    $companyCode = str_pad($companyCode, 3, 'X');
    $typeMap = ['API Impurity'=>'IMP','Reference Standard'=>'STD','Metabolite'=>'MET','Intermediate'=>'INT','API'=>'API'];
    $typeCode = $typeMap[$productType] ?? 'GEN';
    $last = $db->fetchValue(
        "SELECT catalog_number FROM products WHERE catalog_number LIKE :prefix ORDER BY catalog_number DESC LIMIT 1",
        ['prefix' => "{$companyCode}-{$typeCode}-%"]
    );
    $nextNum = $last ? (int)substr($last, -4) + 1 : 1;
    return sprintf("%s-%s-%04d", $companyCode, $typeCode, $nextNum);
}

function isCatalogNumberUnique(string $catalogNumber, ?int $excludeProductId = null): bool {
    $db = Database::getInstance();
    $sql = "SELECT id FROM products WHERE catalog_number = :cat";
    $params = ['cat' => $catalogNumber];
    if ($excludeProductId) { $sql .= " AND id != :id"; $params['id'] = $excludeProductId; }
    return $db->fetchValue($sql, $params) === null;
}

function saveCustomField(int $productId, int $fieldId, ?string $value): bool {
    $db = Database::getInstance();
    $existing = $db->fetchValue("SELECT custom_data FROM products WHERE id = :id", ['id' => $productId]);
    $data = $existing ? json_decode($existing, true) : [];
    $data["field_{$fieldId}"] = $value;
    return $db->update('products',
        ['custom_data' => json_encode($data, JSON_UNESCAPED_UNICODE)],
        'id = :id', ['id' => $productId]
    ) > 0;
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

function importProductsFromCSV(string $csvPath): array {
    $db = Database::getInstance();
    $db->beginTransaction();
    try {
        $handle = fopen($csvPath, 'r');
        if (!$handle) throw new RuntimeException("Cannot open CSV file");
        $headers = array_map('trim', fgetcsv($handle, 0, ',', '"', '\\'));
        $inserted = $updated = $errors = 0;
        $usedSlugs = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($row) < count($headers)) $row = array_pad($row, count($headers), '');
            $data = array_combine($headers, $row);
            if (empty($data['product_name']) && empty($data['slug'])) continue;
            if (empty($data['slug']) && !empty($data['product_name'])) {
                $data['slug'] = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $data['product_name']));
                $data['slug'] = trim($data['slug'], '-');
            }
            $baseSlug = $data['slug']; $counter = 1;
            while (isset($usedSlugs[$data['slug']])) $data['slug'] = $baseSlug . '-' . $counter++;
            $usedSlugs[$data['slug']] = true;
            $productData = [
                'slug' => substr($data['slug'], 0, 100),
                'product_name' => substr($data['product_name'] ?? '', 0, 255),
                'cas_number' => (!empty($data['cas_number']) && $data['cas_number'] !== 'NA') ? $data['cas_number'] : null,
                'parent_drug' => (!empty($data['parent_drug']) && $data['parent_drug'] !== 'NA') ? $data['parent_drug'] : null,
                'company_make' => (!empty($data['company_make']) && $data['company_make'] !== 'NA') ? $data['company_make'] : null,
                'smiles' => (!empty($data['smiles']) && $data['smiles'] !== 'NA') ? $data['smiles'] : null,
                'inchi' => (!empty($data['inchi']) && $data['inchi'] !== 'NA') ? $data['inchi'] : null,
                'inchi_key' => (!empty($data['inchi_key']) && $data['inchi_key'] !== 'NA') ? substr($data['inchi_key'], 0, 50) : null,
                'iupac_name' => (!empty($data['iupac_name']) && $data['iupac_name'] !== 'NA') ? $data['iupac_name'] : null,
                'molecular_formula' => (!empty($data['molecular_formula']) && $data['molecular_formula'] !== 'NA') ? $data['molecular_formula'] : null,
                'molecular_weight' => (!empty($data['molecular_weight']) && is_numeric($data['molecular_weight'])) ? (float)$data['molecular_weight'] : null,
                'purity' => (!empty($data['purity']) && $data['purity'] !== 'NA') ? $data['purity'] : null,
                'product_type' => (!empty($data['product_type']) && $data['product_type'] !== 'NA') ? $data['product_type'] : null,
                'availability' => in_array($data['availability'] ?? '', ['In Stock','Backorder','Discontinued','On Request']) ? $data['availability'] : 'On Request',
                'image_url' => (!empty($data['image_url']) && $data['image_url'] !== 'NA') ? $data['image_url'] : null,
                'lead_time' => (!empty($data['lead_time']) && $data['lead_time'] !== 'NA') ? $data['lead_time'] : null,
                'pubchem_cid' => (!empty($data['pubchem_cid']) && is_numeric($data['pubchem_cid'])) ? (int)$data['pubchem_cid'] : null,
                'synonyms' => (!empty($data['synonyms']) && $data['synonyms'] !== 'NA') ? $data['synonyms'] : null,
                'lot_number' => (!empty($data['lot_number']) && $data['lot_number'] !== 'NA') ? $data['lot_number'] : null,
                'manufacture_date' => (!empty($data['manufacture_date']) && $data['manufacture_date'] !== 'NA') ? date('Y-m-d', strtotime($data['manufacture_date'])) : null,
                'expiry_date' => (!empty($data['expiry_date']) && $data['expiry_date'] !== 'NA') ? date('Y-m-d', strtotime($data['expiry_date'])) : null,
                'status' => 'Active'
            ];
            $existing = $db->fetchOne("SELECT id FROM products WHERE slug = :slug", ['slug' => $productData['slug']]);
            if ($existing) { $db->update('products', $productData, 'id = :id', ['id' => $existing['id']]); $updated++; }
            else { $db->insert('products', $productData); $inserted++; }
        }
        fclose($handle);
        $db->commit();
        clearProductCache();
        return ['success' => true, 'inserted' => $inserted, 'updated' => $updated, 'errors' => $errors];
    } catch (Exception $e) {
        $db->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
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

// =============================================
// RATE LIMITER WITH PROGRESSIVE DELAY (Item 3)
// =============================================
class RateLimiter {
    public static function check(string $key, int $maxAttempts = 5, int $decayMinutes = 15): bool {
        $storage = sys_get_temp_dir() . '/rate_' . md5($key);
        if (file_exists($storage)) {
            $data = json_decode(file_get_contents($storage), true);
            if (time() - $data['time'] < $decayMinutes * 60) {
                if ($data['attempts'] >= $maxAttempts) return false;
                $data['attempts']++;
            } else {
                $data = ['attempts' => 1, 'time' => time(), 'alerted' => false];
            }
        } else {
            $data = ['attempts' => 1, 'time' => time(), 'alerted' => false];
        }

        file_put_contents($storage, json_encode($data), LOCK_EX);

        // Progressive delay after 3rd attempt: 1s, 2s, 4s, 8s (capped)
        $attempt = $data['attempts'];
        if ($attempt >= 3) {
            $delay = min(pow(2, $attempt - 3), 8);
            sleep((int)$delay);
        }

        // Email alert after exactly 3 failed attempts (item 3)
        if ($attempt === 3 && empty($data['alerted'])) {
            self::sendAlertEmail($key);
            $data['alerted'] = true;
            file_put_contents($storage, json_encode($data), LOCK_EX);
        }

        return true;
    }

    private static function sendAlertEmail(string $key): void {
        $parts = explode('_', $key, 2);
        $email = filter_var($parts[1] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$email) return;
        $ip = $parts[0] ?? 'unknown';
        $subject = 'Security Alert: Multiple Failed Login Attempts — AB Chem India';
        $body = "<p>Dear user,</p>
<p>We detected 3 failed login attempts on your AB Chem India account
(<strong>" . htmlspecialchars($email) . "</strong>) from IP <strong>" . htmlspecialchars($ip) . "</strong>.</p>
<p>If this was not you, your password may be compromised.
Please <a href='https://www.abchem.co.in/forgot-password'>reset your password</a> immediately.</p>
<p>— AB Chem India Security Team</p>";
        if (function_exists('sendProfessionalEmail')) sendProfessionalEmail($email, $subject, $body);
    }

    public static function clear(string $key): void {
        $storage = sys_get_temp_dir() . '/rate_' . md5($key);
        @unlink($storage);
    }
}
