<?php
/**
 * Catalog Page — Dynamic filters, modern sidebar, AJAX product loading
 */
ini_set('log_errors', 1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../private/functions.php';

// Seed initial URL state so PHP-rendered HTML matches first JS load
$initTypes     = isset($_GET['type'])       ? array_map('trim', (array)$_GET['type']) : [];
$initSortField = in_array($_GET['sort_field'] ?? '', ['product_name','purity','molecular_weight','cas_number'])
                 ? $_GET['sort_field'] : 'product_name';
$initSortDir   = ($_GET['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$rawPerPage    = $_GET['per_page'] ?? '20';
$initPerPage   = in_array($rawPerPage, ['10','20','50','100']) ? $rawPerPage : '20';
$initAvail       = isset($_GET['avail'])        ? array_map('trim', (array)$_GET['avail'])        : [];
$initPurityMin   = isset($_GET['purity_min'])   ? (float)$_GET['purity_min']                       : 0;
$initMwMin       = isset($_GET['mw_min'])       ? (float)$_GET['mw_min']                           : 0;
$initMwMax       = isset($_GET['mw_max'])       ? (float)$_GET['mw_max']                           : 0;
$initParentDrugs = isset($_GET['parent_drug'])  ? array_map('trim', (array)$_GET['parent_drug'])   : [];

// ── Parent drug info banner (SSR — shown when exactly one parent drug is filtered) ──
// Rendered server-side so search engines index the overview text (not via AJAX).
$parentDrugInfo  = null;
$parentDrugCount = 0;
if (count($initParentDrugs) === 1) {
    try {
        $db = Database::getInstance();
        $parentDrugInfo = $db->fetchOne(
            "SELECT overview, generated_at FROM parent_drug_info WHERE parent_drug = :pd",
            ['pd' => $initParentDrugs[0]]
        );
        $parentDrugCount = (int)($db->fetchValue(
            "SELECT COUNT(*) FROM compounds WHERE parent_drug = :pd AND status = 'Active'",
            ['pd' => $initParentDrugs[0]]
        ) ?? 0);
    } catch (Throwable $e) {
        // Table not yet created (migration 014 not run) — degrade silently
        error_log('[catalog.php] parent_drug_info lookup skipped: ' . $e->getMessage());
    }
}

// Pass all init state to JS
$initState = json_encode([
    'types'        => $initTypes,
    'sortField'    => $initSortField,
    'sortDir'      => $initSortDir,
    'perPage'      => (int)$initPerPage,
    'avail'        => $initAvail,
    'purityMin'    => $initPurityMin,
    'mwMin'        => $initMwMin,
    'mwMax'        => $initMwMax,
    'parentDrugs'  => $initParentDrugs,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chemical Catalog | AB Chem India</title>
    <meta name="description" content="GMP-compliant APIs, impurities, and reference standards. Fully characterised with CoA, InChIKey and SMILES.">
    <link rel="stylesheet" href="/styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="/logo.png">
    <link rel="canonical" href="https://www.abchem.co.in/catalog">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<!-- FEAT-23: Mobile filter drawer trigger bar ─────────────────────── -->
<div class="mobile-filter-bar" id="mobile-filter-bar">
    <button class="mobile-filter-btn" id="btn-open-drawer" onclick="openFilterDrawer()" aria-expanded="false" aria-controls="sidebar">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="flex-shrink:0"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="16" y2="12"/><line x1="4" y1="18" x2="12" y2="18"/></svg>
        Filters
        <span class="mobile-result-count" id="mobile-result-count"></span>
    </button>
    <div class="mobile-sort-row">
        <select class="mobile-sort-sel" onchange="onSortChange(this.value)" id="mobile-sort-field">
            <option value="product_name">Name ↑↓</option>
            <option value="molecular_weight">MW</option>
            <option value="cas_number">CAS</option>
            <option value="purity">Purity</option>
        </select>
    </div>
</div>
<!-- FEAT-23: Overlay backdrop for drawer -->
<div class="drawer-overlay" id="drawer-overlay" onclick="closeFilterDrawer()"></div>

<div class="catalog-layout">

    <!-- ═══════════════════════════════════════════════════════
         LEFT SIDEBAR — on desktop: static column.
         On mobile (< 1024px): slide-in drawer (FEAT-23).
         All controls rendered by JS after filter_options API.
    ════════════════════════════════════════════════════════════ -->
    <aside class="catalog-sidebar" id="sidebar">
        <!-- FEAT-23: Mobile drawer close button (shown only on mobile) -->
        <div class="drawer-close-row">
            <span class="drawer-title">Filters</span>
            <button class="drawer-close-btn" onclick="closeFilterDrawer()" aria-label="Close filters">&#10005;</button>
        </div>

        <!-- Header -->
        <div class="sb-head">
            <span class="sb-title">Filters</span>
            <button class="sb-clear" id="btn-clear-all" onclick="clearAllFilters()">Clear all</button>
        </div>

        <!-- Active filter chips (populated by JS) -->
        <div class="sb-chips" id="active-chips"></div>

        <!-- ── SORT ─────────────────────────────────────────── -->
        <div class="sb-section open" id="sec-sort">
            <button class="sb-sec-header" onclick="toggleSection(this)">
                <span class="sb-sec-label">Sort by</span>
                <span class="sb-chevron">&#8964;</span>
            </button>
            <div class="sb-sec-body">
                <div class="sort-row">
                    <select class="sort-select" id="sort-field" onchange="onSortChange()">
                        <option value="product_name">Product name</option>
                        <option value="purity">Purity</option>
                        <option value="molecular_weight">Molecular weight</option>
                        <option value="cas_number">CAS number</option>
                    </select>
                    <button class="dir-btn" id="dir-asc"  title="Ascending"  onclick="setSortDir('asc')">&#8593;</button>
                    <button class="dir-btn" id="dir-desc" title="Descending" onclick="setSortDir('desc')">&#8595;</button>
                </div>
            </div>
        </div>

        <!-- ── PRODUCT TYPE (dynamic, rendered by JS) ───────── -->
        <div class="sb-section open" id="sec-type">
            <button class="sb-sec-header" onclick="toggleSection(this)">
                <span class="sb-sec-label">Product type</span>
                <span class="sb-sec-count" id="type-count"></span>
                <span class="sb-chevron">&#8964;</span>
            </button>
            <div class="sb-sec-body">
                <div class="type-pills" id="type-pills">
                    <span class="sb-loading">Loading&hellip;</span>
                </div>
            </div>
        </div>

        <!-- ── PARENT DRUG (dynamic, rendered by JS) ──────── -->
        <div class="sb-section open" id="sec-parent">
            <button class="sb-sec-header" onclick="toggleSection(this)">
                <span class="sb-sec-label">Parent Drug</span>
                <span class="sb-sec-count" id="parent-count"></span>
                <span class="sb-chevron">&#8964;</span>
            </button>
            <div class="sb-sec-body">
                <div class="sb-search-wrap">
                    <svg class="sb-search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="sb-search-input" id="parent-search"
                           placeholder="Search parent drug…" oninput="filterParentDrugList(this.value)" autocomplete="off">
                </div>
                <div class="parent-drug-list" id="parent-drug-list">
                    <span class="sb-loading">Loading&hellip;</span>
                </div>
                <button class="sb-show-more" id="parent-show-more" style="display:none" onclick="toggleParentDrugMore()">
                    Show all <span id="parent-show-more-count"></span>
                </button>
            </div>
        </div>

        <!-- ── AVAILABILITY (dynamic, rendered by JS) ───────── -->
        <div class="sb-section open" id="sec-avail">
            <button class="sb-sec-header" onclick="toggleSection(this)">
                <span class="sb-sec-label">Availability</span>
                <span class="sb-chevron">&#8964;</span>
            </button>
            <div class="sb-sec-body">
                <div class="avail-rows" id="avail-rows">
                    <span class="sb-loading">Loading&hellip;</span>
                </div>
            </div>
        </div>

        <!-- ── PURITY ────────────────────────────────────────── -->
        <div class="sb-section" id="sec-purity">
            <button class="sb-sec-header" onclick="toggleSection(this)">
                <span class="sb-sec-label">Purity</span>
                <span class="sb-chevron">&#8964;</span>
            </button>
            <div class="sb-sec-body">
                <div class="purity-btns" id="purity-btns">
                    <!-- JS renders only thresholds that exist in DB -->
                </div>
                <p class="sb-hint">Minimum purity threshold</p>
            </div>
        </div>

        <!-- ── MOLECULAR WEIGHT ──────────────────────────────── -->
        <div class="sb-section" id="sec-mw">
            <button class="sb-sec-header" onclick="toggleSection(this)">
                <span class="sb-sec-label">Molecular weight</span>
                <span class="sb-chevron">&#8964;</span>
            </button>
            <div class="sb-sec-body">
                <div class="mw-range-row">
                    <input type="number" class="mw-input" id="mw-min" placeholder="Min" min="0" step="1" onchange="onMwChange()">
                    <span class="mw-sep">—</span>
                    <input type="number" class="mw-input" id="mw-max" placeholder="Max" min="0" step="1" onchange="onMwChange()">
                </div>
                <p class="sb-hint" id="mw-range-hint">g/mol</p>
            </div>
        </div>

        <!-- Filters fire automatically on change (FEAT-24). Keep a manual refresh as fallback. -->
        <div class="sb-footer">
            <button class="btn-apply" onclick="applyAndLoad()" title="Manually refresh results">↺ Refresh</button>
        </div>

    </aside>

    <!-- ═══════════════════════════════════════════════════════
         MAIN CONTENT
    ════════════════════════════════════════════════════════════ -->
    <main class="catalog-content">
        <div class="catalog-header">
            <div>
                <h1>Pharma &amp; Specialty Chemical Standards</h1>
                <p class="catalog-sub">GMP-compliant APIs, isotopes, impurities, and intermediates</p>
            </div>
            <div class="result-info">
                <span id="result-range" class="result-range">Loading&hellip;</span>
                <span id="result-count" class="result-count-text"></span>
            </div>
        </div>

        <?php if ($parentDrugInfo && !empty($parentDrugInfo['overview'])): ?>
        <div class="parent-drug-banner" style="
            background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%);
            border: 1px solid #bfdbfe;
            border-left: 4px solid #2563eb;
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 20px;
        ">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                <h2 style="margin:0; font-size:1.15rem; color:#1e3a8a;">
                    💊 <?= e($initParentDrugs[0]) ?>
                </h2>
                <span style="font-size:.8rem; color:#3b82f6; background:#dbeafe; padding:3px 10px; border-radius:20px; white-space:nowrap;">
                    <?= $parentDrugCount ?> compound<?= $parentDrugCount !== 1 ? 's' : '' ?> in catalog
                </span>
            </div>
            <p style="margin:0; font-size:.9rem; line-height:1.7; color:#1e293b;">
                <?= e($parentDrugInfo['overview']) ?>
            </p>
            <?php if (!empty($parentDrugInfo['generated_at'])): ?>
            <p style="margin:10px 0 0; font-size:.75rem; color:#64748b;">
                AI-generated overview · <?= e(date('M Y', strtotime($parentDrugInfo['generated_at']))) ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div id="product-grid" class="product-grid-loading">
            <div class="loading-placeholder"><p>Loading compounds&hellip;</p></div>
        </div>

        <!-- Pagination + per-page strip together -->
        <div class="pagination-bar" id="pagination-bar">
            <div id="pagination"></div>
            <div class="per-page-strip" id="per-page-strip">
                <span class="per-page-label">Per page</span>
                <?php foreach ([10, 20, 50, 100] as $n): ?>
                <button class="pp-btn<?= $n == $initPerPage ? ' active' : '' ?>"
                        data-n="<?= $n ?>" onclick="setPerPage(this)">
                    <?= $n ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<!-- FEAT-29: Honeypot — CSS-hidden from real users; scrapers that follow all links are logged and receive 410 -->
<a href="/catalog-all" tabindex="-1" aria-hidden="true" rel="nofollow" style="position:absolute;left:-9999px;opacity:0;font-size:1px;pointer-events:none;user-select:none">Products</a>

<script>
    // PHP injects initial filter state here so external JS can read it safely
    window.CATALOG_STATE = <?= $initState ?>;
</script>
<script src="/js/app.js" defer></script>
</body>
</html>