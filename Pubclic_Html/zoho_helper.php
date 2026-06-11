<?php
/**
 * zoho_helper.php  —  Zoho Books API Integration for AB Chem India
 *
 * SETUP (one-time):
 *  1. Go to https://api-console.zoho.in  → Create a "Server-based Application"
 *  2. Set Redirect URI to: https://abchem.co.in/zoho_helper.php?callback=1
 *  3. Copy Client ID and Client Secret below
 *  4. Add your Zoho Books Organisation ID (Zoho Books → Settings → Organisation Profile → ID)
 *  5. Visit https://abchem.co.in/zoho_helper.php?connect=1  (while logged in as Admin)
 *  6. Approve access → token saved to zoho_token.json automatically
 *
 * After setup, call:  ZohoBooks::createInvoiceFromInquiry($query)
 *
 * For SaaS alternatives: swap the ZohoBooks class with a RazorpayInvoice or TallySync class
 * using the same interface (createInvoiceFromInquiry, listInvoices).
 */

// ── Configuration ─────────────────────────────────────────────────────────────
define('ZOHO_CLIENT_ID',     'YOUR_CLIENT_ID_HERE');
define('ZOHO_CLIENT_SECRET', 'YOUR_CLIENT_SECRET_HERE');
define('ZOHO_ORG_ID',        'YOUR_ORG_ID_HERE');
define('ZOHO_REDIRECT_URI',  'https://abchem.co.in/zoho_helper.php?callback=1');
define('ZOHO_TOKEN_FILE',    __DIR__ . '/zoho_token.json');

// Zoho uses region-specific domains. For India use .in
define('ZOHO_ACCOUNTS_URL',  'https://accounts.zoho.in');
define('ZOHO_BOOKS_URL',     'https://www.zohoapis.in/books/v3');

class ZohoBooks {

    // ── OAuth2 token management ───────────────────────────────────────────────
    public static function getAccessToken(): string {
        $token = self::loadToken();
        if ($token && time() < ($token['expires_at'] - 60)) {
            return $token['access_token'];
        }
        if ($token && !empty($token['refresh_token'])) {
            return self::refreshToken($token['refresh_token']);
        }
        throw new \RuntimeException('Zoho token missing. Visit /zoho_helper.php?connect=1 to authorise.');
    }

    private static function loadToken(): ?array {
        if (!file_exists(ZOHO_TOKEN_FILE)) return null;
        return json_decode(file_get_contents(ZOHO_TOKEN_FILE), true);
    }

