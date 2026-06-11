<?php
/**
 * PubChem Auto-Fetch Script - DATABASE VERSION
 * Fetches chemical data from PubChem and updates the database directly
 */
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/dedup.php';  // dedup_completeness_score() + dedup_merge() for auto-merge on InChIKey collision

// 🔒 Web-only: Enforce 15-min inactivity timeout + Admin gate
if (php_sapi_name() !== 'cli') {
    enforceSessionTimeout(900);
    if (!isset($_SESSION['role']) || !checkRole('Admin')) {
        header('Location: /signin.php');
        exit;
    }
}

error_reporting(E_ALL);
// Only show errors on CLI — in web mode they corrupt JSON responses
ini_set('display_errors', php_sapi_name() === 'cli' ? '1' : '0');
ini_set('log_errors', '1');

class PubChemFetcher {
    private $imageDir;
    private $db;
    
    public function __construct() {
        $this->imageDir = __DIR__ . '/compound_images/';
        if (!is_dir($this->imageDir)) {
            mkdir($this->imageDir, 0755, true);
        }
        $this->db = Database::getInstance();
    }

    /**
     * Generate slug from name
     */
    private function generateSlug($name, $existingSlugs = []) {
        if (empty($name)) return 'pubchem-' . uniqid();
        
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name)));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 80);
        
        $base = $slug;
        $i = 1;
        while (in_array($slug, $existingSlugs)) {
            $slug = $base . '-' . $i++;
        }
        
        return $slug;
    }

    /**
     * Generate structure image using OPSIN
     */
    private function generateImage($iupacName, $slug) {
        if (empty($iupacName)) return '';
        
        // rawurlencode for path encoding — '+' would be treated as literal in the path
        $opsinUrl = "https://www.ebi.ac.uk/opsin/ws/" . rawurlencode($iupacName) . ".png";
        $localPath = $this->imageDir . $slug . '.png';
        
        if (file_exists($localPath) && filesize($localPath) > 100) {
            return '/compound_images/' . $slug . '.png';
        }
        
        $imageData = $this->fetchURL($opsinUrl, true);
        
        if ($imageData && strlen($imageData) > 500 && strpos($imageData, "\x89PNG") === 0) {
            file_put_contents($localPath, $imageData);
            return '/compound_images/' . $slug . '.png';
        }
        
        return '';
    }

    /**
     * Download PubChem PNG as fallback
     */
    private function downloadPubChemImage($cid, $slug) {
        $url = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/{$cid}/PNG";
        $path = $this->imageDir . $slug . '.png';
        $data = $this->fetchURL($url, true);
        
        if ($data && strlen($data) > 500 && strpos($data, "\x89PNG") === 0) {
            file_put_contents($path, $data);
            return '/compound_images/' . $slug . '.png';
        }
        return '';
    }

    /**
     * Fetch synonyms from PubChem.
     * Note: CAS verification was moved to cas_verify.php so this stays focused
     * on enrichment. Synonyms saved here are the source of truth that the
     * verifier later reads to populate cas_verified + cas_other.
     */
    private function fetchSynonyms(int $cid): array {
        $url = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/{$cid}/synonyms/JSON";
        $resp = $this->fetchURL($url);
        if (!$resp) return [];

        $data = json_decode($resp, true);
        $syns = $data['InformationList']['Information'][0]['Synonym'] ?? [];
        return array_map('trim', $syns);
    }

    /**
     * Search PubChem by priority:
     *   1. InChIKey          — structure fingerprint, single unambiguous CID
     *   2. SMILES fastidentity — exact structure match (stereo-aware, then connectivity)
     *   3. IUPAC name        — systematic name, usually unambiguous
     *   4. CAS number        — LAST: one CAS can map to multiple CIDs (chiral vs racemic)
     *   5. Compound name     — broadest fallback
     */
    private function searchByPriority($inchiKey, $smiles, $iupacName, $cas, $name) {
        // 1. InChIKey — most precise, single-valued by definition
        if (!empty($inchiKey) && $inchiKey !== 'NA') {
            $cid = $this->searchPubChem('inchikey', $inchiKey);
            if ($cid) return $cid;
        }

        // 2. SMILES fastidentity — exact structure search (handles stereo better than CAS)
        if (!empty($smiles) && $smiles !== 'NA') {
            $cid = $this->searchBySmilesFastIdentity($smiles);
            if ($cid) return $cid;
        }

        // 3. IUPAC name — systematic, usually unambiguous for known compounds
        if (!empty($iupacName) && $iupacName !== 'NA') {
            $cid = $this->searchPubChem('name', $iupacName);
            if ($cid) return $cid;
        }

        // 4. CAS number — last resort: one CAS can resolve to multiple CIDs
        //    (e.g. same CAS for racemate and individual enantiomer in PubChem)
        if (!empty($cas) && $cas !== 'NA') {
            $cid = $this->searchPubChem('name', $cas);
            if ($cid) return $cid;
        }

        // 5. Compound name — broadest fallback
        if (!empty($name) && $name !== 'NA') {
            $cid = $this->searchPubChem('name', $name);
            if ($cid) return $cid;
        }

        return null;
    }

    /**
     * SMILES fastidentity search via PubChem REST API.
     * Uses POST to avoid URL-length issues with long SMILES strings.
     *
     * Tries in order:
     *   same_stereo_isotope — exact stereo + isotope match (strictest, finds specific enantiomer)
     *   same_connectivity   — same graph ignoring stereo (finds parent/racemic compound)
     *
     * Returns first CID found, or null if nothing matches.
     */
    private function searchBySmilesFastIdentity($smiles) {
        $baseUrl = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/fastidentity/smiles/cids/JSON';

        foreach (['same_stereo_isotope', 'same_connectivity'] as $identityType) {
            $postData = http_build_query(['smiles' => $smiles, 'identity_type' => $identityType]);
            $response = $this->fetchURL($baseUrl, false, $postData);
            if (!$response) continue;

            $data = json_decode($response, true);
            $cid  = $data['IdentifierList']['CID'][0] ?? null;
            if ($cid) return $cid;
        }

        return null;
    }

    /**
     * Search PubChem by a named type (inchikey, name, cid …)
     */
    public function searchPubChem($type, $value) {
        // rawurlencode for path encoding — urlencode() turns spaces into '+',
        // which PubChem treats as a literal character in a URL path (not as a
        // space), causing names like "15-EPI BIMATOPROST" to 404. See RFC 3986.
        $url = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/{$type}/" . rawurlencode($value) . "/cids/JSON";
        $response = $this->fetchURL($url);
        if (!$response) return null;

        $data = json_decode($response, true);
        return $data['IdentifierList']['CID'][0] ?? null;
    }

