<?php

require_once __DIR__ . '/../private/functions.php';

ini_set('display_errors', 0);
ini_set('log_errors',     1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
set_time_limit(60); // RDKit over large catalogues needs breathing room

// ── Input ─────────────────────────────────────────────────────────────────────
$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$smiles     = trim($input['smiles'] ?? $input['query'] ?? '');
$searchType = strtolower($input['search_type'] ?? 'exact');
$threshold  = max(0.10, min(1.0, (float)($input['threshold'] ?? 0.60)));

if (!in_array($searchType, ['exact', 'substructure', 'similar'])) {
    $searchType = 'exact';
}

if (empty($smiles)) {
    echo json_encode(['success' => false, 'error' => 'No SMILES provided', 'results' => []]);
    exit;
}

$db = Database::getInstance();

// ── Fetch products with SMILES ────────────────────────────────────────────────
$allProducts = $db->fetchAll(
    "SELECT c.id, c.slug,
            c.compound_name AS product_name,
            c.cas_number,
            c.smiles, c.smiles_canonical,
            c.molecular_formula, c.molecular_weight,
            COALESCE(sl.purity, '')           AS purity,
            c.product_type,
            COALESCE(sl.availability, 'On Request') AS availability,
            c.image_url, c.synonyms
     FROM compounds c
     LEFT JOIN supplier_listings sl
         ON sl.id = (SELECT MIN(sl2.id) FROM supplier_listings sl2
                     WHERE sl2.compound_id = c.id AND sl2.status = 'Active')
     WHERE c.status = 'Active'
       AND ((c.smiles IS NOT NULL AND c.smiles != '' AND c.smiles != 'NA')
         OR (c.smiles_canonical IS NOT NULL AND c.smiles_canonical != '' AND c.smiles_canonical != 'NA'))"
);

$smilesStats = $db->fetchOne(
    "SELECT COUNT(*) as total_active,
            SUM(CASE WHEN (smiles IS NOT NULL AND smiles NOT IN ('','NA'))
                          OR (smiles_canonical IS NOT NULL AND smiles_canonical NOT IN ('','NA'))
                     THEN 1 ELSE 0 END) as with_smiles
     FROM compounds WHERE status = 'Active'"
);

// ── No SMILES data at all → keyword fallback ──────────────────────────────────
if (empty($allProducts)) {
    $fallback = keywordFallback($db, $smiles);
    echo json_encode([
        'success'     => true,
        'total'       => count($fallback),
        'search_type' => 'keyword_fallback',
        'query_smiles'=> $smiles,
        'message'     => 'No SMILES data in database. Showing keyword matches instead.',
        'results'     => $fallback,
        'stats'       => $smilesStats,
        'engine'      => 'keyword',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Build product lookup map and SMILES list for Python ───────────────────────
$productMap   = [];   // id → full product row
$pythonInput  = [];   // [{id, smiles}] for rdkit_search.py

foreach ($allProducts as $p) {
    $productMap[$p['id']] = $p;
    // Prefer canonical SMILES; fall back to regular SMILES
    $smilesForSearch = (!empty($p['smiles_canonical']) && $p['smiles_canonical'] !== 'NA')
        ? $p['smiles_canonical']
        : $p['smiles'];
    $pythonInput[] = ['id' => (int)$p['id'], 'smiles' => $smilesForSearch];
}

// ── Try RDKit via Python ──────────────────────────────────────────────────────
$rdkitResult  = runRDKitSearch($smiles, $searchType, $threshold, $pythonInput);
$usedEngine   = 'rdkit';

if ($rdkitResult === null || !empty($rdkitResult['rdkit_missing'])) {
    // RDKit unavailable → fall back to PHP string matching
    $usedEngine   = 'php_fallback';
    $rdkitResult  = phpFallbackSearch($smiles, $searchType, $threshold, $allProducts);
}

// ── Merge RDKit match IDs → full product data ─────────────────────────────────
$results = [];
foreach ($rdkitResult['results'] as $match) {
    $id = (int)$match['id'];
    if (!isset($productMap[$id])) continue;
    $row = $productMap[$id];
    $row['match_score']      = $match['score'] ?? 0;
    $row['canonical_smiles'] = $match['canonical'] ?? '';
    $results[] = $row;
}

// Already sorted by Python; PHP fallback sorts its own results
if ($usedEngine === 'php_fallback') {
    usort($results, fn($a, $b) => ($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0));
}

// ── If no matches, offer keyword fallback ─────────────────────────────────────
if (empty($results)) {
    $fallback = keywordFallback($db, $smiles, 20);
    if (!empty($fallback)) {
        echo json_encode([
            'success'      => true,
            'total'        => count($fallback),
            'search_type'  => 'keyword_fallback',
            'query_smiles' => $smiles,
            'message'      => 'No structure matches found. Showing keyword matches instead.',
            'results'      => $fallback,
            'stats'        => $smilesStats,
            'engine'       => 'keyword',
            'rdkit_errors' => $rdkitResult['errors'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode([
    'success'          => true,
    'total'            => count($results),
    'search_type'      => $searchType,
    'query_smiles'     => $smiles,
    'query_canonical'  => $rdkitResult['query_canonical'] ?? '',
    'results'          => array_slice($results, 0, 100),
    'stats'            => $smilesStats,
    'engine'           => $usedEngine,
    'rdkit_errors'     => $rdkitResult['errors'] ?? [],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ─────────────────────────────────────────────────────────────────────────────
// FUNCTIONS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Call rdkit_search.py via proc_open.
 * Everything is passed through stdin/stdout — no user data ever touches a shell argument.
 * Returns decoded array or null on failure.
 */
function runRDKitSearch(string $smiles, string $searchType, float $threshold, array $products): ?array {
    $pythonBin  = trim(shell_exec('which python3 2>/dev/null') ?: '');
    if (empty($pythonBin)) return null;

    // rdkit_search.py lives in the same directory as this file
    $scriptPath = __DIR__ . '/rdkit_search.py';
    if (!file_exists($scriptPath)) {
        error_log("rdkit_search.py not found at: $scriptPath");
        return null;
    }

    $payload = json_encode([
        'query'       => $smiles,
        'search_type' => $searchType,
        'threshold'   => $threshold,
        'products'    => $products,
    ]);
    if ($payload === false) return null;

    $descriptors = [
        0 => ['pipe', 'r'],   // stdin  → we write the JSON payload
        1 => ['pipe', 'w'],   // stdout ← Python writes results
        2 => ['pipe', 'w'],   // stderr ← Python warnings/errors (logged, not shown)
    ];

    $proc = proc_open(
        escapeshellcmd($pythonBin) . ' ' . escapeshellarg($scriptPath),
        $descriptors,
        $pipes
    );
    if (!is_resource($proc)) return null;

    // Write payload and close stdin so Python knows input is complete
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if (!empty($stderr)) {
        // RDKit prints deprecation warnings to stderr — filter noise, log real errors
        $realErrors = preg_grep('/(?:DEPRECATION|WARNING)/i', explode("\n", $stderr), PREG_GREP_INVERT);
        $realErrors = array_filter($realErrors);
        if (!empty($realErrors)) {
            error_log('rdkit_search.py stderr: ' . implode(' | ', $realErrors));
        }
    }

    if ($exitCode !== 0 || empty($stdout)) {
        error_log("rdkit_search.py exited with code $exitCode");
        return null;
    }

    $result = json_decode($stdout, true);
    return is_array($result) ? $result : null;
}

/**
 * Pure-PHP fallback when RDKit is unavailable.
 * Uses trigram Tanimoto on normalised SMILES — not chemically correct but better than nothing.
 */
function phpFallbackSearch(string $querySmiles, string $searchType, float $threshold, array $products): array {
    $normQuery = strtoupper(preg_replace('/\s+/', '', $querySmiles));
    $results   = [];

    foreach ($products as $p) {
        $smilesRaw = (!empty($p['smiles_canonical']) && $p['smiles_canonical'] !== 'NA')
            ? $p['smiles_canonical'] : $p['smiles'];
        $normProd  = strtoupper(preg_replace('/\s+/', '', $smilesRaw));

        $score   = 0;
        $matched = false;

        switch ($searchType) {
            case 'exact':
                if ($normQuery === $normProd) { $matched = true; $score = 100; }
                break;
            case 'substructure':
                if (strpos($normProd, $normQuery) !== false) { $matched = true; $score = 90; }
                elseif (strpos($normQuery, $normProd) !== false) { $matched = true; $score = 80; }
                break;
            case 'similar':
                $s = trigramTanimoto($normQuery, $normProd);
                if ($s >= $threshold * 100) { $matched = true; $score = $s; }
                break;
        }

        if ($matched) {
            $results[] = ['id' => (int)$p['id'], 'score' => (float)$score, 'canonical' => ''];
        }
    }
    return ['results' => $results, 'errors' => [], 'query_canonical' => ''];
}

function trigramTanimoto(string $a, string $b): float {
    $tg = fn(string $s) => array_unique(array_map(
        fn($i) => substr($s, $i, 3),
        range(0, max(0, strlen($s) - 3))
    ));
    $t1 = $tg($a); $t2 = $tg($b);
    $inter = count(array_intersect($t1, $t2));
    $union = count(array_unique(array_merge($t1, $t2)));
    return $union > 0 ? round($inter / $union * 100, 1) : 0.0;
}

function keywordFallback(object $db, string $term, int $limit = 30): array {
    return $db->fetchAll(
        "SELECT c.id, c.slug,
                c.compound_name AS product_name,
                c.cas_number, c.smiles, c.smiles_canonical,
                c.molecular_formula, c.molecular_weight,
                COALESCE(sl.purity, '')           AS purity,
                c.product_type,
                COALESCE(sl.availability, 'On Request') AS availability,
                c.image_url, c.synonyms
         FROM compounds c
         LEFT JOIN supplier_listings sl
             ON sl.id = (SELECT MIN(sl2.id) FROM supplier_listings sl2
                         WHERE sl2.compound_id = c.id AND sl2.status = 'Active')
         WHERE c.status = 'Active'
           AND (c.compound_name LIKE :t OR c.cas_number LIKE :t OR c.synonyms LIKE :t)
         LIMIT $limit",
        ['t' => "%$term%"]
    );
}
