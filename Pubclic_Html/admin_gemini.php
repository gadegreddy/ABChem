<?php
/**
 * Admin utility to auto-generate Descriptions, Applications, and Meta Descriptions
 * using the Gemini REST API via standard PHP cURL.
 * 
 * Perfect for shared hosting (Hostinger) as it processes in small batches of 5
 * and automatically refreshes the page to avoid execution timeouts.
 */
require_once __DIR__ . '/../private/functions.php';

// Enforce admin only
if (!isset($_SESSION['user_id']) || !checkRole('Admin')) {
    http_response_code(403);
    die('Unauthorized access. Please log in as an administrator.');
}

// Configuration
$apiKey = getenv('GEMINI_API_KEY');
if (!$apiKey) {
    die("Error: GEMINI_API_KEY is not set in your .env file.");
}

$batchSize = 5; // Optimized to 5 per batch to stay under 15 RPM free tier limit
$db = Database::getInstance();

// 1. Fetch compounds that are missing content
$compounds = $db->fetchAll(
    "SELECT id, compound_name, cas_number, molecular_formula, product_type, parent_drug 
     FROM compounds 
     WHERE status = 'Active' 
       AND (description IS NULL OR description = '' 
            OR meta_description IS NULL OR meta_description = ''
            OR ((cas_number IS NULL OR cas_number = '') AND ai_predicted_cas IS NULL))
     LIMIT :limit",
    ['limit' => $batchSize]
);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Gemini Batch Generator</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #334155; padding: 40px; }
        .card { background: white; padding: 24px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); margin-bottom: 20px;}
        .success { color: #16a34a; }
        .error { color: #dc2626; }
    </style>
    <?php if (!empty($compounds)): ?>
        <!-- Refresh buffer to keep overall speed strictly under 15 RPM -->
        <meta http-equiv="refresh" content="<?= isset($rateLimitHit) && $rateLimitHit ? '65' : '15' ?>">
    <?php endif; ?>
</head>
<body>
    <div class="card">
        <h2>🤖 Gemini Batch Generator</h2>
        <?php
        if (empty($compounds)) {
            echo "<p class='success'>✅ All compounds have descriptions! No further action needed.</p>";
            echo "</div></body></html>";
            exit;
        }

        echo "<p>Processing batch of " . count($compounds) . " compounds...</p>";
        echo "<ul>";
        $rateLimitHit = false;

        foreach ($compounds as $c) {
            echo "<li><strong>" . e($c['compound_name']) . "</strong> (CAS: " . e($c['cas_number']) . ")<br>";
            
            // Construct prompt asking for JSON
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
            Do NOT include medical advice.";

            // Gemini API JSON structure
            $payload = [
                "contents" => [
                    ["parts" => [["text" => $prompt]]]
                ],
                "generationConfig" => [
                    "response_mime_type" => "application/json"
                ]
            ];

            // Setup cURL (Using gemini-2.5-flash to avoid 2-RPM limits)
            $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            
            // Execute and parse
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    $jsonText = $data['candidates'][0]['content']['parts'][0]['text'];
                    $generated = json_decode($jsonText, true);

                    if ($generated && isset($generated['description'])) {
                        // Update the database
                        $db->query(
                            "UPDATE compounds 
                             SET description = :desc, 
                                 meta_description = :meta,
                                 ai_predicted_cas = :ai_cas
                             WHERE id = :id",
                            [
                                'desc'   => $generated['description'] ?? '',
                                'meta'   => $generated['meta_description'] ?? '',
                                'ai_cas' => $generated['predicted_cas'] ?? 'N/A',
                                'id'     => $c['id']
                            ]
                        );
                        echo "<span class='success'>✓ Successfully updated description, meta, and AI CAS.</span>";
                    } else {
                        echo "<span class='error'>✗ Failed to parse JSON response from Gemini.</span>";
                    }
                } else {
                    echo "<span class='error'>✗ Invalid response format from API.</span>";
                }
            } elseif ($httpCode === 429) {
                echo "<span class='error'>✗ API Error (429): Rate Limit Exceeded. Pausing batch.</span>";
                echo "<pre style='font-size: 0.7em; color: #64748b; margin-top: 4px; padding: 8px; background: #f1f5f9; border-radius: 4px;'>" . e($response) . "</pre>";
                $rateLimitHit = true;
                break; // Stop processing this batch
            } else {
                echo "<span class='error'>✗ API Error ($httpCode): $response</span>";
            }
            echo "</li><br>";
            
            // Critical sleep to prevent Pay-As-You-Go billing charges (15 RPM free tier)
            sleep(4);
        }
        
        echo "</ul>";
        echo "<p><i>Page will auto-refresh to process the next batch...</i></p>";
        ?>
    </div>
</body>
</html>
