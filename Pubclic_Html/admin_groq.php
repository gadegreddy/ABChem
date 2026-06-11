<?php
/**
 * Admin utility to auto-generate Descriptions, Meta Descriptions, and predicted CAS
 * using Groq's free LLM API (OpenAI-compatible).
 *
 * Dual-mode:
 *   - WEB:  process small batches with browser meta-refresh (no SSH needed)
 *   - CLI:  process all compounds in a continuous loop (for cron jobs later)
 *
 * URL params (web mode):
 *   ?model=...     Override the model (see $AVAILABLE_MODELS below)
 *   ?limit=N       Compounds per batch (default 5)
 *   ?apply=1       Actually UPDATE the database. Default is DRY-RUN (preview only).
 *
 * Cron-job-ready usage (when SSH/cron available):
 *   /usr/bin/php -q /home/u670463068/domains/abchem.co.in/public_html/admin_groq.php
 *
 * Get a free Groq API key: https://console.groq.com/keys
 */

require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/csrf.php';

$isCli = (php_sapi_name() === 'cli');

// ── Auth (web mode only) ─────────────────────────────────────────────────────
if (!$isCli) {
    if (!isset($_SESSION['user_id']) || !checkRole('Admin')) {
        http_response_code(403);
        die('Unauthorized access. Please log in as an administrator.');
    }
    // Batch loop sleeps 2s per compound — without this a 10-row batch (20s+ of
    // sleep + API latency) can exceed the web max_execution_time and die mid-loop.
    set_time_limit(0);
}

// ── API key ──────────────────────────────────────────────────────────────────
$apiKey = getenv('GROQ_API_KEY');
if (!$apiKey) {
    die("Error: GROQ_API_KEY is not set in your .env file.\n"
      . "Get a free key at https://console.groq.com/keys");
}

// ── Available models (Groq free tier) ────────────────────────────────────────
// Switch via ?model=... in URL, or change $defaultModel below.
$AVAILABLE_MODELS = [
    'llama-3.3-70b-versatile'         => 'Llama 3.3 70B — best quality, 30 RPM free',
    'llama-3.1-8b-instant'            => 'Llama 3.1 8B — fastest, lower quality',
    'deepseek-r1-distill-llama-70b'   => 'DeepSeek R1 distill — reasoning-heavy',
    'qwen/qwen3-32b'                  => 'Qwen3 32B — strong technical writing',
    'meta-llama/llama-4-scout-17b-16e-instruct' => 'Llama 4 Scout — newer',
    'gemma2-9b-it'                    => 'Gemma 2 9B — small/fast',
];

$defaultModel = 'qwen/qwen3-32b';
// Apply path posts; the initial dry-run preview link may pass ?model= via GET.
$model = $_POST['model'] ?? $_GET['model'] ?? $defaultModel;
if (!isset($AVAILABLE_MODELS[$model])) {
    $model = $defaultModel;
}

// ── Batch config ─────────────────────────────────────────────────────────────
// CLI batch size kept small (5) to stay under Groq's free-tier TPM cap:
// qwen/qwen3-32b allows 6000 tokens/min; ~900 tokens per compound × 5 = 4500.
// Web is capped at 10 so a single request can't outrun max_execution_time.
$reqLimit  = intval($_POST['limit'] ?? $_GET['limit'] ?? 5);
$batchSize = $isCli ? 5 : max(1, min(10, $reqLimit));

// ── Apply-to-DB gate ──────────────────────────────────────────────────────────
// Writes only happen on a POST that carries a valid CSRF token. CLI always
// applies. A GET (or POST without apply) is a dry-run preview — no DB writes.
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

// ── Fetch compounds needing content ──────────────────────────────────────────
$compounds = $db->fetchAll(
    "SELECT id, compound_name, cas_number, molecular_formula, product_type, parent_drug
     FROM compounds
     WHERE status = 'Active'
       AND (description IS NULL OR description = ''
            OR meta_description IS NULL OR meta_description = ''
            OR ((cas_number IS NULL OR cas_number = '') AND ai_predicted_cas IS NULL))
     LIMIT " . (int)$batchSize
);

