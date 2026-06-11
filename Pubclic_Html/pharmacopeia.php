<?php
/**
 * FEAT-31c: Pharmacopeia cross-reference page
 * URL format: /pharmacopeia/{standard}/{compound}
 * e.g. /pharmacopeia/ep/aspirin  →  ?standard=ep&compound=aspirin
 *
 * Shows the monograph entry + all listed impurities, linking to our catalog
 * for any impurity we stock. Links out to official EDQM / USP source pages.
 *
 * Data model:
 *   pharmacopeia_standards  — EP / USP / JP / IP / WHO
 *   pharmacopeia_monographs — one row per parent compound per standard
 *   pharmacopeia_impurities — one row per impurity in a monograph
 *
 * Population strategy: manual curation + api_pharma_enrich.php enrichment.
 * Official EP/USP impurity lists are copyrighted — data is entered manually
 * or sourced from open literature; this page links OUT to official sources.
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../private/functions.php';

$db = Database::getInstance();

// ── Route params ──────────────────────────────────────────────────────────────
$standardCode  = strtoupper(preg_replace('/[^a-zA-Z]/', '', $_GET['standard'] ?? ''));
$compoundSlug  = strtolower(preg_replace('/[^a-z0-9\-]/', '', $_GET['compound'] ?? ''));

if (!$standardCode || !$compoundSlug) {
    header('Location: /catalog');
    exit;
}

// ── Load standard ─────────────────────────────────────────────────────────────
$standard = $db->fetchOne(
    "SELECT * FROM pharmacopeia_standards WHERE code = :c",
    ['c' => $standardCode]
);
if (!$standard) {
    http_response_code(404);
    $errorMsg = "Pharmacopeia standard '{$standardCode}' not found.";
}

// ── Load monograph ────────────────────────────────────────────────────────────
$monograph = null;
$impurities = [];

if ($standard) {
    $monograph = $db->fetchOne(
        "SELECT pm.*, ps.code AS std_code, ps.name AS std_name, ps.base_url AS std_base_url
         FROM pharmacopeia_monographs pm
         JOIN pharmacopeia_standards ps ON ps.id = pm.standard_id
         WHERE pm.standard_id = :sid AND pm.compound_slug = :slug",
        ['sid' => $standard['id'], 'slug' => $compoundSlug]
    );

    if ($monograph) {
        // Load impurities, join to our catalog if we sell them
        $impurities = $db->fetchAll(
            "SELECT pi.*,
                    c.id         AS cat_compound_id,
                    c.slug       AS cat_slug,
                    c.url_slug   AS cat_url_slug,
                    c.ab_catalog_number,
                    c.url_token,
                    c.compound_name AS cat_name,
                    c.purity_note
             FROM pharmacopeia_impurities pi
             LEFT JOIN compounds c
                ON c.id = pi.compound_id AND c.status = 'Active'
             WHERE pi.monograph_id = :mid
             ORDER BY pi.impurity_label ASC, pi.impurity_name ASC",
            ['mid' => $monograph['id']]
        );
    }
}

// ── SEO / meta ────────────────────────────────────────────────────────────────
$pageTitle = $monograph
    ? ($monograph['compound_name'] . ' — ' . $monograph['std_name'] . ' Impurities | AB Chem India')
    : (ucfirst($compoundSlug) . ' ' . $standardCode . ' Pharmacopeia | AB Chem India');

$pageDesc = $monograph
    ? ('Complete list of ' . $monograph['std_name'] . ' impurities for ' . $monograph['compound_name']
       . '. Reference standards available from AB Chem India with CoA.')
    : ('Pharmacopeia impurity reference page for ' . ucfirst($compoundSlug) . '.');

// Helper to build our product URL from impurity row
function impurityProductUrl(array $row): string {
    if (!empty($row['ab_catalog_number']) && !empty($row['url_token'])) {
        $token = rawurlencode($row['ab_catalog_number'] . '-' . $row['url_token']);
        if (!empty($row['cat_url_slug'])) {
            return '/product/' . rawurlencode($row['cat_url_slug']) . '/' . $token;
        }
        return '/product/' . $token;
    }
    if (!empty($row['cat_slug'])) return '/product/' . rawurlencode($row['cat_slug']);
    return '/catalog';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($pageDesc) ?>">
    <link rel="canonical" href="https://www.abchem.co.in/pharmacopeia/<?= e(strtolower($standardCode)) ?>/<?= e($compoundSlug) ?>">
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">

    <?php if ($monograph): ?>
    <script type="application/ld+json"><?= json_encode([
        '@context'    => 'https://schema.org',
        '@type'       => 'Dataset',
        'name'        => $monograph['compound_name'] . ' ' . $monograph['std_code'] . ' Impurities',
        'description' => $pageDesc,
        'url'         => 'https://www.abchem.co.in/pharmacopeia/' . strtolower($standardCode) . '/' . $compoundSlug,
        'creator'     => ['@type' => 'Organization', 'name' => 'AB Chem India'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
    <?php endif; ?>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="product-container" style="max-width:1100px">

    <!-- Breadcrumb -->
    <nav style="font-size:14px;color:#64748b;margin-bottom:20px;">
        <a href="/" style="color:#0284c7;text-decoration:none">Home</a> ›
        <a href="/catalog" style="color:#0284c7;text-decoration:none">Catalog</a> ›
        Pharmacopeia ›
        <?= e($standardCode) ?> ›
        <?= e($monograph['compound_name'] ?? ucfirst(str_replace('-', ' ', $compoundSlug))) ?>
    </nav>

    <?php if (!$standard || !$monograph): ?>
    <!-- ── Monograph not yet in database ─────────────────────────────────── -->
    <div style="background:#fef3c7;border:1.5px solid #f59e0b;border-radius:12px;padding:28px 32px;max-width:680px;">
        <h1 style="font-size:1.4rem;color:#78350f;margin:0 0 12px">
            <?= e(ucfirst(str_replace('-', ' ', $compoundSlug))) ?> —
            <?= $standard ? e($standard['name']) : e($standardCode) ?> Impurities
        </h1>
        <p style="color:#92400e;margin:0 0 16px;line-height:1.7">
            This pharmacopeia entry is not yet in our database.
            We are continuously expanding our reference data.
            If you need this monograph urgently, please contact us.
        </p>
        <a href="/contact?subject=<?= urlencode('Pharmacopeia request: ' . strtoupper($standardCode) . ' ' . $compoundSlug) ?>"
           style="display:inline-block;background:#f59e0b;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;">
            Request this monograph
        </a>
        <?php if ($standard && $standard['base_url']): ?>
        <a href="<?= e($standard['base_url']) ?>" target="_blank" rel="noopener noreferrer"
           style="display:inline-block;margin-left:10px;background:#fff;border:1px solid #d97706;color:#92400e;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;">
            Visit <?= e($standard['code']) ?> official site ↗
        </a>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ── Monograph header ───────────────────────────────────────────────── -->
    <div style="margin-bottom:28px;">
        <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
            <div style="flex:1;min-width:260px;">
                <span style="background:#0284c7;color:#fff;font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.05em;">
                    <?= e($monograph['std_code']) ?>
                </span>
                <h1 style="font-size:1.6rem;color:#0f172a;margin:10px 0 6px;">
                    <?= e($monograph['compound_name']) ?> Impurities
                </h1>
                <p style="color:#64748b;margin:0;">
                    <?= e($monograph['std_name']) ?>
                    <?php if ($monograph['monograph_code']): ?>
                    · Monograph <?= e($monograph['monograph_code']) ?>
                    <?php endif; ?>
                    <?php if ($monograph['cas_number']): ?>
                    · CAS <?= e($monograph['cas_number']) ?>
                    <?php endif; ?>
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <?php if ($monograph['official_url']): ?>
                <a href="<?= e($monograph['official_url']) ?>" target="_blank" rel="noopener noreferrer"
                   style="font-size:.85rem;padding:8px 16px;background:#0284c7;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">
                    Official monograph ↗
                </a>
                <?php elseif ($monograph['std_base_url']): ?>
                <a href="<?= e($monograph['std_base_url']) ?>" target="_blank" rel="noopener noreferrer"
                   style="font-size:.85rem;padding:8px 16px;background:#fff;border:1px solid #cbd5e1;color:#334155;border-radius:8px;text-decoration:none;font-weight:600;">
                    Visit <?= e($monograph['std_code']) ?> ↗
                </a>
                <?php endif; ?>
                <a href="/contact?subject=<?= urlencode($monograph['compound_name'] . ' ' . $monograph['std_code'] . ' impurity quote') ?>"
                   style="font-size:.85rem;padding:8px 16px;background:#f59e0b;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">
                    Request quote
                </a>
            </div>
        </div>

        <?php if ($monograph['pubchem_cid']): ?>
        <p style="margin:12px 0 0;font-size:.85rem;color:#64748b;">
            PubChem CID: <a href="https://pubchem.ncbi.nlm.nih.gov/compound/<?= (int)$monograph['pubchem_cid'] ?>"
                            target="_blank" rel="noopener noreferrer" style="color:#0284c7;">
                <?= (int)$monograph['pubchem_cid'] ?>
            </a>
        </p>
        <?php endif; ?>
    </div>

    <!-- ── Impurity table ────────────────────────────────────────────────── -->
    <?php
    $stocked   = array_filter($impurities, fn($i) => !empty($i['cat_compound_id']));
    $unstocked = array_filter($impurities, fn($i) => empty($i['cat_compound_id']));
    ?>

    <?php if (empty($impurities)): ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:24px;text-align:center;color:#64748b;">
        <p style="margin:0 0 8px;">Impurity data for this monograph is being compiled.</p>
        <a href="/contact?subject=<?= urlencode($monograph['compound_name'] . ' impurity data request') ?>"
           style="color:#0284c7;font-weight:600;">Request impurity list →</a>
    </div>

    <?php else: ?>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
        <thead>
            <tr style="background:#f1f5f9;color:#475569;text-align:left;border-bottom:2px solid #e2e8f0;">
                <th style="padding:10px 12px;white-space:nowrap;">Impurity</th>
                <th style="padding:10px 12px;">Name</th>
                <th style="padding:10px 12px;white-space:nowrap;">CAS</th>
                <th style="padding:10px 12px;white-space:nowrap;">Threshold</th>
                <th style="padding:10px 12px;white-space:nowrap;">Available from AB Chem</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($impurities as $imp): ?>
        <?php
            $hasProduct = !empty($imp['cat_compound_id']);
            $productUrl = $hasProduct ? impurityProductUrl($imp) : null;
        ?>
        <tr style="border-bottom:1px solid #f1f5f9;<?= $hasProduct ? '' : 'color:#94a3b8;' ?>">
            <td style="padding:10px 12px;">
                <?php if ($imp['impurity_label']): ?>
                <span style="background:#dbeafe;color:#1d4ed8;font-weight:700;font-size:.8rem;padding:2px 8px;border-radius:12px;">
                    Imp. <?= e($imp['impurity_label']) ?>
                </span>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td style="padding:10px 12px;font-weight:<?= $hasProduct ? '600' : '400' ?>;">
                <?php if ($productUrl): ?>
                <a href="<?= e($productUrl) ?>" style="color:#0f172a;text-decoration:none;">
                    <?= e($imp['impurity_name'] ?? $imp['cat_name'] ?? '—') ?>
                </a>
                <?php else: ?>
                <?= e($imp['impurity_name'] ?? '—') ?>
                <?php endif; ?>
                <?php if ($imp['notes']): ?>
                <span style="font-size:.78rem;color:#94a3b8;display:block;"><?= e($imp['notes']) ?></span>
                <?php endif; ?>
            </td>
            <td style="padding:10px 12px;font-family:monospace;font-size:.82rem;">
                <?php if ($imp['cas_number']): ?>
                <a href="https://pubchem.ncbi.nlm.nih.gov/#query=<?= urlencode($imp['cas_number']) ?>"
                   target="_blank" rel="noopener noreferrer"
                   style="color:<?= $hasProduct ? '#0284c7' : '#94a3b8' ?>;text-decoration:none;">
                    <?= e($imp['cas_number']) ?>
                </a>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td style="padding:10px 12px;white-space:nowrap;font-size:.82rem;">
                <?php if ($imp['threshold_ppm'] !== null && $imp['threshold_ppm'] !== ''): ?>
                <?= rtrim(rtrim(number_format($imp['threshold_ppm'], 4), '0'), '.') ?> ppm
                <?php if ($imp['threshold_type']): ?>
                <span style="color:#94a3b8;">(<?= e($imp['threshold_type']) ?>)</span>
                <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td style="padding:10px 12px;">
                <?php if ($hasProduct && $productUrl): ?>
                <a href="<?= e($productUrl) ?>"
                   style="display:inline-block;background:#0284c7;color:#fff;padding:4px 14px;border-radius:6px;font-size:.8rem;text-decoration:none;font-weight:600;white-space:nowrap;">
                    <?= e($imp['ab_catalog_number'] ?? 'View') ?> →
                </a>
                <?php else: ?>
                <a href="/contact?subject=<?= urlencode('Request: ' . ($imp['impurity_name'] ?? '') . ' ' . ($imp['cas_number'] ?? '')) ?>"
                   style="display:inline-block;background:#f1f5f9;color:#64748b;padding:4px 14px;border-radius:6px;font-size:.8rem;text-decoration:none;white-space:nowrap;">
                    Enquire
                </a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <p style="margin:12px 0 0;font-size:.8rem;color:#94a3b8;">
        Impurity threshold data is indicative. Always consult the current official pharmacopeia monograph for regulatory purposes.
        <?php if ($monograph['official_url']): ?>
        <a href="<?= e($monograph['official_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:#0284c7;">Official source ↗</a>
        <?php endif; ?>
    </p>
    <?php endif; ?>

    <?php endif; // monograph found ?>

    <!-- Related compound search -->
    <div style="margin-top:36px;padding:20px 24px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
        <h3 style="margin:0 0 10px;font-size:1rem;color:#0f172a;">Looking for a specific standard?</h3>
        <p style="margin:0 0 14px;font-size:.9rem;color:#64748b;">
            Browse our full catalog of reference standards and pharmacopeia impurities.
        </p>
        <a href="/catalog?type[]=API+Impurity&type[]=Reference+Standard"
           style="display:inline-block;background:#0284c7;color:#fff;padding:9px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;">
            Browse Standards & Impurities
        </a>
        <a href="/contact" style="display:inline-block;margin-left:10px;color:#0284c7;font-weight:600;font-size:.9rem;">
            Contact us →
        </a>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
