<?php
// Public endpoint to expose the audit public key for verification tools.
// Served at /.well-known/audit-public-key.php

require_once __DIR__ . '/../includes/signing.php';
$pub = load_public_key_pem();
if (empty($pub)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Public key not configured\n";
    exit;
}
header('Content-Type: text/plain');
echo $pub;
