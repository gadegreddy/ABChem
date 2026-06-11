<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
error_reporting(E_ALL);

require_once __DIR__ . '/../private/functions.php';

// Library guard: prevents pubchem_fetch.php from rendering its own HTML page
define('PUBCHEM_LIBRARY_MODE', true);
require_once 'pubchem_fetch.php';

// Auth & Session
enforceSessionTimeout(900);
if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    header('Location: /signin');
    exit;
}

// Buffer all output — lets AJAX handlers call ob_end_clean() to strip any stray
// PHP notices/warnings before emitting JSON, so the response is never corrupted.
ob_start();

$db          = Database::getInstance();
$message     = '';
$error       = '';
$pubchemDiff       = null;
$compoundConflicts = [];

$action = $_GET['action'] ?? 'list';
$search = $_GET['search'] ?? '';

// Restore flash data across Post-Redirect-Get
if (!empty($_SESSION['pubchem_diff']))        { $pubchemDiff       = $_SESSION['pubchem_diff'];        unset($_SESSION['pubchem_diff']); }
if (!empty($_SESSION['flash_message']))       { $message           = $_SESSION['flash_message'];       unset($_SESSION['flash_message']); }
if (!empty($_SESSION['flash_error']))         { $error             = $_SESSION['flash_error'];         unset($_SESSION['flash_error']); }
if (!empty($_SESSION['compound_conflicts']))  { $compoundConflicts = $_SESSION['compound_conflicts'];  unset($_SESSION['compound_conflicts']); }

// =============================================
// SCHEMA — read columns dynamically from compounds
// =============================================
$columnsInfo    = $db->fetchAll("SHOW COLUMNS FROM compounds");
$allColumns     = [];
$columnDefaults = [];
foreach ($columnsInfo as $col) {
    $field        = $col['Field'];
    $allColumns[] = $field;
    if (in_array($field, ['id', 'created_at', 'updated_at', 'created_by'])) continue;
    $default = $col['Default'];
    if ($default === 'current_timestamp()' || str_starts_with((string)$default, 'current_timestamp()')) {
        $default = '';
    }
    $columnDefaults[$field] = $default;
}

// Supplier-specific fields shown on the form (from supplier_listings)
$listingColumns = ['purity', 'purity_by_method', 'availability', 'stock_status',
                   'min_order_qty', 'unit', 'lead_time', 'lot_number',
                   'manufacture_date', 'expiry_date', 'catalog_number', 'supplier_id'];
// Add listing columns so the form can render them
foreach ($listingColumns as $lc) {
    if (!in_array($lc, $allColumns)) $allColumns[] = $lc;
}

$textareaFields = ['smiles', 'inchi', 'iupac_name', 'synonyms', 'meta_description', 'keywords', 'smiles_canonical', 'smiles_stereo'];
$skipFields     = array_merge(
    ['id', 'slug', 'catalog_number', 'custom_data', 'created_at', 'updated_at', 'created_by',
     'stereo_status', 'stereo_source', 'smiles_stereo'],  // stereo handled in dedicated section
    $listingColumns  // listing fields are handled in the dedicated Supplier Listings section
);
$chemicalFields = ['smiles', 'inchi', 'inchi_key', 'iupac_name', 'molecular_formula',
                   'molecular_weight', 'pubchem_cid', 'smiles_canonical', 'synonyms', 'image_url'];

// =============================================
// HELPER FUNCTIONS  (defined before POST handlers)
// =============================================

/**
 * Generate canonical SMILES using RDKit
 */
function generateCanonicalSmiles(string $smiles): ?string {
    $script = __DIR__ . '/generate_canonical_smiles.py';

    if (!file_exists($script)) {
        error_log("RDKit script not found: $script");
        return null;
    }

    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        "python3 " . escapeshellarg($script) . " --smiles " . escapeshellarg($smiles),
        $descriptorspec,
        $pipes
    );

    if (!is_resource($process)) return null;

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $data = json_decode($output, true);
    return $data['canonical'] ?? null;
}

function autoFetchPubChemData(string $productName, string $iupacName, string $inchiKey, string $casNumber, string $cid): ?array {
    $fetcher  = new PubChemFetcher();
    $foundCid = null;

    if (!empty($iupacName) && $iupacName !== 'NA')   $foundCid = $fetcher->searchPubChem('name', $iupacName);
    if (!$foundCid && !empty($inchiKey) && $inchiKey !== 'NA') $foundCid = $fetcher->searchPubChem('inchikey', $inchiKey);
    if (!$foundCid && !empty($casNumber) && $casNumber !== 'NA') $foundCid = $fetcher->searchPubChem('name', $casNumber);
    if (!$foundCid && !empty($cid) && intval($cid) > 0) $foundCid = intval($cid);
    if (!$foundCid && !empty($productName)) $foundCid = $fetcher->searchPubChem('name', $productName);

    if (!$foundCid) return null;

    return $fetcher->fetchProperties($foundCid);
}


function fetchCompoundImage(string $iupacName, string $slug): string {
    if (empty($iupacName) || $iupacName === 'NA') return '';
    $imageDir  = __DIR__ . '/compound_images/';
    if (!is_dir($imageDir)) mkdir($imageDir, 0755, true);
    $localPath = $imageDir . $slug . '.png';
    if (file_exists($localPath) && filesize($localPath) > 100) return '/compound_images/' . $slug . '.png';

    $ch = curl_init();
    curl_setopt_array($ch, [
        // rawurlencode for path encoding — '+' would be treated as literal in the URL path
        CURLOPT_URL            => "https://www.ebi.ac.uk/opsin/ws/" . rawurlencode($iupacName) . ".png",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'ABChem-Fetcher/2.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $imageData = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);


    if ($httpCode === 200 && $imageData && strlen($imageData) > 500 && strpos($imageData, "\x89PNG") === 0) {
        file_put_contents($localPath, $imageData);
        return '/compound_images/' . $slug . '.png';
    }
    return '';
}

/**
 * Download an image from an external URL, validate it, save it to /compound_images/,
 * and return the local web path — or an error string.
 *
 * Returns: ['path' => '/compound_images/...', 'size' => bytes]  on success
 *          ['error' => 'message']  on failure
 */

function fetchImageFromUrl(string $imageUrl, string $productSlug): array {
    // 1. Basic URL validation
    if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        return ['error' => 'Invalid URL format. Please provide a full URL starting with https://'];
    }
    $scheme = strtolower(parse_url($imageUrl, PHP_URL_SCHEME) ?? '');
    if ($scheme !== 'https') {
        return ['error' => 'Only HTTPS image URLs are accepted for security reasons.'];
    }

    // 2. Download via cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $imageUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'ABChem-ImageFetcher/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_BUFFERSIZE     => 128 * 1024,
    ]);
    $imageData = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);


    if ($httpCode !== 200 || empty($imageData)) {
        return ['error' => "Could not download image (HTTP $httpCode). Check the URL is publicly accessible."];
    }

    // 3. Size guard — max 5 MB
    $sizeBytes = strlen($imageData);
    if ($sizeBytes > 5 * 1024 * 1024) {
        return ['error' => 'Image is too large (' . round($sizeBytes / 1048576, 1) . ' MB). Maximum allowed is 5 MB.'];
    }

    // 4. Server-side MIME validation via finfo
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $tmpFile  = tempnam(sys_get_temp_dir(), 'abcimg_');
    file_put_contents($tmpFile, $imageData);
    $realMime = $finfo->file($tmpFile);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$realMime])) {
        unlink($tmpFile);
        return ['error' => "File is not a supported image type (detected: $realMime). Only JPG, PNG, GIF, WebP allowed."];
    }

    // 5. Double-check with getimagesize()
    $imgInfo = @getimagesize($tmpFile);
    if (!$imgInfo) {
        unlink($tmpFile);
        return ['error' => 'Downloaded file does not appear to be a valid image (corrupt or truncated).'];
    }

    $ext      = $allowed[$realMime];
    $imageDir = __DIR__ . '/compound_images/';
    if (!is_dir($imageDir)) {
        if (!mkdir($imageDir, 0755, true)) {
            unlink($tmpFile);
            return ['error' => 'Server error: could not create compound_images directory.'];
        }
    }

    // ✅ CLEAN FILENAME: Just the slug + extension, no hashes, no random bytes
    $cleanSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($productSlug));
    $cleanSlug = trim($cleanSlug, '-');

    // If slug is empty after cleaning, use a fallback
    if (empty($cleanSlug)) {
        $cleanSlug = 'product-image';
    }

    $filename = $cleanSlug . '.' . $ext;
    $filepath = $imageDir . $filename;

    // If file already exists, overwrite it (no suffix)
    // This ensures one product = one image file, always the same name

    if (!rename($tmpFile, $filepath)) {
        @unlink($tmpFile);
        return ['error' => 'Server error: could not save image file.'];
    }

    // Set proper permissions
    chmod($filepath, 0644);

    return [
        'path' => '/compound_images/' . $filename,
        'size' => $sizeBytes,
        'mime' => $realMime,
        'dims' => $imgInfo[0] . '×' . $imgInfo[1] . 'px',
    ];
}

function buildDiff(array $beforeState, array $pubchemData, array $chemFields, bool $forceOverwrite): array {
    $diff = [];
    foreach ($chemFields as $f) {
        $oldVal = trim($beforeState[$f] ?? '');
        $newVal = trim($pubchemData[$f]  ?? '');
        if ($newVal === '' || $newVal === 'NA') continue;

        $wasEmpty  = ($oldVal === '' || $oldVal === 'NA');
        $willFill  = $wasEmpty || $forceOverwrite;

        $diff[$f] = [
            'before'  => $oldVal ?: '(empty)',
            'after'   => $newVal,
            'filled'  => $wasEmpty,          // true = was empty and is now filled
            'forced'  => !$wasEmpty && $forceOverwrite, // true = had value but overwritten
            'applied' => $willFill,           // whether this value is actually saved
        ];
    }
    return $diff;
}

function renderFormField(string $fieldName, $value, array $columnDefaults): string {
    $label = ucwords(str_replace('_', ' ', $fieldName));
    $rawValue = $value ?? $columnDefaults[$fieldName] ?? '';
    // FEAT-31b: applications stored pipe-separated; show one per line for editing
    if ($fieldName === 'applications' && is_string($rawValue) && strpos($rawValue, '|') !== false) {
        $rawValue = str_replace('|', "\n", $rawValue);
    }
    $value = htmlspecialchars($rawValue);
    $id    = 'field_' . $fieldName;

    $textareaFields = ['smiles', 'inchi', 'iupac_name', 'synonyms', 'meta_description',
                       'keywords', 'smiles_canonical', 'description', 'applications'];
    if (in_array($fieldName, $textareaFields)) {
        // FEAT-31a/31b: helper text + multi-line input for description + applications
        $helper      = '';
        $placeholder = '';
        $rows        = 3;
        if ($fieldName === 'applications') {
            $rows        = 4;
            $placeholder = "One per line. Example:\nUsed as EP impurity marker in Aspirin analysis per Ph. Eur. 0174.\nReference standard for HPLC purity testing.";
            $helper      = '<small style="display:block;color:#64748b;font-size:0.75rem;margin-top:4px;">'
                         . '🎯 One application per line — stored pipe-separated, rendered as a bulleted list on the product page.'
                         . '</small>';
        } elseif ($fieldName === 'description') {
            $rows        = 5;
            $placeholder = 'Free-form "What is this compound?" paragraph. Shown above synonyms on the product page.';
            $helper      = '<small style="display:block;color:#64748b;font-size:0.75rem;margin-top:4px;">'
                         . '📖 Long-form description — overrides the auto-generated meta_description fallback.'
                         . '</small>';
        }
        return '<div class="form-group span-full">
            <label class="form-label">' . $label . '</label>
            <textarea name="' . $fieldName . '" id="' . $id . '" rows="' . $rows . '" class="form-textarea"'
            . ($placeholder ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '') . '>' . $value . '</textarea>'
            . $helper .
        '</div>';
    }
    if ($fieldName === 'product_name' || $fieldName === 'compound_name') {
        // Q1: dropdown of imported names + free-text. Uses HTML <datalist> so
        // the admin can pick a known name OR type a brand-new one.
        $cands  = $GLOBALS['compoundNameCandidates'] ?? [];
        $opts   = '';
        $helper = '';
        if (!empty($cands)) {
            foreach ($cands as $c) {
                $opts .= '<option value="' . htmlspecialchars($c['name']) . '">' . htmlspecialchars($c['src']) . '</option>';
            }
            $count   = count($cands);
            $helper  = '<small style="display:block;color:#64748b;font-size:0.75rem;margin-top:4px;">'
                     . '🏷️ ' . $count . ' name' . ($count === 1 ? '' : 's') . ' on file (current + supplier + PubChem). '
                     . 'Pick from the list <strong>or</strong> type a new one.'
                     . '</small>';
        }
        return '<div class="form-group span-full">
            <label class="form-label">Compound Name <span style="color:#ef4444">*</span></label>
            <input type="text" name="compound_name" id="field_compound_name" value="' . $value . '" required
                   list="compound-name-options" autocomplete="off"
                   class="form-input" placeholder="e.g. Aspirin Impurity A">
            <datalist id="compound-name-options">' . $opts . '</datalist>'
            . $helper .
        '</div>';
    }
    // Fields that need extra horizontal space
    if (in_array($fieldName, ['image_url', 'inchi_key', 'meta_title', 'regulatory_ref'])) {
        return '<div class="form-group span-2">
            <label class="form-label">' . $label . '</label>
            <input type="text" name="' . $fieldName . '" id="' . $id . '" value="' . $value . '" class="form-input">
        </div>';
    }
    foreach ([
        'availability' => ['In Stock', 'Backorder', 'On Request', 'Discontinued'],
        'product_type' => PRODUCT_TYPES,
        'status'       => ['Active', 'Inactive', 'Draft'],
        'unit'         => ['mg', 'g', 'kg', 'ml', 'L', 'vial', 'ampoule', 'tablet', 'capsule', 'lot'],
    ] as $sel => $opts) {
        if ($fieldName === $sel) {
            $isRequired  = ($fieldName === 'product_type');
            $reqAttr     = $isRequired ? ' required' : '';
            $reqStar     = $isRequired ? ' <span style="color:#ef4444">*</span>' : '';
            $html = '<div class="form-group"><label class="form-label">' . $label . $reqStar . '</label>'
                  . '<select name="' . $fieldName . '" id="' . $id . '" class="form-select"' . $reqAttr . '>';
            if ($fieldName === 'product_type') $html .= '<option value="">— Select type —</option>';
            foreach ($opts as $opt) {
                $html .= '<option value="' . $opt . '"' . ($value == $opt ? ' selected' : '') . '>' . $opt . '</option>';
            }
            $html .= '</select></div>';
            return $html;
        }
    }
    if ($fieldName === 'stock_status') {
        $opts = ['in_stock' => 'In Stock', 'low_stock' => 'Low Stock', 'backordered' => 'Backordered', 'discontinued' => 'Discontinued'];
        $html = '<div class="form-group"><label class="form-label">' . $label . '</label><select name="' . $fieldName . '" id="' . $id . '" class="form-select">';
        foreach ($opts as $v => $l) {
            $html .= '<option value="' . $v . '"' . ($value == $v ? ' selected' : '') . '>' . $l . '</option>';
        }
        return $html . '</select></div>';
    }
    if ($fieldName === 'supplier_id') {
        $db        = Database::getInstance();
        $suppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");
        $html      = '<div class="form-group"><label class="form-label">Supplier</label>'
                   . '<select name="supplier_id" id="field_supplier_id" class="form-select">'
                   . '<option value="">-- Select Supplier --</option>';
        foreach ($suppliers as $sup) {
            $html .= '<option value="' . $sup['id'] . '"' . ((string)$value === (string)$sup['id'] ? ' selected' : '') . '>'
                   . htmlspecialchars($sup['supplier_name'], ENT_QUOTES, 'UTF-8') . '</option>';
        }
        return $html . '</select></div>';
    }
    if ($fieldName === 'hazardous') {
        return '<div class="form-group">
            <label class="form-label">' . $label . '</label>
            <label class="checkbox-label">
                <input type="hidden" name="' . $fieldName . '" value="0">
                <input type="checkbox" name="' . $fieldName . '" id="' . $id . '" value="1"' . ($value == '1' ? ' checked' : '') . ' class="form-checkbox"> Yes
            </label>
        </div>';
    }
    if (in_array($fieldName, ['manufacture_date', 'expiry_date'])) {
        return '<div class="form-group">
            <label class="form-label">' . $label . '</label>
            <input type="date" name="' . $fieldName . '" id="' . $id . '" value="' . $value . '" class="form-input">
        </div>';
    }
    return '<div class="form-group">
        <label class="form-label">' . $label . '</label>
        <input type="text" name="' . $fieldName . '" id="' . $id . '" value="' . $value . '" class="form-input">
    </div>';
}

