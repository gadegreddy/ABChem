// ── Product URL helper (shared with search.js) ────────────────────────────────
// New format: /product/{url_slug}/{ab_catalog_number}-{url_token}
// Falls back to opaque token-only or legacy text slug for older rows.
function catalogProductUrl(p) {
    if (p.ab_catalog_number && p.url_token) {
        var token = encodeURIComponent(p.ab_catalog_number + '-' + p.url_token);
        if (p.url_slug) {
            return '/product/' + encodeURIComponent(p.url_slug) + '/' + token;
        }
        return '/product/' + token;
    }
    return '/product/' + encodeURIComponent(p.slug || '');
}

// Wait for the page to fully load before touching the DOM
document.addEventListener('DOMContentLoaded', function() {
    // Run synonym renderer only on pages where it exists
    const synonymContainers = document.querySelectorAll('.synonym-tags');
    if (synonymContainers.length > 0) {
        synonymContainers.forEach(el => {
            const raw = el.dataset.raw || '';
            el.innerHTML = raw.split('|')
                .map(s => s.trim())
                .filter(Boolean)
                .map(s => `<span class="synonym-tag">${escapeHtml(s)}</span>`)
                .join('');
        });
    }
});


/* ==============================================================
   CATALOG — Filters, AJAX Loading, Pagination
   ============================================================== */
