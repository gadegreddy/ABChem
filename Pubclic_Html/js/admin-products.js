/* =============================================================
   admin-products.js — Logic for admin_products.php
   Depends on: escapeHtml() from utils.js
   ============================================================= */

// PROD_ID and PROD_SLUG are set by PHP inline before this script loads.

/* ── Live image preview ──────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    const imgUrlField = document.getElementById('field_image_url');
    const imgPreview  = document.getElementById('img-preview');
    if (imgUrlField && imgPreview) {
        imgUrlField.addEventListener('input', function () {
            const v = imgUrlField.value.trim();
            if (v) imgPreview.src = v;
        });
    }
});

/* ── Form flag helpers ───────────────────────────────────────── */
function setFlags(fetch, force) {
    document.getElementById('fetch_pubchem_flag').value = fetch;
    document.getElementById('force_pubchem_flag').value = force;
}

function handleForceSubmit() {
    const forceOn = document.getElementById('force_overwrite_cb')?.checked;
    if (forceOn) {
        if (!confirm('⚡ Force overwrite will REPLACE existing chemical field values with PubChem data. Continue?')) return false;
        setFlags(1, 1);
    } else {
        setFlags(1, 0);
    }
    return true;
}

/* ── Image fetch via AJAX (no nested form, no page reload) ────── */
async function fetchExternalImage() {
    const urlInput  = document.getElementById('ext_image_url');
    const statusEl  = document.getElementById('fetch_status');
    const previewEl = document.getElementById('fetch_preview');
    const btn       = document.getElementById('btn_fetch_image');

    const imageUrl = (urlInput?.value || '').trim();
    if (!imageUrl) { showFetchStatus('Please paste an image URL first.', 'err'); return; }
    if (!imageUrl.startsWith('https://')) { showFetchStatus('Only HTTPS URLs are accepted.', 'err'); return; }

    btn.disabled    = true;
    btn.textContent = '⏳ Fetching…';
    previewEl.style.display = 'none';
    showFetchStatus('Downloading and validating image…', 'loading');

    try {
        const body = new FormData();
        body.append('image_url',  imageUrl);
        const slugField = document.getElementById('field_slug');
        const slug = (slugField?.value || '').trim() || window.PROD_SLUG || 'product-' + Date.now();
        body.append('slug',       slug);
        body.append('product_id', window.PROD_ID || 0);

        const res  = await fetch('?ajax=fetch_image', { method: 'POST', body });
        const data = await res.json();

        if (data.error) {
            showFetchStatus('❌ ' + data.error, 'err');
        } else {
            const imgUrlField = document.getElementById('field_image_url');
            const imgPreview  = document.getElementById('img-preview');
            if (imgUrlField) imgUrlField.value = data.path;
            if (imgPreview)  imgPreview.src    = data.path;
            urlInput.value = '';

            const kb   = (data.size / 1024).toFixed(1);
            const info = `✅ Saved to <code>${escapeHtml(data.path)}</code><br>
                          Size: <strong>${kb} KB</strong> · Dimensions: <strong>${data.dims || '—'}</strong> · Type: <strong>${data.mime || '—'}</strong>
                          ${data.db_warning ? '<br>⚠️ ' + escapeHtml(data.db_warning) : '<br><span style="color:#64748b;font-size:.8rem">Image saved — save the product form to finalize.</span>'}`;

            showFetchStatus('Image fetched and saved!', 'ok');
            document.getElementById('fetch_preview_img').src = data.path;
            document.getElementById('fetch_preview_info').innerHTML = info;
            previewEl.style.display = 'flex';
        }
    } catch (err) {
        showFetchStatus('❌ Network error: ' + escapeHtml(err.message), 'err');
    } finally {
        btn.disabled    = false;
        btn.textContent = '📥 Fetch & Save Image';
    }
}

function showFetchStatus(msg, type) {
    const el = document.getElementById('fetch_status');
    el.innerHTML = msg;
    el.className = 'fetch-status ' + type;
}

