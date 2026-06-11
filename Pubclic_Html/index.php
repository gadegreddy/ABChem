<?php
require_once __DIR__ . '/../private/functions.php'; $meta = get_seo_meta(); ?>

<!DOCTYPE html><html><head>
<title><?=e($meta['title'])?></title><link rel="stylesheet" href="styles.css">
</head><body>
<?php include 'header.php'; ?>
<section class="hero"><h1>Precision Chemical Standards</h1>
<p style="color:#e2e8f0; margin-bottom:20px;">High-purity APIs, impurities & intermediates manufactured under GMP standards in Hyderabad.</p>
<a href="catalog" class="btn btn-primary">Browse Catalog →</a></section>

<main style="padding:60px 32px; max-width:1200px; margin:0 auto;">
    <h2 style="margin-bottom:30px; text-align:center;">Why Partner with AB Chem?</h2>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:24px;">
        <div style="background:#fff; padding:24px; border-radius:8px; border:1px solid #e2e8f0;">
            <h3 style="margin-top:0;">🏭 GMP & ISO Compliant</h3><p>All batches manufactured under strict cGMP & ISO 9001 guidelines with full traceability.</p>
        </div>
        <div style="background:#fff; padding:24px; border-radius:8px; border:1px solid #e2e8f0;">
            <h3 style="margin-top:0;">📜 Complete Documentation</h3><p>Certificate of Analysis (CoA), MSDS, NMR, HPLC chromatograms, and regulatory support included.</p>
        </div>
        <div style="background:#fff; padding:24px; border-radius:8px; border:1px solid #e2e8f0;">
            <h3 style="margin-top:0;">🌍 Global Dispatch</h3><p>Reliable cold-chain & ambient shipping from India to 50+ countries with IATA/DGR compliance.</p>
        </div>
        <div style="background:#fff; padding:24px; border-radius:8px; border:1px solid #e2e8f0;">
            <h3 style="margin-top:0;">🔬 R&D Ready</h3><p>Pre-validated InChIKeys, canonical SMILES, and structure search integration for seamless pipeline onboarding.</p>
        </div>
    </div>

    <div style="margin-top:60px; background:var(--bg); padding:40px; border-radius:12px; text-align:center;">
        <h2 style="margin:0 0 16px;">About AB Chem India</h2>
        <p style="max-width:800px; margin:0 auto 20px; color:var(--muted);">
            Founded by industry veterans in Hyderabad's Pharma City, we specialize in high-purity APIs, stable isotopes, and regulated impurities. 
            Our state-of-the-art synthesis & purification facility ensures batch-to-batch consistency for clinical & commercial scale needs.
        </p>
        <a href="about" class="btn btn-outline">Learn More About Us</a>
    </div>
</main>
<?php include 'footer.php'; ?>
</body></html>