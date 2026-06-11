<?php
// logout.php - Enhanced version
require_once __DIR__ . '/../private/functions.php';

// Log the logout action to the audit trail
if (isset($_SESSION['user'])) {
    try {
        logAudit('logout', "User logged out", '', $_SESSION['user']);
    } catch (Exception $e) {
        // Silent fail - don't let logging prevent logout
        error_log("Logout audit failed: " . $e->getMessage());
    }
}

// Clear all session variables
$_SESSION = [];

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Optional: Clear any remember-me cookies if you have them
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// Redirect to sign in page
header('Location: signin.php');
exit;
?>