<?php
/**
 * Groq Parent Drug Overview Generator
 *
 * Generates a ~100-word pharmaceutical chemistry overview for each distinct
 * parent_drug value in the compounds table, using the Qwen 32B model.
 * Results are stored in parent_drug_info and shown at the top of the
 * catalog page when a single parent drug filter is active.
 *
 * Dual-mode:
 *   CLI:  php cron_groq_parent_drug.php [--force]   (processes all missing; --force regenerates all)
 *   WEB:  visit /cron_groq_parent_drug.php as Admin  (preview + apply loop)
 *
 * URL params (web mode):
 *   ?apply=1       Write to DB (default: dry-run)
 *   ?limit=N       Drugs per batch (default 5, max 10)
 *   ?force=1       Regenerate entries that already have an overview
 *
 * Cron (weekly, Sun 04:00 — picks up newly added parent drugs):
 *   0 4 * * 0 /usr/bin/php /home/u670463068/domains/abchem.co.in/public_html/cron_groq_parent_drug.php >> /home/u670463068/cron_parent_drug.log 2>&1
 */

require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/csrf.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    if (!isset($_SESSION['user_id']) || !checkRole('Admin')) {
        http_response_code(403);
        die('Unauthorized.');
    }
    set_time_limit(0);
}

$apiKey = getenv('GROQ_API_KEY');
if (!$apiKey) {
    die("Error: GROQ_API_KEY not set.\n");
}

$model     = 'qwen/qwen3-32b';
$db        = Database::getInstance();

// CLI flags
$forceRegen = $isCli ? in_array('--force', $argv ?? []) : !empty($_POST['force'] ?? $_GET['force'] ?? '');
$reqLimit   = intval($_POST['limit'] ?? $_GET['limit'] ?? 5);
$batchSize  = $isCli ? 10 : max(1, min(10, $reqLimit));

// ── Apply gate (web) ──────────────────────────────────────────────────────────
$csrfError = '';
if ($isCli) {
    $applyToDb = true;
} elseif (!empty($_POST['apply'])) {
    if (CSRF::verify($_POST['csrf_token'] ?? null)) {
        $applyToDb = true;
    } else {
        $applyToDb = false;
        $csrfError = 'Security token expired — showing dry-run only.';
    }
} else {
    $applyToDb = false;
}

