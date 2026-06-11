<?php
// FEAT-29: Anti-scraping honeypot handler
// This URL is hidden from real users in catalog HTML. Any visitor is a scraper/bot.
// Logs the hit to security.log + audit_log, returns HTTP 410 Gone.
require_once __DIR__ . '/../private/functions.php';
logHoneypotHit();
