<?php
require_once __DIR__ . '/../includes/crypto.php';
try {
    $r = encrypt_blob('test payload');
    echo "algorithm: " . $r['algorithm'] . "\n";
    echo "iv len: " . strlen($r['iv']) . "\n";
    echo "mac len: " . strlen($r['mac']) . "\n";
    echo "data len: " . strlen($r['data']) . "\n";
} catch (Exception $e) {
    echo "Encrypt error: " . $e->getMessage() . "\n";
}
