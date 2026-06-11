<?php
/**
 * ChemSpider Fetcher — Standalone Admin Tool
 *
 * Mirror of pubchem_fetch.php for the RSC ChemSpider API. This tool runs
 * INDEPENDENTLY — it is not called from pubchem_fetch.php and does not call
 * into pubchem_fetch.php. The two tools each fill missing fields in the
 * `compounds` table directly, with their own tracker columns:
 *
 *     PubChem    → last_fetch_attempt    / last_fetch_status     (migration 009)
 *     ChemSpider → chemspider_last_attempt / chemspider_last_status (migration 010)
 *
 * SUGGESTED WORKFLOW:
 *   1. Run pubchem_fetch.php (batch) — fills the easy cases
 *   2. Run chemspider_fetch.php (batch) — fills compounds PubChem couldn't find
 *
 * QUOTA:
 *   Free RSC tier ~1000 calls/month, ~4 API calls per compound lookup.
 *   `chemspider_cache` table (migration 008) ensures each distinct compound
 *   costs at most 4 calls regardless of how many times the batch is re-run.
 */

require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/dedup.php';  // dedup_completeness_score() + dedup_merge() for auto-merge on InChIKey collision

// Web-only: enforce 15-min inactivity timeout + Admin gate
if (php_sapi_name() !== 'cli') {
    enforceSessionTimeout(900);
    if (!isset($_SESSION['role']) || !checkRole('Admin')) {
        header('Location: /signin.php');
        exit;
    }
}

error_reporting(E_ALL);
ini_set('display_errors', php_sapi_name() === 'cli' ? '1' : '0');
ini_set('log_errors', '1');

class ChemSpiderFetcher {
    private Database $db;
    private string   $imageDir;

