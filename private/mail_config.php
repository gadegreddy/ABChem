<?php
/* mail_config.php - Professional Email Configuration */

// Email configuration
define('MAIL_FROM', 'connect@abchem.co.in');
define('MAIL_FROM_NAME', 'AB Chem India');
define('MAIL_REPLY_TO', 'connect@abchem.co.in');
define('MAIL_RETURN_PATH', 'connect@abchem.co.in');

/**
 * Send professional HTML email with proper headers
 */
function sendProfessionalEmail($to, $subject, $htmlBody, $plainTextBody = '') {
    
    // Generate plain text version if not provided
    if (empty($plainTextBody)) {
        $plainTextBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $htmlBody));
    }
    
    // Generate a unique boundary for multipart message
    $boundary = md5(uniqid(time()));
    
    // Professional email headers to avoid spam
    $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "Reply-To: " . MAIL_REPLY_TO . "\r\n";
    $headers .= "Return-Path: " . MAIL_RETURN_PATH . "\r\n";
    $headers .= "Organization: AB Chem India\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "X-Priority: 3\r\n";
    $headers .= "X-MSMail-Priority: Normal\r\n";
    $headers .= "Importance: Normal\r\n";
    $headers .= "List-Unsubscribe: <mailto:" . MAIL_FROM . "?subject=unsubscribe>\r\n";
    
    // Build message body
    $message = "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $message .= quoted_printable_encode($plainTextBody) . "\r\n\r\n";
    
    $message .= "--$boundary\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $message .= quoted_printable_encode($htmlBody) . "\r\n\r\n";
    
    $message .= "--$boundary--";
    
    // Try to send email
    $success = mail($to, $subject, $message, $headers, "-f " . MAIL_FROM);
    
    if (!$success) {
        error_log("Email failed to send to: $to, Subject: $subject");
    }
    
    return $success;
}