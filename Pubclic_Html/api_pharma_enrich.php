<?php
/**
 * FEAT-31c: Pharmacopeia monograph enrichment endpoint
 * Admin-only. Fetches PubChem/ChEBI metadata for a pharmacopeia_monographs row
 * and constructs official URL based on the standard.
 *
 * GET /api/pharma-enrich?standard=ep&compound=aspirin
 *
 * Returns JSON: { success, updated, fields_set[], message }
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../private/functions.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth: admin-only ──────────────────────────────────────────────────────────
// Session is already started by functions.php on include. Use the app-wide role
// convention (checkRole → $_SESSION['role'] === 'Admin'); the old 'user_role'
// key was never set, so this endpoint always 403'd.
if (!isset($_SESSION['user_id']) || !checkRole('Admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$db = Database::getInstance();

$standardCode = strtoupper(preg_replace('/[^a-zA-Z]/', '', $_GET['standard'] ?? ''));
$compoundSlug = strtolower(preg_replace('/[^a-z0-9\-]/', '', $_GET['compound'] ?? ''));

if (!$standardCode || !$compoundSlug) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'standard and compound params required']);
    exit;
}

// ── Load standard + monograph ─────────────────────────────────────────────────
$standard = $db->fetchOne(
    "SELECT * FROM pharmacopeia_standards WHERE code = :c",
    ['c' => $standardCode]
);
if (!$standard) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => "Unknown standard: $standardCode"]);
    exit;
}

$monograph = $db->fetchOne(
    "SELECT * FROM pharmacopeia_monographs WHERE standard_id = :sid AND compound_slug = :slug",
    ['sid' => $standard['id'], 'slug' => $compoundSlug]
);
if (!$monograph) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => "Monograph not found for $standardCode/$compoundSlug"]);
    exit;
}

// Already enriched recently?
if (!empty($monograph['pubchem_enriched_at'])) {
    $age = time() - strtotime($monograph['pubchem_enriched_at']);
    if ($age < 86400 * 7 && empty($_GET['force'])) {
        echo json_encode([
            'success'    => true,
            'updated'    => false,
            'message'    => 'Already enriched ' . round($age / 3600) . 'h ago. Pass ?force=1 to re-run.',
            'fields_set' => [],
        ]);
        exit;
    }
}

// ── PubChem lookup ────────────────────────────────────────────────────────────
$update     = [];
$fieldsSet  = [];
$errors     = [];

$casNumber   = $monograph['cas_number'] ?? null;
$compName    = $monograph['compound_name'];

$pubchemData = fetchPubChemByQuery($casNumber ?: $compName);

if ($pubchemData) {
    if (!empty($pubchemData['cid']) && empty($monograph['pubchem_cid'])) {
        $update['pubchem_cid'] = (int)$pubchemData['cid'];
        $fieldsSet[] = 'pubchem_cid';
    }
    if (!empty($pubchemData['inchikey']) && empty($monograph['inchikey'])) {
        $update['inchikey'] = $pubchemData['inchikey'];
        $fieldsSet[] = 'inchikey';
    }
    // Fill CAS from PubChem if we don't have it
    if (!empty($pubchemData['cas']) && empty($monograph['cas_number'])) {
        $update['cas_number'] = $pubchemData['cas'];
        $fieldsSet[] = 'cas_number';
    }
}

// ── ChEBI lookup (if no ChEBI ID yet) ────────────────────────────────────────
if (empty($monograph['chebi_id'])) {
    $chebiId = fetchChebiId($casNumber ?: $compName);
    if ($chebiId) {
        $update['chebi_id'] = $chebiId;
        $fieldsSet[] = 'chebi_id';
    }
}

// ── Construct official URL ────────────────────────────────────────────────────
if (empty($monograph['official_url'])) {
    $officialUrl = buildOfficialUrl($standardCode, $monograph, $update);
    if ($officialUrl) {
        $update['official_url'] = $officialUrl;
        $fieldsSet[] = 'official_url';
    }
}

$update['pubchem_enriched_at'] = date('Y-m-d H:i:s');

// ── Persist ───────────────────────────────────────────────────────────────────
if (!empty($update)) {
    // Database::update(table, data, whereString, whereParams) — the 3rd arg is a
    // SQL string, NOT an array. Passing ['id'=>…] here threw a TypeError.
    $db->update('pharmacopeia_monographs', $update, 'id = :id', ['id' => $monograph['id']]);
}

echo json_encode([
    'success'    => true,
    'updated'    => !empty($fieldsSet),
    'fields_set' => $fieldsSet,
    'message'    => empty($fieldsSet)
        ? 'No new data found to add.'
        : 'Updated: ' . implode(', ', $fieldsSet),
    'errors'     => $errors,
]);
exit;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Query PubChem REST for a CAS number or compound name.
 * Returns assoc array with keys: cid, inchikey, cas.
 */
