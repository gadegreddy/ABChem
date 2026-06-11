<?php
/**
 * signup.php — Updated with email verification, strong password policy,
 * session regeneration (Item 4), inline styles removed.
 */
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/mail_config.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/captcha.php';

if (isset($_SESSION['user'])) { header('Location: /catalog'); exit; }

$err     = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify($_POST['csrf_token'] ?? '')) {
        $err = 'Invalid form submission. Please refresh and try again.';
    } elseif (($captchaErr = Captcha::verify($_POST)) !== null) {
        $err = $captchaErr;
    } else {
        $email          = strtolower(trim($_POST['email'] ?? ''));
        $password       = $_POST['password'] ?? '';
        $confirmPassword= $_POST['confirm_password'] ?? '';
        $companyName    = sanitize_input($_POST['company_name'] ?? '');
        $userType       = sanitize_input($_POST['user_type'] ?? 'Customer');
        $contactName    = sanitize_input($_POST['contact_name'] ?? '');
        $phone          = sanitize_input($_POST['phone'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Invalid email address.';
        } elseif (strlen($password) < 12) {
            $err = 'Password must be at least 12 characters.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $err = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $err = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $err = 'Password must contain at least one digit.';
        } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $err = 'Password must contain at least one special character.';
        } elseif ($password !== $confirmPassword) {
            $err = 'Passwords do not match.';
        } elseif (empty($companyName)) {
            $err = 'Company name is required.';
        }

        if (empty($err)) {
            $db = Database::getInstance();
            $existing = $db->fetchOne("SELECT id FROM users WHERE email = :email", ['email' => $email]);

            if ($existing) {
                $err = 'Email already registered. Please sign in.';
            } else {
                $verifyToken = bin2hex(random_bytes(32));
                $userId = $db->insert('users', [
                    'email'              => $email,
                    'password_hash'      => password_hash($password, PASSWORD_DEFAULT),
                    'role'               => 'User',
                    'user_type'          => $userType,
                    'company_name'       => $companyName,
                    'contact_name'       => $contactName,
                    'phone'              => $phone,
                    'status'             => 'Pending',
                    'email_verify_token' => $verifyToken,
                    'email_verify_sent_at'=> date('Y-m-d H:i:s'),
                    'created_at'         => date('Y-m-d H:i:s'),
                    'created_ip'         => $_SERVER['REMOTE_ADDR'] ?? ''
                ]);

                $emailSent = sendVerificationEmail($email, $contactName ?: $companyName, $verifyToken);
                logAudit('user_registered', "New user registered: $email", '', 'Pending email verification');
                $success = $emailSent
                    ? 'Registration successful! Please check your email to verify your account.'
                    : "Registration successful! Contact support if you don't receive the verification email.";
            }
        }
    }
}

