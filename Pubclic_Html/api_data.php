<?php
/**
 * API Data Endpoint
 * Supports catalog browsing, search, and dynamic filter options
 *
 * Data source: compounds + supplier_listings (new schema)
 * Aliases used so all existing filter/sort/search column names are unchanged.
 */
require_once __DIR__ . '/../private/functions.php';

ini_set('log_errors', 1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
ini_set('display_errors', 0);
error_reporting(E_ALL);

ob_start();   // catch any stray PHP output before headers are sent

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$action = $_GET['action'] ?? 'catalog';

// ─── FILTER OPTIONS ENDPOINT ──────────────────────────────────────────────────
// Returns dynamic filter data with cascading counts (FEAT-25):
// each dimension is counted after applying all OTHER active filters, so
// selecting "API Impurity" narrows the Parent Drug list to matching drugs only.
if ($action === 'filter_options') {
    try {
        $db = Database::getInstance();
        ob_end_clean();

        // Read currently-active filters from request (passed by JS after any filter change)
        $fTypes   = isset($_GET['type'])        ? array_filter(array_map('strip_tags', (array)$_GET['type']))        : [];
        $fParents = isset($_GET['parent_drug']) ? array_filter(array_map('strip_tags', (array)$_GET['parent_drug'])) : [];
        $fAvail   = isset($_GET['avail'])        ? array_filter(array_map('strip_tags', (array)$_GET['avail']))        : [];
        $fPurMin  = isset($_GET['purity_min'])   ? (float)$_GET['purity_min']   : null;
        $fMwMin   = isset($_GET['mw_min'])       ? (float)$_GET['mw_min']       : null;
        $fMwMax   = isset($_GET['mw_max'])       ? (float)$_GET['mw_max']       : null;

        // Product types — apply all filters EXCEPT type itself
        [$tWhere, $tParams] = cascadeWhere('type', $fTypes, $fParents, $fAvail, $fPurMin, $fMwMin, $fMwMax);
        $types = $db->fetchAll("
            SELECT c.product_type AS label, COUNT(DISTINCT c.id) AS cnt
            FROM compounds c
            LEFT JOIN supplier_listings sl ON sl.compound_id = c.id AND sl.status = 'Active'
            WHERE c.status = 'Active'
              AND c.product_type IS NOT NULL AND c.product_type != '' AND c.product_type != 'NA'
              {$tWhere}
            GROUP BY c.product_type
            ORDER BY cnt DESC, c.product_type ASC
        ", $tParams);

        // Availability — apply all filters EXCEPT avail itself
        [$aWhere, $aParams] = cascadeWhere('avail', $fTypes, $fParents, $fAvail, $fPurMin, $fMwMin, $fMwMax);
        $avail = $db->fetchAll("
            SELECT sl.availability AS label, COUNT(DISTINCT c.id) AS cnt
            FROM supplier_listings sl
            JOIN compounds c ON c.id = sl.compound_id
            WHERE c.status = 'Active' AND sl.status = 'Active'
              AND sl.availability IS NOT NULL AND sl.availability != ''
              {$aWhere}
            GROUP BY sl.availability
            ORDER BY cnt DESC
        ", $aParams);

        // Molecular weight range — always global (range doesn't cascade)
        $mw = $db->fetchOne("
            SELECT FLOOR(MIN(CAST(molecular_weight AS DECIMAL(10,4)))) AS mw_min,
                   CEIL( MAX(CAST(molecular_weight AS DECIMAL(10,4)))) AS mw_max
            FROM compounds
            WHERE status = 'Active'
              AND molecular_weight IS NOT NULL AND molecular_weight != ''
              AND molecular_weight != 'NA'
              AND molecular_weight REGEXP '^[0-9]+\\.?[0-9]*$'
        ");

        // Purity — apply all filters EXCEPT purity itself
        [$pWhere, $pParams] = cascadeWhere('purity', $fTypes, $fParents, $fAvail, $fPurMin, $fMwMin, $fMwMax);
        $purityVals = $db->fetchAll("
            SELECT DISTINCT sl.purity
            FROM supplier_listings sl
            JOIN compounds c ON c.id = sl.compound_id
            WHERE c.status = 'Active' AND sl.status = 'Active'
              AND sl.purity IS NOT NULL AND sl.purity != '' AND sl.purity != 'NA'
              {$pWhere}
            ORDER BY sl.purity ASC
        ", $pParams);

        // Parent drugs — apply all filters EXCEPT parent_drug itself
        [$pdWhere, $pdParams] = cascadeWhere('parent_drug', $fTypes, $fParents, $fAvail, $fPurMin, $fMwMin, $fMwMax);
        $parentDrugs = $db->fetchAll("
            SELECT c.parent_drug AS label, COUNT(DISTINCT c.id) AS cnt
            FROM compounds c
            LEFT JOIN supplier_listings sl ON sl.compound_id = c.id AND sl.status = 'Active'
            WHERE c.status = 'Active'
              AND c.parent_drug IS NOT NULL AND c.parent_drug != '' AND c.parent_drug != 'NA'
              {$pdWhere}
            GROUP BY c.parent_drug
            ORDER BY c.parent_drug ASC
        ", $pdParams);

        echo json_encode([
            'types'        => $types,
            'avail'        => $avail,
            'mw_min'       => (float)($mw['mw_min'] ?? 0),
            'mw_max'       => (float)($mw['mw_max'] ?? 2000),
            'purity_raw'   => array_column($purityVals, 'purity'),
            'parent_drugs' => $parentDrugs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Exception $e) {
        ob_end_clean();
        error_log('filter_options error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not load filter options']);
        exit;
    }
}

// ─── ADVANCED / BATCH SEARCH ENDPOINT ───────────────────────────────────────
// POST /api_data.php?action=advanced_search
// Body (JSON): { terms: ["Aspirin", "73-40-5", "YVPYQ...", ...] }
// Each term is LIKE-searched across: compound_name, iupac_name, synonyms,
// cas_number, inchi_key. Results are grouped by input term.
if ($action === 'advanced_search') {
    try {
        $db = Database::getInstance();
        ob_end_clean();

        $body      = json_decode(file_get_contents('php://input'), true) ?: [];
        $rawTerms  = array_values(
            array_filter(array_map('trim', (array)($body['terms'] ?? [])))
        );
        $matchMode = (($body['match_mode'] ?? 'any') === 'exact') ? 'exact' : 'any';

        if (empty($rawTerms)) {
            echo json_encode(['data' => [], 'total' => 0, 'grouped' => []]);
            exit;
        }

        $flat    = [];
        $grouped = [];

        foreach ($rawTerms as $idx => $term) {

            if ($matchMode === 'exact') {
                // Exact match:
                //   Name fields (compound_name, iupac_name): case-insensitive full-string equality
                //   Synonyms: pipe-separated list — must contain term as a whole entry
                //   CAS / InChIKey: trimmed equality
                $t    = trim($term);
                $rows = $db->fetchAll(
                    "SELECT c.id, c.slug, c.url_slug, c.ab_catalog_number, c.url_token,
                            c.compound_name AS product_name,
                            c.cas_number, c.molecular_formula, c.molecular_weight,
                            c.iupac_name, c.synonyms, c.inchi_key,
                            c.product_type, c.image_url,
                            COALESCE(sl.purity,       '')           AS purity,
                            COALESCE(sl.availability, 'On Request') AS availability,
                            COALESCE(sl.lead_time,    '')           AS lead_time
                     FROM compounds c
                     LEFT JOIN supplier_listings sl
                         ON sl.id = (SELECT MIN(sl2.id) FROM supplier_listings sl2
                                     WHERE sl2.compound_id = c.id AND sl2.status = 'Active')
                     WHERE c.status = 'Active'
                       AND (   LOWER(TRIM(c.compound_name)) = LOWER(:p0)
                            OR LOWER(TRIM(c.iupac_name))    = LOWER(:p1)
                            OR CONCAT('|', LOWER(c.synonyms),  '|') LIKE LOWER(CONCAT('%|', :p2, '|%'))
                            OR LOWER(TRIM(c.cas_number))    = LOWER(:p3)
                            OR LOWER(TRIM(c.inchi_key))     = LOWER(:p4)
                           )
                     ORDER BY c.compound_name ASC
                     LIMIT 50",
                    ['p0' => $t, 'p1' => $t, 'p2' => $t, 'p3' => $t, 'p4' => $t]
                );
            } else {
                // Any match: partial substring search
                $pct  = '%' . $term . '%';
                $rows = $db->fetchAll(
                    "SELECT c.id, c.slug, c.url_slug, c.ab_catalog_number, c.url_token,
                            c.compound_name AS product_name,
                            c.cas_number, c.molecular_formula, c.molecular_weight,
                            c.iupac_name, c.synonyms, c.inchi_key,
                            c.product_type, c.image_url,
                            COALESCE(sl.purity,       '')           AS purity,
                            COALESCE(sl.availability, 'On Request') AS availability,
                            COALESCE(sl.lead_time,    '')           AS lead_time
                     FROM compounds c
                     LEFT JOIN supplier_listings sl
                         ON sl.id = (SELECT MIN(sl2.id) FROM supplier_listings sl2
                                     WHERE sl2.compound_id = c.id AND sl2.status = 'Active')
                     WHERE c.status = 'Active'
                       AND (   c.compound_name LIKE :p0
                            OR c.iupac_name    LIKE :p1
                            OR c.synonyms      LIKE :p2
                            OR c.cas_number    LIKE :p3
                            OR c.inchi_key     LIKE :p4
                           )
                     ORDER BY c.compound_name ASC
                     LIMIT 50",
                    ['p0' => $pct, 'p1' => $pct, 'p2' => $pct, 'p3' => $pct, 'p4' => $pct]
                );
            }

            $grouped[$idx] = ['term' => $term, 'count' => count($rows), 'results' => $rows];
            foreach ($rows as $row) {
                $row['matched_term'] = $term;
                $flat[] = $row;
            }
        }

        echo json_encode([
            'data'    => $flat,
            'total'   => count($flat),
            'grouped' => $grouped,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;

    } catch (Exception $e) {
        if (ob_get_level()) ob_end_clean();
        error_log('advanced_search error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => true, 'message' => $e->getMessage(), 'data' => [], 'total' => 0]);
        exit;
    }
}

// ─── CATALOG / SEARCH ENDPOINT ────────────────────────────────────────────────
// Input parsing
$q           = trim($_GET['q'] ?? '');
$searchType  = strtolower($_GET['search_type'] ?? 'auto');
$matchMode   = (($_GET['match_mode'] ?? 'any') === 'exact') ? 'exact' : 'any';
$types       = isset($_GET['type'])        ? array_map('trim', (array)$_GET['type'])        : [];
$parentDrugs = isset($_GET['parent_drug']) ? array_map('trim', (array)$_GET['parent_drug']) : [];
$avail      = isset($_GET['avail']) ? array_map('trim', (array)$_GET['avail']) : [];
$page       = max(1, (int)($_GET['page'] ?? 1));

// FEAT-29 L4: Accept signed pagination cursor (preferred over raw ?page=N).
// Cursor decode is authoritative when present; the raw ?page=N is a fallback
// for legacy paths (sitemap.xml, manual deep-links) and page-1 entry traffic.
$cursorValid = false;
if (!empty($_GET['cursor'])) {
    $decoded = verifyPageCursor($_GET['cursor']);
    if (is_array($decoded) && isset($decoded['p'])) {
        $page        = max(1, (int)$decoded['p']);
        $cursorValid = true;
    }
}
// Soft honeypot: deep-page request without a cursor AND without a Referer
// header logs to error_log. Does NOT block the response — anti-scraping
// Layers 1–3 (Cloudflare, DB rate-limit, hidden link) handle blocking.
detectDeepPageScraping($page, $cursorValid);
$purityMin  = isset($_GET['purity_min']) ? (float)$_GET['purity_min'] : null;
$mwMin      = isset($_GET['mw_min'])     ? (float)$_GET['mw_min']     : null;
$mwMax      = isset($_GET['mw_max'])     ? (float)$_GET['mw_max']     : null;

// Per-page: default 20, valid values extended to include 20
$perPageRaw = $_GET['per_page'] ?? '20';
$limit      = in_array($perPageRaw, ['10', '12', '20', '50', '100']) ? (int)$perPageRaw : 20;

// ── Sort: support both legacy `sort` param AND new `sort_field` + `sort_dir` ──
// Legacy: sort=name_asc | name_desc | purity_desc | mw_asc | default
// New:    sort_field=product_name|purity|molecular_weight|cas_number  + sort_dir=asc|desc
$sortField = in_array($_GET['sort_field'] ?? '', ['product_name', 'purity', 'molecular_weight', 'cas_number'])
    ? $_GET['sort_field']
    : null;
$sortDir   = ($_GET['sort_dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

// Map legacy sort param → field + dir
$legacySort = $_GET['sort'] ?? 'default';
if (!$sortField) {
    switch ($legacySort) {
        case 'name_asc':    $sortField = 'product_name';     $sortDir = 'ASC';  break;
        case 'name_desc':   $sortField = 'product_name';     $sortDir = 'DESC'; break;
        case 'purity_desc': $sortField = 'purity';           $sortDir = 'DESC'; break;
        case 'mw_asc':      $sortField = 'molecular_weight'; $sortDir = 'ASC';  break;
        case 'cas_asc':     $sortField = 'cas_number';       $sortDir = 'ASC';  break;
        default:            $sortField = 'product_name';     $sortDir = 'ASC';
    }
}

try {
    $db = Database::getInstance();
    ob_end_clean();

    // ── Base derived table ────────────────────────────────────────────────────
    // Wraps compounds + best active listing so all downstream WHERE/ORDER columns
    // (product_name, purity, availability, lead_time, etc.) stay unchanged.
    $baseSql = "
        SELECT
            c.id,
            c.slug,
            c.url_slug,
            c.ab_catalog_number,
            c.url_token,
            c.compound_name          AS product_name,
            c.cas_number,
            c.molecular_formula,
            c.molecular_weight,
            c.iupac_name,
            c.synonyms,
            c.inchi_key,
            c.smiles,
            c.smiles_canonical,
            c.product_type,
            c.parent_drug,
            c.image_url,
            c.pubchem_cid,
            COALESCE(sl.purity,       '')            AS purity,
            COALESCE(sl.availability, 'On Request')  AS availability,
            COALESCE(sl.lead_time,    '')             AS lead_time
        FROM compounds c
        LEFT JOIN supplier_listings sl
            ON sl.id = (
                SELECT MIN(sl2.id)
                FROM supplier_listings sl2
                WHERE sl2.compound_id = c.id
                  AND sl2.status = 'Active'
            )
        WHERE c.status = 'Active'
    ";

    $sql      = "SELECT * FROM ({$baseSql}) AS p WHERE 1=1";
    $countSql = "SELECT COUNT(*) AS total FROM ({$baseSql}) AS p WHERE 1=1";

    $params           = [];
    $whereConditions  = [];

    // ── Search query ──────────────────────────────────────────────────────────
    if (!empty($q)) {
        if ($searchType === 'auto') $searchType = detectSearchType($q);

        if ($matchMode === 'exact') {
            // Exact mode: equality on all identifier fields; pipe-aware synonym
            // check. Alternate CAS lookup uses the indexed compound_cas_aliases
            // junction table — exact equality is an O(log n) B-tree probe.
            $t = trim($q);
            $whereConditions[] =
                "(   LOWER(TRIM(product_name))   = LOWER(:ex0)
                  OR LOWER(TRIM(iupac_name))     = LOWER(:ex1)
                  OR CONCAT('|', LOWER(synonyms), '|') LIKE LOWER(CONCAT('%|', :ex2, '|%'))
                  OR LOWER(TRIM(cas_number))     = LOWER(:ex3)
                  OR EXISTS (SELECT 1 FROM compound_cas_aliases a
                             WHERE a.compound_id = p.id
                               AND LOWER(a.cas_number) = LOWER(:ex3b))
                  OR LOWER(TRIM(inchi_key))      = LOWER(:ex4)
                  OR LOWER(TRIM(ab_catalog_number)) = LOWER(:ex5)
                )";
            $params['ex0']  = $t; $params['ex1'] = $t; $params['ex2'] = $t;
            $params['ex3']  = $t; $params['ex3b'] = $t;
            $params['ex4']  = $t; $params['ex5'] = $t;
        } else {
            // Any / partial match — original type-aware LIKE logic
            switch ($searchType) {
                case 'cas':
                    // Try primary CAS first (LIKE), then alias prefix match on
                    // the indexed junction column. The prefix form `q%` lets
                    // MySQL use idx_cas on compound_cas_aliases.cas_number.
                    $whereConditions[] = "(
                        cas_number LIKE :search_term
                        OR EXISTS (SELECT 1 FROM compound_cas_aliases a
                                   WHERE a.compound_id = p.id
                                     AND (a.cas_number = :search_exact
                                          OR a.cas_number LIKE :search_prefix))
                    )";
                    $params['search_term']   = "%{$q}%";
                    $params['search_exact']  = $q;
                    $params['search_prefix'] = "{$q}%";
                    break;
                case 'inchikey':
                    $cleanKey = strtoupper(str_replace('-', '', $q));
                    $whereConditions[] = "(REPLACE(UPPER(inchi_key), '-', '') LIKE :ik1 OR inchi_key LIKE :ik2)";
                    $params['ik1'] = "%{$cleanKey}%";
                    $params['ik2'] = "%{$q}%";
                    break;
                case 'mol_formula':
                    $whereConditions[] = "REPLACE(molecular_formula, ' ', '') = REPLACE(:mf, ' ', '')";
                    $params['mf'] = $q;
                    break;
                case 'smiles':
                    $whereConditions[] = "(smiles LIKE :sm1 OR smiles_canonical LIKE :sm2)";
                    $params['sm1'] = "%{$q}%";
                    $params['sm2'] = "%{$q}%";
                    break;
                case 'iupac_name':
                    $whereConditions[] = "iupac_name LIKE :iupac";
                    $params['iupac'] = "%{$q}%";
                    break;
                case 'synonym':
                    $whereConditions[] = "synonyms LIKE :syn";
                    $params['syn'] = "%{$q}%";
                    break;
                case 'ab_catalog':
                    $whereConditions[] = "ab_catalog_number LIKE :abc";
                    $params['abc'] = "%{$q}%";
                    break;
                default:
                    $keywords = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
                    $kwConds  = [];
                    foreach ($keywords as $i => $kw) {
                        $k = "kw_{$i}";
                        // Alias lookup via junction table — `LIKE 'kw%'` uses
                        // idx_cas; the EXISTS subquery keeps the outer plan
                        // unaffected (no extra rows joined).
                        $kwConds[] = "(product_name LIKE :{$k}_n
                                       OR cas_number LIKE :{$k}_c
                                       OR EXISTS (SELECT 1 FROM compound_cas_aliases a
                                                  WHERE a.compound_id = p.id
                                                    AND a.cas_number LIKE :{$k}_cop)
                                       OR iupac_name LIKE :{$k}_i
                                       OR synonyms LIKE :{$k}_s
                                       OR molecular_formula LIKE :{$k}_m
                                       OR ab_catalog_number LIKE :{$k}_a)";
                        $lk = "%{$kw}%";
                        $params["{$k}_n"]   = $lk;
                        $params["{$k}_c"]   = $lk;
                        $params["{$k}_cop"] = "{$kw}%";  // prefix → uses idx_cas
                        $params["{$k}_i"]   = $lk;
                        $params["{$k}_s"]   = $lk;
                        $params["{$k}_m"]   = $lk;
                        $params["{$k}_a"]   = $lk;
                    }
                    if ($kwConds) $whereConditions[] = "(" . implode(' AND ', $kwConds) . ")";
            }
        }
    }

    // ── Parent drug filter ────────────────────────────────────────────────────
    if (!empty($parentDrugs)) {
        $pdConds = [];
        foreach (array_filter(array_map('strip_tags', $parentDrugs)) as $i => $pd) {
            $k = "pd_{$i}";
            $pdConds[] = ":{$k}";
            $params[$k] = $pd;
        }
        if ($pdConds) $whereConditions[] = "parent_drug IN (" . implode(', ', $pdConds) . ")";
    }

    // ── Product type filter ───────────────────────────────────────────────────
    if (!empty($types)) {
        $typeConds = [];
        foreach (array_filter(array_map('strip_tags', $types)) as $i => $t) {
            $k = "type_{$i}";
            $typeConds[] = ":{$k}";
            $params[$k]  = $t;
        }
        if ($typeConds) $whereConditions[] = "product_type IN (" . implode(', ', $typeConds) . ")";
    }

    // ── Availability filter ───────────────────────────────────────────────────
    if (!empty($avail)) {
        $availConds = [];
        foreach (array_filter(array_map('strip_tags', $avail)) as $i => $a) {
            $k = "avail_{$i}";
            $availConds[] = ":{$k}";
            $params[$k]   = $a;
        }
        if ($availConds) $whereConditions[] = "availability IN (" . implode(', ', $availConds) . ")";
    }

    // ── Purity threshold filter ───────────────────────────────────────────────
    // purity is stored as text like "≥98%" or "99.5%" — extract numeric part
    if ($purityMin !== null && $purityMin > 0) {
        $whereConditions[] = "CAST(REGEXP_REPLACE(COALESCE(purity,'0'), '[^0-9.]', '') AS DECIMAL(10,2)) >= :pur_min";
        $params['pur_min'] = $purityMin;
    }

    // ── MW range filter ───────────────────────────────────────────────────────
    if ($mwMin !== null && $mwMin > 0) {
        $whereConditions[] = "CAST(COALESCE(molecular_weight,'0') AS DECIMAL(10,4)) >= :mw_min";
        $params['mw_min']  = $mwMin;
    }
    if ($mwMax !== null && $mwMax > 0) {
        $whereConditions[] = "CAST(COALESCE(molecular_weight,'999999') AS DECIMAL(10,4)) <= :mw_max";
        $params['mw_max']  = $mwMax;
    }

    if (!empty($whereConditions)) {
        $andClause = " AND " . implode(' AND ', $whereConditions);
        $sql      .= $andClause;
        $countSql .= $andClause;
    }

    // ── Count total ───────────────────────────────────────────────────────────
    $totalResult = $db->fetchOne($countSql, $params);
    $total       = (int)($totalResult['total'] ?? 0);

    // ── ORDER BY ──────────────────────────────────────────────────────────────
    switch ($sortField) {
        case 'purity':
            // Strip non-numeric chars for numeric sort
            $sql .= " ORDER BY CAST(REGEXP_REPLACE(COALESCE(purity,'0'), '[^0-9.]', '') AS DECIMAL(10,2)) {$sortDir}";
            break;
        case 'molecular_weight':
            $sql .= " ORDER BY CAST(COALESCE(molecular_weight,'0') AS DECIMAL(10,4)) {$sortDir}";
            break;
        case 'cas_number':
            $sql .= " ORDER BY cas_number {$sortDir}";
            break;
        case 'product_name':
        default:
            $sql .= " ORDER BY product_name {$sortDir}";
    }

    // ── Pagination ────────────────────────────────────────────────────────────
    $offset = ($page - 1) * $limit;
    $sql   .= " LIMIT {$limit} OFFSET {$offset}";

    $results = $total > 0 ? $db->fetchAll($sql, $params) : [];

    // Sanitise for JSON
    $clean = array_map(function ($row) {
        return array_map(function ($v) {
            if ($v === null) return '';
            return is_string($v) ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : $v;
        }, $row);
    }, $results);

    echo json_encode([
        'data'        => $clean,
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'pages'       => $total > 0 ? (int)ceil($total / $limit) : 1,
        'search_type' => $searchType,
        'query'       => $q,
        'sort_field'  => $sortField,
        'sort_dir'    => strtolower($sortDir),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

} catch (Exception $e) {
    if (ob_get_level()) ob_end_clean();
    error_log('api_data error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => true, 'message' => 'Search failed. Please try again.',
        'data' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'pages' => 1,
    ]);
}

// ─── Helper Functions ─────────────────────────────────────────────────────────

// FEAT-25: Build a WHERE fragment applying all active filters EXCEPT the excluded dimension.
// Used by filter_options to produce cascading counts: selecting a type narrows parent drugs, etc.
// Returns [where_string, params_array] — where_string starts with " AND " if non-empty.
function cascadeWhere(
    string $exclude,
    array  $types,
    array  $parents,
    array  $avail,
    ?float $purMin,
    ?float $mwMin,
    ?float $mwMax
): array {
    $conds  = [];
    $params = [];

    if ($exclude !== 'type' && !empty($types)) {
        $phs = [];
        foreach ($types as $i => $t) {
            $k = "cfw_t{$i}"; $phs[] = ":{$k}"; $params[$k] = $t;
        }
        $conds[] = 'c.product_type IN (' . implode(',', $phs) . ')';
    }

    if ($exclude !== 'parent_drug' && !empty($parents)) {
        $phs = [];
        foreach ($parents as $i => $d) {
            $k = "cfw_pd{$i}"; $phs[] = ":{$k}"; $params[$k] = $d;
        }
        $conds[] = 'c.parent_drug IN (' . implode(',', $phs) . ')';
    }

    if ($exclude !== 'avail' && !empty($avail)) {
        $phs = [];
        foreach ($avail as $i => $a) {
            $k = "cfw_a{$i}"; $phs[] = ":{$k}"; $params[$k] = $a;
        }
        $conds[] = 'sl.availability IN (' . implode(',', $phs) . ')';
    }

    if ($exclude !== 'purity' && $purMin > 0) {
        $params['cfw_pur'] = $purMin;
        $conds[] = "CAST(REGEXP_REPLACE(COALESCE(sl.purity,'0'), '[^0-9.]', '') AS DECIMAL(10,2)) >= :cfw_pur";
    }

    if ($exclude !== 'mw') {
        if ($mwMin > 0) {
            $params['cfw_mwmin'] = $mwMin;
            $conds[] = "CAST(COALESCE(c.molecular_weight,'0') AS DECIMAL(10,4)) >= :cfw_mwmin";
        }
        if ($mwMax > 0) {
            $params['cfw_mwmax'] = $mwMax;
            $conds[] = "CAST(COALESCE(c.molecular_weight,'999999') AS DECIMAL(10,4)) <= :cfw_mwmax";
        }
    }

    return [!empty($conds) ? ' AND ' . implode(' AND ', $conds) : '', $params];
}

function detectSearchType(string $q): string {
    $q     = trim($q);
    $qUp   = strtoupper($q);
    if (preg_match('/^ABC\d{5}[A-Z]{2}$/i', $q))                                       return 'ab_catalog';
    if (preg_match('/^\d{1,7}-\d{2}-\d$/', $q))                                        return 'cas';
    if (preg_match('/^[A-Z]{14}-[A-Z]{10}-[A-Z]$/', $qUp))                             return 'inchikey';
    if (preg_match('/^[A-Z]{14}$/', $qUp))                                              return 'inchikey';
    if (preg_match('/^([A-Z][a-z]?\d*)+$/', $q) && preg_match('/[A-Z]/', $q) && preg_match('/\d/', $q)) return 'mol_formula';
    if (preg_match('/[\(\)\[\]\@\=\#\+\-\.\%]/', $q) && preg_match('/^[A-Za-z0-9@\[\]\(\)\/\\\=\#\+\-\.\%]+$/', $q)) return 'smiles';
    return 'keyword';
}
