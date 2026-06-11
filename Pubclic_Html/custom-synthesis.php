<?php
include __DIR__ . '/../private/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$message_sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['synthesis_request'])) {
    $message_sent = true; // Placeholder: integrate mail/DB here
}
$meta = get_seo_meta();
?>
<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Synthesis Services | AB Chem India</title>
    <meta name="description" content="<?=e($meta['description'])?>">
    <link rel="stylesheet" href="/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        .service-hero { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); padding: 80px 32px; color: #fff; text-align: center; }
        .service-hero h1 { font-size: 2.5rem; margin-bottom: 12px; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .service-hero p { max-width: 700px; margin: 0 auto 24px; color: rgba(255,255,255,0.9); font-size: 1.1rem; }
        .service-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; padding: 40px 32px; background: var(--surface); margin-top: -30px; position: relative; border-radius: var(--radius); box-shadow: var(--shadow); max-width: 1100px; margin-left: auto; margin-right: auto; }
        .stat-item { text-align: center; }
        .stat-num { font-size: 1.8rem; font-weight: 700; color: var(--accent); }
        .stat-label { color: var(--muted); font-size: 0.9rem; margin-top: 4px; }
        .section { padding: 60px 32px; max-width: 1200px; margin: 0 auto; }
        .section-title { font-size: 1.8rem; color: var(--primary); margin-bottom: 8px; }
        .section-sub { color: var(--muted); margin-bottom: 32px; max-width: 700px; }
        .cap-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .cap-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; transition: 0.2s; }
        .cap-card:hover { transform: translateY(-4px); box-shadow: var(--shadow); border-color: var(--accent); }
        .cap-card h3 { color: var(--primary); margin-bottom: 8px; font-size: 1.1rem; }
        .cap-card p { color: var(--muted); font-size: 0.9rem; line-height: 1.5; }
        .steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 30px; counter-reset: step; }
        .step { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; position: relative; counter-increment: step; }
        .step::before { content: counter(step); position: absolute; top: -12px; left: 16px; background: var(--accent); color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.85rem; }
        .step h4 { margin-top: 8px; color: var(--text); }
        .step p { color: var(--muted); font-size: 0.85rem; margin-top: 4px; }
        .scale-table { width: 100%; border-collapse: collapse; margin-top: 20px; background: var(--surface); border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); }
        .scale-table th { background: var(--primary); color: #fff; padding: 12px; text-align: left; font-weight: 500; }
        .scale-table td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 20px; }
        @media (max-width: 768px) { .service-hero h1 { font-size: 1.8rem; } .form-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <section class="service-hero">
        <h1>Custom Synthesis Services</h1>
        <p>From milligram discovery compounds to multi-kilogram process batches. We deliver custom-synthesized chemicals with rigorous QA, fast turnaround, and strict confidentiality.</p>
        <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
            <a href="#inquiry" class="btn btn-primary" style="background: #fff; color: var(--accent);">Submit Request</a>
            <a href="#process" class="btn btn-outline" style="border-color: #fff; color: #fff;">How It Works</a>
        </div>
    </section>

    <?php if($message_sent): ?>
    <div style="max-width:1100px; margin:20px auto; padding:16px; background:var(--green-lt); color:var(--green); border-radius:var(--radius); text-align:center; font-weight:500;">
        ✅ Thank you! Your synthesis request has been received. We'll respond within 2 business days.
    </div>
    <?php endif; ?>

    <div class="service-stats">
        <div class="stat-item"><div class="stat-num">500+</div><div class="stat-label">Custom molecules delivered</div></div>
        <div class="stat-item"><div class="stat-num">mg – kg</div><div class="stat-label">Scale flexibility</div></div>
        <div class="stat-item"><div class="stat-num">98%</div><div class="stat-label">On-time delivery</div></div>
        <div class="stat-item"><div class="stat-num">NDA</div><div class="stat-label">Strict confidentiality</div></div>
    </div>

    <section class="section">
        <h2 class="section-title">Synthesis Capabilities</h2>
        <p class="section-sub">Our team handles a broad range of organic transformations, from classical multi-step routes to modern catalytic and flow chemistry methods.</p>
        <div class="cap-grid">
            <div class="cap-card"><h3>API & Impurity Synthesis</h3><p>Active pharmaceutical ingredients, known impurities, metabolites, and degradants for regulatory submissions.</p></div>
            <div class="cap-card"><h3>Building Blocks & Fragments</h3><p>Custom heteroaromatic scaffolds, chiral fragments, and functionalized building blocks for medicinal chemistry.</p></div>
            <div class="cap-card"><h3>Isotopically Labelled Compounds</h3><p>Synthesis of ²H, ¹³C, and ¹⁵N labeled analogs for ADME/PK studies and internal standards.</p></div>
            <div class="cap-card"><h3>Process Chemistry Scale-Up</h3><p>Route optimization, process development, and scale-up from gram to multi-kilogram under GMP-aligned conditions.</p></div>
            <div class="cap-card"><h3>Chiral Synthesis & Resolution</h3><p>Asymmetric synthesis and classical resolution to deliver enantiopure compounds with ee ≥ 99%.</p></div>
            <div class="cap-card"><h3>Reference Standard Synthesis</h3><p>Full characterization of pharmacopoeial and proprietary reference standards with traceable CoA.</p></div>
        </div>
    </section>

    <section class="section" id="process" style="background: var(--bg); border-radius: var(--radius);">
        <h2 class="section-title">How It Works</h2>
        <p class="section-sub">A streamlined, transparent process from enquiry to delivery — keeping you informed at every stage.</p>
        <div class="steps">
            <div class="step"><h4>Submit Request</h4><p>Provide structure (SMILES, CAS), desired quantity, purity, and timeline.</p></div>
            <div class="step"><h4>Feasibility & Quote</h4><p>Chemists evaluate routes within 48h and issue a technical quote with cost/delivery.</p></div>
            <div class="step"><h4>NDA & Kickoff</h4><p>Mutual NDA signed, milestones agreed, dedicated project chemist assigned.</p></div>
            <div class="step"><h4>Synthesis & QC</h4><p>Compound synthesized and characterized by NMR, MS, HPLC, and chiral analysis.</p></div>
            <div class="step"><h4>Delivery & CoA</h4><p>Shipped with full Certificate of Analysis, spectral data, and clear storage labels.</p></div>
        </div>
    </section>

    <section class="section">
        <h2 class="section-title">Scale & Delivery</h2>
        <table class="scale-table">
            <thead><tr><th>Scale</th><th>Quantity</th><th>Typical Timeline</th><th>CoA</th><th>GMP Option</th></tr></thead>
            <tbody>
                <tr><td>Discovery</td><td>1 mg – 500 mg</td><td>2 – 4 weeks</td><td>✔</td><td>—</td></tr>
                <tr><td>Hit-to-Lead</td><td>0.5 g – 100 g</td><td>3 – 6 weeks</td><td>✔</td><td>—</td></tr>
                <tr><td>Lead Opt.</td><td>100 g – 1 kg</td><td>4 – 8 weeks</td><td>✔</td><td>✔</td></tr>
                <tr><td>IND / CTA</td><td>1 kg – 10 kg</td><td>6 – 14 weeks</td><td>✔</td><td>✔</td></tr>
            </tbody>
        </table>
    </section>

    <section class="section" id="inquiry" style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 40px;">
        <h2 class="section-title">Submit a Synthesis Request</h2>
        <p class="section-sub">Tell us about your compound. We'll respond with a feasibility assessment within 2 business days.</p>
        <form method="post" onsubmit="handleFormSubmit(event)">
            <div class="form-grid">
                <div class="form-group"><label class="filter-label">First Name *</label><input type="text" name="first_name" required class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Last Name *</label><input type="text" name="last_name" required class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Organisation *</label><input type="text" name="org" required class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Email *</label><input type="email" name="email" required class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Compound / CAS / SMILES *</label><input type="text" name="compound" required class="filter-input"></div>
                <div class="form-group"><label class="filter-label">Target Purity</label>
                    <select name="purity" class="filter-input"><option>≥ 95%</option><option>≥ 98%</option><option>≥ 99%</option><option>≥ 99.5%</option></select>
                </div>
                <div class="form-group" style="grid-column: 1/-1;"><label class="filter-label">Additional Requirements</label><textarea name="notes" rows="4" class="filter-input" placeholder="Isotopic labelling, stereochemistry, special packaging, GMP grade..."></textarea></div>
            </div>
            <button type="submit" name="synthesis_request" class="btn btn-primary" style="margin-top:20px; width:100%;">Send Request →</button>
        </form>
    </section>
</main>
<?php include 'footer.php'; ?>
<script>
function handleFormSubmit(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.textContent = 'Request Received ✔';
    btn.disabled = true;
    // Add actual submission logic (AJAX/mail) here
}
</script>
</body>
</html>