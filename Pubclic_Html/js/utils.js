/* =============================================================
   utils.js — Global utilities loaded on every page via header.php
   ============================================================= */

/* ── Sticky header: scroll-aware class ──────────────────────── */
(function () {
    var header = document.querySelector('.site-header');
    if (!header) return;
    var ticking = false;
    function update() {
        header.classList.toggle('scrolled', window.scrollY > 10);
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { requestAnimationFrame(update); ticking = true; }
    }, { passive: true });
    update(); // apply immediately if page loads mid-scroll (e.g. back/forward)
})();

/* ── Header advanced-search popover ─────────────────────────── */
(function () {
    var triggerBtn = document.getElementById('header-adv-btn');
    var dropdown   = document.getElementById('header-adv-dropdown');
    var closeBtn   = document.getElementById('header-adv-close');
    var goBtn      = document.getElementById('header-adv-go');
    var dot        = document.getElementById('header-adv-dot');
    var form       = document.getElementById('header-search-form');
    var qInput     = document.getElementById('smart-search-input');
    if (!triggerBtn || !dropdown) return;

    // ── Open / close ──────────────────────────────────────────────
    function open() {
        dropdown.hidden = false;
        triggerBtn.setAttribute('aria-expanded', 'true');
    }
    function close() {
        dropdown.hidden = true;
        triggerBtn.setAttribute('aria-expanded', 'false');
    }
    triggerBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.hidden ? open() : close();
    });
    if (closeBtn) closeBtn.addEventListener('click', close);

    // Close on outside click
    document.addEventListener('click', function (e) {
        if (!dropdown.hidden && !dropdown.contains(e.target) && e.target !== triggerBtn) close();
    });
    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !dropdown.hidden) close();
    });

    // ── Match mode pills ──────────────────────────────────────────
    var pills = dropdown.querySelectorAll('.hadv-pill');
    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            pills.forEach(function (p) { p.classList.remove('active'); });
            pill.classList.add('active');
            updateDot();
        });
    });
    function getMode() {
        var a = dropdown.querySelector('.hadv-pill.active');
        return a ? a.dataset.mode : 'any';
    }

    // ── Field checkboxes ──────────────────────────────────────────
    var fieldCbs = dropdown.querySelectorAll('.hadv-check input[type="checkbox"]');
    fieldCbs.forEach(function (cb) { cb.addEventListener('change', updateDot); });

    function getFields() {
        return Array.from(fieldCbs)
            .filter(function (cb) { return cb.checked; })
            .map(function (cb) { return cb.value; });
    }

    // ── Dot badge: show when settings differ from defaults ────────
    var ALL_FIELDS = Array.from(fieldCbs).map(function (cb) { return cb.value; });
    function updateDot() {
        var nonDefault = getMode() !== 'any' || getFields().length !== ALL_FIELDS.length;
        dot.hidden = !nonDefault;
    }

    // ── Build search URL and navigate ────────────────────────────
    function buildUrl(q) {
        var params = new URLSearchParams();
        params.set('adv', '1');
        if (q) params.set('q', q);
        params.set('match_mode', getMode());
        getFields().forEach(function (f) { params.append('fields[]', f); });
        return '/search?' + params.toString();
    }

    // "Search →" button inside popover
    if (goBtn) {
        goBtn.addEventListener('click', function () {
            var q = qInput ? qInput.value.trim() : '';
            window.location.href = buildUrl(q);
        });
    }

    // Intercept the main header form submit when non-default options are set
    if (form) {
        form.addEventListener('submit', function (e) {
            var nonDefault = getMode() !== 'any' || getFields().length !== ALL_FIELDS.length;
            if (nonDefault) {
                e.preventDefault();
                var q = qInput ? qInput.value.trim() : '';
                window.location.href = buildUrl(q);
            }
            // else: normal form submit to /search?q=... (simple search)
        });
    }
})();

/* ── Mobile menu ─────────────────────────────────────────────── */
document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function () {
    const menu = document.getElementById('mobile-menu');
    menu.hidden = !menu.hidden;
    menu.style.display = menu.hidden ? 'none' : 'block';
});

/* ── Theme toggle ────────────────────────────────────────────── */
(function () {
    const STORAGE_KEY = 'abchem-theme';
    const html        = document.documentElement;
    const btn         = document.getElementById('theme-toggle');

    if (btn) {
        btn.addEventListener('click', function () {
            const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem(STORAGE_KEY, next);

            btn.style.transform = 'scale(0.85)';
            setTimeout(function () { btn.style.transform = ''; }, 150);
        });
    }

    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            if (!localStorage.getItem(STORAGE_KEY)) {
                html.setAttribute('data-theme', e.matches ? 'dark' : 'light');
            }
        });
    }
})();

