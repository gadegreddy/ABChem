<?php
// profile.php - User profile with password change
require_once __DIR__ . '/../private/functions.php';

// Enforce login
enforceSessionTimeout(900);
if (!isset($_SESSION['user'])) {
    header('Location: /signin.php');
    exit;
}

$db = Database::getInstance();
$message = '';
$error = '';

$user = $db->fetchOne("SELECT * FROM users WHERE email = :email", ['email' => $_SESSION['user']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (password_verify($current, $user['password_hash'])) {
        if (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $db->update('users', 
                ['password_hash' => $hash, 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $user['id']]
            );
            logAudit('password_changed', "User changed password: {$user['email']}");
            $message = 'Password changed successfully!';
        }
    } else {
        $error = 'Current password is incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<?php include 'header.php'; ?>
<main style="max-width: 600px; margin: 40px auto; padding: 0 20px;">
    <div style="background: white; padding: 32px; border-radius: 8px; border: 1px solid #e2e8f0;">
        <h2 style="color: var(--primary); margin-bottom: 8px;">My Profile</h2>
        <p style="color: var(--muted); margin-bottom: 24px;"><?= e($user['email']) ?></p>
        
        <?php if ($message): ?>
            <div style="background: #dcfce7; color: #166534; padding: 12px; border-radius: 6px; margin-bottom: 16px;"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px; margin-bottom: 16px;"><?= e($error) ?></div>
        <?php endif; ?>
        
        <h3 style="margin-bottom: 16px;">Change Password</h3>
        <form method="post">
            <div style="margin-bottom: 16px;">
                <label class="filter-label">Current Password</label>
                <input type="password" name="current_password" required class="filter-input">
            </div>
            <div style="margin-bottom: 16px;">
                <label class="filter-label">New Password</label>
                <input type="password" name="new_password" required class="filter-input" placeholder="Min 8 characters">
            </div>
            <div style="margin-bottom: 20px;">
                <label class="filter-label">Confirm New Password</label>
                <input type="password" name="confirm_password" required class="filter-input">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Update Password</button>
        </form>
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>