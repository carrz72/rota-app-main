<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $stmt = $conn->query("SHOW COLUMNS FROM audit_archive");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo $c['Field'] . "\t" . $c['Type'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
