<?php
/**
 * cron_cleanup.php — Automated Cleanup Cron Job (Item 19)
 *
 * Crontab (run daily at 02:00 AM server time):
 *   0 2 * * * php /home/u670463068/domains/abchem.co.in/public_html/cron_cleanup.php >> /home/u670463068/cron_cleanup.log 2>&1
 *
 * This script:
 *  - Archives quotes > 90 days with no activity → status = 'Archived'
 *  - Purges Pending email-unverified accounts > 7 days old
 *  - Writes all actions to the audit_log before deleting
 *  - Cleans up stale rate-limiter temp files
 */

// Must run from CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

require_once __DIR__ . '/../private/db_config.php';
require_once __DIR__ . '/../private/functions.php';

// Force session-less mode for audit log
$_SESSION['user'] = 'cron';
$_SESSION['role'] = 'system';

$db = Database::getInstance();
$db->beginTransaction();

try {
    // ── 1. Archive stale Pending/New quotes (> 90 days, no activity) ────────
    $cutoffQuote = date('Y-m-d H:i:s', strtotime('-90 days'));
    $staleQuotes = $db->fetchAll(
        "SELECT id, quote_number, user_id FROM quote_requests
         WHERE status IN ('new', 'pending')
           AND updated_at < :cutoff",
        ['cutoff' => $cutoffQuote]
    );

    foreach ($staleQuotes as $q) {
        logAudit(
            'quote_auto_archived',
            "Cron auto-archived stale quote #{$q['quote_number']} (id:{$q['id']})",
            'new/pending', 'archived'
        );
        $db->update('quote_requests',
            ['status' => 'archived', 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $q['id']]
        );
    }
    $archivedCount = count($staleQuotes);
    echo date('[Y-m-d H:i:s]') . " Archived {$archivedCount} stale quotes.\n";

    // ── 2. Purge Pending unverified accounts > 7 days old ───────────────────
    $cutoffUser = date('Y-m-d H:i:s', strtotime('-7 days'));
    $expiredUsers = $db->fetchAll(
        "SELECT id, email FROM users
         WHERE status = 'Pending'
           AND email_verify_token IS NOT NULL
           AND created_at < :cutoff",
        ['cutoff' => $cutoffUser]
    );

    foreach ($expiredUsers as $u) {
        logAudit(
            'user_auto_purged',
            "Cron purged unverified account: {$u['email']} (id:{$u['id']})",
            'Pending', 'deleted'
        );
        $db->delete('users', 'id = :id', ['id' => $u['id']]);
    }
    $purgedCount = count($expiredUsers);
    echo date('[Y-m-d H:i:s]') . " Purged {$purgedCount} unverified accounts.\n";

    // ── 3. Clean stale rate-limiter temp files (> 30 min old) ───────────────
    $tmpDir = sys_get_temp_dir();
    $rateFiles = glob($tmpDir . '/rate_*');
    $cleanedRate = 0;
    if ($rateFiles) {
        foreach ($rateFiles as $file) {
            if (is_file($file) && (time() - filemtime($file)) > 1800) {
                @unlink($file);
                $cleanedRate++;
            }
        }
    }
    echo date('[Y-m-d H:i:s]') . " Cleaned {$cleanedRate} stale rate-limiter files.\n";

    $db->commit();
    echo date('[Y-m-d H:i:s]') . " Cron cleanup complete.\n";

} catch (Throwable $e) {
    $db->rollback();
    echo date('[Y-m-d H:i:s]') . " ERROR: " . $e->getMessage() . "\n";
    error_log("cron_cleanup error: " . $e->getMessage());
    exit(1);
}
