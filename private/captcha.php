<?php
/**
 * Captcha — 4-layer anti-bot verification for public forms
 *
 * Layers (each independently catches a different bot class):
 *   1. HONEYPOT field   — CSS-hidden input that real users never fill, but
 *                         naive bots fill every visible input. Trips on any
 *                         non-empty value.
 *   2. MIN-FILL TIME    — server records the page-load timestamp in session;
 *                         rejects submissions arriving in < MIN_FILL_SECONDS
 *                         (catches scripts that POST instantly).
 *   3. CHECKBOX         — explicit "I am not a robot" tick. Trivial for
 *                         humans, an extra hurdle for headless browsers that
 *                         skip non-required field interactions.
 *   4. WORD CHALLENGE   — display a random chemistry-themed word, user types
 *                         it back. The word lives in $_SESSION (not in a
 *                         hidden form field), so a bot must do a real GET
 *                         then POST.
 *
 * USAGE on a form page:
 *   require_once __DIR__ . '/../private/captcha.php';
 *   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 *       if (($err = Captcha::verify($_POST)) !== null) {
 *           $error = $err;       // re-render the form with the error
 *       } else {
 *           // ... normal form processing ...
 *       }
 *   }
 *   // Inside the <form>:
 *   echo Captcha::renderHtml();
 *
 * The CSRF token, rate limiter, and this captcha are independent layers —
 * keep all three on the high-risk forms.
 */
class Captcha {
    private const SESSION_KEY      = 'captcha';
    private const MIN_FILL_SECONDS = 2;
    private const MAX_AGE_SECONDS  = 1800; // 30-min session lifetime for the challenge

    /**
     * Chemistry-themed word pool. Short, all-caps, recognisable English so any
     * human can read them. The word is shown in the HTML — security comes from
     * the combination of all four layers, not from the word being unguessable.
     */
    private const WORDS = [
        'BENZENE', 'SOLVENT', 'REAGENT', 'ANALYTE', 'BUFFER',
        'ACETONE', 'PURITY',  'CHIRAL',  'PROTON',  'MOLECULE',
        'CATALYST','TITRATE', 'AROMATIC','POLAR',   'CHEMIST',
        'ISOMER',  'OXYGEN',  'CARBON',  'ETHANOL', 'METHYL',
    ];

    /**
     * Render the four-layer captcha as an HTML fragment. The caller is
     * responsible for wrapping it inside a <form>. Generates a new challenge
     * on each render so reload works correctly.
     */
    public static function renderHtml(): string {
        self::generate();
        $word = $_SESSION[self::SESSION_KEY]['word'];
        $w    = htmlspecialchars($word, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!-- 1. Honeypot — invisible to humans, bots fill every input -->
<div style="position:absolute;left:-9999px;top:-9999px;visibility:hidden;" aria-hidden="true">
    <label for="captcha_website">Website (leave this blank)</label>
    <input type="text" name="captcha_website" id="captcha_website" tabindex="-1" autocomplete="off" value="">
</div>

<!-- 2-4. Visible captcha block (checkbox + word challenge) -->
<div class="captcha-block" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin:16px 0;">
    <label style="display:flex;align-items:center;gap:10px;font-size:0.92rem;margin-bottom:12px;cursor:pointer;color:#0f172a;">
        <input type="checkbox" name="captcha_human" value="1" required style="width:18px;height:18px;cursor:pointer;accent-color:#0e7abf;">
        <span>I am not a robot</span>
    </label>
    <label for="captcha_word_input" style="display:block;font-size:0.85rem;color:#475569;margin-bottom:6px;">
        Type the word shown below:
    </label>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="font-family:'JetBrains Mono',monospace;font-weight:700;color:#0e7abf;background:white;padding:6px 14px;border-radius:6px;border:1px dashed #94a3b8;user-select:none;letter-spacing:3px;font-size:1.05rem;">$w</span>
        <input type="text" name="captcha_word_input" id="captcha_word_input" required autocomplete="off" spellcheck="false"
               style="flex:1;min-width:160px;max-width:240px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:2px;font-size:0.95rem;"
               placeholder="Type the word">
    </div>
</div>
HTML;
    }

    /**
     * Verify a submitted form. Returns null on success (and consumes the
     * challenge so it can't be replayed), or a human-readable error string.
     * Caller treats any non-null return as a captcha failure.
     */
    public static function verify(array $post): ?string {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();

        // ── Layer 1: Honeypot ──────────────────────────────────────────────
        if (!empty($post['captcha_website'])) {
            self::logFail('honeypot', $post['captcha_website']);
            return 'Verification failed. Please try again.';
        }

        // ── Session state must exist ──────────────────────────────────────
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$state || empty($state['word']) || empty($state['loaded_at'])) {
            return 'Verification session expired. Please reload the page and try again.';
        }

        // Stale challenge — guards against tabs left open for hours
        if ((time() - $state['loaded_at']) > self::MAX_AGE_SECONDS) {
            unset($_SESSION[self::SESSION_KEY]);
            return 'Verification expired. Please reload the page and try again.';
        }

        // ── Layer 2: Min-fill time ─────────────────────────────────────────
        if ((time() - $state['loaded_at']) < self::MIN_FILL_SECONDS) {
            self::logFail('too_fast', (string)(time() - $state['loaded_at']));
            return 'Form submitted too quickly. Please try again.';
        }

        // ── Layer 3: Checkbox ─────────────────────────────────────────────
        if (empty($post['captcha_human'])) {
            return 'Please tick "I am not a robot".';
        }

        // ── Layer 4: Word match (case-insensitive) ────────────────────────
        $expected = strtoupper(trim($state['word']));
        $given    = strtoupper(trim($post['captcha_word_input'] ?? ''));
        if ($given !== $expected) {
            return 'The word you typed does not match. Please try again.';
        }

        // Success — consume the challenge so the same answer can't be replayed
        unset($_SESSION[self::SESSION_KEY]);
        return null;
    }

    /**
     * Generate a fresh challenge and store in session.
     * Called automatically by renderHtml(); rarely needed manually.
     */
    public static function generate(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION[self::SESSION_KEY] = [
            'word'      => self::WORDS[array_rand(self::WORDS)],
            'loaded_at' => time(),
        ];
    }

    /** Log a captcha failure for monitoring. */
    private static function logFail(string $reason, string $detail): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80);
        error_log("[captcha] reason=$reason ip=$ip ua=\"$ua\" detail=" . substr($detail, 0, 40));
    }
}
