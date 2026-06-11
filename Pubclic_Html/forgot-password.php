<?php
// forgot_password.php - Password reset request page
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/captcha.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (($captchaErr = Captcha::verify($_POST)) !== null) {
        $error = $captchaErr;
    } elseif (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        $db = Database::getInstance();
        $user = $db->fetchOne("SELECT id, email, contact_name FROM users WHERE email = :email AND status = 'Active'", ['email' => $email]);
        
        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Save token in database
            $db->insert('password_resets', [
                'user_id' => $user['id'],
                'token' => $token,
                'expires_at' => $expires,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Send email with reset link
            $resetLink = "https://www.abchem.co.in/reset-password.php?token=" . $token;
            $to = $user['email'];
            $subject = "Password Reset Request - AB Chem India";
            $headers = "From: noreply@abchem.co.in\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            $body = "
            <html>
            <head><title>Password Reset</title></head>
            <body style='font-family: Arial, sans-serif;'>
                <h2>Password Reset Request</h2>
                <p>Hello " . htmlspecialchars($user['contact_name'] ?? 'User') . ",</p>
                <p>We received a request to reset your password for your AB Chem India account.</p>
                <p>Click the link below to reset your password (valid for 1 hour):</p>
                <p><a href='{$resetLink}' style='background: #0284c7; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Reset Password</a></p>
                <p>Or copy this link: <br>{$resetLink}</p>
                <p>If you didn't request this, please ignore this email.</p>
                <hr>
                <p style='font-size: 12px; color: #666;'>AB Chem India - Pharmaceutical Standards</p>
            </body>
            </html>";
            
            if (mail($to, $subject, $body, $headers)) {
                $message = "Password reset link has been sent to your email address.";
            } else {
                error_log("Failed to send password reset email to: $email");
                $message = "If your email exists in our system, you will receive a reset link.";
            }
        } else {
            // Don't reveal if email exists or not for security
            $message = "If your email exists in our system, you will receive a reset link.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>
<main style="padding: 80px 20px; min-height: calc(100vh - 200px); display: flex; align-items: center; justify-content: center; background: var(--bg);">
    <div style="width: 100%; max-width: 500px; background: var(--surface); padding: 32px; border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border);">
        <div style="text-align: center; margin-bottom: 24px;">
            <h2 style="color: var(--primary); margin-bottom: 8px;">Forgot Password?</h2>
            <p style="color: var(--muted); font-size: 0.9rem;">Enter your email and we'll send you a reset link.</p>
        </div>
        
        <?php if ($message): ?>
            <div style="background: #dcfce7; color: #166534; padding: 12px; border-radius: 6px; margin-bottom: 16px; text-align: center;">
                <?= e($message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px; margin-bottom: 16px; text-align: center;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="post">
            <div style="margin-bottom: 20px;">
                <label class="filter-label">Email Address</label>
                <input type="email" name="email" required class="filter-input" placeholder="your@email.com">
            </div>
            <?= Captcha::renderHtml() ?>
            <button type="submit" class="btn btn-primary" style="width:100%;">Send Reset Link</button>
        </form>
        
        <p style="text-align:center; margin-top: 20px;">
            <a href="signin" style="color:var(--accent);">← Back to Sign In</a>
        </p>
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>