function fetchPubChemByQuery(string $query): ?array
{
    $encoded = rawurlencode($query);

    // Try CAS / name lookup via PubChem PUG REST
    $url = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/name/{$encoded}/property/InChIKey,IUPACName/JSON";
    $raw = httpGet($url);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    $props = $data['PropertyTable']['Properties'][0] ?? null;
    if (!$props) return null;

    $result = [
        'cid'      => $props['CID'] ?? null,
        'inchikey' => $props['InChIKey'] ?? null,
        'cas'      => null,
    ];

    // Fetch synonyms to extract CAS
    if ($result['cid']) {
        $synUrl = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/{$result['cid']}/synonyms/JSON";
        $synRaw = httpGet($synUrl);
        if ($synRaw) {
            $synData = json_decode($synRaw, true);
            $synonyms = $synData['InformationList']['Information'][0]['Synonym'] ?? [];
            foreach ($synonyms as $syn) {
                if (preg_match('/^\d{2,7}-\d{2}-\d$/', $syn)) {
                    $result['cas'] = $syn;
                    break;
                }
            }
        }
    }

    return $result;
}

/**
 * Query ChEBI for a CAS number or compound name.
 * Returns ChEBI ID string (e.g. "CHEBI:15365") or null.
 */
function fetchChebiId(string $query): ?string
{
    $encoded = rawurlencode($query);
    $url = "https://www.ebi.ac.uk/webservices/chebi/2.0/test/getLiteEntity?search={$encoded}&searchCategory=ALL&maximumResults=1&stars=ALL";
    $raw = httpGet($url);
    if (!$raw) return null;

    // Parse XML response
    if (preg_match('/<chebiId>(CHEBI:\d+)<\/chebiId>/', $raw, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Construct a best-effort official monograph URL based on the standard.
 * Returns null if the standard has no predictable URL pattern.
 */
function buildOfficialUrl(string $stdCode, array $monograph, array $newData): ?string
{
    $cid  = $newData['pubchem_cid'] ?? $monograph['pubchem_cid'] ?? null;
    $cas  = $newData['cas_number']  ?? $monograph['cas_number']  ?? null;
    $name = $monograph['compound_name'];
    $code = $monograph['monograph_code'] ?? null;

    switch ($stdCode) {
        case 'EP':
            // EDQM Knowledge Database — search by CAS or monograph code
            if ($code) {
                return "https://pheur.edqm.eu/view/Chapter/{$code}";
            }
            if ($cas) {
                return "https://pheur.edqm.eu/search?q=" . rawurlencode($cas);
            }
            return "https://pheur.edqm.eu/search?q=" . rawurlencode($name);

        case 'USP':
            // USP catalog search
            if ($code) {
                return "https://www.usp.org/search#q={$code}&t=All";
            }
            return "https://www.usp.org/search#q=" . rawurlencode($name) . "&t=Monographs";

        case 'JP':
            // PMDA Japanese Pharmacopoeia PDF index — no deep link pattern
            return "https://www.pmda.go.jp/english/rs-sb-std/standards-development/jp/0001.html";

        case 'IP':
            return "https://www.ipc.gov.in/";

        case 'WHO':
            if ($cid) {
                return "https://apps.who.int/phint/en/p/docf/";
            }
            return "https://apps.who.int/phint/";

        default:
            return null;
    }
}

/**
 * Simple HTTP GET with 10-second timeout, respects robots via User-Agent.
 */
function httpGet(string $url): ?string
{
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => "User-Agent: ABChemIndia/1.0 (abchem.co.in; pharma enrichment)\r\n",
            'timeout'         => 10,
            'follow_location' => 1,
            'ignore_errors'   => true,
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    return ($raw !== false && strlen($raw) > 0) ? $raw : null;
}
