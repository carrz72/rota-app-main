<?php
// One-off migration runner. Safe to re-run: it will skip errors and report results.
require_once __DIR__ . '/../includes/db.php';
$dir = __DIR__ . '/../migrations';
$files = glob($dir . '/*.sql');
if (!$files) {
    echo "No migration files found in $dir\n";
    exit(0);
}
foreach ($files as $f) {
    echo "Applying migration: " . basename($f) . "\n";
    $sql = file_get_contents($f);
    if ($sql === false) { echo "  Failed to read file\n"; continue; }
    try {
        $conn->exec($sql);
        echo "  OK\n";
    } catch (PDOException $e) {
        echo "  Skipped/Failed: " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
