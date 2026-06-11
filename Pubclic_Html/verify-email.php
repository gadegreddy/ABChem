<?php
/**
 * verify-email.php - Handle email verification links
 */
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/mail_config.php';

$message = '';
$error = '';
$token = $_GET['token'] ?? '';

$db = Database::getInstance();

if (!empty($token)) {
    // Find user with this verification token
    $user = $db->fetchOne(
        "SELECT * FROM users WHERE email_verify_token = :token AND status = 'Pending'",
        ['token' => $token]
    );
    
    if ($user) {
        // Check if token is expired (72 hours)
        $tokenCreated = strtotime($user['email_verify_sent_at'] ?? $user['created_at']);
        if (time() - $tokenCreated > 259200) { // 72 hours
            $error = 'Verification link has expired. Please request a new one.';
        } else {
            // Activate the user
            $db->update('users', 
                [
                    'status' => 'Active',
                    'email_verified_at' => date('Y-m-d H:i:s'),
                    'email_verify_token' => null
                ],
                'id = :id',
                ['id' => $user['id']]
            );
            
            logAudit('email_verified', "User verified email: {$user['email']}");
            
            $message = "Your email has been verified successfully! You can now sign in to your account.";
            
            // Send welcome email
            sendWelcomeEmail($user['email'], $user['contact_name'] ?? $user['company_name'] ?? 'User');
        }
    } else {
        $error = 'Invalid or expired verification link.';
    }
} else {
    $error = 'No verification token provided.';
}

/**
 * Send welcome email after verification
 */
function sendWelcomeEmail($email, $name) {
    $subject = "Welcome to AB Chem India!";
    
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
            .btn { display: inline-block; background: #0284c7; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 20px 0; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; text-align: center; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>AB<span style="color:#0ea5e9;">Chem</span> India</h1>
                <p style="margin:10px 0 0; opacity:0.9;">Welcome to Our Community!</p>
            </div>
            <div class="content">
                <h2>Hello ' . htmlspecialchars($name) . ',</h2>
                <p>Thank you for verifying your email address. Your AB Chem India account is now fully activated!</p>
                <p>You can now:</p>
                <ul>
                    <li>Browse our complete catalog of pharmaceutical standards</li>
                    <li>Request quotes and place orders</li>
                    <li>Track your inquiries and orders</li>
                    <li>Access Certificates of Analysis</li>
                </ul>
                <div style="text-align: center;">
                    <a href="https://www.abchem.co.in/signin" class="btn">Sign In to Your Account</a>
                </div>
                <p>If you have any questions, please contact us at <a href="mailto:connect@abchem.co.in">connect@abchem.co.in</a>.</p>
                <div class="footer">
                    <p>AB Chem India<br>
                    Balanagar, Hyderabad, India<br>
                    © ' . date('Y') . ' AB Chem India. All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>';
    
    sendProfessionalEmail($email, $subject, $htmlBody);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>
<main style="padding: 80px 20px; min-height: calc(100vh - 200px); display: flex; align-items: center; justify-content: center; background: var(--bg);">
    <div style="width: 100%; max-width: 500px; background: var(--surface); padding: 40px; border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border); text-align: center;">
        
        <?php if ($message): ?>
            <div style="color: #166534; margin-bottom: 20px;">
                <div style="font-size: 48px; margin-bottom: 16px;">✅</div>
                <h2 style="color: var(--primary); margin-bottom: 16px;">Email Verified!</h2>
                <div style="background: #dcfce7; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
                    <?= e($message) ?>
                </div>
                <a href="signin" class="btn btn-primary" style="padding: 12px 32px;">Sign In Now →</a>
            </div>
        <?php elseif ($error): ?>
            <div style="color: #991b1b;">
                <div style="font-size: 48px; margin-bottom: 16px;">❌</div>
                <h2 style="color: var(--primary); margin-bottom: 16px;">Verification Failed</h2>
                <div style="background: #fee2e2; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
                    <?= e($error) ?>
                </div>
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <a href="signin" class="btn btn-outline">Sign In</a>
                    <a href="contact" class="btn btn-primary">Contact Support</a>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>