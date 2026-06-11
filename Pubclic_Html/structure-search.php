<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
error_reporting(E_ALL);

require_once __DIR__ . '/../private/functions.php';
$meta = get_seo_meta();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Structure Search | AB Chem India</title>
    <meta name="description" content="<?= e($meta['description'] ?? 'Structure search for pharmaceutical compounds') ?>">
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">

    <!-- JSME Molecular Editor -->
    <script src="/js/jsme/jsme.nocache.js"></script>
    <link href="/js/jsme/gwt/chrome/mosaic.css" rel="stylesheet">

<style>
/* ── JSME container ─────────────────────────────────────────── */
#jsme_container {
    width: 100%;
    max-width: 560px;
    height: 380px;
    margin: 0 auto 20px;
    border: 2px solid var(--border);
    border-radius: 10px;
    background: var(--surface);
    position: relative;
    overflow: hidden;
}

/* ── SMILES input ───────────────────────────────────────────── */
#smiles_input {
    width: 100%;
    max-width: 560px;
    padding: 12px 16px;
    margin: 4px auto 16px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-family: 'DM Mono', monospace;
    font-size: 0.875rem;
    display: block;
    background: var(--surface);
    color: var(--text);
    transition: border-color 0.2s;
}
#smiles_input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(2,132,199,.12); }

