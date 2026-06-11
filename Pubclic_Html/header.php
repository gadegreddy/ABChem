<!-- Favicon -->
<link rel="icon" type="image/png" href="/logo.png">
<link rel="shortcut icon" href="/logo.png">
<link rel="apple-touch-icon" href="/logo.png">

<!-- SEO -->
<meta name="author" content="AB Chem India">
<meta name="publisher" content="AB Chem India">
<meta name="copyright" content="AB Chem India">
<meta name="robots" content="index, follow">

<!-- WebSite structured data — tells Google to show "AB Chem" (not abchem.co.in) as site name -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "AB Chem",
  "alternateName": "AB Chem India",
  "url": "https://www.abchem.co.in",
  "potentialAction": {
    "@type": "SearchAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "https://www.abchem.co.in/search?q={search_term_string}"
    },
    "query-input": "required name=search_term_string"
  }
}
</script>

<!--
  ⚡ Theme: Apply BEFORE any rendering to prevent flash-of-unstyled-theme (FOUT).
     Single source of truth — one key, one script, no duplicates.
-->
<script>
(function () {
    var stored = localStorage.getItem('abchem-theme');
    var theme  = stored ? stored : 'light';
    document.documentElement.setAttribute('data-theme', theme);
})();
</script>

<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../private/functions.php';
initCart();
$cartCount = getCartCount();
?>

<header class="site-header" role="banner">
<div class="header-container">

    <a href="/" class="footer-logo">
        <div class="footer-logo-row">
            <img src="/logo.png" alt="AB Chem Logo" class="footer-logo-img">
            <span class="footer-logo-text">AB<span class="logo-accent">Chem</span></span>
        </div>
        <span class="footer-tagline">Specialty <span class="footer-tagline-accent">Chemicals</span>, APIs &amp; Consumables</span>
    </a>

    <!-- Search Bar -->
    <div class="search-wrapper">
        <form action="/search.php" method="get" id="header-search-form" class="search-form">
            <input type="hidden" name="search_type" value="auto">
            <input type="text" name="q" id="smart-search-input"
                   placeholder="Search by Name, CAS, Formula..." autocomplete="off"
                   class="search-input">
            <button type="submit" class="search-btn" title="Search">🔍</button>
        </form>

        <!-- Advanced search trigger button -->
        <button type="button" class="header-adv-btn" id="header-adv-btn"
                aria-label="Advanced search options" aria-expanded="false"
                aria-controls="header-adv-dropdown" title="Advanced Search">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="4" y1="6"  x2="20" y2="6"/>  <circle cx="9"  cy="6"  r="2.5"/>
                <line x1="4" y1="12" x2="20" y2="12"/> <circle cx="16" cy="12" r="2.5"/>
                <line x1="4" y1="18" x2="20" y2="18"/> <circle cx="9"  cy="18" r="2.5"/>
            </svg>
            <span class="header-adv-dot" id="header-adv-dot" hidden></span>
        </button>

        <a href="/structure-search" class="structure-search-link">
            🔬 Structure
        </a>

        <!-- Autocomplete dropdown -->
        <div id="search-ac-dropdown" class="ac-dropdown"></div>

        <!-- ── Advanced search popover ──────────────────────────────── -->
        <div id="header-adv-dropdown" class="header-adv-dropdown" hidden role="dialog" aria-label="Advanced search options">

            <div class="hadv-header">
                <span class="hadv-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Advanced Search
                </span>
                <button type="button" class="hadv-close" id="header-adv-close" aria-label="Close">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="hadv-body">
                <!-- Match mode -->
                <div class="hadv-row">
                    <span class="hadv-label">Match</span>
                    <div class="hadv-mode-pills">
                        <button type="button" class="hadv-pill active" data-mode="any">Contains</button>
                        <button type="button" class="hadv-pill" data-mode="exact">Exact</button>
                    </div>
                </div>

                <!-- Search fields -->
                <div class="hadv-row">
                    <span class="hadv-label">Search in</span>
                    <div class="hadv-fields">
                        <label class="hadv-check"><input type="checkbox" value="compound_name"     checked> Name</label>
                        <label class="hadv-check"><input type="checkbox" value="iupac_name"        checked> IUPAC</label>
                        <label class="hadv-check"><input type="checkbox" value="synonyms"          checked> Synonyms</label>
                        <label class="hadv-check"><input type="checkbox" value="cas_number"        checked> CAS</label>
                        <label class="hadv-check"><input type="checkbox" value="inchi_key"         checked> InChI Key</label>
                        <label class="hadv-check"><input type="checkbox" value="molecular_formula" checked> Formula</label>
                        <label class="hadv-check"><input type="checkbox" value="ab_catalog_number" checked> Cat. No.</label>
                    </div>
                </div>
            </div>

            <div class="hadv-footer">
                <button type="button" class="hadv-go-btn" id="header-adv-go">
                    Search →
                </button>
                <a href="/search?adv=1" class="hadv-full-link">Full Advanced Search</a>
            </div>

        </div><!-- /.header-adv-dropdown -->
    </div>

    <!-- Desktop Navigation -->
    <nav class="main-nav" role="navigation" aria-label="Main">

        <!-- Products dropdown -->
        <div class="nav-dropdown-wrap">
            <button class="nav-link nav-dropdown-btn" aria-expanded="false" aria-haspopup="true" id="nav-products-btn">
                Products
                <svg class="nav-dropdown-caret" width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="2,3 5,7 8,3"/></svg>
            </button>
            <div class="nav-dropdown" id="nav-products-menu" role="menu" aria-labelledby="nav-products-btn" hidden>
                <a href="/catalog"         class="nav-dropdown-item" role="menuitem">
                    <span class="nav-di-icon">📦</span>
                    <span>
                        <span class="nav-di-title">Our Catalog</span>
                        <span class="nav-di-sub">APIs, impurities &amp; standards</span>
                    </span>
                </a>
                <a href="/fine-chemicals"  class="nav-dropdown-item" role="menuitem">
                    <span class="nav-di-icon">🧪</span>
                    <span>
                        <span class="nav-di-title">Fine Chemicals</span>
                        <span class="nav-di-sub">All major brands, sourced for you</span>
                    </span>
                </a>
                <a href="/consumables"     class="nav-dropdown-item" role="menuitem">
                    <span class="nav-di-icon">🔩</span>
                    <span>
                        <span class="nav-di-title">Consumables</span>
                        <span class="nav-di-sub">HPLC columns, septa, filters &amp; more</span>
                    </span>
                </a>
            </div>
        </div>

        <!-- Services dropdown -->
        <div class="nav-dropdown-wrap">
            <button class="nav-link nav-dropdown-btn" aria-expanded="false" aria-haspopup="true" id="nav-services-btn">
                Services
                <svg class="nav-dropdown-caret" width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="2,3 5,7 8,3"/></svg>
            </button>
            <div class="nav-dropdown" id="nav-services-menu" role="menu" aria-labelledby="nav-services-btn" hidden>
                <a href="/custom-synthesis" class="nav-dropdown-item" role="menuitem">
                    <span class="nav-di-icon">⚗️</span>
                    <span>
                        <span class="nav-di-title">Custom Synthesis</span>
                        <span class="nav-di-sub">Bespoke molecule manufacturing</span>
                    </span>
                </a>
                <a href="/purification"     class="nav-dropdown-item" role="menuitem">
                    <span class="nav-di-icon">🧫</span>
                    <span>
                        <span class="nav-di-title">Purification</span>
                        <span class="nav-di-sub">Preparative chromatography services</span>
                    </span>
                </a>
            </div>
        </div>

        <a href="/about" class="nav-link">About</a>

        <!-- Theme Toggle Button -->
        <button class="theme-toggle-btn" id="theme-toggle" aria-label="Toggle dark mode">
            <svg class="icon-sun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <svg class="icon-moon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
        </button>

        <a href="/cart" class="nav-link cart-link" title="View Cart">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
            <?php if ($cartCount > 0): ?>
            <span class="cart-badge"><?= $cartCount > 99 ? '99+' : $cartCount ?></span>
            <?php endif; ?>
        </a>

        <?php if (isset($_SESSION['user'])): ?>
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <a href="/admin"     class="nav-link nav-link-admin">Admin</a>
            <?php else: ?>
                <a href="/dashboard" class="nav-link">Dashboard</a>
            <?php endif; ?>
            <a href="/logout"        class="nav-link nav-link-outline">Logout</a>
        <?php else: ?>
            <a href="/signin"        class="nav-link nav-link-signin">Sign In</a>
        <?php endif; ?>
    </nav>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" aria-label="Toggle navigation menu">
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
    </button>