/* ── PubChem Preview Modal ───────────────────────────────────── */
async function openPubChemPreview() {
    document.getElementById('pubchem_overlay').classList.add('open');
    const body = document.getElementById('pubchem_modal_body');
    body.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:30px 0">🔍 Searching PubChem…</p>';

    const formData = new FormData();
    // compound_name field — try new id first, fall back to legacy product_name
    const nameEl = document.getElementById('field_compound_name') || document.getElementById('field_product_name');
    if (nameEl && nameEl.value.trim()) formData.append('compound_name', nameEl.value.trim());

    ['iupac_name', 'inchi_key', 'cas_number', 'pubchem_cid'].forEach(function (f) {
        const el = document.getElementById('field_' + f);
        if (el && el.value.trim()) formData.append(f, el.value.trim());
    });

    try {
        const res  = await fetch('?ajax=pubchem_preview', { method: 'POST', body: formData });
        const json = await res.json();

        if (json.error) {
            body.innerHTML = `<p style="color:#ef4444;text-align:center;padding:30px 0">❌ ${escapeHtml(json.error)}<br><small>Try different search identifiers</small></p>`;
            return;
        }

        const pubchemData   = json.data || {};
        const displayFields = ['molecular_formula','molecular_weight','smiles','smiles_canonical','inchi','inchi_key','iupac_name','pubchem_cid','synonyms'];

        const currentVals = {};
        displayFields.forEach(function (f) {
            const el = document.getElementById('field_' + f);
            currentVals[f] = el ? el.value.trim() : '';
        });

        const items = [];
        let emptyCount = 0, filledCount = 0;

        for (const field of displayFields) {
            const pubchemValue = pubchemData[field];
            if (!pubchemValue || pubchemValue === 'NA' || pubchemValue === '') continue;

            const currentValue = currentVals[field] || '';
            const isEmpty = !currentValue || currentValue === 'NA';
            if (isEmpty) emptyCount++; else filledCount++;

            let displayValue = String(pubchemValue);
            let isLong = false;
            if (field === 'synonyms') {
                const synArray = displayValue.split('|').filter(function (s) { return s.trim(); });
                const synCount = synArray.length;
                displayValue = synArray.slice(0, 3).join(' | ');
                if (synCount > 3) { displayValue += ` | … +${synCount - 3} more`; }
                isLong = synCount > 3;
            } else if (displayValue.length > 120) {
                displayValue = displayValue.substring(0, 120) + '…';
                isLong = true;
            }

            const rowStyle  = isEmpty ? 'color:#166534;font-weight:600' : 'color:#1e293b';
            const badgeHtml = isEmpty
                ? '<span style="font-size:0.7rem;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;margin-left:6px;white-space:nowrap">✦ empty</span>'
                : '<span style="font-size:0.7rem;background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:10px;margin-left:6px;white-space:nowrap">has value</span>';
            const fieldLabel = field.replace(/_/g, ' ');
            const titleAttr  = isLong ? `title="${escapeHtml(String(pubchemValue).substring(0, 500))}"` : '';

            items.push(`
                <div class="pubchem-field-item" style="${isEmpty ? 'background:#f0fdf4;border-radius:6px;padding:6px 8px;' : 'padding:6px 8px;'}">
                    <strong>${fieldLabel}</strong>
                    <div style="display:flex;align-items:center;gap:6px;margin-top:3px;">
                        <span style="${rowStyle};flex:1;word-break:break-word;font-size:0.85rem;" ${titleAttr}>${escapeHtml(displayValue)}</span>
                        ${badgeHtml}
                    </div>
                </div>`);
        }

        if (items.length === 0) {
            body.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:30px 0">No data returned from PubChem for this compound.</p>';
            return;
        }

        const summaryHtml = emptyCount > 0
            ? `<div style="background:#f0fdf4;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;">
                ✅ <strong>${emptyCount} empty field${emptyCount !== 1 ? 's' : ''}</strong> will be filled ·
                ${filledCount > 0 ? `${filledCount} field${filledCount !== 1 ? 's' : ''} already populated (will keep your values)` : 'All fields are empty'}
               </div>`
            : `<div style="background:#fef3c7;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;">
                ℹ️ All fields already have values. Enable <strong>Force Overwrite</strong> to replace them with PubChem data.
               </div>`;

        body.innerHTML = summaryHtml + `<div class="pubchem-field-list">${items.join('')}</div>
            <p style="font-size:0.8rem;color:#64748b;margin-top:12px;">
                Fields marked ✦ <strong style="color:#166534">empty</strong> will be filled automatically.
                Hover over truncated values to see full text.
                Use <strong style="color:#d97706">Force Overwrite</strong> to replace existing values.
            </p>`;
    } catch (err) {
        body.innerHTML = `<p style="color:#ef4444;text-align:center;padding:30px 0">❌ Network error: ${escapeHtml(err.message)}</p>`;
    }
}