document.addEventListener('DOMContentLoaded', function() {
    // Fallback if window.CATALOG_STATE isn't defined
    const S = window.CATALOG_STATE || {
        types: [], sortField: 'product_name', sortDir: 'asc',
        perPage: 20, avail: [], purityMin: 0, mwMin: 0, mwMax: 0, parentDrugs: []
    };
    // Ensure parentDrugs always exists
    S.parentDrugs = S.parentDrugs || [];

    const PARENT_PAGE_SIZE = 10;  // items shown before "show all"
    let allParentDrugs = [];      // full list from API
    let parentExpanded = false;   // show-all state

    let filterOptions = null;

    // ─── Boot ────────────────────────────────────────────────────────────────
    (async function boot() {
        await loadFilterOptions();
        applyStateToUI();
        loadCatalog(buildParams());
    })();

    // ─── Load dynamic filter options from API ────────────────────────────────
    async function loadFilterOptions() {
        try {
            const res = await fetch('/api/data?action=filter_options');
            filterOptions = await res.json();
            renderTypesPills(filterOptions.types);
            renderParentDrugFilter(filterOptions.parent_drugs || []);
            renderAvailToggles(filterOptions.avail);
            renderPurityBtns(filterOptions.purity_raw, filterOptions.mw_min, filterOptions.mw_max);
        } catch (e) {
            console.error('filter_options failed:', e);
            document.getElementById('type-pills').innerHTML = '<span class="sb-hint">Could not load types</span>';
            document.getElementById('parent-drug-list').innerHTML = '<span class="sb-hint">Could not load</span>';
            document.getElementById('avail-rows').innerHTML = '<span class="sb-hint">Could not load</span>';
        }
    }

    // ─── FEAT-25: Cascading filter refresh ───────────────────────────────────
    function buildFilterParams() {
        const p = new URLSearchParams();
        S.types.forEach(t => p.append('type[]', t));
        S.parentDrugs.forEach(d => p.append('parent_drug[]', d));
        S.avail.forEach(a => p.append('avail[]', a));
        if (S.purityMin > 0) p.set('purity_min', S.purityMin);
        if (S.mwMin > 0) p.set('mw_min', S.mwMin);
        if (S.mwMax > 0) p.set('mw_max', S.mwMax);
        return p;
    }

    async function refreshFilterOptions() {
        try {
            const fp = buildFilterParams();
            const url = '/api/data?action=filter_options' + (fp.toString() ? '&' + fp.toString() : '');
            const res = await fetch(url);
            const data = await res.json();
            if (data.error) return;
            renderTypesPills(data.types);
            renderParentDrugFilter(data.parent_drugs || []);
            renderAvailToggles(data.avail);
            renderPurityBtns(data.purity_raw, data.mw_min, data.mw_max);
        } catch (e) {
            console.warn('refreshFilterOptions failed:', e);
        }
    }

    // ─── Render dynamic controls ──────────────────────────────────────────────
    function renderTypesPills(types) {
        const wrap = document.getElementById('type-pills');
        const cnt  = document.getElementById('type-count');
        if (!types || !types.length) { wrap.innerHTML = '<span class="sb-hint">No types found</span>'; return; }
        cnt.textContent = types.length;
        wrap.innerHTML = types.map(t =>
            `<button class="type-pill${S.types.includes(t.label) ? ' active' : ''}"
                data-type="${escHtml(t.label)}" onclick="toggleType(this)"
                title="${escHtml(t.label)} (${t.cnt} products)">
                ${escHtml(t.label)} <span class="pill-cnt">${t.cnt}</span>
            </button>`
        ).join('');
    }

    // ─── Parent Drug filter ───────────────────────────────────────────────────
    function renderParentDrugFilter(drugs) {
        allParentDrugs = drugs;
        const cnt = document.getElementById('parent-count');
        if (cnt) cnt.textContent = drugs.length || '';
        _paintParentDrugList(drugs, '');
    }

    function _paintParentDrugList(drugs, searchTerm) {
        const wrap    = document.getElementById('parent-drug-list');
        const moreBtn = document.getElementById('parent-show-more');
        const moreCnt = document.getElementById('parent-show-more-count');
        if (!drugs.length) {
            wrap.innerHTML = `<span class="sb-hint">${searchTerm ? 'No matches' : 'No data'}</span>`;
            if (moreBtn) moreBtn.style.display = 'none';
            return;
        }
        const visible = parentExpanded ? drugs : drugs.slice(0, PARENT_PAGE_SIZE);
        wrap.innerHTML = visible.map(d =>
            `<label class="pd-item${S.parentDrugs.includes(d.label) ? ' checked' : ''}">
                <input type="checkbox" class="pd-check"
                    value="${escHtml(d.label)}"
                    ${S.parentDrugs.includes(d.label) ? 'checked' : ''}
                    onchange="toggleParentDrug(this)">
                <span class="pd-name">${escHtml(d.label)}</span>
                <span class="pd-cnt">${d.cnt}</span>
            </label>`
        ).join('');
        if (moreBtn) {
            const hidden = drugs.length - PARENT_PAGE_SIZE;
            if (!searchTerm && hidden > 0) {
                moreBtn.style.display = '';
                moreBtn.textContent   = parentExpanded ? 'Show less' : `Show all (${drugs.length})`;
            } else {
                moreBtn.style.display = 'none';
            }
        }
    }

    window.filterParentDrugList = function(term) {
        const t = term.toLowerCase().trim();
        const filtered = t ? allParentDrugs.filter(d => d.label.toLowerCase().includes(t)) : allParentDrugs;
        _paintParentDrugList(filtered, t);
    };

    window.toggleParentDrugMore = function() {
        parentExpanded = !parentExpanded;
        const term = (document.getElementById('parent-search') || {}).value || '';
        window.filterParentDrugList(term);
    };

    window.toggleParentDrug = function(cb) {
        const val = cb.value;
        if (cb.checked) {
            if (!S.parentDrugs.includes(val)) S.parentDrugs.push(val);
        } else {
            S.parentDrugs = S.parentDrugs.filter(x => x !== val);
        }
        cb.closest('.pd-item').classList.toggle('checked', cb.checked);
        updateChips();
        debouncedLoad(); // FEAT-24: auto-fire
    };

    // ─── Availability toggles (bug-fixed: no implicit default) ───────────────
    function renderAvailToggles(avail) {
        const wrap = document.getElementById('avail-rows');
        if (!avail || !avail.length) { wrap.innerHTML = '<span class="sb-hint">No availability data</span>'; return; }
        const dotColour = {'In Stock': '#16a34a', 'Backorder': '#d97706', 'On Request': '#64748b', 'Discontinued': '#dc2626'};
        wrap.innerHTML = avail.map(a => {
            // Only mark as ON if this value is explicitly in S.avail from URL state
            const on  = S.avail.includes(a.label);
            const col = dotColour[a.label] || '#64748b';
            return `<div class="avail-row${on ? ' on' : ''}" data-avail="${escHtml(a.label)}" onclick="toggleAvail(this)">
                <span class="avail-dot" style="background:${col}"></span>
                <span class="avail-label">${escHtml(a.label)} <em>(${a.cnt})</em></span>
                <span class="avail-switch"><span class="avail-thumb"></span></span>
            </div>`;
        }).join('');
    }

    function renderPurityBtns(rawPurities, mwMin, mwMax) {
        if (mwMin != null && mwMax != null) {
            document.getElementById('mw-range-hint').textContent = `g/mol · DB range: ${Math.round(mwMin)} – ${Math.round(mwMax)}`;
            document.getElementById('mw-min').placeholder = `Min (${Math.round(mwMin)})`;
            document.getElementById('mw-max').placeholder = `Max (${Math.round(mwMax)})`;
        }
        const thresholds = [95, 97, 98, 99, 99.5];
        const seen = new Set();
        (rawPurities || []).forEach(p => { const m = String(p).match(/[\d.]+/); if (m) seen.add(parseFloat(m[0])); });
        const valid = thresholds.filter(t => [...seen].some(v => v >= t));
        if (!valid.length) { document.getElementById('purity-btns').innerHTML = '<span class="sb-hint">No purity data</span>'; return; }
        document.getElementById('purity-btns').innerHTML = ['Any', ...valid].map(t => {
            const isAny = t === 'Any';
            const val = isAny ? 0 : t;
            const active = isAny ? S.purityMin === 0 : S.purityMin === val;
            return `<button class="purity-btn${active ? ' active' : ''}" data-min="${val}" onclick="setPurity(this)">${isAny ? 'Any' : `≥${t}%`}</button>`;
        }).join('');
    }

    // ─── Debounce helper (FEAT-24 + FEAT-25) ─────────────────────────────────
    let _debounceTimer = null;
    function debouncedLoad(ms = 300) {
        clearTimeout(_debounceTimer);
        _debounceTimer = setTimeout(() => {
            loadCatalog(buildParams(1));
            refreshFilterOptions(); // FEAT-25: update cascading counts in other filter panes
        }, ms);
    }

    // ─── UI helpers & Event Handlers ──────────────────────────────────────────
    window.toggleSection = function(btn) {
        const sec = btn.closest('.sb-section');
        const open = sec.classList.toggle('open');
        btn.querySelector('.sb-chevron').style.transform = open ? 'rotate(0deg)' : 'rotate(-90deg)';
    };
    // FEAT-24: auto-fire on change (debounced ~300 ms) — no Apply button needed
    window.toggleType  = function(btn) { btn.classList.toggle('active'); S.types = [...document.querySelectorAll('.type-pill.active')].map(b => b.dataset.type); updateChips(); debouncedLoad(); };
    window.toggleAvail = function(row) { row.classList.toggle('on'); S.avail = [...document.querySelectorAll('.avail-row.on')].map(r => r.dataset.avail); updateChips(); debouncedLoad(); };
    window.setPurity   = function(btn) { document.querySelectorAll('.purity-btn').forEach(b => b.classList.remove('active')); btn.classList.add('active'); S.purityMin = parseFloat(btn.dataset.min); updateChips(); debouncedLoad(); };
    window.onSortChange = function(val) { S.sortField = val || document.getElementById('sort-field').value; const sf = document.getElementById('sort-field'); if (sf && val) sf.value = val; updateChips(); debouncedLoad(100); };
    window.setSortDir   = function(dir) { S.sortDir = dir; document.getElementById('dir-asc').classList.toggle('active', dir === 'asc'); document.getElementById('dir-desc').classList.toggle('active', dir === 'desc'); updateChips(); debouncedLoad(100); };
    window.onMwChange   = function() { S.mwMin = parseFloat(document.getElementById('mw-min').value) || 0; S.mwMax = parseFloat(document.getElementById('mw-max').value) || 0; updateChips(); debouncedLoad(600); };
    window.setPerPage   = function(btn) { document.querySelectorAll('.pp-btn').forEach(b => b.classList.remove('active')); btn.classList.add('active'); S.perPage = parseInt(btn.dataset.n); loadCatalog(buildParams()); };

    function applyStateToUI() {
        const sf = document.getElementById('sort-field');
        if (sf) sf.value = S.sortField || 'product_name';
        window.setSortDir(S.sortDir || 'asc');
        if (S.mwMin) document.getElementById('mw-min').value = S.mwMin;
        if (S.mwMax) document.getElementById('mw-max').value = S.mwMax;
    }

    // ─── Active chips ─────────────────────────────────────────────────────────
    function updateChips() {
        const chips = [];
        if (S.sortField && S.sortField !== 'product_name') {
            const labels = {purity:'Purity', molecular_weight:'MW', cas_number:'CAS'};
            chips.push({ label: `Sort: ${labels[S.sortField] || S.sortField} ${S.sortDir === 'desc' ? '↓' : '↑'}`, remove: () => { S.sortField = 'product_name'; document.getElementById('sort-field').value = 'product_name'; } });
        }
        S.types.forEach(t => chips.push({ label: t, remove: () => { S.types = S.types.filter(x => x !== t); document.querySelectorAll('.type-pill').forEach(b => { if (b.dataset.type === t) b.classList.remove('active'); }); }}));
        S.parentDrugs.forEach(d => chips.push({ label: `Parent: ${d}`, remove: () => {
            S.parentDrugs = S.parentDrugs.filter(x => x !== d);
            document.querySelectorAll('.pd-check').forEach(cb => { if (cb.value === d) { cb.checked = false; cb.closest('.pd-item').classList.remove('checked'); } });
        }}));
        if (S.purityMin > 0) chips.push({ label: `Purity ≥${S.purityMin}%`, remove: () => { S.purityMin = 0; document.querySelectorAll('.purity-btn').forEach(b => b.classList.toggle('active', b.dataset.min === '0')); }});
        S.avail.forEach(a => chips.push({ label: `Avail: ${a}`, remove: () => { S.avail = S.avail.filter(x => x !== a); document.querySelectorAll('.avail-row').forEach(r => { if (r.dataset.avail === a) r.classList.remove('on'); }); }}));
        if (S.mwMin > 0 || S.mwMax > 0) chips.push({ label: `MW: ${S.mwMin||''}–${S.mwMax||''}`, remove: () => { S.mwMin = 0; S.mwMax = 0; document.getElementById('mw-min').value = ''; document.getElementById('mw-max').value = ''; }});
        const wrap = document.getElementById('active-chips');
        if (!chips.length) { wrap.innerHTML = ''; return; }
        wrap.innerHTML = chips.map((c, i) => `<span class="chip" onclick="removeChip(${i})">${escHtml(c.label)}<span class="chip-x">&#215;</span></span>`).join('');
        wrap.querySelectorAll('.chip').forEach((el, i) => { el._remove = chips[i].remove; });
    }
    window.removeChip = function(i) { const chips = document.querySelectorAll('#active-chips .chip'); if (chips[i] && chips[i]._remove) chips[i]._remove(); updateChips(); applyAndLoad(); };
    window.clearAllFilters = function() {
        S.types = []; S.avail = []; S.purityMin = 0; S.mwMin = 0; S.mwMax = 0;
        S.sortField = 'product_name'; S.sortDir = 'asc'; S.parentDrugs = [];
        document.querySelectorAll('.type-pill, .purity-btn').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.avail-row').forEach(el => el.classList.remove('on'));
        document.querySelectorAll('.pd-check').forEach(cb => { cb.checked = false; cb.closest('.pd-item').classList.remove('checked'); });
        if (document.getElementById('parent-search')) document.getElementById('parent-search').value = '';
        window.filterParentDrugList('');
        document.getElementById('sort-field').value = 'product_name';
        window.setSortDir('asc');
        document.getElementById('mw-min').value = '';
        document.getElementById('mw-max').value = '';
        updateChips(); applyAndLoad();
        refreshFilterOptions(); // FEAT-25: reset cascading counts
    };

    // ─── Build URL & Load Catalog ─────────────────────────────────────────────
    function buildParams(page = 1) {
        const p = new URLSearchParams();
        if (S.sortField && S.sortField !== 'product_name') p.set('sort_field', S.sortField);
        if (S.sortDir === 'desc') p.set('sort_dir', 'desc');
        S.types.forEach(t => p.append('type[]', t));
        S.parentDrugs.forEach(d => p.append('parent_drug[]', d));
        S.avail.forEach(a => p.append('avail[]', a));
        if (S.purityMin > 0) p.set('purity_min', S.purityMin);
        if (S.mwMin > 0) p.set('mw_min', S.mwMin);
        if (S.mwMax > 0) p.set('mw_max', S.mwMax);
        p.set('per_page', S.perPage || 20);
        if (page > 1) p.set('page', page);
        return p;
    }
    window.applyAndLoad = function() { loadCatalog(buildParams(1)); };

    // ── FEAT-23: Mobile filter drawer ────────────────────────────────────────
    window.openFilterDrawer = function() {
        const sidebar  = document.getElementById('sidebar');
        const openBtn  = document.getElementById('btn-open-drawer');
        if (!sidebar) return;
        if (sidebar.classList.contains('drawer-open')) {
            window.closeFilterDrawer();
            return;
        }
        sidebar.classList.add('drawer-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    };
    window.closeFilterDrawer = function() {
        const sidebar  = document.getElementById('sidebar');
        const openBtn  = document.getElementById('btn-open-drawer');
        if (!sidebar) return;
        sidebar.classList.remove('drawer-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    };
    // Close drawer on Escape key
    document.addEventListener('keydown', e => { if (e.key === 'Escape') window.closeFilterDrawer(); });

    async function loadCatalog(params) {
        const grid = document.getElementById('product-grid');
        const rangeEl = document.getElementById('result-range');
        const countEl = document.getElementById('result-count');
        grid.innerHTML = chromLoader('Loading compounds…');
        document.getElementById('pagination').innerHTML = '';
        history.replaceState({}, '', '?' + params.toString());
        try {
            const urlQ = new URLSearchParams(location.search).get('q') || '';
            if (urlQ) params.set('q', urlQ);
            const res = await fetch('/api/data?' + params.toString());
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            if (data.error) throw new Error(data.message || 'API error');
            const total = data.total || 0; const page = data.page || 1; const limit = data.limit || 20;
            const start = (page - 1) * limit + 1; const end = Math.min(page * limit, total);
            rangeEl.textContent = total > 0 ? `Showing ${start}–${end} of ${total}` : 'No results';
            countEl.textContent = `${total} compound${total !== 1 ? 's' : ''}`;
            // FEAT-23: update mobile result count badge
            const mrc = document.getElementById('mobile-result-count');
            if (mrc) mrc.textContent = total > 0 ? `${total}` : '';
            if (data.data && data.data.length) { renderProducts(data.data); }
            else { grid.innerHTML = `<div class="empty-state"><img src="/logo.png" alt="AB Chem" style="width:70px;opacity:.35;margin-bottom:16px"><h3>No compounds match your filters</h3><p>Try adjusting or <button onclick="window.clearAllFilters()" class="link-btn">clearing all filters</button></p></div>`; }
            renderPagination(page, data.pages, params);
        } catch (err) {
            console.error(err);
            grid.innerHTML = `<div class="empty-state"><h3 style="color:#ef4444">Error loading catalog</h3><p>${escHtml(err.message)}</p><button onclick="window.applyAndLoad()" class="btn btn-primary" style="margin-top:12px">Try again</button></div>`;
        }
    }

    // ── FEAT-26: Image zoom modal ─────────────────────────────────────────────
    (function buildZoomModal() {
        if (document.getElementById('img-zoom-modal')) return; // already built
        const m = document.createElement('div');
        m.id = 'img-zoom-modal';
        m.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.75);align-items:center;justify-content:center;cursor:zoom-out';
        m.innerHTML = '<img id="img-zoom-large" style="max-width:90vw;max-height:90vh;border-radius:8px;box-shadow:0 24px 60px rgba(0,0,0,.6);background:#fff;padding:8px">';
        m.addEventListener('click', () => { m.style.display = 'none'; });
        document.body.appendChild(m);
    })();

    window.openImageZoom = function(src, alt) {
        const m = document.getElementById('img-zoom-modal');
        if (!m) return;
        document.getElementById('img-zoom-large').src  = src;
        document.getElementById('img-zoom-large').alt  = alt;
        m.style.display = 'flex';
    };

    function renderProducts(items) {
        document.getElementById('product-grid').innerHTML = items.map(p => {
            const avl    = (p.availability || '').toLowerCase();
            const badgeCls = avl.includes('stock') ? 'in-stock' : avl.includes('order') ? 'backorder' : 'on-request';
            const hasImg = (p.image_url && p.image_url !== 'NA' && p.image_url !== '');
            const imgSrc = hasImg ? escHtml(p.image_url) : '/logo.png';
            const imgCls = hasImg ? 'prod-img' : 'prod-img prod-img--logo';

            // FEAT-27: ABChem catalog number badge
            const catBadge = p.ab_catalog_number
                ? `<span class="abc-catalog-badge" title="AB Chem catalog number">${escHtml(p.ab_catalog_number)}</span>`
                : '';

            // FEAT-26: clickable zoom only on real structure images
            const imgWrap = hasImg
                ? `<div class="product-card-image product-card-image--zoomable" onclick="openImageZoom('${imgSrc}','${escHtml(p.product_name)}')" title="Click to zoom">
                     <img src="${imgSrc}" alt="${escHtml(p.product_name)}" class="${imgCls}" loading="lazy" onerror="this.src='/logo.png';this.className='prod-img prod-img--logo';this.closest('.product-card-image').onclick=null;this.closest('.product-card-image').classList.remove('product-card-image--zoomable')">
                     <span class="zoom-hint">🔍</span>
                   </div>`
                : `<div class="product-card-image"><img src="${imgSrc}" alt="${escHtml(p.product_name)}" class="${imgCls}" loading="lazy"></div>`;

            return `<article class="product-card">
                ${imgWrap}
                <div class="avail-ribbon avail-ribbon--${badgeCls}">${escHtml(p.availability || 'In Stock')}</div>
                <div class="product-card-content">
                    ${catBadge}
                    <h3 class="product-card-title"><a href="${catalogProductUrl(p)}" title="${escHtml(p.product_name)}">${escHtml(p.product_name)}</a></h3>
                    <div class="product-specs-grid">
                        <div class="spec-item"><span class="spec-label">CAS</span><span class="spec-value">${escHtml(p.cas_number || 'N/A')}</span></div>
                        <div class="spec-item"><span class="spec-label">MF</span><span class="spec-value">${escHtml(p.molecular_formula || 'N/A')}</span></div>
                        <div class="spec-item"><span class="spec-label">MW</span><span class="spec-value">${fmtNum(p.molecular_weight)}</span></div>
                        <div class="spec-item"><span class="spec-label">Purity</span><span class="spec-value">${escHtml(p.purity || 'N/A')}</span></div>
                    </div>
                    <div class="product-card-footer"><span class="product-type-badge">${escHtml(p.product_type || 'Chemical')}</span><a href="${catalogProductUrl(p)}" class="view-details-btn">View →</a></div>
                </div>
            </article>`;
        }).join('');
    }

    function renderPagination(current, total, params) {
        const pag = document.getElementById('pagination');
        if (total <= 1) { pag.innerHTML = ''; return; }
        const make = (page, label, cls='') => { const p = new URLSearchParams(params); p.set('page', page); return `<a href="?${p}" class="pag-btn${cls}" data-page="${page}">${label}</a>`; };
        let html = '';
        html += current > 1 ? make(current-1, '← Prev') : '<span class="pag-btn disabled">← Prev</span>';
        const maxV = 5; let s = Math.max(1, current - Math.floor(maxV/2)); let e = Math.min(total, s + maxV - 1);
        if (e - s + 1 < maxV) s = Math.max(1, e - maxV + 1);
        if (s > 1) { html += make(1, '1'); if (s > 2) html += '<span class="pag-btn pag-dots">…</span>'; }
        for (let i = s; i <= e; i++) html += make(i, i, i === current ? ' active' : '');
        if (e < total) { if (e < total-1) html += '<span class="pag-btn pag-dots">…</span>'; html += make(total, total); }
        html += current < total ? make(current+1, 'Next →') : '<span class="pag-btn disabled">Next →</span>';
        pag.innerHTML = html;
        pag.querySelectorAll('a[data-page]').forEach(a => { a.addEventListener('click', ev => { ev.preventDefault(); const p = new URLSearchParams(params); p.set('page', a.dataset.page); loadCatalog(p); document.querySelector('.catalog-content').scrollIntoView({ behavior: 'smooth' }); }); });
    }

    // ─── Utils ────────────────────────────────────────────────────────────────
    function escHtml(t) { if (t == null) return ''; const d = document.createElement('div'); d.textContent = String(t); return d.innerHTML; }
    function fmtNum(n) { if (!n || n === 'NA') return 'N/A'; const v = parseFloat(n); return isNaN(v) ? 'N/A' : v.toFixed(2).replace(/\.00$/, ''); }
});