// =============================================
// AJAX ENDPOINT — image fetch (called by JS fetch())
// Returns JSON, no HTML
// =============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch_image') {
    ob_end_clean();
    header('Content-Type: application/json');
    $imageUrl = trim($_POST['image_url'] ?? '');
    $slug     = trim($_POST['slug'] ?? '');
    $prodId   = intval($_POST['product_id'] ?? 0);

    if (empty($imageUrl) || empty($slug)) {
        echo json_encode(['error' => 'Image URL and product slug are required.']);
        exit;
    }

    $result = fetchImageFromUrl($imageUrl, $slug);

    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]);
        exit;
    }

    // Update DB immediately so the path is saved even without re-saving the whole form
    if ($prodId > 0) {
        try {
            $db->update('compounds', ['image_url' => $result['path']], 'id = :id', ['id' => $prodId]);
            logAudit('compound_image_updated', "Image updated for compound ID $prodId via URL fetch", '', $result['path']);
        } catch (Exception $e) {
            // Non-fatal — image is saved on disk, just warn
            $result['db_warning'] = 'Image saved but DB update failed: ' . $e->getMessage();
        }
    }

    echo json_encode(['success' => true] + $result);
    exit;
}
// =============================================
// AJAX ENDPOINT — PubChem fetch only (no full save)
// Returns JSON field data so JS can preview before saving
// =============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pubchem_preview') {
    ob_end_clean();
    header('Content-Type: application/json');
    $data = autoFetchPubChemData(
        trim($_POST['compound_name'] ?? $_POST['product_name'] ?? ''),
        trim($_POST['iupac_name']   ?? ''),
        trim($_POST['inchi_key']    ?? ''),
        trim($_POST['cas_number']   ?? ''),
        trim($_POST['pubchem_cid']  ?? '')
    );
    echo json_encode($data ? ['success' => true, 'data' => $data] : ['error' => 'No compound found in PubChem for the identifiers provided.']);
    exit;
}

// ── AJAX: fetch a single listing row (used to pre-populate edit modal) ───
if (isset($_GET['ajax']) && $_GET['ajax'] === 'listing_get') {
    ob_end_clean();
    header('Content-Type: application/json');
    $listingId  = intval($_POST['listing_id']  ?? 0);
    $compoundId = intval($_POST['compound_id'] ?? 0);
    if (!$listingId) { echo json_encode(['error' => 'listing_id required']); exit; }
    $listing = $db->fetchOne(
        "SELECT sl.*, s.supplier_name AS company_make
         FROM supplier_listings sl
         JOIN suppliers s ON s.id = sl.supplier_id
         WHERE sl.id = :id AND sl.compound_id = :cid",
        ['id' => $listingId, 'cid' => $compoundId]
    );
    echo json_encode($listing ? ['listing' => $listing] : ['error' => 'Listing not found']);
    exit;
}

// ── AJAX/GET: Download supplier import XLSX template ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'download_template') {
    ob_end_clean();
    require_once __DIR__ . '/../private/SupplierTemplateGenerator.php';
    $supplierName = '';
    $sid = intval($_GET['supplier_id'] ?? 0);
    if ($sid) {
        $srow = $db->fetchOne("SELECT supplier_name FROM suppliers WHERE id=:id", ['id' => $sid]);
        $supplierName = $srow['supplier_name'] ?? '';
    }
    $gen   = new SupplierTemplateGenerator();
    $bytes = $gen->generate($supplierName);
    $fname = $supplierName
        ? 'abchem_supplier_template_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($supplierName)) . '.xlsx'
        : 'abchem_supplier_import_template.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-cache');
    echo $bytes;
    exit;
}

// ── POST: Import supplier Excel ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_excel') {
    $supplierId = intval($_POST['supplier_id'] ?? 0);
    if (!$supplierId) {
        $_SESSION['import_result'] = ['success' => false, 'error' => 'Please select a supplier before importing'];
    } elseif (empty($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['import_result'] = ['success' => false, 'error' => 'File upload failed (code: ' . ($_FILES['xlsx_file']['error'] ?? '?') . ')'];
    } else {
        // Validate MIME (XLSX = ZIP-based)
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($_FILES['xlsx_file']['tmp_name']);
        $okMimes  = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'];
        if (!in_array($realMime, $okMimes, true) && !str_ends_with(strtolower($_FILES['xlsx_file']['name']), '.xlsx')) {
            $_SESSION['import_result'] = ['success' => false, 'error' => 'Only .xlsx files are accepted'];
        } else {
            $_SESSION['import_result'] = importFromSupplierExcel($_FILES['xlsx_file']['tmp_name'], $supplierId);
        }
    }
    header('Location: ?action=import');
    exit;
}

// ── AJAX: suppliers list ──────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_suppliers') {
    ob_end_clean();
    header('Content-Type: application/json');
    $rows = $db->fetchAll("SELECT id, supplier_name, catalog_prefix FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");
    echo json_encode($rows);
    exit;
}

// ── AJAX: save/update a single supplier listing ───────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'listing_save') {
    ob_end_clean();
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); exit; }
    $compoundId = intval($_POST['compound_id'] ?? 0);
    $listingId  = intval($_POST['listing_id']  ?? 0);
    if (!$compoundId) { echo json_encode(['error' => 'compound_id required']); exit; }
    try {
        if ($listingId) {
            $fields = [
                'supplier_id'        => intval($_POST['supplier_id'] ?? 1),
                'catalog_number'     => nullIfEmpty($_POST['catalog_number'] ?? null),
                'purity'             => nullIfEmpty($_POST['purity'] ?? null),
                'purity_by_method'   => nullIfEmpty($_POST['purity_by_method'] ?? null),
                'availability'       => in_array($_POST['availability'] ?? '', ['In Stock','Backorder','On Request','Discontinued'])
                                        ? $_POST['availability'] : 'On Request',
                'stock_status'       => in_array($_POST['stock_status'] ?? '', ['in_stock','low_stock','backordered','discontinued'])
                                        ? $_POST['stock_status'] : 'in_stock',
                'min_order_qty'      => is_numeric($_POST['min_order_qty'] ?? '') ? (float)$_POST['min_order_qty'] : 1.0,
                'unit'               => in_array($_POST['unit'] ?? '', ['mg','g','kg','ml','L','vial','ampoule','tablet','capsule','lot'])
                                        ? $_POST['unit'] : 'mg',
                'lead_time'          => nullIfEmpty($_POST['lead_time'] ?? null),
                'lot_number'         => nullIfEmpty($_POST['lot_number'] ?? null),
                'manufacture_date'   => !empty($_POST['manufacture_date']) ? date('Y-m-d', strtotime($_POST['manufacture_date'])) : null,
                'expiry_date'        => !empty($_POST['expiry_date'])      ? date('Y-m-d', strtotime($_POST['expiry_date']))      : null,
                'quantity_available' => is_numeric($_POST['quantity_available'] ?? '') ? (float)$_POST['quantity_available'] : null,
                'supplier_notes'     => nullIfEmpty($_POST['supplier_notes'] ?? null),
                'status'             => in_array($_POST['status'] ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active',
            ];
            $db->update('supplier_listings', $fields, 'id = :id AND compound_id = :cid', ['id' => $listingId, 'cid' => $compoundId]);
        } else {
            $listingId = saveSupplierListing(array_merge($_POST, ['compound_id' => $compoundId]), $compoundId);
        }
        $listing = $db->fetchOne(
            "SELECT sl.*, s.supplier_name AS company_make
             FROM supplier_listings sl
             JOIN suppliers s ON s.id = sl.supplier_id
             WHERE sl.id = :id",
            ['id' => $listingId]
        );
        logAudit('listing_saved', "Listing #$listingId saved for compound #$compoundId");
        echo json_encode(['success' => true, 'listing' => $listing]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: delete a supplier listing ──────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'listing_delete') {
    ob_end_clean();
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); exit; }
    $listingId  = intval($_POST['listing_id']  ?? 0);
    $compoundId = intval($_POST['compound_id'] ?? 0);
    if (!$listingId || !$compoundId) { echo json_encode(['error' => 'listing_id and compound_id required']); exit; }
    $activeCount = (int)$db->fetchValue(
        "SELECT COUNT(*) FROM supplier_listings WHERE compound_id = :cid AND status = 'Active'",
        ['cid' => $compoundId]
    );
    if ($activeCount <= 1) {
        $db->update('supplier_listings', ['status' => 'Inactive'], 'id = :id', ['id' => $listingId]);
        logAudit('listing_deactivated', "Listing #$listingId deactivated (last active listing for compound #$compoundId)");
        echo json_encode(['success' => true, 'soft_delete' => true,
            'message' => 'Listing marked Inactive — cannot delete the only active listing.']);
    } else {
        $db->delete('supplier_listings', 'id = :id AND compound_id = :cid', ['id' => $listingId, 'cid' => $compoundId]);
        logAudit('listing_deleted', "Listing #$listingId deleted from compound #$compoundId");
        echo json_encode(['success' => true]);
    }
    exit;
}