function closeModal() {
    document.getElementById('pubchem_overlay').classList.remove('open');
}

/* ── Auto-dismiss flash alerts after 8 s ────────────────────── */
autoDismiss('.alert', 8000);

/* ================================================================
   SUPPLIER LISTINGS MANAGEMENT
   AJAX endpoints (all in admin_products.php):
     ?ajax=get_suppliers  GET  → [{id, supplier_name, catalog_prefix}]
     ?ajax=listing_get    POST → {listing: {...}}
     ?ajax=listing_save   POST → {success, listing}
     ?ajax=listing_delete POST → {success, soft_delete?, message?}
   ================================================================ */

let _suppliersCache = null;

async function _loadSuppliers() {
    if (_suppliersCache) return _suppliersCache;
    const res  = await fetch('?ajax=get_suppliers');
    const text = await res.text();
    try {
        _suppliersCache = JSON.parse(text);
    } catch (e) {
        console.error('[_loadSuppliers] Server returned non-JSON:', text.substring(0, 200));
        throw new Error('Could not load supplier list — server returned invalid data. Check browser console.');
    }
    return _suppliersCache;
}

function _setSelect(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    for (const opt of el.options) opt.selected = (opt.value === String(value));
}

function _resetListingModal() {
    ['lm_catalog_number','lm_purity','lm_purity_by_method','lm_lead_time',
     'lm_lot_number','lm_manufacture_date','lm_expiry_date','lm_supplier_notes'].forEach(function (id) {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    const moq = document.getElementById('lm_min_order_qty');      if (moq) moq.value = '1';
    const qty = document.getElementById('lm_quantity_available'); if (qty) qty.value = '';
    _setSelect('lm_availability', 'In Stock');
    _setSelect('lm_stock_status', 'in_stock');
    _setSelect('lm_unit',         'mg');
    _setSelect('lm_status',       'Active');
}

/**
 * openListingModal — async, no side-effects before user confirms Save.
 * listingId = 0  → Add new   |   listingId > 0  → Edit existing (fetches live DB data)
 */
async function openListingModal(listingId, compoundId) {
    // Guard: all required elements must exist before proceeding
    const overlay  = document.getElementById('listing_overlay');
    const title    = document.getElementById('listing-modal-title');
    const subtitle = document.getElementById('listing-modal-subtitle');
    const errBox   = document.getElementById('listing-modal-error');
    const btn      = document.getElementById('btn-save-listing');

    if (!overlay || !title || !errBox || !btn) {
        console.error('[openListingModal] Required modal element(s) missing:', {
            overlay: !!overlay, title: !!title, errBox: !!errBox, btn: !!btn
        });
        alert('UI error: listing modal elements not found. Please refresh the page.');
        return;
    }

    const idField  = document.getElementById('lm_listing_id');
    const cidField = document.getElementById('lm_compound_id');
    if (!idField || !cidField) {
        console.error('[openListingModal] Hidden input fields missing');
        return;
    }

    idField.value  = listingId;
    cidField.value = compoundId;
    errBox.style.display = 'none';
    btn.disabled         = true;

    title.textContent    = listingId > 0 ? '⏳ Loading listing…' : '➕ Add Supplier Listing';
    if (subtitle) subtitle.textContent = '';
    _resetListingModal();
    overlay.classList.add('open');   // show modal immediately (loading state)

    try {
        // 1. Populate supplier dropdown (cached)
        const suppliers = await _loadSuppliers();
        const sel = document.getElementById('lm_supplier_id');
        sel.innerHTML = '<option value="">-- Select Supplier --</option>';
        suppliers.forEach(function (s) {
            const o = document.createElement('option');
            o.value = s.id; o.textContent = s.supplier_name;
            sel.appendChild(o);
        });

        // 2. If editing, fetch current data from DB (never read from DOM text)
        if (listingId > 0) {
            const fd = new FormData();
            fd.append('compound_id', compoundId);
            fd.append('listing_id',  listingId);
            const res  = await fetch('?ajax=listing_get', { method: 'POST', body: fd });
            const json = await res.json();

            if (json.error) {
                errBox.textContent = '❌ ' + json.error;
                errBox.style.display = 'block';
                title.textContent  = '⚠️ Could not load listing';
                return;
            }
            const l = json.listing;
            title.textContent    = '✏️ Edit Listing #' + listingId;
            subtitle.textContent = (l.company_make || '') + ' · Compound #' + compoundId;

            _setSelect('lm_supplier_id',  String(l.supplier_id  || ''));
            _setSelect('lm_availability', l.availability || 'In Stock');
            _setSelect('lm_stock_status', l.stock_status  || 'in_stock');
            _setSelect('lm_unit',         l.unit          || 'mg');
            _setSelect('lm_status',       l.status        || 'Active');

            document.getElementById('lm_catalog_number').value     = l.catalog_number     || '';
            document.getElementById('lm_purity').value             = l.purity             || '';
            document.getElementById('lm_purity_by_method').value   = l.purity_by_method   || '';
            document.getElementById('lm_min_order_qty').value      = l.min_order_qty      || '1';
            document.getElementById('lm_quantity_available').value = l.quantity_available || '';
            document.getElementById('lm_lead_time').value          = l.lead_time          || '';
            document.getElementById('lm_lot_number').value         = l.lot_number         || '';
            document.getElementById('lm_manufacture_date').value   = l.manufacture_date   ? l.manufacture_date.substring(0, 10) : '';
            document.getElementById('lm_expiry_date').value        = l.expiry_date        ? l.expiry_date.substring(0, 10)      : '';
            document.getElementById('lm_supplier_notes').value     = l.supplier_notes     || '';
        } else {
            title.textContent    = '➕ Add Supplier Listing';
            subtitle.textContent = 'Will be linked to compound #' + compoundId;
        }
    } catch (err) {
        console.error('[openListingModal] Error:', err);
        errBox.textContent   = '❌ ' + escapeHtml(err.message || 'Unexpected error loading listing');
        errBox.style.display = 'block';
    } finally {
        if (btn) btn.disabled = false;
    }
}

function closeListingModal() {
    document.getElementById('listing_overlay').classList.remove('open');
}

async function saveListingModal() {
    const btn      = document.getElementById('btn-save-listing');
    const errBox   = document.getElementById('listing-modal-error');
    const listingId  = document.getElementById('lm_listing_id').value;
    const compoundId = document.getElementById('lm_compound_id').value;
    const supplierId = document.getElementById('lm_supplier_id').value;

    errBox.style.display = 'none';

    if (!supplierId) {
        errBox.textContent   = 'Please select a supplier.';
        errBox.style.display = 'block';
        return;
    }

    btn.disabled    = true;
    btn.textContent = '⏳ Saving…';

    const fd = new FormData();
    fd.append('compound_id',        compoundId);
    fd.append('listing_id',         listingId);
    fd.append('supplier_id',        supplierId);
    fd.append('catalog_number',     document.getElementById('lm_catalog_number').value);
    fd.append('purity',             document.getElementById('lm_purity').value);
    fd.append('purity_by_method',   document.getElementById('lm_purity_by_method').value);
    fd.append('availability',       document.getElementById('lm_availability').value);
    fd.append('stock_status',       document.getElementById('lm_stock_status').value);
    fd.append('min_order_qty',      document.getElementById('lm_min_order_qty').value);
    fd.append('unit',               document.getElementById('lm_unit').value);
    fd.append('quantity_available', document.getElementById('lm_quantity_available').value);
    fd.append('lead_time',          document.getElementById('lm_lead_time').value);
    fd.append('lot_number',         document.getElementById('lm_lot_number').value);
    fd.append('manufacture_date',   document.getElementById('lm_manufacture_date').value);
    fd.append('expiry_date',        document.getElementById('lm_expiry_date').value);
    fd.append('supplier_notes',     document.getElementById('lm_supplier_notes').value);
    fd.append('status',             document.getElementById('lm_status').value);

    try {
        const res  = await fetch('?ajax=listing_save', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) {
            errBox.textContent   = '❌ ' + data.error;
            errBox.style.display = 'block';
            return;
        }

        closeListingModal();
        _refreshListingsTable(compoundId);

    } catch (err) {
        errBox.textContent   = '❌ Network error: ' + escapeHtml(err.message);
        errBox.style.display = 'block';
    } finally {
        btn.disabled    = false;
        btn.textContent = '💾 Save Listing';
    }
}

async function deleteListing(listingId, compoundId) {
    if (!confirm('Delete this supplier listing? If it is the only active listing it will be marked Inactive instead.')) return;

    const fd = new FormData();
    fd.append('listing_id',  listingId);
    fd.append('compound_id', compoundId);

    try {
        const res  = await fetch('?ajax=listing_delete', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) { alert('❌ ' + data.error); return; }

        if (data.soft_delete) {
            // Listing still exists — update the status badge in the row
            const row = document.getElementById('listing-row-' + listingId);
            if (row) {
                const badge = row.querySelector('td:last-child ~ td span.status-badge') ||
                              [...row.querySelectorAll('span.status-badge')].pop();
                if (badge) {
                    badge.className   = 'status-badge status-inactive';
                    badge.textContent = 'Inactive';
                }
            }
            alert('ℹ️ ' + (data.message || 'Last active listing was marked Inactive.'));
        } else {
            // Hard-deleted — remove row from table
            const row = document.getElementById('listing-row-' + listingId);
            if (row) row.remove();
            _checkEmptyListingsTable(compoundId);
        }
    } catch (err) {
        alert('❌ Network error: ' + err.message);
    }
}

/**
 * After a save, reload the listings table via a lightweight page fetch
 * so the rendered row is always accurate (PHP-rendered badges, etc.).
 */
function _refreshListingsTable(compoundId) {
    // Simplest reliable approach: reload the page (preserving the edit URL)
    // This re-renders the entire listings panel server-side.
    window.location.reload();
}

/* ── Collapsible form sections ───────────────────────────────────── */
function toggleSection(id) {
    const body = document.getElementById(id);
    const icon = document.getElementById('toggle-' + id);
    if (!body) return;
    const nowCollapsed = body.classList.toggle('collapsed');
    if (icon) icon.style.transform = nowCollapsed ? 'rotate(-90deg)' : '';
    try { localStorage.setItem('sec_' + id, nowCollapsed ? '1' : '0'); } catch(e) {}
}

document.addEventListener('DOMContentLoaded', function () {
    // Restore saved collapse states from localStorage
    ['sec-basic','sec-chem','sec-seo','sec-supplier'].forEach(function (id) {
        try {
            if (localStorage.getItem('sec_' + id) === '1') {
                const body = document.getElementById(id);
                const icon = document.getElementById('toggle-' + id);
                if (body) { body.classList.add('collapsed'); }
                if (icon) { icon.style.transform = 'rotate(-90deg)'; }
            }
        } catch(e) {}
    });
});

function _checkEmptyListingsTable(compoundId) {
    const tbody = document.getElementById('listings-tbody');
    if (tbody && tbody.children.length === 0) {
        const container = document.getElementById('listings-table-container');
        if (container) {
            container.innerHTML = `
                <div id="no-listings-msg" style="text-align:center;padding:40px;background:#f8fafc;border-radius:10px;color:var(--muted);">
                    <p style="font-size:1.2rem;margin-bottom:8px;">🏭 No supplier listings yet</p>
                    <button type="button" class="btn btn-primary" style="margin-top:12px;"
                            onclick="openListingModal(0, ${escapeHtml(String(compoundId))})">➕ Add First Listing</button>
                </div>`;
        }
    }
}
function toggleSectionFallback(sectionId) {
    const body = document.getElementById(sectionId);
    if (!body) return;
    
    // Find the toggle icon
    const toggleIcon = document.getElementById('toggle-' + sectionId);
    
    // Toggle collapsed state
    body.classList.toggle('collapsed');
    
    // Rotate the arrow
    if (toggleIcon) {
        if (body.classList.contains('collapsed')) {
            toggleIcon.style.transform = 'rotate(-90deg)';
        } else {
            toggleIcon.style.transform = 'rotate(0deg)';
        }
    }
}