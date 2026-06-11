<?php

require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/totp.php';
require_once __DIR__ . '/../private/captcha.php';

// Redirect if already fully authenticated
if (isset($_SESSION['user'], $_SESSION['role']) && empty($_SESSION['2fa_pending'])) {
    header('Location: ' . ($_SESSION['role'] === 'Admin' ? '/admin' : '/catalog'));
    exit;
}

$err     = '';
$show2fa = !empty($_SESSION['2fa_pending']);

// Captcha gate — only after 3+ failed attempts from this IP within 15 minutes.
// login_attempts rows are inserted by RateLimiter::check() on every miss and
// cleared by RateLimiter::clear() on success, so this is an accurate failure count.
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$recentFails = 0;
if ($ip) {
    try {
        $recentFails = (int)Database::getInstance()->fetchValue(
            "SELECT COUNT(*) FROM login_attempts
             WHERE attempt_key LIKE :k AND attempted_at >= :w",
            ['k' => $ip . '\\_%', 'w' => date('Y-m-d H:i:s', time() - 900)]
        );
    } catch (\Throwable $e) { /* table may not exist yet — ignore */ }
}
$needsCaptcha = $recentFails >= 3;

// ── Stage 2: Verify TOTP code for pending 2FA ────────────────────────────────
if ($show2fa && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_code'])) {
    if (!CSRF::verify($_POST['csrf_token'] ?? '')) {
        $err = 'Invalid form submission. Please refresh.';
    } else {
        $secret = $_SESSION['2fa_secret'] ?? '';
        $code   = preg_replace('/\D/', '', $_POST['totp_code'] ?? '');

        if (TOTP::verify($secret, $code)) {
            // 2FA passed — restore full session
            $_SESSION['user']        = $_SESSION['2fa_user_email'];
            $_SESSION['user_id']     = $_SESSION['2fa_user_id'];
            $_SESSION['role']        = $_SESSION['2fa_user_role'];
            $_SESSION['user_type']   = $_SESSION['2fa_user_type'] ?? 'Admin';
            $_SESSION['last_activity'] = time();
            
            // Clean up 2FA pending state
            unset($_SESSION['2fa_pending'], $_SESSION['2fa_secret'],
                  $_SESSION['2fa_user_id'], $_SESSION['2fa_user_email'],
                  $_SESSION['2fa_user_role'], $_SESSION['2fa_user_type']);
            
            session_regenerate_id(true);
            logAudit('2fa_verified', "Admin 2FA passed: " . $_SESSION['user']);
            header('Location: /admin');
            exit;
        } else {
            $err = 'Invalid code — check your authenticator app and try again.';
        }
    }
}