// ── Ensure table exists ───────────────────────────────────────────────────────
$db->query("
CREATE TABLE IF NOT EXISTS `parent_drug_info` (
  `id`           INT(11)       NOT NULL AUTO_INCREMENT,
  `parent_drug`  VARCHAR(200)  NOT NULL,
  `overview`     TEXT          DEFAULT NULL,
  `model_used`   VARCHAR(100)  DEFAULT NULL,
  `generated_at` DATETIME      DEFAULT NULL,
  `updated_at`   TIMESTAMP     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parent_drug` (`parent_drug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// ── Fetch parent drugs to process ─────────────────────────────────────────────
if ($forceRegen) {
    // Regenerate all (or those already in the table too)
    $targets = $db->fetchAll(
        "SELECT DISTINCT c.parent_drug
         FROM compounds c
         WHERE c.status = 'Active'
           AND c.parent_drug IS NOT NULL AND c.parent_drug != '' AND c.parent_drug != 'NA'
         ORDER BY c.parent_drug
         LIMIT " . (int)$batchSize
    );
} else {
    // Only those with no existing overview
    $targets = $db->fetchAll(
        "SELECT DISTINCT c.parent_drug
         FROM compounds c
         LEFT JOIN parent_drug_info pdi ON pdi.parent_drug = c.parent_drug
         WHERE c.status = 'Active'
           AND c.parent_drug IS NOT NULL AND c.parent_drug != '' AND c.parent_drug != 'NA'
           AND (pdi.id IS NULL OR pdi.overview IS NULL OR pdi.overview = '')
         ORDER BY c.parent_drug
         LIMIT " . (int)$batchSize
    );
}

// ── CLI mode ──────────────────────────────────────────────────────────────────
if ($isCli) {
    if (empty($targets)) {
        echo "[OK] All parent drugs have overviews. Nothing to do. (Use --force to regenerate.)\n";
        exit(0);
    }
    echo "[" . date('Y-m-d H:i:s') . "] Processing " . count($targets) . " parent drugs with {$model}\n";
    foreach ($targets as $t) {
        $drug   = $t['parent_drug'];
        $result = generateOverview($drug, $apiKey, $model);

        if (!$result['ok'] && $result['rate_limit']) {
            echo "  Rate limit. Sleeping 65s, then retrying {$drug}...\n";
            sleep(65);
            $result = generateOverview($drug, $apiKey, $model);
        }

        if ($result['ok']) {
            saveOverview($db, $drug, $result['overview'], $model);
            echo "  ✓ {$drug}\n";
        } else {
            echo "  ✗ {$drug}: {$result['error']}\n";
        }
        sleep(2);
    }
    echo "[" . date('Y-m-d H:i:s') . "] Batch complete.\n";
    exit(0);
}

// ── Web mode — generate batch and collect results ─────────────────────────────
$rateLimitHit = false;
$results      = [];
foreach ($targets as $t) {
    $drug   = $t['parent_drug'];
    $result = generateOverview($drug, $apiKey, $model);
    $result['drug'] = $drug;
    $results[]      = $result;
    if (!$result['ok'] && $result['rate_limit']) {
        $rateLimitHit = true;
        break;
    }
    if ($result['ok'] && $applyToDb) {
        saveOverview($db, $drug, $result['overview'], $model);
    }
    sleep(2);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Parent Drug Overview Generator</title>
    <style>
        body { font-family: system-ui, sans-serif; background:#f8fafc; color:#334155; padding:24px; max-width:1000px; margin:0 auto; }
        .card { background:white; padding:20px; border-radius:8px; box-shadow:0 2px 6px rgba(0,0,0,0.08); margin-bottom:16px; }
        h2 { margin-top:0; }
        .config { background:#fef3c7; border-left:4px solid #f59e0b; padding:12px 16px; margin-bottom:16px; border-radius:4px; }
        .config.apply { background:#d1fae5; border-color:#16a34a; }
        .badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.75em; font-weight:600; }
        .badge-dryrun { background:#fef3c7; color:#92400e; }
        .badge-apply  { background:#d1fae5; color:#065f46; }
        form.controls { display:flex; gap:12px; flex-wrap:wrap; align-items:end; margin-top:12px; }
        form.controls label { display:block; font-size:.85em; color:#64748b; margin-bottom:4px; }
        form.controls input[type=number] { padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; width:80px; }
        form.controls button { background:#7c3aed; color:white; border:none; padding:8px 16px; border-radius:4px; cursor:pointer; }
        form.controls button.apply { background:#16a34a; }
        .result-card { border:1px solid #e2e8f0; border-radius:6px; padding:14px; margin-bottom:12px; }
        .drug-name { font-weight:600; font-size:1.05rem; color:#0f172a; margin-bottom:8px; }
        .overview-text { background:#f1f5f9; padding:10px 14px; border-radius:6px; font-size:.9em; line-height:1.6; white-space:pre-wrap; }
        .success { color:#16a34a; } .error { color:#dc2626; }
        .meta { font-size:.8em; color:#64748b; margin-top:6px; }
        .empty { background:#ecfdf5; color:#065f46; padding:12px; border-radius:8px; }
    </style>
</head>
<body>
<?php if (!empty($csrfError)): ?>
    <div class="card" style="background:#fee2e2;border-left:4px solid #dc2626;">
        <strong style="color:#991b1b;">⚠️ <?= e($csrfError) ?></strong>
    </div>
<?php endif; ?>

<div class="card">
    <h2>💊 Parent Drug Overview Generator
        <?php if ($applyToDb): ?>
            <span class="badge badge-apply">APPLY MODE — writing to DB</span>
        <?php else: ?>
            <span class="badge badge-dryrun">DRY-RUN — not writing to DB</span>
        <?php endif; ?>
    </h2>
    <div class="config <?= $applyToDb ? 'apply' : '' ?>">
        <strong>Model:</strong> <?= e($model) ?><br>
        <strong>Batch size:</strong> <?= $batchSize ?> drugs
        <?= $forceRegen ? ' &middot; <strong>Force regenerate: ON</strong>' : '' ?>
    </div>
    <form class="controls" method="post">
        <?= CSRF::field() ?>
        <div>
            <label>Batch limit</label>
            <input type="number" name="limit" min="1" max="10" value="<?= e((string)$batchSize) ?>">
        </div>
        <div>
            <label><input type="checkbox" name="force" value="1" <?= $forceRegen ? 'checked' : '' ?>>
                Force regenerate existing</label>
        </div>
        <div>
            <label><input type="checkbox" name="apply" value="1" <?= $applyToDb ? 'checked' : '' ?>>
                Apply to DB</label>
        </div>
        <button type="submit" class="<?= $applyToDb ? 'apply' : '' ?>">
            <?= $applyToDb ? '▶ Apply & Continue' : '👁 Preview (dry-run)' ?>
        </button>
    </form>
</div>

<?php if (empty($targets)): ?>
<div class="card"><p class="empty">✅ All parent drugs have overviews. Nothing to generate.
    <?php if (!$forceRegen): ?> Tick "Force regenerate" to refresh existing ones.<?php endif; ?>
</p></div>
<?php else: ?>
    <?php foreach ($results as $r): ?>
    <div class="result-card">
        <div class="drug-name">💊 <?= e($r['drug']) ?></div>
        <?php if (!$r['ok']): ?>
            <p class="error">✗ <?= e($r['error']) ?></p>
        <?php else: ?>
            <div class="overview-text"><?= e($r['overview']) ?></div>
            <div class="meta">
                ~<?= str_word_count($r['overview']) ?> words &middot;
                <?= number_format($r['tokens']) ?> tokens
                <?php if ($applyToDb): ?> &middot; <span class="success">✓ Written to DB</span><?php else: ?> &middot; <span style="color:#92400e;">Dry-run</span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($applyToDb):
        $delayMs = $rateLimitHit ? 65000 : 5000; ?>
    <div class="card">
        <?php if ($rateLimitHit): ?>
            <p class="error">Rate limit hit — pausing <?= $delayMs/1000 ?>s then auto-continuing.</p>
        <?php else: ?>
            <p><i>Auto-continuing in 5 seconds… (close tab to stop)</i></p>
        <?php endif; ?>
    </div>
    <form id="autocontinue" method="post" style="display:none;">
        <?= CSRF::field() ?>
        <input type="hidden" name="apply" value="1">
        <input type="hidden" name="limit" value="<?= e((string)$batchSize) ?>">
        <?php if ($forceRegen): ?><input type="hidden" name="force" value="1"><?php endif; ?>
    </form>
    <script>setTimeout(function(){ document.getElementById('autocontinue').submit(); }, <?= $delayMs ?>);</script>
    <?php else: ?>
    <div class="card"><p>Dry-run complete. Tick <strong>Apply to DB</strong> and submit to save.</p></div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>
<?php

// ── Helpers ───────────────────────────────────────────────────────────────────

function generateOverview(string $drug, string $apiKey, string $model): array {
    $prompt = "Write a 100-word pharmaceutical chemistry assignment overview of {$drug} in a single concise paragraph using scientific terminology. Include: IUPAC name, Molecular formula and molecular weight, Chemical class and structural characteristics, Therapeutic applications, Important physicochemical properties, Mechanism of action. Do NOT include medical advice. Return valid JSON with exactly one key: overview.";

    $payload = [
        'model'           => $model,
        'messages'        => [['role' => 'user', 'content' => $prompt]],
        'response_format' => ['type' => 'json_object'],
        'temperature'     => 0.3,
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($code === 429) {
        return ['ok' => false, 'rate_limit' => true, 'error' => 'Rate limit (429)', 'overview' => '', 'tokens' => 0];
    }
    if ($code !== 200) {
        return ['ok' => false, 'rate_limit' => false, 'error' => "HTTP {$code}", 'overview' => '', 'tokens' => 0];
    }

    $data = json_decode($resp, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    $gen  = json_decode($text, true);

    if (!$gen || empty($gen['overview'])) {
        return ['ok' => false, 'rate_limit' => false, 'error' => 'No "overview" key in response', 'overview' => '', 'tokens' => 0, 'raw' => $text];
    }

    return [
        'ok'         => true,
        'rate_limit' => false,
        'overview'   => trim($gen['overview']),
        'tokens'     => $data['usage']['total_tokens'] ?? 0,
    ];
}

function saveOverview(Database $db, string $drug, string $overview, string $model): void {
    $db->query(
        "INSERT INTO parent_drug_info (parent_drug, overview, model_used, generated_at)
         VALUES (:drug, :overview, :model, NOW())
         ON DUPLICATE KEY UPDATE overview = :overview2, model_used = :model2, generated_at = NOW()",
        [
            'drug'      => $drug,
            'overview'  => $overview,
            'model'     => $model,
            'overview2' => $overview,
            'model2'    => $model,
        ]
    );
}
