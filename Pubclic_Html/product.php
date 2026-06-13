<?php
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/currency_rates.php'; // live EUR/INR rates (cached, auto-refreshes)

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = Database::getInstance();

// ── Extract raw URL key from path or GET param ────────────────────────────────
// .htaccess strips the name-slug segment for two-segment URLs, so $_GET['slug']
// is always the catalog-token (e.g. "ABC00008ZG-D910AB") in both URL formats.
// We also detect whether the visitor arrived via a legacy single-segment URL so
// we can 301-redirect them to the new /product/name-slug/TOKEN format.
$urlKey     = '';
$path       = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$isLegacyUrl = (bool) preg_match('#^/product/[^/]+$#', $path);  // no name-slug prefix

if (isset($_GET['slug']) && !empty($_GET['slug'])) {
    $urlKey = trim($_GET['slug']);
}
if (empty($urlKey)) {
    if (preg_match('#/product/([^/?]+)#', $path, $m)) {
        $urlKey = $m[1];
    }
}
$urlKey = rtrim(urldecode($urlKey), '/');
$urlKey = preg_replace('/[<>"\'\{\}\|\\\\\^\~\[\]]/', '', $urlKey);

if (empty($urlKey)) {
    header('Location: /catalog');
    exit;
}

// ── 1. Try new opaque URL format: ABC00032PC-7E23F8 ───────────────────────────
$p = getProductByToken($urlKey);

// ── 2. Backward-compat: old slug format ──────────────────────────────────────
if (!$p) {
    $p = getProductBySlug($urlKey);

    // If found by old slug AND the compound now has a token, redirect permanently
    if ($p && !empty($p['ab_catalog_number']) && !empty($p['url_token'])) {
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . buildProductUrl($p));  // builds new /name-slug/TOKEN format
        exit;
    }
}

// ── 3. Compound redirects: was this URL a merged-away compound? ───────────────
// admin_dedup.php writes one row per merged compound covering all four legacy
// URL surfaces (slug, url_slug, ab_catalog_number, url_token). If any matches,
// 301-redirect to the keeper's modern URL. Runs BEFORE the fuzzy name search
// so a deterministic merge target wins over a guess.
if (!$p) {
    // Extract the catalog portion from a "ABC00008ZG-D910AB" key, if applicable
    $abCatalogOnly = preg_match('/^(ABC\d{5}[A-Z]{2})-/i', $urlKey, $m) ? strtoupper($m[1]) : null;

    $redirect = $db->fetchOne(
        "SELECT new_compound_id
         FROM compound_redirects
         WHERE old_slug      = :k1
            OR old_url_slug  = :k2
            OR old_url_token = :k3
            OR (:ab1 IS NOT NULL AND old_ab_catalog = :ab2)
         ORDER BY merged_at DESC
         LIMIT 1",
        [
            'k1'  => $urlKey,
            'k2'  => $urlKey,
            'k3'  => $urlKey,
            'ab1' => $abCatalogOnly,
            'ab2' => $abCatalogOnly,
        ]
    );
    if ($redirect) {
        $newRow = $db->fetchOne(
            "SELECT id, slug, url_slug, ab_catalog_number, url_token, compound_name AS product_name
             FROM compounds WHERE id = :id",
            ['id' => $redirect['new_compound_id']]
        );
        if ($newRow) {
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . buildProductUrl($newRow));
            exit;
        }
    }
}

// ── 4. Last resort: name-based search → redirect ──────────────────────────────
if (!$p) {
    $searchName = str_replace(['-', '_'], ' ', $urlKey);
    $row = $db->fetchOne(
        "SELECT id, slug, ab_catalog_number, url_token, compound_name AS product_name
         FROM compounds WHERE compound_name LIKE :name AND status = 'Active' LIMIT 1",
        ['name' => "%{$searchName}%"]
    );
    if ($row) {
        $dest = buildProductUrl($row);  // builds new /name-slug/TOKEN format
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $dest);
        exit;
    }
}

// If still not found, show 404
if (!$p) {
    http_response_code(404);
    
    // Get search term for display
    $searchTerm = str_replace(['-', '_'], ' ', $urlKey);
    $searchTerm = urldecode($searchTerm);
    
    // Search for similar compounds to suggest
    $suggestions = $db->fetchAll(
        "SELECT slug, url_slug, ab_catalog_number, url_token, compound_name AS product_name
         FROM compounds WHERE compound_name LIKE :name AND status = 'Active' LIMIT 5",
        ['name' => "%{$searchTerm}%"]
    );
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Product Not Found | AB Chem India</title>
        <link rel="stylesheet" href="/styles.css">
        <link rel="icon" type="image/png" href="/logo.png">
        
        
    </head>
    <body>
    <?php include 'header.php'; ?>
    <main>
        <div class="not-found-container">
            <div class="not-found-icon">🔍</div>
            <h1 class="not-found-title">Product Not Found</h1>
            <p class="not-found-message">
                We couldn't find "<strong><?= e($searchTerm) ?></strong>" in our database.<br>
                It may have been discontinued, renamed, or the URL might be incorrect.
            </p>
            
            <?php if (!empty($suggestions)): ?>
            <div class="suggestions-list">
                <strong style="color:#0f172a;">Did you mean:</strong>
                <ul style="margin-top:8px; padding-left:20px;">
                    <?php foreach ($suggestions as $suggestion): ?>
                    <li>
                        <a href="<?= e(buildProductUrl($suggestion)) ?>">
                            <?= e($suggestion['product_name']) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <div class="action-buttons">
                <a href="/catalog" class="btn btn-primary">Browse Full Catalog</a>
                <a href="/search?q=<?= urlencode($searchTerm) ?>" class="btn btn-outline">
                    Search for "<?= e($searchTerm) ?>"
                </a>
                <a href="/contact" class="btn btn-outline">Contact Support</a>
            </div>
        </div>
    </main>
    <?php include 'footer.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

// ── Redirect legacy single-segment URLs to new name-slug/TOKEN format ────────
// e.g. /product/ABC00008ZG-D910AB  →  /product/aprepitant-ep-impurity-e/ABC00008ZG-D910AB
if ($isLegacyUrl && !empty($p['url_slug']) &&
    !empty($p['ab_catalog_number']) && !empty($p['url_token'])) {
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . buildProductUrl($p));
    exit;
}

