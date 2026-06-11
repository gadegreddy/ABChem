/* =============================================================
   search.js — Simple search + Advanced multi-line batch search
   Depends on: escapeHtml(), formatNumber() from utils.js
   ============================================================= */

// ── Build product URL from API row ────────────────────────────────────────────
// New format: /product/{url_slug}/{ab_catalog_number}-{url_token}
function productUrl(p) {
    if (p.ab_catalog_number && p.url_token) {
        var token = encodeURIComponent(p.ab_catalog_number + '-' + p.url_token);
        if (p.url_slug) {
            return '/product/' + encodeURIComponent(p.url_slug) + '/' + token;
        }
        return '/product/' + token;
    }
    return '/product/' + encodeURIComponent(p.slug || '');
}

// ── Render a single product card (matches catalog page CSS classes) ───────────
function renderCard(product) {
    var hasImg    = product.image_url && product.image_url !== 'NA' && product.image_url !== '';
    var imgSrc    = hasImg ? escapeHtml(product.image_url) : '/logo.png';
    var imgCls    = hasImg ? 'prod-img' : 'prod-img prod-img--logo';
    var avl       = (product.availability || '').toLowerCase();
    var ribbon    = avl.includes('stock') ? 'in-stock' : avl.includes('order') ? 'backorder' : 'on-request';
    var catNum    = product.ab_catalog_number
        ? '<div class="spec-item"><span class="spec-label">Cat. No.</span>' +
          '<span class="spec-value cat-num">' + escapeHtml(product.ab_catalog_number) + '</span></div>'
        : '';

    return '<article class="product-card">' +
        '<div class="product-card-image">' +
            '<img src="' + imgSrc + '" alt="' + escapeHtml(product.product_name) + '"' +
            ' class="' + imgCls + '" loading="lazy"' +
            ' onerror="this.onerror=null;this.src=\'/logo.png\';this.className=\'prod-img prod-img--logo\';">' +
        '</div>' +
        '<div class="avail-ribbon avail-ribbon--' + ribbon + '">' +
            escapeHtml(product.availability || 'On Request') +
        '</div>' +
        '<div class="product-card-content">' +
            '<h3 class="product-card-title">' +
                '<a href="' + productUrl(product) + '" title="' + escapeHtml(product.product_name) + '">' +
                    escapeHtml(product.product_name) +
                '</a>' +
            '</h3>' +
            '<div class="product-specs">' +
                catNum +
                '<div class="spec-item"><span class="spec-label">CAS</span>' +
                    '<span class="spec-value">' + escapeHtml(product.cas_number || 'N/A') + '</span></div>' +
                '<div class="spec-item"><span class="spec-label">Purity</span>' +
                    '<span class="spec-value">' + escapeHtml(product.purity || 'N/A') + '</span></div>' +
                '<div class="spec-item"><span class="spec-label">MW</span>' +
                    '<span class="spec-value">' + formatNumber(product.molecular_weight) + '</span></div>' +
                '<div class="spec-item"><span class="spec-label">Formula</span>' +
                    '<span class="spec-value">' + escapeHtml(product.molecular_formula || 'N/A') + '</span></div>' +
            '</div>' +
            '<div class="product-card-footer">' +
                '<span class="product-type-badge">' + escapeHtml(product.product_type || 'Chemical') + '</span>' +
                '<a href="' + productUrl(product) + '" class="view-details-btn">Details →</a>' +
            '</div>' +
        '</div>' +
    '</article>';
}