// ────────────────────────────────────────────────────────────────────────────
// CLI MODE: minimal text output, runs until done
// ────────────────────────────────────────────────────────────────────────────
if ($isCli) {
    if (empty($compounds)) {
        echo "[OK] All compounds have content. Nothing to do.\n";
        exit(0);
    }
    echo "[" . date('Y-m-d H:i:s') . "] Processing " . count($compounds) . " compounds with $model\n";
    $fallbackModel = 'llama-3.3-70b-versatile';
    foreach ($compounds as $c) {
        $result = generateContent($c, $apiKey, $model);

        // Retry json_validate_failed and other 400s once with a more reliable model
        if (!$result['ok'] && !$result['rate_limit'] && $model !== $fallbackModel
            && strpos($result['raw'] ?? '', 'json_validate_failed') !== false) {
            echo "    ↻ retrying {$c['compound_name']} with $fallbackModel\n";
            sleep(1);
            $result = generateContent($c, $apiKey, $fallbackModel);
        }

        // Rate limit: pause for the quota window, then retry the SAME compound
        // once so it isn't skipped and left unprocessed for this run.
        if (!$result['ok'] && $result['rate_limit']) {
            echo "  Rate limit hit. Sleeping 65s, then retrying {$c['compound_name']} once...\n";
            sleep(65);
            $result = generateContent($c, $apiKey, $model);
        }

        if ($result['ok']) {
            updateCompound($db, $c['id'], $result['data']);
            echo "  ✓ {$c['compound_name']}\n";
        } else {
            echo "  ✗ {$c['compound_name']}: {$result['error']}\n";
            if (!empty($result['raw'])) {
                $rawSnippet = substr(trim(preg_replace('/\s+/', ' ', $result['raw'])), 0, 500);
                echo "    └─ raw: {$rawSnippet}\n";
            }
        }
        sleep(2);  // 30 RPM cap on Groq free tier
    }
    echo "[" . date('Y-m-d H:i:s') . "] Batch complete.\n";
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────────
// WEB MODE: HTML output with preview/apply controls
// ────────────────────────────────────────────────────────────────────────────
$rateLimitHit = false;
$totalTokens = 0;
$results = [];

foreach ($compounds as $c) {
    $result = generateContent($c, $apiKey, $model);
    $result['compound'] = $c;
    $results[] = $result;
    if (!$result['ok'] && $result['rate_limit']) {
        $rateLimitHit = true;
        break;
    }
    if ($result['ok']) {
        $totalTokens += $result['tokens'];
        if ($applyToDb) {
            updateCompound($db, $c['id'], $result['data']);
        }
    }
    sleep(2);
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Groq Batch Generator</title>
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
        .field { margin: 8px 0; }
        .field-label { font-size: 0.75em; text-transform: uppercase; color: #64748b; font-weight: 600; letter-spacing: 0.05em; }
        .field-value { background: #f1f5f9; padding: 8px 12px; border-radius: 4px; margin-top: 2px; white-space: pre-wrap; font-size: 0.9em; }
        .success { color: #16a34a; }
        .error { color: #dc2626; }
        .meta { font-size: 0.85em; color: #64748b; }
        .err-pre { font-size: 0.75em; color: #64748b; background: #f1f5f9; padding: 8px; border-radius: 4px; overflow-x: auto; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75em; font-weight: 600; }
        .badge-dryrun { background: #fef3c7; color: #92400e; }
        .badge-apply { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
<?php if (!empty($csrfError)): ?>
    <div class="card" style="background:#fee2e2;border-left:4px solid #dc2626;">
        <strong style="color:#991b1b;">⚠️ <?= e($csrfError) ?></strong>
    </div>
<?php endif; ?>
<div class="card">
    <h2>🤖 Groq Batch Generator
        <?php if ($applyToDb): ?>
            <span class="badge badge-apply">APPLY MODE — writing to DB</span>
        <?php else: ?>
            <span class="badge badge-dryrun">DRY-RUN — not writing to DB</span>
        <?php endif; ?>
    </h2>

    <div class="config <?= $applyToDb ? 'apply' : '' ?>">
        <strong>Model:</strong> <?= e($model) ?> &mdash; <?= e($AVAILABLE_MODELS[$model]) ?><br>
        <strong>Batch size:</strong> <?= $batchSize ?> compounds &middot;
        <strong>Tokens used this batch:</strong> <?= number_format($totalTokens) ?>
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
        <p class="success">✅ All compounds have descriptions, meta descriptions, and (if needed) AI-predicted CAS. Nothing to process.</p>
    </div>
<?php else: ?>
    <?php foreach ($results as $r): $c = $r['compound']; ?>
        <div class="compound-card">
            <div class="compound-header">
                <?= e($c['compound_name']) ?>
                <span class="meta">
                    — CAS: <?= e($c['cas_number'] ?: '(missing)') ?>
                    &middot; Type: <?= e($c['product_type'] ?: 'n/a') ?>
                    &middot; Parent: <?= e($c['parent_drug'] ?: 'n/a') ?>
                </span>
            </div>

            <?php if (!$r['ok']): ?>
                <p class="error">✗ <?= e($r['error']) ?></p>
                <?php if (!empty($r['raw'])): ?>
                    <pre class="err-pre"><?= e($r['raw']) ?></pre>
                <?php endif; ?>
            <?php else: $d = $r['data']; ?>
                <div class="field">
                    <div class="field-label">Description (<?= str_word_count($d['description'] ?? '') ?> words)</div>
                    <div class="field-value"><?= e($d['description'] ?? '') ?></div>
                </div>
                <div class="field">
                    <div class="field-label">Meta description (<?= strlen($d['meta_description'] ?? '') ?> chars)</div>
                    <div class="field-value"><?= e($d['meta_description'] ?? '') ?></div>
                </div>
                <?php if (empty($c['cas_number'])): ?>
                    <div class="field">
                        <div class="field-label">AI-predicted CAS <span style="color:#dc2626;">(unverified — LLMs hallucinate)</span></div>
                        <div class="field-value"><?= e($d['predicted_cas'] ?? 'null') ?></div>
                    </div>
                <?php endif; ?>
                <div class="meta">
                    Tokens: <?= number_format($r['tokens']) ?>
                    <?php if ($applyToDb): ?>
                        &middot; <span class="success">✓ Written to DB</span>
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
        <!-- POST auto-continue: a fresh CSRF token is minted on every render, so
             the meta-refresh-style loop survives CSRF rotation. -->
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
        <div class="card">
            <p>This was a dry-run. Review the output above. If it looks good, check the <strong>"Apply to DB"</strong> box and click the button to actually write these (and continue with the next batch).</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>

<?php
// ────────────────────────────────────────────────────────────────────────────
// Helper functions
// ────────────────────────────────────────────────────────────────────────────

function generateContent(array $c, string $apiKey, string $model): array {
    $prompt = "You are an expert chemical catalog copywriter. Generate content for the following chemical:
Name: {$c['compound_name']}
CAS: {$c['cas_number']}
Formula: {$c['molecular_formula']}
Type: {$c['product_type']}
Parent Drug: {$c['parent_drug']}

Requirements:
1. 'description': Provide a highly technical, specific chemical description of this exact compound. Focus on its structural features, functional groups, and chemical relationship to the parent drug. End the paragraph with a single concise sentence detailing its precise analytical or synthetic application (e.g., HPLC reference standard, specific degradation byproduct). DO NOT mention its molecular formula. DO NOT use generic boilerplate phrases. Keep it strictly factual, dense, and unique (Maximum 130 words).
2. 'meta_description': An SEO-friendly meta description for the product webpage (under 150 characters).
3. 'predicted_cas': If the CAS is missing or empty, predict the most accurate CAS number for the compound {$c['compound_name']} CAS number. If unsure, return null.
Do NOT include medical advice.

Return your response as valid JSON with exactly these three keys: description, meta_description, predicted_cas.";

    $payload = [
        "model"           => $model,
        "messages"        => [["role" => "user", "content" => $prompt]],
        "response_format" => ["type" => "json_object"],
        "temperature"     => 0.3,
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
    // curl_close() removed — deprecated as of PHP 8.5 (no-op since 8.0).
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
    if (!$generated || !isset($generated['description'])) {
        return ['ok' => false, 'rate_limit' => false, 'error' => 'Failed to parse model JSON', 'raw' => $jsonText];
    }

    return [
        'ok'         => true,
        'rate_limit' => false,
        'data'       => $generated,
        'tokens'     => $data['usage']['total_tokens'] ?? 0,
    ];
}

function updateCompound(Database $db, int $id, array $data): void {
    $db->query(
        "UPDATE compounds
         SET description = :desc,
             meta_description = :meta,
             ai_predicted_cas = :ai_cas
         WHERE id = :id",
        [
            'desc'   => $data['description']      ?? '',
            'meta'   => $data['meta_description'] ?? '',
            'ai_cas' => $data['predicted_cas']    ?? 'N/A',
            'id'     => $id,
        ]
    );
}