    private static function saveToken(array $data): void {
        $data['expires_at'] = time() + (int)($data['expires_in'] ?? 3600);
        file_put_contents(ZOHO_TOKEN_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        @chmod(ZOHO_TOKEN_FILE, 0600);
    }

    private static function refreshToken(string $refreshToken): string {
        $resp = self::post(ZOHO_ACCOUNTS_URL . '/oauth/v2/token', [
            'refresh_token' => $refreshToken,
            'client_id'     => ZOHO_CLIENT_ID,
            'client_secret' => ZOHO_CLIENT_SECRET,
            'grant_type'    => 'refresh_token',
        ]);
        if (empty($resp['access_token'])) {
            throw new \RuntimeException('Zoho token refresh failed: ' . json_encode($resp));
        }
        // Preserve refresh_token (not returned on refresh)
        $existing = self::loadToken();
        $resp['refresh_token'] = $existing['refresh_token'] ?? '';
        self::saveToken($resp);
        return $resp['access_token'];
    }

    // ── First-time OAuth flow ─────────────────────────────────────────────────
    public static function getAuthURL(): string {
        $params = http_build_query([
            'scope'         => 'ZohoBooks.invoices.CREATE,ZohoBooks.contacts.CREATE,ZohoBooks.contacts.READ',
            'client_id'     => ZOHO_CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri'  => ZOHO_REDIRECT_URI,
            'access_type'   => 'offline',
        ]);
        return ZOHO_ACCOUNTS_URL . '/oauth/v2/auth?' . $params;
    }

    public static function handleCallback(string $code): void {
        $resp = self::post(ZOHO_ACCOUNTS_URL . '/oauth/v2/token', [
            'code'          => $code,
            'client_id'     => ZOHO_CLIENT_ID,
            'client_secret' => ZOHO_CLIENT_SECRET,
            'redirect_uri'  => ZOHO_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);
        if (empty($resp['access_token'])) {
            throw new \RuntimeException('Zoho token exchange failed: ' . json_encode($resp));
        }
        self::saveToken($resp);
    }

    // ── Find or create a Zoho Contact from inquiry data ───────────────────────
    private static function findOrCreateContact(array $query): string {
        $token = self::getAccessToken();
        // Search for existing contact by email
        $search = self::apiGet('/contacts', ['email' => $query['email']], $token);
        if (!empty($search['contacts'][0]['contact_id'])) {
            return $search['contacts'][0]['contact_id'];
        }
        // Create new contact
        $payload = [
            'contact_name'  => $query['company_name'] ?? $query['name'] ?? 'Unknown',
            'contact_type'  => 'customer',
            'email'         => $query['email'] ?? '',
            'phone'         => $query['phone'] ?? '',
            'contact_persons'=> [[
                'first_name' => $query['name'] ?? '',
                'email'      => $query['email'] ?? '',
                'phone'      => $query['phone'] ?? '',
                'is_primary_contact' => true,
            ]],
        ];
        $resp = self::apiPost('/contacts', $payload, $token);
        if (empty($resp['contact']['contact_id'])) {
            throw new \RuntimeException('Could not create Zoho contact: ' . json_encode($resp));
        }
        return $resp['contact']['contact_id'];
    }

    // ── Main: Create invoice from inquiry ─────────────────────────────────────
    /**
     * @param  array $query  A single entry from queries.json
     * @return array         ['invoice_number'=>..., 'invoice_id'=>..., 'url'=>...]
     */
    public static function createInvoiceFromInquiry(array $query): array {
        try {
            $token     = self::getAccessToken();
            $contactId = self::findOrCreateContact($query);

            $lineItem = [
                'name'        => $query['subject'] ?? ('Inquiry: ' . ($query['type'] ?? 'Chemical')),
                'description' => substr($query['message'] ?? '', 0, 500),
                'rate'        => 0.00,   // Admin fills actual price in Zoho after creation
                'quantity'    => 1,
                'unit'        => 'nos',
            ];

            $payload = [
                'customer_id'   => $contactId,
                'reference_number' => $query['id'] ?? ('ABCHEM-' . date('YmdHis')),
                'invoice_date'  => date('Y-m-d'),
                'payment_terms' => 30,
                'notes'         => 'Auto-generated from website inquiry. Admin: please fill rate before sending.',
                'line_items'    => [$lineItem],
            ];

            $resp = self::apiPost('/invoices', $payload, $token);
            if (empty($resp['invoice']['invoice_id'])) {
                return ['error' => 'Invoice creation failed: ' . json_encode($resp)];
            }

            $inv = $resp['invoice'];
            return [
                'invoice_id'     => $inv['invoice_id'],
                'invoice_number' => $inv['invoice_number'],
                'status'         => $inv['status'],
                'url'            => 'https://books.zoho.in/app#/invoices/' . $inv['invoice_id'],
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ── List recent invoices ──────────────────────────────────────────────────
    public static function listInvoices(int $limit = 20): array {
        try {
            $token = self::getAccessToken();
            $resp  = self::apiGet('/invoices', ['per_page' => $limit, 'sort_column' => 'created_time', 'sort_order' => 'D'], $token);
            return $resp['invoices'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────
    private static function apiGet(string $endpoint, array $params, string $token): array {
        $url = ZOHO_BOOKS_URL . $endpoint . '?organization_id=' . ZOHO_ORG_ID . '&' . http_build_query($params);
        $ch  = self::initCurl($url, $token);
        $res = curl_exec($ch); curl_close($ch);
        return json_decode($res, true) ?? [];
    }

    private static function apiPost(string $endpoint, array $payload, string $token): array {
        $url = ZOHO_BOOKS_URL . $endpoint . '?organization_id=' . ZOHO_ORG_ID;
        $ch  = self::initCurl($url, $token);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $res = curl_exec($ch); curl_close($ch);
        return json_decode($res, true) ?? [];
    }

    private static function initCurl(string $url, string $token) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Zoho-oauthtoken ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        return $ch;
    }

    private static function post(string $url, array $fields): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res = curl_exec($ch); curl_close($ch);
        return json_decode($res, true) ?? [];
    }
}

// ── Web OAuth flow (direct browser access) ───────────────────────────────────
if (php_sapi_name() !== 'cli' && isset($_GET['connect'])) {
    header('Location: ' . ZohoBooks::getAuthURL()); exit;
}
if (php_sapi_name() !== 'cli' && isset($_GET['callback']) && isset($_GET['code'])) {
    try {
        ZohoBooks::handleCallback($_GET['code']);
        echo '<p style="font-family:sans-serif; color:green;">✅ Zoho connected successfully! Token saved. <a href="/admin.php?tab=invoice">Return to Admin</a></p>';
    } catch (\Throwable $e) {
        echo '<p style="font-family:sans-serif; color:red;">❌ ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    exit;
}