// ── Stage 1: Email + Password ────────────────────────────────────────────────
if (!$show2fa && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify($_POST['csrf_token'] ?? '')) {
        $err = 'Invalid form submission. Please refresh and try again.';
    } elseif ($needsCaptcha && ($captchaErr = Captcha::verify($_POST)) !== null) {
        // After 3+ failures from this IP, every further attempt must clear the captcha
        $err = $captchaErr;
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';

        // Item 3: Progressive rate-limiting
        if (!RateLimiter::check($_SERVER['REMOTE_ADDR'] . '_' . $email)) {
            $err = 'Too many login attempts. Please try again later.';
        } else {
            $db   = Database::getInstance();
            $user = $db->fetchOne("SELECT * FROM users WHERE email = :email", ['email' => $email]);

            if ($user && password_verify($pass, $user['password_hash'])) {
                if ($user['status'] !== 'Active') {
                    $err = 'Account is inactive. Please contact admin.';
                } else {
                    RateLimiter::clear($_SERVER['REMOTE_ADDR'] . '_' . $email);

                    // Check if 2FA is enabled for this admin
                    $has2fa = ($user['role'] === 'Admin' && !empty($user['totp_enabled']) && !empty($user['totp_secret']));

                    // Update last login
                    $db->update('users',
                        ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $_SERVER['REMOTE_ADDR']],
                        'id = :id', ['id' => $user['id']]
                    );

                    if ($has2fa) {
                        // Admin with 2FA enabled: require TOTP before granting session
                        session_regenerate_id(true);
                        $_SESSION['2fa_pending']    = true;
                        $_SESSION['2fa_secret']     = $user['totp_secret'];
                        $_SESSION['2fa_user_id']    = $user['id'];
                        $_SESSION['2fa_user_email'] = $user['email'];
                        $_SESSION['2fa_user_role']  = $user['role'];
                        $_SESSION['2fa_user_type']  = $user['user_type'] ?? 'Admin';
                        $show2fa = true;
                        // Fall through to render 2FA form
                    } else {
                        // No 2FA required — grant session immediately
                        session_regenerate_id(true);
                        $_SESSION['user']        = $user['email'];
                        $_SESSION['user_id']     = $user['id'];
                        $_SESSION['role']        = $user['role'];
                        $_SESSION['user_type']   = $user['user_type'] ?? 'Customer';
                        $_SESSION['last_activity'] = time();

                        logAudit('login_success', "User logged in: {$user['email']}");

                        // Redirect based on role
                        if ($user['role'] === 'Admin') {
                            header('Location: /admin');
                        } else {
                            header('Location: /catalog');
                        }
                        exit;
                    }
                }
            } else {
                logAudit('login_failed', "Failed login attempt for: {$email}");
                $err = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>

<main class="auth-main">
    <div class="auth-card">

        <?php if ($show2fa): ?>
        <!-- ── 2FA Step ── -->
        <div class="auth-heading">
            <h2>🔐 Two-Factor Authentication</h2>
            <p class="text-muted-sm">Enter the 6-digit code from your authenticator app</p>
        </div>

        <?php if ($err): ?>
        <div id="error-msg" class="alert-error mb-2"><?= e($err) ?></div>
        <?php endif; ?>

        <form method="post" action="/signin">
            <?= CSRF::field() ?>
            <div class="form-group">
                <label for="totp_code" class="filter-label">Authenticator Code</label>
                <input type="text" id="totp_code" name="totp_code" required
                       class="filter-input w-full text-center"
                       maxlength="6" pattern="\d{6}" placeholder="000000"
                       autocomplete="one-time-code" inputmode="numeric" autofocus>
            </div>
            <button type="submit" class="btn btn-primary w-full">Verify Code</button>
        </form>
        <p class="auth-footer-link">
            <a href="/logout" class="link-muted">← Cancel and sign out</a>
        </p>

        <?php else: ?>
        <!-- ── Password Step ── -->
        <div class="auth-heading">
            <h2>Welcome Back</h2>
            <p class="text-muted-sm">Sign in to access your account</p>
        </div>

        <?php if ($err): ?>
        <div id="error-msg" class="alert-error mb-2"><?= e($err) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['expired'])): ?>
        <div class="alert-warning mb-2">Your session expired. Please sign in again.</div>
        <?php endif; ?>

        <form method="post" action="/signin" id="signin-form">
            <?= CSRF::field() ?>
            <div class="form-group">
                <label for="email" class="filter-label">Email Address</label>
                <input type="email" id="email" name="email" required class="filter-input w-full"
                       placeholder="you@company.com" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="password" class="filter-label">Password</label>
                <input type="password" id="password" name="password" required
                       class="filter-input w-full" placeholder="••••••••">
            </div>
            <div class="signin-row mb-3">
                <label class="remember-label">
                    <input type="checkbox" name="remember"> Remember me
                </label>
                <a href="/forgot-password" class="link-accent text-sm">Forgot password?</a>
            </div>
            <?php if ($needsCaptcha): ?>
                <div class="alert-warning mb-2" style="font-size:0.85rem;">
                    Multiple failed attempts detected from your network. Please complete the verification below.
                </div>
                <?= Captcha::renderHtml() ?>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary w-full">Sign In</button>
        </form>
        <p class="auth-footer-link">
            Don't have an account? <a href="/signup" class="link-accent fw-500">Sign Up</a>
        </p>
        <?php endif; ?>

    </div>
</main>

<?php include 'footer.php'; ?>
<script>
autoDismiss('#error-msg', 5000);
</script>
</body>
</html>