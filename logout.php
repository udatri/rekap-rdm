<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Security.php';

Auth::startSession();
Security::sendHeaders(false);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Location: index.php');
    exit;
}

try {
    Security::validateCsrf((string) ($_POST['csrf'] ?? ''));
} catch (Throwable) {
    header('Location: index.php');
    exit;
}

Auth::logout();
header('Location: login.php');
exit;