/* ── Search option pills ────────────────────────────────────── */
.search-options { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin: 0 0 16px; }
.search-options label {
    display: flex; align-items: center; gap: 7px;
    padding: 8px 16px;
    border: 1.5px solid var(--border);
    border-radius: 24px;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--muted);
    transition: all 0.2s;
    user-select: none;
}
.search-options label:hover { border-color: var(--accent); color: var(--accent); }
.search-options label.selected { border-color: var(--accent); background: #dbeafe; color: #1d4ed8; font-weight: 500; }
.search-options input[type="radio"] { display: none; }

/* ── Similarity threshold ───────────────────────────────────── */
.similarity-control {
    display: none;
    align-items: center;
    gap: 10px;
    justify-content: center;
    margin-bottom: 14px;
    font-size: 0.85rem;
    color: var(--muted);
}
.similarity-control.visible { display: flex; }
#threshold_slider { accent-color: var(--accent); width: 140px; }
#threshold_label  { font-weight: 600; color: var(--accent); min-width: 36px; }

/* ── Button group ───────────────────────────────────────────── */
.button-group { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

/* ── Status bar ─────────────────────────────────────────────── */
#search_status { margin: 14px auto 0; font-size: 0.875rem; min-height: 22px; max-width: 560px; text-align: center; }

/* ── Engine badge ───────────────────────────────────────────── */
.engine-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.72rem; padding: 3px 10px; border-radius: 20px;
    font-weight: 600; margin-left: 10px; vertical-align: middle;
}
.engine-rdkit   { background: #dcfce7; color: #166534; }
.engine-fallback{ background: #fef3c7; color: #92400e; }
.engine-keyword { background: #e0f2fe; color: #075985; }

/* ── Result card ────────────────────────────────────────────── */
.ss-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    /* Removed hover animation */
}
.ss-card:hover {
    /* Subtle hover only, no movement */
    border-color: var(--accent);
}

.ss-card-img {
    height: 150px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.ss-card-img img {
    width: 100%; height: 100%;
    object-fit: contain; padding: 10px;
}

/* Availability ribbon */
.ss-avail-ribbon {
    text-align: center;
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 3px 0;
}
.ss-avail-ribbon.in-stock   { background: #dcfce7; color: #166534; }
.ss-avail-ribbon.backorder  { background: #fef3c7; color: #92400e; }
.ss-avail-ribbon.on-request { background: #e0f2fe; color: #075985; }

.ss-card-body { padding: 14px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
.ss-card-title { font-size: 0.9rem; font-weight: 600; line-height: 1.4; margin: 0; }
.ss-card-title a { color: var(--text); text-decoration: none; }
.ss-card-title a:hover { color: var(--accent); }

.ss-specs { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; background: #f8fafc; padding: 9px; border-radius: 8px; }
.ss-spec  { font-size: 0.75rem; }
.ss-spec strong { display: block; color: var(--muted); font-size: 0.68rem; text-transform: uppercase; }
.ss-spec span   { color: var(--text); }

.ss-score-bar { height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden; margin: 2px 0; }
.ss-score-fill { height: 100%; border-radius: 2px; background: linear-gradient(90deg, #38bdf8, #0284c7); }

.ss-card-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 10px; border-top: 1px solid #f1f5f9; margin-top: auto; }
.ss-type-badge { font-size: 0.7rem; background: #e0f2fe; color: #0369a1; padding: 3px 9px; border-radius: 14px; }

/* ── Synonyms ────────────────────────────────────────────────── */
.synonym-wrap { display: flex; flex-wrap: wrap; gap: 4px; }
.synonym-tag  { font-size: 0.68rem; padding: 2px 7px; background: #f1f5f9; border-radius: 12px; color: var(--muted); border: 1px solid var(--border); }

/* ── Clean search box ───────────────────────────────────────── */
.structure-search-box-clean {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 32px;
    margin-bottom: 28px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
</style>
</head>
<body>
<?php include 'header.php'; ?>

<main style="max-width: 1200px; margin: 28px auto; padding: 0 24px;">
    <div class="structure-search-box-clean">
        <h1 style="margin:0 0 6px; color:var(--primary); font-size:1.5rem;">Structure Search</h1>
        <p style="margin:0 0 24px; color:var(--muted); font-size:0.9rem;">
            Draw a molecule in the editor, or paste a SMILES string below.
            Search uses <strong>RDKit</strong> for chemically accurate results.
        </p>

        <!-- JSME Editor -->
        <div id="jsme_container"></div>

        <!-- SMILES paste input -->
        <input type="text" id="smiles_input"
               placeholder="Or paste SMILES here — e.g. CC(C)CC1=CC=C(C=C1)C(C)C(=O)O"
               autocomplete="off" spellcheck="false">

        <!-- Search type -->
        <div class="search-options" id="search_options">
            <label class="selected">
                <input type="radio" name="search_type" value="exact" checked>
                Exact
            </label>
            <label>
                <input type="radio" name="search_type" value="substructure">
                Substructure
            </label>
            <label>
                <input type="radio" name="search_type" value="similar">
                Similarity
            </label>
        </div>

        <!-- Threshold slider -->
        <div class="similarity-control" id="similarity_control">
            <span>Min similarity:</span>
            <input type="range" id="threshold_slider" min="30" max="95" step="5" value="60">
            <span id="threshold_label">60%</span>
        </div>

        <!-- Buttons -->
        <div class="button-group">
            <button id="btn_search"  class="btn btn-primary">Search</button>
            <button id="btn_clear"   class="btn btn-outline">Clear</button>
            <button id="btn_example" class="btn btn-outline">Example (Ibuprofen)</button>
        </div>

        <div id="search_status"></div>
    </div>

    <!-- Results grid -->
    <div id="search-results-grid" class="grid"
         style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:18px;">
        <p style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;">
            Draw or enter a structure above to begin searching.
        </p>
    </div>
</main>

<?php include 'footer.php'; ?>

<script>
// ─── State ────────────────────────────────────────────────────────────────────
let jsmeApplet = null;
let jsmeReady  = false;

// ─── DOMContentLoaded ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const input      = document.getElementById('smiles_input');
    const grid       = document.getElementById('search-results-grid');
    const btnSearch  = document.getElementById('btn_search');
    const btnClear   = document.getElementById('btn_clear');
    const btnExample = document.getElementById('btn_example');
    const slider     = document.getElementById('threshold_slider');
    const thrLabel   = document.getElementById('threshold_label');
    const simCtrl    = document.getElementById('similarity_control');

    // Search type radios
    document.querySelectorAll('input[name="search_type"]').forEach(radio => {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.search-options label').forEach(l => l.classList.remove('selected'));
            this.closest('label').classList.add('selected');
            simCtrl.classList.toggle('visible', this.value === 'similar');
        });
    });

    // Similarity threshold slider
    slider.addEventListener('input', () => {
        thrLabel.textContent = slider.value + '%';
    });

    // Text input → JSME sync
    input.addEventListener('input', () => {
        const val = input.value.trim();
        if (val && jsmeReady && jsmeApplet) {
            try { jsmeApplet.readMolecule(val); } catch (e) {}
        }
    });

    // Example button
    btnExample.addEventListener('click', () => {
        const ibuprofen = 'CC(C)CC1=CC=C(C=C1)C(C)C(=O)O';
        input.value = ibuprofen;
        if (jsmeReady && jsmeApplet) {
            try { jsmeApplet.readMolecule(ibuprofen); } catch (e) {}
        }
        setStatus('Ibuprofen loaded — click Search to find matches', 'var(--accent)', 3000);
    });

    // Clear button
    btnClear.addEventListener('click', () => {
        input.value = '';
        if (jsmeReady && jsmeApplet) {
            try { jsmeApplet.reset(); } catch (e) {}
        }
        grid.innerHTML = '<p style="text-align:center;padding:40px;color:var(--muted);grid-column:1/-1;">Cleared. Draw or paste a structure to search.</p>';
        setStatus('', '');
    });

    // Search button
    btnSearch.addEventListener('click', async () => {
        let smiles = (input.value || '').trim();
        if ((!smiles || smiles === '[#1]') && jsmeReady && jsmeApplet) {
            try { smiles = jsmeApplet.smiles() || ''; } catch (e) {}
        }
        smiles = smiles.trim();

        if (!smiles || smiles === '[#1]') {
            setStatus('Please draw a molecule or paste a SMILES string first.', 'var(--danger)');
            return;
        }

        const searchType = document.querySelector('input[name="search_type"]:checked')?.value || 'exact';
        const threshold  = parseFloat(slider.value) / 100;

        btnSearch.disabled = true;
        setStatus('Searching...', 'var(--accent)');
        grid.innerHTML = '<p style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;">Searching database...</p>';

        try {
            const res = await fetch('/api_structure_search.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ smiles, search_type: searchType, threshold }),
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            const results = data.results || [];
            const total   = data.total   || results.length;
            const engine  = data.engine  || 'rdkit';

            const engineBadge = engine === 'rdkit'
                ? '<span class="engine-badge engine-rdkit">RDKit</span>'
                : engine === 'keyword'
                    ? '<span class="engine-badge engine-keyword">Keyword match</span>'
                    : '<span class="engine-badge engine-fallback">PHP fallback</span>';

            if (results.length === 0) {
                grid.innerHTML = `<div style="padding:48px;text-align:center;color:var(--muted);grid-column:1/-1;">
                    <h3>No compounds found</h3>
                    <p style="margin-top:6px">Try Substructure or Similarity search, or simplify the structure.</p>
                </div>`;
                setStatus('No matches for ' + searchType + ' search ' + engineBadge, 'var(--muted)');
            } else {
                grid.innerHTML =
                    `<div style="grid-column:1/-1;padding:12px 16px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;display:flex;align-items:center;flex-wrap:wrap;gap:8px;">
                        <strong>${total} compound${total !== 1 ? 's' : ''} found</strong>
                        <em style="color:var(--muted);font-size:.85rem">${searchType}</em>
                        ${engineBadge}
                    </div>`
                    + results.map(renderCard).join('');

                setStatus(total + ' compound' + (total !== 1 ? 's' : '') + ' found ' + engineBadge, 'var(--success)');
            }

        } catch (err) {
            console.error('Search error:', err);
            grid.innerHTML = `<p style="text-align:center;padding:40px;color:var(--danger);grid-column:1/-1;">Search failed: ${esc(err.message)}</p>`;
            setStatus(esc(err.message), 'var(--danger)');
        } finally {
            btnSearch.disabled = false;
        }
    });
});

// ─── JSME Global Callback ─────────────────────────────────────────────────────
function jsmeOnLoad() {
    try {
        jsmeApplet = new JSApplet.JSME('jsme_container', '100%', '380px', {
            options: 'oldlook,starburst,noschiff'
        });
        jsmeReady = true;

        // JSME → input sync
        if (typeof jsmeApplet.setAfterChangeCallback === 'function') {
            jsmeApplet.setAfterChangeCallback(() => {
                try {
                    const s = jsmeApplet.smiles();
                    const inp = document.getElementById('smiles_input');
                    if (inp && s && s !== '[#1]') inp.value = s;
                } catch (e) {}
            });
        }
    } catch (err) {
        console.error('JSME init failed:', err);
    }
}

// ─── Card renderer ─────────────────────────────────────────────────────────────
function renderCard(p) {
    const hasImg   = p.image_url && p.image_url !== 'NA' && p.image_url !== '';
    const imgSrc   = hasImg ? esc(p.image_url) : '/logo.png';
    const imgCls   = hasImg ? '' : ' logo-fallback';
    const score    = Math.round(p.match_score || 0);
    const avl      = (p.availability || '').toLowerCase();
    const availCls = avl.includes('stock') ? 'in-stock' : avl.includes('request') ? 'on-request' : 'backorder';

    const synHtml = p.synonyms && p.synonyms !== 'NA'
        ? '<div class="synonym-wrap">'
          + p.synonyms.split('|').map(s => s.trim()).filter(Boolean)
                .slice(0, 4)
                .map(s => `<span class="synonym-tag">${esc(s)}</span>`)
                .join('')
          + '</div>'
        : '';

    return `
    <article class="ss-card">
        <div class="ss-card-img">
            <img src="${imgSrc}" alt="${esc(p.product_name)}" loading="lazy"
                 class="${imgCls}"
                 onerror="this.src='/logo.png';this.className='logo-fallback'">
        </div>
        <div class="ss-avail-ribbon ${availCls}">${esc(p.availability || 'In Stock')}</div>
        <div class="ss-card-body">
            <h3 class="ss-card-title">
                <a href="/product/${esc(p.slug)}" title="${esc(p.product_name)}">${esc(p.product_name)}</a>
            </h3>
            ${synHtml}
            <div class="ss-specs">
                <div class="ss-spec"><strong>CAS</strong><span>${esc(p.cas_number || 'N/A')}</span></div>
                <div class="ss-spec"><strong>MF</strong><span>${esc(p.molecular_formula || 'N/A')}</span></div>
                <div class="ss-spec"><strong>MW</strong><span>${esc(p.molecular_weight || 'N/A')}</span></div>
                <div class="ss-spec"><strong>Purity</strong><span>${esc(p.purity || 'N/A')}</span></div>
            </div>
            <div title="Match score: ${score}%">
                <div class="ss-score-bar"><div class="ss-score-fill" style="width:${score}%"></div></div>
                <small style="color:var(--muted);font-size:.7rem">Match score: ${score}%</small>
            </div>
            <div class="ss-card-footer">
                <span class="ss-type-badge">${esc(p.product_type || 'Chemical')}</span>
                <a href="/product/${esc(p.slug)}" class="btn btn-outline btn-sm">Details</a>
            </div>
        </div>
    </article>`;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function setStatus(msg, color, clearAfterMs = 0) {
    const el = document.getElementById('search_status');
    if (!el) return;
    el.innerHTML   = msg;
    el.style.color = color;
    if (clearAfterMs) setTimeout(() => { el.innerHTML = ''; }, clearAfterMs);
}

function esc(t) {
    if (t == null) return '';
    const d = document.createElement('div');
    d.textContent = String(t);
    return d.innerHTML;
}
</script>
</body>
</html>