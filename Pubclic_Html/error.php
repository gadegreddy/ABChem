<?php
/**
 * Error Page Handler
 */
$code = $_GET['code'] ?? 404;
$codes = [
    400 => 'Bad Request',
    401 => 'Unauthorized',
    403 => 'Forbidden',
    404 => 'Page Not Found',
    500 => 'Internal Server Error',
    503 => 'Service Unavailable'
];

$title = $codes[$code] ?? 'Error';
http_response_code((int)$code);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $code ?> - <?= $title ?> | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
    <style>
        .error-container {
            max-width: 600px;
            margin: 100px auto;
            padding: 40px;
            text-align: center;
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .error-code {
            font-size: 72px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .error-title {
            font-size: 24px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 24px;
        }
        .error-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="error-container">
        <div class="error-code"><?= $code ?></div>
        <div class="error-title"><?= $title ?></div>
        
        <?php if ($code == 404): ?>
            <p style="color:#64748b; margin-bottom:16px;">
                The page or product you're looking for doesn't exist or has been moved.
            </p>
            <div class="error-actions">
                <a href="/catalog" class="btn btn-primary">Browse Catalog</a>
                <a href="/" class="btn btn-outline">Go Home</a>
            </div>
        <?php else: ?>
            <p style="color:#64748b; margin-bottom:16px;">
                Something went wrong. Please try again or contact support.
            </p>
            <div class="error-actions">
                <a href="/" class="btn btn-primary">Go Home</a>
                <a href="/contact" class="btn btn-outline">Contact Support</a>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>