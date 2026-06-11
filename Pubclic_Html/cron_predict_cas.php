<?php
/**
 * Cron utility to auto-generate AI Predicted CAS numbers
 * using Gemini REST API.
 * 
 * Usage for cron job:
 *   /usr/bin/php -q /home/u670463068/domains/abchem.co.in/public_html/cron_predict_cas.php
 */

require_once __DIR__ . '/../private/functions.php';

// Enforce CLI only to prevent web-based execution if desired for cron.
if (php_sapi_name() !== 'cli') {
    die("This script is intended to be run from the command line (cron) only.\nUsage: php cron_predict_cas.php\n");
}

$apiKey = getenv('GEMINI_API_KEY');
if (!$apiKey) {
    die("Error: GEMINI_API_KEY is not set in your .env file.\n");
}

$batchSize = 10; // Process 10 compounds per batch
$db = Database::getInstance();

$compounds = $db->fetchAll(
    "SELECT id, compound_name 
     FROM compounds 
     WHERE status = 'Active' 
       AND (cas_number IS NULL OR cas_number = '') 
       AND (ai_predicted_cas IS NULL OR ai_predicted_cas = '' OR ai_predicted_cas = 'N/A')
     LIMIT :limit",
    ['limit' => $batchSize]
);

if (empty($compounds)) {
    echo "[" . date('Y-m-d H:i:s') . "] [OK] All missing CAS numbers have been processed.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Processing batch of " . count($compounds) . " compounds...\n";

foreach ($compounds as $c) {
    $compoundName = $c['compound_name'];
    echo "  > Processing: {$compoundName}\n";
    
    // Explicitly appending "CAS number" to the prompt for better AI context as requested
    $prompt = "You are a chemical data expert. Your task is to find the CAS Registry Number for the following compound.
Compound Name: {$compoundName} CAS number

Requirements:
1. Provide ONLY the precise CAS Registry Number for this exact compound.
2. If you are unsure, or if the compound is a generic class without a single specific CAS, return null.
3. Return a JSON object with exactly one key: 'predicted_cas'.";

    $payload = [
        "contents" => [
            ["parts" => [["text" => $prompt]]]
        ],
        "generationConfig" => [
            "response_mime_type" => "application/json",
            "temperature" => 0.1
        ]
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $jsonText = $data['candidates'][0]['content']['parts'][0]['text'];
            $generated = json_decode($jsonText, true);

            if ($generated && array_key_exists('predicted_cas', $generated)) {
                $cas = $generated['predicted_cas'];
                
                // Normalizing cases where the AI returns string "null" or "N/A"
                if (is_string($cas) && in_array(strtolower(trim($cas)), ['null', 'n/a', 'none', ''], true)) {
                    $cas = null;
                }

                $db->query(
                    "UPDATE compounds 
                     SET ai_predicted_cas = :ai_cas
                     WHERE id = :id",
                    [
                        'ai_cas' => $cas ?? 'N/A',
                        'id'     => $c['id']
                    ]
                );
                echo "    ✓ Predicted CAS: " . ($cas ?? 'N/A') . "\n";
            } else {
                echo "    ✗ Failed to parse JSON response correctly.\n";
            }
        } else {
            echo "    ✗ Invalid response format from API.\n";
        }
    } elseif ($httpCode === 429) {
        echo "    ✗ Rate Limit Exceeded (429). Pausing batch...\n";
        break; // Stop processing this batch on rate limit
    } else {
        echo "    ✗ API Error ($httpCode).\n";
    }
    
    // Sleep to avoid rate limit (15 RPM for Gemini Free Tier)
    sleep(4);
}

echo "[" . date('Y-m-d H:i:s') . "] Batch finished.\n";
