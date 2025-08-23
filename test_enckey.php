<?php
require_once __DIR__ . '/includes/crypto.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $k = get_encryption_key();
    if ($k === null) { echo "No key found\n"; exit(0); }
    echo "Key length: " . strlen($k) . " bytes\n";
    echo "Hex: " . bin2hex($k) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