// ── AJAX: Excel (.xlsx) template download ─────────────────────────────────
// Generates a workbook with dropdown validation, colour-coded required columns,
// frozen header row, auto-filter — no PHP library needed (pure ZipArchive/XML).
function generateCompoundTemplateXlsx(): string {
    // [field_name, header_label, is_required, dropdown_csv|null, example_value, col_width]
    $cols = [
        ['compound_name',        'compound_name *',               true,  null, 'Aspirin', 24],
        ['cas_number',           'cas_number',                    false, null, '50-78-2', 13],
        ['parent_drug',          'parent_drug',                   false, null, 'Salicylic acid', 18],
        ['supplier_id',          'supplier_id *',                 true,  null, '3', 12],
        ['product_type',         'product_type *',                true,
            'API Impurity,Reference Standard,Metabolite,Intermediate,API,Building Block,Isotope',
            'API Impurity', 22],
        ['availability',         'availability *',                true,
            'In Stock,Backorder,On Request,Discontinued', 'In Stock', 14],
        ['iupac_name',           'iupac_name',                    false, null, '2-acetoxybenzoic acid', 28],
        ['molecular_formula',    'molecular_formula',             false, null, 'C9H8O4', 16],
        ['molecular_weight',     'molecular_weight',              false, null, '180.16', 16],
        ['smiles',               'smiles',                        false, null, 'CC(=O)Oc1ccccc1C(O)=O', 32],
        ['inchi',                'inchi',                         false, null, 'InChI=1S/C9H8O4/...', 32],
        ['inchi_key',            'inchi_key',                     false, null, 'BSYNRYMUTXBLSP-UHFFFAOYSA-N', 28],
        ['pubchem_cid',          'pubchem_cid',                   false, null, '2244', 12],
        ['synonyms',             'synonyms (pipe-separated)',      false, null, 'Acetylsalicylic acid|ASA', 28],
        
        ['storage_condition',    'storage_condition',             false, null, '2-8°C refrigerator', 20],
        ['therapeutic_category', 'therapeutic_category',          false, null, 'Anti-inflammatory', 20],
        ['regulatory_ref',       'regulatory_ref',                false, null, 'BP 2024', 14],
        ['hazard_class',         'hazard_class',                  false, null, 'Class II', 12],
        ['status',               'status',                        false, 'Active,Inactive,Draft', 'Active', 10],
        
        ['purity',               'purity',                        false, null, '≥99.5%', 12],
        ['purity_by_method',     'purity_by_method',              false, 'HPLC,NMR,GC,MS,Titration,UV', 'HPLC', 16],
        
        ['stock_status',         'stock_status',                  false,
            'in_stock,low_stock,backordered,discontinued', 'in_stock', 14],
        ['min_order_qty',        'min_order_qty',                 false, null, '10', 14],
        ['unit',                 'unit',                          false,
            'mg,g,kg,ml,L,vial,ampoule,tablet,capsule,lot', 'mg', 10],
        ['lead_time',            'lead_time',                     false, null, '7-14 days', 12],
        ['lot_number',           'lot_number',                    false, null, 'LOT-2024-001', 14],
        ['manufacture_date',     'manufacture_date (YYYY-MM-DD)', false, null, '2024-01-15', 22],
        ['expiry_date',          'expiry_date (YYYY-MM-DD)',       false, null, '2026-01-15', 20],
        ['catalog_number',       'catalog_number (auto if blank)',false, null, '', 24],
        ['supplier_notes',       'supplier_notes',                false, null, 'Standard reference material', 22],
    ];

    $numCols = count($cols);
    // Column letter: 0→A … 25→Z, 26→AA, 27→AB …
    $cl = fn(int $i) => $i < 26 ? chr(65+$i) : chr(64+intdiv($i,26)).chr(65+($i%26));
    $xe = fn($s) => str_replace(['&','<','>'], ['&amp;','&lt;','&gt;'], (string)$s);

    /* ── styles.xml ──────────────────────────────────────────────────────── */
    // xf index 0 = default, 1 = required header (blue bg + white bold), 2 = optional header (gray bg + bold)
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="3">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <fills count="4">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF2E75B6"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFD6DCE4"/></patternFill></fill>
  </fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

    /* ── column widths ───────────────────────────────────────────────────── */
    $colsXml = '<cols>';
    foreach ($cols as $i => $col) {
        $colsXml .= '<col min="'.($i+1).'" max="'.($i+1).'" width="'.$col[5].'" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    /* ── row 1: header ───────────────────────────────────────────────────── */
    $headerRow = '<row r="1" ht="20" customHeight="1">';
    foreach ($cols as $i => $col) {
        $s = $col[2] ? '1' : '2'; // required=blue, optional=gray
        $headerRow .= '<c r="'.$cl($i).'1" t="inlineStr" s="'.$s.'"><is><t>'.$xe($col[1]).'</t></is></c>';
    }
    $headerRow .= '</row>';

    /* ── row 2: example ──────────────────────────────────────────────────── */
    $exRow = '<row r="2">';
    foreach ($cols as $i => $col) {
        $v = (string)$col[4];
        if ($v === '') continue;
        if (is_numeric($v)) {
            $exRow .= '<c r="'.$cl($i).'2"><v>'.$xe($v).'</v></c>';
        } else {
            $exRow .= '<c r="'.$cl($i).'2" t="inlineStr"><is><t>'.$xe($v).'</t></is></c>';
        }
    }
    $exRow .= '</row>';

    /* ── data validations (dropdowns for rows 2–1000) ────────────────────── */
    $dvXml = ''; $dvCount = 0;
    foreach ($cols as $i => $col) {
        if (!$col[3]) continue;
        $letter = $cl($i);
        $dvXml .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorAlert="1"'
                . ' sqref="'.$letter.'2:'.$letter.'1000">'
                . '<formula1>"'.$col[3].'"</formula1></dataValidation>';
        $dvCount++;
    }
    if ($dvCount) $dvXml = '<dataValidations count="'.$dvCount.'">'.$dvXml.'</dataValidations>';

    $lastCol = $cl($numCols - 1);

    /* ── sheet1.xml ──────────────────────────────────────────────────────── */
    $sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews><sheetView tabSelected="1" workbookViewId="0">
    <pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>
  </sheetView></sheetViews>
  '.$colsXml.'
  <sheetData>'.$headerRow.$exRow.'</sheetData>
  <autoFilter ref="A1:'.$lastCol.'1"/>
  '.$dvXml.'
</worksheet>';

    /* ── workbook + relationships ─────────────────────────────────────────── */
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Compound Template" sheetId="1" r:id="rId1"/></sheets>
</workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"           ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"  ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml"             ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

    /* ── zip into .xlsx ───────────────────────────────────────────────────── */
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create XLSX temp file');
    }
    $zip->addFromString('[Content_Types].xml',       $ct);
    $zip->addFromString('_rels/.rels',               $rels);
    $zip->addFromString('xl/workbook.xml',           $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels',$wbRels);
    $zip->addFromString('xl/styles.xml',             $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml',  $sheet1);
    $zip->close();

    $content = file_get_contents($tmp);
    unlink($tmp);
    return $content;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'csv_template') {
    ob_end_clean();
    error_reporting(0);
    $xlsx    = generateCompoundTemplateXlsx();
    $safeDate = date('Y-m-d');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="compound_import_template_'.$safeDate.'.xlsx"');
    header('Content-Length: ' . strlen($xlsx));
    header('Cache-Control: no-store, no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $xlsx;
    exit;
}

// =============================================
// POST HANDLERS  (single, clean if/elseif chain)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // ── ADD or UPDATE product ──────────────────────────────────────────────
    if ($postAction === 'add' || $postAction === 'update') {
        $forceOverwrite  = isset($_POST['force_pubchem']) && $_POST['force_pubchem'] === '1';
        $doFetch         = isset($_POST['fetch_pubchem'])  && $_POST['fetch_pubchem']  === '1';
        $pubchemData     = null;
        $pubchemDiffData = null;

        if ($doFetch) {
            $beforeState = [];
            foreach ($chemicalFields as $f) $beforeState[$f] = $_POST[$f] ?? '';
            $compoundNameForFetch = $_POST['compound_name'] ?? $_POST['product_name'] ?? '';
            $pubchemData = autoFetchPubChemData(
                $compoundNameForFetch,
                $_POST['iupac_name']   ?? '',
                $_POST['inchi_key']    ?? '',
                $_POST['cas_number']   ?? '',
                $_POST['pubchem_cid']  ?? ''
            );
            if ($pubchemData) {
                $diff = buildDiff($beforeState, $pubchemData, $chemicalFields, $forceOverwrite);
                if (!empty($diff))
                    $pubchemDiffData = ['product' => $compoundNameForFetch, 'fields' => $diff, 'forced' => $forceOverwrite];
            }
        }

        // Merge form + PubChem data
        //
        // Precedence (highest to lowest):
        //   1. Force-overwrite mode → PubChem wins for chemical fields
        //   2. Fetch mode + empty form field + chem field + PubChem has data → PubChem wins
        //      (BUG FIX: previously the "update + empty form value" branch below silently
        //      discarded PubChem data when admin clicked "Fetch Missing Fields" on a row
        //      with empty chem textareas. The diff panel still said "✚ Filled" but the
        //      empty form value overwrote PubChem's value during save.)
        //   3. Form value present (or admin deliberately cleared it on update) → form wins
        //   4. Otherwise → fall back to PubChem
        $rawData = [];
        foreach ($allColumns as $col) {
            if (in_array($col, ['id', 'slug', 'created_at', 'updated_at', 'created_by'])) continue;
            $pubchemVal = $pubchemData[$col]  ?? null;
            $formVal    = $_POST[$col]        ?? null;
            $isChem     = in_array($col, $chemicalFields);

            if ($forceOverwrite && $isChem && !empty($pubchemVal) && $pubchemVal !== 'NA') {
                $rawData[$col] = $pubchemVal;
            }
            elseif ($doFetch && $isChem && trim((string)$formVal) === '' && !empty($pubchemVal) && $pubchemVal !== 'NA') {
                // ⚗️ Fetch mode and form field is empty → use what PubChem returned
                $rawData[$col] = $pubchemVal;
            }
            elseif ($formVal !== null && ($formVal !== '' || $postAction === 'update')) {
                // For updates: keep empty strings so admin-cleared fields reach saveCompound as null
                $rawData[$col] = $formVal;
            }
            elseif (!empty($pubchemVal)) {
                $rawData[$col] = $pubchemVal;
            }
        }
        $rawData['compound_name'] = $_POST['compound_name'] ?? $_POST['product_name'] ?? '';
        $rawData['created_by']    = $_SESSION['email'] ?? 'admin';

        // FEAT-31b: convert one-application-per-line input → pipe-separated storage
        if (isset($rawData['applications'])) {
            $lines = array_values(array_filter(array_map('trim',
                preg_split('/\r?\n/', $rawData['applications']))));
            $rawData['applications'] = empty($lines) ? null : implode('|', $lines);
        }

        // ── Server-side required-field validation ──────────────────────────
        if (empty(trim($rawData['compound_name']))) {
            $_SESSION['flash_error'] = 'Compound name is required.';
            header('Location: ?action=' . ($postAction === 'add' ? 'add' : 'edit&id=' . intval($_POST['id'] ?? 0)));
            exit;
        }
        if (empty(trim($rawData['product_type'] ?? ''))) {
            $_SESSION['flash_error'] = 'Product type is required. Please select a type from the dropdown.';
            header('Location: ?action=' . ($postAction === 'add' ? 'add' : 'edit&id=' . intval($_POST['id'] ?? 0)));
            exit;
        }

        // Auto-generate structure image if missing
        if (empty($rawData['image_url']) && !empty($rawData['iupac_name']) && $rawData['iupac_name'] !== 'NA') {
            $tmpSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $rawData['compound_name'])));
            $rawData['image_url'] = fetchCompoundImage($rawData['iupac_name'], $tmpSlug);
        }

        try {
            if ($postAction === 'add') {
                // ── DEDUPLICATION CHECK ───────────────────────────────────
                $existing = findExistingCompound($rawData);
                if ($existing) {
                    $compoundId = $existing['id'];
                    saveCompound($rawData, $compoundId, $forceOverwrite);
                    saveSupplierListing($rawData, $compoundId);
                    logAudit('compound_deduplicated',
                        "Merged '{$rawData['compound_name']}' into existing compound #{$compoundId} via {$existing['matched_by']}");
                    $_SESSION['flash_message'] =
                        "⚠️ Duplicate detected — matched by {$existing['matched_by']} to existing compound “{$existing['name']}”. "
                        . "Supplier listing was added/updated instead of creating a duplicate.";
                } else {
                    $compoundId = saveCompound($rawData);
                    saveSupplierListing($rawData, $compoundId);
                    logAudit('compound_added', "Added: {$rawData['compound_name']}");
                    $note = !$pubchemData ? '' : ($pubchemDiffData
                        ? ' PubChem: ' . count($pubchemDiffData['fields']) . ' fields fetched.' : ' PubChem: no new data.');
                    $_SESSION['flash_message'] = "Compound added successfully.$note";
                }
                if ($pubchemDiffData) $_SESSION['pubchem_diff'] = $pubchemDiffData;
                header("Location: ?action=edit&id=$compoundId");
            } else {
                $compoundId = intval($_POST['id']);
                saveCompound($rawData, $compoundId, true); // admin edit always overwrites — fill-only is for imports only
                saveSupplierListing($rawData, $compoundId);
                logAudit('compound_updated', "Updated compound ID: $compoundId", '', $rawData['compound_name']);
                $note = !$pubchemData ? '' : ($pubchemDiffData
                    ? ' PubChem: ' . count($pubchemDiffData['fields']) . ' fields updated.' : ' PubChem: no new fields.');
                $_SESSION['flash_message'] = "Compound saved successfully.$note";
                if ($pubchemDiffData) $_SESSION['pubchem_diff'] = $pubchemDiffData;
                header("Location: ?action=edit&id=$compoundId");
            }
            exit;
        } catch (Exception $e) {
            $error = "Save failed: " . $e->getMessage();
        }

    // ── BULK CSV IMPORT ────────────────────────────────────────────────────
    } elseif ($postAction === 'bulk_update') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === 0) {
            $result = importCompoundsFromCSV($_FILES['csv_file']['tmp_name']);
            if ($result['success']) {
                $message = "Bulk import complete — "
                    . "New: {$result['inserted']}, Enriched: {$result['updated']}, "
                    . "New listings: {$result['new_listings']}, Updated: {$result['updated_listings']}, "
                    . "Skipped: {$result['skipped']}.";
                if (!empty($result['pubchem_note'])) $message .= ' ' . $result['pubchem_note'];
                elseif (($result['pubchem_fetched'] ?? 0) > 0) $message .= " PubChem enriched {$result['pubchem_fetched']} compound(s).";
                if (!empty($result['dedup_log'])) {
                    $_SESSION['dedup_log'] = $result['dedup_log'];
                    $message .= " " . count($result['dedup_log']) . " duplicate(s) merged — see report below.";
                }
                if (!empty($result['warnings'])) {
                    $_SESSION['import_warnings'] = $result['warnings'];
                    $message .= " ⚠️ " . count($result['warnings']) . " warning(s) need review — see report below.";
                }
            } else {
                $error = "CSV import failed: " . $result['error'];
            }
        } else {
            $error = "No valid CSV file uploaded.";
        }
    }
}

// ── DELETE (GET action) ────────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    try {
        // Deleting a compound also removes all its supplier_listings via FK CASCADE
        $db->delete('compounds', 'id = :id', ['id' => intval($_GET['id'])]);
        logAudit('compound_deleted', "Deleted compound ID: " . intval($_GET['id']));
        $_SESSION['flash_message'] = "Compound and all its supplier listings deleted.";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Delete failed: " . $e->getMessage();
    }
    header('Location: ?action=list');
    exit;
}

// =============================================
// FETCH DATA FOR DISPLAY — with pagination (Item 13)
// =============================================
$products    = [];
$totalRows   = 0;
$perPage     = 50;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

