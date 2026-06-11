<?php
/**
 * Admin utility to predict missing CAS numbers using Groq's free LLM API.
 *
 * Companion to admin_groq.php, but FOCUSED on CAS only:
 *   - Targets any compound with empty cas_number, regardless of current ai_predicted_cas
 *   - Tight prompt: asks ONLY for the CAS number (no description / meta noise)
 *   - Validates the model's answer with the CAS check-digit algorithm before storing
 *   - Writes only the `ai_predicted_cas` column
 *
 * Dual-mode (same as admin_groq.php):
 *   - WEB:  small batch with browser meta-refresh
 *   - CLI:  loops the batch in one process for cron
 *
 * Cron-job-ready usage:
 *   /usr/bin/php -q /home/u670463068/domains/abchem.co.in/public_html/admin_groq_cas.php
 *
 * URL params (web mode):
 *   ?model=...     Override the model
 *   ?limit=N       Compounds per batch (default 10 — CAS prompt is much smaller than full content)
 *   ?apply=1       Actually UPDATE the database. Default is DRY-RUN (preview only).
 */

require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/csrf.php';

/**
 * CAS check-digit validator. Duplicated from casGroqValidCheck() in
 * cas_verify.php — that file is a full admin page (runs POST handlers and
 * renders HTML on require), so we can't just include it for one helper.
 */
function casGroqValidCheck(string $cas): bool {
    $digits = preg_replace('/[^0-9]/', '', $cas);
    $len = strlen($digits);
    if ($len < 5 || $len > 10) return false;
    $check = (int)$digits[$len - 1];
    $sum = 0;
    for ($i = 0; $i < $len - 1; $i++) {
        $sum += (int)$digits[$len - 2 - $i] * ($i + 1);
    }
    return ($sum % 10) === $check;
}

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (!isset($_SESSION['user_id']) || !checkRole('Admin')) {
        http_response_code(403);
        die('Unauthorized access. Please log in as an administrator.');
    }
    // Batch loop sleeps 2s per compound — prevent web max_execution_time kill mid-batch.
    set_time_limit(0);
}

$apiKey = getenv('GROQ_API_KEY');
if (!$apiKey) {
    die("Error: GROQ_API_KEY is not set in your .env file.\n"
      . "Get a free key at https://console.groq.com/keys");
}

$AVAILABLE_MODELS = [
    'llama-3.3-70b-versatile'         => 'Llama 3.3 70B — best factual recall, 30 RPM free',
    'qwen/qwen3-32b'                  => 'Qwen3 32B — strong technical recall',
    'llama-3.1-8b-instant'            => 'Llama 3.1 8B — fastest, weaker recall',
    'deepseek-r1-distill-llama-70b'   => 'DeepSeek R1 distill — reasoning-heavy',
    'meta-llama/llama-4-scout-17b-16e-instruct' => 'Llama 4 Scout — newer',
    'gemma2-9b-it'                    => 'Gemma 2 9B — small/fast',
];

// Default to the 70B model — CAS recall is a factual-knowledge task, larger models do better.
$defaultModel = 'llama-3.3-70b-versatile';
// Apply path posts; the initial dry-run preview link may pass ?model= via GET.
$model = $_POST['model'] ?? $_GET['model'] ?? $defaultModel;
if (!isset($AVAILABLE_MODELS[$model])) {
    $model = $defaultModel;
}

// CAS prompt is tiny (~250 tokens), but web is still capped at 10 so a single
// request can't outrun max_execution_time. CLI runs larger batches.
$reqLimit  = intval($_POST['limit'] ?? $_GET['limit'] ?? 10);
$batchSize = $isCli ? 10 : max(1, min(10, $reqLimit));

// ── Apply-to-DB gate ──────────────────────────────────────────────────────────
// Writes only happen on a POST carrying a valid CSRF token. CLI always applies.
$csrfError = '';
if ($isCli) {
    $applyToDb = true;
} elseif (!empty($_POST['apply'])) {
    if (CSRF::verify($_POST['csrf_token'] ?? null)) {
        $applyToDb = true;
    } else {
        $applyToDb = false;
        $csrfError = 'Security token expired or invalid — re-submit to apply. Showing dry-run only (nothing written).';
    }
} else {
    $applyToDb = false;
}

$db = Database::getInstance();

// Target: empty CAS, regardless of ai_predicted_cas (per user choice — overwrite mode).
// `synonyms` is pulled in for extra prompt context (often contains IUPAC/brand names that disambiguate).
$compounds = $db->fetchAll(
    "SELECT id, compound_name, cas_number, molecular_formula, product_type, parent_drug, synonyms
     FROM compounds
     WHERE status = 'Active'
       AND (cas_number IS NULL OR cas_number = '' OR cas_number = 'NA')
     ORDER BY id
     LIMIT " . (int)$batchSize
);

