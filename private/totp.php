<?php
/**
 * totp.php — Pure-PHP RFC 6238 TOTP (Time-based One-Time Password)
 * No external library required. Used for Admin 2FA (item 1).
 */

class TOTP {
    /** Generate a random 16-char Base32 secret */
    public static function generateSecret(): string {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        $bytes  = random_bytes(10); // 80 bits = good entropy
        for ($i = 0; $i < 10; $i++) {
            $secret .= $chars[ord($bytes[$i]) & 31];
        }
        // Pad to 16 chars (standard for most authenticator apps)
        while (strlen($secret) < 16) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    /** Decode a Base32 string to binary */
    private static function base32Decode(string $b32): string {
        $b32     = strtoupper(preg_replace('/\s+/', '', $b32));
        $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits    = '';
        foreach (str_split($b32) as $ch) {
            $pos  = strpos($charset, $ch);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }
        return $bytes;
    }

    /**
     * Compute HOTP code for a given counter value.
     * RFC 4226 §5
     */
    private static function hotp(string $secret, int $counter): string {
        $key = self::base32Decode($secret);
        // Pack counter as 8-byte big-endian
        $msg = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $msg, $key, true);
        // Dynamic truncation
        $offset = ord($hash[19]) & 0xF;
        $code = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
            ((ord($hash[$offset + 3]) & 0xFF))
        ) % 1_000_000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a 6-digit code against the current time window.
     * Allows ±1 step (±30 s) to handle clock skew.
     */
    public static function verify(string $secret, string $code, int $step = 30, int $window = 1): bool {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) return false;
        $counter = (int)floor(time() / $step);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build an otpauth:// URI so authenticator apps can import via QR.
     */
    public static function getUri(string $secret, string $account, string $issuer = 'AB Chem India'): string {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($account),
            rawurlencode($secret),
            rawurlencode($issuer)
        );
    }

    /**
     * Return a Google Charts QR URL for the otpauth URI.
     * Google Charts is only used for setup — never for verification.
     */
    public static function getQrUrl(string $uri): string {
        return 'https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl=' . rawurlencode($uri);
    }
}
