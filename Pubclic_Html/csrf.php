<?php
// csrf.php - CSRF Protection with token rotation
class CSRF {
    public static function generate(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_expires'] = time() + 7200; // 2 hours
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function verify(?string $token): bool {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_expires'])) {
            return false;
        }
        if (time() > $_SESSION['csrf_expires']) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_expires']);
            return false;
        }
        
        $valid = hash_equals($_SESSION['csrf_token'], $token ?? '');
        
        // Rotate token after successful verification (prevent replay)
        if ($valid) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_expires']);
        }
        
        return $valid;
    }
    
    public static function field(): string {
        return '<input type="hidden" name="csrf_token" value="' . self::generate() . '">';
    }
}