// ────────────────────────────────────────────────────────────────────────────
// CLI MODE
// ────────────────────────────────────────────────────────────────────────────
if ($isCli) {
    if (empty($compounds)) {
        echo "[OK] No compounds with empty cas_number. Nothing to do.\n";
        exit(0);
    }
    echo "[" . date('Y-m-d H:i:s') . "] Predicting CAS for " . count($compounds) . " compounds with $model\n";
    $fallbackModel = 'qwen/qwen3-32b';
    foreach ($compounds as $c) {
        $result = predictCas($c, $apiKey, $model);

        if (!$result['ok'] && !$result['rate_limit'] && $model !== $fallbackModel
            && strpos($result['raw'] ?? '', 'json_validate_failed') !== false) {
            echo "    ↻ retrying {$c['compound_name']} with $fallbackModel\n";
            sleep(1);
            $result = predictCas($c, $apiKey, $fallbackModel);
        }

        // Rate limit: pause for the quota window, then retry the SAME compound
        // once so it isn't skipped and left unprocessed for this run.
        if (!$result['ok'] && $result['rate_limit']) {
            echo "  Rate limit hit. Sleeping 65s, then retrying {$c['compound_name']} once...\n";
            sleep(65);
            $result = predictCas($c, $apiKey, $model);
        }

        if ($result['ok']) {
            $cas = $result['data']['cas_number'] ?? null;
            $conf = $result['data']['confidence'] ?? 'unknown';
            $stored = storeCas($db, $c['id'], $cas);
            echo "  ✓ {$c['compound_name']} → " . ($stored ?? 'N/A') . " (conf: $conf)\n";
        } else {
            echo "  ✗ {$c['compound_name']}: {$result['error']}\n";
            if (!empty($result['raw'])) {
                $rawSnippet = substr(trim(preg_replace('/\s+/', ' ', $result['raw'])), 0, 500);
                echo "    └─ raw: {$rawSnippet}\n";
            }
        }
        sleep(2); // 30 RPM cap on Groq free tier
    }
    echo "[" . date('Y-m-d H:i:s') . "] Batch complete.\n";
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────────
// WEB MODE
// ────────────────────────────────────────────────────────────────────────────
$rateLimitHit = false;
$totalTokens = 0;
$results = [];

foreach ($compounds as $c) {
    $result = predictCas($c, $apiKey, $model);
    $result['compound'] = $c;
    if ($result['ok']) {
        $totalTokens += $result['tokens'];
        if ($applyToDb) {
            $result['stored'] = storeCas($db, $c['id'], $result['data']['cas_number'] ?? null);
        }
    }
    $results[] = $result;
    if (!$result['ok'] && $result['rate_limit']) {
        $rateLimitHit = true;
        break;
    }
    sleep(2);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Groq CAS Predictor</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #334155; padding: 24px; max-width: 1100px; margin: 0 auto; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); margin-bottom: 16px; }
        h2 { margin-top: 0; }
        .config { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; margin-bottom: 16px; border-radius: 4px; }
        .config.apply { background: #d1fae5; border-color: #16a34a; }
        form.controls { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-top: 12px; }
        form.controls label { display: block; font-size: 0.85em; color: #64748b; margin-bottom: 4px; }
        form.controls select, form.controls input { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; }
        form.controls button { background: #2563eb; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; }
        form.controls button.apply { background: #16a34a; }
        .compound-card { border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-bottom: 12px; background: #fff; }
        .compound-header { font-weight: 600; color: #0f172a; margin-bottom: 8px; }
        .cas-pill { display: inline-block; padding: 2px 10px; border-radius: 12px; font-family: 'JetBrains Mono', monospace; font-size: 0.95em; }
        .cas-high   { background: #d1fae5; color: #065f46; }
        .cas-medium { background: #fef3c7; color: #92400e; }
        .cas-low    { background: #fee2e2; color: #991b1b; }
        .cas-null   { background: #f1f5f9; color: #64748b; font-style: italic; }
        .meta { font-size: 0.85em; color: #64748b; margin-top: 6px; }
        .error { color: #dc2626; }
        .err-pre { font-size: 0.75em; color: #64748b; background: #f1f5f9; padding: 8px; border-radius: 4px; overflow-x: auto; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75em; font-weight: 600; }
        .badge-dryrun { background: #fef3c7; color: #92400e; }
        .badge-apply  { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
<?php if (!empty($csrfError)): ?>
    <div class="card" style="background:#fee2e2;border-left:4px solid #dc2626;">
        <strong style="color:#991b1b;">⚠️ <?= e($csrfError) ?></strong>
    </div>
<?php endif; ?>
<div class="card">
    <h2>🧪 Groq CAS Predictor
        <?php if ($applyToDb): ?>
            <span class="badge badge-apply">APPLY MODE — writing to DB</span>
        <?php else: ?>
            <span class="badge badge-dryrun">DRY-RUN — not writing to DB</span>
        <?php endif; ?>
    </h2>

    <div class="config <?= $applyToDb ? 'apply' : '' ?>">
        <strong>Model:</strong> <?= e($model) ?> &mdash; <?= e($AVAILABLE_MODELS[$model]) ?><br>
        <strong>Batch size:</strong> <?= $batchSize ?> &middot;
        <strong>Tokens used:</strong> <?= number_format($totalTokens) ?> &middot;
        <strong>Target:</strong> any compound with empty cas_number (ignores existing ai_predicted_cas — overwrite mode)
    </div>

    <form class="controls" method="post">
        <?= CSRF::field() ?>
        <div>
            <label>Model</label>
            <select name="model">
                <?php foreach ($AVAILABLE_MODELS as $m => $desc): ?>
                    <option value="<?= e($m) ?>" <?= $m === $model ? 'selected' : '' ?>>
                        <?= e($m) ?> — <?= e($desc) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Batch limit</label>
            <input type="number" name="limit" min="1" max="50" value="<?= e($batchSize) ?>">
        </div>
        <div>
            <label><input type="checkbox" name="apply" value="1" <?= $applyToDb ? 'checked' : '' ?>> Apply to DB</label>
        </div>
        <button type="submit" class="<?= $applyToDb ? 'apply' : '' ?>">
            <?= $applyToDb ? 'Apply to DB →' : 'Preview again (dry-run)' ?>
        </button>
    </form>
</div>

<?php if (empty($compounds)): ?>
    <div class="card">
        <p style="color:#16a34a;">✅ No compounds with empty cas_number. Nothing to predict.</p>
    </div>
<?php else: ?>
    <?php foreach ($results as $r): $c = $r['compound']; ?>
        <div class="compound-card">
            <div class="compound-header">
                <?= e($c['compound_name']) ?>
                <span class="meta">
                    &middot; Type: <?= e($c['product_type'] ?: 'n/a') ?>
                    &middot; Parent: <?= e($c['parent_drug'] ?: 'n/a') ?>
                    &middot; Formula: <?= e($c['molecular_formula'] ?: 'n/a') ?>
                </span>
            </div>

            <?php if (!$r['ok']): ?>
                <p class="error">✗ <?= e($r['error']) ?></p>
                <?php if (!empty($r['raw'])): ?>
                    <pre class="err-pre"><?= e($r['raw']) ?></pre>
                <?php endif; ?>
            <?php else: $d = $r['data']; $cas = $d['cas_number'] ?? null; $conf = $d['confidence'] ?? 'unknown'; ?>
                <?php
                    $pillClass = 'cas-null';
                    if ($cas) {
                        $pillClass = $conf === 'high' ? 'cas-high' : ($conf === 'medium' ? 'cas-medium' : 'cas-low');
                    }
                    $isValid = $cas ? casGroqValidCheck($cas) : false;
                ?>
                <div>
                    Predicted CAS:
                    <span class="cas-pill <?= $pillClass ?>"><?= e($cas ?: '(model returned null)') ?></span>
                    <span class="meta">
                        confidence: <strong><?= e($conf) ?></strong>
                        <?php if ($cas): ?>
                            &middot; check-digit: <?= $isValid ? '<span style="color:#16a34a;">valid</span>' : '<span style="color:#dc2626;">invalid (will be stored as N/A)</span>' ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (!empty($d['notes'])): ?>
                    <div class="meta">Notes: <?= e($d['notes']) ?></div>
                <?php endif; ?>
                <div class="meta">
                    Tokens: <?= number_format($r['tokens']) ?>
                    <?php if ($applyToDb): ?>
                        &middot; <span style="color:#16a34a;">✓ ai_predicted_cas ← <?= e($r['stored'] ?? 'N/A') ?></span>
                    <?php else: ?>
                        &middot; <span style="color:#92400e;">Dry-run — not saved</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($applyToDb): // apply mode → auto-continue to the next batch
        $delayMs = $rateLimitHit ? 65000 : 5000; ?>
        <div class="card">
            <?php if ($rateLimitHit): ?>
                <p class="error">Rate limit hit. Auto-continue paused for 65s…</p>
            <?php else: ?>
                <p><i>Auto-continuing in 5 seconds for the next batch… (close this tab to stop)</i></p>
            <?php endif; ?>
        </div>
        <!-- POST auto-continue with a fresh CSRF token (survives token rotation). -->
        <form id="autocontinue" method="post" style="display:none;">
            <?= CSRF::field() ?>
            <input type="hidden" name="apply" value="1">
            <input type="hidden" name="model" value="<?= e($model) ?>">
            <input type="hidden" name="limit" value="<?= e((string)$batchSize) ?>">
        </form>
        <script>
            setTimeout(function () {
                document.getElementById('autocontinue').submit();
            }, <?= $delayMs ?>);
        </script>
    <?php else: ?>
        <div class="card"><p>Dry-run preview. Tick <strong>"Apply to DB"</strong> and submit to start writing.</p></div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>

<?php
// ────────────────────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────────────────────

function predictCas(array $c, string $apiKey, string $model): array {
    // Trim synonyms to the first ~12 entries so we don't burn tokens on long lists.
    $synonymsRaw = (string)($c['synonyms'] ?? '');
    $synonymsList = '';
    if ($synonymsRaw !== '' && $synonymsRaw !== 'NA') {
        $parts = array_slice(array_filter(array_map('trim', explode('|', $synonymsRaw))), 0, 12);
        if ($parts) $synonymsList = implode(' | ', $parts);
    }

    $prompt = "You are a chemistry reference assistant. Return the CAS Registry Number (CAS number) for the compound below.\n\n"
        . "Compound name: {$c['compound_name']}\n"
        . "Molecular formula: " . ($c['molecular_formula'] ?: 'unknown') . "\n"
        . "Product type: "      . ($c['product_type']      ?: 'unknown') . "\n"
        . "Parent drug: "       . ($c['parent_drug']       ?: 'unknown') . "\n"
        . ($synonymsList ? "Known synonyms: $synonymsList\n" : "")
        . "\n"
        . "A CAS number is formatted as XXXXXXX-XX-X (e.g., aspirin = 50-78-2, caffeine = 58-08-2, paracetamol = 103-90-2).\n"
        . "If the compound exists as multiple forms (racemate vs. enantiomer, free base vs. salt), return the CAS most commonly cited for the exact name above.\n\n"
        . "Return ONLY a JSON object with these keys:\n"
        . "  \"cas_number\":   the CAS number as a string in XXXXXXX-XX-X format, or null if you cannot identify the compound at all.\n"
        . "  \"confidence\":   one of \"high\" | \"medium\" | \"low\" | \"unknown\".\n"
        . "  \"notes\":        (optional) one short sentence on which form/isomer this CAS refers to. Omit if not relevant.\n\n"
        . "Do NOT include any prose outside the JSON. Do NOT wrap in markdown.";

    $payload = [
        "model"           => $model,
        "messages"        => [["role" => "user", "content" => $prompt]],
        "response_format" => ["type" => "json_object"],
        "temperature"     => 0.0, // CAS lookup is factual — no creativity
    ];

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($httpCode === 429) {
        return ['ok' => false, 'rate_limit' => true, 'error' => 'Rate limit (429)', 'raw' => $response];
    }
    if ($httpCode !== 200) {
        return ['ok' => false, 'rate_limit' => false, 'error' => "HTTP $httpCode", 'raw' => $response];
    }

    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        return ['ok' => false, 'rate_limit' => false, 'error' => 'Unexpected response shape', 'raw' => $response];
    }

    $jsonText = $data['choices'][0]['message']['content'];
    $generated = json_decode($jsonText, true);
    if (!is_array($generated) || !array_key_exists('cas_number', $generated)) {
        return ['ok' => false, 'rate_limit' => false, 'error' => 'Failed to parse model JSON', 'raw' => $jsonText];
    }

    return [
        'ok'         => true,
        'rate_limit' => false,
        'data'       => $generated,
        'tokens'     => $data['usage']['total_tokens'] ?? 0,
    ];
}

/**
 * Store the predicted CAS. Validates check-digit before accepting.
 * Returns the string actually written (or 'N/A' if rejected/null).
 */
function storeCas(Database $db, int $id, $cas): string {
    $cas = is_string($cas) ? trim($cas) : '';
    $valueToStore = ($cas !== '' && casGroqValidCheck($cas)) ? $cas : 'N/A';
    $db->query(
        "UPDATE compounds SET ai_predicted_cas = :ai_cas WHERE id = :id",
        ['ai_cas' => $valueToStore, 'id' => $id]
    );
    return $valueToStore;
}
