<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
include __DIR__ . '/../private/functions.php';

$q          = $_GET['q']           ?? '';
$searchType = $_GET['search_type'] ?? 'auto';
$advMode    = isset($_GET['adv']);
$meta       = get_seo_meta();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $q ? 'Search Results for "' . e($q) . '"' : 'Advanced Search' ?> | AB Chem India</title>
<meta name="description" content="Search AB Chem India's catalog by compound name, CAS number, IUPAC name, InChI Key or synonyms. Batch search multiple compounds at once.">
<link rel="stylesheet" href="/styles.css">
<link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>

<main class="search-page-container">

    <!-- ══════════════════════════════════════════════════════════
         SIMPLE SEARCH HEADER  (shown when a quick search ran)
    ═══════════════════════════════════════════════════════════ -->
    <?php if ($q && !$advMode): ?>
    <div class="search-header-card">
        <h1>Search Results for "<?= e($q) ?>"</h1>
        <div class="search-header-meta">
            <p class="search-meta-type">
                <?php
                $typeLabels = [
                    'cas'        => '🔬 CAS Number',
                    'inchikey'   => '🔬 InChIKey',
                    'iupac_name' => '🔬 IUPAC Name',
                    'synonym'    => '🔬 Synonym',
                    'ab_catalog' => '🏷️ ABChem Catalog No.',
                    'keyword'    => '📝 Keyword Search',
                ];
                echo $typeLabels[$searchType] ?? '📝 Keyword Search';
                ?>
                &nbsp;·&nbsp;
                <a href="/search?adv=1" class="adv-toggle-link">🔧 Advanced / Batch Search</a>
            </p>
            <div class="adv-match-pills" id="simple-match-pills" style="margin-top:8px;">
                <button type="button" class="adv-match-pill active" data-mode="any">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Any match
                </button>
                <button type="button" class="adv-match-pill" data-mode="exact">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px"><polyline points="20 6 9 17 4 12"/></svg>
                    Exact match
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         ADVANCED SEARCH PANEL
    ═══════════════════════════════════════════════════════════ -->
    <div class="adv-search-panel <?= ($advMode || !$q) ? 'adv-open' : '' ?>" id="adv-panel">

        <div class="adv-panel-header">
            <span class="adv-panel-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Advanced Compound Search
            </span>
            <?php if ($q && !$advMode): ?>
            <button class="adv-close-btn" id="adv-close-btn" title="Close">✕</button>
            <?php endif; ?>
        </div>

        <div class="adv-panel-body">

            <!-- ── Search fields hint ── -->
            <div class="adv-fields-hint">
                <span class="adv-fields-hint-label">Searches across:</span>
                <span class="adv-fields-pill">Compound Name</span>
                <span class="adv-fields-pill">IUPAC Name</span>
                <span class="adv-fields-pill">Synonyms</span>
                <span class="adv-fields-pill">CAS Number</span>
                <span class="adv-fields-pill">InChI Key</span>
            </div>

            <!-- ── Match mode toggle ── -->
            <div class="adv-match-row">
                <span class="adv-match-label">Match mode:</span>
                <div class="adv-match-pills" id="adv-match-pills">
                    <button type="button" class="adv-match-pill active" data-mode="any">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        Any match
                    </button>
                    <button type="button" class="adv-match-pill" data-mode="exact">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px"><polyline points="20 6 9 17 4 12"/></svg>
                        Exact match
                    </button>
                </div>
                <span class="adv-match-hint" id="adv-match-hint-any">Partial substring — finds all compounds containing the term</span>
                <span class="adv-match-hint" id="adv-match-hint-exact" style="display:none">Full-word — CAS/InChIKey must match exactly; name/synonym must match in full</span>
            </div>

            <!-- ── Multi-line search textarea ── -->
            <div class="adv-row">
                <label class="adv-section-label" for="adv-batch-input">
                    Enter one compound per line
                    <span class="adv-hint">— mix any identifier type, one per row</span>
                </label>
                <textarea id="adv-batch-input" class="adv-textarea" rows="6"
                    placeholder="Atazanavir&#10;73-40-5&#10;Albendazole Sulfone&#10;YVPYQGTVKBYREQ-UHFFFAOYSA-N&#10;Omeprazole impurity B"><?= $advMode && $q ? e($q) : '' ?></textarea>
                <div class="adv-batch-footer">
                    <span class="adv-batch-count" id="adv-batch-count">0 terms</span>
                    <button class="btn btn-primary" id="adv-search-btn">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:5px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        Search Compounds
                    </button>
                </div>
            </div>

        </div><!-- /.adv-panel-body -->
    </div><!-- /.adv-search-panel -->

    <?php if ($q && !$advMode): ?>
    <button class="adv-toggle-btn" id="adv-toggle-btn">🔧 Advanced / Batch Search</button>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         RESULTS CONTAINER  (populated by search.js)
    ═══════════════════════════════════════════════════════════ -->
    <div id="search-results">
        <?php if ($q && !$advMode): ?>
        <div class="chrom-loader" role="status" aria-live="polite">
            <svg class="chrom-loader__svg" viewBox="0 0 220 70" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <line class="chrom-loader__axis" x1="0" y1="60" x2="220" y2="60"/>
                <path class="chrom-loader__trace" pathLength="100" d="M0,60 L25,60 C35,60 38,18 45,18 C52,18 55,60 65,60 L95,60 C105,60 108,5 115,5 C122,5 125,60 135,60 L160,60 C168,60 171,35 175,35 C179,35 182,60 190,60 L220,60"/>
            </svg>
            <span class="chrom-loader__label">Searching for "<?= e($q) ?>"…</span>
        </div>
        <?php else: ?>
        <div class="adv-empty-state">
            <div class="adv-empty-icon">🔬</div>
            <p>Paste your compound list above and click <strong>Search Compounds</strong>.</p>
        </div>
        <?php endif; ?>
    </div>

</main>

<?php include 'footer.php'; ?>
<script src="/js/utils.js" defer></script>
<script>
    window.SEARCH_INIT = {
        q:          <?= json_encode($q) ?>,
        searchType: <?= json_encode($searchType) ?>,
        advMode:    <?= json_encode((bool)$advMode) ?>
    };
</script>
<script src="/js/search.js" defer></script>
</body>
</html>