/** Fetch compound properties from PubChem (including synonyms) */
    public function fetchProperties($cid) {
        $props = ['MolecularFormula', 'MolecularWeight', 'SMILES', 'IUPACName', 'InChI', 'InChIKey'];
        $url = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/{$cid}/property/" . implode(',', $props) . "/JSON";
        
        $response = $this->fetchURL($url);
        if (!$response) return null;
        
        $data = json_decode($response, true);
        if (!isset($data['PropertyTable']['Properties'][0])) return null;
        
        $p = $data['PropertyTable']['Properties'][0];
        
        $result = [
            'molecular_formula' => $p['MolecularFormula'] ?? '',
            'molecular_weight' => $p['MolecularWeight'] ?? '',
            'smiles' => $p['SMILES'] ?? '',
            'inchi' => $p['InChI'] ?? '',
            'inchi_key' => $p['InChIKey'] ?? '',
            'iupac_name' => $p['IUPACName'] ?? '',
            'pubchem_cid' => (string)$cid,
        ];
        
        // Fetch synonyms and merge with any already stored — BUG-10 fix
        try {
            $pubchemSyns = $this->fetchSynonyms($cid);
            if (!empty($pubchemSyns)) {
                // Clean PubChem synonyms
                $pubchemSyns = array_filter(array_map('trim', $pubchemSyns), function($s) {
                    return !empty($s) && $s !== 'NA' && strlen($s) > 1;
                });
                // Store up to 50 for search recall; display layer slices to 10
                $result['synonyms'] = implode('|', array_slice(array_values($pubchemSyns), 0, 50));
            }
        } catch (Exception $e) {
            error_log("PubChem synonyms fetch failed for CID $cid: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Convert a chemical name (IUPAC or compound name) to structure data via OPSIN.
     *
     * OPSIN parses systematic IUPAC nomenclature into a chemical structure and
     * exposes per-format endpoints. We call three of them to obtain SMILES, InChI,
     * and a standard InChIKey. The InChIKey is the bridge back to PubChem: even
     * if PubChem could not match the original name, it may know the structure.
     *
     * Returns null when OPSIN cannot parse the name (typical for trivial/brand
     * names — OPSIN only understands systematic chemistry nomenclature).
     */
    private function fetchFromOpsin(string $name): ?array {
        $name = trim($name);
        if ($name === '' || strtoupper($name) === 'NA') return null;

        $base = 'https://www.ebi.ac.uk/opsin/ws/' . rawurlencode($name);

        // SMILES first — if OPSIN can't parse the name, this 404s and we bail fast,
        // saving two more round-trips for inchi/inchikey that would also fail.
        $smiles = $this->fetchURL($base . '.smi');
        if (!$smiles) return null;

        $smiles   = trim($smiles);
        $inchi    = trim((string)$this->fetchURL($base . '.inchi'));
        $inchiKey = trim((string)$this->fetchURL($base . '.stdinchikey'));

        if ($smiles === '' && $inchi === '' && $inchiKey === '') return null;

        return [
            'smiles'    => $smiles,
            'inchi'     => $inchi,
            'inchi_key' => $inchiKey,
        ];
    }

    /**
     * Last-resort writer when PubChem cannot identify the compound even after
     * an OPSIN round-trip. Persists OPSIN-derived structure fields + image so
     * the compound still has SMILES/InChI/InChIKey for catalog rendering and
     * future structure-based searches.
     *
     * molecular_formula and molecular_weight remain unset here — they would
     * require an RDKit compute pass on the OPSIN SMILES; a later PubChem fetch
     * (e.g. after the compound becomes known) can backfill them.
     */
    private function saveOpsinOnly(int $productId, array $product, array $opsin, string $opsinName): array {
        $updateData = [];
        foreach (['smiles', 'inchi', 'inchi_key'] as $field) {
            if (!empty($opsin[$field]) && (empty($product[$field]) || $product[$field] === 'NA')) {
                $updateData[$field] = $opsin[$field];
            }
        }

        if (empty($product['image_url']) || $product['image_url'] === 'NA') {
            $img = $this->generateImage($opsinName, $product['slug']);
            if ($img) $updateData['image_url'] = $img;
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('compounds', $updateData, 'id = :id', ['id' => $productId]);
            logAudit('opsin_fallback', "OPSIN-only fetch for compound ID: $productId", '', json_encode(array_keys($updateData)));
        }

        $updated = $this->db->fetchOne("SELECT *, compound_name AS product_name FROM compounds WHERE id = :id", ['id' => $productId]);
        return [
            'status'  => 'opsin_fallback',
            'product' => $updated,
            'note'    => 'PubChem could not identify this compound; saved OPSIN-derived structure (SMILES/InChI/InChIKey + image).',
        ];
    }

    /**
     * HTTP fetch with cURL.
     * @param string      $url      Full URL to fetch
     * @param bool        $binary   Return raw bytes (for image downloads)
     * @param string|null $postData URL-encoded POST body; null = GET request
     */
    private function fetchURL($url, $binary = false, $postData = null) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'ABChem-Fetcher/2.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($postData !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $postData;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close() is a no-op since PHP 8.0 and deprecated in PHP 8.5 — omitted

        if ($httpCode === 200 && $response) {
            return $response;
        }

        error_log("PubChem fetch failed: $url - HTTP $httpCode" . ($postData ? " [POST]" : ""));
        return null;
    }

    /**
     * ✅ MAIN FUNCTION: Fetch missing data for a single product by ID.
     *
     * Thin wrapper around the real work in fetchProductByIdInner(). The wrapper's
     * job is to stamp `last_fetch_attempt` + `last_fetch_status` on the row when
     * we're done, regardless of outcome. fetchAllMissing() uses those columns to
     * skip rows that recently failed and haven't been edited since — see migration
     * 009 and the WHERE clause below in fetchAllMissing.
     */
    public function fetchProductById($productId) {
        $result = $this->fetchProductByIdInner($productId);

        // Stamp the tracker unless the product simply doesn't exist
        $isNotFound = isset($result['error']) && strpos($result['error'], 'not found') !== false;
        if (!$isNotFound) {
            $status = $result['status']
                   ?? (isset($result['error']) ? 'all_failed' : 'unknown');
            $this->stampAttempt((int)$productId, $status);
        }

        return $result;
    }

    /**
     * Record a fetch attempt outcome to compounds.last_fetch_attempt / .last_fetch_status.
     * Soft-fails when migration 009 has not been applied — the fetcher keeps working,
     * but every batch will re-try failures (the pre-tracker behavior).
     */
    private function stampAttempt(int $productId, string $status): void {
        try {
            $this->db->update(
                'compounds',
                [
                    'last_fetch_attempt' => date('Y-m-d H:i:s'),
                    'last_fetch_status'  => substr($status, 0, 32),
                ],
                'id = :pid',
                ['pid' => $productId]
            );
        } catch (Exception $e) {
            error_log('[fetch_tracker] stamp failed (run migration 009?): ' . $e->getMessage());
        }
    }

    /**
     * The actual fetch logic — see fetchProductById() above for the public entry point.
     */
    private function fetchProductByIdInner($productId) {
        // Get compound from database
        $product = $this->db->fetchOne("SELECT *, compound_name AS product_name FROM compounds WHERE id = :id", ['id' => $productId]);
        
        if (!$product) {
            return ['error' => "Product with ID '$productId' not found"];
        }
        
        // Check if we already have complete data
        $requiredFields = ['smiles', 'inchi', 'inchi_key', 'iupac_name', 'molecular_formula', 'molecular_weight'];
        $hasAll = true;
        foreach ($requiredFields as $field) {
            if (empty($product[$field]) || $product[$field] === 'NA') {
                $hasAll = false;
                break;
            }
        }
        
        // Only exit early if synonyms are also populated — BUG-09 fix
        $hasSynonyms = !empty($product['synonyms']) && $product['synonyms'] !== 'NA';
        if ($hasAll && !empty($product['pubchem_cid']) && $hasSynonyms) {
            return ['status' => 'already_complete', 'product' => $product];
        }
        
        // Search PubChem — priority: InChIKey → SMILES → IUPAC → CAS → name
        $cid = $this->searchByPriority(
            $product['inchi_key']    ?? '',
            $product['smiles']       ?? '',
            $product['iupac_name']   ?? '',
            $product['cas_number']   ?? '',
            $product['compound_name'] ?? $product['product_name'] ?? ''
        );

        // ── OPSIN fallback ────────────────────────────────────────────
        // When PubChem can't find the compound by any identifier, parse the
        // IUPAC/compound name via OPSIN → SMILES/InChI/InChIKey, then retry
        // PubChem using the InChIKey (many niche compounds are indexed by
        // structure but not by the supplier's trivial name).
        //
        // ChemSpider is NOT consulted here — it's a separate standalone tool
        // (chemspider_fetch.php) that the admin runs independently to fill
        // remaining gaps after this PubChem+OPSIN pass completes.
        $opsinBridged = false;

        if (!$cid) {
            $opsinName = (!empty($product['iupac_name']) && $product['iupac_name'] !== 'NA')
                ? $product['iupac_name']
                : ($product['compound_name'] ?? $product['product_name'] ?? '');

            $opsinData = $this->fetchFromOpsin($opsinName);

            if ($opsinData && !empty($opsinData['inchi_key'])) {
                $cid = $this->searchPubChem('inchikey', $opsinData['inchi_key']);
                if ($cid) $opsinBridged = true;
            }

            if (!$cid) {
                if ($opsinData) {
                    // Save OPSIN data alone — admin can later run chemspider_fetch.php
                    // to fill formula/MW that OPSIN can't provide.
                    return $this->saveOpsinOnly($productId, $product, $opsinData, $opsinName);
                }
                return ['error' => 'PubChem lookup failed (OPSIN could not parse the name either)', 'product' => $product];
            }
        }
        
        // Fetch properties
        $props = $this->fetchProperties($cid);
        if (!$props) {
            return ['error' => 'Failed to fetch properties', 'product' => $product];
        }
        
        // Prepare update data (only missing fields)
        $updateData = [];
        foreach ($props as $key => $val) {
            if ($key === 'synonyms') continue; // handled separately below
            if (!empty($val) && (empty($product[$key]) || $product[$key] === 'NA')) {
                $updateData[$key] = $val;
            }
        }

        $updateData['pubchem_cid'] = $cid;
        // CAS verification (cas_verified, cas_other) is set by /cas_verify.php
        // after synonyms are populated below. Keeps this file focused on enrichment.

        // BUG-36: Stereo guard — PubChem can return a racemic/flat CID for a chiral CAS number,
        // silently losing stereo information critical for pharmaceutical impurity identity.
        // Rule 1: if we already have a verified stereo SMILES, never let PubChem's flat SMILES overwrite it.
        // Rule 2: if the new SMILES has no stereo annotations, flag the compound for cross-DB stereo check.
        if (!empty($updateData['smiles'])) {
            $pubchemHasStereo = strpos($updateData['smiles'], '@') !== false
                             || strpos($updateData['smiles'], '/') !== false;
            $dbStereoSafe     = !empty($product['smiles_stereo'])
                             && (strpos($product['smiles_stereo'], '@') !== false
                              || strpos($product['smiles_stereo'], '/') !== false);

            if ($dbStereoSafe && !$pubchemHasStereo) {
                unset($updateData['smiles']);
                error_log("PubChem stereo guard: ID $productId has stored stereo SMILES but PubChem returned flat SMILES — SMILES update skipped");
            } elseif (!$pubchemHasStereo && empty($product['stereo_status'])) {
                $updateData['stereo_status'] = 'unverified';
            }
        }

        // Merge existing DB synonyms with PubChem synonyms — BUG-10 fix
        // Union both sets, deduplicate, store up to 50 for maximum search recall
        if (!empty($props['synonyms'])) {
            $existing = !empty($product['synonyms']) && $product['synonyms'] !== 'NA'
                ? explode('|', $product['synonyms'])
                : [];
            $pubchem  = explode('|', $props['synonyms']);
            $merged   = array_values(array_unique(array_filter(
                array_map('trim', array_merge($existing, $pubchem)),
                fn($s) => !empty($s) && $s !== 'NA' && strlen($s) > 1
            )));
            $updateData['synonyms'] = implode('|', array_slice($merged, 0, 50));
        }
        
        // Generate image if IUPAC available and no image
        if (!empty($props['iupac_name']) && (empty($product['image_url']) || $product['image_url'] === 'NA')) {
            $img = $this->generateImage($props['iupac_name'], $product['slug']);
            if ($img) {
                $updateData['image_url'] = $img;
            } else {
                // Try PubChem image as fallback
                $img = $this->downloadPubChemImage($cid, $product['slug']);
                if ($img) $updateData['image_url'] = $img;
            }
        }
        
        // ── Structural conflict detection ──────────────────────────────
        // Before writing, check if the fetched InChIKey / CAS already belongs
        // to a DIFFERENT compound row. InChIKey collisions trigger an
        // auto-merge (same molecule by definition); CAS-only collisions are
        // surfaced to the admin via the dedup banner (CAS is not unique on
        // compounds, and salt/free-base or typo cases need human review).
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

                    // 1. Active beats Inactive: an Inactive row should never become
                    //    the new keeper — it's hidden from the public catalog.
                    // 2. Both same status → higher completeness score wins.
                    // 3. Same score → lower id (older row).
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

                    dedup_merge($this->db, $keeperId, [$loserId], 'pubchem_auto_inchikey');
                    error_log("[pubchem_fetch] AUTO-MERGED loser=#$loserId into keeper=#$keeperId (InChIKey={$inchiConflict['value']}, scores cur=$curScore other=$othScore)");

                    $mergeInfo = [
                        'keeper_id'   => $keeperId,
                        'loser_id'    => $loserId,
                        'inchi_key'   => $inchiConflict['value'],
                        'other_name'  => $inchiConflict['other_name'],
                        'reason'      => 'pubchem_auto_inchikey',
                    ];
                    // Redirect the rest of the fetch flow to the surviving row.
                    $productId = $keeperId;
                }
            } catch (Exception $e) {
                error_log("[pubchem_fetch] AUTO-MERGE FAILED for InChIKey={$inchiConflict['value']}: " . $e->getMessage() . " — falling back to strip-field behavior");
                // Fall back: strip the field so the UPDATE doesn't crash on uq_inchi_key.
                unset($updateData['inchi_key']);
            }
        }

        // Defensive: strip any field that STILL collides with a different row
        // (rare — happens if auto-merge above caught one collision but a third
        // row holds the same InChIKey, or auto-merge silently no-op'd).
        foreach (['inchi_key'] as $col) {
            if (empty($updateData[$col]) || $updateData[$col] === 'NA') continue;
            $stillCollides = $this->db->fetchOne(
                "SELECT id FROM compounds WHERE `$col` = :v AND id != :myId LIMIT 1",
                ['v' => $updateData[$col], 'myId' => $productId]
            );
            if ($stillCollides) {
                error_log("[pubchem_fetch] $col still collides with compound #{$stillCollides['id']} after merge attempt — stripping from UPDATE");
                unset($updateData[$col]);
            }
        }

        // Update database — log fields + rowCount so we can audit whether the
        // UPDATE actually persisted. Helps diagnose the "result page shows data
        // but edit form is blank" symptom.
        $rowsAffected = 0;
        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            $rowsAffected = $this->db->update('compounds', $updateData, 'id = :id', ['id' => $productId]);

            error_log("[pubchem_fetch] id=$productId UPDATE fields=" . implode(',', array_keys($updateData)) . " rows_affected=$rowsAffected");
            logAudit('pubchem_fetch', "Fetched data for compound ID: $productId (rows=$rowsAffected)", '', json_encode(array_keys($updateData)));
        } else {
            error_log("[pubchem_fetch] id=$productId — \$updateData empty, no UPDATE executed (all fields already populated in DB)");
        }

        // Re-read the row from DB so the result page reflects the actual stored state
        $updatedProduct = $this->db->fetchOne("SELECT *, compound_name AS product_name FROM compounds WHERE id = :id", ['id' => $productId]);

        // IndexNow ping — tell search engines to re-crawl immediately now that
        // InChIKey, SMILES, synonyms etc. have been stored (FEAT-32)
        if (function_exists('compoundPublicUrl') && function_exists('indexNowPing')) {
            $url = compoundPublicUrl($updatedProduct ?? []);
            if ($url) indexNowPing($url);
        }

        // Compose status. Priority: auto-merge > CAS conflict banner > normal update.
        if ($mergeInfo) {
            $status = 'auto_merged_into_' . $mergeInfo['keeper_id'];
            $source = $opsinBridged ? 'opsin->pubchem' : 'pubchem';
        } elseif ($casConflict) {
            $status = 'duplicate_of_' . (int)$casConflict['other_id'];
            $source = $opsinBridged ? 'opsin->pubchem' : 'pubchem';
        } else {
            $status = $opsinBridged ? 'updated_via_opsin' : 'updated';
            $source = $opsinBridged ? 'opsin->pubchem' : 'pubchem';
        }

        return [
            'status'         => $status,
            'product'        => $updatedProduct,
            'source'         => $source,
            'rows_affected'  => $rowsAffected,
            'fields_written' => array_keys($updateData ?? []),
            'merge'          => $mergeInfo,    // null OR ['keeper_id','loser_id','inchi_key','other_name','reason']
            'conflict'       => $casConflict,  // null OR ['type','value','other_id','other_name','other_catalog','other_status']
        ];
    }

    /**
     * Detect whether the fetched InChIKey or CAS already belongs to a different
     * compound row.
     *
     * Detection-only — does NOT mutate $updateData. Both Active *and* Inactive
     * compounds are matched: a row that was set Inactive after a previous merge
     * still owns uq_inchi_key, so skipping Inactive rows used to crash the
     * subsequent UPDATE with SQLSTATE[23000] 1062.
     *
     * Returns:
     *   [
     *     'inchi' => null|{type,value,other_id,other_name,other_catalog,other_status},
     *     'cas'   => null|{...same shape...},
     *   ]
     *
     * Caller decides what to do with each (auto-merge for InChI, banner for CAS).
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
            // Prefer Active rows when multiple match (better UI), but include
            // Inactive/Draft so a merged-away duplicate isn't silently skipped.
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

            error_log("[pubchem_fetch] DUPLICATE DETECTED: compound #$productId {$check['type']}={$check['value']} also belongs to compound #{$other['id']} ({$other['compound_name']}, status={$other['status']})");

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
     * Fetch missing data for all products (batch).
     *
     * @param int  $limit     Max compounds per run (0 = no limit).
     * @param int  $startFrom Only consider products with id > this value.
     * @param bool $force     When true, ignore the failed-fetch tracker and retry
     *                        previously-failed compounds. When false (default),
     *                        skip rows where last_fetch_attempt is recent AND
     *                        updated_at hasn't moved since.
     */
    public function fetchAllMissing($limit = 0, $startFrom = 0, bool $force = false) {
        $sql = "SELECT *, compound_name AS product_name FROM compounds WHERE status = 'Active'
                AND (smiles IS NULL OR smiles = '' OR smiles = 'NA'
                OR inchi_key IS NULL OR inchi_key = '' OR inchi_key = 'NA'
                OR pubchem_cid IS NULL OR pubchem_cid = 0)";

        if (!$force) {
            // Tracker skip: avoid re-querying compounds that were attempted recently
            // and haven't been edited since. Auto-retry after 30 days in case the
            // upstream databases have added the compound. Set $force=true to override.
            $sql .= " AND (last_fetch_attempt IS NULL
                           OR updated_at > last_fetch_attempt
                           OR last_fetch_attempt < (NOW() - INTERVAL 30 DAY))";
        }

        if ($startFrom > 0) {
            $sql .= " AND id > " . intval($startFrom);
        }

        // Compounds with a CAS number are more likely to be found on PubChem — process those first
        $sql .= " ORDER BY (cas_number IS NOT NULL AND cas_number NOT IN ('', 'NA')) DESC, id";

        if ($limit > 0) {
            $sql .= " LIMIT " . intval($limit);
        }

        $products  = $this->db->fetchAll($sql);
        $updated   = 0;
        $errors    = 0;
        $conflicts = [];  // CAS-only conflicts surfaced to admin for review
        $merges    = [];  // InChIKey duplicates auto-merged during this batch

        echo "🔄 Starting batch fetch from DATABASE" . ($force ? " (FORCE — tracker bypassed)" : "") . "...\n";
        echo "📊 Products needing data: " . count($products) . "\n\n";

        foreach ($products as $index => $product) {
            echo "[" . ($index + 1) . "] " . ($product['product_name'] ?? 'Unknown') . "... ";

            $result = $this->fetchProductById($product['id']);

            if (isset($result['error'])) {
                $errors++;
                echo "❌ " . $result['error'] . "\n";
            } else {
                $updated++;
                $cid = $result['product']['pubchem_cid'] ?? 'N/A';
                if (!empty($result['merge'])) {
                    $m = $result['merge'];
                    $merges[] = [
                        'this_id'   => $product['id'],
                        'this_name' => $product['product_name'] ?? '',
                        'merge'     => $m,
                    ];
                    $role = (int)$m['keeper_id'] === (int)$product['id'] ? 'kept' : 'merged into #' . $m['keeper_id'];
                    echo "✅ (CID: $cid) 🔀 AUTO-MERGED via InChIKey ($role)\n";
                } elseif (!empty($result['conflict'])) {
                    $c = $result['conflict'];
                    $conflicts[] = [
                        'this_id'   => $product['id'],
                        'this_name' => $product['product_name'] ?? '',
                        'matched'   => $c,
                    ];
                    echo "✅ (CID: $cid) ⚠️ CAS DUPLICATE — also in compound #{$c['other_id']} ({$c['other_name']})\n";
                } else {
                    echo "✅ (CID: $cid)\n";
                }
            }

            // Rate limiting
            usleep(500000); // 0.5 seconds between requests
        }

        $cn = count($conflicts);
        $mn = count($merges);
        $tail = '';
        if ($mn) $tail .= ", 🔀 $mn auto-merged (InChIKey)";
        if ($cn) $tail .= ", ⚠️ $cn CAS duplicate(s) — review in /admin_dedup.php";
        echo "\n🎉 Complete: $updated updated, $errors errors$tail\n";
        return [
            'updated'   => $updated,
            'errors'    => $errors,
            'conflicts' => $conflicts,
            'merges'    => $merges,
        ];
    }

    /**
     * Fetch by slug (for admin panel compatibility)
     */
    public function lazyFetchProduct($slug) {
        $product = $this->db->fetchOne("SELECT *, compound_name AS product_name FROM compounds WHERE slug = :slug", ['slug' => $slug]);
        if (!$product) {
            return ['error' => "Product with slug '$slug' not found"];
        }
        return $this->fetchProductById($product['id']);
    }

    /**
     * Fetch by compound name (partial match)
     */
    public function fetchByCompoundName($name) {
        $product = $this->db->fetchOne(
            "SELECT *, compound_name AS product_name FROM compounds WHERE compound_name = :name LIMIT 1",
            ['name' => trim($name)]
        );
        if (!$product) {
            // Try partial match
            $product = $this->db->fetchOne(
                "SELECT *, compound_name AS product_name FROM compounds WHERE compound_name LIKE :name LIMIT 1",
                ['name' => '%' . trim($name) . '%']
            );
        }
        if (!$product) {
            return ['error' => "No compound found with name containing '$name'"];
        }
        return $this->fetchProductById($product['id']);
    }

    /**
     * Fetch by CAS number
     */
    public function fetchByCasNumber($cas) {
        $cas = trim($cas);
        $product = $this->db->fetchOne(
            "SELECT *, compound_name AS product_name FROM compounds WHERE cas_number = :cas LIMIT 1",
            ['cas' => $cas]
        );
        if (!$product) {
            return ['error' => "No compound found with CAS number '$cas'"];
        }
        return $this->fetchProductById($product['id']);
    }
    

}