// ── Simple search: auto-fires on ?q= pages (header bar search) ───────────────
(function () {
    var init = window.SEARCH_INIT || {};
    if (!init.q || init.advMode) return;

    function runSimpleSearch(mode) {
        var div = document.getElementById('search-results');
        div.innerHTML = chromLoader('Searching…');

        fetch('/api/data?q=' + encodeURIComponent(init.q) +
              '&search_type=' + encodeURIComponent(init.searchType) +
              '&match_mode=' + encodeURIComponent(mode || 'any') +
              '&per_page=50')
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                if (data.error) {
                    div.innerHTML = '<div class="error-state"><h3>Search Error</h3><p>' +
                        escapeHtml(data.message || 'An error occurred') + '</p></div>';
                    return;
                }
                if (!data.data || data.data.length === 0) {
                    div.innerHTML =
                        '<div class="empty-state">' +
                        '<img src="/logo.png" style="width:60px;margin-bottom:20px;opacity:0.5;">' +
                        '<h3>No compounds found</h3>' +
                        '<p>No results for "<strong>' + escapeHtml(init.q) + '</strong>"' +
                        (mode === 'exact' ? ' in exact mode. Try <strong>Any match</strong> for partial results.' : '. Try the advanced search for more options.') + '</p>' +
                        '<div style="margin-top:16px;display:flex;gap:12px;justify-content:center;">' +
                        '<a href="/catalog" class="btn btn-primary" style="text-decoration:none;">Browse Catalog</a>' +
                        '<a href="/search?adv=1" class="btn btn-outline" style="text-decoration:none;">Advanced Search</a>' +
                        '</div></div>';
                    return;
                }
                div.innerHTML =
                    '<div class="results-count">Found <strong>' + data.total + '</strong> compound' +
                    (data.total !== 1 ? 's' : '') + ' for "<strong>' + escapeHtml(init.q) + '</strong>"' +
                    (mode === 'exact' ? ' <span style="font-size:0.8em;color:var(--muted)">(exact)</span>' : '') + '</div>' +
                    '<div class="results-grid">' + data.data.map(renderCard).join('') + '</div>';
            })
            .catch(function (err) {
                div.innerHTML =
                    '<div class="error-state"><h3>Search Failed</h3>' +
                    '<p>Unable to complete search. <a href="/catalog">Browse Catalog</a></p>' +
                    '<p style="font-size:0.8rem;color:#94a3b8;">' + escapeHtml(err.message) + '</p></div>';
            });
    }

    // Wire up match mode pills
    var simplePills = document.getElementById('simple-match-pills');
    if (simplePills) {
        simplePills.addEventListener('click', function (e) {
            var pill = e.target.closest('.adv-match-pill');
            if (!pill) return;
            simplePills.querySelectorAll('.adv-match-pill').forEach(function (p) { p.classList.remove('active'); });
            pill.classList.add('active');
            runSimpleSearch(pill.dataset.mode);
        });
    }

    runSimpleSearch('any');
})();

// ── Advanced panel: close button (when panel overlaps simple results) ─────────
(function () {
    var panel   = document.getElementById('adv-panel');
    var openBtn = document.getElementById('adv-toggle-btn');
    var closeBtn = document.getElementById('adv-close-btn');
    if (closeBtn) closeBtn.addEventListener('click', function () {
        panel.classList.remove('adv-open');
        if (openBtn) openBtn.style.display = '';
    });
    if (openBtn) openBtn.addEventListener('click', function () {
        panel.classList.add('adv-open');
        openBtn.style.display = 'none';
    });
})();

// ── Live term counter in batch textarea ───────────────────────────────────────
(function () {
    var ta    = document.getElementById('adv-batch-input');
    var count = document.getElementById('adv-batch-count');
    if (!ta || !count) return;

    function update() {
        var n = ta.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean).length;
        count.textContent = n + (n === 1 ? ' term' : ' terms');
    }
    ta.addEventListener('input', update);
    update(); // run once on load (handles pre-filled value from PHP)
})();

// ── Match mode pill toggle ────────────────────────────────────────────────────
(function () {
    var container = document.getElementById('adv-match-pills');
    if (!container) return;
    container.addEventListener('click', function (e) {
        var pill = e.target.closest('.adv-match-pill');
        if (!pill) return;
        container.querySelectorAll('.adv-match-pill').forEach(function (p) { p.classList.remove('active'); });
        pill.classList.add('active');
        var mode = pill.dataset.mode;
        document.getElementById('adv-match-hint-any').style.display   = mode === 'any'   ? '' : 'none';
        document.getElementById('adv-match-hint-exact').style.display = mode === 'exact' ? '' : 'none';
    });
})();

// ── Advanced batch search ─────────────────────────────────────────────────────
(function () {
    var btn = document.getElementById('adv-search-btn');
    var ta  = document.getElementById('adv-batch-input');
    if (!btn || !ta) return;

    function getMatchMode() {
        var active = document.querySelector('#adv-match-pills .adv-match-pill.active');
        return active ? active.dataset.mode : 'any';
    }

    function runSearch() {
        var terms = ta.value.split('\n')
            .map(function (s) { return s.trim(); })
            .filter(Boolean);

        if (terms.length === 0) {
            ta.focus();
            return;
        }

        var mode = getMatchMode();
        var div  = document.getElementById('search-results');
        div.innerHTML = chromLoader(
            'Searching ' + terms.length + ' term' + (terms.length !== 1 ? 's' : '') +
            (mode === 'exact' ? ' (exact match)' : '') + '…'
        );

        fetch('/api/data?action=advanced_search', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ terms: terms, match_mode: mode })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) { renderBatchResults(data, terms); })
        .catch(function (err) {
            document.getElementById('search-results').innerHTML =
                '<div class="error-state"><h3>Search Failed</h3>' +
                '<p>' + escapeHtml(err.message) + '</p></div>';
        });
    }

    btn.addEventListener('click', runSearch);
    ta.addEventListener('keydown', function (e) {
        // Ctrl+Enter / Cmd+Enter triggers search from inside the textarea
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) runSearch();
    });
})();

