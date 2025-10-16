<?php
echo "=== PHP Diagnostic ===<br>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "Current Script: " . $_SERVER['SCRIPT_FILENAME'] . "<br>";
echo "<br>✅ PHP is working correctly!<br>";
echo "<br><a href='users/chat.php'>Click here to open Team Chat</a>";
?>