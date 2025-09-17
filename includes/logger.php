<?php
function rota_log_rotate($baseDir, $baseName, $message) {
    if (!is_dir($baseDir)) @mkdir($baseDir, 0700, true);
    $date = (new DateTime())->format('Ymd');
    $path = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '_' . $date . '.log';
    $ts = (new DateTime())->format(DateTime::ATOM);
    $line = "[$ts] " . $message . "\n";
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