</div>

<!-- Mobile Menu -->
<div id="mobile-menu" class="mobile-menu" hidden>
    <div class="mobile-search-wrapper">
        <form action="/search.php" method="get" class="search-form" id="mobile-search-form">
            <input type="hidden" name="search_type" value="auto">
            <input type="text" name="q" id="mobile-search-input"
                   placeholder="Search compounds..." autocomplete="off" class="search-input">
            <button type="submit" class="search-btn">🔍</button>
        </form>
        <div id="mobile-search-ac-dropdown" class="ac-dropdown"></div>
    </div>
    <a href="/catalog"          class="mobile-nav-link">📦 Our Catalog</a>
    <a href="/fine-chemicals"   class="mobile-nav-link">🧪 Fine Chemicals</a>
    <a href="/consumables"      class="mobile-nav-link">🔩 Consumables</a>
    <a href="/structure-search" class="mobile-nav-link">🔬 Structure Search</a>
    <a href="/custom-synthesis" class="mobile-nav-link">⚗️ Custom Synthesis</a>
    <a href="/purification"     class="mobile-nav-link">🧫 Purification</a>
    <a href="/about"            class="mobile-nav-link">ℹ️ About Us</a>
    <a href="/contact"          class="mobile-nav-link">📧 Contact</a>
    <a href="/cart"             class="mobile-nav-link">🛒 Cart (<?= $cartCount ?>)</a>
    <?php if (isset($_SESSION['user'])): ?>
        <?php if ($_SESSION['role'] === 'Admin'): ?>
            <a href="/admin"     class="mobile-nav-link">🔧 Admin</a>
        <?php else: ?>
            <a href="/dashboard" class="mobile-nav-link">📊 Dashboard</a>
        <?php endif; ?>
        <a href="/logout"        class="mobile-nav-link mobile-nav-link-danger">🚪 Logout</a>
    <?php else: ?>
        <a href="/signin"        class="mobile-nav-link mobile-nav-link-accent">🔑 Sign In</a>
    <?php endif; ?>
</div>
</header>

<script src="/js/utils.js" defer></script>