// ── Compact card for batch results ───────────────────────────────────────────
function renderCompactCard(product) {
    var avl    = (product.availability || '').toLowerCase();
    var ribbon = avl.includes('stock') ? 'in-stock' : avl.includes('order') ? 'backorder' : 'on-request';
    var catNum = product.ab_catalog_number
        ? '<span class="cc-cat">' + escapeHtml(product.ab_catalog_number) + '</span>'
        : '';
    return '<a href="' + productUrl(product) + '" class="compact-card">' +
        '<div class="cc-avail cc-avail--' + ribbon + '"></div>' +
        '<div class="cc-body">' +
            '<div class="cc-name">' + escapeHtml(product.product_name) + '</div>' +
            '<div class="cc-meta">' +
                catNum +
                (product.cas_number  ? '<span class="cc-field"><span class="cc-lbl">CAS</span>' + escapeHtml(product.cas_number) + '</span>' : '') +
                (product.molecular_formula ? '<span class="cc-field"><span class="cc-lbl">MF</span>' + escapeHtml(product.molecular_formula) + '</span>' : '') +
                (product.molecular_weight  ? '<span class="cc-field"><span class="cc-lbl">MW</span>' + formatNumber(product.molecular_weight) + '</span>' : '') +
                (product.purity ? '<span class="cc-field"><span class="cc-lbl">Purity</span>' + escapeHtml(product.purity) + '</span>' : '') +
            '</div>' +
        '</div>' +
        '<span class="cc-arrow">→</span>' +
    '</a>';
}

// ── Render batch results (grouped by input term) ──────────────────────────────
function renderBatchResults(data, inputTerms) {
    var div = document.getElementById('search-results');

    if (data.error) {
        div.innerHTML =
            '<div class="error-state"><h3>Search Error</h3>' +
            '<p>' + escapeHtml(data.message || 'An error occurred') + '</p></div>';
        return;
    }

    if (!data.grouped || Object.keys(data.grouped).length === 0) {
        div.innerHTML =
            '<div class="empty-state"><h3>No compounds found</h3>' +
            '<p>None of the ' + inputTerms.length + ' term' + (inputTerms.length !== 1 ? 's' : '') +
            ' matched any compound in our catalog.</p></div>';
        return;
    }

    var totalHits  = data.total || 0;
    var groupCount = Object.keys(data.grouped).length;
    var html =
        '<div class="batch-results-summary">' +
        'Found <strong>' + totalHits + '</strong> result' + (totalHits !== 1 ? 's' : '') +
        ' across <strong>' + groupCount + '</strong> of ' + inputTerms.length + ' search term' +
        (inputTerms.length !== 1 ? 's' : '') +
        '</div>';

    // Show each term group in input order
    Object.values(data.grouped).forEach(function (group) {
        var cnt = group.count || (group.results ? group.results.length : 0);
        html +=
            '<div class="batch-group">' +
            '<div class="batch-group-header">' +
                '<span class="batch-term">' + escapeHtml(group.term) + '</span>' +
                '<span class="batch-count">' + cnt + ' result' + (cnt !== 1 ? 's' : '') + '</span>' +
            '</div>';

        if (cnt > 0) {
            html += '<div class="compact-card-list">' +
                (group.results || []).map(renderCompactCard).join('') +
                '</div>';
        } else {
            html += '<p class="batch-no-results">No matches found for this term.</p>';
        }
        html += '</div>';
    });

    // Also show terms that returned zero results (they won't be in grouped)
    inputTerms.forEach(function (term) {
        var found = Object.values(data.grouped).some(function (g) {
            return g.term === term;
        });
        if (!found) {
            html +=
                '<div class="batch-group batch-group--miss">' +
                '<div class="batch-group-header">' +
                    '<span class="batch-term">' + escapeHtml(term) + '</span>' +
                    '<span class="batch-count batch-count--miss">0 results</span>' +
                '</div>' +
                '<p class="batch-no-results">No matches found for this term.</p>' +
                '</div>';
        }
    });

    div.innerHTML = html;
}
