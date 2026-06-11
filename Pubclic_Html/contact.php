<?php include __DIR__ . '/../private/functions.php';
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize_input($_POST['name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $type = sanitize_input($_POST['inquiry_type'] ?? 'general');
    $subject = sanitize_input($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (!$name || !$email || !$message) { $err = 'All required fields must be filled.'; }
    else {
        $query = ['id'=>uniqid(), 'name'=>$name, 'email'=>$email, 'type'=>$type, 'subject'=>$subject, 'message'=>$message, 'date'=>date('Y-m-d H:i:s'), 'status'=>'New'];
        saveQuery($query);
        
        $to = 'connect@abchem.co.in';
        $headers = "From: $name <$email>\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8";
        $body = "Inquiry: $type\nSubject: $subject\nName: $name\nEmail: $email\n\nMessage:\n$message";
        if (@mail($to, "AB Chem Inquiry: $subject", $body, $headers)) {
            $msg = 'Your inquiry has been sent successfully. We will respond within 24 hours.';
        } else {
            $err = 'Failed to send email, but saved locally. Please contact us directly.';
        }
    }
}
?>
<!DOCTYPE html><html><head>
<title>Contact | AB Chem</title><link rel="stylesheet" href="styles.css">
</head><body>
<?php include 'header.php'; ?>
<main class="main" style="max-width:700px; margin:40px auto;">
    <h1 style="text-align:center; margin-bottom:24px;">Contact AB Chem</h1>
    <?php if($msg): ?><div style="background:var(--success); color:#fff; padding:12px; border-radius:6px; margin-bottom:20px;"><?=e($msg)?></div><?php endif; ?>
    <?php if($err): ?><div style="background:var(--danger); color:#fff; padding:12px; border-radius:6px; margin-bottom:20px;"><?=e($err)?></div><?php endif; ?>
    
    <form method="post" class="contact-form" style="background:var(--surface); padding:24px; border-radius:var(--radius); border:1px solid #e2e8f0;">
        <div style="margin-bottom:16px;"><label class="filter-label">Full Name *</label><input type="text" name="name" required class="filter-input"></div>
        <div style="margin-bottom:16px;"><label class="filter-label">Email *</label><input type="email" name="email" required class="filter-input"></div>
        <div style="margin-bottom:16px;"><label class="filter-label">Inquiry Type</label>
            <select name="inquiry_type" class="filter-input">
                <option value="general">General Inquiry</option><option value="quote">Price Quote</option>
                <option value="custom_synthesis">Custom Synthesis</option><option value="coa_request">CoA/Documentation</option>
            </select>
        </div>
        <div style="margin-bottom:16px;"><label class="filter-label">Subject / Product</label><input type="text" name="subject" class="filter-input" value="<?=e($_GET['subject']??'')?>"></div>
        <div style="margin-bottom:16px;"><label class="filter-label">Message</label><textarea name="message" rows="6" class="filter-input" required></textarea></div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Send Inquiry</button>
    </form>
</main>
<?php include 'footer.php'; ?>
</body></html>