<?php
// reset-password.php - Updated with professional email sending
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/mail_config.php';

$message = '';
$error = '';
$token = $_GET['token'] ?? ($_POST['token'] ?? '');

$db = Database::getInstance();

// Handle password reset request (from forgot-password page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reset'])) {
    $email = strtolower(trim($_POST['email'] ?? ''));
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        $user = $db->fetchOne("SELECT id, email, contact_name, company_name FROM users WHERE email = :email AND status = 'Active'", ['email' => $email]);
        
        if ($user) {
            $resetToken = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            $db->insert('password_resets', [
                'user_id' => $user['id'],
                'token' => $resetToken,
                'expires_at' => $expires,
                'created_at' => date('Y-m-d H:i:s'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            // Send professional password reset email
            $emailSent = sendPasswordResetEmail($user['email'], $user['contact_name'] ?? $user['company_name'] ?? 'User', $resetToken);
            
            if ($emailSent) {
                logAudit('password_reset_requested', "Password reset requested for: {$user['email']}");
                $message = "Password reset link has been sent to your email address.";
            } else {
                $message = "If your email exists in our system, you will receive a reset link. Please check your spam folder.";
            }
        } else {
            $message = "If your email exists in our system, you will receive a reset link.";
        }
    }
}

// If token is provided via GET, show the reset form
if (!empty($token) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reset = $db->fetchOne(
        "SELECT * FROM password_resets WHERE token = :token AND expires_at > NOW() AND used = 0",
        ['token' => $token]
    );
    
    if (!$reset) {
        $error = 'Invalid or expired reset link. Please request a new one.';
    }
}

// Handle password reset submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $reset = $db->fetchOne(
            "SELECT * FROM password_resets WHERE token = :token AND expires_at > NOW() AND used = 0",
            ['token' => $token]
        );
        
        if ($reset) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->update('users', 
                ['password_hash' => $hash, 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $reset['user_id']]
            );
            
            $db->update('password_resets',
                ['used' => 1, 'used_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $reset['id']]
            );
            
            logAudit('password_reset_completed', "Password reset completed for user ID: {$reset['user_id']}");
            $message = "Password has been reset successfully! You can now sign in.";
            $token = '';
        } else {
            $error = 'Invalid or expired reset link. Please request a new one.';
        }
    }
}

/**
 * Send professional password reset email
 */
function sendPasswordResetEmail($email, $name, $token) {
    $resetLink = "https://www.abchem.co.in/reset-password?token=" . $token;
    
    $subject = "Reset Your Password - AB Chem India";
    
    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #1e293b; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0f172a; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
            .header h1 { margin: 0; font-size: 24px; }
            .header span { color: #0ea5e9; }
            .content { background: #ffffff; padding: 30px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px; }
            .btn { display: inline-block; background: #0284c7; color: white; padding: 14px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 20px 0; }
            .link { word-break: break-all; color: #0284c7; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; text-align: center; }
            .warning { background: #fef3c7; padding: 12px; border-radius: 6px; font-size: 13px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>AB<span style="color:#0ea5e9;">Chem</span> India</h1>
                <p style="margin:10px 0 0; opacity:0.9;">Password Reset Request</p>
            </div>
            <div class="content">
                <h2>Reset Your Password</h2>
                <p>Hello ' . htmlspecialchars($name) . ',</p>
                <p>We received a request to reset the password for your AB Chem India account. Click the button below to create a new password:</p>
                
                <div style="text-align: center;">
                    <a href="' . $resetLink . '" class="btn">Reset Password</a>
                </div>
                
                <p>Or copy and paste this link into your browser:</p>
                <p class="link">' . $resetLink . '</p>
                
                <div class="warning">
                    <strong>⚠️ Security Notice:</strong><br>
                    • This link will expire in 1 hour<br>
                    • If you did not request this change, please ignore this email or contact support<br>
                    • Never share this link with anyone
                </div>
                
                <p>For security reasons, this link can only be used once. If you need to reset your password again, please submit a new request.</p>
                
                <div class="footer">
                    <p>AB Chem India<br>
                    Balanagar, Hyderabad, India<br>
                    <a href="mailto:connect@abchem.co.in">connect@abchem.co.in</a><br>
                    © ' . date('Y') . ' AB Chem India. All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>';
    
    return sendProfessionalEmail($email, $subject, $htmlBody);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>
<main style="padding: 80px 20px; min-height: calc(100vh - 200px); display: flex; align-items: center; justify-content: center; background: var(--bg);">
    <div style="width: 100%; max-width: 500px; background: var(--surface); padding: 32px; border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border);">
        
        <?php if ($message && empty($token)): ?>
            <div style="text-align: center;">
                <div style="font-size: 48px; margin-bottom: 16px;">📧</div>
                <h2 style="color: var(--primary); margin-bottom: 16px;">Check Your Email</h2>
                <div style="background: #dcfce7; color: #166534; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
                    <?= e($message) ?>
                </div>
                <p style="color: var(--muted); margin-bottom: 20px;">Didn't receive the email? Check your spam folder or</p>
                <a href="signin" class="btn btn-primary">Return to Sign In</a>
            </div>
            
        <?php elseif ($message && empty($token)): ?>
            <div style="background: #dcfce7; color: #166534; padding: 16px; border-radius: 6px; margin-bottom: 16px; text-align: center;">
                <?= e($message) ?>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <a href="signin" class="btn btn-primary">Sign In Now</a>
            </div>
            
        <?php elseif ($error && empty($token) && !isset($_POST['request_reset'])): ?>
            <!-- Show request form -->
            <div style="text-align: center; margin-bottom: 24px;">
                <h2 style="color: var(--primary); margin-bottom: 8px;">Forgot Password?</h2>
                <p style="color: var(--muted); font-size: 0.9rem;">Enter your email and we'll send you a reset link.</p>
            </div>
            
            <?php if ($error): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px; margin-bottom: 16px; text-align: center;">
                <?= e($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="post">
                <input type="hidden" name="request_reset" value="1">
                <div style="margin-bottom: 20px;">
                    <label class="filter-label">Email Address</label>
                    <input type="email" name="email" required class="filter-input" placeholder="your@email.com">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Send Reset Link</button>
            </form>
            
            <p style="text-align:center; margin-top: 20px;">
                <a href="signin" style="color:var(--accent);">← Back to Sign In</a>
            </p>
            
        <?php elseif (!empty($token) && empty($error)): ?>
            <!-- Show password reset form -->
            <div style="text-align: center; margin-bottom: 24px;">
                <h2 style="color: var(--primary); margin-bottom: 8px;">Reset Password</h2>
                <p style="color: var(--muted); font-size: 0.9rem;">Enter your new password below.</p>
            </div>
            
            <form method="post">
                <input type="hidden" name="reset_password" value="1">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div style="margin-bottom: 16px;">
                    <label class="filter-label">New Password</label>
                    <input type="password" name="password" required class="filter-input" placeholder="Min 8 characters">
                </div>
                <div style="margin-bottom: 20px;">
                    <label class="filter-label">Confirm Password</label>
                    <input type="password" name="confirm_password" required class="filter-input" placeholder="Re-enter password">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Reset Password</button>
            </form>
            
        <?php else: ?>
            <div style="text-align: center;">
                <div style="background: #fee2e2; color: #991b1b; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                    Invalid request. Please use the link from your email.
                </div>
                <a href="forgot-password" class="btn btn-primary">Request Reset Link</a>
                <a href="signin" class="btn btn-outline" style="margin-left: 10px;">Back to Sign In</a>
            </div>
        <?php endif; ?>
        
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>