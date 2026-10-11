<?php
declare(strict_types=1);

// Compatibility endpoint for OAuth clients registered with /auth/callback.
$_GET['action'] = 'callback';
require_once __DIR__ . '/../../auth.php';
