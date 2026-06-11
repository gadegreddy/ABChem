<?php
/**
 * 404.php - Redirect to central error handler
 */
header('Location: /error.php?code=404&url=' . urlencode($_SERVER['REQUEST_URI']));
exit;