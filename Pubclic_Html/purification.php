<?php
include __DIR__ . '/../private/functions.php';
$meta = get_seo_meta();
?>
<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purification Services | AB Chem India</title>
    <meta name="description" content="<?=e($meta['description'])?>">
    <link rel="stylesheet" href="/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        .pur-hero { background: linear-gradient(135deg, #0f172a 0%, var(--accent) 100%); padding: 80px 32px; color: #fff; text-align: center; }
        .pur-hero h1 { font-size: 2.4rem; margin-bottom: 12px; }
        .pur-hero p { max-width: 750px; margin: 0 auto 24px; color: rgba(255,255,255,0.85); font-size: 1.05rem; }
        .tech-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; margin-top: 30px; }
        .tech-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; transition: 0.2s; }
        .tech-card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
        .tech-card h3 { color: var(--primary); font-size: 1.05rem; margin-bottom: 6px; }
        .tech-card p { color: var(--muted); font-size: 0.85rem; line-height: 1.5; }
        .outcomes-table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); margin-top: 20px; }
        .outcomes-table th { background: var(--primary); color: #fff; padding: 10px; text-align: left; font-weight: 500; font-size: 0.9rem; }
        .outcomes-table td { padding: 10px; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .workflow { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 30px; counter-reset: wf; }
        .wf-step { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; position: relative; counter-increment: wf; }
        .wf-step::before { content: counter(wf); position: absolute; top: -10px; right: 16px; background: var(--primary); color: #fff; width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600; }
        .wf-step h4 { color: var(--text); margin-bottom: 6px; }
        .wf-step p { color: var(--muted); font-size: 0.85rem; }
        @media(max-width:768px) { .pur-hero h1 { font-size: 1.8rem; } }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <section class="pur-hero">
        <h1>Purification Services</h1>
        <p>Expert contract purification — from small-scale chromatographic polishing to multi-gram preparative isolation. We recover and refine your compound to the purity your project demands.</p>
        <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
            <a href="#inquiry" class="btn btn-primary" style="background:#fff; color:var(--primary);">Request Purification</a>
            <a href="#techniques" class="btn btn-outline" style="border-color:#fff; color:#fff;">View Techniques</a>
        </div>
    </section>

    <section class="section" id="techniques" style="max-width:1200px; margin:0 auto;">
        <h2 class="section-title">Purification Techniques</h2>
        <p class="section-sub">We select and combine methods based on compound properties, scale requirements, and target purity specifications.</p>
        <div class="tech-grid">
            <div class="tech-card"><h3>🧫 Preparative HPLC</h3><p>Reverse-phase & normal-phase for high-resolution separation of closely eluting impurities. Suitable for polar, non-polar, and chiral compounds.</p></div>
            <div class="tech-card"><h3>🌊 Flash Chromatography</h3><p>Automated flash purification on silica or reverse-phase. Cost-effective for crude reaction mixtures at gram to multi-gram scale.</p></div>
            <div class="tech-card"><h3>❄️ Recrystallisation</h3><p>Solvent & anti-solvent techniques for scalable, cost-effective upgrade of crystalline solids. Preferred final polishing for API intermediates.</p></div>
            <div class="tech-card"><h3>🧲 Ion Exchange</h3><p>Cation/anion exchange resins for removal of ionic impurities, inorganic salts, and residual metals from pharmaceutical intermediates.</p></div>
            <div class="tech-card"><h3>🌀 Size Exclusion</h3><p>Molecular-size-based separation for removal of oligomers, polymers, or high-MW byproducts from small-molecule compounds.</p></div>
            <div class="tech-card"><h3>♨️ Distillation & Extraction</h3><p>Vacuum/fractional distillation & liquid-liquid extraction with pH-controlled phase separation for multi-component mixtures.</p></div>
        </div>
    </section>

    <section class="section" style="background:var(--bg); border-radius:var(--radius); max-width:1200px; margin:0 auto;">
        <h2 class="section-title">Applications & Achievable Purities</h2>
        <table class="outcomes-table">
            <thead><tr><th>Compound Type</th><th>Starting Purity</th><th>Achieved Purity</th><th>Recommended Technique</th><th>Typical Scale</th></tr></thead>
            <tbody>
                <tr><td>API crude (non-chiral)</td><td>85 – 95%</td><td>≥ 99.0%</td><td>Flash + Recrystallisation</td><td>g – kg</td></tr>
                <tr><td>Chiral API / enantiomer</td><td>80 – 90% ee</td><td>≥ 99% ee</td><td>Chiral Prep-HPLC</td><td>mg – g</td></tr>
                <tr><td>Pharm. impurity standard</td><td>70 – 90%</td><td>≥ 98.0%</td><td>Preparative HPLC</td><td>mg – g</td></tr>
                <tr><td>Synthesis intermediate</td><td>75 – 92%</td><td>≥ 97.0%</td><td>Flash chromatography</td><td>g – kg</td></tr>
                <tr><td>Residual metal removal</td><td>> 500 ppm</td><td>< 5 ppm</td><td>Ion Exchange / Scavenger</td><td>g – kg</td></tr>
            </tbody>
        </table>
    </section>

    <section class="section" style="max-width:1200px; margin:0 auto;">
        <h2 class="section-title">Our Workflow</h2>
        <p class="section-sub">A straightforward process designed to minimise turnaround time and maximise recovery.</p>
        <div class="workflow">
            <div class="wf-step"><h4>1. Sample Submission</h4><p>Send crude material (min 50 mg) with HPLC trace, structure, and purity target.</p></div>
            <div class="wf-step"><h4>2. Analytical Assessment</h4><p>HPLC/TLC/NMR profiling to select the optimal purification strategy.</p></div>
            <div class="wf-step"><h4>3. Method Scouting</h4><p>Small-scale trials confirm separation before full-scale commitment.</p></div>
            <div class="wf-step"><h4>4. Purification Run</h4><p>Full-scale run with fraction collection and real-time UV monitoring.</p></div>
            <div class="wf-step"><h4>5. QC & Characterisation</h4><p>Verified by analytical HPLC, NMR, MS. Solvent content checked by NMR/KF.</p></div>
            <div class="wf-step"><h4>6. Delivery</h4><p>Shipped with full CoA, analytical data pack, and recovery yield report.</p></div>
        </div>
    </section>

    <section class="section" id="inquiry" style="background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:40px; max-width:1200px; margin:0 auto 60px;">
        <h2 class="section-title">Request Purification Service</h2>
        <p class="section-sub">Submit your sample details and target purity. Our team will get back to you within 2 business days.</p>
        <form method="post" onsubmit="handlePurSubmit(event)">
            <div class="form-grid">
                <div class="form-group"><label class="filter-label">Your Name *</label><input type="text" name="name" required class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Organisation *</label><input type="text" name="org" required class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Email *</label><input type="email" name="email" required class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Phone</label><input type="tel" name="phone" class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Compound Name / CAS *</label><input type="text" name="compound" required class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Sample Amount Available</label><input type="text" name="amount" class="filter-input" placeholder="e.g. 2.5 g"></div>
                <div class="form-group"><label class="filter-label">Target Purity *</label>
                    <select name="target_purity" required class="filter-input"><option>≥ 95%</option><option>≥ 97%</option><option>≥ 98%</option><option>≥ 99%</option><option>≥ 99.5%</option></select>
                </div>
                <div class="form-group" style="grid-column: 1/-1;"><label class="filter-label">Known Impurities / Additional Notes</label><textarea name="notes" rows="4" class="filter-input" placeholder="Specific impurities to remove, solvent restrictions, etc."></textarea></div>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:20px; width:100%;">Send Purification Request →</button>
        </form>
    </section>
</main>
<?php include 'footer.php'; ?>
<script>
function handlePurSubmit(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.textContent = 'Request Received ✔';
    btn.disabled = true;
    // Add actual submission logic (AJAX/mail) here
}
</script>
</body>
</html>