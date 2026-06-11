<?php
/**
 * seller_settings.php — Load & Save Company/Seller Settings from company_settings table.
 * Used by admin.php (settings form), order-detail.php, and invoice_pdf.php.
 * Never include directly from public URLs — accessed only via other PHP files.
 */

if (!defined('ABSPATH')) define('ABSPATH', dirname(__FILE__) . '/');

/**
 * Load all company settings as a flat key→value array.
 * Returns defaults if the table doesn't exist yet.
 */
function getSellerSettings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        'seller_name'     => 'AB Chem India',
        'seller_address1' => 'Industrial Estate',
        'seller_address2' => 'Balanagar',
        'seller_city'     => 'Hyderabad',
        'seller_state'    => 'Telangana',
        'seller_pin'      => '500037',
        'seller_country'  => 'India',
        'seller_phone'    => '+91-97 05 09 2020',
        'seller_email'    => 'connect@abchem.co.in',
        'seller_website'  => 'www.abchem.co.in',
        'seller_gstin'    => '36ACDFA7838D1ZG',
        'seller_pan'      => '',
        'seller_cin'      => '',
        'tax_cgst_pct'    => '9',
        'tax_sgst_pct'    => '9',
        'tax_igst_pct'    => '18',
        'bank_name'       => '',
        'bank_account'    => '',
        'bank_ifsc'       => '',
        'bank_branch'     => '',
        'bank_upi'        => '',
        'invoice_prefix'  => 'INV',
        'invoice_footer'  => 'This is a computer-generated invoice. No signature required.',
        'invoice_terms'   => 'Payment due within 30 days of invoice date.',
    ];

    try {
        $db   = Database::getInstance();
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM company_settings");
        $settings = $defaults;
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'] ?? '';
        }
        $cache = $settings;
        return $cache;
    } catch (Throwable $e) {
        // Table may not exist yet — return defaults
        error_log('getSellerSettings: ' . $e->getMessage());
        $cache = $defaults;
        return $cache;
    }
}

/**
 * Save an array of key→value settings to company_settings.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE for upsert.
 */
function saveSellerSettings(array $data): bool {
    try {
        $db = Database::getInstance();
        foreach ($data as $key => $value) {
            // Whitelist allowed keys
            if (!preg_match('/^[a-z0-9_]{2,60}$/', $key)) continue;
            $db->query(
                "INSERT INTO company_settings (setting_key, setting_value, updated_at)
                 VALUES (:k, :v, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = :v2, updated_at = NOW()",
                ['k' => $key, 'v' => trim($value), 'v2' => trim($value)]
            );
        }
        // Bust static cache
        return true;
    } catch (Throwable $e) {
        error_log('saveSellerSettings: ' . $e->getMessage());
        return false;
    }
}

/**
 * Return a formatted one-line address string for use in invoices.
 */
function sellerAddressOneLine(array $s): string {
    $parts = array_filter([
        $s['seller_address1'] ?? '',
        $s['seller_address2'] ?? '',
        $s['seller_city']     ?? '',
        $s['seller_state']    ?? '',
        $s['seller_pin']      ?? '',
        $s['seller_country']  ?? '',
    ]);
    return implode(', ', $parts);
}