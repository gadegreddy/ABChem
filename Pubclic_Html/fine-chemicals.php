<?php
include __DIR__ . '/../private/functions.php';
$meta = get_seo_meta();
?>
<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fine Chemicals from All Major Brands | AB Chem India</title>
    <meta name="description" content="Source fine chemicals from all leading Indian and international brands — Merck, SRL, TCI, Avra, Loba Chemie, Thermo Fisher and more — through AB Chem India, Hyderabad.">
    <link rel="canonical" href="https://www.abchem.co.in/fine-chemicals">
    <link rel="stylesheet" href="/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Hero ─────────────────────────────────────────────── */
        .fc-hero {
            background: var(--hero-bg);
            padding: 80px 32px 64px;
            color: #fff;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .fc-hero::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(ellipse 70% 60% at 50% -10%, rgba(14,165,233,0.18) 0%, transparent 70%);
            pointer-events: none;
        }
        .fc-hero-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(14,165,233,0.15);
            border: 1px solid rgba(14,165,233,0.3);
            color: #38bdf8;
            font-size: 0.78rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;
            padding: 5px 14px; border-radius: 999px; margin-bottom: 20px;
        }
        .fc-hero h1 {
            font-size: clamp(1.9rem, 4vw, 2.8rem);
            font-weight: 800; letter-spacing: -0.02em;
            margin: 0 0 16px;
            background: linear-gradient(135deg, #fff 40%, #7dd3fc 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .fc-hero p {
            max-width: 680px; margin: 0 auto 32px;
            color: rgba(255,255,255,0.75); font-size: 1.05rem; line-height: 1.7;
        }
        .fc-hero-ctas {
            display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;
        }
        .btn-hero-primary {
            background: linear-gradient(135deg, #0284c7, #6366f1);
            color: #fff; font-weight: 600; padding: 12px 28px;
            border-radius: 10px; text-decoration: none;
            box-shadow: 0 6px 20px rgba(14,165,233,0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            font-size: 0.95rem;
        }
        .btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(14,165,233,0.4); }
        .btn-hero-outline {
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; font-weight: 500; padding: 12px 24px;
            border-radius: 10px; text-decoration: none;
            transition: background 0.2s ease; font-size: 0.95rem;
        }
        .btn-hero-outline:hover { background: rgba(255,255,255,0.12); }

        /* ── Stats Bar ────────────────────────────────────────── */
        .fc-stats-bar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 20px 32px;
        }
        .fc-stats-inner {
            max-width: 1100px; margin: 0 auto;
            display: flex; gap: 0; justify-content: center;
            flex-wrap: wrap;
        }
        .fc-stat {
            flex: 1; min-width: 130px;
            text-align: center; padding: 10px 24px;
            border-right: 1px solid var(--border);
        }
        .fc-stat:last-child { border-right: none; }
        .fc-stat-num {
            font-size: 1.7rem; font-weight: 800;
            background: linear-gradient(135deg, #0ea5e9, #6366f1);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
        }
        .fc-stat-label { color: var(--muted); font-size: 0.78rem; font-weight: 500; margin-top: 4px; }

        /* ── Section layout ───────────────────────────────────── */
        .fc-section {
            max-width: 1200px; margin: 0 auto; padding: 56px 32px;
        }
        .fc-section-title {
            font-size: 1.55rem; font-weight: 800; color: var(--text);
            letter-spacing: -0.02em; margin-bottom: 6px;
        }
        .fc-section-sub {
            color: var(--muted); font-size: 0.95rem; margin-bottom: 36px; line-height: 1.6;
        }

        /* ── Brand grid ───────────────────────────────────────── */
        .brand-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 14px;
        }
        .brand-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 16px 16px;
            display: flex; flex-direction: column;
            align-items: center; gap: 12px;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            cursor: default;
        }
        .brand-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--accent);
        }
        .brand-logo-wrap {
            width: 80px; height: 44px;
            display: flex; align-items: center; justify-content: center;
        }
        .brand-logo-wrap img {
            max-width: 80px; max-height: 44px;
            object-fit: contain; width: auto; height: auto;
            filter: var(--brand-logo-filter, none);
            transition: filter 0.2s ease;
        }
        /* In dark mode, brighten logos that use dark text */
        [data-theme="dark"] .brand-logo-wrap img.invert-dark {
            filter: brightness(0) invert(1) opacity(0.85);
        }
        .brand-name {
            color: var(--text); font-size: 0.78rem; font-weight: 600;
            text-align: center; line-height: 1.3;
        }
        .brand-origin {
            color: var(--muted); font-size: 0.7rem; font-weight: 500;
            background: var(--surface-2); border: 1px solid var(--border);
            padding: 2px 8px; border-radius: 999px;
        }
        /* Tab filters for brands */
        .brand-tabs {
            display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 28px;
        }
        .brand-tab {
            background: var(--surface); border: 1px solid var(--border);
            color: var(--muted); font-size: 0.8rem; font-weight: 600;
            padding: 6px 16px; border-radius: 999px; cursor: pointer;
            transition: all 0.15s ease;
        }
        .brand-tab.active, .brand-tab:hover {
            background: rgba(14,165,233,0.12);
            border-color: rgba(14,165,233,0.4);
            color: var(--accent-bright);
        }

        /* ── Category cards ───────────────────────────────────── */
        .fc-cat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
        }
        .fc-cat-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 22px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .fc-cat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
        .fc-cat-icon {
            font-size: 1.8rem; margin-bottom: 12px; display: block;
        }
        .fc-cat-card h3 {
            color: var(--text); font-size: 1rem; font-weight: 700; margin-bottom: 6px;
        }
        .fc-cat-card p {
            color: var(--muted); font-size: 0.85rem; line-height: 1.55;
        }

        /* ── Why AB Chem ──────────────────────────────────────── */
        .why-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
        }
        .why-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 22px;
        }
        .why-card-icon {
            width: 40px; height: 40px; border-radius: 10px;
            background: rgba(14,165,233,0.12);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; margin-bottom: 12px;
        }
        .why-card h4 { color: var(--text); font-size: 0.95rem; font-weight: 700; margin-bottom: 5px; }
        .why-card p  { color: var(--muted); font-size: 0.83rem; line-height: 1.55; }

        /* ── CTA banner ───────────────────────────────────────── */
        .fc-cta-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f172a 100%);
            border: 1px solid rgba(14,165,233,0.2);
            border-radius: var(--radius-lg);
            padding: 48px 40px;
            text-align: center;
            position: relative; overflow: hidden;
        }
        .fc-cta-banner::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(ellipse 60% 80% at 50% 100%, rgba(14,165,233,0.12), transparent);
            pointer-events: none;
        }
        .fc-cta-banner h2 { color: #fff; font-size: 1.7rem; font-weight: 800; margin-bottom: 10px; }
        .fc-cta-banner p  { color: rgba(255,255,255,0.65); font-size: 1rem; margin-bottom: 28px; line-height: 1.6; max-width: 540px; margin-left: auto; margin-right: auto; }
        .fc-cta-btns { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; position: relative; z-index: 1; }

        /* ── Divider ──────────────────────────────────────────── */
        .fc-divider { border: none; border-top: 1px solid var(--border); margin: 0; }

        @media (max-width: 768px) {
            .fc-hero { padding: 52px 20px 44px; }
            .fc-section { padding: 40px 20px; }
            .fc-stat { border-right: none; border-bottom: 1px solid var(--border); }
            .fc-stat:last-child { border-bottom: none; }
            .brand-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
            .fc-cta-banner { padding: 32px 20px; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>

    <!-- ── Hero ──────────────────────────────────────────────── -->
    <section class="fc-hero">
        <div class="fc-hero-badge">🧪 Multi-Brand Sourcing Partner</div>
        <h1>Fine Chemicals from Every Major Brand</h1>
        <p>
            Access fine chemicals, reagents, and research-grade compounds from all leading Indian and
            international manufacturers — sourced, verified, and delivered to your lab or facility
            across India from Hyderabad.
        </p>
        <div class="fc-hero-ctas">
            <a href="/contact" class="btn-hero-primary">📋 Submit a Sourcing Enquiry</a>
            <a href="/catalog" class="btn-hero-outline">Browse Our Own Catalog →</a>
        </div>
    </section>

    <!-- ── Stats ─────────────────────────────────────────────── -->
    <div class="fc-stats-bar">
        <div class="fc-stats-inner">
            <div class="fc-stat">
                <div class="fc-stat-num">30+</div>
                <div class="fc-stat-label">Brands Covered</div>
            </div>
            <div class="fc-stat">
                <div class="fc-stat-num">50,000+</div>
                <div class="fc-stat-label">Catalogue SKUs Accessible</div>
            </div>
            <div class="fc-stat">
                <div class="fc-stat-num">Pan-India</div>
                <div class="fc-stat-label">Delivery Coverage</div>
            </div>
            <div class="fc-stat">
                <div class="fc-stat-num">Single</div>
                <div class="fc-stat-label">Point of Contact</div>
            </div>
        </div>
    </div>

    <!-- ── Brand Grid ─────────────────────────────────────────── -->
    <section class="fc-section">
        <h2 class="fc-section-title">Brands We Source From</h2>
        <p class="fc-section-sub">
            From globally recognised manufacturers to trusted Indian suppliers —
            we work with the full spectrum so you don't have to manage multiple vendors.
        </p>

        <!-- Tab filters -->
        <div class="brand-tabs" id="brand-tabs">
            <button class="brand-tab active" data-filter="all">All Brands</button>
            <button class="brand-tab" data-filter="international">🌐 International</button>
            <button class="brand-tab" data-filter="indian">🇮🇳 Indian</button>
        </div>

        <div class="brand-grid" id="brand-grid">

            <!-- ── International Brands ── -->
            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/56/Merck_KGaA_Logo.svg/320px-Merck_KGaA_Logo.svg.png"
                         alt="Merck Sigma-Aldrich" class="invert-dark">
                </div>
                <span class="brand-name">Merck / Sigma-Aldrich</span>
                <span class="brand-origin">Germany / USA</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/a/a8/Thermo_Fisher_Scientific_logo.svg/320px-Thermo_Fisher_Scientific_logo.svg.png"
                         alt="Thermo Fisher Scientific" class="invert-dark">
                </div>
                <span class="brand-name">Thermo Fisher Scientific</span>
                <span class="brand-origin">USA</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/7/7e/TCI_Chemicals_logo.svg/320px-TCI_Chemicals_logo.svg.png"
                         alt="TCI Chemicals" class="invert-dark">
                </div>
                <span class="brand-name">TCI Chemicals</span>
                <span class="brand-origin">Japan</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap" style="background:none;">
                    <!-- Alfa Aesar text logo fallback -->
                    <span style="font-size:1.05rem;font-weight:800;color:var(--accent-bright);letter-spacing:-0.02em;">Alfa Aesar</span>
                </div>
                <span class="brand-name">Alfa Aesar</span>
                <span class="brand-origin">USA (Thermo)</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/9e/Acros_Organics_logo.svg/320px-Acros_Organics_logo.svg.png"
                         alt="Acros Organics" class="invert-dark">
                </div>
                <span class="brand-name">Acros Organics</span>
                <span class="brand-origin">Belgium</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <span style="font-size:1.05rem;font-weight:800;color:#e11d48;letter-spacing:-0.02em;">Cayman Chem</span>
                </div>
                <span class="brand-name">Cayman Chemical</span>
                <span class="brand-origin">USA</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:var(--text);letter-spacing:-0.02em;">Fluorochem</span>
                </div>
                <span class="brand-name">Fluorochem</span>
                <span class="brand-origin">UK</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#0369a1;">Apollo Scientific</span>
                </div>
                <span class="brand-name">Apollo Scientific</span>
                <span class="brand-origin">UK</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#7c3aed;">Combi-Blocks</span>
                </div>
                <span class="brand-name">Combi-Blocks</span>
                <span class="brand-origin">USA</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#0f766e;">Enamine</span>
                </div>
                <span class="brand-name">Enamine</span>
                <span class="brand-origin">Ukraine / EU</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <span style="font-size:0.95rem;font-weight:800;color:#b45309;">Oakwood Chemical</span>
                </div>
                <span class="brand-name">Oakwood Chemical</span>
                <span class="brand-origin">USA</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#1d4ed8;">AK Scientific</span>
                </div>
                <span class="brand-name">AK Scientific</span>
                <span class="brand-origin">USA</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <span style="font-size:0.9rem;font-weight:800;color:#047857;">Matrix Scientific</span>
                </div>
                <span class="brand-name">Matrix Scientific</span>
                <span class="brand-origin">USA</span>
            </div>

            <div class="brand-card" data-region="international">
                <div class="brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#6d28d9;">Angene</span>
                </div>
                <span class="brand-name">Angene Chemical</span>
                <span class="brand-origin">China / USA</span>
            </div>

            <!-- ── Indian Brands ── -->
            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/en/thumb/5/5f/SRL_Chemicals_logo.png/240px-SRL_Chemicals_logo.png"
                         alt="SRL Chemicals" class="invert-dark">
                </div>
                <span class="brand-name">SRL Chemicals</span>
                <span class="brand-origin">India (Mumbai)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <img src="https://upload.wikimedia.org/wikipedia/en/thumb/a/a7/Sisco_Research_Laboratories_Logo.png/240px-Sisco_Research_Laboratories_Logo.png"
                         alt="Sisco Research Laboratories" class="invert-dark">
                </div>
                <span class="brand-name">Sisco Research Labs</span>
                <span class="brand-origin">India (Mumbai)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <img src="https://www.lobachem.com/images/logo.png"
                         alt="Loba Chemie" class="invert-dark"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                    <span style="display:none;font-size:1rem;font-weight:800;color:#be123c;">Loba Chemie</span>
                </div>
                <span class="brand-name">Loba Chemie</span>
                <span class="brand-origin">India (Mumbai)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#0369a1;">HiMedia</span>
                </div>
                <span class="brand-name">HiMedia Laboratories</span>
                <span class="brand-origin">India (Mumbai)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <span style="font-size:1rem;font-weight:800;color:#b45309;">Avra Synthesis</span>
                </div>
                <span class="brand-name">Avra Synthesis</span>
                <span class="brand-origin">India (Hyderabad)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <span style="font-size:0.95rem;font-weight:800;color:#0f766e;">Spectrochem</span>
                </div>
                <span class="brand-name">Spectrochem</span>
                <span class="brand-origin">India (Mumbai)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <span style="font-size:0.95rem;font-weight:800;color:#1d4ed8;">Rankem / RFCL</span>
                </div>
                <span class="brand-name">Rankem / RFCL</span>
                <span class="brand-origin">India (New Delhi)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <span style="font-size:0.95rem;font-weight:800;color:#7c3aed;">FINAR Chemicals</span>
                </div>
                <span class="brand-name">FINAR Chemicals</span>
                <span class="brand-origin">India (Ahmedabad)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <span style="font-size:0.95rem;font-weight:800;color:#047857;">Qualigens</span>
                </div>
                <span class="brand-name">Qualigens Fine Chem</span>
                <span class="brand-origin">India (Mumbai)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <span style="font-size:0.95rem;font-weight:800;color:#dc2626;">SD Fine Chem</span>
                </div>
                <span class="brand-name">SD Fine-Chem</span>
                <span class="brand-origin">India (Mumbai)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <span style="font-size:0.95rem;font-weight:800;color:#b45309;">Molychem</span>
                </div>
                <span class="brand-name">Molychem</span>
                <span class="brand-origin">India (Mumbai)</span>
            </div>

            <div class="brand-card" data-region="indian">
                <div class="brand-logo-wrap">
                    <span style="font-size:0.95rem;font-weight:800;color:#6d28d9;">Astron Chemicals</span>
                </div>
                <span class="brand-name">Astron Chemicals</span>
                <span class="brand-origin">India (Ahmedabad)</span>
            </div>

        </div>
        <p style="margin-top:18px;color:var(--muted);font-size:0.82rem;">
            * Don't see your preferred brand? We can source from virtually any catalogue supplier. <a href="/contact" style="color:var(--accent-bright);">Contact us</a> with your specific requirements.
        </p>
    </section>

    <hr class="fc-divider">

    <!-- ── Chemical Categories ────────────────────────────────── -->
    <section class="fc-section">
        <h2 class="fc-section-title">Chemical Categories We Source</h2>
        <p class="fc-section-sub">
            Whether you need milligram quantities of a rare reference standard or kilogram quantities of a common reagent, we handle the full range.
        </p>
        <div class="fc-cat-grid">
            <div class="fc-cat-card">
                <span class="fc-cat-icon">⚗️</span>
                <h3>Reagent Grade Chemicals</h3>
                <p>Solvents, acids, bases, inorganic salts, and general-purpose reagents for routine lab work. Available in multiple grades: LR, AR, GR, and ACS.</p>
            </div>
            <div class="fc-cat-card">
                <span class="fc-cat-icon">💊</span>
                <h3>Pharma Reference Standards</h3>
                <p>USP, EP, BP, and IP reference standards for method development, QC testing, and regulatory submissions. CoA provided for all standards.</p>
            </div>
            <div class="fc-cat-card">
                <span class="fc-cat-icon">🧬</span>
                <h3>Organic Building Blocks</h3>
                <p>Heterocycles, amino acids, protecting groups, linkers, and scaffolds for medicinal chemistry and drug discovery programs.</p>
            </div>
            <div class="fc-cat-card">
                <span class="fc-cat-icon">🔬</span>
                <h3>Analytical &amp; Chromatography</h3>
                <p>HPLC-grade solvents, ion pair reagents, derivatisation reagents, buffer salts, and ion chromatography standards.</p>
            </div>
            <div class="fc-cat-card">
                <span class="fc-cat-icon">🌡️</span>
                <h3>Isotope-Labelled Compounds</h3>
                <p>Deuterium-labelled, ¹³C, and ¹⁵N compounds for metabolite profiling, mechanistic studies, and DMPK/ADME research.</p>
            </div>
            <div class="fc-cat-card">
                <span class="fc-cat-icon">🧪</span>
                <h3>High-Purity Metals &amp; Salts</h3>
                <p>Transition metal catalysts, noble metal salts, organometallics, and ligands for catalytic reactions and materials research.</p>
            </div>
            <div class="fc-cat-card">
                <span class="fc-cat-icon">🛡️</span>
                <h3>Biochemicals &amp; Biologics</h3>
                <p>Enzymes, substrates, inhibitors, culture media components, vitamins, and other life-science grade biochemicals.</p>
            </div>
            <div class="fc-cat-card">
                <span class="fc-cat-icon">🏭</span>
                <h3>Industrial &amp; Bulk Chemicals</h3>
                <p>Commercial quantities of intermediates, solvents, and excipients for process development, scale-up, and manufacturing.</p>
            </div>
        </div>
    </section>

    <hr class="fc-divider">

    <!-- ── Why AB Chem ─────────────────────────────────────────── -->
    <section class="fc-section">
        <h2 class="fc-section-title">Why Source Through AB Chem India?</h2>
        <p class="fc-section-sub">We remove the friction of multi-vendor management so your team can focus on science.</p>
        <div class="why-grid">
            <div class="why-card">
                <div class="why-card-icon">🏷️</div>
                <h4>One Invoice, Multiple Brands</h4>
                <p>Consolidate your purchases across 30+ brands into a single PO and invoice. Simplify accounts payable and vendor management.</p>
            </div>
            <div class="why-card">
                <div class="why-card-icon">✅</div>
                <h4>Authenticity Guaranteed</h4>
                <p>We source directly from authorised distributors. Every product ships with the original manufacturer's CoA and packaging intact.</p>
            </div>
            <div class="why-card">
                <div class="why-card-icon">🚚</div>
                <h4>Pan-India Logistics</h4>
                <p>Temperature-controlled and ambient shipping to any address in India. Regulatory documentation handled for controlled substances.</p>
            </div>
            <div class="why-card">
                <div class="why-card-icon">💼</div>
                <h4>GEM &amp; Institutional Supply</h4>
                <p>We are actively registering on the GEM portal and will be equipped to fulfil e-tenders and institutional supply orders for government labs, universities, and research institutes.</p>
            </div>
            <div class="why-card">
                <div class="why-card-icon">🕐</div>
                <h4>Fast Turnaround</h4>
                <p>Most in-stock items dispatched within 24–48 hours from Hyderabad. Urgent requirements handled with priority sourcing.</p>
            </div>
            <div class="why-card">
                <div class="why-card-icon">🧾</div>
                <h4>GST-Compliant Billing</h4>
                <p>All invoices issued with GSTIN <strong>36ACDFA7838D1ZG</strong> for seamless ITC claims. Credit periods available for approved institutions.</p>
            </div>
        </div>
    </section>

    <hr class="fc-divider">

    <!-- ── CTA ────────────────────────────────────────────────── -->
    <section class="fc-section">
        <div class="fc-cta-banner">
            <h2>Ready to Place a Fine Chemicals Order?</h2>
            <p>
                Send us your requirement list — CAS numbers, quantities, grade, and preferred brand (if any) —
                and we'll respond with availability and pricing within 24 hours.
            </p>
            <div class="fc-cta-btns">
                <a href="/contact" class="btn-hero-primary">📋 Send Enquiry</a>
                <a href="mailto:connect@abchem.co.in" class="btn-hero-outline">✉️ connect@abchem.co.in</a>
            </div>
        </div>
    </section>

</main>

<?php include 'footer.php'; ?>

<script>
/* ── Brand tab filter ─────────────────────────────────────────── */
(function () {
    const tabs  = document.querySelectorAll('.brand-tab');
    const cards = document.querySelectorAll('#brand-grid .brand-card');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');

            var filter = tab.dataset.filter;
            cards.forEach(function (card) {
                if (filter === 'all' || card.dataset.region === filter) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });
})();
</script>
</body>
</html>