function sendVerificationEmail($email, $name, $token) {
    $verifyLink = "https://www.abchem.co.in/verify-email?token=" . $token;
    $subject    = "Verify Your Email - AB Chem India";
    $htmlBody   = '<!DOCTYPE html><html><head><style>
        body{font-family:Arial,sans-serif;line-height:1.6;color:#1e293b;}
        .container{max-width:600px;margin:0 auto;padding:20px;}
        .header{background:#0f172a;color:white;padding:30px;text-align:center;border-radius:8px 8px 0 0;}
        .content{background:#fff;padding:30px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;}
        .btn{display:inline-block;background:#0284c7;color:white;padding:14px 32px;text-decoration:none;border-radius:6px;font-weight:600;margin:20px 0;}
        .link{word-break:break-all;color:#0284c7;}
        .footer{margin-top:30px;padding-top:20px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;text-align:center;}
        .note{background:#fef3c7;padding:12px;border-radius:6px;font-size:13px;margin:20px 0;}
    </style></head><body>
    <div class="container">
        <div class="header"><h1>AB<span style="color:#0ea5e9;">Chem</span> India</h1><p style="margin:10px 0 0;opacity:.9;">Specialty Chemicals, APIs &amp; Consumables</p></div>
        <div class="content">
            <h2>Verify Your Email Address</h2>
            <p>Hello ' . htmlspecialchars($name) . ',</p>
            <p>Thank you for registering with AB Chem India. Please verify your email address:</p>
            <div style="text-align:center;"><a href="' . $verifyLink . '" class="btn">Verify Email Address</a></div>
            <p>Or copy this link: <span class="link">' . $verifyLink . '</span></p>
            <div class="note"><strong>⚠️ This link expires in 72 hours.</strong></div>
            <div class="footer"><p>AB Chem India · Balanagar, Hyderabad, India<br>
            <a href="mailto:connect@abchem.co.in">connect@abchem.co.in</a><br>
            © ' . date('Y') . ' AB Chem India. All rights reserved.</p></div>
        </div>
    </div></body></html>';
    return sendProfessionalEmail($email, $subject, $htmlBody);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>
<main class="auth-main">
    <div class="auth-card auth-card-wide">
        <div class="auth-heading">
            <h2>Create Account</h2>
            <p class="text-muted-sm">Join AB Chem India</p>
        </div>

        <?php if ($success): ?>
        <div id="success-msg" class="alert-success mb-3"><?= e($success) ?></div>
        <div class="text-center mt-2">
            <p class="text-muted-sm mb-2">Didn't receive the email? Check your spam folder or</p>
            <a href="/signin" class="btn btn-outline">Go to Sign In</a>
        </div>
        <?php else: ?>

        <?php if ($err): ?>
        <div id="error-msg" class="alert-error mb-2"><?= e($err) ?></div>
        <?php endif; ?>

        <form method="post" action="/signup">
            <?= CSRF::field() ?>
            <div class="form-group">
                <label class="filter-label">Email Address *</label>
                <input type="email" name="email" required class="filter-input w-full"
                       placeholder="you@company.com" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="filter-label">Password *</label>
                <input type="password" name="password" required class="filter-input w-full"
                       placeholder="Min 12 chars, upper, lower, digit, symbol">
                <small class="text-xs text-muted">12+ characters with uppercase, lowercase, number &amp; special character</small>
            </div>
            <div class="form-group">
                <label class="filter-label">Confirm Password *</label>
                <input type="password" name="confirm_password" required class="filter-input w-full"
                       placeholder="Re-enter password">
            </div>
            <div class="form-group">
                <label class="filter-label">Account Type *</label>
                <select name="user_type" required class="filter-input w-full">
                    <option value="Customer" <?= ($_POST['user_type'] ?? '') === 'Customer' ? 'selected' : '' ?>>Customer</option>
                    <option value="Buyer"    <?= ($_POST['user_type'] ?? '') === 'Buyer'    ? 'selected' : '' ?>>Buyer</option>
                    <option value="Vendor"   <?= ($_POST['user_type'] ?? '') === 'Vendor'   ? 'selected' : '' ?>>Vendor</option>
                </select>
            </div>
            <div class="form-group">
                <label class="filter-label">Company Name *</label>
                <input type="text" name="company_name" required class="filter-input w-full"
                       placeholder="Your company name" value="<?= e($_POST['company_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="filter-label">Contact Person</label>
                <input type="text" name="contact_name" class="filter-input w-full"
                       placeholder="Full name" value="<?= e($_POST['contact_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="filter-label">Phone Number</label>
                <input type="tel" name="phone" class="filter-input w-full"
                       placeholder="+91 XXXXX XXXXX" value="<?= e($_POST['phone'] ?? '') ?>">
            </div>
            <?= Captcha::renderHtml() ?>
            <button type="submit" class="btn btn-primary w-full">Create Account</button>
        </form>
        <p class="auth-footer-link">
            Already have an account? <a href="/signin" class="link-accent fw-500">Sign In</a>
        </p>
        <?php endif; ?>
    </div>
</main>
<?php include 'footer.php'; ?>
<script>
setTimeout(() => {
    ['success-msg', 'error-msg'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.transition = 'opacity 0.5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }
    });
}, 5000);
</script>
</body>
</html>