/* ── Search autocomplete ─────────────────────────────────────── */
(function () {
    function initAC(inputEl, dropdownEl) {
        if (!inputEl || !dropdownEl) return;
        let timer;

        inputEl.addEventListener('input', function () {
            clearTimeout(timer);
            const q = this.value.trim();
            if (q.length < 2) { dropdownEl.style.display = 'none'; return; }

            timer = setTimeout(async function () {
                try {
                    const data = await (await fetch('/api/autocomplete?q=' + encodeURIComponent(q))).json();
                    if (!data.length) { dropdownEl.style.display = 'none'; return; }

                    dropdownEl.innerHTML = data.map(function (d) {
                        // API now returns a pre-built canonical URL (d.url)
                        return '<div class="ac-item" data-url="' + escapeHtml(d.url) + '" tabindex="-1">' +
                            '<div class="ac-main">' +
                                '<strong>' + escapeHtml(d.name) + '</strong>' +
                                (d.formula ? '<span class="ac-formula">' + escapeHtml(d.formula) + '</span>' : '') +
                            '</div>' +
                            '<div class="ac-meta">' +
                                '<span class="ac-cas">' + escapeHtml(d.cas) + '</span>' +
                                (d.type ? '<span class="ac-type">' + escapeHtml(d.type) + '</span>' : '') +
                            '</div>' +
                        '</div>';
                    }).join('');
                    dropdownEl.style.display = 'block';

                    dropdownEl.querySelectorAll('.ac-item').forEach(function (item) {
                        item.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            inputEl.value = item.querySelector('strong').textContent;
                            dropdownEl.style.display = 'none';
                            window.location.href = item.dataset.url;
                        });
                    });
                } catch (err) {
                    console.error('Autocomplete error:', err);
                }
            }, 280);
        });

        inputEl.addEventListener('keydown', function (e) {
            const items = dropdownEl.querySelectorAll('.ac-item');
            if (!items.length || dropdownEl.style.display === 'none') return;
            const focused = dropdownEl.querySelector('.ac-item.ac-focused');
            let idx = Array.from(items).indexOf(focused);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (focused) focused.classList.remove('ac-focused');
                items[(idx + 1) % items.length].classList.add('ac-focused');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (focused) focused.classList.remove('ac-focused');
                items[(idx - 1 + items.length) % items.length].classList.add('ac-focused');
            } else if (e.key === 'Enter' && focused) {
                e.preventDefault();
                inputEl.value = focused.querySelector('strong').textContent;
                dropdownEl.style.display = 'none';
                window.location.href = focused.dataset.url;
            } else if (e.key === 'Escape') {
                dropdownEl.style.display = 'none';
            }
        });

        document.addEventListener('click', function (e) {
            if (!dropdownEl.contains(e.target) && e.target !== inputEl) {
                dropdownEl.style.display = 'none';
            }
        });
    }

    initAC(document.getElementById('smart-search-input'),  document.getElementById('search-ac-dropdown'));
    initAC(document.getElementById('mobile-search-input'), document.getElementById('mobile-search-ac-dropdown'));

    document.getElementById('header-search-form')?.addEventListener('submit', function (e) {
        if (!document.getElementById('smart-search-input')?.value.trim()) e.preventDefault();
    });
    document.getElementById('mobile-search-form')?.addEventListener('submit', function (e) {
        if (!document.getElementById('mobile-search-input')?.value.trim()) e.preventDefault();
    });
})();

/* ── Image fallback ──────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('img').forEach(function (img) {
        img.addEventListener('error', function () {
            if (this.src !== location.origin + '/logo.png') {
                this.src = '/logo.png';
                this.classList.add('img-fallback');
            }
        });
        if (img.complete && img.naturalWidth === 0) {
            img.src = '/logo.png';
            img.classList.add('img-fallback');
        }
    });
});

/* ── Shared helpers ──────────────────────────────────────────── */

/**
 * XSS-safe HTML escaping. Available globally for any page script.
 */
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

/**
 * Format a numeric value to 2 d.p., stripping trailing ".00".
 */
function formatNumber(num) {
    if (!num || num === 'NA') return 'N/A';
    const parsed = parseFloat(num);
    return isNaN(parsed) ? 'N/A' : parsed.toFixed(2).replace(/\.00$/, '');
}

/**
 * Build chromatogram-trace loader markup for section / full-page loading states.
 * `label` is plain text — escaped for safety.
 */
function chromLoader(label) {
    var safe = escapeHtml(label || 'Loading…');
    return '<div class="chrom-loader" role="status" aria-live="polite">' +
             '<svg class="chrom-loader__svg" viewBox="0 0 220 70" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
               '<line class="chrom-loader__axis" x1="0" y1="60" x2="220" y2="60"/>' +
               '<path class="chrom-loader__trace" pathLength="100" d="M0,60 L25,60 C35,60 38,18 45,18 C52,18 55,60 65,60 L95,60 C105,60 108,5 115,5 C122,5 125,60 135,60 L160,60 C168,60 171,35 175,35 C179,35 182,60 190,60 L220,60"/>' +
             '</svg>' +
             '<span class="chrom-loader__label">' + safe + '</span>' +
           '</div>';
}

/**
 * Auto-dismiss any elements matching selector after `delay` ms.
 * Called with CSS selector and optional delay (default 5 s).
 */
function autoDismiss(selector, delay) {
    delay = delay || 5000;
    setTimeout(function () {
        document.querySelectorAll(selector).forEach(function (el) {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 500);
        });
    }, delay);
}
