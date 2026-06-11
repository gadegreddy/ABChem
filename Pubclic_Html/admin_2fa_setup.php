<?php
/**
 * admin_2fa_setup.php — Admin TOTP 2FA Setup (Item 1)
 * Accessible only to authenticated Admins who have not yet enabled 2FA,
 * OR Admins who want to regenerate their secret.
 */
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/totp.php';

enforceSessionTimeout(900);
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header('Location: /signin'); exit;
}

$db      = Database::getInstance();
$userId  = $_SESSION['user_id'];
$email   = $_SESSION['user'];
$message = '';
$error   = '';

// Load current 2FA state
$user = $db->fetchOne('SELECT totp_secret, totp_enabled FROM users WHERE id = :id', ['id' => $userId]);

// Generate or keep a pending secret (stored in session until confirmed)
if (!isset($_SESSION['pending_totp_secret'])) {
    $_SESSION['pending_totp_secret'] = TOTP::generateSecret();
}
$pendingSecret = $_SESSION['pending_totp_secret'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please refresh.';
    } else {
        $code = trim($_POST['totp_code'] ?? '');
        if (TOTP::verify($pendingSecret, $code)) {
            // Save confirmed secret to DB
            $db->update('users',
                ['totp_secret' => $pendingSecret, 'totp_enabled' => 1],
                'id = :id', ['id' => $userId]
            );
            // Regenerate session after privilege change (item 4)
            session_regenerate_id(true);
            unset($_SESSION['pending_totp_secret']);
            logAudit('2fa_enabled', "Admin enabled 2FA: {$email}");
            $message = '2FA has been enabled for your account.';
            $user = $db->fetchOne('SELECT totp_secret, totp_enabled FROM users WHERE id = :id', ['id' => $userId]);
        } else {
            $error = 'Invalid code. Please try again — check your authenticator app.';
        }
    }
}

$uri    = TOTP::getUri($pendingSecret, $email);
$qrUrl  = TOTP::getQrUrl($uri);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Setup | Admin | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>
<main class="auth-main">
    <div class="auth-card">
        <div class="auth-heading">
            <h2>🔐 Two-Factor Authentication</h2>
            <p class="text-muted-sm">Secure your admin account with TOTP 2FA</p>
        </div>

        <?php if ($message): ?>
        <div class="alert-success mb-2"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert-error mb-2"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($user['totp_enabled'])): ?>
        <div class="alert-success mb-3">✅ 2FA is currently <strong>enabled</strong> on your account.</div>
        <p class="mb-3 text-muted-sm">To reset your 2FA (e.g. new phone), scan the new QR below and confirm a code.</p>
        <?php endif; ?>

        <ol class="mb-3" style="padding-left: 20px; line-height: 2;">
            <li>Install Google Authenticator or Authy on your phone.</li>
            <li>Scan the QR code below.</li>
            <li>Enter the 6-digit code your app shows.</li>
        </ol>

        <div class="text-center mb-3">
            <img src="<?= e($qrUrl) ?>" alt="2FA QR Code" width="200" height="200" style="border:4px solid var(--border); border-radius: 8px;">
            <p class="text-xs mt-1 text-muted">Manual entry key: <code><?= e($pendingSecret) ?></code></p>
        </div>

        <form method="post" action="admin-2fa-setup">
            <?= CSRF::field() ?>
            <div class="form-group">
                <label class="filter-label">Enter 6-Digit Code from App</label>
                <input type="text" name="totp_code" class="filter-input w-full" maxlength="6" pattern="\d{6}"
                       placeholder="000000" autocomplete="one-time-code" required inputmode="numeric">
            </div>
            <button type="submit" class="btn btn-primary w-full">Verify &amp; Enable 2FA</button>
        </form>

        <div class="auth-footer-link">
            <a href="/admin" class="link-accent">← Back to Admin Panel</a>
        </div>
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>
