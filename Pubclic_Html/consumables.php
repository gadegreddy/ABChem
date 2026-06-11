<?php
include __DIR__ . '/../private/functions.php';
$meta = get_seo_meta();
?>
<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Consumables — HPLC Columns, Septa, Filters &amp; More | AB Chem India</title>
    <meta name="description" content="Source HPLC columns, septa, syringe filters, vials, tubing and all lab consumables from top brands — Waters, Agilent, Phenomenex, Whatman — through AB Chem India, Hyderabad.">
    <link rel="canonical" href="https://www.abchem.co.in/consumables">
    <link rel="stylesheet" href="/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Hero ─────────────────────────────────────────────── */
        .con-hero {
            background: var(--hero-bg);
            padding: 80px 32px 64px;
            color: #fff;
            text-align: center;
            position: relative; overflow: hidden;
        }
        .con-hero::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(ellipse 70% 60% at 50% -10%, rgba(99,102,241,0.18) 0%, transparent 70%);
            pointer-events: none;
        }
        .con-hero-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(99,102,241,0.15);
            border: 1px solid rgba(99,102,241,0.3);
            color: #a5b4fc;
            font-size: 0.78rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;
            padding: 5px 14px; border-radius: 999px; margin-bottom: 20px;
        }
        .con-hero h1 {
            font-size: clamp(1.9rem, 4vw, 2.8rem);
            font-weight: 800; letter-spacing: -0.02em; margin: 0 0 16px;
            background: linear-gradient(135deg, #fff 40%, #c4b5fd 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .con-hero p {
            max-width: 680px; margin: 0 auto 32px;
            color: rgba(255,255,255,0.75); font-size: 1.05rem; line-height: 1.7;
        }
        .con-hero-ctas { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .btn-hero-primary {
            background: linear-gradient(135deg, #6366f1, #0284c7);
            color: #fff; font-weight: 600; padding: 12px 28px;
            border-radius: 10px; text-decoration: none;
            box-shadow: 0 6px 20px rgba(99,102,241,0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease; font-size: 0.95rem;
        }
        .btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(99,102,241,0.4); }
        .btn-hero-outline {
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; font-weight: 500; padding: 12px 24px;
            border-radius: 10px; text-decoration: none;
            transition: background 0.2s ease; font-size: 0.95rem;
        }
        .btn-hero-outline:hover { background: rgba(255,255,255,0.12); }

        /* ── Stats Bar ────────────────────────────────────────── */
        .con-stats-bar {
            background: var(--surface); border-bottom: 1px solid var(--border); padding: 20px 32px;
        }
        .con-stats-inner {
            max-width: 1100px; margin: 0 auto;
            display: flex; gap: 0; justify-content: center; flex-wrap: wrap;
        }
        .con-stat {
            flex: 1; min-width: 130px;
            text-align: center; padding: 10px 24px;
            border-right: 1px solid var(--border);
        }
        .con-stat:last-child { border-right: none; }
        .con-stat-num {
            font-size: 1.7rem; font-weight: 800;
            background: linear-gradient(135deg, #6366f1, #0ea5e9);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; line-height: 1;
        }
        .con-stat-label { color: var(--muted); font-size: 0.78rem; font-weight: 500; margin-top: 4px; }

        /* ── Section ──────────────────────────────────────────── */
        .con-section { max-width: 1200px; margin: 0 auto; padding: 56px 32px; }
        .con-section-title { font-size: 1.55rem; font-weight: 800; color: var(--text); letter-spacing: -0.02em; margin-bottom: 6px; }
        .con-section-sub { color: var(--muted); font-size: 0.95rem; margin-bottom: 36px; line-height: 1.6; }

        /* ── Category cards ───────────────────────────────────── */
        .con-cat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 18px;
        }
        .con-cat-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 24px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .con-cat-card:hover {
            transform: translateY(-3px); box-shadow: var(--shadow);
            border-color: rgba(99,102,241,0.35);
        }
        .con-cat-header {
            display: flex; align-items: center; gap: 12px; margin-bottom: 14px;
        }
        .con-cat-icon-wrap {
            width: 44px; height: 44px; border-radius: 12px;
            background: rgba(99,102,241,0.12);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .con-cat-card h3 { color: var(--text); font-size: 1rem; font-weight: 700; }
        .con-cat-card p { color: var(--muted); font-size: 0.84rem; line-height: 1.55; margin-bottom: 12px; }
        .con-cat-tags { display: flex; flex-wrap: wrap; gap: 5px; }
        .con-tag {
            background: var(--surface-2); border: 1px solid var(--border);
            color: var(--muted); font-size: 0.72rem; font-weight: 600;
            padding: 2px 9px; border-radius: 999px;
        }

        /* ── Brand grid ───────────────────────────────────────── */
        .con-brand-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
        }
        .con-brand-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px 12px 14px;
            display: flex; flex-direction: column; align-items: center; gap: 10px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .con-brand-card:hover {
            transform: translateY(-3px); box-shadow: var(--shadow);
            border-color: rgba(99,102,241,0.35);
        }
        .con-brand-logo-wrap {
            width: 80px; height: 40px;
            display: flex; align-items: center; justify-content: center;
        }
        .con-brand-logo-wrap img {
            max-width: 80px; max-height: 40px;
            object-fit: contain; width: auto; height: auto;
        }
        [data-theme="dark"] .con-brand-logo-wrap img.invert-dark {
            filter: brightness(0) invert(1) opacity(0.85);
        }
        .con-brand-name { color: var(--text); font-size: 0.78rem; font-weight: 600; text-align: center; }
        .con-brand-spec { color: var(--muted); font-size: 0.7rem; font-weight: 500; background: var(--surface-2); border: 1px solid var(--border); padding: 2px 8px; border-radius: 999px; text-align: center; }

        /* ── HPLC spotlight ───────────────────────────────────── */
        .hplc-table-wrap { overflow-x: auto; margin-top: 20px; }
        .hplc-table {
            width: 100%; border-collapse: collapse;
            background: var(--surface); border-radius: var(--radius);
            overflow: hidden; border: 1px solid var(--border);
            font-size: 0.84rem;
        }
        .hplc-table th {
            background: linear-gradient(135deg, #4f46e5, #0284c7);
            color: #fff; padding: 10px 14px; text-align: left;
            font-weight: 600; font-size: 0.8rem; letter-spacing: 0.03em;
        }
        .hplc-table td {
            padding: 10px 14px; border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
        }
        .hplc-table tr:last-child td { border-bottom: none; }
        .hplc-table tr:hover td { background: var(--table-hover-bg); }

        /* ── Why ──────────────────────────────────────────────── */
        .why-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px;
        }
        .why-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 22px;
        }
        .why-icon {
            width: 40px; height: 40px; border-radius: 10px;
            background: rgba(99,102,241,0.12);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; margin-bottom: 12px;
        }
        .why-card h4 { color: var(--text); font-size: 0.95rem; font-weight: 700; margin-bottom: 5px; }
        .why-card p  { color: var(--muted); font-size: 0.83rem; line-height: 1.55; }

        /* ── CTA ──────────────────────────────────────────────── */
        .con-cta-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            border: 1px solid rgba(99,102,241,0.2);
            border-radius: var(--radius-lg); padding: 48px 40px; text-align: center;
            position: relative; overflow: hidden;
        }
        .con-cta-banner::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(ellipse 60% 80% at 50% 100%, rgba(99,102,241,0.12), transparent);
            pointer-events: none;
        }
        .con-cta-banner h2 { color: #fff; font-size: 1.7rem; font-weight: 800; margin-bottom: 10px; }
        .con-cta-banner p  {
            color: rgba(255,255,255,0.65); font-size: 1rem; margin: 0 auto 28px;
            line-height: 1.6; max-width: 540px;
        }
        .con-cta-btns { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; position: relative; z-index: 1; }

        .con-divider { border: none; border-top: 1px solid var(--border); margin: 0; }

        @media (max-width: 768px) {
            .con-hero { padding: 52px 20px 44px; }
            .con-section { padding: 40px 20px; }
            .con-stat { border-right: none; border-bottom: 1px solid var(--border); }
            .con-stat:last-child { border-bottom: none; }
            .con-brand-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
            .con-cta-banner { padding: 32px 20px; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>

    <!-- ── Hero ──────────────────────────────────────────────── -->
    <section class="con-hero">
        <div class="con-hero-badge">🔩 Lab Consumables &amp; Supplies</div>
        <h1>Everything Your Lab Needs, Beyond Chemicals</h1>
        <p>
            HPLC columns, septa, syringe filters, vials, tubing, fittings, and the full range of
            analytical consumables — sourced from the world's leading manufacturers and delivered
            across India from Hyderabad.
        </p>
        <div class="con-hero-ctas">
            <a href="/contact" class="btn-hero-primary">📋 Request a Quote</a>
            <a href="/fine-chemicals" class="btn-hero-outline">Fine Chemicals →</a>
        </div>
    </section>

    <!-- ── Stats ─────────────────────────────────────────────── -->
    <div class="con-stats-bar">
        <div class="con-stats-inner">
            <div class="con-stat">
                <div class="con-stat-num">15+</div>
                <div class="con-stat-label">Consumable Brands</div>
            </div>
            <div class="con-stat">
                <div class="con-stat-num">10,000+</div>
                <div class="con-stat-label">Consumable SKUs</div>
            </div>
            <div class="con-stat">
                <div class="con-stat-num">48 hrs</div>
                <div class="con-stat-label">Typical Dispatch</div>
            </div>
            <div class="con-stat">
                <div class="con-stat-num">GST</div>
                <div class="con-stat-label">Compliant Invoicing</div>
            </div>
        </div>
    </div>

    <!-- ── Categories ─────────────────────────────────────────── -->
    <section class="con-section">
        <h2 class="con-section-title">Consumable Categories</h2>
        <p class="con-section-sub">
            From chromatography to sample preparation — we stock and source the consumables that keep your analytical workflows running.
        </p>
        <div class="con-cat-grid">

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">📊</div>
                    <h3>HPLC &amp; LC-MS Columns</h3>
                </div>
                <p>Reversed-phase, HILIC, ion-exchange, size-exclusion, and chiral columns for analytical and preparative applications. Multiple stationary phases and particle sizes.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">C18 / C8</span>
                    <span class="con-tag">HILIC</span>
                    <span class="con-tag">Chiral</span>
                    <span class="con-tag">Ion Exchange</span>
                    <span class="con-tag">SEC/GPC</span>
                    <span class="con-tag">Phenyl</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">🔵</div>
                    <h3>Septa &amp; Vial Closures</h3>
                </div>
                <p>Pre-slit and non-slit septa in silicone, PTFE/silicone, and red rubber. Crimp-top, screw-top, and snap-cap closures for all major autosampler vial formats.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">PTFE/Silicone</span>
                    <span class="con-tag">Pre-slit</span>
                    <span class="con-tag">9mm Crimp</span>
                    <span class="con-tag">11mm Crimp</span>
                    <span class="con-tag">Screw-top</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">🫙</div>
                    <h3>Autosampler Vials &amp; Inserts</h3>
                </div>
                <p>2 mL, 1.5 mL, and 0.3 mL clear and amber glass vials. Polypropylene micro-inserts for trace-level analysis. Compatible with all major HPLC/UHPLC systems.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">2mL Standard</span>
                    <span class="con-tag">1.5mL Short</span>
                    <span class="con-tag">Amber Glass</span>
                    <span class="con-tag">Polypropylene</span>
                    <span class="con-tag">Micro Inserts</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">💧</div>
                    <h3>Syringe Filters</h3>
                </div>
                <p>Membrane-based syringe filters in PVDF, Nylon, PES, PTFE, MCE, and RC. Available in 4 mm, 13 mm, 17 mm, 25 mm, and 33 mm diameters with 0.2 µm and 0.45 µm pore sizes.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">0.2 µm</span>
                    <span class="con-tag">0.45 µm</span>
                    <span class="con-tag">PVDF</span>
                    <span class="con-tag">PTFE</span>
                    <span class="con-tag">Nylon</span>
                    <span class="con-tag">PES</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">🌀</div>
                    <h3>HPLC Tubing &amp; Fittings</h3>
                </div>
                <p>Stainless steel, PEEK, and PFA tubing. Fingertight fittings, unions, tees, and adapters for low-pressure to ultra-high-pressure UHPLC applications (up to 1200 bar).</p>
                <div class="con-cat-tags">
                    <span class="con-tag">SS Tubing</span>
                    <span class="con-tag">PEEK Tubing</span>
                    <span class="con-tag">Fingertight</span>
                    <span class="con-tag">UHPLC Fittings</span>
                    <span class="con-tag">Unions</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">🧲</div>
                    <h3>SPE Cartridges &amp; Plates</h3>
                </div>
                <p>Solid-phase extraction cartridges for sample clean-up — C18, C8, SCX, SAX, mixed-mode, and specialty sorbents. Available in 1 mL to 60 mL formats and 96-well plates.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">C18 SPE</span>
                    <span class="con-tag">Mixed Mode</span>
                    <span class="con-tag">96-well</span>
                    <span class="con-tag">Polymeric</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">🗂️</div>
                    <h3>Filtration Membranes &amp; Papers</h3>
                </div>
                <p>Whatman filter papers (qualitative and quantitative), membrane filters, glass microfibre filters, and ultrafiltration membranes for gravimetric, particle, and solvent filtration.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">Whatman No.1</span>
                    <span class="con-tag">GF/C</span>
                    <span class="con-tag">PVDF Membrane</span>
                    <span class="con-tag">Nylon Disc</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">🧫</div>
                    <h3>Centrifuge Tubes &amp; Plates</h3>
                </div>
                <p>Polypropylene microcentrifuge tubes (0.2, 0.5, 1.5, 2 mL), conical tubes (15 mL, 50 mL), and PCR plates. RNase/DNase-free grades available.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">1.5 mL Eppendorf</span>
                    <span class="con-tag">15 mL Falcon</span>
                    <span class="con-tag">50 mL Conical</span>
                    <span class="con-tag">Deep Well</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">🔬</div>
                    <h3>Pipette Tips &amp; Consumables</h3>
                </div>
                <p>Universal and brand-specific pipette tips (filter and non-filter) for 0.1 µL to 5 mL volume ranges. Sterile-packed and bulk options. Reservoir troughs and reagent boats.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">Filter Tips</span>
                    <span class="con-tag">Low-Retention</span>
                    <span class="con-tag">Sterile</span>
                    <span class="con-tag">Gel-Loading</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">🥼</div>
                    <h3>Lab Safety &amp; PPE Consumables</h3>
                </div>
                <p>Nitrile and latex gloves, lab coats, safety goggles, respirator masks, and chemical-resistant aprons. Compliant with IS and EN standards for use in chemical laboratories.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">Nitrile Gloves</span>
                    <span class="con-tag">N95 Masks</span>
                    <span class="con-tag">Safety Goggles</span>
                    <span class="con-tag">Lab Coats</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">📦</div>
                    <h3>Sample Storage &amp; Cryogenics</h3>
                </div>
                <p>Cryovials, cryogenic storage boxes, liquid nitrogen containers, and labelling systems for biological and chemical sample archives. Temperature-rated from −196 °C to +121 °C.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">Cryovials</span>
                    <span class="con-tag">Cryo Boxes</span>
                    <span class="con-tag">LN₂ Dewars</span>
                    <span class="con-tag">Freezer Labels</span>
                </div>
            </div>

            <div class="con-cat-card">
                <div class="con-cat-header">
                    <div class="con-cat-icon-wrap">⚗️</div>
                    <h3>Glassware &amp; Plasticware</h3>
                </div>
                <p>Borosilicate volumetric and general glassware — round-bottom flasks, Erlenmeyers, beakers, burettes, pipettes — plus PP, PE, and PFA plasticware for corrosive media.</p>
                <div class="con-cat-tags">
                    <span class="con-tag">Borosilicate</span>
                    <span class="con-tag">Volumetric</span>
                    <span class="con-tag">PFA Ware</span>
                    <span class="con-tag">PTFE Bottles</span>
                </div>
            </div>

        </div>
    </section>

    <hr class="con-divider">

    <!-- ── HPLC Column Spotlight ──────────────────────────────── -->
    <section class="con-section">
        <h2 class="con-section-title">HPLC Column Brand Guide</h2>
        <p class="con-section-sub">
            The right column depends on your analyte chemistry, throughput, and regulatory requirements.
            We can help match the right stationary phase and supplier to your method.
        </p>
        <div class="hplc-table-wrap">
            <table class="hplc-table">
                <thead>
                    <tr>
                        <th>Brand</th>
                        <th>Origin</th>
                        <th>Popular Columns</th>
                        <th>Best For</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Waters</strong></td>
                        <td>USA</td>
                        <td>Atlantis T3, XBridge BEH C18, XSelect CSH C18, Cortecs</td>
                        <td>Pharma, regulated bioanalysis, USP methods</td>
                    </tr>
                    <tr>
                        <td><strong>Agilent (Zorbax)</strong></td>
                        <td>USA</td>
                        <td>ZORBAX Eclipse Plus C18, SB-C18, Poroshell 120, InfinityLab</td>
                        <td>General pharma QC, food, environmental testing</td>
                    </tr>
                    <tr>
                        <td><strong>Phenomenex</strong></td>
                        <td>USA</td>
                        <td>Kinetex C18, Luna C18, Synergi, Gemini, Strata-X</td>
                        <td>Fast gradient, method development, wide pH range</td>
                    </tr>
                    <tr>
                        <td><strong>Merck (Supelco)</strong></td>
                        <td>Germany</td>
                        <td>Ascentis Express C18, Discovery C18, Purospher STAR</td>
                        <td>Robust QC, USP compendial methods</td>
                    </tr>
                    <tr>
                        <td><strong>YMC</strong></td>
                        <td>Japan</td>
                        <td>YMC-Triart C18, YMC-Pack ODS-A, YMC Chiral</td>
                        <td>Biosimilars, peptides, chiral separation</td>
                    </tr>
                    <tr>
                        <td><strong>Shiseido (Capcell Pak)</strong></td>
                        <td>Japan</td>
                        <td>Capcell Pak C18 MGII, Capcell Pak Adme</td>
                        <td>Protein binding, plasma sample analysis, ADME</td>
                    </tr>
                    <tr>
                        <td><strong>Dr. Maisch (ReproSil)</strong></td>
                        <td>Germany</td>
                        <td>ReproSil-Pur C18-AQ, ReproSil Gold, Reprosil-Pur AQ</td>
                        <td>Proteomics, LC-MS, high aqueous gradients</td>
                    </tr>
                    <tr>
                        <td><strong>Restek</strong></td>
                        <td>USA</td>
                        <td>Raptor C18, Ultra C18, Pinnacle DB C18, Rxi GC</td>
                        <td>Pesticides, forensics, GC and HPLC dual lab</td>
                    </tr>
                    <tr>
                        <td><strong>GL Sciences (Inertsil)</strong></td>
                        <td>Japan</td>
                        <td>Inertsil ODS-3, Inertsil ODS-SP, Inertsil C8-3</td>
                        <td>Pharmaceutical impurity profiling, Japanese pharmacopoeia</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p style="margin-top:14px;color:var(--muted);font-size:0.82rem;">
            Looking for a specific column not listed? <a href="/contact" style="color:var(--accent-bright);">Contact us</a> with your method requirements and we'll help you select and source it.
        </p>
    </section>

    <hr class="con-divider">

    <!-- ── Consumable Brands ───────────────────────────────────── -->
    <section class="con-section">
        <h2 class="con-section-title">Brands We Supply</h2>
        <p class="con-section-sub">
            All major consumable manufacturers, sourced through authorised Indian distributors.
        </p>
        <div class="con-brand-grid">

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/e/ec/Waters_Corporation_logo.svg/320px-Waters_Corporation_logo.svg.png"
                         alt="Waters" class="invert-dark">
                </div>
                <span class="con-brand-name">Waters</span>
                <span class="con-brand-spec">Columns &bull; Vials &bull; SPE</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/95/Agilent_Technologies-Logo.svg/320px-Agilent_Technologies-Logo.svg.png"
                         alt="Agilent" class="invert-dark">
                </div>
                <span class="con-brand-name">Agilent</span>
                <span class="con-brand-spec">Columns &bull; Vials &bull; Tubing</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/56/Merck_KGaA_Logo.svg/320px-Merck_KGaA_Logo.svg.png"
                         alt="Merck Supelco" class="invert-dark">
                </div>
                <span class="con-brand-name">Merck / Supelco</span>
                <span class="con-brand-spec">Columns &bull; Filters &bull; SPE</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#dc2626;">Phenomenex</span>
                </div>
                <span class="con-brand-name">Phenomenex</span>
                <span class="con-brand-spec">Columns &bull; SPE &bull; Strata-X</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/a/a8/Thermo_Fisher_Scientific_logo.svg/320px-Thermo_Fisher_Scientific_logo.svg.png"
                         alt="Thermo Fisher" class="invert-dark">
                </div>
                <span class="con-brand-name">Thermo Fisher</span>
                <span class="con-brand-spec">Columns &bull; Vials &bull; Plasticware</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#0369a1;">Whatman / Cytiva</span>
                </div>
                <span class="con-brand-name">Whatman / Cytiva</span>
                <span class="con-brand-spec">Filter Papers &bull; Membranes</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#047857;">Restek</span>
                </div>
                <span class="con-brand-name">Restek</span>
                <span class="con-brand-spec">Columns &bull; GC &bull; Liners</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#7c3aed;">YMC</span>
                </div>
                <span class="con-brand-name">YMC</span>
                <span class="con-brand-spec">Columns &bull; Prep &bull; Chiral</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <span style="font-size:0.9rem;font-weight:800;color:#b45309;">Macherey-Nagel</span>
                </div>
                <span class="con-brand-name">Macherey-Nagel</span>
                <span class="con-brand-spec">TLC &bull; Columns &bull; Filters</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#0f766e;">Eppendorf</span>
                </div>
                <span class="con-brand-name">Eppendorf</span>
                <span class="con-brand-spec">Tubes &bull; Tips &bull; Plates</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#1d4ed8;">Sartorius</span>
                </div>
                <span class="con-brand-name">Sartorius</span>
                <span class="con-brand-spec">Filters &bull; Membranes &bull; Tips</span>
            </div>

            <div class="con-brand-card">
                <div class="con-brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#6d28d9;">Nalgene / Thermo</span>
                </div>
                <span class="con-brand-name">Nalgene</span>
                <span class="con-brand-spec">Bottles &bull; Plasticware &bull; Tubing</span>
            </div>

        </div>
        <p style="margin-top:16px;color:var(--muted);font-size:0.82rem;">
            * We can source from additional brands on request. <a href="/contact" style="color:var(--accent-bright);">Contact us</a> with your specific requirements.
        </p>
    </section>

    <hr class="con-divider">

    <!-- ── Why AB Chem ─────────────────────────────────────────── -->
    <section class="con-section">
        <h2 class="con-section-title">Why Choose AB Chem India for Consumables?</h2>
        <p class="con-section-sub">Order chemicals and consumables from a single vendor — less admin, fewer purchase orders, one delivery.</p>
        <div class="why-grid">
            <div class="why-card">
                <div class="why-icon">🧪</div>
                <h4>Chemicals + Consumables Together</h4>
                <p>Combine your fine chemical and consumable orders into one PO. Reduces procurement overhead for your purchasing team.</p>
            </div>
            <div class="why-card">
                <div class="why-icon">📜</div>
                <h4>Original Manufacturer CoA</h4>
                <p>Every consumable ships with its original documentation — CoA, MSDS, and compliance certificates intact for audit readiness.</p>
            </div>
            <div class="why-card">
                <div class="why-icon">🚢</div>
                <h4>Handles Import Logistics</h4>
                <p>For international brands, we manage CIF imports, customs clearance, and local distribution so you don't have to deal with multiple freight agents.</p>
            </div>
            <div class="why-card">
                <div class="why-icon">🏛️</div>
                <h4>Institutional &amp; Govt. Supply</h4>
                <p>Equipped for supply to universities, CSIR/ICMR labs, and central government institutes. GEM portal registration in progress for e-tender participation.</p>
            </div>
            <div class="why-card">
                <div class="why-icon">🧾</div>
                <h4>GST-Compliant Billing</h4>
                <p>All invoices raised with GSTIN <strong>36ACDFA7838D1ZG</strong>. Credit terms available for approved institutions and corporates.</p>
            </div>
            <div class="why-card">
                <div class="why-icon">📞</div>
                <h4>Technical Support</h4>
                <p>Not sure which column or filter suits your method? Our chemists can recommend the right consumable for your application — free pre-sales guidance.</p>
            </div>
        </div>
    </section>

    <hr class="con-divider">

    <!-- ── CTA ────────────────────────────────────────────────── -->
    <section class="con-section">
        <div class="con-cta-banner">
            <h2>Need a Consumables Quote?</h2>
            <p>
                Send us your list — product name, catalogue number, quantity, and preferred brand —
                and we'll reply with pricing and availability within 24 hours.
            </p>
            <div class="con-cta-btns">
                <a href="/contact" class="btn-hero-primary">📋 Request a Quote</a>
                <a href="mailto:connect@abchem.co.in" class="btn-hero-outline">✉️ connect@abchem.co.in</a>
            </div>
        </div>
    </section>

</main>

<?php include 'footer.php'; ?>
</body>
</html>