    public function __construct() {
        $this->db       = Database::getInstance();
        $this->imageDir = __DIR__ . '/compound_images/';
        if (!is_dir($this->imageDir)) {
            mkdir($this->imageDir, 0755, true);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Public API — mirrors PubChemFetcher's signatures so admin pages
    // and CLI scripts can swap between them with no other changes.
    // ─────────────────────────────────────────────────────────────────

    /**
     * Thin wrapper around fetchProductByIdInner(). Stamps chemspider_last_attempt
     * / chemspider_last_status on the row when done, regardless of outcome, so
     * fetchAllMissing() can skip recently-attempted compounds.
     */
    public function fetchProductById($productId) {
        $result = $this->fetchProductByIdInner($productId);

        $isNotFound = isset($result['error']) && strpos($result['error'], 'not found') !== false;
        if (!$isNotFound) {
            $status = $result['status']
                   ?? (isset($result['error']) ? 'no_match' : 'unknown');
            $this->stampAttempt((int)$productId, $status);
        }
        return $result;
    }

    /**
     * Batch fill of compounds with missing structural data.
     *
     * Selection criteria: smiles OR inchi_key OR molecular_formula is empty.
     * pubchem_cid is NOT in the criteria — this tool fills structure fields
     * regardless of whether PubChem has a CID (since ChemSpider has different
     * coverage, notably pharma impurities).
     *
     * @param int  $limit     0 = no cap
     * @param int  $startFrom Only compounds with id > this value
     * @param bool $force     Ignore the 30-day tracker and retry everything
     */
    public function fetchAllMissing(int $limit = 0, int $startFrom = 0, bool $force = false) {
        $sql = "SELECT *, compound_name AS product_name FROM compounds WHERE status = 'Active'
                AND (smiles IS NULL OR smiles = '' OR smiles = 'NA'
                OR inchi_key IS NULL OR inchi_key = '' OR inchi_key = 'NA'
                OR molecular_formula IS NULL OR molecular_formula = '' OR molecular_formula = 'NA')";

        if (!$force) {
            // Skip compounds attempted in last 30 days that haven't been edited since.
            // updated_at advancing past chemspider_last_attempt = admin touched it = retry.
            $sql .= " AND (chemspider_last_attempt IS NULL
                           OR updated_at > chemspider_last_attempt
                           OR chemspider_last_attempt < (NOW() - INTERVAL 30 DAY))";
        }

        if ($startFrom > 0) $sql .= " AND id > " . intval($startFrom);

        // Compounds with an InChIKey are looked up faster (single API call path)
        $sql .= " ORDER BY (inchi_key IS NOT NULL AND inchi_key NOT IN ('', 'NA')) DESC, id";

        if ($limit > 0) $sql .= " LIMIT " . intval($limit);

        $products  = $this->db->fetchAll($sql);
        $updated   = 0;
        $errors    = 0;
        $conflicts = [];  // CAS-only conflicts → admin review
        $merges    = [];  // InChIKey duplicates auto-merged this batch

        echo "🔬 ChemSpider batch fetch" . ($force ? " (FORCE — tracker bypassed)" : "") . "\n";
        echo "📊 Compounds with missing data: " . count($products) . "\n\n";

        foreach ($products as $index => $product) {
            echo "[" . ($index + 1) . "] " . ($product['product_name'] ?? 'Unknown') . "... ";
            $result = $this->fetchProductById($product['id']);

            if (isset($result['error'])) {
                $errors++;
                echo "❌ " . $result['error'] . "\n";
            } else {
                $updated++;
                $status = $result['status'] ?? 'updated';
                if (!empty($result['merge'])) {
                    $m = $result['merge'];
                    $merges[] = [
                        'this_id'   => $product['id'],
                        'this_name' => $product['product_name'] ?? '',
                        'merge'     => $m,
                    ];
                    $role = (int)$m['keeper_id'] === (int)$product['id'] ? 'kept' : 'merged into #' . $m['keeper_id'];
                    echo "✅ ({$status}) 🔀 AUTO-MERGED via InChIKey ($role)\n";
                } elseif (!empty($result['conflict'])) {
                    $c = $result['conflict'];
                    $conflicts[] = [
                        'this_id'   => $product['id'],
                        'this_name' => $product['product_name'] ?? '',
                        'matched'   => $c,
                    ];
                    echo "✅ ({$status}) ⚠️ CAS DUPLICATE — also in compound #{$c['other_id']} ({$c['other_name']})\n";
                } else {
                    echo "✅ ({$status})\n";
                }
            }

            // Be polite to RSC — slightly slower than PubChem since each lookup
            // is 4 calls deep
            usleep(700000); // 0.7s
        }

        $cn = count($conflicts);
        $mn = count($merges);
        $tail = '';
        if ($mn) $tail .= ", 🔀 $mn auto-merged (InChIKey)";
        if ($cn) $tail .= ", ⚠️ $cn CAS duplicate(s) — review in /admin_dedup.php";
        echo "\n🎉 Complete: $updated updated, $errors no match$tail\n";
        return [
            'updated'   => $updated,
            'errors'    => $errors,
            'conflicts' => $conflicts,
            'merges'    => $merges,
        ];
    }

    public function lazyFetchProduct(string $slug) {
        $product = $this->db->fetchOne("SELECT id FROM compounds WHERE slug = :slug", ['slug' => $slug]);
        if (!$product) return ['error' => "Product with slug '$slug' not found"];
        return $this->fetchProductById($product['id']);
    }

    public function fetchByCompoundName(string $name) {
        $name = trim($name);
        $product = $this->db->fetchOne(
            "SELECT id FROM compounds WHERE compound_name = :n LIMIT 1",
            ['n' => $name]
        );
        if (!$product) {
            $product = $this->db->fetchOne(
                "SELECT id FROM compounds WHERE compound_name LIKE :n LIMIT 1",
                ['n' => '%' . $name . '%']
            );
        }
        if (!$product) return ['error' => "No compound found with name containing '$name'"];
        return $this->fetchProductById($product['id']);
    }

    public function fetchByCasNumber(string $cas) {
        $cas = trim($cas);
        $product = $this->db->fetchOne(
            "SELECT id FROM compounds WHERE cas_number = :cas LIMIT 1",
            ['cas' => $cas]
        );
        if (!$product) return ['error' => "No compound found with CAS '$cas'"];
        return $this->fetchProductById($product['id']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────

    /**
     * Core single-compound logic. Always called via fetchProductById() so the
     * tracker stamping happens automatically.
     */
    private function fetchProductByIdInner($productId) {
        $product = $this->db->fetchOne(
            "SELECT *, compound_name AS product_name FROM compounds WHERE id = :id",
            ['id' => $productId]
        );
        if (!$product) {
            return ['error' => "Product with ID '$productId' not found"];
        }

        // Already-complete check: structural fields all populated → skip API call
        $hasAll = true;
        foreach (['smiles', 'inchi', 'inchi_key', 'molecular_formula', 'molecular_weight'] as $f) {
            if (empty($product[$f]) || $product[$f] === 'NA') { $hasAll = false; break; }
        }
        if ($hasAll) {
            return ['status' => 'already_complete', 'product' => $product];
        }

        // Lookup keys — prefer IUPAC name (more parseable), fall back to compound_name
        $name = (!empty($product['iupac_name']) && $product['iupac_name'] !== 'NA')
            ? $product['iupac_name']
            : ($product['compound_name'] ?? '');
        $inchiKey = $product['inchi_key'] ?? '';

        $cs = $this->fetch($name, $inchiKey);
        if (!$cs) {
            return ['error' => 'ChemSpider returned no match', 'product' => $product];
        }

        // Fill only missing fields — never overwrite existing data
        $updateData = [];
        $map = [
            'smiles'            => 'smiles',
            'inchi'             => 'inchi',
            'inchi_key'         => 'inchi_key',
            'molecular_formula' => 'molecular_formula',
            'molecular_weight'  => 'molecular_weight',
        ];
        foreach ($map as $csField => $dbField) {
            if (!empty($cs[$csField]) && (empty($product[$dbField]) || $product[$dbField] === 'NA')) {
                $updateData[$dbField] = $cs[$csField];
            }
        }

        // ChemSpider's CommonName goes into synonyms — never overwrites compound_name
        if (!empty($cs['common_name'])) {
            $existing = !empty($product['synonyms']) && $product['synonyms'] !== 'NA'
                ? explode('|', $product['synonyms']) : [];
            if (!in_array($cs['common_name'], $existing, true)) {
                $existing[] = $cs['common_name'];
                $updateData['synonyms'] = implode('|', array_slice($existing, 0, 50));
            }
        }

        // Image via OPSIN PNG (ChemSpider's PNG endpoint requires paid tier)
        if (empty($product['image_url']) || $product['image_url'] === 'NA') {
            $img = $this->fetchOpsinImage($name, $product['slug'] ?? '');
            if ($img) $updateData['image_url'] = $img;
        }

        // Structural conflict check + auto-merge on InChIKey collision.
        // See pubchem_fetch.php for the rationale — same rules apply here.
        $conflicts     = $this->detectStructuralConflict($productId, $updateData);
        $inchiConflict = $conflicts['inchi'] ?? null;
        $casConflict   = $conflicts['cas']   ?? null;
        $mergeInfo     = null;

        if ($inchiConflict) {
            try {
                $current = $this->db->fetchOne(
                    "SELECT c.*,
                            (SELECT COUNT(*) FROM supplier_listings WHERE compound_id = c.id) AS listings_count,
                            (SELECT COUNT(*) FROM order_items       WHERE compound_id = c.id) AS orders_count
                     FROM compounds c WHERE c.id = :id",
                    ['id' => $productId]
                );
                $other = $this->db->fetchOne(
                    "SELECT c.*,
                            (SELECT COUNT(*) FROM supplier_listings WHERE compound_id = c.id) AS listings_count,
                            (SELECT COUNT(*) FROM order_items       WHERE compound_id = c.id) AS orders_count
                     FROM compounds c WHERE c.id = :id",
                    ['id' => $inchiConflict['other_id']]
                );
                if ($current && $other) {
                    $curActive = ($current['status'] ?? '') === 'Active';
                    $othActive = ($other['status']   ?? '') === 'Active';

                    // Active beats Inactive → higher completeness score → older row.
                    if ($curActive !== $othActive) {
                        $keeperId = $curActive ? (int)$current['id'] : (int)$other['id'];
                    } else {
                        $curScore = dedup_completeness_score($current);
                        $othScore = dedup_completeness_score($other);
                        if ($curScore === $othScore) {
                            $keeperId = min((int)$current['id'], (int)$other['id']);
                        } else {
                            $keeperId = $curScore > $othScore ? (int)$current['id'] : (int)$other['id'];
                        }
                    }
                    $loserId = $keeperId === (int)$current['id'] ? (int)$other['id'] : (int)$current['id'];

                    dedup_merge($this->db, $keeperId, [$loserId], 'chemspider_auto_inchikey');
                    error_log("[chemspider_fetch] AUTO-MERGED loser=#$loserId into keeper=#$keeperId (InChIKey={$inchiConflict['value']})");

                    $mergeInfo = [
                        'keeper_id'  => $keeperId,
                        'loser_id'   => $loserId,
                        'inchi_key'  => $inchiConflict['value'],
                        'other_name' => $inchiConflict['other_name'],
                        'reason'     => 'chemspider_auto_inchikey',
                    ];
                    $productId = $keeperId;  // redirect rest of flow to the survivor
                }
            } catch (Exception $e) {
                error_log("[chemspider_fetch] AUTO-MERGE FAILED for InChIKey={$inchiConflict['value']}: " . $e->getMessage() . " — falling back to strip-field behavior");
                unset($updateData['inchi_key']);
            }
        }

        // Defensive: strip any inchi_key that STILL collides with a different row.
        if (!empty($updateData['inchi_key']) && $updateData['inchi_key'] !== 'NA') {
            $stillCollides = $this->db->fetchOne(
                "SELECT id FROM compounds WHERE inchi_key = :v AND id != :myId LIMIT 1",
                ['v' => $updateData['inchi_key'], 'myId' => $productId]
            );
            if ($stillCollides) {
                error_log("[chemspider_fetch] inchi_key still collides with compound #{$stillCollides['id']} after merge attempt — stripping from UPDATE");
                unset($updateData['inchi_key']);
            }
        }

        $status = 'no_new_data';
        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('compounds', $updateData, 'id = :id', ['id' => $productId]);
            logAudit(
                'chemspider_fetch',
                "ChemSpider fetch for compound ID: $productId (rec: " . ($cs['record_id'] ?? '?') . ")",
                '', json_encode(array_keys($updateData))
            );
            $status = 'updated';
        }

        // Status priority: auto-merge > CAS conflict banner > no_new_data/updated
        if ($mergeInfo) {
            $status = 'auto_merged_into_' . $mergeInfo['keeper_id'];
        } elseif ($casConflict) {
            $status = 'duplicate_of_' . (int)$casConflict['other_id'];
        }

        $updated = $this->db->fetchOne(
            "SELECT *, compound_name AS product_name FROM compounds WHERE id = :id",
            ['id' => $productId]
        );
        return [
            'status'   => $status,
            'product'  => $updated,
            'source'   => 'chemspider',
            'merge'    => $mergeInfo,    // null OR ['keeper_id','loser_id','inchi_key','other_name','reason']
            'conflict' => $casConflict,  // null OR ['type','value','other_id','other_name','other_catalog','other_status']
        ];
    }

    /**
     * Detect whether the fetched InChIKey or CAS already belongs to a different
     * compound row. Mirrors PubChemFetcher::detectStructuralConflict:
     *   • Detection-only — does NOT mutate $updateData.
     *   • Matches Active *and* Inactive rows (an Inactive row still owns
     *     uq_inchi_key, so silently skipping it crashes the next UPDATE
     *     with SQLSTATE[23000] 1062).
     *   • Returns ['inchi' => …|null, 'cas' => …|null]; the caller decides
     *     auto-merge (InChI) vs. banner (CAS).
     */
    private function detectStructuralConflict(int $productId, array $updateData): array {
        $out = ['inchi' => null, 'cas' => null];

        $checks = [];
        if (!empty($updateData['inchi_key'])  && $updateData['inchi_key']  !== 'NA') {
            $checks[] = ['key' => 'inchi', 'type' => 'inchi_key', 'column' => 'inchi_key',  'value' => $updateData['inchi_key']];
        }
        if (!empty($updateData['cas_number']) && $updateData['cas_number'] !== 'NA') {
            $checks[] = ['key' => 'cas',   'type' => 'cas',       'column' => 'cas_number', 'value' => $updateData['cas_number']];
        }

        foreach ($checks as $check) {
            // Prefer Active rows for the banner, but include Inactive so a
            // merged-away duplicate isn't silently skipped.
            $other = $this->db->fetchOne(
                "SELECT id, compound_name, ab_catalog_number, status
                 FROM compounds
                 WHERE `{$check['column']}` = :v
                   AND id != :myId
                 ORDER BY (status = 'Active') DESC, id ASC
                 LIMIT 1",
                ['v' => $check['value'], 'myId' => $productId]
            );
            if (!$other) continue;

            error_log("[chemspider_fetch] DUPLICATE DETECTED: compound #$productId {$check['type']}={$check['value']} also belongs to compound #{$other['id']} ({$other['compound_name']}, status={$other['status']})");

            $out[$check['key']] = [
                'type'          => $check['type'],
                'value'         => $check['value'],
                'other_id'      => (int)$other['id'],
                'other_name'    => $other['compound_name'] ?? '',
                'other_catalog' => $other['ab_catalog_number'] ?? '',
                'other_status'  => $other['status'] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Record an attempt outcome to chemspider_last_attempt / chemspider_last_status.
     * Soft-fails if migration 010 has not been applied — fetcher keeps working,
     * but every batch will re-try previous failures (pre-tracker behavior).
     */
    private function stampAttempt(int $productId, string $status): void {
        try {
            $this->db->update(
                'compounds',
                [
                    'chemspider_last_attempt' => date('Y-m-d H:i:s'),
                    'chemspider_last_status'  => substr($status, 0, 32),
                ],
                'id = :pid',
                ['pid' => $productId]
            );
        } catch (Exception $e) {
            error_log('[chemspider_tracker] stamp failed (run migration 010?): ' . $e->getMessage());
        }
    }

    /**
     * ChemSpider API lookup with cache. Tries InChIKey first (structure-based,
     * less ambiguous), then name. Returns null when:
     *   - CHEMSPIDER_API_KEY is not set in .env
     *   - Both lookups miss
     *   - HTTP 429 (quota exhausted)
     */
    private function fetch(string $name = '', string $inchiKey = ''): ?array {
        $apiKey = getenv('CHEMSPIDER_API_KEY');
        if (!$apiKey) {
            error_log('[chemspider] CHEMSPIDER_API_KEY not set in .env — skipping');
            return null;
        }

        $name     = trim($name);
        $inchiKey = trim($inchiKey);

        // 1. InChIKey (preferred)
        if ($inchiKey !== '' && strtoupper($inchiKey) !== 'NA') {
            $cached = $this->cacheGet('inchikey', $inchiKey);
            if ($cached !== false) {
                if (is_array($cached)) return $cached;
            } else {
                $result = $this->query('inchikey', $inchiKey, $apiKey);
                $this->cachePut('inchikey', $inchiKey, $result);
                if ($result) return $result;
            }
        }

        // 2. Name (fallback)
        if ($name !== '' && strtoupper($name) !== 'NA') {
            $nameKey = strtolower($name);
            $cached  = $this->cacheGet('name', $nameKey);
            if ($cached !== false) {
                if (is_array($cached)) return $cached;
            } else {
                $result = $this->query('name', $name, $apiKey);
                $this->cachePut('name', $nameKey, $result);
                if ($result) return $result;
            }
        }

        return null;
    }

    /**
     * 4-step RSC Compounds API flow:
     *   POST  /filter/{type}            → queryId
     *   GET   /filter/{queryId}/status  → poll until Complete (max 3 tries)
     *   GET   /filter/{queryId}/results → recordId list
     *   GET   /records/{recordId}/details → SMILES/InChI/InChIKey/formula/MW/name
     */
    private function query(string $filterType, string $value, string $apiKey): ?array {
        $base = 'https://api.rsc.org/compounds/v1';
        $body = $filterType === 'name'
            ? json_encode(['name' => $value])
            : json_encode(['inchikey' => $value]);

        $resp = $this->http("{$base}/filter/{$filterType}", 'POST', $body, $apiKey);
        if (!$resp) return null;
        $queryId = json_decode($resp, true)['queryId'] ?? null;
        if (!$queryId) return null;

        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) usleep(700000); // 0.7s between polls
            $s = $this->http("{$base}/filter/{$queryId}/status", 'GET', null, $apiKey);
            if (!$s) return null;
            if ((json_decode($s, true)['status'] ?? '') === 'Complete') break;
        }

        $resultsResp = $this->http("{$base}/filter/{$queryId}/results", 'GET', null, $apiKey);
        if (!$resultsResp) return null;
        $recordId = json_decode($resultsResp, true)['results'][0] ?? null;
        if (!$recordId) return null;

        $fields = 'SMILES,Formula,MolecularWeight,CommonName,InChI,InChIKey';
        $detailsResp = $this->http(
            "{$base}/records/{$recordId}/details?fields={$fields}",
            'GET', null, $apiKey
        );
        if (!$detailsResp) return null;
        $d = json_decode($detailsResp, true);
        if (!is_array($d)) return null;

        return [
            'record_id'         => (int)$recordId,
            'smiles'            => $d['smiles']     ?? '',
            'inchi'             => $d['inchi']      ?? '',
            'inchi_key'         => $d['inchiKey']   ?? '',
            'molecular_formula' => $d['formula']    ?? '',
            'molecular_weight'  => isset($d['molecularWeight']) ? (string)$d['molecularWeight'] : '',
            'common_name'       => $d['commonName'] ?? '',
        ];
    }

    /**
     * Single HTTP call with `apikey:` header. Every call is logged to error_log
     * for monthly quota auditing — grep for "[chemspider]" to count usage.
     */
    private function http(string $url, string $method, ?string $body, string $apiKey): ?string {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'ABChem-Fetcher/2.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'apikey: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;

        curl_setopt_array($ch, $opts);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        error_log("[chemspider] {$method} " . (parse_url($url, PHP_URL_PATH) ?: $url) . " — HTTP {$httpCode}");
        if ($httpCode === 429) {
            error_log('[chemspider] *** HTTP 429 — monthly quota likely exhausted ***');
        }

        return ($httpCode === 200 && $resp) ? $resp : null;
    }

    /**
     * Cache read. Returns:
     *   false → no row, must query
     *   null  → cached miss, do NOT re-query
     *   array → cache hit
     */
    private function cacheGet(string $type, string $key): array|null|false {
        try {
            $row = $this->db->fetchOne(
                "SELECT record_id, smiles, inchi, inchi_key, molecular_formula, molecular_weight, common_name
                 FROM chemspider_cache WHERE lookup_type = :t AND lookup_key = :k LIMIT 1",
                ['t' => $type, 'k' => $key]
            );
        } catch (Exception $e) {
            error_log('[chemspider] cache read failed: ' . $e->getMessage());
            return false;
        }
        if (!$row) return false;
        if (empty($row['record_id'])) return null;
        return [
            'record_id'         => (int)$row['record_id'],
            'smiles'            => $row['smiles']            ?? '',
            'inchi'             => $row['inchi']             ?? '',
            'inchi_key'         => $row['inchi_key']         ?? '',
            'molecular_formula' => $row['molecular_formula'] ?? '',
            'molecular_weight'  => $row['molecular_weight']  ?? '',
            'common_name'       => $row['common_name']       ?? '',
        ];
    }

    /**
     * Cache write. Stores both hits AND misses ($result === null is a miss).
     */
    private function cachePut(string $type, string $key, ?array $result): void {
        $row = [
            'lookup_type'       => $type,
            'lookup_key'        => $key,
            'record_id'         => $result['record_id']         ?? null,
            'smiles'            => $result['smiles']            ?? null,
            'inchi'             => $result['inchi']             ?? null,
            'inchi_key'         => $result['inchi_key']         ?? null,
            'molecular_formula' => $result['molecular_formula'] ?? null,
            'molecular_weight'  => $result['molecular_weight']  ?? null,
            'common_name'       => $result['common_name']       ?? null,
        ];
        $fields       = array_keys($row);
        $placeholders = ':' . implode(', :', $fields);
        $setClauses   = array_map(fn($f) => "`$f` = VALUES(`$f`)", $fields);
        $sql = "INSERT INTO chemspider_cache (`" . implode('`, `', $fields) . "`)
                VALUES ($placeholders)
                ON DUPLICATE KEY UPDATE " . implode(', ', $setClauses);
        try {
            $this->db->query($sql, $row);
        } catch (Exception $e) {
            error_log('[chemspider] cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * OPSIN PNG fetch (ChemSpider's PNG endpoint requires the paid tier).
     * Self-contained so chemspider_fetch.php has no dependency on pubchem_fetch.php.
     */
    private function fetchOpsinImage(string $iupacName, string $slug): string {
        $iupacName = trim($iupacName);
        $slug      = trim($slug);
        if ($iupacName === '' || $slug === '') return '';

        $localPath = $this->imageDir . $slug . '.png';
        if (file_exists($localPath) && filesize($localPath) > 100) {
            return '/compound_images/' . $slug . '.png';
        }

        // rawurlencode for path encoding — '+' would be treated as literal in the path
        $url = 'https://www.ebi.ac.uk/opsin/ws/' . rawurlencode($iupacName) . '.png';
        $ch  = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'ABChem-Fetcher/2.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $data = curl_exec($ch);

        if ($data && strlen($data) > 500 && strpos($data, "\x89PNG") === 0) {
            file_put_contents($localPath, $data);
            return '/compound_images/' . $slug . '.png';
        }
        return '';
    }
}

// ============= CLI vs WEB EXECUTION =============
// LIBRARY GUARD: only execute when this file is the direct entry point
if (basename(realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {

if (php_sapi_name() === 'cli') {
    // ── CLI MODE ───────────────────────────────────────────────────
    $fetcher = new ChemSpiderFetcher();

    if (isset($argv[1]) && $argv[1] === '--lazy' && isset($argv[2])) {
        echo json_encode($fetcher->lazyFetchProduct($argv[2]), JSON_PRETTY_PRINT) . "\n";

    } elseif (isset($argv[1]) && $argv[1] === '--fetch-id' && isset($argv[2])) {
        echo json_encode($fetcher->fetchProductById(intval($argv[2])), JSON_PRETTY_PRINT) . "\n";

    } elseif (isset($argv[1]) && $argv[1] === '--batch') {
        $limit = isset($argv[2]) && is_numeric($argv[2]) ? (int)$argv[2] : 0;
        $force = in_array('--force', $argv, true);
        $fetcher->fetchAllMissing($limit, 0, $force);

    } else {
        echo "ChemSpider Fetcher — Usage:\n";
        echo "  php chemspider_fetch.php --lazy <slug>              # Single fetch by slug\n";
        echo "  php chemspider_fetch.php --fetch-id <id>            # Single fetch by ID\n";
        echo "  php chemspider_fetch.php --batch [limit] [--force]  # Batch fill missing data\n";
        echo "                                                      # --force: ignore tracker, retry previous failures\n";
    }

} else {
    // ── WEB MODE ───────────────────────────────────────────────────
    header('Content-Type: text/html; charset=utf-8');

    $message     = '';
    $error       = '';
    $fetchResult = null;

    $buildMessage = function(array $result): string {
        $name   = htmlspecialchars($result['product']['product_name'] ?? '');
        $status = $result['status'] ?? '';

        // Auto-merge — InChIKey collision was resolved automatically. Plain text
        // here; the rich link + tip banner is rendered separately below.
        if (!empty($result['merge'])) {
            $m     = $result['merge'];
            $other = $m['other_name'] ?? '';
            return "🔀 Auto-merged on InChIKey: compound #{$m['loser_id']} was the same molecule as '$other' (#{$m['keeper_id']}). FK references re-pointed, archive snapshot saved. Surviving record: #{$m['keeper_id']}.";
        }
        // CAS-only conflict — flagged for admin, not merged.
        if (!empty($result['conflict'])) {
            $c = $result['conflict'];
            return "⚠️ ChemSpider filled data for '$name', BUT the {$c['type']} also belongs to compound #{$c['other_id']} ({$c['other_name']}, {$c['other_catalog']}). Review in /admin_dedup.php to merge manually.";
        }
        switch ($status) {
            case 'already_complete':
                return "ℹ️ '$name' already has all structural data — no API call made.";
            case 'no_new_data':
                return "ℹ️ ChemSpider had data for '$name', but the row already had every field — nothing to fill.";
            case 'updated':
                return "✅ ChemSpider filled missing data for: $name";
            default:
                return "✅ Result for: $name";
        }
    };

    // ── Form handlers ──
    if (isset($_GET['fetch_id']) && is_numeric($_GET['fetch_id'])) {
        $fetcher = new ChemSpiderFetcher();
        $result  = $fetcher->fetchProductById(intval($_GET['fetch_id']));
        if (isset($result['error'])) $error = $result['error'];
        else { $message = $buildMessage($result); $fetchResult = $result; }
    }

    if (isset($_GET['fetch_slug']) && !empty($_GET['fetch_slug'])) {
        $fetcher = new ChemSpiderFetcher();
        $result  = $fetcher->lazyFetchProduct($_GET['fetch_slug']);
        if (isset($result['error'])) $error = $result['error'];
        else { $message = $buildMessage($result); $fetchResult = $result; }
    }

    if (isset($_GET['fetch_name']) && !empty($_GET['fetch_name'])) {
        $fetcher = new ChemSpiderFetcher();
        $result  = $fetcher->fetchByCompoundName($_GET['fetch_name']);
        if (isset($result['error'])) $error = $result['error'];
        else { $message = $buildMessage($result); $fetchResult = $result; }
    }

    if (isset($_GET['fetch_cas']) && !empty($_GET['fetch_cas'])) {
        $fetcher = new ChemSpiderFetcher();
        $result  = $fetcher->fetchByCasNumber($_GET['fetch_cas']);
        if (isset($result['error'])) $error = $result['error'];
        else { $message = $buildMessage($result); $fetchResult = $result; }
    }

    if (isset($_POST['batch_fetch']) && is_numeric($_POST['limit'])) {
        $limit     = min(10, intval($_POST['limit']));
        $startFrom = isset($_POST['start_from']) && is_numeric($_POST['start_from']) ? intval($_POST['start_from']) : 0;
        $force     = !empty($_POST['force_refetch']);
        $fetcher   = new ChemSpiderFetcher();

        echo "<div style='background:#f0fdf4; padding:15px; border-radius:8px; margin-bottom:20px;'>";
        echo "<h3>🔬 ChemSpider Batch Progress" . ($force ? ' <span style="color:#dc2626;">(FORCE)</span>' : '') . "</h3>";
        echo "<pre style='background:#1e293b; color:#e2e8f0; padding:12px; border-radius:6px; overflow-x:auto;'>";
        ob_start();
        $result = $fetcher->fetchAllMissing($limit, $startFrom, $force);
        $output = ob_get_clean();
        echo htmlspecialchars($output);
        echo "</pre></div>";
        $message = "Batch fetch completed: {$result['updated']} updated, {$result['errors']} no match.";
        $batchConflicts = $result['conflicts'] ?? [];
        $batchMerges    = $result['merges']    ?? [];

        // Auto-merge banner — InChIKey duplicates were merged automatically.
        if (!empty($batchMerges)) {
            echo "<div style='background:#eff6ff; border-left:4px solid #3b82f6; padding:14px 18px; border-radius:0 8px 8px 0; margin-bottom:18px;'>";
            echo "<h3 style='margin:0 0 8px 0; color:#1e3a8a;'>🔀 " . count($batchMerges) . " InChIKey duplicate(s) auto-merged during this batch</h3>";
            echo "<p style='margin:0 0 10px 0; font-size:14px; color:#1e3a8a;'>The InChIKey ChemSpider returned matched another compound — same molecule by definition, so we merged them automatically (keeper = Active &gt; higher completeness score &gt; older row). Snapshots in <code>compound_archive</code>; URL redirects in <code>compound_redirects</code>.</p>";
            echo "<table style='width:100%; border-collapse:collapse; font-size:13px; background:white; border-radius:6px; overflow:hidden;'>";
            echo "<thead><tr style='background:#dbeafe;'><th style='padding:8px;text-align:left;'>Fetched compound</th><th style='padding:8px;'>Role</th><th style='padding:8px;text-align:left;'>Surviving keeper</th></tr></thead><tbody>";
            foreach ($batchMerges as $mr) {
                $m        = $mr['merge'];
                $keeper   = (int)$m['keeper_id'];
                $isKeeper = $keeper === (int)$mr['this_id'];
                echo "<tr style='border-bottom:1px solid #dbeafe;'>";
                echo "<td style='padding:8px;'>#" . (int)$mr['this_id'] . " " . htmlspecialchars($mr['this_name']) . "</td>";
                echo "<td style='padding:8px;text-align:center;'><code>" . ($isKeeper ? 'kept' : 'merged → keeper') . "</code></td>";
                echo "<td style='padding:8px;'><a href='/admin_products.php?action=edit&id=$keeper'>#$keeper " . htmlspecialchars($m['other_name'] ?? '') . "</a></td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
            echo "<p style='margin:10px 0 0 0; font-size:12px; color:#1e3a8a;'>💡 <strong>Tip:</strong> To reverse, restore from <code>compound_archive</code> by <code>merged_into_id</code> (loser's id preserved in <code>original_id</code>).</p>";
            echo "</div>";
        }

        // CAS-only conflict banner — admin must review.
        if (!empty($batchConflicts)) {
            echo "<div style='background:#fef3c7; border-left:4px solid #f59e0b; padding:14px 18px; border-radius:0 8px 8px 0; margin-bottom:18px;'>";
            echo "<h3 style='margin:0 0 8px 0; color:#92400e;'>⚠️ " . count($batchConflicts) . " CAS duplicate(s) flagged for review</h3>";
            echo "<p style='margin:0 0 10px 0; font-size:14px; color:#78350f;'>The CAS ChemSpider returned also belongs to another row, but the InChIKeys differ (or are missing). NOT auto-merged — CAS alone can be ambiguous (salt vs. free base, hydrate vs. anhydrate, data-entry typo). Review manually.</p>";
            echo "<table style='width:100%; border-collapse:collapse; font-size:13px; background:white; border-radius:6px; overflow:hidden;'>";
            echo "<thead><tr style='background:#fde68a;'><th style='padding:8px;text-align:left;'>This compound</th><th style='padding:8px;'>Matched on</th><th style='padding:8px;text-align:left;'>Existing duplicate</th></tr></thead><tbody>";
            foreach ($batchConflicts as $cf) {
                $m = $cf['matched'];
                echo "<tr style='border-bottom:1px solid #fde68a;'>";
                echo "<td style='padding:8px;'><a href='/admin_products.php?action=edit&id=" . (int)$cf['this_id'] . "'>#" . (int)$cf['this_id'] . " " . htmlspecialchars($cf['this_name']) . "</a></td>";
                echo "<td style='padding:8px;text-align:center;'><code>" . htmlspecialchars($m['type']) . "</code></td>";
                echo "<td style='padding:8px;'><a href='/admin_products.php?action=edit&id=" . (int)$m['other_id'] . "'>#" . (int)$m['other_id'] . " " . htmlspecialchars($m['other_name']) . "</a> <code style='color:#78350f;'>" . htmlspecialchars($m['other_catalog']) . "</code></td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
            echo "<p style='margin:10px 0 0 0;'><a href='/admin_dedup.php?criteria%5B%5D=inchi_key&criteria%5B%5D=cas' style='background:#92400e; color:white; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:13px; font-weight:500;'>🧬 Open Dedup Tool (InChIKey + CAS scan)</a></p>";
            echo "</div>";
        }
    }

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ChemSpider Fetcher | AB Chem</title>
<style>
* { box-sizing: border-box; }
body { font-family: system-ui, -apple-system, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; background: #f8fafc; }
.card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
h1 { color: #be123c; margin: 0 0 8px 0; }
h2 { color: #1e293b; font-size: 1.3rem; margin: 0 0 16px 0; }
.muted { color: #64748b; }
.info-box { background: #fff1f2; border-left: 4px solid #f43f5e; padding: 12px 16px; border-radius: 0 8px 8px 0; margin: 14px 0; font-size: 14px; }
.warning-box { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 0 8px 8px 0; margin: 14px 0; font-size: 14px; }
.btn { display: inline-block; background: #be123c; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 14px; font-weight: 500; }
.btn:hover { background: #9f1239; }
.btn-outline { background: white; color: #be123c; border: 2px solid #be123c; }
.btn-outline:hover { background: #fff1f2; }
.btn-green { background: #16a34a; }
.btn-green:hover { background: #15803d; }
.input-group { margin: 14px 0; }
.input-group label { display: block; font-weight: 600; margin-bottom: 6px; color: #334155; }
.input-group input { padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; width: 100%; max-width: 300px; }
pre { background: #1e293b; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
.form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
.result-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
.result-table th { background: #be123c; color: white; padding: 10px; text-align: left; font-weight: 500; }
.result-table td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
.result-table tr:hover td { background: #fff1f2; }
</style>
</head>
<body>

<div class="card">
    <h1>🔬 ChemSpider Data Fetcher</h1>
    <p class="muted">
        Fills missing SMILES / InChI / InChIKey / molecular formula / molecular weight directly from the RSC ChemSpider API.
        Use this <em>after</em> running PubChem Fetcher, for compounds PubChem couldn't identify.
    </p>

    <div class="info-box">
        <strong>Independent tool</strong> — separate from <code>pubchem_fetch.php</code>. Each tool has its own
        tracker so re-running one doesn't affect the other.
    </div>

    <div class="warning-box">
        <strong>Quota:</strong> free RSC tier allows ~1000 API calls/month, ~4 calls per compound lookup.
        Cached responses cost nothing on re-runs. Watch usage: <code>grep '[chemspider]' /home/.../error_log | wc -l</code>
    </div>

    <?php if ($message): ?>
        <div style="background:#dcfce7; color:#166534; padding:12px; border-radius:8px; margin-bottom:16px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($fetchResult['merge'])):
        $m = $fetchResult['merge'];
        $keeper = (int)$m['keeper_id'];
        $loser  = (int)$m['loser_id']; ?>
        <div style="background:#eff6ff; border-left:4px solid #3b82f6; padding:14px 18px; border-radius:0 8px 8px 0; margin-bottom:16px;">
            <h3 style="margin:0 0 6px 0; color:#1e3a8a;">🔀 Auto-merged on InChIKey</h3>
            <p style="margin:0 0 8px 0; color:#1e3a8a; font-size:14px;">
                Compound <strong>#<?= $loser ?></strong> was structurally identical to <strong>#<?= $keeper ?> <?= htmlspecialchars($m['other_name'] ?? '') ?></strong> (same InChIKey: <code><?= htmlspecialchars($m['inchi_key'] ?? '') ?></code>).
                FK references re-pointed, archive snapshot &amp; redirect entry saved.
            </p>
            <p style="margin:6px 0;"><a href="/admin_products.php?action=edit&amp;id=<?= $keeper ?>" style="background:#1e40af; color:white; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:13px; font-weight:500;">Open surviving record #<?= $keeper ?></a></p>
            <p style="margin:10px 0 0 0; font-size:12px; color:#1e3a8a; background:white; padding:8px 10px; border-radius:6px;">
                💡 <strong>Tip:</strong> InChIKey duplicates auto-merge because the key identifies one molecule unambiguously. CAS-only duplicates show in the amber banner for manual review (CAS can be wrong, or shared by a salt vs. its free base). Inactive duplicates are caught too — previously they crashed the next fetch with <code>SQLSTATE 23000</code>.
            </p>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:16px;">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>📦 Fetch Single Product</h2>
    <div class="form-row">
        <div class="input-group">
            <label>By Compound Name</label>
            <form method="get" style="display:flex; gap:10px;">
                <input type="text" name="fetch_name" placeholder="e.g. Acetylsalicylic acid" style="min-width:250px;" value="<?= htmlspecialchars($_GET['fetch_name'] ?? '') ?>">
                <button type="submit" class="btn btn-green">🔍 Fetch by Name</button>
            </form>
        </div>
        <div class="input-group">
            <label>By CAS Number</label>
            <form method="get" style="display:flex; gap:10px;">
                <input type="text" name="fetch_cas" placeholder="e.g. 50-78-2" style="min-width:180px;" value="<?= htmlspecialchars($_GET['fetch_cas'] ?? '') ?>">
                <button type="submit" class="btn btn-green">🔍 Fetch by CAS</button>
            </form>
        </div>
        <div class="input-group">
            <label>By Product ID</label>
            <form method="get" style="display:flex; gap:10px;">
                <input type="number" name="fetch_id" placeholder="Enter product ID" value="<?= htmlspecialchars($_GET['fetch_id'] ?? '') ?>">
                <button type="submit" class="btn btn-green">🔍 Fetch by ID</button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <h2>🔄 Batch Fetch Missing Data</h2>
    <p class="muted" style="margin:-8px 0 12px 0; font-size:13px;">
        Skips compounds attempted in the last 30 days that haven't been edited since.
        Tick <strong>Force refetch</strong> to ignore the tracker.
    </p>
    <form method="post">
        <div class="form-row">
            <div class="input-group">
                <label>Number of products (max 10)</label>
                <input type="number" name="limit" value="10" min="1" max="10">
            </div>
            <div class="input-group">
                <label>Start from Product ID (optional)</label>
                <input type="number" name="start_from" value="" placeholder="Leave empty to start from beginning">
            </div>
            <div class="input-group" style="align-self:center;">
                <label style="display:flex; align-items:center; gap:8px; font-weight:500;">
                    <input type="checkbox" name="force_refetch" value="1" style="width:auto;">
                    Force refetch (ignore tracker)
                </label>
            </div>
            <div class="input-group">
                <button type="submit" name="batch_fetch" value="1" class="btn">🔄 Start Batch Fetch</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <h2>📋 Recent Fetch Result</h2>
    <?php if ($fetchResult && isset($fetchResult['product'])): ?>
        <table class="result-table">
            <thead><tr><th>Field</th><th>Value</th></tr></thead>
            <tbody>
                <tr><td><strong>ID</strong></td><td><?= htmlspecialchars($fetchResult['product']['id'] ?? '') ?></td></tr>
                <tr><td><strong>Product Name</strong></td><td><?= htmlspecialchars($fetchResult['product']['product_name'] ?? '') ?></td></tr>
                <tr><td><strong>CAS Number</strong></td><td><?= htmlspecialchars($fetchResult['product']['cas_number'] ?? '—') ?></td></tr>
                <tr><td><strong>Molecular Formula</strong></td><td><?= htmlspecialchars($fetchResult['product']['molecular_formula'] ?? '—') ?></td></tr>
                <tr><td><strong>Molecular Weight</strong></td><td><?= htmlspecialchars($fetchResult['product']['molecular_weight'] ?? '—') ?></td></tr>
                <tr><td><strong>SMILES</strong></td><td><code style="word-break:break-all;"><?= htmlspecialchars(substr($fetchResult['product']['smiles'] ?? '', 0, 100)) ?></code></td></tr>
                <tr><td><strong>InChIKey</strong></td><td><?= htmlspecialchars($fetchResult['product']['inchi_key'] ?? '—') ?></td></tr>
                <tr><td><strong>Image URL</strong></td><td><?= htmlspecialchars($fetchResult['product']['image_url'] ?? '—') ?></td></tr>
                <tr><td><strong>Last ChemSpider Fetch</strong></td><td><?= htmlspecialchars($fetchResult['product']['chemspider_last_attempt'] ?? '—') ?> &nbsp;<span class="muted">(<?= htmlspecialchars($fetchResult['product']['chemspider_last_status'] ?? 'never') ?>)</span></td></tr>
            </tbody>
        </table>
    <?php else: ?>
        <p class="muted">No fetch results yet. Use the forms above to fetch data from ChemSpider.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>📖 CLI Usage (via SSH)</h2>
    <pre>
# Fetch single product by slug
php chemspider_fetch.php --lazy "product-slug"

# Fetch single product by ID
php chemspider_fetch.php --fetch-id 123

# Batch fill missing data (default: 10 compounds, respect tracker)
php chemspider_fetch.php --batch 10

# Force batch — retry previous failures
php chemspider_fetch.php --batch 10 --force
    </pre>
</div>

<div class="card">
    <p>
        <a href="/admin" class="btn btn-outline">← Back to Admin</a>
        <a href="/pubchem_fetch.php" class="btn btn-outline">🧪 PubChem Fetcher</a>
        <a href="/admin_dedup.php" class="btn btn-outline">🧬 Deduplicate Compounds</a>
        <a href="/admin_products.php" class="btn btn-outline">📦 Manage Products</a>
        <a href="/catalog" class="btn btn-outline">🔍 View Catalog</a>
    </p>
</div>

</body>
</html>
<?php
}
} // end library-guard
?>