if ($action === 'list') {
    // ── Sort handling ────────────────────────────────────────────────
    // Allowlist keeps SQL injection impossible — only these columns can drive ORDER BY.
    // Map: ?sort=key → SQL expression
    $sortMap = [
        'id'           => 'c.id',
        'product_name' => 'c.compound_name',
        'cas'          => 'c.cas_number',
        'type'         => 'c.product_type',
        'purity'       => 'sl.purity',
        'status'       => 'c.status',
    ];
    $sortKey = $_GET['sort'] ?? 'product_name';
    if (!isset($sortMap[$sortKey])) $sortKey = 'product_name';
    $sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    // Tiebreak by id so paging is stable when many rows share the same sort value
    $orderBy = $sortMap[$sortKey] . " $sortDir, c.id ASC";

    $baseJoin = "FROM compounds c
                 LEFT JOIN supplier_listings sl
                     ON sl.id = (SELECT MIN(id) FROM supplier_listings WHERE compound_id = c.id AND status='Active')
                 LEFT JOIN suppliers s ON s.id = sl.supplier_id";
    if ($search) {
        $totalRows = (int)$db->fetchValue(
            "SELECT COUNT(DISTINCT c.id) $baseJoin
             WHERE c.compound_name LIKE :s OR c.cas_number LIKE :s2 OR sl.catalog_number LIKE :s3",
            ['s' => "%$search%", 's2' => "%$search%", 's3' => "%$search%"]
        );
        $products = $db->fetchAll(
            "SELECT c.id, c.slug, c.compound_name AS product_name, c.cas_number,
                    c.product_type, c.status, c.image_url,
                    sl.id AS listing_id, sl.purity, sl.availability, sl.catalog_number,
                    sl.stock_status, sl.lead_time, s.supplier_name AS company_make
             $baseJoin
             WHERE c.compound_name LIKE :s OR c.cas_number LIKE :s2 OR sl.catalog_number LIKE :s3
             ORDER BY $orderBy LIMIT :lim OFFSET :off",
            ['s' => "%$search%", 's2' => "%$search%", 's3' => "%$search%", 'lim' => $perPage, 'off' => $offset]
        );
    } else {
        $totalRows = (int)$db->fetchValue("SELECT COUNT(*) FROM compounds");
        $products  = $db->fetchAll(
            "SELECT c.id, c.slug, c.compound_name AS product_name, c.cas_number,
                    c.product_type, c.status, c.image_url,
                    sl.id AS listing_id, sl.purity, sl.availability, sl.catalog_number,
                    sl.stock_status, sl.lead_time, s.supplier_name AS company_make
             $baseJoin
             ORDER BY $orderBy LIMIT :lim OFFSET :off",
            ['lim' => $perPage, 'off' => $offset]
        );
    }
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$editProduct = null;
// Q1 (DECIDED 2026-05-23): compound_name editor uses a dropdown of imported
// names. Candidates collected here, rendered as a <datalist> in renderFormField.
$GLOBALS['compoundNameCandidates'] = [];
if ($action === 'edit' && isset($_GET['id'])) {
    // Full compound + all listings for admin edit view
    $editProduct = $db->fetchOne("SELECT * FROM compounds WHERE id = :id", ['id' => intval($_GET['id'])]);
    if ($editProduct) {
        $editProduct['product_name'] = $editProduct['compound_name']; // backward compat
        $editProduct['listings']     = $db->fetchAll(
            "SELECT sl.*, s.supplier_name AS company_make
             FROM supplier_listings sl
             JOIN suppliers s ON s.id = sl.supplier_id
             WHERE sl.compound_id = :id ORDER BY sl.supplier_id, sl.purity",
            ['id' => $editProduct['id']]
        );
        // Expose primary listing fields for existing form fields
        if (!empty($editProduct['listings'])) {
            $pl = $editProduct['listings'][0];
            foreach (['purity','availability','stock_status','lead_time','min_order_qty',
                      'unit','catalog_number','company_make','lot_number',
                      'manufacture_date','expiry_date','supplier_id'] as $f) {
                $editProduct[$f] = $pl[$f] ?? null;
            }
            $editProduct['listing_id'] = $pl['id'];
        }

        // Build name candidate list: canonical + supplier-supplied + top synonyms
        $cands = [];
        $cands[] = ['name' => $editProduct['compound_name'] ?? '', 'src' => 'current'];
        foreach ($editProduct['listings'] ?? [] as $l) {
            $sn = trim((string)($l['supplier_product_name'] ?? ''));
            if ($sn !== '' && $sn !== ($editProduct['compound_name'] ?? '')) {
                $cands[] = ['name' => $sn, 'src' => 'supplier: ' . ($l['company_make'] ?? '?')];
            }
        }
        $synonymsRaw = $editProduct['synonyms'] ?? '';
        if ($synonymsRaw !== '' && $synonymsRaw !== 'NA') {
            foreach (array_slice(explode('|', $synonymsRaw), 0, 20) as $syn) {
                $syn = trim($syn);
                if ($syn !== '' && strlen($syn) > 1 && strlen($syn) < 200) {
                    $cands[] = ['name' => $syn, 'src' => 'pubchem'];
                }
            }
        }
        // Dedupe (first occurrence wins so canonical + supplier stay on top)
        $seen = [];
        foreach ($cands as $c) {
            $key = strtolower($c['name']);
            if (!isset($seen[$key]) && $c['name'] !== '') {
                $seen[$key] = true;
                $GLOBALS['compoundNameCandidates'][] = $c;
            }
        }
    } else {
        $error = "Compound not found."; $action = 'list';
    }

    // Auto-generate canonical SMILES if SMILES is present but canonical is empty
    if ($editProduct && !empty($editProduct['smiles']) && $editProduct['smiles'] !== 'NA') {
        if (empty($editProduct['smiles_canonical']) || $editProduct['smiles_canonical'] === 'NA') {
            $cs = generateCanonicalSmiles($editProduct['smiles']);
            if ($cs) $editProduct['smiles_canonical'] = $cs;
        }
    }
}

// ── Dashboard stats ────────────────────────────────────────────────────────
$totalProducts      = $db->fetchValue("SELECT COUNT(*) FROM compounds");
$productsWithCas    = $db->fetchValue("SELECT COUNT(*) FROM compounds WHERE cas_number IS NOT NULL AND cas_number NOT IN ('','NA')");
$productsWithSmiles = $db->fetchValue("SELECT COUNT(*) FROM compounds WHERE (smiles IS NOT NULL AND smiles NOT IN ('','NA')) OR (smiles_canonical IS NOT NULL AND smiles_canonical NOT IN ('','NA'))");
$productsWithImage  = $db->fetchValue("SELECT COUNT(*) FROM compounds WHERE image_url IS NOT NULL AND image_url NOT IN ('','NA')");
$totalListings      = $db->fetchValue("SELECT COUNT(*) FROM supplier_listings WHERE status = 'Active'");
$totalSuppliers     = $db->fetchValue("SELECT COUNT(*) FROM suppliers WHERE is_active = 1");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Product Management | AB Chem Admin</title>
<link rel="stylesheet" href="/styles.css">
<style>
/* ── Image Fetch Panel ──────────────────────────────────────────────────── */
.image-fetch-panel {
    border: 2px dashed var(--border);
    border-radius: 10px;
    padding: 20px 24px;
    margin: 20px 0;
    background: #f8fafc;
    transition: border-color .2s;
}
.image-fetch-panel:focus-within { border-color: var(--accent); background: #eff6ff; }
.image-fetch-panel h4 { margin: 0 0 6px 0; font-size: 1rem; color: var(--primary); }
.image-fetch-panel p  { margin: 0 0 14px 0; font-size: .85rem; color: var(--muted); }
.image-fetch-row { display: flex; gap: 10px; align-items: stretch; flex-wrap: wrap; }
.image-fetch-row input[type="url"] {
    flex: 1; min-width: 260px;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: .9rem;
    outline: none;
}
.image-fetch-row input[type="url"]:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(2,132,199,.15); }
.btn-fetch-img {
    background: #0369a1; color: white; border: none;
    padding: 10px 18px; border-radius: 8px; cursor: pointer;
    font-size: .9rem; font-weight: 500; white-space: nowrap;
    display: inline-flex; align-items: center; gap: 6px;
}
.btn-fetch-img:hover   { background: #0284c7; }
.btn-fetch-img:disabled { background: #94a3b8; cursor: not-allowed; }
.fetch-status {
    margin-top: 12px; padding: 10px 14px;
    border-radius: 8px; font-size: .875rem; display: none;
}
.fetch-status.ok  { background: #dcfce7; color: #166534; display: block; }
.fetch-status.err { background: #fee2e2; color: #991b1b; display: block; }
.fetch-status.loading { background: #eff6ff; color: #1d4ed8; display: block; }
.fetched-preview { display: flex; align-items: center; gap: 14px; margin-top: 10px; }
.fetched-preview img { width: 72px; height: 72px; object-fit: contain; border: 1px solid #e2e8f0; border-radius: 8px; background: white; padding: 4px; }
/* ── PubChem preview modal overlay ─────────────────────────────────────── */
.pubchem-preview-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 1000;
    align-items: center; justify-content: center;
}
.pubchem-preview-overlay.open { display: flex; }
.pubchem-preview-modal {
    background: white; border-radius: 14px;
    width: 90%; max-width: 780px; max-height: 85vh;
    overflow-y: auto; padding: 28px 32px;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.pubchem-preview-modal h3 { margin: 0 0 16px 0; color: #1d4ed8; }
.pubchem-field-list { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; margin-bottom: 20px; }
.pubchem-field-item { font-size: .85rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; }
.pubchem-field-item strong { display: block; color: #64748b; font-size: .75rem; text-transform: uppercase; }
.pubchem-field-item span { color: #1e293b; word-break: break-word; }
.modal-actions { display: flex; gap: 12px; margin-top: 20px; }
/* ── Force overwrite toggle ─────────────────────────────────────────────── */
.overwrite-toggle {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: .85rem; color: #64748b; cursor: pointer;
    padding: 6px 12px; border-radius: 20px;
    border: 1px solid #e2e8f0; background: white;
    transition: all .2s;
}
.overwrite-toggle:has(input:checked) { background: #fef3c7; border-color: #d97706; color: #92400e; }
/* ── diff panel ─────────────────────────────────────────────────────────── */
.pubchem-diff-panel { background: #f0fdf4; border: 1px solid #86efac; border-left: 5px solid #16a34a; border-radius: 10px; padding: 20px 24px; margin-bottom: 24px; }
.diff-header { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
.diff-icon { font-size: 1.8rem; }
.diff-count { display: inline-block; background: #16a34a; color: white; font-size: .75rem; font-weight: 600; padding: 2px 10px; border-radius: 20px; margin-left: 10px; }
.diff-forced-note { background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; padding: 8px 12px; font-size: .82rem; color: #92400e; margin-bottom: 12px; }
.diff-table { width: 100%; border-collapse: collapse; font-size: .875rem; background: white; border-radius: 8px; overflow: hidden; }
.diff-table th { background: #0f172a; color: white; padding: 10px 14px; text-align: left; font-size: .8rem; }
.diff-table td { padding: 9px 14px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
.diff-table code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: .8rem; }
.diff-row-new td { background: #f0fdf4; }
.diff-row-forced td { background: #fffbeb; }
.diff-row-kept td { background: #fafafa; }
.diff-before { color: #94a3b8; font-style: italic; max-width: 180px; word-break: break-all; }
.diff-after  { color: #166534; font-weight: 500; max-width: 240px; word-break: break-all; }
.diff-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: .73rem; font-weight: 600; }
.diff-badge-new    { background: #dcfce7; color: #166534; }
.diff-badge-forced { background: #fef3c7; color: #92400e; }
.diff-badge-kept   { background: #f1f5f9; color: #64748b; }
.diff-note { margin-top: 10px; font-size: .8rem; color: #64748b; }
/* ── Responsive stats row ────────────────────────────────────────────────── */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.stat-box { padding: 14px 12px; min-width: 0; }
.stat-number { font-size: 1.55rem; }
@media (max-width: 640px) {
    .stats-row { grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .stat-box   { padding: 10px 8px; }
    .stat-number { font-size: 1.25rem; }
    .stat-desc  { font-size: .7rem; }
}
@media (max-width: 400px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
}
/* ── Admin form grid — multi-column layout ───────────────────────────────── */
.form-card .form-grid,
.form-section .form-grid,
.upload-card .form-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px 20px;
}
/* Full-width span helpers */
.form-grid .form-group.span-full { grid-column: 1 / -1; }
.form-grid .form-group.span-2    { grid-column: span 2; }
@media (max-width: 900px) {
    .form-card .form-grid,
    .form-section .form-grid { grid-template-columns: repeat(2, 1fr); }
    .form-grid .form-group.span-2 { grid-column: 1 / -1; }
}
@media (max-width: 560px) {
    .form-card .form-grid,
    .form-section .form-grid { grid-template-columns: 1fr; }
    .form-grid .form-group.span-full,
    .form-grid .form-group.span-2 { grid-column: 1; }
}
/* ── Collapsible form sections ───────────────────────────────────────────── */
.form-section {
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-bottom: 14px;
    overflow: hidden;
}
.form-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 11px 18px;
    background: #f8fafc;
    cursor: pointer;
    user-select: none;
    transition: background .15s;
    gap: 12px;
}
.form-section-header:hover { background: #eff6ff; }
.form-section-header h4 {
    margin: 0;
    font-size: .9rem;
    font-weight: 600;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 7px;
    flex: 1;
}
.section-badge {
    display: inline-block;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: .7rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
    white-space: nowrap;
}
.section-badge.badge-green { background: #dcfce7; color: #166534; }
.section-badge.badge-amber { background: #fef3c7; color: #92400e; }
.section-body { padding: 16px 18px 18px; }
/* !important beats any inherited display:grid from .form-grid */
.section-body.collapsed { display: none !important; }
.toggle-icon {
    font-size: .72rem;
    color: var(--muted);
    transition: transform .2s ease;
    flex-shrink: 0;
}
/* ── pubchem-complete banner ─────────────────────────────────────────────── */
.pubchem-complete { background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 10px 16px; margin-bottom: 18px; color: #166534; font-size: .9rem; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.btn-pubchem { background: #1d4ed8; color: white; border: 2px solid #1d4ed8; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 500; font-size: .9rem; transition: background .2s; }
.btn-pubchem:hover { background: #1e40af; border-color: #1e40af; }
</style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="admin-products-container">

    <div class="page-header">
        <h1>📦 Product Management</h1>
        <a href="/admin" class="btn btn-outline" style="text-decoration:none;">← Back to Admin</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success">✅ <?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?= e($error) ?></div>
    <?php endif; ?>

    <!-- ── PubChem Diff Panel ──────────────────────────────────────────── -->
    <?php if ($pubchemDiff && !empty($pubchemDiff['fields'])): ?>
    <div class="pubchem-diff-panel">
        <div class="diff-header">
            <span class="diff-icon">⚗️</span>
            <div>
                <strong>PubChem Fetch Result — <?= e($pubchemDiff['product']) ?></strong>
                <span class="diff-count"><?= count($pubchemDiff['fields']) ?> field<?= count($pubchemDiff['fields']) !== 1 ? 's' : '' ?> processed</span>
            </div>
        </div>
        <?php if (!empty($pubchemDiff['forced'])): ?>
        <div class="diff-forced-note">⚠️ <strong>Force overwrite mode was active</strong> — PubChem data replaced existing chemical field values.</div>
        <?php endif; ?>
        <table class="diff-table">
            <thead><tr><th>Field</th><th>Status</th><th>Previous Value</th><th>Saved Value</th></tr></thead>
            <tbody>
            <?php foreach ($pubchemDiff['fields'] as $field => $d): ?>
                <?php
                // 'applied' tells us whether the value actually reached the DB.
                // When false, the badge must NOT say "Filled" — that would lie.
                $applied  = $d['applied'] ?? true;
                if (!$applied) {
                    $rowClass = 'diff-row-kept';
                    $badge    = '<span class="diff-badge diff-badge-kept" style="background:#fee2e2;color:#991b1b;">✗ Not applied</span>';
                } elseif ($d['filled']) {
                    $rowClass = 'diff-row-new';
                    $badge    = '<span class="diff-badge diff-badge-new">✚ Filled</span>';
                } elseif ($d['forced']) {
                    $rowClass = 'diff-row-forced';
                    $badge    = '<span class="diff-badge diff-badge-forced">⚡ Overwritten</span>';
                } else {
                    $rowClass = 'diff-row-kept';
                    $badge    = '<span class="diff-badge diff-badge-kept">— Kept yours</span>';
                }
                ?>
                <tr class="<?= $rowClass ?>">
                    <td><code><?= e(str_replace('_', ' ', $field)) ?></code></td>
                    <td><?= $badge ?></td>
                    <td class="diff-before"><?= e(mb_substr($d['before'], 0, 80)) ?><?= mb_strlen($d['before']) > 80 ? '…' : '' ?></td>
                    <td class="diff-after"><?= e(mb_substr($d['after'], 0, 80)) ?><?= mb_strlen($d['after']) > 80 ? '…' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="diff-note">
            <strong>✚ Filled</strong> = was empty, now populated. &nbsp;
            <strong>⚡ Overwritten</strong> = existing value replaced by PubChem. &nbsp;
            <strong>— Kept yours</strong> = your value was kept (PubChem data not applied). &nbsp;
            <strong style="color:#991b1b;">✗ Not applied</strong> = PubChem returned data but it was dropped during save (form value won).
        </p>
    </div>
    <?php endif; ?>

    <!-- ── UNIQUE-constraint conflict warning ───────────────────────────────── -->
    <?php if (!empty($compoundConflicts)): ?>
    <div style="background:#fef3c7; border-left:4px solid #f59e0b; padding:14px 18px; border-radius:0 8px 8px 0; margin-bottom:18px;">
        <h3 style="margin:0 0 8px 0; color:#92400e;">⚠️ Duplicate detected — some fields were not saved</h3>
        <p style="margin:0 0 10px 0; font-size:14px; color:#78350f;">
            The value(s) below already belong to a different compound. The save proceeded with the rest of your data;
            <strong>only the conflicting field(s) were skipped</strong> to avoid violating the UNIQUE constraint.
            Use the deduplication tool to merge the duplicate compounds.
        </p>
        <table style="width:100%; border-collapse:collapse; font-size:13px; background:white; border-radius:6px; overflow:hidden;">
            <thead><tr style="background:#fde68a;">
                <th style="padding:8px;text-align:left;">Field skipped</th>
                <th style="padding:8px;text-align:left;">Value</th>
                <th style="padding:8px;text-align:left;">Already in compound</th>
            </tr></thead>
            <tbody>
            <?php foreach ($compoundConflicts as $cf): ?>
                <tr style="border-bottom:1px solid #fde68a;">
                    <td style="padding:8px;"><code><?= e($cf['field']) ?></code></td>
                    <td style="padding:8px;"><code style="font-family:'JetBrains Mono',monospace;"><?= e($cf['value']) ?></code></td>
                    <td style="padding:8px;">
                        <a href="?action=edit&id=<?= (int)$cf['other_id'] ?>">
                            #<?= (int)$cf['other_id'] ?> <?= e($cf['other_name']) ?>
                        </a>
                        <code style="color:#78350f;"><?= e($cf['other_catalog']) ?></code>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin:10px 0 0 0;">
            <a href="/admin_dedup.php?criteria%5B%5D=inchi_key&criteria%5B%5D=cas"
               style="background:#92400e; color:white; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:13px; font-weight:500;">
                🧬 Open Dedup Tool (InChIKey + CAS scan)
            </a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-box"><div class="stat-number"><?= $totalProducts ?></div><div class="stat-desc">Unique Compounds</div></div>
        <div class="stat-box"><div class="stat-number"><?= $totalListings ?></div><div class="stat-desc">Supplier Listings</div></div>
        <div class="stat-box"><div class="stat-number"><?= $totalSuppliers ?></div><div class="stat-desc">Active Suppliers</div></div>
        <div class="stat-box"><div class="stat-number"><?= $productsWithCas ?></div><div class="stat-desc">With CAS Number</div></div>
        <div class="stat-box"><div class="stat-number"><?= $productsWithSmiles ?></div><div class="stat-desc">With SMILES</div></div>
        <div class="stat-box"><div class="stat-number"><?= $productsWithImage ?></div><div class="stat-desc">With Images</div></div>
    </div>

    <!-- Tabs -->
    <div class="nav-tabs">
        <a href="?action=list"      class="tab-link <?= $action === 'list'      ? 'active' : '' ?>">📋 Product List</a>
        <a href="?action=add"       class="tab-link <?= $action === 'add'       ? 'active' : '' ?>">➕ Add New</a>
        <a href="?action=bulk"      class="tab-link <?= $action === 'bulk'      ? 'active' : '' ?>">📤 Bulk CSV</a>
        <a href="?action=import"    class="tab-link <?= $action === 'import'    ? 'active' : '' ?>">📦 Supplier Import</a>
        <a href="?action=stereo"    class="tab-link <?= $action === 'stereo'    ? 'active' : '' ?>">🔬 Stereo Review</a>
        <a href="admin_listings.php"  class="tab-link">📋 All Listings</a>
        <a href="admin_suppliers.php" class="tab-link">🏭 Suppliers</a>
    </div>

    <!-- ==================== PRODUCT LIST ==================== -->
    <?php if ($action === 'list'): ?>
    <form method="get" class="search-bar">
        <input type="hidden" name="action" value="list">
        <input type="text" name="search" placeholder="Search product name or CAS..." value="<?= e($search) ?>">
        <button type="submit" class="btn btn-primary">🔍 Search</button>
        <?php if ($search): ?>
            <a href="?action=list" class="btn btn-outline" style="text-decoration:none;">✕ Clear</a>
        <?php endif; ?>
    </form>

    <div class="product-table-wrapper">
        <?php
        // Helper to build a sortable column header. Preserves search/page params,
        // toggles direction on repeat clicks, and shows an arrow on the active column.
        $sortHeader = function(string $label, string $key) use ($sortKey, $sortDir, $search, $currentPage): string {
            $nextDir = ($key === $sortKey && $sortDir === 'ASC') ? 'desc' : 'asc';
            $arrow   = $key === $sortKey ? ($sortDir === 'ASC' ? ' ▲' : ' ▼') : '';
            $params  = ['action' => 'list', 'sort' => $key, 'dir' => $nextDir];
            if ($search) $params['search'] = $search;
            if ($currentPage > 1) $params['page'] = $currentPage;
            $href = '?' . http_build_query($params);
            $style = $key === $sortKey ? 'color:#0e7abf;font-weight:700;' : '';
            return '<a href="' . htmlspecialchars($href) . '" style="text-decoration:none;color:inherit;' . $style . '">' . htmlspecialchars($label) . $arrow . '</a>';
        };
        ?>
        <table class="product-table">
            <thead><tr>
                <th><?= $sortHeader('ID', 'id') ?></th>
                <th>Image</th>
                <th><?= $sortHeader('Product Name', 'product_name') ?></th>
                <th><?= $sortHeader('CAS', 'cas') ?></th>
                <th><?= $sortHeader('Type', 'type') ?></th>
                <th><?= $sortHeader('Purity', 'purity') ?></th>
                <th><?= $sortHeader('Status', 'status') ?></th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if (empty($products)): ?>
                <tr><td colspan="8" class="no-results"><p>No products found.</p>
                    <a href="?action=add" class="btn btn-primary" style="margin-top:12px;">➕ Add First Product</a></td></tr>
            <?php else: foreach ($products as $p): ?>
                <tr>
                    <td><strong>#<?= $p['id'] ?></strong></td>
                    <td>
                        <?php if (!empty($p['image_url']) && $p['image_url'] !== 'NA'): ?>
                            <img src="<?= e($p['image_url']) ?>" alt="" class="mini-img" onerror="this.style.display='none';this.nextElementSibling.style.display='inline'">
                            <span style="display:none;font-size:1.2rem">🧪</span>
                        <?php else: ?><span style="font-size:1.2rem">🧪</span><?php endif; ?>
                    </td>
                    <td><strong><?= e(mb_substr($p['product_name'] ?? '', 0, 60)) ?></strong></td>
                    <td><code style="font-size:.8rem"><?= e($p['cas_number'] ?? '—') ?></code></td>
                    <td><?= e($p['product_type'] ?? '—') ?></td>
                    <td><?= e($p['purity'] ?? '—') ?></td>
                    <td><span class="status-badge status-<?= strtolower($p['status'] ?? 'active') ?>"><?= e($p['status'] ?? 'Active') ?></span></td>
                    <td>
                        <div class="action-btns">
                            <a href="?action=edit&id=<?= $p['id'] ?>" class="action-btn">✏️ Edit</a>
                            <a href="?action=delete&id=<?= $p['id'] ?>" class="action-btn delete-btn" onclick="return confirm('Delete this product?')">🗑️ Del</a>
                            <a href="/product/<?= urlencode($p['slug'] ?? '') ?>" target="_blank" class="action-btn">👁️ View</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <!-- ── Pagination (Item 13) ── -->
        <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Product pages">
            <?php
            // Preserve sort + search across pagination links
            $pgParams = ['action' => 'list'];
            if ($search)              $pgParams['search'] = $search;
            if ($sortKey !== 'product_name') $pgParams['sort'] = $sortKey;
            if ($sortDir !== 'ASC')   $pgParams['dir']  = strtolower($sortDir);
            $baseUrl = '?' . http_build_query($pgParams);
            $prev    = $currentPage > 1 ? $currentPage - 1 : null;
            $next    = $currentPage < $totalPages ? $currentPage + 1 : null;
            ?>
            <a href="<?= $prev ? $baseUrl . '&page=' . $prev : '#' ?>"
               class="<?= $prev ? '' : 'page-disabled' ?>">&laquo; Prev</a>

            <?php
            $start = max(1, $currentPage - 3);
            $end   = min($totalPages, $currentPage + 3);
            if ($start > 1)         echo '<span>…</span>';
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="<?= $baseUrl . '&page=' . $i ?>"
                   class="<?= $i === $currentPage ? 'page-active' : '' ?>"><?= $i ?></a>
            <?php endfor;
            if ($end < $totalPages) echo '<span>…</span>';
            ?>

            <a href="<?= $next ? $baseUrl . '&page=' . $next : '#' ?>"
               class="<?= $next ? '' : 'page-disabled' ?>">Next &raquo;</a>

            <span style="margin-left:8px; font-size:0.82rem; color:var(--muted);">
                Page <?= $currentPage ?> of <?= $totalPages ?>
                (<?= number_format($totalRows) ?> products)
            </span>
        </nav>
        <?php endif; ?>
    </div>

    <!-- ==================== ADD / EDIT ==================== -->
    <?php elseif ($action === 'add' || ($action === 'edit' && $editProduct)):
        $isEdit    = ($action === 'edit');
        $product   = $isEdit ? $editProduct : [];
        $pageTitle = $isEdit ? 'Edit: ' . e($product['product_name']) : 'Add New Product';
        $prodId    = $isEdit ? (int)$product['id'] : 0;
        $prodSlug  = $product['slug'] ?? '';

        $missingChemFields = [];
        if ($isEdit) {
            foreach ($chemicalFields as $f) {
                if (empty($product[$f]) || $product[$f] === 'NA') $missingChemFields[] = str_replace('_', ' ', $f);
            }
        }
    ?>

    <div class="form-card">
        <h2><?= $pageTitle ?></h2>
        <p class="form-subtitle">Fields marked <span style="color:#ef4444">*</span> are required.</p>

        <!-- PubChem Status Banner -->
        <?php if ($isEdit && !empty($missingChemFields)): ?>
        <div class="pubchem-alert">
            <span class="alert-title">⚗️ Missing Chemical Data</span>
            <div class="missing-tags">
                <?php foreach ($missingChemFields as $mf): ?>
                <span class="missing-tag"><?= e($mf) ?></span>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:8px;">
                <button type="button" class="btn btn-primary" style="background:#1d4ed8;" onclick="openPubChemPreview()">
                    👁️ Preview PubChem Data
                </button>
                <button type="submit" name="fetch_pubchem" value="1" form="product-form" class="btn-pubchem">
                    ⚗️ Fetch Missing Fields
                </button>
            </div>
            <span class="alert-hint" style="margin-top:6px;display:block;">Search order: IUPAC → InChIKey → CAS → CID → Product Name</span>
        </div>
        <?php elseif ($isEdit): ?>
        <div class="pubchem-complete">
            ✅ All chemical data fields are populated.
            <button type="button" class="btn btn-outline" style="font-size:.8rem;padding:4px 12px;" onclick="openPubChemPreview()">👁️ Preview PubChem</button>
            <button type="submit" name="fetch_pubchem" value="1" form="product-form" class="btn btn-outline" style="font-size:.8rem;padding:4px 12px;">↻ Re-fetch</button>
        </div>
        <?php endif; ?>

        <!-- Main product form -->
        <form method="post" id="product-form">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'add' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $prodId ?>"><?php endif; ?>
            <input type="hidden" name="fetch_pubchem" id="fetch_pubchem_flag" value="0">
            <input type="hidden" name="force_pubchem" id="force_pubchem_flag" value="0">

            <?php
            // ── Field groups for collapsible sections ───────────────────────
            $grpBasic    = ['compound_name','cas_number','product_type','status',
                            'parent_drug','storage_condition'];
            $grpChem     = ['iupac_name','molecular_formula','molecular_weight',
                            'smiles','smiles_canonical','inchi','inchi_key',
                            'pubchem_cid','synonyms','image_url'];
            $grpSeo      = ['therapeutic_category','regulatory_ref','hazard_class',
                            'meta_title','meta_description','description','applications','keywords'];
            $allGrouped  = array_merge($grpBasic, $grpChem, $grpSeo);

            // Count how many chem fields are empty (for badge)
            $missingCount = 0;
            if ($isEdit) {
                foreach ($grpChem as $f) {
                    if (empty($product[$f]) || $product[$f] === 'NA') $missingCount++;
                }
            }
            $chemBadge      = $isEdit
                ? ($missingCount > 0 ? "$missingCount missing" : 'Complete ✓')
                : 'PubChem auto-fill';
            $chemBadgeClass = $isEdit && $missingCount === 0 ? 'badge-green' : '';

            $sections = [
                ['id'=>'sec-basic', 'icon'=>'📋', 'title'=>'Basic Information',
                 'badge'=>'', 'bclass'=>'', 'collapsed'=>false, 'fields'=>$grpBasic],
                ['id'=>'sec-chem',  'icon'=>'⚗️', 'title'=>'Chemical Data',
                 'badge'=>$chemBadge, 'bclass'=>$chemBadgeClass, 'collapsed'=>false, 'fields'=>$grpChem],
                ['id'=>'sec-seo',   'icon'=>'🏷️', 'title'=>'Classification & SEO',
                 'badge'=>'', 'bclass'=>'', 'collapsed'=>true, 'fields'=>$grpSeo],
            ];
            ?>

            <?php foreach ($sections as $sec): ?>
            <div class="form-section">
                <div class="form-section-header" onclick="toggleSectionFallback('<?= $sec['id'] ?>')">
                    <h4>
                        <?= $sec['icon'] ?> <?= $sec['title'] ?>
                        <?php if ($sec['badge']): ?>
                        <span class="section-badge <?= $sec['bclass'] ?>"><?= e($sec['badge']) ?></span>
                        <?php endif; ?>
                    </h4>
                    <span class="toggle-icon" id="toggle-<?= $sec['id'] ?>"
                          <?= $sec['collapsed'] ? 'style="transform:rotate(-90deg)"' : '' ?>>▼</span>
                </div>
                <div class="form-grid section-body <?= $sec['collapsed'] ? 'collapsed' : '' ?>"
                     id="<?= $sec['id'] ?>">
                    <?php foreach ($sec['fields'] as $col):
                        if (in_array($col, $skipFields)) continue;
                        $value = $isEdit ? ($product[$col] ?? '') : '';
                        echo renderFormField($col, $value, $columnDefaults);
                    endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php
            // ── Stereochemistry section (edit only, requires stereo columns in DB) ─────
            $hasStereoCols = in_array('stereo_status', $allColumns);
            if ($isEdit && $hasStereoCols):
                $stereoStatus = $product['stereo_status'] ?? null;
                $stereoSource = $product['stereo_source'] ?? '';
                $smilesStero  = $product['smiles_stereo'] ?? '';
                $statusColors = [
                    'achiral'       => ['bg'=>'#dcfce7','color'=>'#166534','label'=>'Achiral ✓'],
                    'verified'      => ['bg'=>'#dbeafe','color'=>'#1e40af','label'=>'Verified ✓'],
                    'unverified'    => ['bg'=>'#fef3c7','color'=>'#92400e','label'=>'Unverified ⚠'],
                    'manual_review' => ['bg'=>'#fee2e2','color'=>'#991b1b','label'=>'Needs Review ✗'],
                    ''              => ['bg'=>'#f1f5f9','color'=>'#475569','label'=>'Unchecked'],
                ];
                $sc = $statusColors[$stereoStatus ?? ''] ?? $statusColors[''];
                $stereoBadge = "<span style='display:inline-block;padding:2px 10px;border-radius:12px;font-size:.78rem;font-weight:600;background:{$sc['bg']};color:{$sc['color']}'>{$sc['label']}</span>";
            ?>
            <div class="form-section">
                <div class="form-section-header" onclick="toggleSectionFallback('sec-stereo')">
                    <h4>🔬 Stereochemistry <?= $stereoBadge ?></h4>
                    <span class="toggle-icon" id="toggle-sec-stereo" style="transform:rotate(-90deg)">▼</span>
                </div>
                <div class="form-grid section-body collapsed" id="sec-stereo">
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Stereo Status</label>
                        <select name="stereo_status" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.9rem;width:100%;max-width:260px">
                            <option value="">— not checked —</option>
                            <option value="achiral"       <?= $stereoStatus==='achiral'       ? 'selected' : '' ?>>Achiral (no stereocenters)</option>
                            <option value="unverified"    <?= $stereoStatus==='unverified'    ? 'selected' : '' ?>>Unverified (has stereocenters, unchecked)</option>
                            <option value="verified"      <?= $stereoStatus==='verified'      ? 'selected' : '' ?>>Verified (stereo confirmed by external source)</option>
                            <option value="manual_review" <?= $stereoStatus==='manual_review' ? 'selected' : '' ?>>Manual Review needed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Stereo Source</label>
                        <input type="text" name="stereo_source" value="<?= e($stereoSource) ?>" placeholder="gsrs / chembl / manual" style="font-family:monospace">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Stereospecific SMILES
                            <span style="font-size:.75rem;color:#64748b;font-weight:400"> — from authoritative source (kept separate from supplier SMILES above)</span>
                        </label>
                        <textarea name="smiles_stereo" rows="3" style="font-family:monospace;font-size:.82rem"><?= e($smilesStero) ?></textarea>
                    </div>
                    <div class="form-group" style="grid-column:1/-1;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <button type="button" class="btn btn-outline" onclick="runStereoCheck(<?= $prodId ?>)">
                            🔬 Run RDKit Stereo Check
                        </button>
                        <button type="button" class="btn btn-outline" onclick="fetchStereoExternal(<?= $prodId ?>)">
                            🌐 Fetch from GSRS / ChEMBL
                        </button>
                        <span id="stereo-ajax-msg" style="font-size:.83rem;color:#64748b"></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // Any column from the DB not covered by any section (future-proofing)
            $orphans = array_filter($allColumns, fn($c) => !in_array($c, $skipFields) && !in_array($c, $allGrouped));
            if (!empty($orphans)):
            ?>
            <div class="form-grid" style="margin-bottom:14px;">
                <?php foreach ($orphans as $col):
                    $value = $isEdit ? ($product[$col] ?? '') : '';
                    echo renderFormField($col, $value, $columnDefaults);
                endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ── Supplier Listing section (add form only; edit uses the panel below) ── -->
            <?php if (!$isEdit): ?>
            <?php $suppliersForAdd = $db->fetchAll("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name"); ?>
            <div class="form-section" id="sec-supplier-wrap">
                <div class="form-section-header" onclick="toggleSectionFallback('sec-supplier')">
                    <h4>🏭 Supplier Listing <span style="color:#ef4444;font-weight:normal;font-size:.82rem;">— required</span></h4>
                    <span class="toggle-icon" id="toggle-sec-supplier">▼</span>
                </div>
                <div class="section-body" id="sec-supplier">
                    <p style="color:var(--muted);margin:0 0 16px 0;font-size:.85rem;">
                        Specify supplier and availability. Additional listings can be added after saving.
                    </p>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Supplier <span style="color:#ef4444">*</span></label>
                            <select name="supplier_id" id="field_supplier_id" class="form-select">
                                <option value="">-- Select Supplier --</option>
                                <?php foreach ($suppliersForAdd as $sup): ?>
                                <option value="<?= $sup['id'] ?>"><?= e($sup['supplier_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php foreach (['purity','purity_by_method','availability','stock_status',
                                        'min_order_qty','unit','lead_time','lot_number',
                                        'manufacture_date','expiry_date'] as $lf):
                            echo renderFormField($lf, $product[$lf] ?? '', $columnDefaults);
                        endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Image Preview (live update) -->
            <div class="image-preview-box">
                <strong style="min-width:80px;">Current Image:</strong>
                <?php
                $imgSrc = (!empty($product['image_url']) && $product['image_url'] !== 'NA')
                    ? $product['image_url'] : '/logo.png';
                ?>
                <img src="<?= e($imgSrc) ?>" alt="Product Image" id="img-preview"
                     style="width:120px;height:120px;object-fit:contain;border:1px solid #e2e8f0;border-radius:8px;background:white;padding:4px;"
                     onerror="this.src='/logo.png'">
                <div class="image-preview-info">
                    <small>Updates live as you type in the Image URL field above. Or use the fetch tool below.</small>
                </div>
            </div>

            <!-- Form Action Buttons -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" onclick="setFlags(0,0)">💾 Save Product</button>

                <button type="submit" class="btn-pubchem" onclick="setFlags(1,0)" title="Saves the form, then fills any EMPTY chemical fields from PubChem">
                    ⚗️ Save + Fill Missing from PubChem
                </button>

                <?php if ($isEdit): ?>
                <label class="overwrite-toggle" title="When checked, PubChem data will overwrite existing chemical values too">
                    <input type="checkbox" id="force_overwrite_cb" style="accent-color:#d97706">
                    ⚡ Force overwrite existing fields
                </label>
                <button type="submit" class="btn btn-outline" style="border-color:#d97706;color:#d97706;" onclick="return handleForceSubmit()">
                    ↻ Force Re-fetch PubChem
                </button>
                <?php endif; ?>

                <a href="?action=list" class="btn btn-outline" style="text-decoration:none;">✕ Cancel</a>
                <?php if ($isEdit): ?>
                <a href="/product/<?= urlencode($prodSlug) ?>" target="_blank" class="btn btn-outline" style="text-decoration:none;">👁️ View Live</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- ── IMAGE FETCH PANEL ─────────────────────────────────────────────
             IMPORTANT: This is a SEPARATE panel, NOT inside product-form.
             It uses JavaScript fetch() to call the AJAX endpoint, so no nested
             <form> tags, no broken HTML, no page reload needed.
        ──────────────────────────────────────────────────────────────────── -->
        <div class="image-fetch-panel">
            <h4>🖼️ Fetch Image from External URL</h4>
            <p>Paste a public HTTPS image URL below. The image will be downloaded, validated, saved to <code>/compound_images/</code>, and the Image URL field will be updated automatically.</p>
            <div class="image-fetch-row">
                <input type="url" id="ext_image_url" placeholder="https://example.com/compound.png"
                       autocomplete="off" spellcheck="false">
                <button type="button" class="btn-fetch-img" id="btn_fetch_image"
                        onclick="fetchExternalImage()">
                    📥 Fetch &amp; Save Image
                </button>
            </div>
            <div class="fetch-status" id="fetch_status"></div>
            <div class="fetched-preview" id="fetch_preview" style="display:none">
                <img id="fetch_preview_img" src="" alt="Fetched image">
                <div id="fetch_preview_info" style="font-size:.85rem;"></div>
            </div>
        </div>
    </div>

    <?php if ($isEdit && $prodId): ?>
    <!-- ==================== SUPPLIER LISTINGS PANEL ==================== -->
    <div class="form-card" id="listings-panel">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
            <div>
                <h2 style="margin:0 0 4px 0;">🏭 Supplier Listings</h2>
                <p style="color:var(--muted);margin:0;font-size:.875rem;">
                    All supplier-specific records for this compound.
                </p>
            </div>
            <button type="button" class="btn btn-primary" onclick="openListingModal(0, <?= $prodId ?>)">
                ➕ Add Listing
            </button>
        </div>

        <div id="listings-table-container">
        <?php if (!empty($editProduct['listings'])): ?>
        <div style="overflow-x:auto;">
        <table class="product-table" id="listings-table">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Catalog #</th>
                    <th>Purity</th>
                    <th>Method</th>
                    <th>Availability</th>
                    <th>Stock</th>
                    <th>MOQ</th>
                    <th>Lead Time</th>
                    <th>Lot #</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="listings-tbody">
            <?php foreach ($editProduct['listings'] as $lst): ?>
                <tr id="listing-row-<?= $lst['id'] ?>">
                    <td><strong><?= e($lst['company_make'] ?? '—') ?></strong></td>
                    <td><code style="font-size:.78rem"><?= e($lst['catalog_number'] ?? '—') ?></code></td>
                    <td><?= e($lst['purity'] ?? '—') ?></td>
                    <td style="font-size:.8rem;color:var(--muted)"><?= e($lst['purity_by_method'] ?? '—') ?></td>
                    <td>
                        <?php $avail = strtolower(str_replace(' ', '-', $lst['availability'] ?? 'inactive')); ?>
                        <span class="status-badge status-<?= $avail ?>"><?= e($lst['availability'] ?? '—') ?></span>
                    </td>
                    <td style="font-size:.8rem"><?= e(str_replace('_', ' ', ucfirst($lst['stock_status'] ?? '—'))) ?></td>
                    <td style="font-size:.8rem"><?= e($lst['min_order_qty'] ?? '—') ?> <?= e($lst['unit'] ?? '') ?></td>
                    <td style="font-size:.8rem"><?= e($lst['lead_time'] ?? '—') ?></td>
                    <td style="font-size:.8rem"><?= e($lst['lot_number'] ?? '—') ?></td>
                    <td>
                        <span class="status-badge status-<?= strtolower($lst['status'] ?? 'active') ?>">
                            <?= e($lst['status'] ?? 'Active') ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <button type="button" class="action-btn"
                                    onclick="openListingModal(<?= $lst['id'] ?>, <?= $prodId ?>)">✏️ Edit</button>
                            <button type="button" class="action-btn delete-btn"
                                    onclick="deleteListing(<?= $lst['id'] ?>, <?= $prodId ?>)">🗑️ Del</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <div id="no-listings-msg" style="text-align:center;padding:40px;background:#f8fafc;border-radius:10px;color:var(--muted);">
            <p style="font-size:1.2rem;margin-bottom:8px;">🏭 No supplier listings yet</p>
            <p style="margin-bottom:16px;">Add a listing to specify supplier details, availability, and stock info.</p>
            <button type="button" class="btn btn-primary" onclick="openListingModal(0, <?= $prodId ?>)">➕ Add First Listing</button>
        </div>
        <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ==================== SUPPLIER EXCEL IMPORT ==================== -->
    <?php elseif ($action === 'import'):
        $importResult = $_SESSION['import_result'] ?? null;
        unset($_SESSION['import_result']);
        $allSuppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers WHERE is_active=1 ORDER BY supplier_name");
    ?>

    <?php if ($importResult): ?>
    <div style="margin-bottom:24px;padding:20px 24px;border-radius:10px;
                background:<?= $importResult['success'] ? '#f0fdf4' : '#fef2f2' ?>;
                border:1.5px solid <?= $importResult['success'] ? '#86efac' : '#fca5a5' ?>;">

        <?php if (!$importResult['success']): ?>
            <strong style="color:#991b1b;">❌ Import failed:</strong>
            <span style="color:#7f1d1d"><?= e($importResult['error'] ?? 'Unknown error') ?></span>

        <?php else: ?>
            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:<?= (!empty($importResult['warnings']) || !empty($importResult['dedup_log'])) ? '16px' : '0' ?>">
                <?php
                $stats = [
                    ['🆕 New compounds',    $importResult['inserted'],        '#166534','#dcfce7'],
                    ['🔄 Enriched',         $importResult['updated'],         '#1e40af','#dbeafe'],
                    ['📋 New listings',     $importResult['new_listings'],    '#7c3aed','#ede9fe'],
                    ['🔃 Updated listings', $importResult['updated_listings'],'#92400e','#fef3c7'],
                    ['⏭️ Skipped (blank)',  $importResult['skipped'],         '#475569','#f1f5f9'],
                    ['🔬 Stereo checked',   $importResult['pubchem_fetched'], '#065f46','#d1fae5'],
                ];
                foreach ($stats as [$lbl, $val, $clr, $bg]): ?>
                <div style="background:<?= $bg ?>;color:<?= $clr ?>;padding:10px 18px;border-radius:8px;text-align:center;min-width:100px">
                    <div style="font-size:1.4rem;font-weight:700"><?= (int)$val ?></div>
                    <div style="font-size:.75rem"><?= $lbl ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($importResult['pubchem_note'] ?? null): ?>
            <div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;padding:8px 14px;margin-bottom:12px;font-size:.875rem">
                ⚠️ <?= e($importResult['pubchem_note']) ?>
            </div>
            <?php endif; ?>
            <?php foreach ($importResult['pubchem_errors'] ?? [] as $perr): ?>
            <div style="color:#b45309;font-size:.82rem">PubChem: <?= e($perr) ?></div>
            <?php endforeach; ?>

            <?php if (!empty($importResult['warnings'])): ?>
            <details style="margin-top:12px">
                <summary style="cursor:pointer;font-weight:600;color:#92400e">
                    ⚠️ <?= count($importResult['warnings']) ?> warning<?= count($importResult['warnings']) !== 1 ? 's' : '' ?> — review before using data
                </summary>
                <table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:.82rem">
                    <thead><tr style="background:#fef3c7">
                        <th style="padding:6px 10px;text-align:left">Row</th>
                        <th style="padding:6px 10px;text-align:left">Type</th>
                        <th style="padding:6px 10px;text-align:left">Compound</th>
                        <th style="padding:6px 10px;text-align:left">Details</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($importResult['warnings'] as $w): ?>
                        <tr style="border-bottom:1px solid #fde68a">
                            <td style="padding:5px 10px"><?= (int)$w['row'] ?></td>
                            <td style="padding:5px 10px"><code style="background:#fef9c3;padding:1px 5px;border-radius:3px"><?= e($w['type']) ?></code></td>
                            <td style="padding:5px 10px"><?= e($w['name'] ?? '') ?></td>
                            <td style="padding:5px 10px;color:#92400e"><?= e($w['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>

            <?php if (!empty($importResult['dedup_log'])): ?>
            <details style="margin-top:10px">
                <summary style="cursor:pointer;font-weight:600;color:#1e40af">
                    🔁 <?= count($importResult['dedup_log']) ?> deduplication event<?= count($importResult['dedup_log']) !== 1 ? 's' : '' ?>
                </summary>
                <table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:.82rem">
                    <thead><tr style="background:#dbeafe">
                        <th style="padding:6px 10px;text-align:left">Row</th>
                        <th style="padding:6px 10px;text-align:left">Confidence</th>
                        <th style="padding:6px 10px;text-align:left">Spreadsheet Name</th>
                        <th style="padding:6px 10px;text-align:left">Matched By</th>
                        <th style="padding:6px 10px;text-align:left">Merged Into</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($importResult['dedup_log'] as $d):
                        $confColor = match($d['confidence'] ?? '') { 'high' => '#166534', 'medium' => '#92400e', default => '#991b1b' };
                    ?>
                        <tr style="border-bottom:1px solid #bfdbfe">
                            <td style="padding:5px 10px"><?= (int)$d['row'] ?></td>
                            <td style="padding:5px 10px;color:<?= $confColor ?>;font-weight:600"><?= e($d['confidence'] ?? '—') ?></td>
                            <td style="padding:5px 10px"><?= e($d['csv_name'] ?? '') ?></td>
                            <td style="padding:5px 10px"><code><?= e($d['matched_by'] ?? '') ?></code></td>
                            <td style="padding:5px 10px"><?= e($d['matched_to'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="upload-card">
        <h2>📦 Supplier Excel Import</h2>
        <p style="color:var(--muted);margin-bottom:6px">
            Import compound and listing data directly from a supplier's filled-in Excel template.
            New compounds are auto-enriched from PubChem and stereo-checked via RDKit.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px">

            <!-- ── Step 1: Download Template ── -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px">
                <h3 style="margin:0 0 10px;font-size:1rem">Step 1 — Download template for supplier</h3>
                <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px">
                    Generates a coloured .xlsx with dropdowns. Green = required, Yellow = commercial,
                    Blue = classification, Gray = auto-filled by us. Share this with the supplier.
                </p>
                <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                    <div>
                        <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:4px">Supplier (optional pre-fill)</label>
                        <select id="tpl-supplier-sel" style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem">
                            <option value="">— generic template —</option>
                            <?php foreach ($allSuppliers as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['supplier_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button onclick="downloadTemplate()" class="btn btn-primary">⬇️ Download Template (.xlsx)</button>
                </div>
            </div>

            <!-- ── Step 2: Upload completed file ── -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px">
                <h3 style="margin:0 0 10px;font-size:1rem">Step 2 — Upload completed supplier file</h3>
                <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px">
                    Select the supplier whose data this is, then upload their filled-in .xlsx.
                    Duplicates are detected automatically. ≤ 20 new compounds get PubChem + stereo
                    checked immediately; larger batches use the batch tools below.
                </p>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div style="margin-bottom:12px">
                        <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:4px">Supplier <span style="color:#ef4444">*</span></label>
                        <select name="supplier_id" required style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;width:100%">
                            <option value="">— select supplier —</option>
                            <?php foreach ($allSuppliers as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['supplier_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:14px">
                        <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:4px">Excel File (.xlsx) <span style="color:#ef4444">*</span></label>
                        <input type="file" name="xlsx_file" accept=".xlsx" required
                               style="padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;width:100%">
                        <small style="color:var(--muted);display:block;margin-top:4px">Max: <?= ini_get('upload_max_filesize') ?></small>
                    </div>
                    <button type="submit" class="btn btn-primary"
                            onclick="this.disabled=true;this.textContent='Importing…';this.form.submit()">
                        📤 Import Excel
                    </button>
                </form>
            </div>

        </div><!-- /grid -->

        <!-- Column guide -->
        <details style="margin-top:24px">
            <summary style="cursor:pointer;font-weight:600;color:#334155;font-size:.9rem">📄 Column reference — what the template expects</summary>
            <div style="margin-top:12px;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                <thead>
                <tr style="background:#f1f5f9">
                    <th style="padding:6px 10px;text-align:left">Column</th>
                    <th style="padding:6px 10px;text-align:left">Section</th>
                    <th style="padding:6px 10px;text-align:left">DB target</th>
                    <th style="padding:6px 10px;text-align:left">Notes</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $colGuide = [
                    ['compound_name *',              '🟢 Required',   'compounds.compound_name',                  'Common/trade name'],
                    ['cas_number',                   '🟢 Required',   'compounds.cas_number',                     'Drives PubChem auto-fill'],
                    ['supplier_catalog_number',      '🟢 Required',   'supplier_listings.supplier_catalog_number','Supplier\'s own SKU'],
                    ['availability',                 '🟡 Commercial', 'supplier_listings.availability',           'In Stock / On Request / Backorder / Discontinued'],
                    ['stock_status',                 '🟡 Commercial', 'supplier_listings.stock_status',           'in_stock / low_stock / backordered / discontinued'],
                    ['min_order_qty',                '🟡 Commercial', 'supplier_listings.min_order_qty',          'Numeric'],
                    ['unit',                         '🟡 Commercial', 'supplier_listings.unit',                   'mg / g / kg / ml / L / vial …'],
                    ['purity',                       '🟡 Commercial', 'supplier_listings.purity',                 'e.g. 98.5'],
                    ['purity_by_method',             '🟡 Commercial', 'supplier_listings.purity_by_method',       'HPLC / NMR / GC …'],
                    ['lead_time',                    '🟡 Commercial', 'supplier_listings.lead_time',              'e.g. "2-3 weeks"'],
                    ['lot_number',                   '🟡 Commercial', 'supplier_listings.lot_number',             ''],
                    ['manufacture_date',             '🟡 Commercial', 'supplier_listings.manufacture_date',       'YYYY-MM-DD'],
                    ['expiry_date',                  '🟡 Commercial', 'supplier_listings.expiry_date',            'YYYY-MM-DD'],
                    ['storage_condition',            '🟡 Commercial', 'compounds.storage_condition',              'Room Temperature / 2-8°C …'],
                    ['product_type',                 '🔵 Class.',     'compounds.product_type',                   'API / Impurity / Metabolite …'],
                    ['parent_drug',                  '🔵 Class.',     'compounds.parent_drug',                    ''],
                    ['therapeutic_category',         '🔵 Class.',     'compounds.therapeutic_category',           ''],
                    ['hazard_class',                 '🔵 Class.',     'compounds.hazard_class',                   ''],
                    ['regulatory_ref',               '🔵 Class.',     'compounds.regulatory_ref',                 'USP / EP / BP reference'],
                    ['supplier_notes',               '🩷 Notes',      'supplier_listings.supplier_notes',         ''],
                    ['iupac_name (auto)',             '⬜ Auto',       'compounds.iupac_name',                     'Leave blank — filled from PubChem'],
                    ['molecular_formula (auto)',      '⬜ Auto',       'compounds.molecular_formula',              'Leave blank'],
                    ['molecular_weight (auto)',       '⬜ Auto',       'compounds.molecular_weight',               'Leave blank'],
                    ['smiles (auto)',                 '⬜ Auto',       'compounds.smiles',                         'Leave blank (or paste if known)'],
                    ['inchi (auto)',                  '⬜ Auto',       'compounds.inchi',                          'Leave blank'],
                    ['inchi_key (auto)',              '⬜ Auto',       'compounds.inchi_key',                      'Leave blank'],
                    ['synonyms (auto)',               '⬜ Auto',       'compounds.synonyms',                       'Leave blank or pipe-separated'],
                    ['pubchem_cid (auto)',            '⬜ Auto',       'compounds.pubchem_cid',                    'Leave blank'],
                    ['ab_catalog_number (internal)', '🔘 Internal',   'compounds.ab_catalog_number',              'DO NOT EDIT — assigned by ABChem'],
                ];
                foreach ($colGuide as [$col, $sec, $db_col, $note]): ?>
                <tr style="border-bottom:1px solid #e2e8f0">
                    <td style="padding:5px 10px;font-family:monospace;white-space:nowrap"><?= e($col) ?></td>
                    <td style="padding:5px 10px;white-space:nowrap"><?= $sec ?></td>
                    <td style="padding:5px 10px;color:#64748b;font-size:.78rem"><?= e($db_col) ?></td>
                    <td style="padding:5px 10px;color:#64748b"><?= e($note) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
    </div><!-- /upload-card -->

    <script>
    function downloadTemplate() {
        var sid = document.getElementById('tpl-supplier-sel').value;
        window.location = '?ajax=download_template' + (sid ? '&supplier_id=' + sid : '');
    }
    </script>

    <!-- ==================== BULK UPDATE ==================== -->
    <?php elseif ($action === 'bulk'): ?>

    <?php
    // Show dedup log and warnings if stored in session after CSV import
    $dedupLog       = $_SESSION['dedup_log']       ?? null;
    $importWarnings = $_SESSION['import_warnings'] ?? null;
    unset($_SESSION['dedup_log'], $_SESSION['import_warnings']);
    ?>

    <?php if (!empty($importWarnings)): ?>
    <div class="pubchem-diff-panel" style="margin-bottom:24px;border-left:4px solid #f59e0b;background:#fffbeb;">
        <div class="diff-header" style="background:#fef3c7;">
            <span class="diff-icon">⚠️</span>
            <div>
                <strong style="color:#92400e">Import Warnings — Rows Needing Review</strong>
                <span class="diff-count" style="background:#fde68a;color:#92400e"><?= count($importWarnings) ?> warning<?= count($importWarnings) !== 1 ? 's' : '' ?></span>
            </div>
        </div>
        <table class="diff-table">
            <thead><tr style="background:#fef3c7"><th>Row</th><th>Compound Name</th><th>Type</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($importWarnings as $i => $w): ?>
                <tr class="diff-row-forced" style="background:<?= $i % 2 === 0 ? '#fffbeb' : '#fef9ee' ?>">
                    <td><?= (int)($w['row'] ?? '—') ?></td>
                    <td><?= e($w['name'] ?? '') ?></td>
                    <td><span style="font-size:.75rem;padding:2px 7px;border-radius:10px;background:<?= ($w['type'] ?? '') === 'cas_conflict' ? '#fee2e2' : '#fef3c7' ?>;color:<?= ($w['type'] ?? '') === 'cas_conflict' ? '#991b1b' : '#92400e' ?>"><?= e($w['type'] ?? '') ?></span></td>
                    <td style="font-size:.82rem"><?= e($w['message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($dedupLog)): ?>
    <div class="pubchem-diff-panel" style="margin-bottom:24px;">
        <div class="diff-header">
            <span class="diff-icon">🔁</span>
            <div>
                <strong>Deduplication Report — Last Import</strong>
                <span class="diff-count"><?= count($dedupLog) ?> duplicate<?= count($dedupLog) !== 1 ? 's' : '' ?> detected &amp; merged</span>
            </div>
        </div>
        <table class="diff-table">
            <thead><tr><th>#</th><th>Row</th><th>CSV Name</th><th>Confidence</th><th>Matched By</th><th>Merged Into</th></tr></thead>
            <tbody>
            <?php foreach ($dedupLog as $i => $entry):
                $confColor = match($entry['confidence'] ?? '') { 'high' => '#166534', 'medium' => '#92400e', default => '#991b1b' };
            ?>
                <tr class="diff-row-forced">
                    <td><?= $i + 1 ?></td>
                    <td><?= (int)($entry['row'] ?? 0) ?></td>
                    <td><?= e($entry['csv_name']) ?></td>
                    <td style="color:<?= $confColor ?>;font-weight:600"><?= e($entry['confidence'] ?? '—') ?></td>
                    <td><code><?= e($entry['matched_by']) ?></code></td>
                    <td><?= e($entry['matched_to']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="upload-card">
        <h2>📤 Bulk Import / Update via CSV</h2>
        <p style="color:var(--muted);margin-bottom:20px;">
            Upload a CSV containing both <strong>compound data</strong> and <strong>supplier listing data</strong>.
            Deduplication runs automatically — existing compounds are enriched, new ones created.
            Each row also creates or updates one supplier listing.
        </p>

        <!-- Upload form -->
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="bulk_update">
            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label">CSV File <span style="color:#ef4444">*</span></label>
                <input type="file" name="csv_file" accept=".csv" required class="form-input" style="padding:12px;">
                <small style="color:var(--muted);display:block;margin-top:6px;">
                    Max upload size: <?= ini_get('upload_max_filesize') ?>.
                    Use UTF-8 encoding. First row must be the header.
                </small>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">📤 Upload &amp; Import</button>
                <a href="?action=list" class="btn btn-outline" style="text-decoration:none;">Cancel</a>
            </div>
        </form>

        <hr style="margin:28px 0;border-color:#e2e8f0;">

        <!-- Template + Export row -->
        <?php
        $allSuppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");
        ?>
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
            <div style="flex:1;min-width:220px;">
                <h3 style="margin-bottom:8px;">📥 Download Excel Template</h3>
                <p style="color:var(--muted);font-size:.875rem;margin-bottom:12px;">
                    Excel (.xlsx) with dropdown validation for product type, availability, unit &amp; more.
                    Blue columns are required. One example row included.
                </p>
                <a href="?ajax=csv_template" class="btn btn-primary">⬇️ Download Template (.xlsx)</a>
            </div>

            <div style="flex:1;min-width:220px;">
                <h3 style="margin-bottom:8px;">📊 Export All Compounds</h3>
                <p style="color:var(--muted);font-size:.875rem;margin-bottom:12px;">
                    One row per supplier listing — every compound appears once per supplier.
                </p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="export_products.php?status=active" class="btn btn-outline" style="text-decoration:none;">
                        ⬇️ Active Listings
                    </a>
                    <a href="export_products.php?status=all" class="btn btn-outline" style="text-decoration:none;">
                        ⬇️ All Listings
                    </a>
                </div>
            </div>

            <?php if (!empty($allSuppliers)): ?>
            <div style="flex:1;min-width:220px;">
                <h3 style="margin-bottom:8px;">🏭 Export by Supplier</h3>
                <p style="color:var(--muted);font-size:.875rem;margin-bottom:12px;">
                    Download only the compounds and listings for one specific supplier.
                </p>
                <form method="get" action="export_products.php"
                      style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <select name="supplier_id" class="form-select" style="flex:1;min-width:150px;padding:8px 12px;">
                        <option value="">-- Supplier --</option>
                        <?php foreach ($allSuppliers as $sup): ?>
                        <option value="<?= $sup['id'] ?>"><?= e($sup['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="form-select" style="width:auto;padding:8px 12px;">
                        <option value="active">Active only</option>
                        <option value="all">All</option>
                    </select>
                    <button type="submit" class="btn btn-outline">⬇️ Export</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <hr style="margin:28px 0;border-color:#e2e8f0;">

        <!-- CSV field reference -->
        <details style="cursor:pointer;">
            <summary style="font-weight:600;color:var(--primary);font-size:.95rem;padding:8px 0;">
                📋 CSV Column Reference
            </summary>
            <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:20px;font-size:.85rem;">
                <div>
                    <strong style="display:block;margin-bottom:8px;color:#0369a1;">Compound Fields</strong>
                    <table style="width:100%;border-collapse:collapse;">
                        <tr style="background:#f1f5f9"><th style="padding:4px 8px;text-align:left">Column</th><th style="padding:4px 8px;text-align:left">Notes</th></tr>
                        <?php foreach ([
                            'compound_name' => 'Required',
                            'cas_number'    => 'e.g. 50-78-2',
                            'iupac_name'    => '',
                            'molecular_formula' => 'e.g. C9H8O4',
                            'molecular_weight'  => 'Numeric',
                            'smiles'        => 'SMILES string',
                            'inchi_key'     => '27-char InChIKey',
                            'pubchem_cid'   => 'Numeric',
                            'synonyms'      => 'Pipe-separated',
                            'product_type'  => 'API Impurity | Reference Standard | Metabolite | Intermediate | API | Building Block | Isotope',
                            'status'        => 'Active / Inactive / Draft',
                        ] as $col => $note): ?>
                        <tr style="border-bottom:1px solid #f1f5f9">
                            <td style="padding:4px 8px"><code><?= $col ?></code></td>
                            <td style="padding:4px 8px;color:var(--muted)"><?= $note ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div>
                    <strong style="display:block;margin-bottom:8px;color:#0369a1;">Supplier Listing Fields</strong>
                    <table style="width:100%;border-collapse:collapse;">
                        <tr style="background:#f1f5f9"><th style="padding:4px 8px;text-align:left">Column</th><th style="padding:4px 8px;text-align:left">Notes</th></tr>
                        <?php foreach ([
                            'supplier_id'      => 'ID from suppliers table (1=Sunveda, 2=ABChem)',
                            'purity'           => 'e.g. 99.5%',
                            'purity_by_method' => 'e.g. HPLC',
                            'availability'     => 'In Stock | Backorder | On Request | Discontinued',
                            'stock_status'     => 'in_stock | low_stock | backordered | discontinued',
                            'min_order_qty'    => 'Numeric',
                            'unit'             => 'mg | g | kg | ml | L | vial | …',
                            'lead_time'        => 'e.g. 7-14 days',
                            'lot_number'       => '',
                            'manufacture_date' => 'YYYY-MM-DD',
                            'expiry_date'      => 'YYYY-MM-DD',
                            'catalog_number'   => 'Auto-generated if blank',
                        ] as $col => $note): ?>
                        <tr style="border-bottom:1px solid #f1f5f9">
                            <td style="padding:4px 8px"><code><?= $col ?></code></td>
                            <td style="padding:4px 8px;color:var(--muted)"><?= $note ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </details>
    </div>
    <?php elseif ($action === 'stereo'): ?>
    <!-- ==================== STEREO REVIEW QUEUE ==================== -->
    <?php
    $hasStereoCols = in_array('stereo_status', $allColumns);
    if (!$hasStereoCols): ?>
    <div style="padding:40px;text-align:center;background:#fef3c7;border-radius:8px;color:#92400e">
        <strong>⚠️ DB migration required.</strong> Run <code>migrate_stereo.php</code> on the server first to add stereo columns.
    </div>
    <?php else:
        $stereoStats = $db->fetchOne(
            "SELECT
               COUNT(*) as total,
               SUM(CASE WHEN stereo_status IS NULL THEN 1 ELSE 0 END) as unchecked,
               SUM(CASE WHEN stereo_status='achiral' THEN 1 ELSE 0 END) as achiral,
               SUM(CASE WHEN stereo_status='unverified' THEN 1 ELSE 0 END) as unverified,
               SUM(CASE WHEN stereo_status='verified' THEN 1 ELSE 0 END) as verified,
               SUM(CASE WHEN stereo_status='manual_review' THEN 1 ELSE 0 END) as manual_review
             FROM compounds WHERE status='Active'"
        );
        $stereoFilter = $_GET['stereo_filter'] ?? 'unverified';
        $validFilters = ['unchecked','achiral','unverified','verified','manual_review','all'];
        if (!in_array($stereoFilter, $validFilters)) $stereoFilter = 'unverified';

        $whereClause = match($stereoFilter) {
            'unchecked'     => "stereo_status IS NULL AND smiles IS NOT NULL AND smiles NOT IN ('','NA')",
            'achiral'       => "stereo_status = 'achiral'",
            'unverified'    => "stereo_status = 'unverified'",
            'verified'      => "stereo_status = 'verified'",
            'manual_review' => "stereo_status = 'manual_review'",
            default         => "1=1",
        };
        $stereoPage  = max(1, (int)($_GET['sp'] ?? 1));
        $stereoLimit = 30;
        $stereoOffset = ($stereoPage - 1) * $stereoLimit;
        $stereoTotal = (int)$db->fetchValue("SELECT COUNT(*) FROM compounds WHERE status='Active' AND $whereClause");
        $stereoRows  = $db->fetchAll(
            "SELECT id, compound_name, cas_number, ab_catalog_number, url_token, url_slug,
                    stereo_status, stereo_source, smiles, smiles_stereo,
                    LEFT(inchi_key,30) as inchi_key_short
             FROM compounds WHERE status='Active' AND $whereClause
             ORDER BY id DESC LIMIT $stereoLimit OFFSET $stereoOffset"
        );
    ?>

    <!-- Stats bar -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
        <?php foreach ([
            ['unchecked','Unchecked','#f1f5f9','#475569'],
            ['unverified','Unverified','#fef3c7','#92400e'],
            ['manual_review','Needs Review','#fee2e2','#991b1b'],
            ['achiral','Achiral','#dcfce7','#166534'],
            ['verified','Verified','#dbeafe','#1e40af'],
        ] as [$key, $label, $bg, $color]):
            $cnt = $stereoStats[$key] ?? 0;
        ?>
        <a href="?action=stereo&stereo_filter=<?= $key ?>" style="text-decoration:none">
            <div style="background:<?= $bg ?>;color:<?= $color ?>;padding:10px 18px;border-radius:8px;
                        border:2px solid <?= $stereoFilter===$key ? $color : 'transparent' ?>;cursor:pointer">
                <div style="font-size:1.4rem;font-weight:700"><?= number_format($cnt) ?></div>
                <div style="font-size:.78rem;font-weight:600"><?= $label ?></div>
            </div>
        </a>
        <?php endforeach; ?>

        <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
            <button class="btn btn-primary" onclick="stereoBatchCheck()" id="btn-batch-check">
                🔬 RDKit Check All Unchecked
            </button>
            <button class="btn btn-outline" onclick="stereoBatchFetch()" id="btn-batch-fetch">
                🌐 Fetch GSRS/ChEMBL (20 at a time)
            </button>
        </div>
    </div>
    <div id="stereo-batch-msg" style="margin-bottom:14px;font-size:.85rem;color:#475569"></div>

    <!-- Filter tabs -->
    <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
        <?php foreach (['unchecked'=>'Unchecked','unverified'=>'Unverified','manual_review'=>'Needs Review','verified'=>'Verified','achiral'=>'Achiral','all'=>'All'] as $f => $lbl): ?>
        <a href="?action=stereo&stereo_filter=<?= $f ?>"
           style="padding:5px 14px;border-radius:20px;font-size:.82rem;text-decoration:none;
                  background:<?= $stereoFilter===$f ? '#0f172a' : '#f1f5f9' ?>;
                  color:<?= $stereoFilter===$f ? '#fff' : '#475569' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($stereoRows)): ?>
    <p style="color:#64748b;padding:20px 0">No compounds in this category.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="product-table">
        <thead><tr>
            <th>ID</th><th>Compound</th><th>CAS</th>
            <th>Stereo Status</th><th>Source</th>
            <th>SMILES (supplier)</th><th>SMILES (stereo)</th>
            <th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($stereoRows as $sr):
            $statusColors = ['achiral'=>'#dcfce7|#166534','verified'=>'#dbeafe|#1e40af',
                             'unverified'=>'#fef3c7|#92400e','manual_review'=>'#fee2e2|#991b1b'];
            [$sBg, $sFg] = explode('|', $statusColors[$sr['stereo_status'] ?? ''] ?? '#f1f5f9|#475569');
            $productUrl  = buildProductUrl($sr);
        ?>
        <tr id="stereo-row-<?= $sr['id'] ?>">
            <td><a href="?action=edit&id=<?= $sr['id'] ?>" style="color:#2563eb"><?= $sr['id'] ?></a></td>
            <td style="max-width:200px;word-break:break-word">
                <a href="?action=edit&id=<?= $sr['id'] ?>" style="color:#0f172a;text-decoration:none"><?= e($sr['compound_name']) ?></a>
            </td>
            <td style="font-family:monospace;font-size:.82rem"><?= e($sr['cas_number'] ?? '—') ?></td>
            <td>
                <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:600;background:<?= $sBg ?>;color:<?= $sFg ?>">
                    <?= e($sr['stereo_status'] ?? 'unchecked') ?>
                </span>
            </td>
            <td style="font-size:.8rem;color:#64748b"><?= e($sr['stereo_source'] ?? '—') ?></td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;font-family:monospace;font-size:.72rem;color:#475569" title="<?= e($sr['smiles'] ?? '') ?>">
                <?= e(mb_substr($sr['smiles'] ?? '—', 0, 60)) ?><?= strlen($sr['smiles'] ?? '') > 60 ? '…' : '' ?>
            </td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;font-family:monospace;font-size:.72rem;color:#166534" title="<?= e($sr['smiles_stereo'] ?? '') ?>">
                <?php if ($sr['smiles_stereo']): ?>
                    <?= e(mb_substr($sr['smiles_stereo'], 0, 60)) ?><?= strlen($sr['smiles_stereo']) > 60 ? '…' : '' ?>
                <?php else: ?>
                    <span style="color:#94a3b8">—</span>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap">
                <button class="btn btn-outline" style="font-size:.75rem;padding:3px 8px"
                        onclick="stereoCheckOne(<?= $sr['id'] ?>)">🔬 Check</button>
                <button class="btn btn-outline" style="font-size:.75rem;padding:3px 8px"
                        onclick="stereoFetchOne(<?= $sr['id'] ?>)">🌐 Fetch</button>
                <a href="?action=edit&id=<?= $sr['id'] ?>" class="btn btn-outline"
                   style="font-size:.75rem;padding:3px 8px;text-decoration:none">✏️ Edit</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($stereoTotal > $stereoLimit): ?>
    <div style="margin-top:16px;display:flex;gap:8px;align-items:center">
        <?php if ($stereoPage > 1): ?>
        <a href="?action=stereo&stereo_filter=<?= $stereoFilter ?>&sp=<?= $stereoPage-1 ?>" class="btn btn-outline">← Prev</a>
        <?php endif; ?>
        <span style="color:#64748b;font-size:.85rem">Page <?= $stereoPage ?> of <?= ceil($stereoTotal/$stereoLimit) ?> (<?= $stereoTotal ?> total)</span>
        <?php if ($stereoPage < ceil($stereoTotal/$stereoLimit)): ?>
        <a href="?action=stereo&stereo_filter=<?= $stereoFilter ?>&sp=<?= $stereoPage+1 ?>" class="btn btn-outline">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; // empty rows ?>
    <?php endif; // hasStereoCols ?>

    <?php endif; // action === stereo ?>

</div>

<!-- ── PubChem Preview Modal ─────────────────────────────────────────────── -->
<div class="pubchem-preview-overlay" id="pubchem_overlay" onclick="if(event.target===this)closeModal()">
    <div class="pubchem-preview-modal">
        <h3>⚗️ PubChem Data Preview</h3>
        <p style="color:#64748b;font-size:.875rem;margin-bottom:16px;">
            Data that would be fetched from PubChem for this compound. Fields shown in <strong style="color:#16a34a">green</strong> are currently empty on this product.
        </p>
        <div id="pubchem_modal_body" style="color:#94a3b8;text-align:center;padding:30px 0">
            Loading…
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal()">Close</button>
            <button type="button" class="btn-pubchem" onclick="closeModal(); document.getElementById('fetch_pubchem_flag').value='1'; document.getElementById('product-form').submit()">
                ⚗️ Apply to Product
            </button>
        </div>
    </div>
</div>

<!-- ── Supplier Listing Add/Edit Modal ───────────────────────────────────── -->
<div class="pubchem-preview-overlay" id="listing_overlay" onclick="if(event.target===this)closeListingModal()">
    <div class="pubchem-preview-modal" style="max-width:900px;">
        <h3 id="listing-modal-title">🏭 Add Supplier Listing</h3>
        <p style="color:#64748b;font-size:.875rem;margin-bottom:4px;" id="listing-modal-subtitle"></p>

        <input type="hidden" id="lm_listing_id"  value="0">
        <input type="hidden" id="lm_compound_id" value="0">

        <div class="form-grid" style="margin-top:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:14px 20px;">
            <!-- Supplier -->
            <div class="form-group">
                <label class="form-label">Supplier <span style="color:#ef4444">*</span></label>
                <select id="lm_supplier_id" class="form-select">
                    <option value="">Loading…</option>
                </select>
            </div>
            <!-- Catalog Number -->
            <div class="form-group">
                <label class="form-label">Catalog Number</label>
                <input type="text" id="lm_catalog_number" class="form-input" placeholder="Auto-generated if blank">
            </div>
            <!-- Purity -->
            <div class="form-group">
                <label class="form-label">Purity</label>
                <input type="text" id="lm_purity" class="form-input" placeholder="e.g. 99.5%">
            </div>
            <!-- Purity by Method -->
            <div class="form-group">
                <label class="form-label">Purity By Method</label>
                <input type="text" id="lm_purity_by_method" class="form-input" placeholder="e.g. HPLC">
            </div>
            <!-- Availability -->
            <div class="form-group">
                <label class="form-label">Availability</label>
                <select id="lm_availability" class="form-select">
                    <option value="In Stock">In Stock</option>
                    <option value="Backorder">Backorder</option>
                    <option value="On Request">On Request</option>
                    <option value="Discontinued">Discontinued</option>
                </select>
            </div>
            <!-- Stock Status -->
            <div class="form-group">
                <label class="form-label">Stock Status</label>
                <select id="lm_stock_status" class="form-select">
                    <option value="in_stock">In Stock</option>
                    <option value="low_stock">Low Stock</option>
                    <option value="backordered">Backordered</option>
                    <option value="discontinued">Discontinued</option>
                </select>
            </div>
            <!-- Min Order Qty -->
            <div class="form-group">
                <label class="form-label">Min Order Qty</label>
                <input type="number" id="lm_min_order_qty" class="form-input" value="1" min="0.001" step="any">
            </div>
            <!-- Unit -->
            <div class="form-group">
                <label class="form-label">Unit</label>
                <select id="lm_unit" class="form-select">
                    <?php foreach (['mg','g','kg','ml','L','vial','ampoule','tablet','capsule','lot'] as $u): ?>
                    <option value="<?= $u ?>"><?= $u ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Quantity Available -->
            <div class="form-group">
                <label class="form-label">Qty Available</label>
                <input type="number" id="lm_quantity_available" class="form-input" placeholder="e.g. 500" step="any" min="0">
            </div>
            <!-- Lead Time -->
            <div class="form-group">
                <label class="form-label">Lead Time</label>
                <input type="text" id="lm_lead_time" class="form-input" placeholder="e.g. 7-14 days">
            </div>
            <!-- Lot Number -->
            <div class="form-group">
                <label class="form-label">Lot Number</label>
                <input type="text" id="lm_lot_number" class="form-input">
            </div>
            <!-- Manufacture Date -->
            <div class="form-group">
                <label class="form-label">Manufacture Date</label>
                <input type="date" id="lm_manufacture_date" class="form-input">
            </div>
            <!-- Expiry Date -->
            <div class="form-group">
                <label class="form-label">Expiry Date</label>
                <input type="date" id="lm_expiry_date" class="form-input">
            </div>
            <!-- Status -->
            <div class="form-group">
                <label class="form-label">Status</label>
                <select id="lm_status" class="form-select">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
        </div>

        <!-- Supplier Notes (full width) -->
        <div class="form-group span-full" style="margin-top:8px;">
            <label class="form-label">Supplier Notes</label>
            <textarea id="lm_supplier_notes" rows="2" class="form-textarea"
                      placeholder="Internal notes (e.g. CoA reference, special handling)"></textarea>
        </div>

        <div id="listing-modal-error" style="display:none;margin-top:12px;padding:10px 14px;background:#fee2e2;color:#991b1b;border-radius:8px;font-size:.875rem;"></div>

        <div class="modal-actions" style="margin-top:20px;">
            <button type="button" class="btn btn-outline" onclick="closeListingModal()">✕ Cancel</button>
            <button type="button" class="btn btn-primary" id="btn-save-listing" onclick="saveListingModal()">
                💾 Save Listing
            </button>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
window.PROD_ID   = <?= json_encode($prodId ?? 0) ?>;
window.PROD_SLUG = <?= json_encode($prodSlug ?? '') ?>;
</script>
<script src="/js/utils.js" defer></script>
<script src="/js/admin-products.js" defer></script>
<script>
// ── Stereo AJAX helpers ───────────────────────────────────────────────────────
function _stereoApi(params, msgEl, btnEl) {
    if (btnEl) btnEl.disabled = true;
    if (msgEl) msgEl.textContent = '⏳ Working…';
    return fetch('/api_stereo.php?' + new URLSearchParams(params))
        .then(r => r.json())
        .then(data => {
            if (msgEl) msgEl.textContent = data.error
                ? '❌ ' + data.error
                : '✅ ' + JSON.stringify(data).replace(/[{}"]/g,'').slice(0, 120);
            return data;
        })
        .catch(e => { if (msgEl) msgEl.textContent = '❌ ' + e; })
        .finally(() => { if (btnEl) btnEl.disabled = false; });
}

// Single-compound check from edit form
function runStereoCheck(id) {
    const msg = document.getElementById('stereo-ajax-msg');
    _stereoApi({action:'check_one', id}, msg, null).then(d => {
        if (d && d.stereo_status) {
            document.querySelector('select[name="stereo_status"]').value = d.stereo_status;
            msg.textContent = '✅ Status set to: ' + d.stereo_status + ' — save the form to persist.';
        }
    });
}
function fetchStereoExternal(id) {
    const msg = document.getElementById('stereo-ajax-msg');
    _stereoApi({action:'fetch_one', id}, msg, null).then(d => {
        if (d && d.smiles_stereo) {
            document.querySelector('textarea[name="smiles_stereo"]').value = d.smiles_stereo;
            document.querySelector('input[name="stereo_source"]').value  = d.stereo_source || '';
            document.querySelector('select[name="stereo_status"]').value = d.stereo_status || 'verified';
            msg.textContent = '✅ Found via ' + (d.stereo_source||'?') + ' (score ' + (d.score||'?') + ') — save the form to persist.';
        } else {
            msg.textContent = d && d.message ? '⚠ ' + d.message : '⚠ Not found in external sources.';
        }
    });
}

// Review-queue row actions
function stereoCheckOne(id) {
    const row = document.getElementById('stereo-row-' + id);
    _stereoApi({action:'check_one', id}, null, null).then(d => {
        if (d && row) row.cells[3].innerHTML = '<span style="padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:600;background:#fef3c7;color:#92400e">' + (d.stereo_status||'?') + '</span>';
    });
}
function stereoFetchOne(id) {
    const row = document.getElementById('stereo-row-' + id);
    _stereoApi({action:'fetch_one', id}, null, null).then(d => {
        if (d && row) {
            row.cells[3].innerHTML = '<span style="padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:600;background:#dbeafe;color:#1e40af">' + (d.stereo_status||'verified') + '</span>';
            row.cells[4].textContent = d.stereo_source || '';
            row.cells[6].textContent = (d.smiles_stereo || '').slice(0, 60) + (d.smiles_stereo?.length > 60 ? '…' : '');
        }
    });
}

// Batch actions (review queue page)
function stereoBatchCheck() {
    const msg = document.getElementById('stereo-batch-msg');
    const btn = document.getElementById('btn-batch-check');
    _stereoApi({action:'check_batch'}, msg, btn).then(() => setTimeout(()=>location.reload(), 1500));
}
function stereoBatchFetch() {
    const msg = document.getElementById('stereo-batch-msg');
    const btn = document.getElementById('btn-batch-fetch');
    _stereoApi({action:'fetch_batch'}, msg, btn).then(() => setTimeout(()=>location.reload(), 2000));
}

function toggleSectionFallback(sectionId) {
    const body = document.getElementById(sectionId);
    if (!body) return;
    
    // Find the toggle icon
    const toggleIcon = document.getElementById('toggle-' + sectionId);
    
    // Toggle collapsed state
    body.classList.toggle('collapsed');
    
    // Rotate the arrow
    if (toggleIcon) {
        if (body.classList.contains('collapsed')) {
            toggleIcon.style.transform = 'rotate(-90deg)';
        } else {
            toggleIcon.style.transform = 'rotate(0deg)';
        }
    }
}
</script>

</body>
</html>
