<?php
require_once __DIR__ . '/../includes/db.php';
$stmt = $conn->query('SELECT id, username, role FROM users ORDER BY id ASC LIMIT 10');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['id'] . "\t" . $r['username'] . "\t" . $r['role'] . "\n";
}