// Product found - track view if user is logged in
if (isset($_SESSION['user_id'])) {
    trackProductView($_SESSION['user_id'], $p['id']);
}

// Check if product is saved by current user
$isSaved = false;
if (isset($_SESSION['user_id'])) {
    $saved = $db->fetchOne(
        "SELECT id FROM saved_products WHERE user_id = :uid AND product_id = :pid",
        ['uid' => $_SESSION['user_id'], 'pid' => $p['id']]
    );
    $isSaved = !empty($saved);
}

// Handle POST actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $action = $_POST['action'] ?? '';
    $userId = $_SESSION['user_id'];
    $productId = $p['id'];
    
    // Toggle Save/Favorite
    if ($action === 'toggle_save') {
        try {
            $existing = $db->fetchOne(
                "SELECT id FROM saved_products WHERE user_id = :uid AND product_id = :pid",
                ['uid' => $userId, 'pid' => $productId]
            );
            
            if ($existing) {
                $db->delete('saved_products', 
                    'user_id = :uid AND product_id = :pid',
                    ['uid' => $userId, 'pid' => $productId]
                );
                $message = 'Product removed from saved list.';
                $isSaved = false;
            } else {
                $db->insert('saved_products', [
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $message = 'Product added to saved list!';
                $isSaved = true;
            }
        } catch (Exception $e) {
            $error = 'Unable to update saved products.';
        }
    }
    
    // Add to Cart
    if ($action === 'add_to_cart') {
        $quantity = max(1, floatval($_POST['quantity'] ?? 1));
        $unit = $_POST['unit'] ?? 'mg';
        
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        $cartKey = $productId . '-' . $unit;
        
        if (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$cartKey] = [
                'product_id' => $productId,
                'product_name' => $p['product_name'],
                'slug' => $p['slug'],
                'cas_number' => $p['cas_number'],
                'purity' => $p['purity'],
                'quantity' => $quantity,
                'unit' => $unit,
                'added_at' => date('Y-m-d H:i:s')
            ];
        }
        
        $message = "Added {$quantity} {$unit} to cart!";
    }
    
    // Quick Order / Request Quote
    if ($action === 'quick_order') {
        $quantity   = max(1, floatval($_POST['order_quantity'] ?? 1));
        $unit       = $_POST['order_unit'] ?? 'mg';
        $notes      = trim($_POST['order_notes'] ?? '');
        $supplierId = intval($_POST['supplier_id'] ?? 0);

        // Enrich notes with chosen supplier details (admin)
        if ($supplierId > 0) {
            $supListing = $db->fetchOne(
                "SELECT s.supplier_name, sl.catalog_number, sl.purity, sl.lead_time
                 FROM suppliers s
                 JOIN supplier_listings sl ON sl.supplier_id = s.id
                 WHERE s.id = :sid AND sl.compound_id = :cid AND sl.status = 'Active' LIMIT 1",
                ['sid' => $supplierId, 'cid' => $p['id']]
            );
            if ($supListing) {
                $supNote = 'Supplier: ' . $supListing['supplier_name'];
                if ($supListing['catalog_number']) $supNote .= ' | Cat#: ' . $supListing['catalog_number'];
                if ($supListing['purity'])         $supNote .= ' | Purity: ' . $supListing['purity'];
                if ($supListing['lead_time'])      $supNote .= ' | Lead time: ' . $supListing['lead_time'];
                $notes = $supNote . ($notes ? ' | ' . $notes : '');
            }
        }
        
        // Create quote request
        $quoteNumber = 'QTE-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        $quoteId = $db->insert('quote_requests', [
            'quote_number' => $quoteNumber,
            'user_id' => $userId,
            'subject' => "Quote Request: " . $p['product_name'],
            'status' => 'new',
            'priority' => 'medium',
            'notes' => $notes,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Add quote item
        $db->insert('quote_items', [
            'quote_id' => $quoteId,
            'product_id' => $productId,
            'product_name' => $p['product_name'],
            'cas_number' => $p['cas_number'],
            'quantity' => $quantity,
            'unit' => $unit,
            'notes' => "Quick order from product page",
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Create notification
        createNotification($userId, 'quote', 'Quote Request Created', 
            "Your quote request #{$quoteNumber} for {$p['product_name']} has been submitted.", 
            "/dashboard?tab=quotes");
        
                // Send WhatsApp notification to supplier if available, otherwise admin
        $wa_message = "New Quote Request #{$quoteNumber} for {$p['product_name']}. Qty: {$quantity} {$unit}.";
        if (!empty($notes)) {
            $wa_message .= " Notes: {$notes}";
        }

        if ($supplierId > 0) {
            // Get supplier phone number
            $supplierData = $db->fetchOne("SELECT contact_phone FROM suppliers WHERE id = :sid", ['sid' => $supplierId]);
            if ($supplierData && !empty($supplierData['contact_phone'])) {
                sendWhatsAppMessage($supplierData['contact_phone'], $wa_message);
            } else {
                sendWhatsAppMessage(ADMIN_WHATSAPP_PHONE, "Supplier has no phone: " . $wa_message);
            }
        } else {
            sendWhatsAppMessage(ADMIN_WHATSAPP_PHONE, "Unassigned Quote: " . $wa_message);
        }

        logAudit('quick_order', "User created quote #{$quoteNumber} for product: {$p['product_name']}");
        
        $message = "Quote request submitted! Quote #: {$quoteNumber}";
    }
}

$meta = get_seo_meta($p);

// Admin: fetch ALL listings (active + inactive) for the supplier panel
$isAdmin       = isset($_SESSION['role']) && checkRole('Admin');
$adminListings = [];
if ($isAdmin) {
    $adminListings = $db->fetchAll(
        "SELECT sl.*, s.supplier_name AS company_make, s.supplier_code
         FROM supplier_listings sl
         JOIN suppliers s ON s.id = sl.supplier_id
         WHERE sl.compound_id = :id
         ORDER BY (sl.status = 'Active') DESC, s.supplier_name, sl.purity",
        ['id' => $p['id']]
    );
}

// Parse synonyms
$synonyms = [];
$rawSyn = $p['synonyms'] ?? '';

if (!empty($rawSyn) && $rawSyn !== 'NA') {
    // Split by pipe delimiter
    $synonyms = explode('|', $rawSyn);
    $synonyms = array_map('trim', $synonyms);
    $synonyms = array_filter($synonyms, function($s) {
        return !empty($s) && $s !== 'NA' && strlen($s) > 1;
    });
    $synonyms = array_slice(array_values($synonyms), 0, 10);
}

// Get related products
$related = getRelatedProducts($p['id'], $p['product_type'] ?? '', 4);

// Get cart count
$cartCount = 0;
if (isset($_SESSION['cart'])) {
    $cartCount = count($_SESSION['cart']);
}
 

   
   
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($meta['title']) ?></title>
    <meta name="description" content="<?= e($meta['description']) ?>">
    <link rel="canonical" href="<?= e(compoundPublicUrl($p) ?? '/catalog') ?>">
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">

    <?php
    // FEAT-30: schema.org/ChemicalSubstance JSON-LD for Google rich snippets
    $jsonLd = [
        '@context'    => 'https://schema.org',
        '@type'       => 'ChemicalSubstance',
        'name'        => $p['compound_name'] ?? '',
        'url'         => compoundPublicUrl($p) ?? 'https://www.abchem.co.in/catalog',
        'description' => $meta['description'] ?? '',
    ];
    if (!empty($p['cas_number'])        && $p['cas_number']        !== 'NA') $jsonLd['identifier']      = $p['cas_number'];
    if (!empty($p['iupac_name'])        && $p['iupac_name']        !== 'NA') $jsonLd['iupacName']        = $p['iupac_name'];
    if (!empty($p['molecular_formula']) && $p['molecular_formula'] !== 'NA') $jsonLd['molecularFormula'] = $p['molecular_formula'];
    if (!empty($p['molecular_weight'])  && $p['molecular_weight']  !== 'NA') $jsonLd['molecularWeight']  = (string)$p['molecular_weight'];
    if (!empty($p['inchi_key'])         && $p['inchi_key']         !== 'NA') $jsonLd['inChIKey']         = $p['inchi_key'];
    if (!empty($p['smiles'])            && $p['smiles']            !== 'NA') $jsonLd['smiles']           = $p['smiles'];
    if (!empty($p['synonyms'])          && $p['synonyms']          !== 'NA') {
        $synArr = array_values(array_filter(array_map('trim', explode('|', $p['synonyms']))));
        if ($synArr) $jsonLd['alternateName'] = $synArr;
    }
    if (!empty($p['pubchem_cid'])) {
        $jsonLd['sameAs'] = 'https://pubchem.ncbi.nlm.nih.gov/compound/' . $p['pubchem_cid'];
    }
    ?>
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>

</head>
<body>
<?php include 'header.php'; ?>

<div class="product-container">
    
    <!-- Breadcrumb -->
    <nav style="font-size: 14px; color: #64748b; margin-bottom: 20px;">
        <a href="/" style="color: #0284c7; text-decoration: none;">Home</a> ›
        <a href="/catalog" style="color: #0284c7; text-decoration: none;">Catalog</a> ›
        <?= e($p['product_name']) ?>
    </nav>
    
    <?php if ($message): ?>
        <div class="message message-success">✅ <?= e($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="message message-error">❌ <?= e($error) ?></div>
    <?php endif; ?>
    
    <?php
    // Pre-compute display data for the redesigned compact card
    $hasImage     = !empty($p['image_url']) && $p['image_url'] !== 'NA';
    $casNum       = trim((string)($p['cas_number'] ?? ''));
    $casVerified  = $p['cas_verified'] ?? 'unchecked';
    $casOtherList = [];
    if (!empty($p['cas_other']) && $p['cas_other'] !== 'NA') {
        $casOtherList = array_values(array_filter(array_map('trim', explode('|', $p['cas_other']))));
    }
    $pubchemCid   = !empty($p['pubchem_cid']) && $p['pubchem_cid'] !== 'NA' ? $p['pubchem_cid'] : '';
    $pharmaLinks  = getPharmacopeiaLinks($casNum ?: null, $p['parent_drug'] ?? null);
    $isInStock    = strtolower($p['availability'] ?? '') === 'in stock';

    // Live exchange rates (cached in private/exchange_rates.json, auto-refreshes every 23h)
    $liveRates = getCurrencyRates();

    // Fetch official catalogs (USP/EP) — STRICTLY by CAS number.
    //
    // Match is by this compound's CAS plus any alternate CAS in cas_other (same
    // molecule, e.g. salt/hydrate forms). We deliberately do NOT fall back to a
    // name LIKE match: "ACEBUTOLOL" would match "ACEBUTOLOL IMPURITY A/B/C…",
    // pulling every impurity standard onto the parent compound's page. A USP/EP
    // standard is identified by its CAS — name matching produces false positives.
    $syncList = [];
    if (!empty($casNum) && $casNum !== 'NA') {
        $casParams = [$casNum];
        if (!empty($casOtherList)) {
            $casParams = array_merge($casParams, $casOtherList);
        }
        // Normalise + dedupe so e.g. a duplicated CAS doesn't widen the IN() list.
        $casParams = array_values(array_unique(array_filter(array_map('trim', $casParams))));

        if (!empty($casParams)) {
            try {
                $in   = implode(',', array_fill(0, count($casParams), '?'));
                $stmt = $db->getPdo()->prepare("
                    SELECT * FROM pharmacopeia_sync_catalog
                    WHERE cas_number IN ($in)
                    ORDER BY standard, price ASC
                ");
                $stmt->execute($casParams);

                // Deduplicate by standard+catalog_number (a CAS could appear twice).
                $seen = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $key = $item['standard'] . '_' . $item['catalog_number'];
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $syncList[] = $item;
                    }
                }
            } catch (Throwable $e) {
                error_log('[product.php] pharmacopeia_sync_catalog lookup skipped: ' . $e->getMessage());
                $syncList = [];
            }
        }
    }

    $badgeMap = [
        'verified'   => ['label' => '✓ Verified',    'class' => 'pd-badge--verified',   'title' => 'CAS verified against PubChem'],
        'multi'      => ['label' => '✓ Verified',    'class' => 'pd-badge--multi',      'title' => 'Verified — PubChem also lists ' . count($casOtherList) . ' alternate CAS'],
        'unverified' => ['label' => '⚠ Unverified',  'class' => 'pd-badge--unverified', 'title' => 'This CAS does not appear in PubChem synonyms'],
        'unchecked'  => null,
    ];
    $casBadge = $badgeMap[$casVerified] ?? null;
    ?>

    <!-- ═════════════════ Compact Product Detail Card ═════════════════ -->
    <div class="pd-main">
        <div class="pd-grid">

            <!-- Left: image + compact action stack -->
            <aside class="pd-aside">
                <div class="pd-image<?= $hasImage ? ' pd-image--zoomable' : '' ?>"
                     <?php if ($hasImage): ?>onclick="openImageZoom('<?= e($p['image_url']) ?>','<?= e(addslashes($p['product_name'])) ?>')" title="Click to zoom structure image"<?php endif; ?>>
                    <?php if ($hasImage): ?>
                        <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['product_name']) ?>"
                             onerror="this.src='/logo.png'; this.style.opacity='0.6'; this.closest('.pd-image').onclick=null; this.closest('.pd-image').classList.remove('pd-image--zoomable');">
                        <span class="zoom-hint">🔍</span>
                    <?php else: ?>
                        <img src="/logo.png" alt="<?= e($p['product_name']) ?>" style="opacity:0.6;padding:40px;">
                    <?php endif; ?>
                </div>

                <!-- Zoom modal (shared with catalog) -->
                <div id="img-zoom-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.75);align-items:center;justify-content:center;cursor:zoom-out" onclick="this.style.display='none'">
                    <img id="img-zoom-large" style="max-width:90vw;max-height:90vh;border-radius:8px;box-shadow:0 24px 60px rgba(0,0,0,.6);background:#fff;padding:8px">
                </div>
                <script>
                function openImageZoom(src, alt) {
                    var m = document.getElementById('img-zoom-modal');
                    document.getElementById('img-zoom-large').src = src;
                    document.getElementById('img-zoom-large').alt = alt;
                    m.style.display = 'flex';
                }
                </script>

                <div class="pd-actions">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <form method="post" style="display:contents;">
                            <input type="hidden" name="action" value="toggle_save">
                            <button type="submit" class="pd-btn pd-btn--save<?= $isSaved ? ' saved' : '' ?>">
                                <?= $isSaved ? '⭐ Saved' : '☆ Save for Later' ?>
                            </button>
                        </form>
                        <form method="post" style="display:contents;" id="addToCartForm">
                            <input type="hidden" name="action" value="add_to_cart">
                            <input type="hidden" name="quantity" value="1">
                            <input type="hidden" name="unit" value="mg">
                            <button type="submit" class="pd-btn pd-btn--cart">🛒 Add to Cart</button>
                        </form>
                    <?php else: ?>
                        <a href="/signin?redirect=<?= urlencode(buildProductUrl($p)) ?>" class="pd-btn pd-btn--save">☆ Sign in to Save</a>
                        <a href="/signin?redirect=<?= urlencode(buildProductUrl($p)) ?>" class="pd-btn pd-btn--cart">🛒 Sign in to Order</a>
                    <?php endif; ?>
                    <a href="/contact?subject=<?= urlencode($p['product_name']) ?>&cas=<?= urlencode($casNum) ?>" class="pd-btn pd-btn--quote">📋 Request Custom Quote</a>
                </div>
            </aside>

            <!-- Right: structured info -->
            <section class="pd-info">

                <header class="pd-head">
                    <?php if (!empty($p['ab_catalog_number'])): ?>
                        <span class="pd-catalog" title="AB Chem unified catalog number"><?= e($p['ab_catalog_number']) ?></span>
                    <?php endif; ?>
                    <h1 class="pd-title"><?= e($p['product_name']) ?></h1>
                    
                    <?php
                    $displayDesc = null;
                    if (!empty($p['description']) && $p['description'] !== 'NA') {
                        $displayDesc = $p['description'];
                    } elseif (!empty($p['meta_description']) && $p['meta_description'] !== 'NA'
                              && !str_starts_with(trim($p['meta_description']), 'Buy ')) {
                        $displayDesc = $p['meta_description'];
                    }
                    if ($displayDesc): ?>
                    <p class="pd-description" style="margin-top:12px; font-size:1rem; color:#475569; line-height:1.6; max-width:800px; padding-bottom: 8px;">
                        <?= nl2br(e($displayDesc)) ?>
                    </p>
                    <?php endif; ?>

                    <span class="pd-avail <?= $isInStock ? 'in-stock' : 'backorder' ?>">
                        <?= e($p['availability'] ?? 'Contact Us') ?>
                    </span>
                </header>

                <!-- Primary identity strip: MF · MW · CAS · PubChem CID -->
                <div class="pd-identity">
                    <div class="pd-field">
                        <span class="pd-k">Molecular Formula</span>
                        <span class="pd-v pd-mono"><?= e($p['molecular_formula'] ?? 'N/A') ?></span>
                    </div>
                    <div class="pd-field">
                        <span class="pd-k">Molecular Weight</span>
                        <span class="pd-v pd-mono"><?= e($p['molecular_weight'] ?? 'N/A') ?></span>
                    </div>
                    <div class="pd-field">
                        <span class="pd-k">CAS Number</span>
                        <span class="pd-cas-row">
                            <span class="pd-v pd-mono"><?= e($casNum !== '' ? $casNum : 'N/A') ?></span>
                            <?php if ($casBadge && $casNum !== ''): ?>
                                <span class="pd-badge <?= $casBadge['class'] ?>" title="<?= e($casBadge['title']) ?>">
                                    <?= $casBadge['label'] ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="pd-field">
                        <span class="pd-k">PubChem CID</span>
                        <?php if ($pubchemCid !== ''): ?>
                            <a class="pd-v pd-mono pd-link" target="_blank" rel="noopener"
                               href="https://pubchem.ncbi.nlm.nih.gov/compound/<?= e($pubchemCid) ?>"
                               title="View on PubChem">
                                <?= e($pubchemCid) ?> ↗
                            </a>
                        <?php else: ?>
                            <span class="pd-v pd-mono">N/A</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Secondary attributes: Purity · Product Type · Parent Drug · Lead Time -->
                <div class="pd-attrs">
                    <div class="pd-field">
                        <span class="pd-k">Purity</span>
                        <span class="pd-v"><?= e($p['purity'] ?? 'N/A') ?></span>
                    </div>
                    <div class="pd-field">
                        <span class="pd-k">Product Type</span>
                        <span class="pd-v"><?= e($p['product_type'] ?? 'N/A') ?></span>
                    </div>
                    <?php if (!empty($p['parent_drug']) && $p['parent_drug'] !== 'NA'): ?>
                    <div class="pd-field">
                        <span class="pd-k">Parent Drug</span>
                        <a class="pd-v pd-link" href="/catalog?parent_drug[]=<?= urlencode($p['parent_drug']) ?>">
                            <?= e($p['parent_drug']) ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="pd-field">
                        <span class="pd-k">Lead Time</span>
                        <span class="pd-v"><?= e($p['lead_time'] ?? 'On Request') ?></span>
                    </div>
                </div>

                <?php if (!empty($p['iupac_name']) && $p['iupac_name'] !== 'NA'): ?>
                <div class="pd-iupac">
                    <span class="pd-k">IUPAC Name</span>
                    <span class="pd-iupac-text"><?= e($p['iupac_name']) ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($casOtherList)): ?>
                <div class="pd-other-cas">
                    <span class="pd-k">Other CAS Numbers <span style="font-weight:500;text-transform:none;letter-spacing:0;font-size:0.66rem;opacity:.75;">(also listed on PubChem)</span></span>
                    <div class="pd-cas-list">
                        <?php foreach ($casOtherList as $altCas): ?>
                            <code><?= e($altCas) ?></code>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($pharmaLinks)): ?>
                <div class="pd-pharma">
                    <span class="pd-k">Pharmacopeia Monographs</span>
                    <div class="pd-pharma-list">
                        <?php foreach ($pharmaLinks as $pl):
                            $href = !empty($pl['official_url'])
                                ? $pl['official_url']
                                : '/pharmacopeia/' . strtolower($pl['standard_code']) . '/' . $pl['compound_slug'];
                            $isExternal = !empty($pl['official_url']);
                        ?>
                        <a class="pd-pharma-link" data-std="<?= e($pl['standard_code']) ?>"
                           href="<?= e($href) ?>"
                           <?= $isExternal ? 'target="_blank" rel="noopener"' : '' ?>
                           title="<?= e($pl['standard_name']) ?><?= !empty($pl['monograph_code']) ? ' — ' . e($pl['monograph_code']) : '' ?>">
                            <?= e($pl['standard_code']) ?><?php if (!empty($pl['monograph_code'])): ?> · <?= e($pl['monograph_code']) ?><?php endif; ?><?= $isExternal ? ' ↗' : '' ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($syncList)): ?>
                <div class="pd-pharma" style="flex-direction: column; align-items: stretch; gap: 12px; margin-top: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span class="pd-k" style="flex-shrink: 0; margin-bottom: 0;">Official Reference Standards</span>
                        <select id="currency-toggle"
                            title="Rates: ECB via frankfurter.app<?= !empty($liveRates['source_date']) ? ' · ' . e($liveRates['source_date']) : '' ?>"
                            style="font-size: 0.8rem; padding: 2px 6px; border-radius: 4px; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; cursor: pointer;">
                            <option value="NATIVE">Native Currency</option>
                            <option value="USD">USD ($)</option>
                            <option value="INR" selected>INR (₹)</option>
                        </select>
                    </div>
                    <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left;">
                            <thead>
                                <tr style="background: #f1f5f9; color: #475569;">
                                    <th style="padding: 8px 12px; font-weight: 600; border-bottom: 1px solid #e2e8f0;">Std</th>
                                    <th style="padding: 8px 12px; font-weight: 600; border-bottom: 1px solid #e2e8f0;">Catalog #</th>
                                    <th style="padding: 8px 12px; font-weight: 600; border-bottom: 1px solid #e2e8f0;">Quantity</th>
                                    <th style="padding: 8px 12px; font-weight: 600; border-bottom: 1px solid #e2e8f0;">Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($syncList as $item): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 8px 12px; font-weight: 600; color: #0284c7;"><?= e($item['standard']) ?></td>
                                    <td style="padding: 8px 12px;">
                                        <a href="<?= e($item['url']) ?>" target="_blank" rel="noopener" style="color: #0369a1; text-decoration: none; display: flex; align-items: center; gap: 4px;" title="<?= e($item['name']) ?>">
                                            <?= e($item['catalog_number']) ?> ↗
                                        </a>
                                    </td>
                                    <td style="padding: 8px 12px; color: #475569;"><?= e($item['quantity'] ?: '—') ?></td>
                                    <td style="padding: 8px 12px; font-family: monospace;" class="sync-price-cell" data-raw-price="<?= e($item['price']) ?>" data-currency="<?= e($item['currency']) ?>">
                                        <?= e($item['price'] ? $item['currency'] . ' ' . $item['price'] : '—') ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <script>
                // Live rates injected server-side from private/exchange_rates.json
                // (ECB via frankfurter.app, refreshed daily by cron_currency_rates.php)
                const rates = {
                    USD_TO_INR: <?= (float)$liveRates['USD_TO_INR'] ?>,
                    EUR_TO_INR: <?= (float)$liveRates['EUR_TO_INR'] ?>,
                    EUR_TO_USD: <?= (float)$liveRates['EUR_TO_USD'] ?>,
                    // rate date: <?= e($liveRates['source_date'] ?: 'fallback') ?>

                };

                function applyPriceCurrency(target) {
                    document.querySelectorAll('.sync-price-cell').forEach(cell => {
                        const rawPrice     = parseFloat(cell.getAttribute('data-raw-price'));
                        const baseCurrency = cell.getAttribute('data-currency');
                        if (!rawPrice || isNaN(rawPrice)) return;

                        if (target === 'NATIVE') {
                            cell.textContent = baseCurrency + ' ' + rawPrice.toFixed(2);
                            return;
                        }

                        let finalPrice, symbol;
                        if (target === 'INR') {
                            // Direct conversion — avoids rounding through USD
                            finalPrice = baseCurrency === 'EUR'
                                ? rawPrice * rates.EUR_TO_INR
                                : rawPrice * rates.USD_TO_INR;
                            symbol = '₹';
                        } else {
                            // USD
                            finalPrice = baseCurrency === 'EUR'
                                ? rawPrice * rates.EUR_TO_USD
                                : rawPrice;
                            symbol = '$';
                        }
                        cell.textContent = symbol + new Intl.NumberFormat('en-IN', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2
                        }).format(finalPrice);
                    });
                }

                // Apply INR on page load (matches the default selected option)
                document.addEventListener('DOMContentLoaded', function() { applyPriceCurrency('INR'); });

                document.getElementById('currency-toggle')?.addEventListener('change', function() {
                    applyPriceCurrency(this.value);
                });
                </script>
                <?php endif; ?>
                
                <!-- Quick Order Section -->
                <?php if (isset($_SESSION['user_id'])): ?>
                <div class="quick-order-section">
                    <h3 style="margin: 0 0 16px; font-size: 18px; color: #0f172a;">⚡ Quick Order / Quote</h3>
                    <form method="post" class="quick-order-form">
                        <input type="hidden" name="action" value="quick_order">
                        
                        <div class="form-group">
                            <label>Quantity</label>
                            <input type="number" name="order_quantity" value="1" min="0.001" step="0.001" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Unit</label>
                            <select name="order_unit">
                                <option value="mg">mg</option>
                                <option value="g">g</option>
                                <option value="kg">kg</option>
                                <option value="mL">mL</option>
                                <option value="L">L</option>
                            </select>
                        </div>
                        
                        <?php if ($isAdmin && !empty($adminListings)): ?>
                        <div class="form-group" style="flex:2;">
                            <label>Source Supplier <span style="font-size:.75rem;color:#94a3b8;font-weight:400">(Admin)</span></label>
                            <select name="supplier_id" id="qs-supplier-select" style="width:100%;">
                                <option value="">— Any available supplier —</option>
                                <?php foreach ($adminListings as $lst):
                                    if ($lst['status'] !== 'Active') continue; ?>
                                <option value="<?= $lst['supplier_id'] ?>"
                                        data-listing-id="<?= $lst['id'] ?>"
                                        data-purity="<?= e($lst['purity'] ?? '') ?>"
                                        data-lead="<?= e($lst['lead_time'] ?? '') ?>">
                                    <?= e($lst['company_make']) ?><?= $lst['purity'] ? ' — '.e($lst['purity']) : '' ?><?= $lst['lead_time'] ? ' — '.e($lst['lead_time']) : '' ?><?= $lst['catalog_number'] ? ' ('.e($lst['catalog_number']).')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="form-group" style="flex: 2;">
                            <label>Notes (Optional)</label>
                            <input type="text" name="order_notes" id="qs-notes" placeholder="e.g., Need CoA, purity requirements">
                        </div>

                        <div class="form-group">
                            <button type="submit" class="action-btn btn-quote" style="padding: 10px 24px;">
                                Submit Request
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         ADMIN SUPPLIER PANEL — only visible to Admin role
         ═══════════════════════════════════════════════════════════ -->
    <?php if ($isAdmin): ?>
    <div style="margin-top:28px;background:#fffbeb;border:1.5px solid #f59e0b;border-radius:12px;padding:20px 24px;">

        <!-- Panel header -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <h3 style="margin:0;font-size:1.05rem;color:#92400e;">🏭 Supplier Listings</h3>
                <span style="background:#f59e0b;color:#fff;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:20px;letter-spacing:.05em;">ADMIN</span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="/admin_products?action=edit&id=<?= $p['id'] ?>"
                   style="font-size:.8rem;padding:5px 12px;background:#fff;border:1px solid #d97706;color:#92400e;border-radius:6px;text-decoration:none;font-weight:600;">
                    ✏️ Edit Compound
                </a>
                <a href="/admin_listings.php"
                   style="font-size:.8rem;padding:5px 12px;background:#f59e0b;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">
                    ⚙️ Manage Listings
                </a>
            </div>
        </div>

        <?php if (empty($adminListings)): ?>
            <p style="color:#92400e;font-size:.875rem;">No supplier listings for this compound.
                <a href="/admin_products?action=edit&id=<?= $p['id'] ?>" style="color:#d97706;">Add one →</a>
            </p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
            <thead>
                <tr style="background:#fef3c7;color:#78350f;text-align:left;">
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">Supplier</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">Catalog #</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">Purity</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">Availability</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">MOQ</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">Lead Time</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">Lot No.</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">Expiry</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">Status</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #fcd34d;white-space:nowrap;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($adminListings as $lst):
                $rowOpacity = $lst['status'] !== 'Active' ? 'opacity:.55;' : '';
                $availColor = match(strtolower($lst['availability'] ?? '')) {
                    'in stock'  => '#16a34a',
                    'backorder' => '#d97706',
                    default     => '#64748b',
                };
            ?>
                <tr style="border-bottom:1px solid #fef3c7;<?= $rowOpacity ?>">
                    <td style="padding:8px 10px;">
                        <strong style="color:#1e293b;"><?= e($lst['company_make']) ?></strong><br>
                        <span style="color:#94a3b8;font-size:.75rem;"><?= e($lst['supplier_code']) ?></span>
                    </td>
                    <td style="padding:8px 10px;">
                        <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:.78rem;">
                            <?= e($lst['catalog_number'] ?? '—') ?>
                        </code>
                    </td>
                    <td style="padding:8px 10px;">
                        <?= e($lst['purity'] ?? '—') ?>
                        <?php if (!empty($lst['purity_by_method'])): ?>
                        <br><span style="color:#94a3b8;font-size:.73rem;"><?= e($lst['purity_by_method']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:8px 10px;">
                        <span style="color:<?= $availColor ?>;font-weight:600;">
                            <?= e($lst['availability'] ?? '—') ?>
                        </span>
                        <?php if (!empty($lst['stock_status']) && $lst['stock_status'] !== 'in_stock'): ?>
                        <br><span style="color:#94a3b8;font-size:.73rem;"><?= e(str_replace('_',' ',ucfirst($lst['stock_status']))) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:8px 10px;white-space:nowrap;">
                        <?= e($lst['min_order_qty'] ?? '—') ?>
                        <?php if (!empty($lst['unit'])): ?><span style="color:#94a3b8"> <?= e($lst['unit']) ?></span><?php endif; ?>
                    </td>
                    <td style="padding:8px 10px;white-space:nowrap;"><?= e($lst['lead_time'] ?? '—') ?></td>
                    <td style="padding:8px 10px;font-size:.78rem;color:#475569;"><?= e($lst['lot_number'] ?? '—') ?></td>
                    <td style="padding:8px 10px;font-size:.78rem;color:#475569;white-space:nowrap;">
                        <?php if (!empty($lst['expiry_date']) && $lst['expiry_date'] !== '0000-00-00'):
                            $daysLeft = (int)round((strtotime($lst['expiry_date']) - time()) / 86400);
                            $expStyle = $daysLeft < 90 ? 'color:#dc2626;font-weight:600;' : '';
                        ?>
                            <span style="<?= $expStyle ?>"><?= e(date('d M Y', strtotime($lst['expiry_date']))) ?></span>
                            <?php if ($daysLeft < 180): ?>
                            <br><span style="color:#dc2626;font-size:.7rem;">⚠ <?= $daysLeft ?> days</span>
                            <?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="padding:8px 10px;">
                        <?php
                        $stBg = $lst['status'] === 'Active' ? '#dcfce7' : '#fee2e2';
                        $stFg = $lst['status'] === 'Active' ? '#166534' : '#991b1b';
                        ?>
                        <span style="background:<?= $stBg ?>;color:<?= $stFg ?>;padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:600;">
                            <?= e($lst['status']) ?>
                        </span>
                    </td>
                    <td style="padding:8px 10px;">
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <?php if ($lst['status'] === 'Active'): ?>
                            <button onclick="adminFillQuote(<?= $lst['supplier_id'] ?>, <?= $lst['id'] ?>, '<?= addslashes($lst['company_make']) ?>', '<?= addslashes($lst['purity'] ?? '') ?>', '<?= addslashes($lst['lead_time'] ?? '') ?>')"
                                    style="font-size:.75rem;padding:3px 9px;background:#f59e0b;color:#fff;border:none;border-radius:5px;cursor:pointer;font-weight:600;">
                                📋 Quote
                            </button>
                            <?php endif; ?>
                            <a href="/admin_listings.php?edit=<?= $lst['id'] ?>"
                               style="font-size:.75rem;padding:3px 9px;background:#fff;border:1px solid #d97706;color:#92400e;border-radius:5px;text-decoration:none;font-weight:600;">
                                ✏️ Edit
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php
        // Show any supplier notes below table
        $notedListings = array_filter($adminListings, fn($l) => !empty($l['supplier_notes']));
        if ($notedListings): ?>
        <div style="margin-top:12px;border-top:1px solid #fcd34d;padding-top:10px;">
            <?php foreach ($notedListings as $lst): ?>
            <p style="margin:4px 0;font-size:.8rem;color:#78350f;">
                <strong><?= e($lst['company_make']) ?>:</strong> <?= e($lst['supplier_notes']) ?>
            </p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php endif; // empty adminListings ?>
    </div><!-- /admin supplier panel -->

    <script>
    function adminFillQuote(supplierId, listingId, supplierName, purity, leadTime) {
        // Select the supplier in the quick-order form dropdown
        var sel = document.getElementById('qs-supplier-select');
        if (sel) {
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value == supplierId) { sel.selectedIndex = i; break; }
            }
        }
        // Pre-fill notes if empty
        var notesEl = document.getElementById('qs-notes');
        if (notesEl && !notesEl.value.trim()) {
            notesEl.value = 'Supplier: ' + supplierName
                + (purity   ? ' | Purity: '    + purity   : '')
                + (leadTime ? ' | Lead time: ' + leadTime : '');
        }
        // Scroll to quick order section and flash it
        var section = document.querySelector('.quick-order-section');
        if (section) {
            section.scrollIntoView({ behavior: 'smooth', block: 'center' });
            section.style.transition = 'outline .2s';
            section.style.outline = '3px solid #f59e0b';
            setTimeout(function() { section.style.outline = 'none'; }, 2000);
        }
    }
    </script>
    <?php endif; // isAdmin ?>

    <!-- Synonyms Section -->
    <?php if (!empty($synonyms)): ?>
    <div class="synonyms-section">
        <h3 style="color: #0f172a; margin: 0 0 8px; font-size: 18px;">📝 Synonyms</h3>
        <div class="synonyms-list">
            <?php foreach ($synonyms as $syn): ?>
            <span class="synonym-tag"><?= e($syn) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Chemical Identifiers -->
    <?php if (!empty($p['smiles']) || !empty($p['inchi']) || !empty($p['inchi_key'])): ?>
    <div class="identifiers-section">
        <h3 style="color: #0f172a; margin: 0 0 16px; font-size: 18px;">🔬 Chemical Identifiers</h3>
        
        <?php
        // PubChem CID is shown at top of compact card now — only structure identifiers here
        $identifiers = [
            'SMILES'   => $p['smiles']    ?? '',
            'InChI'    => $p['inchi']     ?? '',
            'InChIKey' => $p['inchi_key'] ?? '',
        ];
        foreach ($identifiers as $label => $val):
            if (empty($val) || $val === 'NA') continue;
        ?>
        <div class="identifier-item">
            <label><?= e($label) ?></label>
            <code><?= e($val) ?></code>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Related Products -->
    <?php if (!empty($related)): ?>
    <div style="margin-top: 32px;">
        <h3 style="color: #0f172a; margin-bottom: 20px; font-size: 22px;">Related Products</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px;">
        <?php foreach ($related as $r): ?>
        <article style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;">
            <div style="height: 130px; background: #f8fafc; display: flex; align-items: center; justify-content: center; position: relative;">
                <?php if (!empty($r['image_url']) && $r['image_url'] !== 'NA'): ?>
                    <img src="<?= e($r['image_url']) ?>" alt="<?= e($r['product_name']) ?>" style="width:100%; height:100%; object-fit:contain;" loading="lazy">
                <?php else: ?>
                    <img src="/logo.png" 
                             alt="<?= e($p['product_name']) ?>" 
                             style="opacity: 0.6; padding: 40px;">
                <?php endif; ?>
            </div>
            <div style="padding: 14px;">
                <h4 style="font-size: 0.9rem; margin-bottom: 8px;">
                    <a href="<?= e(buildProductUrl($r)) ?>" style="color: #1e293b; text-decoration: none;">
                        <?= e($r['product_name']) ?>
                    </a>
                </h4>
                <div style="font-size: 0.8rem; color: #64748b;">
                    CAS: <?= e($r['cas_number'] ?? 'N/A') ?>
                </div>
                <a href="<?= e(buildProductUrl($r)) ?>" style="display: inline-block; margin-top: 10px; color: var(--accent); font-weight: 600; font-size: 0.85rem;">
                    View Details →
                </a>
            </div>
        </article>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>

<script src="/js/app.js" defer></script>

</body>
</html>