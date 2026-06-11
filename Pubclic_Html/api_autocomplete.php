<?php
/**
 * Autocomplete API - Fixed for keyword searches
 */
require_once __DIR__ . '/../private/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) { 
    echo json_encode([]); 
    exit; 
}

try {
    $db = Database::getInstance();
    
    // Search in multiple fields for autocomplete.
    // Alternate CAS uses the indexed compound_cas_aliases junction table:
    // - exact match  → cas_number = :q  (uses B-tree, O(log n))
    // - prefix match → cas_number LIKE 'q%'  (also uses B-tree)
    // Falls back to the cas_other cache only as a last resort.
    $results = $db->fetchAll(
        "SELECT id, slug, url_slug, ab_catalog_number, url_token,
                compound_name AS product_name, cas_number, molecular_formula, product_type
         FROM compounds c
         WHERE status = 'Active'
           AND (compound_name LIKE :q1
                OR cas_number LIKE :q2
                OR EXISTS (SELECT 1 FROM compound_cas_aliases a
                           WHERE a.compound_id = c.id AND a.cas_number LIKE :q2b)
                OR synonyms LIKE :q3
                OR iupac_name LIKE :q4
                OR molecular_formula LIKE :q5)
         ORDER BY
            CASE
                WHEN compound_name LIKE :q6 THEN 1
                WHEN cas_number   =   :q7 THEN 2
                WHEN cas_number   LIKE :q7p THEN 3
                WHEN EXISTS (SELECT 1 FROM compound_cas_aliases a
                             WHERE a.compound_id = c.id AND a.cas_number = :q7b) THEN 4
                WHEN synonyms LIKE :q8 THEN 5
                ELSE 6
            END,
            compound_name ASC
         LIMIT 8",
        [
            'q1'  => "%{$q}%",
            'q2'  => "%{$q}%",
            'q2b' => "{$q}%",          // junction prefix match — uses idx_cas
            'q3'  => "%{$q}%",
            'q4'  => "%{$q}%",
            'q5'  => "%{$q}%",
            'q6'  => "{$q}%",
            'q7'  => $q,                // exact CAS — top priority
            'q7p' => "{$q}%",          // prefix CAS
            'q7b' => $q,                // exact alias CAS
            'q8'  => "%{$q}%"
        ]
    );
    
    // Format results — return a pre-built URL so JS doesn't need to construct it
    $formatted = array_map(function($p) {
        // Build canonical URL: /product/name-slug/TOKEN or legacy fallback
        if (!empty($p['ab_catalog_number']) && !empty($p['url_token'])) {
            $token = rawurlencode($p['ab_catalog_number'] . '-' . $p['url_token']);
            $url   = !empty($p['url_slug'])
                   ? '/product/' . rawurlencode($p['url_slug']) . '/' . $token
                   : '/product/' . $token;
        } else {
            $url = '/product/' . rawurlencode($p['slug'] ?? '');
        }
        return [
            'name'    => $p['product_name'],
            'cas'     => $p['cas_number'] ?? 'N/A',
            'url'     => $url,
            'formula' => $p['molecular_formula'] ?? '',
            'type'    => $p['product_type'] ?? ''
        ];
    }, $results);
    
    echo json_encode($formatted, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Autocomplete API Error: " . $e->getMessage());
    echo json_encode([]);
}