// ============= CLI vs WEB EXECUTION =============
// LIBRARY GUARD: Only execute when this file is the direct entry point,
// NOT when require_once'd by admin_products.php or another file.
if (basename(realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {  // library-guard-open
//-- original execution block below --
if (php_sapi_name() === 'cli') {
    // CLI MODE
    $fetcher = new PubChemFetcher();
    
    if (isset($argv[1]) && $argv[1] === '--lazy' && isset($argv[2])) {
        $result = $fetcher->lazyFetchProduct($argv[2]);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        
    } elseif (isset($argv[1]) && $argv[1] === '--batch') {
        $limit = isset($argv[2]) ? (int)$argv[2] : 0;
        $force = in_array('--force', $argv, true);
        $fetcher->fetchAllMissing($limit, 0, $force);

    } elseif (isset($argv[1]) && $argv[1] === '--fetch-id' && isset($argv[2])) {
        $result = $fetcher->fetchProductById(intval($argv[2]));
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

    } else {
        echo "Usage:\n";
        echo "  php pubchem_fetch.php --lazy <slug>              # Fetch single product by slug\n";
        echo "  php pubchem_fetch.php --fetch-id <id>            # Fetch single product by ID\n";
        echo "  php pubchem_fetch.php --batch [limit] [--force]  # Fetch missing products\n";
        echo "                                                   # --force: ignore tracker, retry previous failures\n";
    }
    
} else {
    // ===== WEB MODE - Full Interface =====
    header('Content-Type: text/html; charset=utf-8');
    
    $message = '';
    $error = '';
    $fetchResult = null;

    // Build a success message that reflects which source actually provided the data.
    $buildMessage = function(array $result): string {
        $name   = htmlspecialchars($result['product']['product_name'] ?? '');
        $status = $result['status'] ?? '';

        // Auto-merge happens when the fetched InChIKey matched another row.
        // That row no longer exists — the keeper does. The richer banner
        // (link + tip) is rendered separately below; the message stays plain
        // text because the renderer htmlspecialchars()-escapes it.
        if (!empty($result['merge'])) {
            $m     = $result['merge'];
            $other = $m['other_name'] ?? '';
            return "🔀 Auto-merged on InChIKey: compound #{$m['loser_id']} was the same molecule as '$other' (#{$m['keeper_id']}). FK references re-pointed, archive snapshot saved. Surviving record: #{$m['keeper_id']}.";
        }

        // CAS-only conflict — flagged for admin, not merged
        if (!empty($result['conflict'])) {
            $c = $result['conflict'];
            return "⚠️ Data fetched for '$name', BUT the fetched {$c['type']} also belongs to compound #{$c['other_id']} ({$c['other_name']}, {$c['other_catalog']}). Review in /admin_dedup.php to merge manually.";
        }
        switch ($status) {
            case 'opsin_fallback':
                return "⚠️ PubChem could not identify '$name' — saved OPSIN-derived SMILES/InChI/InChIKey + image. Try running ChemSpider Fetcher for formula/MW.";
            case 'updated_via_opsin':
                return "✅ Data fetched for '$name' via OPSIN → PubChem bridge.";
            default:
                return "✅ Data fetched successfully for: $name";
        }
    };

    // Handle single product fetch
    if (isset($_GET['fetch_id']) && is_numeric($_GET['fetch_id'])) {
        $fetcher = new PubChemFetcher();
        $result = $fetcher->fetchProductById(intval($_GET['fetch_id']));
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $message = $buildMessage($result);
            $fetchResult = $result;
        }
    }
    
    if (isset($_GET['fetch_slug']) && !empty($_GET['fetch_slug'])) {
        $fetcher = new PubChemFetcher();
        $result = $fetcher->lazyFetchProduct($_GET['fetch_slug']);
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $message = $buildMessage($result);
            $fetchResult = $result;
        }
    }

    if (isset($_GET['fetch_name']) && !empty($_GET['fetch_name'])) {
        $fetcher = new PubChemFetcher();
        $result = $fetcher->fetchByCompoundName($_GET['fetch_name']);
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $message = $buildMessage($result);
            $fetchResult = $result;
        }
    }

    if (isset($_GET['fetch_cas']) && !empty($_GET['fetch_cas'])) {
        $fetcher = new PubChemFetcher();
        $result = $fetcher->fetchByCasNumber($_GET['fetch_cas']);
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $message = $buildMessage($result);
            $fetchResult = $result;
        }
    }
    
    if (isset($_POST['batch_fetch']) && is_numeric($_POST['limit'])) {
        $limit     = min(10, intval($_POST['limit']));
        $startFrom = isset($_POST['start_from']) && is_numeric($_POST['start_from']) ? intval($_POST['start_from']) : 0;
        $force     = !empty($_POST['force_refetch']);
        $fetcher   = new PubChemFetcher();

        echo "<div style='background:#f0fdf4; padding:15px; border-radius:8px; margin-bottom:20px;'>";
        echo "<h3>🔄 Batch Fetch Progress" . ($force ? ' <span style="color:#dc2626;">(FORCE)</span>' : '') . "</h3>";
        echo "<pre style='background:#1e293b; color:#e2e8f0; padding:12px; border-radius:6px; overflow-x:auto;'>";

        ob_start();
        $result = $fetcher->fetchAllMissing($limit, $startFrom, $force);
        $output = ob_get_clean();
        echo htmlspecialchars($output);

        echo "</pre>";
        echo "</div>";
        $message = "Batch fetch completed: {$result['updated']} updated, {$result['errors']} errors.";
        $batchConflicts = $result['conflicts'] ?? [];
        $batchMerges    = $result['merges']    ?? [];

        // Auto-merge banner — InChIKey duplicates were merged automatically.
        // No admin action needed; banner is informational + audit trail.
        if (!empty($batchMerges)) {
            echo "<div style='background:#eff6ff; border-left:4px solid #3b82f6; padding:14px 18px; border-radius:0 8px 8px 0; margin-bottom:18px;'>";
            echo "<h3 style='margin:0 0 8px 0; color:#1e3a8a;'>🔀 " . count($batchMerges) . " InChIKey duplicate(s) auto-merged during this batch</h3>";
            echo "<p style='margin:0 0 10px 0; font-size:14px; color:#1e3a8a;'>The fetched InChIKey matched another compound — same molecule by definition, so we merged them automatically (keeper = row with higher completeness score; tie-break = older row). Archive snapshots saved to <code>compound_archive</code>; URL redirects saved to <code>compound_redirects</code>.</p>";
            echo "<table style='width:100%; border-collapse:collapse; font-size:13px; background:white; border-radius:6px; overflow:hidden;'>";
            echo "<thead><tr style='background:#dbeafe;'><th style='padding:8px;text-align:left;'>Fetched compound</th><th style='padding:8px;'>Role</th><th style='padding:8px;text-align:left;'>Surviving keeper</th></tr></thead><tbody>";
            foreach ($batchMerges as $mr) {
                $m       = $mr['merge'];
                $keeper  = (int)$m['keeper_id'];
                $isKeeper = $keeper === (int)$mr['this_id'];
                echo "<tr style='border-bottom:1px solid #dbeafe;'>";
                echo "<td style='padding:8px;'>#" . (int)$mr['this_id'] . " " . htmlspecialchars($mr['this_name']) . "</td>";
                echo "<td style='padding:8px;text-align:center;'><code>" . ($isKeeper ? 'kept' : 'merged → keeper') . "</code></td>";
                echo "<td style='padding:8px;'><a href='/admin_products.php?action=edit&id=$keeper'>#$keeper " . htmlspecialchars($m['other_name'] ?? '') . "</a></td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
            echo "<p style='margin:10px 0 0 0; font-size:12px; color:#1e3a8a;'>💡 <strong>Tip:</strong> To reverse an auto-merge, restore the row snapshot from <code>compound_archive</code> by <code>merged_into_id</code> and the redirect entry from <code>compound_redirects</code>. The loser's id is preserved in <code>compound_archive.original_id</code>.</p>";
            echo "</div>";
        }

        // CAS-only conflict banner — admin must review (CAS alone isn't structurally definitive).
        if (!empty($batchConflicts)) {
            echo "<div style='background:#fef3c7; border-left:4px solid #f59e0b; padding:14px 18px; border-radius:0 8px 8px 0; margin-bottom:18px;'>";
            echo "<h3 style='margin:0 0 8px 0; color:#92400e;'>⚠️ " . count($batchConflicts) . " CAS duplicate(s) flagged for review</h3>";
            echo "<p style='margin:0 0 10px 0; font-size:14px; color:#78350f;'>The fetched CAS for these compounds also belongs to another row, but the InChIKeys differ (or are missing). NOT auto-merged — CAS alone can be ambiguous (salt vs. free base, hydrate vs. anhydrate, data-entry typo). Review manually.</p>";
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
<title>PubChem Fetcher | AB Chem (Database Version)</title>
<style>
* { box-sizing: border-box; }
body { font-family: system-ui, -apple-system, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; background: #f8fafc; }
.card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
h1 { color: #0e7abf; margin: 0 0 8px 0; }
h2 { color: #1e293b; font-size: 1.3rem; margin: 0 0 16px 0; }
.muted { color: #64748b; }
.success { color: #16a34a; font-weight: 500; }
.error { color: #ef4444; font-weight: 500; }
.info-box { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 0 8px 8px 0; margin: 14px 0; font-size: 14px; }
.warning-box { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 0 8px 8px 0; margin: 14px 0; font-size: 14px; }
.btn { display: inline-block; background: #0e7abf; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 14px; font-weight: 500; }
.btn:hover { background: #0a5a8c; }
.btn-outline { background: white; color: #0e7abf; border: 2px solid #0e7abf; }
.btn-outline:hover { background: #f1f5f9; }
.btn-green { background: #16a34a; }
.btn-green:hover { background: #15803d; }
.input-group { margin: 14px 0; }
.input-group label { display: block; font-weight: 600; margin-bottom: 6px; color: #334155; }
.input-group input, .input-group select { padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; width: 100%; max-width: 300px; }
pre { background: #1e293b; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
.form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
.result-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
.result-table th { background: #0e7abf; color: white; padding: 10px; text-align: left; font-weight: 500; }
.result-table td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
.result-table tr:hover td { background: #f8fafc; }
</style>
</head>
<body>

<div class="card">
    <h1>🔬 PubChem Data Fetcher</h1>
    <p class="muted">Fetches SMILES, InChI, InChIKey, MW, formula, and synonyms from PubChem and updates the <strong>DATABASE directly</strong>.</p>
    
    <div class="info-box">
        <strong>✅ Database Version</strong> — This fetcher updates your MySQL database, not CSV files.
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
                💡 <strong>Tip for future reference:</strong> InChIKey duplicates auto-merge because the key identifies one molecule unambiguously. CAS-only duplicates are <em>not</em> auto-merged — they appear in the dedup banner below for manual review (CAS can be wrong, or shared by a salt vs. its free base, hydrate vs. anhydrate). Inactive duplicates are also caught now — previously they were skipped and would crash the next fetch with <code>SQLSTATE 23000</code>.
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
            <form method="get" style="display: flex; gap: 10px;">
                <input type="text" name="fetch_name" placeholder="e.g. Acetylsalicylic acid" style="min-width: 250px;" value="<?= htmlspecialchars($_GET['fetch_name'] ?? '') ?>">
                <button type="submit" class="btn btn-green">🔍 Fetch by Name</button>
            </form>
        </div>

        <div class="input-group">
            <label>By CAS Number</label>
            <form method="get" style="display: flex; gap: 10px;">
                <input type="text" name="fetch_cas" placeholder="e.g. 50-78-2" style="min-width: 180px;" value="<?= htmlspecialchars($_GET['fetch_cas'] ?? '') ?>">
                <button type="submit" class="btn btn-green">🔍 Fetch by CAS</button>
            </form>
        </div>

        <div class="input-group">
            <label>By Product ID</label>
            <form method="get" style="display: flex; gap: 10px;">
                <input type="number" name="fetch_id" placeholder="Enter product ID" value="<?= htmlspecialchars($_GET['fetch_id'] ?? '') ?>">
                <button type="submit" class="btn btn-green">🔍 Fetch by ID</button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <h2>🔄 Batch Fetch Missing Data</h2>
    <p class="muted" style="margin:-8px 0 12px 0; font-size:13px;">
        By default this skips compounds that were attempted in the last 30 days and haven't been edited since.
        Tick <strong>Force refetch</strong> to ignore the tracker and retry every missing-data row.
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
                <tr><td><strong>PubChem CID</strong></td><td><?= htmlspecialchars($fetchResult['product']['pubchem_cid'] ?? '—') ?></td></tr>
                <tr><td><strong>Molecular Formula</strong></td><td><?= htmlspecialchars($fetchResult['product']['molecular_formula'] ?? '—') ?></td></tr>
                <tr><td><strong>Molecular Weight</strong></td><td><?= htmlspecialchars($fetchResult['product']['molecular_weight'] ?? '—') ?></td></tr>
                <tr><td><strong>SMILES</strong></td><td><code style="word-break:break-all;"><?= htmlspecialchars(substr($fetchResult['product']['smiles'] ?? '', 0, 100)) ?></code></td></tr>
                <tr><td><strong>InChIKey</strong></td><td><?= htmlspecialchars($fetchResult['product']['inchi_key'] ?? '—') ?></td></tr>
                <tr><td><strong>Image URL</strong></td><td><?= htmlspecialchars($fetchResult['product']['image_url'] ?? '—') ?></td></tr>
                <tr><td><strong>Last Fetch</strong></td><td><?= htmlspecialchars($fetchResult['product']['last_fetch_attempt'] ?? '—') ?> &nbsp;<span class="muted">(<?= htmlspecialchars($fetchResult['product']['last_fetch_status'] ?? 'never') ?>)</span></td></tr>
                <tr><td><strong>updated_at (DB)</strong></td><td><?= htmlspecialchars($fetchResult['product']['updated_at'] ?? '—') ?></td></tr>
                <tr style="background:#fffbeb;"><td><strong>🔍 Rows Affected</strong></td><td>
                    <?php $ra = $fetchResult['rows_affected'] ?? null; ?>
                    <?php if ($ra === null): ?>
                        <span class="muted">—</span>
                    <?php elseif ($ra === 0): ?>
                        <span class="error"><strong>0</strong> — UPDATE ran but didn't change anything (all values were already what we tried to write)</span>
                    <?php else: ?>
                        <span class="success"><strong><?= (int)$ra ?></strong> row(s) modified in DB ✓</span>
                    <?php endif; ?>
                </td></tr>
                <tr style="background:#fffbeb;"><td><strong>🔍 Fields Written</strong></td><td>
                    <?php $fw = $fetchResult['fields_written'] ?? []; ?>
                    <?php if (empty($fw)): ?>
                        <span class="muted">none — no API data was new (or no API match)</span>
                    <?php else: ?>
                        <code style="font-size:0.85rem;"><?= htmlspecialchars(implode(', ', $fw)) ?></code>
                    <?php endif; ?>
                </td></tr>
            </tbody>
        </table>
    <?php else: ?>
        <p class="muted">No fetch results yet. Use the forms above to fetch data from PubChem.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>📖 CLI Usage (via SSH)</h2>
    <pre>
# Fetch single product by slug
php pubchem_fetch.php --lazy "product-slug"

# Fetch single product by ID
php pubchem_fetch.php --fetch-id 123

# Fetch all missing products (no limit)
php pubchem_fetch.php --batch

# Fetch first 10 missing products
php pubchem_fetch.php --batch 10
    </pre>
</div>

<div class="card">
    <p>
        <a href="/admin?tab=pubchem" class="btn btn-outline">← Back to Admin</a>
        <a href="/chemspider_fetch.php" class="btn btn-outline">🔬 ChemSpider Fetcher</a>
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